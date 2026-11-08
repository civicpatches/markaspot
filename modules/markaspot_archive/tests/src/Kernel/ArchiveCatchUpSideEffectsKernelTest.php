<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\eca\Entity\Eca;
use Drupal\eca\EventSubscriber\DynamicSubscriber;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_archive\ArchiveService;
use Drupal\markaspot_archive\Drush\Commands\ArchiveCommands;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The archive catch-up saves send no mail and keep the request as it was.
 *
 * Large tenants run the anonymization backfill and the one-time unpublish
 * pass on thousands of archived requests at once. These system saves must not
 * notify the reporter, an organisation or an assignee, and must not change a
 * field that tenant notification rules react to. A tenant ECA model that
 * mails on every save of a request (core's e-mail action stands in for the
 * Mark-a-Spot mail actions, ECA dispatches both alike) must stay silent too,
 * and moderation events in the revision history must survive. All names,
 * addresses and titles are fictional.
 *
 * @group markaspot_archive
 */
#[RunTestsInSeparateProcesses]
final class ArchiveCatchUpSideEffectsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'node',
    'taxonomy',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'modeler_api',
    'eca',
    'eca_content',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_moderation',
    'service_request',
    'markaspot_archive',
  ];

  /**
   * Fields a tenant notification rule may react to on update.
   */
  private const WATCHED_FIELDS = [
    'field_organisation',
    'field_assignee',
    'field_status',
    'field_e_mail',
    'field_source',
    'changed',
  ];

  /**
   * Jurisdiction of the requests.
   */
  private Group $district;

  /**
   * First organisation.
   */
  private Group $roadWorks;

  /**
   * Second organisation.
   */
  private Group $parks;

  /**
   * Archived status term ID.
   */
  private int $archived;

  /**
   * Fixture requests keyed by role.
   *
   * @var array<string, \Drupal\node\Entity\Node>
   */
  private array $requests = [];

  /**
   * Node hooks seen while probing.
   *
   * @var list<string>
   */
  private array $hookLog = [];

  /**
   * Watched fields that changed on an update while probing, keyed by node ID.
   *
   * @var array<int, list<string>>
   */
  private array $changedFields = [];

  /**
   * Whether the hook probes record.
   */
  private bool $probing = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['user', 'node', 'taxonomy_term', 'paragraph', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_moderation', ['markaspot_moderation_flags']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'eca']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    // markaspot_open311 is not enabled; the group presave asks it for an
    // initial status of new requests in a category with a jurisdiction.
    $this->container->set('markaspot_open311.processor', new class {

      /**
       * Leaves the initial status unset.
       */
      public function getInitialStatusTid(int $jurisdictionId): ?int {
        return NULL;
      }

    });

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1, 'mail' => 'admin@example.test']);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request', 'new_revision' => TRUE])->save();
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service category'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Service status'])->save();
    $this->installConfig(['markaspot_archive']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $groupType) {
      if (!$types->load($groupType . '-group_node-service_request')) {
        $types->createFromPlugin(GroupType::load($groupType), 'group_node:service_request')->save();
      }
    }

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_head_organisation_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1, 'custom');
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_first_name', 'string');
    $this->createField('node', 'service_request', 'field_source', 'list_string', [
      'allowed_values' => ['web' => 'Web', 'staff' => 'Staff', 'email' => 'E-mail'],
    ]);
    $this->createField('node', 'service_request', 'field_request_attributes', 'text_long');
    $this->createField('node', 'service_request', 'field_assignee', 'entity_reference', ['target_type' => 'user']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $open = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $open->save();
    $archived = Term::create(['vid' => 'service_status', 'name' => 'Archived']);
    $archived->save();
    $this->archived = (int) $archived->id();

    $county = $this->createGroup('jur', 'Fictional county');
    $this->district = $this->createGroup('jur', 'Fictional district', ['field_parent_jurisdiction' => $county->id()]);
    $this->roadWorks = $this->createGroup('org', 'Road works unit', [
      'field_jurisdiction' => $county->id(),
      'field_head_organisation_e_mail' => 'roads@example.test',
    ]);
    $this->parks = $this->createGroup('org', 'Parks unit', [
      'field_jurisdiction' => $county->id(),
      'field_head_organisation_e_mail' => 'parks@example.test',
    ]);

    // Requests archived before archive transitions unpublished and
    // anonymized them: settings off while they reach the archived status.
    $this->config('markaspot_archive.settings')
      ->set('status_archived', (string) $this->archived)
      ->set('anonymize', 0)
      ->set('unpublish', 0)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_first_name' => 'field_first_name',
      ])
      ->save();

    $roads = (int) $this->roadWorks->id();
    $parks = (int) $this->parks->id();
    $this->requests['consistent'] = $this->createRequest('Loose paving slab', [$roads], 'jane@example.org');
    // Drift: the relation to the organisation in the field is gone.
    $this->requests['missing'] = $this->createRequest('Pothole near the bus stop', [$roads], 'john@example.org');
    $this->deleteRelations($this->requests['missing'], $roads);
    // Drift: the field moved on, the relation stayed behind.
    $this->requests['moved'] = $this->createRequest('Fallen branch', [$roads], 'joan@example.org');
    $this->setFieldOrganisations($this->requests['moved'], [$parks]);
    $this->requests['hidden'] = $this->createRequest('Broken bench', [$parks], 'jim@example.org', FALSE, 'moderation_hidden');
    $this->deleteRelations($this->requests['hidden'], $parks);
    // Staff assigned a person before the request was archived.
    $assignee = User::create(['name' => 'Fictional assignee', 'status' => 1, 'mail' => 'assignee@example.test']);
    $assignee->save();
    $this->setFieldValue($this->requests['consistent'], 'field_assignee', 'target_id', (int) $assignee->id());

    // A tenant rule that mails on every save of a request.
    $events = [];
    foreach (['presave', 'update'] as $event) {
      $events[$event] = [
        'plugin' => 'content_entity:' . $event,
        'configuration' => ['type' => 'node service_request'],
        'successors' => [['id' => 'citizen_mail', 'condition' => '']],
      ];
    }
    Eca::create([
      'id' => 'archive_catch_up_citizen_mail',
      'label' => 'Synthetic citizen mail on every save',
      'status' => TRUE,
      'events' => $events,
      'conditions' => [],
      'gateways' => [],
      'actions' => [
        'citizen_mail' => [
          'plugin' => 'action_send_email_action',
          'configuration' => [
            'recipient' => 'citizen-mail@example.test',
            'subject' => 'Your report changed',
            'message' => 'Fictional status mail.',
          ],
          'successors' => [],
        ],
      ],
    ])->save();

    $this->config('markaspot_archive.settings')
      ->set('anonymize', 1)
      ->set('unpublish', 1)
      ->save();
    // A cron run is a new PHP process: nothing has been notified there yet.
    drupal_static_reset();
    $this->clearCollectedMails();
    // Cron and Drush run as the anonymous user.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * The cron catch-up sends no mail and changes no watched field.
   */
  public function testCronCatchUpSendsNoMail(): void {
    $before = $this->watchedValues();
    $nodeCount = $this->nodeCount();
    $service = $this->container->get('markaspot_archive.archive');

    $this->probing = TRUE;
    $service->unpublishArchivedBacklog(ArchiveService::BACKFILL_LIMIT, ArchiveService::BACKFILL_TIME_BUDGET);
    $service->unpublishArchivedBacklog(ArchiveService::BACKFILL_LIMIT, ArchiveService::BACKFILL_TIME_BUDGET);
    $service->backfillArchived(ArchiveService::BACKFILL_LIMIT, ArchiveService::BACKFILL_TIME_BUDGET);
    $this->probing = FALSE;

    $this->assertSame([], $this->collectedMails());
    $this->assertCatchUpResult($before, $nodeCount);
    $this->assertTrue(DynamicSubscriber::isActive(), 'ECA is active again after the catch-up.');
  }

  /**
   * The operator command sends no mail either and finishes in one run.
   */
  public function testOperatorCommandSendsNoMail(): void {
    $before = $this->watchedValues();
    $nodeCount = $this->nodeCount();

    $this->probing = TRUE;
    $rows = ArchiveCommands::create($this->container)->backfill([
      'dry-run' => FALSE,
      'only' => NULL,
      'limit' => 0,
      'batch-size' => 2,
    ]);
    $this->probing = FALSE;

    $this->assertSame([], $this->collectedMails());
    $this->assertCatchUpResult($before, $nodeCount);
    $this->assertTrue(DynamicSubscriber::isActive(), 'ECA is active again after the catch-up.');
    $this->assertSame(0, $rows->getExitCode());
    $remaining = [];
    foreach ($rows->getOutputData() as $row) {
      $remaining[$row['step']] = $row['remaining'];
    }
    $this->assertSame(['unpublish' => 0, 'anonymize' => 0], $remaining);
  }

  /**
   * The catch-up repairs relation drift without notifying the organisation.
   */
  public function testCatchUpRepairsRelationsSilently(): void {
    $service = $this->container->get('markaspot_archive.archive');
    $service->backfillArchived(ArchiveService::BACKFILL_LIMIT, ArchiveService::BACKFILL_TIME_BUDGET);

    $this->assertSame([], $this->collectedMails());
    $this->assertSame([(int) $this->roadWorks->id()], $this->relationIds('missing'));
    $this->assertSame([(int) $this->parks->id()], $this->relationIds('moved'));
    $this->assertSame([(int) $this->parks->id()], $this->relationIds('hidden'));
  }

  /**
   * A staff save of a drifted request still notifies as before.
   *
   * Keeps the suppression narrow: only system saves stay silent. The
   * organisation is notified once; the tenant ECA rule mails on the presave
   * and update of the staff save and of the re-save that repairs the
   * relation.
   */
  public function testStaffSaveOfDriftedRequestStillNotifies(): void {
    $this->container->get('current_user')->setAccount(User::load(1));
    $node = $this->reload('missing');
    $node->setTitle('Pothole near the bus stop, deeper');
    $node->save();

    $mails = [];
    foreach ($this->collectedMails() as $mail) {
      $mails[] = $mail['key'] . ' ' . $mail['to'];
    }
    $this->assertSame([
      'action_send_email citizen-mail@example.test' => 4,
      'org_notification roads@example.test' => 1,
    ], array_count_values($mails));
  }

  /**
   * Asserts the outcome every catch-up path must reach.
   *
   * @param array<int, array<string, mixed>> $before
   *   Watched field values before the catch-up, keyed by node ID.
   * @param int $nodeCount
   *   Number of nodes before the catch-up.
   */
  private function assertCatchUpResult(array $before, int $nodeCount): void {
    $this->assertSame($nodeCount, $this->nodeCount(), 'No request was inserted.');
    $this->assertNotContains('node_insert', $this->hookLog);
    $this->assertContains('node_update', $this->hookLog);
    $this->assertSame([], $this->changedFields, 'No watched field changed on update.');

    $after = $this->watchedValues();
    foreach ($this->requests as $key => $request) {
      $nid = (int) $request->id();
      $node = $this->reload($key);
      $this->assertFalse($node->isPublished(), $key);
      $this->assertStringEndsWith('@anonymized.off', (string) $node->get('field_e_mail')->value, $key);
      $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) $node->get('field_first_name')->value, $key);
      $this->assertSame('staff', $node->get('field_source')->value, $key);
      $this->assertSame(1000000000, (int) $node->getChangedTime(), $key);
      $this->assertSame(['_markaspot_channel' => 'staff'], json_decode((string) $node->get('field_request_attributes')->value, TRUE), $key);
      unset($before[$nid]['field_e_mail'], $after[$nid]['field_e_mail']);
      $this->assertSame($before[$nid], $after[$nid], $key);
    }
    // The moderation decision stays readable, the quoted address does not.
    $logs = $this->container->get('database')->select('node_revision', 'r')
      ->fields('r', ['revision_log'])
      ->condition('r.nid', $this->requests['hidden']->id())
      ->orderBy('r.vid')
      ->execute()
      ->fetchCol();
    $first = RevisionLog::decode((string) $logs[0]);
    $this->assertSame(['moderation_hidden'], $first['events']);
    $this->assertSame('Hidden after a complaint by [REDACTED email].', $first['message']);
    $this->assertStringNotContainsString('jim@example.org', implode(' ', $logs));
  }

  /**
   * Records node inserts while probing.
   */
  #[Hook('node_insert')]
  public function probeNodeInsert(NodeInterface $node): void {
    if ($this->probing) {
      $this->hookLog[] = 'node_insert';
    }
  }

  /**
   * Records node updates and the watched fields they change while probing.
   *
   * Contact fields change on purpose; every other watched field must not.
   */
  #[Hook('node_update')]
  public function probeNodeUpdate(NodeInterface $node): void {
    if (!$this->probing) {
      return;
    }
    $this->hookLog[] = 'node_update';
    $original = $node->getOriginal();
    if (!$original instanceof NodeInterface) {
      return;
    }
    foreach (self::WATCHED_FIELDS as $field) {
      if ($field !== 'field_e_mail' && !$node->get($field)->equals($original->get($field))) {
        $this->changedFields[(int) $node->id()][] = $field;
      }
    }
  }

  /**
   * Returns the stored watched field values of every fixture request.
   *
   * @return array<int, array<string, mixed>>
   *   Field values keyed by node ID and field name.
   */
  private function watchedValues(): array {
    $values = [];
    foreach (array_keys($this->requests) as $key) {
      $node = $this->reload($key);
      foreach (self::WATCHED_FIELDS as $field) {
        $values[(int) $node->id()][$field] = $node->get($field)->getValue();
      }
    }
    return $values;
  }

  /**
   * Returns the number of stored nodes.
   */
  private function nodeCount(): int {
    return (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {node}')->fetchField();
  }

  /**
   * Creates an archived request as staff did before archiving existed.
   *
   * @param string $title
   *   Fictional title.
   * @param list<int> $organisations
   *   Organisation group IDs.
   * @param string $email
   *   Fictional reporter address.
   * @param bool $published
   *   Whether the request is published.
   * @param string|null $event
   *   A revision event of the creation, with a note quoting the reporter.
   */
  private function createRequest(string $title, array $organisations, string $email, bool $published = TRUE, ?string $event = NULL): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'uid' => 1,
      'field_jurisdiction' => [$this->district->id()],
      'field_organisation' => $organisations,
      'field_status' => $this->archived,
      'field_e_mail' => $email,
      'field_first_name' => 'Jordan',
      'field_source' => 'staff',
      'field_request_attributes' => json_encode(['_markaspot_channel' => 'staff']),
      'changed' => 1000000000,
    ]);
    if ($event !== NULL) {
      RevisionLog::setEvent($node, $event);
      RevisionLog::setMessage($node, 'Hidden after a complaint by ' . $email . '.');
    }
    $node->save();
    $this->assertSame(1000000000, (int) Node::load($node->id())->getChangedTime());
    return $node;
  }

  /**
   * Reloads a fixture request from storage.
   */
  private function reload(string $key): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->requests[$key]->id()]);
    $node = $storage->load($this->requests[$key]->id());
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * Returns the sorted organisation relation group IDs of a fixture request.
   *
   * @return list<int>
   *   Group IDs.
   */
  private function relationIds(string $key): array {
    $ids = array_map('intval', $this->container->get('database')->select('group_relationship_field_data', 'd')
      ->fields('d', ['gid'])
      ->condition('d.entity_id', $this->requests[$key]->id())
      ->condition('d.plugin_id', 'group_node:service_request')
      ->condition('d.group_type', 'org')
      ->execute()
      ->fetchCol());
    sort($ids);
    return $ids;
  }

  /**
   * Overwrites field_organisation in storage, bypassing hooks.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The request.
   * @param list<int> $organisations
   *   Organisation group IDs.
   */
  private function setFieldOrganisations(Node $node, array $organisations): void {
    $database = $this->container->get('database');
    foreach (['node__field_organisation', 'node_revision__field_organisation'] as $table) {
      $database->delete($table)->condition('entity_id', $node->id())->execute();
      foreach (array_values($organisations) as $delta => $gid) {
        $database->insert($table)->fields([
          'bundle' => 'service_request',
          'deleted' => 0,
          'entity_id' => $node->id(),
          'revision_id' => $node->getRevisionId(),
          'langcode' => $node->language()->getId(),
          'delta' => $delta,
          'field_organisation_target_id' => $gid,
        ])->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
  }

  /**
   * Sets a single field value in storage, bypassing hooks.
   */
  private function setFieldValue(Node $node, string $field, string $property, int|string $value): void {
    $database = $this->container->get('database');
    foreach (['node__' . $field, 'node_revision__' . $field] as $table) {
      $database->delete($table)->condition('entity_id', $node->id())->execute();
      $database->insert($table)->fields([
        'bundle' => 'service_request',
        'deleted' => 0,
        'entity_id' => $node->id(),
        'revision_id' => $node->getRevisionId(),
        'langcode' => $node->language()->getId(),
        'delta' => 0,
        $field . '_' . $property => $value,
      ])->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
  }

  /**
   * Deletes the relations of a request to one group, bypassing hooks.
   */
  private function deleteRelations(Node $node, int $gid): void {
    $database = $this->container->get('database');
    $ids = $database->select('group_relationship_field_data', 'd')
      ->fields('d', ['id'])
      ->condition('d.entity_id', $node->id())
      ->condition('d.gid', $gid)
      ->condition('d.plugin_id', 'group_node:service_request')
      ->execute()
      ->fetchCol();
    $this->assertNotEmpty($ids);
    $database->delete('group_relationship_field_data')->condition('id', $ids, 'IN')->execute();
    $database->delete('group_relationship')->condition('id', $ids, 'IN')->execute();
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache($ids);
  }

  /**
   * Creates a group.
   *
   * @param string $type
   *   Group type.
   * @param string $label
   *   Fictional label.
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function createGroup(string $type, string $label, array $values = []): Group {
    $group = Group::create(['type' => $type, 'label' => $label] + $values);
    $group->save();
    return $group;
  }

  /**
   * Creates a field storage and field.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string $name
   *   Field name.
   * @param string $type
   *   Field type.
   * @param array<string, mixed> $settings
   *   Storage settings.
   * @param int $cardinality
   *   Cardinality.
   * @param string $permissionType
   *   The field_permissions type.
   */
  private function createField(string $entityType, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1, string $permissionType = 'public'): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
        'third_party_settings' => ['field_permissions' => ['permission_type' => $permissionType]],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $name,
    ])->save();
  }

  /**
   * Clears mail collected by the kernel test mail backend.
   */
  private function clearCollectedMails(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

  /**
   * Returns mail collected by the kernel test mail backend.
   *
   * @return list<array<string, mixed>>
   *   Collected mail messages.
   */
  private function collectedMails(): array {
    return $this->container->get('state')->get('system.test_mail_collector') ?? [];
  }

}
