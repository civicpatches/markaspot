<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\Entity\Eca;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_dashboard\Controller\StatusNoteController;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\service_request\StatusNoteRequirement;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A status note that keeps the status does not look like a status change.
 *
 * Runs the dashboard note endpoint against both shipped status mail
 * automations (the legacy model with fixed status IDs and the keyed term
 * model), with a reporter who opted in and has a valid address. The
 * "field value changed" condition of each model is evaluated during the
 * update hook, while the original entity still exists; after the save
 * Drupal discards it. All names and addresses are fictional.
 */
#[TestGroup('markaspot_dashboard')]
#[CoversClass(StatusNoteController::class)]
#[RunTestsInSeparateProcesses]
final class StatusNoteKeepsStatusKernelTest extends KernelTestBase {

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
    'path_alias',
    'token',
    'modeler_api',
    'eca',
    'eca_content',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'service_request',
    'markaspot_mail',
  ];

  /**
   * {@inheritdoc}
   *
   * The shipped legacy model carries an "object" key on core's e-mail action
   * that has no config schema. The models are imported as shipped.
   */
  protected static $configSchemaCheckerExclusions = [
    'eca.eca.process_tunr6d6',
  ];

  /**
   * The shipped status mail automations.
   */
  private const MODELS = ['process_tunr6d6', 'process_notify_status_term'];

  /**
   * The reporter's address.
   */
  private const REPORTER = 'reporter@example.test';

  /**
   * Keyed status that only the term model mails for.
   */
  private TermInterface $open;

  /**
   * Another keyed status that only the term model mails for.
   */
  private TermInterface $inProgress;

  /**
   * Keyed status with a legacy ID, so both models mail for it.
   */
  private TermInterface $closed;

  /**
   * The report under test.
   */
  private NodeInterface $report;

  /**
   * Condition results per model, recorded during each update hook.
   *
   * @var list<array<string, bool>>
   */
  private array $observed = [];

  /**
   * PHP type of the status target ID seen during each update hook.
   *
   * @var list<string>
   */
  private array $statusTypes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user',
      'node',
      'taxonomy_term',
      'paragraph',
      'path_alias',
      'group',
      'group_relationship',
      'group_config_wrapper',
    ];
    foreach ($entityTypes as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'eca']);
    // No automatic status notes: the endpoint writes the only paragraph.
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    // markaspot_open311 is not enabled; the group presave asks it for an
    // initial status of new requests.
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

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    // One root jurisdiction; the group hooks assign new reports to it.
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_slug', 'string');
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    $this->createField('taxonomy_term', 'service_status', 'field_notification_key', 'string');
    $this->createField('taxonomy_term', 'service_status', StatusNoteRequirement::FIELD, 'boolean');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request', 'new_revision' => TRUE])->save();
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$types->load('jur-group_node-service_request')) {
      $types->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    }
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // The legacy model mails only for the IDs 4, 5 and 30; the term model
    // for every status with a notification key.
    $this->open = $this->createStatus(12, 'Open', 'status_open');
    $this->inProgress = $this->createStatus(13, 'In progress', 'status_in_progress');
    $this->closed = $this->createStatus(5, 'Closed', 'status_closed');

    $models = dirname(__DIR__, 4) . '/markaspot_group/config/optional';
    foreach (self::MODELS as $id) {
      $values = Yaml::decode((string) file_get_contents("$models/eca.eca.$id.yml"));
      $this->assertTrue($values['status'], "The shipped model $id is active.");
      Eca::create($values)->save();
    }

    $district = Group::create(['type' => 'jur', 'label' => 'Fictional district', 'field_slug' => 'district']);
    $district->save();

    $this->report = Node::create([
      'type' => 'service_request',
      'title' => 'Broken bench',
      'field_jurisdiction' => $district->id(),
      'field_status' => $this->open->id(),
      'field_notification' => TRUE,
      'field_e_mail' => self::REPORTER,
    ]);
    $this->report->save();
  }

  /**
   * A note that keeps the status records the note and sends no status mail.
   */
  public function testNoteKeepingTheStatusSendsNoStatusMail(): void {
    $before = $this->prepare();

    $response = $this->controller()->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->open->uuid(),
      'note' => 'The bench will be repaired next week.',
    ]));

    $this->assertSame(
      ['changed' => [['process_tunr6d6' => FALSE, 'process_notify_status_term' => FALSE]], 'mails' => []],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    $this->assertNoteStored($response, $before, $this->open, 'The bench will be repaired next week.');
  }

  /**
   * A limited author who omits the status keeps the status silently too.
   */
  public function testNoteWithoutStatusFromLimitedAuthorSendsNoStatusMail(): void {
    $before = $this->prepare();

    $response = $this->controller(FALSE)->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'note' => 'Site visited, material ordered.',
    ]));

    $this->assertSame(
      ['changed' => [['process_tunr6d6' => FALSE, 'process_notify_status_term' => FALSE]], 'mails' => []],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    $this->assertNoteStored($response, $before, $this->open, 'Site visited, material ordered.');
  }

  /**
   * The condition stays false for a reporter who opted out as well.
   */
  public function testNoteKeepingTheStatusForOptedOutReporter(): void {
    $this->report->set('field_notification', FALSE)->save();
    $before = $this->prepare();

    $response = $this->controller()->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->open->uuid(),
      'note' => 'Checked on site.',
    ]));

    $this->assertSame(
      ['changed' => [['process_tunr6d6' => FALSE, 'process_notify_status_term' => FALSE]], 'mails' => []],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    $this->assertNoteStored($response, $before, $this->open, 'Checked on site.');
  }

  /**
   * A note with a real status change sends the status mail as before.
   */
  public function testNoteWithStatusChangeSendsTheStatusMail(): void {
    $before = $this->prepare();

    $response = $this->controller()->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->inProgress->uuid(),
      'note' => 'Work has started.',
    ]));

    $this->assertSame(
      [
        'changed' => [['process_tunr6d6' => TRUE, 'process_notify_status_term' => TRUE]],
        'mails' => ['markaspot_mail_notification_status_in_progress'],
      ],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    $this->assertNoteStored($response, $before, $this->inProgress, 'Work has started.');
  }

  /**
   * A status both automations mail for: the transition count is unchanged.
   *
   * Both models mail for a keyed status with a legacy ID. The fix leaves
   * that count alone; a later note that keeps the status mails nobody.
   */
  public function testStatusInBothAutomations(): void {
    $before = $this->prepare();

    $response = $this->controller()->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->closed->uuid(),
      'note' => 'Repaired.',
    ]));

    $this->assertSame(
      [
        'changed' => [['process_tunr6d6' => TRUE, 'process_notify_status_term' => TRUE]],
        'mails' => ['markaspot_mail_notification_status_closed', 'system_action_send_email'],
      ],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    $this->assertNoteStored($response, $before, $this->closed, 'Repaired.');

    $before = $this->prepare();
    $response = $this->controller()->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->closed->uuid(),
      'note' => 'Photo of the repaired bench added.',
    ]));

    $this->assertSame(
      ['changed' => [['process_tunr6d6' => FALSE, 'process_notify_status_term' => FALSE]], 'mails' => []],
      ['changed' => $this->observed, 'mails' => $this->mailIds()],
    );
    // In this fixture a second note does not move the changed time, with or
    // without the status write: entity_reference_revisions stops comparing at
    // the first delta when that paragraph has a translation in the report's
    // language. Not part of this behaviour, so not asserted here.
    $this->assertNoteStored($response, $before, $this->closed, 'Photo of the repaired bench added.', FALSE);
  }

  /**
   * Evaluates each model's changed condition while the original exists.
   */
  #[Hook('node_update')]
  public function observeNodeUpdate(NodeInterface $node): void {
    if ($node->bundle() !== 'service_request') {
      return;
    }
    $this->statusTypes[] = get_debug_type($node->get('field_status')->target_id);
    $manager = $this->container->get('plugin.manager.eca.condition');
    $results = [];
    foreach (self::MODELS as $id) {
      $model = Eca::load($id);
      $this->assertInstanceOf(Eca::class, $model);
      $conditions = array_filter(
        $model->get('conditions'),
        static fn(array $condition): bool => $condition['plugin'] === 'eca_entity_field_value_changed',
      );
      $this->assertCount(1, $conditions, "Model $id has one changed condition.");
      $condition = $manager->createInstance('eca_entity_field_value_changed', reset($conditions)['configuration']);
      $condition->setContextValue('entity', $node);
      $results[$id] = $condition->evaluate();
    }
    $this->observed[] = $results;
  }

  /**
   * Resets probes, mails and flood; returns the state before the request.
   *
   * The report is reloaded from storage, as the endpoint loads it, so its
   * status ID is the stored string. Its changed time is moved into the past
   * to show that a first note still postpones the archive and escalation
   * timers.
   *
   * @return array{revision: int, changed: int, notes: int}
   *   Revision ID, changed time and number of status notes.
   */
  private function prepare(): array {
    $report = $this->reload();
    $report->set('changed', $this->container->get('datetime.time')->getRequestTime() - 86400);
    $report->save();

    $report = $this->reload();
    $this->assertIsString($report->get('field_status')->target_id, 'The stored status ID is a string.');
    $this->observed = [];
    $this->statusTypes = [];
    $this->container->get('state')->set('system.test_mail_collector', []);
    $this->container->get('flood')->clear('markaspot_mail.notification_recipient', self::REPORTER);

    return [
      'revision' => (int) $report->getRevisionId(),
      'changed' => (int) $report->getChangedTime(),
      'notes' => $report->get('field_status_notes')->count(),
    ];
  }

  /**
   * Asserts the response, note, status, revision and changed time.
   *
   * @param \Symfony\Component\HttpFoundation\JsonResponse $response
   *   The endpoint response.
   * @param array{revision: int, changed: int, notes: int} $before
   *   State before the request.
   * @param \Drupal\taxonomy\TermInterface $status
   *   Expected status of the report and of the new note.
   * @param string $text
   *   Expected note text.
   * @param bool $firstNote
   *   Whether this is the report's first note; only then the changed time
   *   moves.
   */
  private function assertNoteStored(JsonResponse $response, array $before, TermInterface $status, string $text, bool $firstNote = TRUE): void {
    $this->assertSame(201, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('success', $body['status']);

    $report = $this->reload();
    $this->assertSame((string) $status->id(), (string) $report->get('field_status')->target_id);
    $notes = $report->get('field_status_notes');
    $this->assertSame($before['notes'] + 1, $notes->count());
    $note = $notes->get($notes->count() - 1)?->entity;
    $this->assertInstanceOf(ParagraphInterface::class, $note);
    $this->assertSame($body['uuid'], $note->uuid());
    $this->assertSame((string) $status->id(), (string) $note->get('field_status_term')->target_id);
    $this->assertSame($text, $note->get('field_status_note')->value);
    $this->assertNotEmpty($note->get('created')->value);
    $this->assertGreaterThan($before['revision'], (int) $report->getRevisionId(), 'The note saved a new revision.');
    if ($firstNote) {
      $this->assertGreaterThan($before['changed'], (int) $report->getChangedTime(), 'The note moved the changed time.');
    }
    $this->assertCount(1, $this->statusTypes, 'The note saved the report once.');
  }

  /**
   * IDs of the mails collected since the last prepare(), sorted.
   *
   * @return list<string>
   *   Mail IDs.
   */
  private function mailIds(): array {
    $mails = $this->container->get('state')->get('system.test_mail_collector') ?? [];
    foreach ($mails as $mail) {
      $this->assertSame(self::REPORTER, $mail['to'], 'Status mails go to the reporter only.');
    }
    $ids = array_map(static fn(array $mail): string => (string) $mail['id'], $mails);
    sort($ids);
    return $ids;
  }

  /**
   * Builds the note controller with the real paragraph factory.
   *
   * Only the jurisdiction lookup of the processor and the status term scope
   * are stubbed; the factory and the note rule are the real ones.
   */
  private function controller(bool $managesNotes = TRUE): StatusNoteController {
    $processor = $this->getMockBuilder(GeoreportProcessorService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['resolveNodeJurisdictionId'])
      ->getMock();
    $processor->method('resolveNodeJurisdictionId')->willReturn(NULL);
    foreach (['entityTypeManager' => 'entity_type.manager', 'languageManager' => 'language_manager'] as $property => $service) {
      (new \ReflectionProperty(GeoreportProcessorService::class, $property))
        ->setValue($processor, $this->container->get($service));
    }
    $scope = $this->createMock(StatusTermScope::class);
    $scope->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => Term::loadMultiple(
        \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
          ->condition('uuid', $properties['uuid'])
          ->execute()
      )
    );
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('hasPermission')->willReturn($managesNotes);

    return new StatusNoteController(
      $this->container->get('entity_type.manager'),
      $processor,
      $account,
      $this->createMock(FeatureFlagChecker::class),
      $scope,
      new StatusNoteRequirement($this->container->get('entity_type.manager'), $this->container),
    );
  }

  /**
   * A JSON request to the note endpoint.
   */
  private function request(array $data): Request {
    return Request::create('/api/dashboard/status-notes', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
  }

  /**
   * Creates a status term with a fixed ID and a notification key.
   */
  private function createStatus(int $tid, string $name, string $notificationKey): TermInterface {
    $term = Term::create([
      'tid' => $tid,
      'vid' => 'service_status',
      'name' => $name,
      'field_notification_key' => $notificationKey,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Reloads the report without the static cache.
   */
  private function reload(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->report->id()]);
    $report = $storage->load($this->report->id());
    $this->assertInstanceOf(NodeInterface::class, $report);
    return $report;
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
