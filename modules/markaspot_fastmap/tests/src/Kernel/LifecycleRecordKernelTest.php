<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\LifecycleRecordSchema;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';

/**
 * Tests the lifecycle record field on an updated site and through JSON:API.
 *
 * The site starts like an existing tenant: a jurisdiction with data, real
 * members and, depending on the test, with or without a JSON:API resource
 * config for the jurisdiction. The update step must add the switch, the
 * exclusion and the field without touching the workspace, and nobody may
 * read, filter, sort or write the record through JSON:API afterwards.
 *
 * The module is not enabled here (its dependency tree is not needed), so its
 * two access hook functions, loaded from this checkout, are registered
 * through this class. LifecycleProvisioningKernelTest covers the module's
 * own registration.
 *
 * The deny lives in markaspot_fastmap_entity_field_access(), the module's
 * only implementation of that hook. Core merges field access results per
 * module (EntityAccessControlHandler::fieldAccess()), so a second
 * implementation in the module would overwrite one of the two results.
 */
#[CoversClass(LifecycleRecordSchema::class)]
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class LifecycleRecordKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The fastmap settings are written without enabling the module, whose
   * dependency tree is not needed here, so their schema is not loaded.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'node',
    'options',
    'entity',
    'flexible_permissions',
    'group',
    'field_permissions',
    'serialization',
    'jsonapi',
    'jsonapi_extras',
  ];

  /**
   * The record value written to the workspace.
   */
  private const MARKER = 'lifecycle-marker-2026';

  /**
   * Whether the module's deny is active (off only for the control test).
   */
  private bool $deny = TRUE;

  /**
   * An existing workspace.
   */
  private GroupInterface $workspace;

  /**
   * Accounts that try to reach the record, by label.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  private array $accounts = [];

  /**
   * The tenant admin of the workspace.
   */
  private User $tenantAdmin;

  /**
   * Delegates to the module's hook function.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    return $this->deny ? markaspot_fastmap_entity_field_access($operation, $field_definition, $account, $items) : AccessResult::neutral();
  }

  /**
   * Delegates to the module's hook function.
   */
  #[Hook('jsonapi_entity_field_filter_access')]
  public function jsonapiEntityFieldFilterAccess(FieldDefinitionInterface $field_definition, AccountInterface $account): AccessResultInterface {
    return $this->deny ? markaspot_fastmap_jsonapi_entity_field_filter_access($field_definition, $account) : AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['system', 'user', 'field', 'group', 'jsonapi', 'jsonapi_extras']);
    // Writes are allowed, so PATCH and POST reach the field checks.
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationshipTypes->load('jur-group_membership')) {
      $relationshipTypes->createFromPlugin(GroupType::load('jur'), 'group_membership')->save();
    }
    // Workspaces are publicly readable, as on a tenant: the record must stay
    // hidden although the group itself is not.
    foreach (['anonymous', 'authenticated'] as $globalRole) {
      GroupRole::create([
        'id' => 'jur-outsider_' . $globalRole,
        'label' => 'Outsider ' . $globalRole,
        'group_type' => 'jur',
        'scope' => 'outsider',
        'global_role' => $globalRole,
        'permissions' => ['view group'],
      ])->save();
    }
    $roles = [
      'jur-member' => ['view group'],
      'jur-tenant_admin' => ['view group', 'edit group', 'administer members'],
    ];
    foreach ($roles as $id => $permissions) {
      GroupRole::create([
        'id' => $id,
        'label' => $id,
        'group_type' => 'jur',
        'scope' => 'individual',
        'permissions' => $permissions,
      ])->save();
    }

    $this->workspace = Group::create(['type' => 'jur', 'label' => 'Existing map', 'uid' => 1]);
    $this->workspace->save();

    $member = $this->createUser([], 'member');
    $this->workspace->addMember($member, ['group_roles' => ['jur-member']]);
    $this->tenantAdmin = $this->createUser([], 'tenant-admin');
    $this->workspace->addMember($this->tenantAdmin, ['group_roles' => ['jur-tenant_admin']]);
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $siteAdmin = $this->createUser([], 'site-admin');
    $siteAdmin->addRole('administrator')->save();

    $this->accounts = [
      'anonymous' => new AnonymousUserSession(),
      'jur-member' => $member,
      'jur-tenant_admin' => $this->tenantAdmin,
      'site administrator' => $siteAdmin,
      'uid 1' => User::load(1),
    ];
  }

  /**
   * The update step is complete, idempotent and writes no workspace data.
   */
  public function testUpdateStepOnExistingSite(): void {
    $this->installResourceConfig();
    $groups = $this->container->get('entity_type.manager')->getStorage('group');
    $before = $groups->loadUnchanged($this->workspace->id())->toArray();
    $this->assertNull($this->config('markaspot_fastmap.settings')->get('lifecycle_enabled'));

    $messages = $this->schema()->apply();
    $this->assertSame([
      'Added the lifecycle switch (off).',
      'Disabled field_lifecycle_record on the jurisdiction JSON:API resource.',
      'Created the lifecycle record field: storage, instance.',
    ], $messages);

    $this->assertFalse($this->config('markaspot_fastmap.settings')->get('lifecycle_enabled'));
    $entry = $this->config(LifecycleRecordSchema::RESOURCE_CONFIG)->get('resourceFields')[WorkspaceLifecycle::FIELD];
    $this->assertTrue($entry['disabled']);
    $storage = $this->container->get('entity_type.manager')->getStorage('field_storage_config')->load('group.' . WorkspaceLifecycle::FIELD);
    $this->assertSame('custom', $storage->getThirdPartySetting('field_permissions', 'permission_type'));

    // Second run: nothing to do.
    $this->assertSame([
      'Lifecycle switch already present.',
      'The lifecycle record is already disabled on JSON:API.',
      'The lifecycle record field already exists.',
    ], $this->schema()->apply());

    $reloaded = $groups->loadUnchanged($this->workspace->id());
    $this->assertTrue($reloaded->get(WorkspaceLifecycle::FIELD)->isEmpty());
    $after = $reloaded->toArray();
    unset($after[WorkspaceLifecycle::FIELD]);
    $this->assertSame($before, $after, 'The update step changed workspace data.');
  }

  /**
   * Without a resource config, the module's deny alone closes the record.
   *
   * The state of tenants whose exported config has no group--jur resource
   * config: JSON:API exposes every field there.
   */
  public function testRecordIsClosedWithoutResourceConfig(): void {
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('jsonapi_resource_config')->load('group--jur'));
    $workspace = $this->prepareRecord();
    $this->assertTrue($this->container->get('jsonapi.resource_type.repository')->get('group', 'jur')->isFieldEnabled(WorkspaceLifecycle::FIELD));

    $this->assertRecordClosed($workspace, FALSE);
  }

  /**
   * With the resource config the field is also disabled there.
   */
  public function testRecordIsClosedWithResourceConfig(): void {
    $this->installResourceConfig();
    $workspace = $this->prepareRecord();
    $this->assertFalse($this->container->get('jsonapi.resource_type.repository')->get('group', 'jur')->isFieldEnabled(WorkspaceLifecycle::FIELD));

    $this->assertRecordClosed($workspace, TRUE);
  }

  /**
   * Control: without the deny and the resource config, uid 1 reads it.
   *
   * Shows that the checks above detect an exposed record.
   */
  public function testControlWithoutDenyExposesRecord(): void {
    $this->deny = FALSE;
    $workspace = $this->prepareRecord();
    $this->setCurrentUser(User::load(1));
    $response = $this->request('GET', '/jsonapi/group/jur/' . $workspace->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString(self::MARKER, (string) $response->getContent());
  }

  /**
   * An existing field of that name is checked; safe settings are corrected.
   */
  public function testUpdateStepCorrectsExistingField(): void {
    FieldStorageConfig::create([
      'field_name' => WorkspaceLifecycle::FIELD,
      'entity_type' => 'group',
      'type' => 'string_long',
      'translatable' => TRUE,
      'third_party_settings' => ['field_permissions' => ['permission_type' => 'public']],
    ])->save();
    FieldConfig::create([
      'field_name' => WorkspaceLifecycle::FIELD,
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Lifecycle record',
      'translatable' => TRUE,
      'default_value' => [['value' => $this->recordValue()]],
      'default_value_callback' => static::class . '::defaultRecord',
    ])->save();

    $messages = $this->schema()->apply();

    // The instance is effectively not translatable once its storage is not.
    $this->assertSame('The lifecycle record field already exists. Corrected: field permission type custom, storage not translatable, default value removed, default value callback removed.', $messages[2]);
    $instance = FieldConfig::loadByName('group', 'jur', WorkspaceLifecycle::FIELD);
    $this->assertSame([], $instance->getDefaultValueLiteral());
    $this->assertSame('', (string) $instance->getDefaultValueCallback());
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $fresh = Group::create(['type' => 'jur', 'label' => 'New map', 'uid' => 1]);
    $this->assertTrue($fresh->get(WorkspaceLifecycle::FIELD)->isEmpty(), 'A new workspace starts without a record.');
    $storage = FieldStorageConfig::loadByName('group', WorkspaceLifecycle::FIELD);
    $this->assertSame('custom', $storage->getThirdPartySetting('field_permissions', 'permission_type'));
    $this->assertFalse($storage->isTranslatable());
    $this->assertFalse(FieldConfig::loadByName('group', 'jur', WorkspaceLifecycle::FIELD)->isTranslatable());
    $this->assertSame('The lifecycle record field already exists.', $this->schema()->apply()[2]);
  }

  /**
   * An incompatible field of that name stops the update.
   */
  public function testUpdateStepRefusesIncompatibleField(): void {
    $conflicts = [
      ['type' => 'string', 'cardinality' => 1, 'message' => 'exists with type "string"'],
      ['type' => 'string_long', 'cardinality' => 2, 'message' => 'exists with cardinality 2'],
    ];
    foreach ($conflicts as $conflict) {
      $storage = FieldStorageConfig::create([
        'field_name' => WorkspaceLifecycle::FIELD,
        'entity_type' => 'group',
        'type' => $conflict['type'],
        'cardinality' => $conflict['cardinality'],
      ]);
      $storage->save();
      try {
        $this->schema()->apply();
        $this->fail('The update step accepted an incompatible field: ' . $conflict['message']);
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString($conflict['message'], $e->getMessage());
      }
      $this->assertNull(FieldConfig::loadByName('group', 'jur', WorkspaceLifecycle::FIELD), 'No instance on a conflicting storage.');
      $storage->delete();
    }
  }

  /**
   * The dump scrub zeroes the account in every revision and keeps the record.
   *
   * --verify ends the process with exit(1) on residue, so the gate is covered
   * through the counter it uses, as for the other carriers.
   */
  public function testScrubScriptCoversRecords(): void {
    $this->schema()->apply();
    $groups = $this->container->get('entity_type.manager')->getStorage('group');
    $groups->resetCache();
    $acceptance = [
      'terms' => 't1',
      'dpa' => 'd1',
      'declaration' => 'o1',
      'langcode' => 'en',
      'accepted_at' => 1800000000,
    ];
    $record = json_encode(WorkspaceLifecycle::buildRecord($acceptance, 5));

    $workspace = Group::load($this->workspace->id());
    // Fixture write outside the lifecycle service: marked as syncing, the
    // only save the record rule lets through besides the service's own.
    $workspace->setSyncing(TRUE);
    $workspace->set(WorkspaceLifecycle::FIELD, $record)->save();
    $workspace->setSyncing(FALSE);
    $workspace->setNewRevision(TRUE);
    $workspace->set('label', 'Existing map, renamed')->save();
    $garbage = Group::create([
      'type' => 'jur',
      'label' => 'Garbage',
      'uid' => 1,
      WorkspaceLifecycle::FIELD => 'uid 5, not json',
    ]);
    $garbage->save();
    $opened = Group::create([
      'type' => 'jur',
      'label' => 'Opened',
      'uid' => 1,
      WorkspaceLifecycle::FIELD => json_encode(['opening' => ['name' => 'Jane Doe']] + WorkspaceLifecycle::buildRecord($acceptance, 6)),
    ]);
    $opened->save();

    putenv('IS_DDEV_PROJECT=true');
    // The script reads its drush arguments from $extra: none means dry run.
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    $extra = [];
    ob_start();
    include dirname(__DIR__, 5) . '/scripts/scrub-pii.php';
    $output = (string) ob_get_clean();

    $tables = scrub_lifecycle_tables();
    $this->assertSame(['group__field_lifecycle_record', 'group_revision__field_lifecycle_record'], $tables);
    $this->assertStringContainsString('Lifecycle group__field_lifecycle_record: 3 records would be rewritten', $output);
    $this->assertSame($record, $groups->loadUnchanged($workspace->id())->get(WorkspaceLifecycle::FIELD)->value, 'The dry run wrote.');
    $this->assertSame(3, scrub_lifecycle_count_unscrubbed('group__field_lifecycle_record'));
    $this->assertSame(4, scrub_lifecycle_count_unscrubbed('group_revision__field_lifecycle_record'));

    foreach ($tables as $table) {
      // The opened record is left for a later version and keeps verify red.
      $this->assertSame(1, scrub_lifecycle_sweep($table), $table);
      $this->assertSame(1, scrub_lifecycle_count_unscrubbed($table), $table);
    }

    // The sweep writes the tables directly; the apply run flushes all caches
    // afterwards, this test only the entity cache.
    $this->container->get('cache.entity')->deleteAll();
    $groups->resetCache();
    $scrubbed = WorkspaceLifecycle::parse($groups->loadUnchanged($workspace->id())->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertNotNull($scrubbed, 'The scrubbed record is still valid.');
    $this->assertSame(0, $scrubbed['acceptance']['uid']);
    $this->assertSame('t1', $scrubbed['acceptance']['terms']);
    $this->assertTrue($groups->loadUnchanged($garbage->id())->get(WorkspaceLifecycle::FIELD)->isEmpty());
    $this->assertStringContainsString('Jane Doe', (string) $groups->loadUnchanged($opened->id())->get(WorkspaceLifecycle::FIELD)->value);
    $revisionValues = \Drupal::database()->select('group_revision__field_lifecycle_record', 'r')
      ->fields('r', ['field_lifecycle_record_value'])
      ->condition('entity_id', $workspace->id())
      ->execute()
      ->fetchCol();
    $this->assertCount(2, $revisionValues);
    foreach ($revisionValues as $value) {
      $this->assertSame(0, json_decode($value, TRUE)['acceptance']['uid']);
    }
  }

  /**
   * A default value callback a tampered instance might carry.
   */
  public static function defaultRecord(): array {
    return [['value' => '{"v":1,"marker":"' . self::MARKER . '"}']];
  }

  /**
   * Runs the update step and stores a record carrying the marker.
   */
  private function prepareRecord(): GroupInterface {
    $this->schema()->apply();
    $groups = $this->container->get('entity_type.manager')->getStorage('group');
    $groups->resetCache();
    $workspace = $groups->load($this->workspace->id());
    // Fixture write outside the lifecycle service, marked as syncing.
    $workspace->setSyncing(TRUE);
    $workspace->set(WorkspaceLifecycle::FIELD, $this->recordValue())->save();
    $workspace->setSyncing(FALSE);
    $this->container->get('router.builder')->rebuild();
    return $workspace;
  }

  /**
   * The stored record value of the tests.
   */
  private function recordValue(): string {
    return '{"v":1,"marker":"' . self::MARKER . '"}';
  }

  /**
   * Tries every way to reach the record, as every account.
   */
  private function assertRecordClosed(GroupInterface $workspace, bool $withResourceConfig): void {
    $field = WorkspaceLifecycle::FIELD;
    $path = '/jsonapi/group/jur/' . $workspace->uuid();
    $write = fn(string $value) => json_encode(['data' => [
      'type' => 'group--jur',
      'id' => $workspace->uuid(),
      'attributes' => [$field => $value],
    ]]);
    $create = json_encode(['data' => [
      'type' => 'group--jur',
      'attributes' => ['label' => 'Crafted map', $field => '{"v":1,"acceptance":"crafted"}'],
    ]]);

    foreach ($this->accounts as $label => $account) {
      $this->setCurrentUser($account);

      // Read: the group is readable, the record is not part of it.
      $individual = $this->request('GET', $path);
      $this->assertSame(200, $individual->getStatusCode(), $label . ': ' . $individual->getContent());
      $this->assertStringContainsString('Existing map', (string) $individual->getContent(), $label);
      $this->assertStringNotContainsString(self::MARKER, (string) $individual->getContent(), $label);
      $this->assertArrayNotHasKey($field, json_decode((string) $individual->getContent(), TRUE)['data']['attributes'], $label);

      $collection = $this->request('GET', '/jsonapi/group/jur');
      $this->assertStringContainsString($workspace->uuid(), (string) $collection->getContent(), $label);
      $this->assertStringNotContainsString(self::MARKER, (string) $collection->getContent(), $label);

      // Sparse fieldset: the resource without the record. Include of the
      // field itself: refused, it is no relationship.
      foreach (["$path?fields[group--jur]=$field" => 200, "$path?include=$field" => 400] as $query => $expected) {
        $response = $this->request('GET', $query);
        $this->assertSame($expected, $response->getStatusCode(), "$label: $query");
        $this->assertStringNotContainsString(self::MARKER, (string) $response->getContent(), "$label: $query");
      }

      // Include through a relationship: the workspace as included resource
      // of its memberships carries no record either.
      $memberships = $this->request('GET', '/jsonapi/group_relationship/jur-group_membership?include=gid');
      $this->assertSame(200, $memberships->getStatusCode(), $label);
      $this->assertStringNotContainsString(self::MARKER, (string) $memberships->getContent(), $label);
      foreach (json_decode((string) $memberships->getContent(), TRUE)['included'] ?? [] as $included) {
        $this->assertArrayNotHasKey($field, $included['attributes'] ?? [], $label);
      }
      if ($label === 'jur-tenant_admin') {
        $includedIds = array_column(json_decode((string) $memberships->getContent(), TRUE)['included'] ?? [], 'id');
        $this->assertContains($workspace->uuid(), $includedIds, 'The include reached the workspace.');
      }

      // Filter and sort: refused.
      foreach ([
        "/jsonapi/group/jur?filter[$field][operator]=CONTAINS&filter[$field][value]=" . self::MARKER,
        "/jsonapi/group/jur?filter[$field]=" . rawurlencode($this->recordValue()),
        "/jsonapi/group/jur?sort=$field",
        "/jsonapi/group/jur?sort=-$field",
      ] as $query) {
        $response = $this->request('GET', $query);
        // 403: field access denied (the module's deny); 400: no such field
        // (disabled in the resource config).
        $this->assertSame($withResourceConfig ? 400 : 403, $response->getStatusCode(), "$label: $query: " . $response->getContent());
        $this->assertStringNotContainsString($workspace->uuid(), (string) $response->getContent(), "$label: $query");
      }

      // Write: refused, also for accounts that may edit the group.
      $patch = $this->request('PATCH', $path, $write('{"v":1,"acceptance":"overwritten by ' . $label . '"}'));
      $this->assertSame(403, $patch->getStatusCode(), "$label: " . $patch->getContent());
      $post = $this->request('POST', '/jsonapi/group/jur', $create);
      $this->assertSame(403, $post->getStatusCode(), "$label: " . $post->getContent());
    }

    // The stored value is unchanged and no crafted workspace exists.
    $groups = $this->container->get('entity_type.manager')->getStorage('group');
    $this->assertSame($this->recordValue(), $groups->loadUnchanged($workspace->id())->get($field)->value);
    $this->assertSame([], $groups->loadByProperties(['label' => 'Crafted map']));

    // Control: the tenant admin can write the group itself, so the refusals
    // above are about the record, not about writing in general.
    $this->setCurrentUser($this->tenantAdmin);
    $rename = $this->request('PATCH', $path, json_encode(['data' => [
      'type' => 'group--jur',
      'id' => $workspace->uuid(),
      'attributes' => ['label' => 'Renamed map'],
    ]]));
    $this->assertSame(200, $rename->getStatusCode(), (string) $rename->getContent());
    $this->assertSame($this->recordValue(), $groups->loadUnchanged($workspace->id())->get($field)->value);
    // Same for creating: uid 1 creates a group without the record.
    $this->setCurrentUser(User::load(1));
    $plain = $this->request('POST', '/jsonapi/group/jur', json_encode(['data' => [
      'type' => 'group--jur',
      'attributes' => ['label' => 'Plain map'],
    ]]));
    $this->assertSame(201, $plain->getStatusCode(), (string) $plain->getContent());
  }

  /**
   * Installs the shipped resource config as an older site has it.
   *
   * Without the record entry, as before the update step.
   */
  private function installResourceConfig(): void {
    $resource = Yaml::decode((string) file_get_contents($this->nuxtResourceConfigPath()));
    unset($resource['resourceFields'][WorkspaceLifecycle::FIELD], $resource['uuid'], $resource['_core']);
    $this->container->get('entity_type.manager')->getStorage('jsonapi_resource_config')->create($resource)->save();
  }

  /**
   * Builds the update step on this checkout's shipped config.
   */
  private function schema(): LifecycleRecordSchema {
    return new LifecycleRecordSchema(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      dirname(__DIR__, 3) . '/config/optional',
    );
  }

  /**
   * The shipped JSON:API resource config of the jurisdiction group.
   */
  private function nuxtResourceConfigPath(): string {
    return dirname(__DIR__, 4) . '/markaspot_nuxt/config/optional/' . LifecycleRecordSchema::RESOURCE_CONFIG . '.yml';
  }

  /**
   * Sends a JSON:API request through the HTTP kernel.
   */
  private function request(string $method, string $path, ?string $body = NULL): Response {
    $request = Request::create($path, $method, [], [], [], [], $body);
    $request->headers->set('Accept', 'application/vnd.api+json');
    if ($body !== NULL) {
      $request->headers->set('Content-Type', 'application/vnd.api+json');
    }
    return $this->container->get('http_kernel')->handle($request);
  }

}
