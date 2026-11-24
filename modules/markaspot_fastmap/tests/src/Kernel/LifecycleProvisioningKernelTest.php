<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\AcceptanceRequiredException;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the lifecycle record that provisioning writes, with real storage.
 *
 * Same harness as DemoRequestPublicationKernelTest.
 *
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService::provisionWorkspace
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class LifecycleProvisioningKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'color_field',
    'geolocation',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_fastmap',
    // After markaspot_group, like the alphabetical order in production: the
    // initial status is forced before the creation note is written.
    'service_request',
  ];

  /**
   * The acceptance as it arrives from the pending signup row.
   */
  private const ACCEPTANCE = [
    'terms' => '2026-11-15',
    'dpa' => '2026-11-15',
    'declaration' => '1.0',
    'langcode' => 'de',
    'accepted_at' => 1799990000,
  ];

  /**
   * When set, saving an account with an acceptance time fails.
   */
  private bool $failAcceptanceSave = FALSE;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // A worktree run discovers the module's services from another checkout.
    if (!$container->hasDefinition('markaspot_fastmap.workspace_lifecycle')) {
      $container->register('markaspot_fastmap.workspace_lifecycle', WorkspaceLifecycle::class)
        ->setArguments([new Reference('config.factory'), new Reference('datetime.time')])
        ->setPublic(TRUE);
    }
    if (!$container->hasDefinition('markaspot_fastmap.workspace_lifecycle_gate')) {
      $container->register('markaspot_fastmap.workspace_lifecycle_gate', WorkspaceLifecycleGate::class)
        ->setArguments([
          new Reference('markaspot_fastmap.workspace_lifecycle'),
          new Reference('entity_type.manager'),
          new Reference('module_handler'),
          new Reference('request_stack'),
          new Reference('markaspot_group.hierarchy_resolver'),
        ])
        ->setPublic(TRUE);
    }
  }

  /**
   * Stands in for the module's save hook where it comes from elsewhere.
   *
   * Makes the seeding exemption run against the gate of this checkout.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    $path = $this->container->get('extension.list.module')->getPath('markaspot_fastmap');
    if (realpath(DRUPAL_ROOT . '/' . $path) === realpath(dirname(__DIR__, 3))) {
      return;
    }
    $this->container->get('markaspot_fastmap.workspace_lifecycle_gate')->enforceOnSave(
      $node,
      $this->container->get('current_user'),
      static fn(): ?GroupInterface => _markaspot_fastmap_resolve_jurisdiction_group_for_node($node),
      (bool) drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE),
    );
  }

  /**
   * Makes the account save after the record write fail, on request.
   */
  #[Hook('user_presave')]
  public function userPresave(UserInterface $account): void {
    if ($this->failAcceptanceSave && !$account->get('field_tos_accepted_at')->isEmpty()) {
      throw new \RuntimeException('Simulated failure after the record write.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->createField('group', 'jur', WorkspaceLifecycle::FIELD, 'string_long');
    $this->createField('user', 'user', 'field_tos_accepted_at', 'timestamp');
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();
  }

  /**
   * Switch on: a valid record for the tenant admin and the account time.
   */
  public function testSwitchOnWritesRecordAndAcceptanceTime(): void {
    $this->setSwitch(TRUE);
    $result = $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);

    $group = $this->reloadGroup($result['group_id']);
    $record = WorkspaceLifecycle::parse($group->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertNotNull($record, 'The stored record is valid.');
    $this->assertSame($result['user_id'], $record['acceptance']['uid']);
    $this->assertSame(['terms' => '2026-11-15', 'dpa' => '2026-11-15', 'declaration' => '1.0'], array_intersect_key($record['acceptance'], array_flip(WorkspaceLifecycle::DOCUMENTS)));
    $this->assertSame(1799990000, $record['acceptance']['accepted_at']);
    $this->assertSame($result['user_id'], $record['opening']['uid']);
    $this->assertGreaterThan(0, $record['opening']['opened_at']);
    $this->assertNull($record['opening']['operator']);

    $owner = $this->reloadUser($result['user_id']);
    $this->assertSame('parents@example.test', $owner->getEmail());
    $this->assertSame(1799990000, (int) $owner->get('field_tos_accepted_at')->value);
    $this->assertNotSame([], $group->getRelationshipsByEntity($owner, 'group_membership'), 'The record names a member of the workspace.');
    // Saving the acceptance time must not drop the roles the membership
    // granted: the owner stays tenant admin of the workspace (found in a
    // browser acceptance: a stale account object removed both roles).
    $this->assertTrue($owner->hasRole('tenant_admin'), 'The owner keeps the tenant_admin role.');
    $roles = $this->membershipRoles($group, $owner);
    $this->assertContains($group->bundle() . '-tenant_admin', $roles, 'The owner keeps the tenant admin role of the workspace.');
  }

  /**
   * The module's own hook registration closes the written record.
   *
   * Proves the code of this checkout only where the enabled module is this
   * checkout (CI). In a worktree the site discovers the module elsewhere, so
   * the test skips there instead of testing another branch.
   *
   * The deny must stay in the module's only implementation of
   * hook_entity_field_access(): core merges field access results per module,
   * so a second implementation would overwrite one of the two results.
   */
  public function testRealRegistrationDeniesRecord(): void {
    $loaded = $this->container->get('extension.list.module')->getPath('markaspot_fastmap');
    if (realpath(DRUPAL_ROOT . '/' . $loaded) !== realpath(dirname(__DIR__, 3))) {
      $this->markTestSkipped('markaspot_fastmap is loaded from another checkout: ' . $loaded);
    }
    $this->setSwitch(TRUE);
    $result = $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);
    $group = $this->reloadGroup($result['group_id']);
    $this->assertNotNull(WorkspaceLifecycle::parse($group->get(WorkspaceLifecycle::FIELD)->value), 'Written although every account is denied.');
    foreach ([User::load(1), $this->reloadUser($result['user_id'])] as $account) {
      $this->assertFalse($group->get(WorkspaceLifecycle::FIELD)->access('view', $account));
      $this->assertFalse($group->get(WorkspaceLifecycle::FIELD)->access('edit', $account));
    }
  }

  /**
   * Switch on: Free without expiry, public examples, open intake and review.
   */
  public function testSwitchOnProvisionsAnOpenFreeWorkspace(): void {
    $this->setSwitch(TRUE);
    $this->setSetting('markaspot_operating_mode', 'saas');
    foreach (['lindenhof' => '', 'riverside' => 'pro'] as $slug => $selectedTier) {
      $result = $this->provision($slug, ['acceptance' => self::ACCEPTANCE, 'selected_tier' => $selectedTier]);
      $group = $this->reloadGroup($result['group_id']);
      $this->assertSame('free', $group->get('field_tier')->value, "$slug: tier free");
      $this->assertTrue($group->get('field_expiry_date')->isEmpty(), "$slug: no expiry, also with a chosen tier");
      $config = json_decode((string) $group->get('field_nuxt_config')->value, TRUE);
      $this->assertTrue($config['features']['reviewBeforePublish'], "$slug: review on");
      $this->assertTrue($this->container->get('markaspot_fastmap.workspace_lifecycle')->isOpen($group), "$slug: open");

      $examples = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
        'type' => 'service_request',
        'field_jurisdiction' => $result['group_id'],
      ]);
      $this->assertNotEmpty($examples, "$slug: examples seeded");
      foreach ($examples as $example) {
        $this->assertTrue($example->isPublished(), "$slug: the seeded examples are public");
      }
    }

    // Anonymous intake is open and the approval flag still holds the report.
    $this->setCurrentUser(new AnonymousUserSession());
    $report = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'service_request',
      'title' => 'Citizen report',
      'field_jurisdiction' => $result['group_id'],
      'body' => ['value' => 'Cars park on the sidewalk.', 'format' => 'plain_text'],
    ]);
    $report->save();
    $this->assertFalse($report->isPublished());
  }

  /**
   * Verification opens once and sends one support mail.
   */
  public function testVerificationSendsSupportMailOnce(): void {
    $this->setSwitch(TRUE);
    $this->setSetting('markaspot_operating_mode', 'saas');
    $this->installSchema('markaspot_fastmap', ['markaspot_fastmap_pending', 'markaspot_fastmap_verified']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('markaspot_fastmap.settings')->set('support_email', 'support@example.test')->save();
    $token = str_repeat('a', 64);
    $database = $this->container->get('database');
    $database->insert('markaspot_fastmap_pending')->fields([
      'token' => $token,
      'email' => 'parents@example.test',
      'workspace_data' => json_encode($this->signup('verified', ['acceptance' => self::ACCEPTANCE]), JSON_THROW_ON_ERROR),
      'created' => $this->container->get('datetime.time')->getRequestTime(),
    ])->execute();

    $controller = FastMapWorkspaceController::create($this->container);
    $response = $controller->verifyWorkspace($token);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $group = $this->reloadGroup(json_decode((string) $response->getContent(), TRUE)['id']);
    $this->assertTrue($this->container->get('markaspot_fastmap.workspace_lifecycle')->isOpen($group));

    $this->assertSame(200, $controller->verifyWorkspace($token)->getStatusCode());
    $group->set('field_platform_name', 'Updated map');
    $group->save();
    $mails = array_values(array_filter($this->container->get('state')->get('system.test_mail_collector', []), static fn(array $mail): bool => $mail['key'] === WorkspaceOpening::MAIL_KEY));
    $this->assertCount(1, $mails);
    $this->assertSame('support@example.test', $mails[0]['to']);
    $this->assertSame('parents@example.test', $mails[0]['params']['opened_by']);
    $this->assertSame('(not provided)', $mails[0]['params']['operator_name']);
  }

  /**
   * Switch on without acceptance: no group, no account.
   */
  public function testSwitchOnWithoutAcceptanceLeavesNothing(): void {
    $this->setSwitch(TRUE);
    try {
      $this->provision('lindenhof');
      $this->fail('Provisioning ran without an acceptance.');
    }
    catch (AcceptanceRequiredException) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame(0, $this->countGroups());
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'parents@example.test']));
  }

  /**
   * A second workspace of the same account gets its own record.
   */
  public function testSecondWorkspaceOfSameAccountGetsOwnRecord(): void {
    $this->setSwitch(TRUE);
    $first = $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);
    $later = ['accepted_at' => 1799995000, 'terms' => '2026-11-20'] + self::ACCEPTANCE;
    $second = $this->provision('riverside', ['acceptance' => $later]);

    $this->assertSame($first['user_id'], $second['user_id']);
    $firstRecord = WorkspaceLifecycle::parse($this->reloadGroup($first['group_id'])->get(WorkspaceLifecycle::FIELD)->value);
    $secondRecord = WorkspaceLifecycle::parse($this->reloadGroup($second['group_id'])->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertSame('2026-11-15', $firstRecord['acceptance']['terms']);
    $this->assertSame('2026-11-20', $secondRecord['acceptance']['terms']);
    $this->assertSame($first['user_id'], $secondRecord['acceptance']['uid']);
    $this->assertSame(1799995000, (int) $this->reloadUser($first['user_id'])->get('field_tos_accepted_at')->value);
    // The reused account is tenant admin of both workspaces.
    $owner = $this->reloadUser($first['user_id']);
    $this->assertTrue($owner->hasRole('tenant_admin'));
    foreach ([$first['group_id'], $second['group_id']] as $groupId) {
      $group = $this->reloadGroup($groupId);
      $this->assertContains($group->bundle() . '-tenant_admin', $this->membershipRoles($group, $owner));
    }
  }

  /**
   * Switch off: no record and no account time, acceptance or not.
   */
  public function testSwitchOffWritesNoRecord(): void {
    $this->setSwitch(FALSE);
    $result = $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);
    $this->assertTrue($this->reloadGroup($result['group_id'])->get(WorkspaceLifecycle::FIELD)->isEmpty());
    $this->assertTrue($this->reloadUser($result['user_id'])->get('field_tos_accepted_at')->isEmpty());
    // Today's provisioning still sets the expiry.
    $this->assertFalse($this->reloadGroup($result['group_id'])->get('field_expiry_date')->isEmpty());
  }

  /**
   * A failure after the record write rolls the whole workspace back.
   */
  public function testFailureAfterRecordWriteRollsBack(): void {
    $this->setSwitch(TRUE);
    $this->failAcceptanceSave = TRUE;
    try {
      $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);
      $this->fail('Provisioning succeeded despite the simulated failure.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Simulated failure after the record write', $e->getMessage());
    }
    $this->assertSame(0, $this->countGroups());
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'parents@example.test']));
    $this->assertSame(0, (int) \Drupal::database()->select('group__' . WorkspaceLifecycle::FIELD)->countQuery()->execute()->fetchField());
  }

  /**
   * A new owner account speaks the accepted language; nothing else changes.
   */
  public function testNewOwnerGetsTheAcceptanceLanguage(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Switch off: today's account, whatever the signup carries.
    $this->setSwitch(FALSE);
    $old = $this->provision('oldtown', ['acceptance' => self::ACCEPTANCE, 'email' => 'old@example.test']);
    $this->assertNotSame('de', $this->reloadUser($old['user_id'])->getPreferredLangcode(FALSE));

    $this->setSwitch(TRUE);
    $result = $this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE]);
    $owner = $this->reloadUser($result['user_id']);
    $this->assertSame('de', $owner->getPreferredLangcode(FALSE));

    // An existing account keeps its language.
    $later = ['langcode' => 'en'] + self::ACCEPTANCE;
    $second = $this->provision('riverside', ['acceptance' => $later]);
    $this->assertSame($result['user_id'], $second['user_id']);
    $this->assertSame('de', $this->reloadUser($second['user_id'])->getPreferredLangcode(FALSE));

    // A language the site does not have leaves the default.
    $unknown = ['langcode' => 'fi'] + self::ACCEPTANCE;
    $third = $this->provision('hillside', ['acceptance' => $unknown, 'email' => 'other@example.test']);
    $this->assertSame($this->reloadUser($old['user_id'])->getPreferredLangcode(FALSE), $this->reloadUser($third['user_id'])->getPreferredLangcode(FALSE));
  }

  /**
   * Lifecycle model: the examples lie in the first map view.
   *
   * At zoom 15 around 50.83, 6.9 a fixed spiral of at most 30 % of a tile
   * (about 230 m); the switch off keeps the random spot in the boundary box.
   */
  public function testExamplesLieInTheInitialView(): void {
    $this->setSwitch(TRUE);
    $categories = ['Dangerous crossing', 'Missing sign', 'Parked cars', 'Broken light'];
    $first = $this->examplePositions($this->provision('lindenhof', [
      'acceptance' => self::ACCEPTANCE,
      'categories' => $categories,
    ]));
    $this->assertCount(4, $first);
    foreach ($first as [$lat, $lng]) {
      $this->assertLessThan(240.0, $this->metres(50.83, 6.9, $lat, $lng), 'within the first view');
    }
    $this->assertCount(4, array_unique(array_map('serialize', $first)), 'distinct spots');
    $second = $this->examplePositions($this->provision('riverside', [
      'acceptance' => self::ACCEPTANCE,
      'categories' => $categories,
    ]));
    $this->assertSame($first, $second, 'the same picture for the same center');

    $this->setSwitch(FALSE);
    $old = $this->examplePositions($this->provision('oldtown', ['categories' => $categories]));
    $this->assertCount(4, $old);
    foreach ($old as [$lat, $lng]) {
      $this->assertNotContains([$lat, $lng], $first, 'today\'s random placement');
      $this->assertGreaterThanOrEqual(50.8, $lat);
      $this->assertLessThanOrEqual(51.1, $lat);
      $this->assertGreaterThanOrEqual(6.8, $lng);
      $this->assertLessThanOrEqual(7.1, $lng);
    }
  }

  /**
   * Lifecycle model: the start page is true before and after the opening.
   */
  public function testStartPageFitsBothMapStates(): void {
    $this->setSwitch(TRUE);
    $de = $this->startPageBody($this->provision('lindenhof', ['acceptance' => self::ACCEPTANCE, 'language' => 'de']));
    $this->assertStringContainsString('Solange die Karte noch keine neuen', $de);
    $this->assertStringNotContainsString('selbst etwas Neues zu erfassen', $de);
    $en = $this->startPageBody($this->provision('riverside', ['acceptance' => self::ACCEPTANCE]));
    $this->assertStringContainsString('If the map is not taking new', $en);
    $this->assertStringNotContainsString('add something new', $en);
    // A language without a lifecycle text: the welcome paragraph only.
    $fr = $this->startPageBody($this->provision('hillside', ['acceptance' => self::ACCEPTANCE, 'language' => 'fr']));
    $this->assertStringStartsWith('<p>Bienvenue sur <strong>Map hillside</strong>', $fr);
    $this->assertSame(1, substr_count($fr, '<p>'));

    // Old model: today's text.
    $this->setSwitch(FALSE);
    $old = $this->startPageBody($this->provision('oldtown', ['language' => 'de', 'email' => 'old@example.test']));
    $this->assertStringContainsString('selbst etwas Neues zu erfassen', $old);
  }

  /**
   * The body of a workspace's start page.
   */
  private function startPageBody(array $result): string {
    $pages = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
      'type' => 'page',
      'field_jurisdiction' => $result['group_id'],
    ]);
    $this->assertCount(1, $pages);
    return (string) reset($pages)->get('body')->value;
  }

  /**
   * The example positions of a workspace, sorted by node ID.
   *
   * @return array<int, array{float, float}>
   *   [lat, lng] per example.
   */
  private function examplePositions(array $result): array {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
      'type' => 'service_request',
      'field_jurisdiction' => $result['group_id'],
    ]);
    ksort($nodes);
    return array_values(array_map(static fn(NodeInterface $node): array => [
      round((float) $node->get('field_geolocation')->lat, 6),
      round((float) $node->get('field_geolocation')->lng, 6),
    ], $nodes));
  }

  /**
   * Distance between two points in metres (equirectangular).
   */
  private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
    $y = deg2rad($lat2 - $lat1);
    return sqrt($x * $x + $y * $y) * 6371000.0;
  }

  /**
   * The group role IDs of an account's membership in a group.
   *
   * Read from the membership relationship's group_roles field, without the
   * deprecated membership wrapper.
   *
   * @return string[]
   *   Group role IDs.
   */
  private function membershipRoles(GroupInterface $group, UserInterface $account): array {
    $roles = [];
    foreach ($group->getRelationshipsByEntity($account, 'group_membership') as $relationship) {
      foreach ($relationship->get('group_roles') as $item) {
        $roles[] = (string) $item->target_id;
      }
    }
    return $roles;
  }

  /**
   * Stores the switch the way a config import or drush cset would.
   */
  private function setSwitch(bool $on): void {
    $this->container->get('config.storage')->write('markaspot_fastmap.settings', [
      'lifecycle_enabled' => $on,
      'demo_expiry_days' => 5,
      'checkout_grace_days' => 14,
    ]);
    $this->container->get('config.factory')->reset('markaspot_fastmap.settings');
  }

  /**
   * Provisions a workspace for the same e-mail address.
   */
  private function provision(string $slug, array $extra = []): array {
    return $this->container->get('markaspot_fastmap.workspace_provisioning')->provisionWorkspace($this->signup($slug, $extra));
  }

  /**
   * Builds workspace data stored by a pending signup.
   */
  private function signup(string $slug, array $extra): array {
    return $extra + [
      'boundary' => ['type' => 'Polygon', 'coordinates' => [[[6.8, 50.8], [7.1, 50.8], [7.1, 51.1], [6.8, 50.8]]]],
      'name' => 'Map ' . $slug,
      'slug' => $slug,
      'email' => 'parents@example.test',
      'categories' => ['Dangerous crossing'],
      'language' => 'en',
      'lat' => 50.83,
      'lng' => 6.9,
      'zoom' => 15,
      'template' => 'safe-routes',
    ];
  }

  /**
   * Counts jurisdiction groups in storage.
   */
  private function countGroups(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Loads a group fresh from storage.
   */
  private function reloadGroup(int $id): Group {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Loads an account fresh from storage.
   */
  private function reloadUser(int $id): User {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Stubs the Open311 processor the report hooks consult.
   *
   * Like production, markaspot_group forces a new report to the
   * jurisdiction's initial status, and service_request writes the creation
   * note through the processor.
   */
  private function stubProcessor(): void {
    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->container->set('markaspot_open311.processor', new class($termStorage) {

      public function __construct(
        private readonly EntityStorageInterface $termStorage,
      ) {}

      /**
       * Returns the lowest-weight initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->sort('weight')
          ->range(0, 1)
          ->execute();
        return $tids ? (int) reset($tids) : NULL;
      }

      /**
       * No boilerplates in this test.
       */
      public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int {
        return NULL;
      }

      /**
       * Creates a status note like the real processor, without boilerplates.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create(['type' => 'status']);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        $paragraph->save();
        return $paragraph;
      }

    });
  }

  /**
   * Creates the node and taxonomy model provisioning writes to.
   */
  private function createContentModel(): void {
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    foreach (['plain_text' => 'Plain text', 'basic_html' => 'Basic HTML'] as $format => $name) {
      if (!FilterFormat::load($format)) {
        FilterFormat::create(['format' => $format, 'name' => $name])->save();
      }
    }

    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'body', 'text_with_summary');
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_address', 'string');
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
  }

  /**
   * Creates the jurisdiction group type, roles and fields.
   */
  private function createJurisdictionModel(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    foreach (['tenant_admin' => 'Tenant Admin', 'member' => 'Member'] as $role => $label) {
      GroupRole::create([
        'id' => 'jur-' . $role,
        'label' => $label,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }

    $relationshipStorage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_expiry_date', 'timestamp');
    $this->createField('group', 'jur', 'field_tier', 'list_string', [
      'allowed_values' => ['free' => 'Free', 'starter' => 'Starter', 'pro' => 'Pro', 'heart' => 'Heart'],
    ]);
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => $storageSettings,
    ])->save();
  }

}
