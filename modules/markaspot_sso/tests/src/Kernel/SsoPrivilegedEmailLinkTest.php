<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_sso\Service\OidcClaims;
use Drupal\markaspot_sso\Service\SsoIdentityLinker;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests first-login email linking with real users and group memberships.
 *
 * @group markaspot_sso
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class SsoPrivilegedEmailLinkTest extends KernelTestBase {

  private const EMAIL = 'staff@example.test';
  private const PROVIDER = 'staff_oidc';
  private const REFUSAL = 'Privileged users must be pre-linked before SSO email linking.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_sso',
  ];

  /**
   * The provider's jurisdiction.
   */
  private Group $jurisdiction;

  /**
   * A jurisdiction served by a different provider.
   */
  private Group $otherJurisdiction;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('markaspot_sso', ['markaspot_sso_identities']);
    $this->installConfig(['system', 'user', 'field', 'group']);
    $user = User::create(['uid' => 0, 'name' => '', 'status' => 0]);
    $user->save();

    $group_type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction']);
    $group_type->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationship_types->load('jur-group_membership')) {
      $relationship_types->createFromPlugin($group_type, 'group_membership')->save();
    }
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Jurisdiction']);
    $this->jurisdiction->save();
    $this->otherJurisdiction = Group::create(['type' => 'jur', 'label' => 'Other jurisdiction']);
    $this->otherJurisdiction->save();
  }

  /**
   * Links an eligible account and emits one log line with no email address.
   */
  #[DataProvider('allowedRoles')]
  public function testLinksEligibleRole(array $roles): void {
    $user = $this->createAccount($roles);
    $this->jurisdiction->addMember($user);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('info')->with(
      'SSO linked privileged uid @uid by email for provider @provider.',
      ['@provider' => self::PROVIDER, '@uid' => $user->id()],
    );

    $linker = $this->linker($logger);
    $this->assertSame(
      realpath(dirname(__DIR__, 3) . '/src/Service/SsoIdentityLinker.php'),
      (new \ReflectionClass($linker))->getFileName(),
    );
    // MariaDB matches this case variant through Drupal's usual mail lookup.
    $email = strtoupper(self::EMAIL);
    $lookup = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => $email]);
    $this->assertSame([(int) $user->id()], array_keys($lookup));

    $result = $linker->authenticate(self::PROVIDER, $this->provider(), 'subject', ['email' => [$email]], [], self::EMAIL);

    $this->assertSame((int) $user->id(), $result['uid']);
    $this->assertSame([(int) $user->id()], $this->linkedUids());
  }

  /**
   * Supplies the explicitly linkable global roles.
   */
  public static function allowedRoles(): iterable {
    yield 'moderator' => [['moderator']];
    yield 'editorial board' => [['editorial_board', 'member']];
    yield 'tenant admin' => [['tenant_admin']];
  }

  /**
   * Rejects an ineligible account without storing an identity or logging in.
   */
  #[DataProvider('refusedLinks')]
  public function testRefusesIneligibleLink(array $roles, array $overrides = [], int $uid = 2, bool $member = TRUE, ?string $verified_oidc_email = self::EMAIL): void {
    $user = $this->createAccount($roles, $uid);
    $group = $member ? $this->jurisdiction : $this->otherJurisdiction;
    $group->addMember($user);
    $this->assertPrivilegedLinkRefused($this->provider($overrides), ['email' => [self::EMAIL]], $verified_oidc_email);
  }

  /**
   * Supplies refused roles, providers and jurisdiction configurations.
   */
  public static function refusedLinks(): iterable {
    yield 'flag off' => [['moderator'], ['link_privileged_by_email' => FALSE]];
    yield 'administrator' => [['administrator']];
    yield 'uid 1' => [['moderator'], [], 1];
    yield 'api_user' => [['api_user']];
    yield 'api_editor' => [['api_editor']];
    yield 'unknown additional role' => [['moderator', 'custom_role']];
    yield 'other jurisdiction' => [['moderator'], [], 2, FALSE];
    yield 'missing jurisdiction' => [['moderator'], ['jurisdiction_id' => NULL]];
    yield 'invalid jurisdiction' => [['moderator'], ['jurisdiction_id' => '1invalid']];
    yield 'nonexistent jurisdiction' => [['moderator'], ['jurisdiction_id' => 999]];
    yield 'SAML' => [['moderator'], ['protocol' => 'saml']];
    yield 'brokered OIDC' => [['moderator'], ['oidc_idp_hint' => 'upstream']];
    yield 'truthy non-boolean email linking' => [['moderator'], ['link_by_email' => 1]];
    yield 'OIDC config without OIDC email verification' => [['moderator'], [], 2, TRUE, NULL];
  }

  /**
   * Refuses an account that also belongs to a second jurisdiction.
   */
  public function testRefusesMembershipInTwoJurisdictions(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $this->otherJurisdiction->addMember($user);

    $this->assertPrivilegedLinkRefused($this->provider());
  }

  /**
   * Refuses a second subject for an already linked provider and account.
   */
  public function testRefusesSecondSubjectForSameProviderAndAccount(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $provider = $this->provider();
    $attributes = ['email' => [self::EMAIL]];
    $linker = $this->linker($this->createMock(LoggerInterface::class));
    $first = $linker->authenticate(self::PROVIDER, $provider, 'first', $attributes, [], self::EMAIL);
    $this->assertSame((int) $user->id(), $first['uid']);
    $this->assertSame([(int) $user->id()], $this->linkedUids());
    $returning = $linker->authenticate(self::PROVIDER, $provider, 'first', [], []);
    $this->assertSame((int) $user->id(), $returning['uid']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(self::REFUSAL);
    try {
      $linker->authenticate(self::PROVIDER, $provider, 'second', $attributes, [], self::EMAIL);
    }
    finally {
      $this->assertSame([(int) $user->id()], $this->linkedUids());
    }
  }

  /**
   * Refuses a mapped email that differs from the verified email claim.
   */
  public function testRefusesMappedEmailDifferentFromVerifiedClaim(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $provider = $this->provider(['attribute_map' => ['email' => ['upn']]]);
    $attributes = ['email' => ['other@example.test'], 'upn' => [self::EMAIL]];

    $this->assertPrivilegedLinkRefused($provider, $attributes, $attributes['email'][0]);
  }

  /**
   * Refuses a provider without an explicitly configured OIDC protocol.
   */
  public function testRefusesMissingProtocol(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $provider = $this->provider();
    unset($provider['protocol']);

    $this->assertPrivilegedLinkRefused($provider);
  }

  /**
   * Refuses a matching membership if the configured group is an organisation.
   */
  public function testRefusesNonJurisdictionGroup(): void {
    $user = $this->createAccount(['moderator']);
    $type = GroupType::create(['id' => 'org', 'label' => 'Organisation']);
    $type->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationship_types->load('org-group_membership')) {
      $relationship_types->createFromPlugin($type, 'group_membership')->save();
    }
    $organisation = Group::create(['type' => 'org', 'label' => 'Organisation']);
    $organisation->save();
    $organisation->addMember($user);

    $this->assertPrivilegedLinkRefused($this->provider(['jurisdiction_id' => (int) $organisation->id()]));
  }

  /**
   * Checks the privileged refusal and the absence of an identity mapping.
   */
  private function assertPrivilegedLinkRefused(array $provider, array $attributes = ['email' => [self::EMAIL]], ?string $verified_oidc_email = self::EMAIL): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('info');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(self::REFUSAL);
    try {
      $this->linker($logger)->authenticate(self::PROVIDER, $provider, 'subject', $attributes, [], $verified_oidc_email);
    }
    finally {
      $this->assertSame([], $this->linkedUids());
    }
  }

  /**
   * Preserves the legacy api_user behavior when the new flag is absent.
   */
  public function testPreservesLegacyApiUserLink(): void {
    $user = $this->createAccount(['api_user']);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('info')->with(
      'SSO login for provider @provider linked to uid @uid.',
      ['@provider' => self::PROVIDER, '@uid' => $user->id()],
    );
    $provider = $this->provider();
    unset($provider['link_privileged_by_email']);

    $result = $this->linker($logger)->authenticate(self::PROVIDER, $provider, 'subject', ['email' => [self::EMAIL]], [], self::EMAIL);

    $this->assertSame((int) $user->id(), $result['uid']);
    $this->assertSame([(int) $user->id()], $this->linkedUids());
  }

  /**
   * Leaves the account unlinked when ordinary email linking is disabled.
   */
  public function testRequiresOrdinaryEmailLinking(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $provider = $this->provider(['link_by_email' => FALSE]);

    $this->assertNoEmailLink($provider, ['email' => [self::EMAIL]], self::EMAIL);
  }

  /**
   * Leaves the account unlinked when the callback strips its email.
   */
  public function testDoesNotLinkWithoutMappedEmail(): void {
    $user = $this->createAccount(['moderator']);
    $this->jurisdiction->addMember($user);
    $provider = $this->provider();
    $attributes = OidcClaims::withoutUnverifiedEmail(['email' => [self::EMAIL]], $provider['attribute_map']);

    $this->assertArrayNotHasKey('email', $attributes);
    $this->assertNoEmailLink($provider, $attributes, NULL);
  }

  /**
   * Checks that the linker skips email matching and stores no identity.
   */
  private function assertNoEmailLink(array $provider, array $attributes, ?string $verified_oidc_email): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SSO auto-registration is disabled for this provider.');
    try {
      $this->linker($this->createMock(LoggerInterface::class))
        ->authenticate(self::PROVIDER, $provider, 'subject', $attributes, [], $verified_oidc_email);
    }
    finally {
      $this->assertSame([], $this->linkedUids());
    }
  }

  /**
   * Creates a staff account with the given global role IDs.
   */
  private function createAccount(array $roles, int $uid = 2): User {
    foreach ($roles as $role) {
      Role::create(['id' => $role, 'label' => $role])->save();
    }
    $user = User::create([
      'uid' => $uid,
      'name' => 'staff',
      'mail' => self::EMAIL,
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Returns provider settings for the test jurisdiction.
   */
  private function provider(array $overrides = []): array {
    return $overrides + [
      'protocol' => 'oidc',
      'link_by_email' => TRUE,
      'link_privileged_by_email' => TRUE,
      'create_users' => FALSE,
      'jurisdiction_id' => (int) $this->jurisdiction->id(),
      'attribute_map' => OidcClaims::DEFAULT_ATTRIBUTE_MAP,
    ];
  }

  /**
   * Builds the real linker with a test logger.
   */
  private function linker(LoggerInterface $logger): SsoIdentityLinker {
    return new SsoIdentityLinker(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('markaspot_sso.group_membership'),
      $this->container->get('lock'),
      $logger,
    );
  }

  /**
   * Returns the account IDs persisted by the linker.
   */
  private function linkedUids(): array {
    return array_map('intval', $this->container->get('database')
      ->select('markaspot_sso_identities', 'identity')
      ->fields('identity', ['uid'])
      ->execute()
      ->fetchCol());
  }

}
