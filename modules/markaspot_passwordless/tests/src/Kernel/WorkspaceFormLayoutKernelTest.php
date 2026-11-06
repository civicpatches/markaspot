<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolver;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_passwordless\Controller\FormLayoutPreferencesController;
use Drupal\markaspot_passwordless\Controller\WorkspaceFormLayoutController;
use Drupal\markaspot_passwordless\Service\WorkspaceFormLayoutStore;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the workspace default layout on real jurisdiction groups.
 *
 * Controllers are constructed here instead of being routed, so the test runs
 * this checkout's classes; the route definitions are covered by
 * WorkspaceFormLayoutTest.
 */
#[TestGroup('markaspot_passwordless')]
#[RunTestsInSeparateProcesses]
final class WorkspaceFormLayoutKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options',
    'entity', 'flexible_permissions', 'group',
  ];

  /**
   * The site UUID that demo account UUIDs derive from.
   */
  private const SITE_UUID = '11bd3935-672b-41b3-9f90-aefecbc86350';

  /**
   * Jurisdictions keyed by slug.
   *
   * "root" is a tenant root, "child" its sub-jurisdiction and "foreign" the
   * root of another tenant.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  private array $jurisdictions = [];

  /**
   * The workspace default store.
   */
  private WorkspaceFormLayoutStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'group', 'group_relationship', 'group_config_wrapper'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'group']);
    $this->config('system.site')->set('uuid', self::SITE_UUID)->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root_account = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root_account->save();
    $this->container->get('current_user')->setAccount($root_account);
    foreach (['administrator', 'tenant_admin', 'moderator'] as $role) {
      Role::create(['id' => $role, 'label' => $role])->save();
    }

    $type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE]);
    $type->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationship_types->load('jur-group_membership')) {
      $relationship_types->createFromPlugin($type, 'group_membership')->save();
    }
    foreach (['tenant_admin', 'moderator', 'member'] as $role) {
      GroupRole::create([
        'id' => 'jur-' . $role, 'label' => $role, 'group_type' => 'jur',
        'scope' => 'individual', 'permissions' => [],
      ])->save();
    }
    foreach ([
      'field_nuxt_config' => ['text_long', []],
      'field_parent_jurisdiction' => ['entity_reference', ['target_type' => 'group']],
      'field_slug' => ['string', []],
    ] as $name => [$field_type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => 'group', 'type' => $field_type, 'settings' => $settings,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'group', 'bundle' => 'jur', 'label' => $name])->save();
    }

    $root = $this->jurisdiction('root', [
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'blue'], 'forms' => ['pickOnMapButton' => TRUE]]),
    ]);
    $this->jurisdiction('child', ['field_parent_jurisdiction' => $root->id()]);
    $this->jurisdiction('foreign');

    $this->store = new WorkspaceFormLayoutStore(
      $this->container->get('entity_type.manager'),
      $this->hierarchy(),
    );
  }

  /**
   * Access follows TenantSettingsController::accessCheck() unchanged.
   */
  public function testAccessFollowsTenantSettingsRule(): void {
    // Label => [account, expected access on root, child, foreign].
    $cases = [
      'platform admin' => [
        $this->account('platform', ['administrator']),
        [TRUE, TRUE, TRUE],
      ],
      'root tenant admin' => [
        $this->account('root-admin', ['tenant_admin'], [['root', 'tenant_admin']]),
        [TRUE, TRUE, FALSE],
      ],
      'child tenant admin' => [
        $this->account('child-admin', ['tenant_admin'], [['child', 'tenant_admin']]),
        [FALSE, TRUE, FALSE],
      ],
      'moderator' => [
        $this->account('moderator', ['moderator'], [['root', 'moderator']]),
        [FALSE, FALSE, FALSE],
      ],
      'member' => [
        $this->account('member', [], [['root', 'member']]),
        [FALSE, FALSE, FALSE],
      ],
      'tenant_admin role, member membership' => [
        $this->account('role-only', ['tenant_admin'], [['root', 'member']]),
        [FALSE, FALSE, FALSE],
      ],
    ];
    foreach ($cases as $label => [$account, $expected]) {
      foreach (array_combine(['root', 'child', 'foreign'], $expected) as $slug => $allowed) {
        $this->assertSame($allowed, $this->tenantSettings($account)->accessCheck($account, $slug)->isAllowed(), "$label on $slug");
        $this->assertSame($allowed, $this->tenantSettings($account)->accessCheck($account, (string) $this->jurisdictions[$slug]->id())->isAllowed(), "$label on $slug ID");
      }
    }
  }

  /**
   * The root holds the default; sub-jurisdictions and staff read it.
   */
  public function testRootStoresAndSubJurisdictionsInherit(): void {
    $admin = $this->account('root-admin', ['tenant_admin'], [['root', 'tenant_admin']]);
    $controller = $this->controller($admin);
    $layout = $this->layout();

    $this->assertSame(['editLayout' => NULL], $this->json($controller->getLayout(Request::create('/'), 'child')));
    $this->assertSame(['editLayout' => $layout], $this->json($controller->updateLayout($this->patch($layout), 'root'), 200));

    $stored = $this->storedConfig($this->jurisdictions['root']);
    $this->assertSame($layout, $stored[WorkspaceFormLayoutStore::CONFIG_KEY]['editLayout']);
    // The public "forms" key keeps its own settings and never the layout.
    $this->assertSame(['pickOnMapButton' => TRUE], $stored['forms']);
    $this->assertSame(['primary' => 'blue'], $stored['theme']);
    $this->assertSame([], $this->storedConfig($this->jurisdictions['child']));

    $this->assertSame(['editLayout' => $layout], $this->json($controller->getLayout(Request::create('/'), 'child')));
    $this->assertSame(['editLayout' => $layout], $this->json($controller->getLayout(Request::create('/'), (string) $this->jurisdictions['root']->id())));
    $this->assertNull($this->store->get((int) $this->jurisdictions['foreign']->id()));

    // Staff see the default next to their own layout, which still wins.
    $member = $this->account('member', [], [['child', 'member']]);
    $personal = $this->personal($member);
    $this->assertSame(
      ['layout' => NULL, 'writable' => TRUE, 'workspaceDefault' => $layout, 'workspaceDefaultWritable' => FALSE],
      $this->json($personal->preferences(Request::create('/'), (string) $this->jurisdictions['child']->id())),
    );
    $own = ['version' => 1, 'left' => FormLayoutPreferencesController::SECTIONS, 'right' => []];
    $personal->preferences(Request::create('/', 'PATCH', [], [], [], [], json_encode(['layout' => $own])), (string) $this->jurisdictions['child']->id());
    $this->assertSame(
      ['layout' => $own, 'writable' => TRUE, 'workspaceDefault' => $layout, 'workspaceDefaultWritable' => FALSE],
      $this->json($personal->preferences(Request::create('/'), (string) $this->jurisdictions['child']->id())),
    );
    $this->assertSame($layout, $this->storedConfig($this->jurisdictions['root'])[WorkspaceFormLayoutStore::CONFIG_KEY]['editLayout']);

    // A root admin may write through a sub-jurisdiction; it lands on the root.
    $reordered = ['version' => 1, 'left' => [], 'right' => FormLayoutPreferencesController::SECTIONS];
    $this->json($controller->updateLayout($this->patch($reordered), 'child'), 200);
    $this->assertSame($reordered, $this->storedConfig($this->jurisdictions['root'])[WorkspaceFormLayoutStore::CONFIG_KEY]['editLayout']);
    $this->assertSame([], $this->storedConfig($this->jurisdictions['child']));

    // NULL resets to the built-in layout and keeps the other form settings.
    $this->assertSame(['editLayout' => NULL], $this->json($controller->updateLayout($this->patch(NULL), 'root'), 200));
    $this->assertSame(['forms' => ['pickOnMapButton' => TRUE], 'theme' => ['primary' => 'blue']], $this->sorted($this->storedConfig($this->jurisdictions['root'])));
    $this->assertSame(
      ['layout' => $own, 'writable' => TRUE, 'workspaceDefault' => NULL, 'workspaceDefaultWritable' => FALSE],
      $this->json($personal->preferences(Request::create('/'), (string) $this->jurisdictions['child']->id())),
    );
  }

  /**
   * A sub-jurisdiction admin reads the default but cannot change the root.
   */
  public function testSubJurisdictionAdminCannotWriteRootDefault(): void {
    $this->store->set((int) $this->jurisdictions['root']->id(), $this->layout());
    $before = $this->storedConfig($this->jurisdictions['root']);
    $admin = $this->account('child-admin', ['tenant_admin'], [['child', 'tenant_admin']]);
    $controller = $this->controller($admin);

    $this->assertSame(['editLayout' => $this->layout()], $this->json($controller->getLayout(Request::create('/'), 'child')));
    $this->json($controller->updateLayout($this->patch(NULL), 'child'), 403);
    $this->assertSame($before, $this->storedConfig($this->jurisdictions['root']));
  }

  /**
   * Unknown and duplicate section IDs are rejected and never stored.
   */
  public function testInvalidLayoutsAreRejected(): void {
    $this->store->set((int) $this->jurisdictions['root']->id(), $this->layout());
    $before = $this->storedConfig($this->jurisdictions['root']);
    $controller = $this->controller($this->account('platform', ['administrator']));
    $sections = FormLayoutPreferencesController::SECTIONS;
    foreach ([
      'unknown ID' => ['version' => 1, 'left' => array_merge(array_slice($sections, 0, 9), ['invented']), 'right' => []],
      'duplicate ID' => ['version' => 1, 'left' => array_slice($sections, 0, 9), 'right' => ['details']],
      'missing ID' => ['version' => 1, 'left' => array_slice($sections, 0, 9), 'right' => []],
      'unknown version' => ['version' => 2, 'left' => $sections, 'right' => []],
    ] as $label => $layout) {
      $response = $controller->updateLayout($this->patch($layout), 'root');
      $this->assertSame(422, $response->getStatusCode(), $label);
    }
    $this->assertSame($before, $this->storedConfig($this->jurisdictions['root']));

    // A corrupt stored value reads as "no default" for every endpoint.
    $this->jurisdictions['root'] = $this->reload($this->jurisdictions['root']);
    $corrupt = [
      WorkspaceFormLayoutStore::CONFIG_KEY => [
        'editLayout' => ['version' => 1, 'left' => ['details'], 'right' => []],
      ],
    ];
    $this->jurisdictions['root']->set('field_nuxt_config', json_encode($corrupt))->save();
    $this->assertSame(['editLayout' => NULL], $this->json($controller->getLayout(Request::create('/'), 'child')));
  }

  /**
   * Demo staff accounts can neither read nor write the workspace default.
   */
  public function testDemoAccountCannotUseEndpoint(): void {
    $this->store->set((int) $this->jurisdictions['root']->id(), $this->layout());
    $before = $this->storedConfig($this->jurisdictions['root']);
    // Worst case: the demo account even holds tenant admin rights.
    $demo_user = User::create([
      'name' => DemoStaffAccount::accountName('demo-layout'),
      'uuid' => DemoWorkspace::uuid(self::SITE_UUID, 'demo-layout', 'user', 'staff'),
      'status' => 1,
      'roles' => ['tenant_admin'],
    ]);
    $demo_user->save();
    $this->jurisdictions['root']->addMember($demo_user, ['group_roles' => ['jur-tenant_admin']]);
    $demo = new DemoStaffAccount(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->createMock(UserSessionRepositoryInterface::class),
      $this->createMock(TimeInterface::class),
    );
    $this->assertTrue($demo->isDemoAccount($demo_user));

    $controller = $this->controller($demo_user, $demo);
    $this->json($controller->getLayout(Request::create('/'), 'root'), 403);
    $this->json($controller->updateLayout($this->patch(NULL), 'root'), 403);
    $this->assertSame($before, $this->storedConfig($this->jurisdictions['root']));

    // The personal endpoint stays read-only for demos, as before.
    $personal = $this->personal($demo_user, $demo);
    $this->assertSame(
      [
        'layout' => NULL,
        'writable' => FALSE,
        'workspaceDefault' => $this->layout(),
        'workspaceDefaultWritable' => FALSE,
      ],
      $this->json($personal->preferences(Request::create('/'), (string) $this->jurisdictions['root']->id())),
    );
    $this->json($personal->preferences(Request::create('/', 'PATCH', [], [], [], [], '{"layout":null}'), (string) $this->jurisdictions['root']->id()), 403);
  }

  /**
   * The preferences GET reports the PATCH rule as workspaceDefaultWritable.
   */
  public function testWorkspaceDefaultWritableFollowsPatchRule(): void {
    $demo_user = User::create([
      'name' => DemoStaffAccount::accountName('demo-layout'),
      'uuid' => DemoWorkspace::uuid(self::SITE_UUID, 'demo-layout', 'user', 'staff'),
      'status' => 1,
      'roles' => ['tenant_admin'],
    ]);
    $demo_user->save();
    $this->jurisdictions['root']->addMember($demo_user, ['group_roles' => ['jur-tenant_admin']]);
    $demo = new DemoStaffAccount(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->createMock(UserSessionRepositoryInterface::class),
      $this->createMock(TimeInterface::class),
    );

    // Label => [account, demo recognizer, expected flag on root and child].
    $root_admin = $this->account('root-admin', ['tenant_admin'], [['root', 'tenant_admin']]);
    $child_admin = $this->account('child-admin', ['tenant_admin'], [['child', 'tenant_admin']]);
    $moderator = $this->account('moderator', ['moderator'], [['root', 'moderator']]);
    $cases = [
      'platform admin' => [$this->account('platform', ['administrator']), NULL, [TRUE, TRUE]],
      'root tenant admin' => [$root_admin, NULL, [TRUE, TRUE]],
      'sub-jurisdiction tenant admin' => [$child_admin, NULL, [FALSE, FALSE]],
      'moderator' => [$moderator, NULL, [FALSE, FALSE]],
      'demo account with tenant admin membership' => [$demo_user, $demo, [FALSE, FALSE]],
    ];
    foreach ($cases as $label => [$account, $recognizer, $expected]) {
      foreach (array_combine(['root', 'child'], $expected) as $slug => $writable) {
        $body = $this->json($this->personal($account, $recognizer)->preferences(Request::create('/'), (string) $this->jurisdictions[$slug]->id()));
        $this->assertSame($writable, $body['workspaceDefaultWritable'], "$label on $slug");

        // The flag matches what the PATCH endpoint answers for the account.
        $status = $this->controller($account, $recognizer)->updateLayout($this->patch($this->layout()), $slug)->getStatusCode();
        $route_allowed = $this->tenantSettings($account)->accessCheck($account, $slug)->isAllowed();
        $this->assertSame($writable, $route_allowed && $status === 200, "$label PATCH on $slug");
      }
    }
  }

  /**
   * Returns a valid layout.
   */
  private function layout(): array {
    return [
      'version' => 1,
      'left' => ['details', 'location', 'media', 'contact', 'status_history'],
      'right' => ['status_actions', 'context', 'service_provider', 'internal', 'related'],
    ];
  }

  /**
   * Creates a published jurisdiction group.
   */
  private function jurisdiction(string $slug, array $values = []): GroupInterface {
    $group = Group::create($values + ['type' => 'jur', 'label' => ucfirst($slug), 'field_slug' => $slug, 'status' => 1]);
    $group->save();
    $this->jurisdictions[$slug] = $group;
    return $group;
  }

  /**
   * Creates an account with global roles and jurisdiction memberships.
   *
   * @param string $name
   *   The account name.
   * @param string[] $roles
   *   Global role IDs.
   * @param array<int, array{0: string, 1: string}> $memberships
   *   Pairs of jurisdiction property name and group role suffix.
   */
  private function account(string $name, array $roles, array $memberships = []): UserInterface {
    $account = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $account->save();
    foreach ($memberships as [$jurisdiction, $role]) {
      $this->jurisdictions[$jurisdiction]->addMember($account, ['group_roles' => ['jur-' . $role]]);
    }
    return $account;
  }

  /**
   * Builds the real hierarchy resolver.
   */
  private function hierarchy(): JurisdictionHierarchyResolver {
    return new JurisdictionHierarchyResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
    );
  }

  /**
   * Builds the tenant settings controller that owns the access rule.
   */
  private function tenantSettings(AccountInterface $account): TenantSettingsController {
    return new TenantSettingsController(
      $this->container->get('entity_type.manager'),
      $this->createMock(StreamWrapperManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(FileUsageInterface::class),
      $account,
      $this->hierarchy(),
      $this->createMock(FeatureScopeResolver::class),
      $this->createMock(EmailValidator::class),
      NULL,
    );
  }

  /**
   * Builds the workspace default controller for an account.
   */
  private function controller(AccountInterface $account, ?DemoStaffAccount $demo = NULL): WorkspaceFormLayoutController {
    return new WorkspaceFormLayoutController($account, $this->container->get('entity_type.manager'), $this->store, $this->resolver($account), $demo);
  }

  /**
   * Builds a class resolver that hands out the tenant settings controller.
   */
  private function resolver(AccountInterface $account): ClassResolverInterface {
    $resolver = $this->createMock(ClassResolverInterface::class);
    $resolver->method('getInstanceFromDefinition')->willReturnCallback(
      fn(string $definition) => $definition === TenantSettingsController::class ? $this->tenantSettings($account) : NULL,
    );
    return $resolver;
  }

  /**
   * Builds the personal layout controller for an account.
   */
  private function personal(AccountInterface $account, ?DemoStaffAccount $demo = NULL): FormLayoutPreferencesController {
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('allowsReadFor')->willReturn(TRUE);
    return new FormLayoutPreferencesController(
      $account,
      $this->container->get('entity_type.manager'),
      $this->container->get('user.data'),
      $visibility,
      $demo,
      $this->store,
      $this->resolver($account),
    );
  }

  /**
   * Builds a PATCH request for the workspace default.
   */
  private function patch(?array $layout): Request {
    return Request::create('/', 'PATCH', [], [], [], [], json_encode(['editLayout' => $layout]));
  }

  /**
   * Decodes a response after checking its status.
   */
  private function json(JsonResponse $response, int $status = 200): mixed {
    $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Reloads a group from storage.
   */
  private function reload(GroupInterface $group): GroupInterface {
    return $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($group->id());
  }

  /**
   * Returns the stored field_nuxt_config of a group.
   */
  private function storedConfig(GroupInterface $group): array {
    $value = $this->reload($group)->get('field_nuxt_config')->value;
    return is_string($value) && $value !== '' ? json_decode($value, TRUE) : [];
  }

  /**
   * Sorts an array by key for order-independent comparison.
   */
  private function sorted(array $value): array {
    ksort($value);
    return $value;
  }

}
