<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\markaspot_health\Plugin\HealthCheck\KeyAccountUnpublishedReadCheck;
use Drupal\node\Entity\NodeType;
use Drupal\services_api_key_auth\Entity\ApiKey;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the API key account group draft permission check with real groups.
 *
 * @group markaspot_health
 */
#[RunTestsInSeparateProcesses]
final class KeyAccountUnpublishedReadCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter',
    'entity', 'flexible_permissions', 'group', 'gnode',
    'services_api_key_auth', 'markaspot_health',
  ];

  /**
   * The account the frontend key maps to.
   */
  private UserInterface $keyAccount;

  /**
   * First jurisdiction the key account is a member of.
   */
  private GroupInterface $north;

  /**
   * Second jurisdiction the key account is a member of.
   */
  private GroupInterface $south;

  /**
   * Organisation inside the first jurisdiction.
   */
  private GroupInterface $organisation;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Inactive until a test writes its state key.
    $container->register('markaspot_health_test.individual_draft_grant_calculator', IndividualDraftGrantCalculator::class)
      ->addArgument(new Reference('state'))
      ->addTag('flexible_permission_calculator', ['priority' => -200]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'node', 'group', 'services_api_key_auth']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();

    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur' => 'Jurisdiction', 'org' => 'Organisation'] as $id => $label) {
      GroupType::create(['id' => $id, 'label' => $label])->save();
      foreach (['group_membership', 'group_node:service_request'] as $plugin) {
        $type = $relationshipTypes->createFromPlugin(GroupType::load($id), $plugin);
        if (!$relationshipTypes->load($type->id())) {
          $type->save();
        }
      }
    }

    // The shipped shape of the relevant roles: the jurisdiction member role
    // reads published requests and own drafts, the editorial role reads all
    // drafts, and every authenticated organisation member reads drafts.
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => [
        'view group',
        'view group_node:service_request entity',
        'view own unpublished group_node:service_request entity',
      ],
    ])->save();
    GroupRole::create([
      'id' => 'jur-editorial',
      'label' => 'Editorial',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => [
        'view group_node:service_request entity',
        KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION,
      ],
    ])->save();
    GroupRole::create([
      'id' => 'jur-outsider',
      'label' => 'Outsider',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'authenticated',
      'permissions' => ['view group_node:service_request entity'],
    ])->save();
    GroupRole::create([
      'id' => 'org-member',
      'label' => 'Member',
      'group_type' => 'org',
      'scope' => 'insider',
      'global_role' => 'authenticated',
      'permissions' => [
        'view group_node:service_request entity',
        KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION,
      ],
    ])->save();

    // Keep uid 0 and uid 1 out of the way; uid 1 bypasses every check.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    $this->keyAccount = User::create(['name' => 'api_user', 'status' => 1]);
    $this->keyAccount->save();
    ApiKey::create([
      'id' => 'frontend',
      'label' => 'Frontend',
      'key' => 'not-a-real-key',
      'user_uuid' => $this->keyAccount->uuid(),
    ])->save();
    // A key whose account no longer exists must not break the check.
    ApiKey::create([
      'id' => 'orphan',
      'label' => 'Orphan',
      'key' => 'not-a-real-key-either',
      'user_uuid' => 'a4f1c0de-0000-4000-8000-000000000000',
    ])->save();

    $this->north = Group::create(['type' => 'jur', 'label' => 'North']);
    $this->north->save();
    $this->south = Group::create(['type' => 'jur', 'label' => 'South']);
    $this->south->save();
    $this->organisation = Group::create(['type' => 'org', 'label' => 'Roads department']);
    $this->organisation->save();
    $this->north->addMember($this->keyAccount, ['group_roles' => ['jur-member']]);
    $this->south->addMember($this->keyAccount, ['group_roles' => ['jur-member']]);
  }

  /**
   * The shipped role shape produces no finding.
   */
  public function testCleanConfigurationPasses(): void {
    $this->assertTrue($this->north->hasPermission('view own unpublished group_node:service_request entity', $this->keyAccount));
    $this->assertFalse($this->north->hasPermission(KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION, $this->keyAccount));

    $result = $this->runCheck();

    $this->assertTrue($result->passed, $result->message);
    $this->assertSame(0, $result->count);
  }

  /**
   * A member role that wrongly carries the draft permission is reported.
   */
  public function testMisconfiguredMemberRoleIsReported(): void {
    // Literal on purpose: a typo in the class constant must fail here.
    GroupRole::load('jur-member')->grantPermission('view unpublished group_node:service_request entity')->save();

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame('warning', $result->severity);
    $this->assertSame(2, $result->count);
    $this->assertSame([
      $this->finding($this->north, 'jur-member (individual)'),
      $this->finding($this->south, 'jur-member (individual)'),
    ], $result->details);
    $this->assertStringContainsString('In 2 account-group pair(s),', $result->message);
    $this->assertStringContainsString('api_user', $result->message);
    $this->assertStringContainsString('North', $result->message);
    $this->assertStringContainsString('jur-member', $result->message);
  }

  /**
   * An organisation membership under the default member role is reported.
   */
  public function testOrganisationMembershipIsReported(): void {
    $this->organisation->addMember($this->keyAccount);

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame([
      $this->finding($this->organisation, 'org-member (insider)'),
    ], $result->details);
  }

  /**
   * A staff role assigned to the key account is reported.
   */
  public function testStaffRoleOnKeyAccountIsReported(): void {
    $this->north->getMember($this->keyAccount)->getGroupRelationship()
      ->set('group_roles', ['jur-member', 'jur-editorial'])
      ->save();

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame([
      $this->finding($this->north, 'jur-editorial (individual)'),
    ], $result->details);
  }

  /**
   * An admin role grants drafts without listing the permission.
   */
  public function testAdminRoleIsReported(): void {
    GroupRole::create([
      'id' => 'jur-admin',
      'label' => 'Admin',
      'group_type' => 'jur',
      'scope' => 'individual',
      'admin' => TRUE,
    ])->save();
    $this->south->getMember($this->keyAccount)->getGroupRelationship()
      ->set('group_roles', ['jur-member', 'jur-admin'])
      ->save();

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame([
      $this->finding($this->south, 'jur-admin (individual, admin)'),
    ], $result->details);
  }

  /**
   * An outsider role reaches every group the account is not a member of.
   */
  public function testOutsiderRoleIsReported(): void {
    $west = Group::create(['type' => 'jur', 'label' => 'West']);
    $west->save();
    GroupRole::load('jur-outsider')->grantPermission(KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION)->save();
    $this->assertTrue($west->hasPermission(KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION, $this->keyAccount));

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame([
      [
        'account' => 'api_user (' . $this->keyAccount->id() . '), key frontend',
        'group' => '1 jur group(s) without membership not listed individually',
        'group_type' => 'jur',
        'scope' => 'outsider',
        'roles' => 'jur-outsider (outsider)',
      ],
    ], $result->details);
  }

  /**
   * The outsider finding counts every non-member group and skips members.
   */
  public function testOutsiderFindingCountsEveryGroupWithoutMembership(): void {
    foreach (['West', 'East'] as $label) {
      Group::create(['type' => 'jur', 'label' => $label])->save();
    }
    GroupRole::load('jur-outsider')->grantPermission('view unpublished group_node:service_request entity')->save();
    // A member group: the outsider role does not apply there.
    $this->assertFalse($this->north->hasPermission('view unpublished group_node:service_request entity', $this->keyAccount));

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame(2, $result->count);
    $this->assertCount(1, $result->details);
    $this->assertSame('2 jur group(s) without membership not listed individually', $result->details[0]['group']);
    $this->assertSame('outsider', $result->details[0]['scope']);
  }

  /**
   * A calculator grant without membership is reported for that group only.
   */
  public function testIndividualGrantWithoutMembershipIsReported(): void {
    $west = Group::create(['type' => 'jur', 'label' => 'West']);
    $west->save();
    $east = Group::create(['type' => 'jur', 'label' => 'East']);
    $east->save();
    $this->container->get('state')->set(IndividualDraftGrantCalculator::STATE_KEY, [
      'uid' => (int) $this->keyAccount->id(),
      'gid' => (int) $west->id(),
    ]);
    $this->assertFalse($west->getMember($this->keyAccount));
    $this->assertTrue($west->hasPermission('view unpublished group_node:service_request entity', $this->keyAccount));
    $this->assertFalse($east->hasPermission('view unpublished group_node:service_request entity', $this->keyAccount));

    $result = $this->runCheck();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertSame([
      [
        'account' => 'api_user (' . $this->keyAccount->id() . '), key frontend',
        'group' => sprintf('West (%d)', $west->id()),
        'group_type' => 'jur',
        'scope' => 'individual without membership',
        'roles' => 'no group role (granted by a permission calculator)',
      ],
    ], $result->details);
  }

  /**
   * A key account entitled to drafts is not reported.
   */
  public function testAdvancedKeyAccountIsIgnored(): void {
    GroupRole::load('jur-member')->grantPermission(KeyAccountUnpublishedReadCheck::DRAFT_PERMISSION)->save();
    $this->organisation->addMember($this->keyAccount);
    // The Open311 permission belongs to a module this test does not install.
    // Write the role as a configuration import would, without the entity
    // API's check that every permission is provided by an enabled module.
    Role::create(['id' => 'integration', 'label' => 'Integration'])->save();
    $this->config('user.role.integration')
      ->set('permissions', [KeyAccountUnpublishedReadCheck::ADVANCED_PERMISSION])
      ->save();
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    $this->keyAccount->addRole('integration')->save();
    $account = User::load($this->keyAccount->id());
    $this->assertTrue($account->hasPermission(KeyAccountUnpublishedReadCheck::ADVANCED_PERMISSION));

    $result = $this->runCheck();

    $this->assertTrue($result->passed, $result->message);
  }

  /**
   * The plugin is discovered with the agreed severity.
   */
  public function testPluginDiscovery(): void {
    $definition = $this->container->get('plugin.manager.markaspot_health_check')
      ->getDefinition('key_account_unpublished_read');

    $this->assertSame(KeyAccountUnpublishedReadCheck::class, $definition['class']);
    $this->assertSame('warning', $definition['severity']);
  }

  /**
   * Runs the check through the plugin manager.
   */
  private function runCheck(): HealthCheckResult {
    $plugin = $this->container->get('plugin.manager.markaspot_health_check')
      ->createInstance('key_account_unpublished_read');
    return $plugin->run();
  }

  /**
   * Builds the expected detail row of a membership finding.
   *
   * @return array<string, string>
   *   The detail row.
   */
  private function finding(GroupInterface $group, string $roles): array {
    return [
      'account' => 'api_user (' . $this->keyAccount->id() . '), key frontend',
      'group' => sprintf('%s (%d)', $group->label(), $group->id()),
      'group_type' => $group->bundle(),
      'scope' => 'member',
      'roles' => $roles,
    ];
  }

}
