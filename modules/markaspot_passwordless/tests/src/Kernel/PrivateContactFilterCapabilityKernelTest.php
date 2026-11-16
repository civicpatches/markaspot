<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Hook\ImplementationList;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\jsonapi\Controller\EntityResource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\services_api_key_auth\Entity\ApiKey;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests real JSON:API contact filter decisions before checking capabilities.
 *
 * @group markaspot_passwordless
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class PrivateContactFilterCapabilityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file',
    'entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group',
    'serialization', 'jsonapi', 'field_permissions', 'markaspot_nuxt',
    'image', 'media', 'taxonomy', 'options', 'datetime', 'language',
    'entity_reference_revisions', 'paragraphs', 'markaspot_validation',
    'service_request', 'markaspot_passwordless', 'services_api_key_auth',
  ];

  /**
   * Fictional workspaces and accounts used by every access decision.
   *
   * @var array<string, \Drupal\group\Entity\Group>
   */
  private array $groups = [];

  /**
   * Fixture accounts, including scope exceptions without field permissions.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  private array $accounts = [];

  /**
   * The report containing the contact search values in a foreign workspace.
   */
  private Node $foreign;

  /**
   * Whether the independent decisions are also compared with the status API.
   */
  private bool $compareCapabilities = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_group', ['markaspot_group_invitations']);
    $this->installConfig(['system', 'user', 'field', 'node', 'group', 'services_api_key_auth']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member', 'entity_type' => 'user', 'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member', 'entity_type' => 'user', 'bundle' => 'user',
    ])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $this->accounts['root'] = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $this->accounts['root']->save();
    $this->container->get('current_user')->setAccount($this->accounts['root']);
    // Let every fixture query published rows so the field guard alone must
    // prevent a contact oracle, even where node visibility cannot protect it.
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    foreach (['jur', 'org'] as $id) {
      GroupType::create(['id' => $id, 'label' => $id])->save();
      $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
      foreach (['group_membership', 'group_node:service_request'] as $plugin) {
        $type = $storage->createFromPlugin(GroupType::load($id), $plugin);
        if (!$storage->load($type->id())) {
          $type->save();
        }
      }
    }
    foreach (['jur', 'org'] as $type) {
      foreach (['insider', 'outsider'] as $scope) {
        foreach ($scope === 'outsider' ? ['authenticated', 'anonymous'] : ['authenticated'] as $global) {
          GroupRole::create([
            'id' => $type . '-' . $scope . '-' . $global,
            'label' => 'Published reader', 'group_type' => $type,
            'scope' => $scope, 'global_role' => $global,
            'permissions' => ['view group_node:service_request entity'],
          ])->save();
        }
      }
    }
    foreach (['jur-moderator', 'jur-org_member'] as $id) {
      GroupRole::create([
        'id' => $id, 'label' => $id, 'group_type' => 'jur', 'scope' => 'individual',
        'permissions' => $id === 'jur-moderator' ? ['view group_node:service_request entity'] : [],
      ])->save();
    }
    $this->reference('group', 'jur', 'field_parent_jurisdiction', 'group');
    $this->reference('group', 'org', 'field_jurisdiction', 'group');
    $this->reference('node', 'service_request', 'field_jurisdiction', 'group');
    $this->reference('node', 'service_request', 'field_organisation', 'group');
    $this->reference('node', 'service_request', 'field_related_report', 'node');
    $this->reference('node', 'service_request', 'field_status', 'node');
    foreach (['field_e_mail' => 'email', 'field_phone' => 'string'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => 'node', 'type' => $type,
        'third_party_settings' => ['field_permissions' => ['permission_type' => 'custom']],
      ])->save();
      FieldConfig::create([
        'field_name' => $name, 'entity_type' => 'node', 'bundle' => 'service_request',
      ])->save();
    }
    Role::load('anonymous')->grantPermission('access content')->save();
    Role::load('authenticated')->grantPermission('access content')
      ->grantPermission('view own field_e_mail')->grantPermission('view own field_phone')->save();
    foreach ([
      'moderator' => ['view field_e_mail', 'view field_phone'],
      'editorial_board' => ['bypass node access'],
      'operator' => ['bypass node access'],
      'contact_reader' => ['view field_e_mail', 'view field_phone'],
      'mail_reader' => ['view field_e_mail'],
      'phone_reader' => ['view field_phone'],
      'administrator' => ['bypass node access'],
    ] as $id => $permissions) {
      Role::create(['id' => $id, 'label' => $id, 'permissions' => $permissions])->save();
    }
    $this->groups['own'] = Group::create(['type' => 'jur', 'label' => 'Workspace A']);
    $this->groups['own']->save();
    $this->groups['child'] = Group::create([
      'type' => 'jur', 'label' => 'Child A', 'field_parent_jurisdiction' => $this->groups['own']->id(),
    ]);
    $this->groups['child']->save();
    $this->groups['foreign'] = Group::create(['type' => 'jur', 'label' => 'Workspace B']);
    $this->groups['foreign']->save();
    foreach (['org_a', 'org_b'] as $id) {
      $this->groups[$id] = Group::create([
        'type' => 'org', 'label' => $id, 'field_jurisdiction' => $this->groups['own']->id(),
      ]);
      $this->groups[$id]->save();
    }
    foreach ([
      'citizen' => [], 'api' => [], 'own' => ['moderator'], 'foreign' => ['moderator'],
      'parent' => ['moderator'], 'child' => ['moderator'], 'org_a' => ['moderator'],
      'org_b' => ['moderator'], 'administrator' => ['administrator'],
      'editorial_denied' => ['editorial_board'], 'bypass_denied' => ['operator'],
      'editorial' => ['editorial_board', 'contact_reader'],
      'bypass' => ['operator', 'contact_reader'],
      'mail_only' => ['editorial_board', 'mail_reader'],
      'phone_only' => ['operator', 'phone_reader'],
    ] as $id => $roles) {
      $account = User::create(['name' => $id, 'status' => 1, 'roles' => $roles]);
      $account->save();
      $this->accounts[$id] = $account;
    }
    $this->accounts['anonymous'] = User::getAnonymousUser();
    foreach (['own' => 'own', 'foreign' => 'foreign', 'parent' => 'own', 'child' => 'child'] as $id => $group) {
      $this->groups[$group]->addMember($this->accounts[$id], ['group_roles' => ['jur-moderator']]);
    }
    foreach (['org_a', 'org_b'] as $id) {
      $this->groups[$id]->addMember($this->accounts[$id]);
    }
    $this->foreign = $this->report('foreign', 'hidden-contact@example.test', '555000999');
    $this->report('own', 'own@example.test', '555000111', $this->accounts['citizen'])
      ->set('field_related_report', $this->foreign->id())->save();
    $this->report('child', 'child@example.test', '555000222');
    $this->container->get('entity_type.manager')->getDefinition('node')->setHandlerClass('query_access', NULL);
    drupal_static_reset();
    $this->assertSame(realpath(__FILE__), (new \ReflectionClass(self::class))->getFileName());
    $this->assertSame(realpath(dirname(__DIR__, 4) . '/markaspot_group/markaspot_group.module'),
      realpath((new \ReflectionFunction('markaspot_group_jsonapi_entity_field_filter_access'))->getFileName()));
    $this->assertSame(realpath(dirname(__DIR__, 3) . '/src/Controller/PasswordlessAuthController.php'),
      realpath((new \ReflectionClass(PasswordlessAuthController::class))->getFileName()));
  }

  /**
   * The actual collection parser and queries reject unsafe contact predicates.
   */
  #[DataProvider('decisionModes')]
  public function testIndependentMultiWorkspaceDecisions(bool $compareCapabilities): void {
    $this->compareCapabilities = $compareCapabilities;
    foreach ($this->accounts as $id => $account) {
      foreach (['field_e_mail', 'field_phone'] as $field) {
        $allowed = in_array($id, ['root', 'administrator', 'editorial', 'bypass'], TRUE)
          || ($id === 'mail_only' && $field === 'field_e_mail')
          || ($id === 'phone_only' && $field === 'field_phone');
        $this->assertDecision($account, $field, $allowed);
      }
    }
    $owned = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uid' => $this->accounts['citizen']->id()]);
    $owned = reset($owned);
    $this->assertTrue($owned->get('field_e_mail')->access('view', $this->accounts['citizen']));
    $this->assertDecision($this->accounts['citizen'], 'field_e_mail', FALSE, 'own@example.test');
  }

  /**
   * OR groups, references, sorts and client flags cannot bypass the decision.
   */
  #[DataProvider('decisionModes')]
  public function testIndependentManipulatedRequests(bool $compareCapabilities): void {
    $this->compareCapabilities = $compareCapabilities;
    foreach (['field_e_mail', 'field_phone'] as $field) {
      $value = $this->searchValue($field);
      $queries = [
        ['filter' => [
          'or' => ['group' => ['conjunction' => 'OR']],
          'public' => ['condition' => ['path' => 'title', 'value' => 'Public report', 'memberOf' => 'or']],
          'private' => ['condition' => ['path' => $field, 'value' => $value, 'memberOf' => 'or']],
        ]],
        ['filter' => ['reference' => ['condition' => [
          'path' => 'field_related_report.' . $field, 'value' => $value,
        ]]]],
        ['sort' => $field],
        ['sort' => '-' . $field],
        ['sort' => ['contact' => ['path' => 'field_related_report.' . $field]]],
      ];
      foreach ($queries as $query) {
        $query['capabilities'] = ['filter_field_e_mail' => TRUE, 'filter_field_phone' => TRUE];
        foreach ([FALSE, TRUE] as $count) {
          try {
            $this->collection($this->accounts['own'], $query, $count);
            $this->fail('Manipulated contact request must be denied before rows or counts.');
          }
          catch (CacheableAccessDeniedHttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertStringContainsString($field, $exception->getMessage());
            if ($this->compareCapabilities) {
              $this->assertCapability($this->accounts['own'], $field, FALSE);
            }
          }
        }
      }
    }
  }

  /**
   * An authenticated API key keeps exactly the key account's field decision.
   */
  #[DataProvider('decisionModes')]
  public function testIndependentApiKeyDecision(bool $compareCapabilities): void {
    $this->compareCapabilities = $compareCapabilities;
    ApiKey::create([
      'id' => 'contact_test', 'label' => 'Synthetic test key', 'key' => 'synthetic-contact-key',
      'user_uuid' => $this->accounts['api']->uuid(),
    ])->save();
    $request = Request::create('/jsonapi/node/service_request');
    $request->headers->set('api_key', 'synthetic-contact-key');
    /** @var \Drupal\Core\Authentication\AuthenticationProviderInterface $provider */
    $provider = $this->container->get('services_api_key_auth.authentication.api_key_auth');
    $this->assertTrue($provider->applies($request));
    $account = $provider->authenticate($request);
    $this->assertSame($this->accounts['api']->id(), $account->id());
    foreach (['field_e_mail', 'field_phone'] as $field) {
      $this->assertDecision($account, $field, FALSE);
    }
  }

  /**
   * Single-workspace exceptions still distinguish organisation-only staff.
   */
  #[DataProvider('decisionModes')]
  public function testIndependentSingleWorkspaceAndRevocation(bool $compareCapabilities): void {
    $this->compareCapabilities = $compareCapabilities;
    $count = &drupal_static('_markaspot_group_jurisdiction_group_count');
    $count = 1;
    $staff = $this->accounts['own'];
    $this->groups['org_a']->addMember($staff);
    foreach (['field_e_mail', 'field_phone'] as $field) {
      $this->assertDecision($staff, $field, TRUE);
      $this->assertDecision($this->accounts['org_b'], $field, FALSE);
    }
    $this->groups['own']->removeMember($staff);
    foreach (['field_e_mail', 'field_phone'] as $field) {
      $this->assertDecision($staff, $field, FALSE);
    }
    $this->groups['own']->addMember($staff, ['group_roles' => ['jur-moderator']]);
    Role::load('moderator')->revokePermission('view field_e_mail')->save();
    $this->assertDecision($staff, 'field_e_mail', FALSE);
    $this->assertDecision($staff, 'field_phone', TRUE);
  }

  /**
   * Field configuration and the anonymous role remain authoritative.
   */
  #[DataProvider('decisionModes')]
  public function testIndependentFieldConfiguration(bool $compareCapabilities): void {
    $this->compareCapabilities = $compareCapabilities;
    FieldStorageConfig::loadByName('node', 'field_phone')
      ->unsetThirdPartySetting('field_permissions', 'permission_type')->save();
    $this->assertDecision($this->accounts['anonymous'], 'field_phone', TRUE);
    $this->assertDecision($this->accounts['own'], 'field_e_mail', FALSE);
    Role::load('anonymous')->grantPermission('view field_e_mail')->save();
    $this->assertDecision($this->accounts['anonymous'], 'field_e_mail', TRUE);
    $this->assertDecision($this->accounts['own'], 'field_e_mail', TRUE);
    $this->assertDecision($this->accounts['citizen'], 'field_e_mail', FALSE);
  }

  /**
   * The real OTP authentication path does not add contact-filter privileges.
   */
  public function testOtpCitizenCannotFilterEvenOwnContactValues(): void {
    $citizen = $this->accounts['citizen'];
    $citizen->setEmail('citizen@example.test')->save();
    $service = $this->container->get('markaspot_passwordless.otp');
    $account = (new \ReflectionMethod($service, 'authenticateUser'))->invoke($service, 'citizen@example.test');
    $this->assertSame($citizen->id(), $account->id());
    foreach (['field_e_mail', 'field_phone'] as $field) {
      $this->assertDecision($account, $field, FALSE);
      $this->assertCapability($account, $field, FALSE);
    }
    $this->assertDecision($account, 'field_e_mail', FALSE, 'own@example.test');
  }

  /**
   * Missing fields and another module's veto stay authoritative.
   */
  public function testMissingFieldAndAdditionalVeto(): void {
    $account = $this->accounts['editorial'];
    $this->assertDecision($account, 'field_phone', TRUE);
    $this->assertCapability($account, 'field_phone', TRUE);
    FieldConfig::loadByName('node', 'service_request', 'field_phone')->delete();
    $this->assertCapability($account, 'field_phone', FALSE);
    $this->assertDecision($account, 'field_e_mail', TRUE);
    $this->assertCapability($account, 'field_e_mail', TRUE);

    // Add an independent filter-hook veto to Core's actual hook registry.
    $handler = $this->container->get('module_handler');
    $method = new \ReflectionMethod($handler, 'getHookImplementationList');
    $hook = 'jsonapi_entity_field_filter_access';
    $list = $method->invoke($handler, $hook);
    $property = new \ReflectionProperty($handler, 'hookImplementationLists');
    $lists = $property->getValue($handler);
    $lists[$hook] = new ImplementationList([
      ...$list->listeners,
      static fn () => AccessResult::forbidden('Independent contact veto'),
    ], [...$list->modules, 'test_contact_veto']);
    $property->setValue($handler, $lists);
    $this->assertDecision($account, 'field_e_mail', FALSE);
    $this->assertCapability($account, 'field_e_mail', FALSE);
  }

  /**
   * A reused controller follows account switches and field revocation live.
   */
  public function testReusedStatusControllerDoesNotCarryCapabilities(): void {
    $controller = PasswordlessAuthController::create($this->container);
    foreach (['mail_only', 'phone_only', 'anonymous', 'editorial'] as $id) {
      $account = $this->accounts[$id];
      $this->container->get('current_user')->setAccount($account);
      $response = $controller->status(Request::create('/api/auth/status'));
      $payload = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
      foreach (['field_e_mail', 'field_phone'] as $field) {
        $allowed = $id === 'editorial' || ($id === 'mail_only' && $field === 'field_e_mail')
          || ($id === 'phone_only' && $field === 'field_phone');
        $this->assertDecision($account, $field, $allowed);
        $this->assertSame($allowed, $payload['capabilities']['filter_' . $field]);
      }
    }
    Role::load('contact_reader')->revokePermission('view field_phone')->save();
    $response = $controller->status(Request::create('/api/auth/status'));
    $payload = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertTrue($payload['capabilities']['filter_field_e_mail']);
    $this->assertFalse($payload['capabilities']['filter_field_phone']);
    $this->assertDecision($this->accounts['editorial'], 'field_phone', FALSE);
  }

  /**
   * First prove the existing decision, then compare the status capability.
   */
  public static function decisionModes(): array {
    return ['independent' => [FALSE], 'capability parity' => [TRUE]];
  }

  /**
   * Status capabilities are fresh, account-specific and never cacheable.
   */
  private function assertCapability(AccountInterface $account, string $field, bool $allowed): void {
    $this->container->get('current_user')->setAccount($account);
    $response = PasswordlessAuthController::create($this->container)->status(Request::create('/api/auth/status'));
    $payload = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame($allowed, $payload['capabilities']['filter_' . $field]);
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Executes a direct Drupal JSON:API collection row or count query.
   */
  private function collection(AccountInterface $account, array $query, bool $count): array|int {
    $this->container->get('current_user')->setAccount($account);
    $request = Request::create('/jsonapi/node/service_request', 'GET', $query);
    $request->attributes->set('_route_object', new Route('/jsonapi/node/service_request', [
      '_is_jsonapi' => TRUE, 'resource_type' => 'node--service_request',
      '_controller' => 'jsonapi.entity_resource:getCollection',
    ]));
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $class = new \ReflectionClass(EntityResource::class);
      $resource = $class->newInstanceWithoutConstructor();
      foreach ([
        'entityTypeManager' => 'entity_type.manager',
        'fieldManager' => 'entity_field.manager',
        'fieldResolver' => 'jsonapi.field_resolver',
      ] as $property => $service) {
        $class->getProperty($property)->setValue($resource, $this->container->get($service));
      }
      $type = $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request');
      $params = $class->getMethod('getJsonApiParams')->invoke($resource, $request, $type);
      $method = $count ? 'getCollectionCountQuery' : 'getCollectionQuery';
      $entity_query = $class->getMethod($method)->invoke($resource, $type, $params, new CacheableMetadata());
      $result = $entity_query->execute();

      return $count ? (int) $result : array_map('intval', array_values($result));
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Checks both hits and counts without using any capability implementation.
   */
  private function assertDecision(AccountInterface $account, string $field, bool $allowed, ?string $value = NULL): void {
    foreach ([FALSE, TRUE] as $count) {
      try {
        $result = $this->collection($account, ['filter' => [
          'contact' => ['condition' => ['path' => $field, 'value' => $value ?? $this->searchValue($field)]],
        ]], $count);
        $this->assertTrue($allowed, $account->getAccountName() . ': ' . $field);
        $this->assertSame($count ? 1 : [(int) $this->foreign->id()], $result, $account->getAccountName() . ': ' . $field);
      }
      catch (CacheableAccessDeniedHttpException $exception) {
        $this->assertFalse($allowed, $account->getAccountName() . ': ' . $field . ': ' . $exception->getMessage());
        $this->assertSame(403, $exception->getStatusCode());
        $this->assertStringContainsString($field, $exception->getMessage());
      }
    }
    if ($this->compareCapabilities) {
      $this->assertCapability($account, $field, $allowed);
    }
  }

  /**
   * Contact values occur only in the foreign report.
   */
  private function searchValue(string $field): string {
    return $field === 'field_e_mail' ? 'hidden-contact@example.test' : '555000999';
  }

  /**
   * Creates a reference field with an unlimited cardinality.
   */
  private function reference(string $entityType, string $bundle, string $field, string $target): void {
    if (!FieldStorageConfig::loadByName($entityType, $field)) {
      FieldStorageConfig::create([
        'field_name' => $field, 'entity_type' => $entityType, 'type' => 'entity_reference',
        'cardinality' => -1, 'settings' => ['target_type' => $target],
      ])->save();
    }
    FieldConfig::create(['field_name' => $field, 'entity_type' => $entityType, 'bundle' => $bundle])->save();
  }

  /**
   * Stores a synthetic report under the selected workspace.
   */
  private function report(string $group, string $email, string $phone, ?UserInterface $owner = NULL): Node {
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Public report', 'status' => 1,
      'uid' => $owner?->id() ?? 1, 'field_jurisdiction' => $this->groups[$group]->id(),
      'field_e_mail' => $email, 'field_phone' => $phone,
    ]);
    $node->save();
    return $node;
  }

}
