<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Drupal\service_request\RevisionLog;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_archive\Drush\Commands\ArchiveCommands;
use Drupal\markaspot_archive\ArchiveService;
use Drupal\markaspot_archive\ArchiveServiceInterface;
use Drupal\markaspot_archive\Plugin\QueueWorker\ArchiveQueueWorker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Psr\Log\LogLevel;

/**
 * Covers archive anonymization for current nodes and historic revisions.
 *
 * @group markaspot_archive
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ArchiveAnonymizerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'taxonomy',
    'telephone',
    'markaspot_archive',
    'filter',
    'service_request',
  ];

  /**
   * The test archive service.
   *
   * @var \Drupal\markaspot_archive\ArchiveService
   */
  private ArchiveService $archiveService;

  /**
   * Logger collecting records for assertions.
   *
   * @var \Drupal\Tests\markaspot_archive\Kernel\ArchiveAnonymizerTestLogger
   */
  private ArchiveAnonymizerTestLogger $loggerSink;

  /**
   * Category term id.
   */
  private int $categoryTid;

  /**
   * Warning message for fields without dedicated revision tables.
   */
  private const SHARED_TABLE_WARNING = 'Revisions of field @field use a ' .
    'shared table and were not anonymized; previous revisions may retain data.';

  /**
   * Critical message when archive queue retries are exhausted.
   */
  private const GIVE_UP_MESSAGE = 'Queue processing failed for node @nid; ' .
    'giving up after 3 attempts: @error';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);

    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);

    // The module ships optional field config targeting the service_category
    // vocabulary, so the bundle entities must exist before config install.
    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
      'new_revision' => TRUE,
    ])->save();
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service category',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();

    $this->installConfig(['system', 'user', 'field', 'node']);
    $this->installConfig(['markaspot_archive']);

    $category = Term::create([
      'vid' => 'service_category',
      'name' => 'Streetlight',
    ]);
    $category->save();
    $this->categoryTid = (int) $category->id();

    $this->createNodeField('field_e_mail', 'email');
    $this->createNodeField('field_phone', 'telephone');
    $this->createNodeField('field_first_name', 'string');
    $this->createNodeField('field_notification', 'boolean');
    $this->createNodeField('field_category', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeField('field_jurisdiction', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeField('field_status', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);

    $logger = new LoggerChannel('markaspot_archive');
    $this->loggerSink = new ArchiveAnonymizerTestLogger();
    $logger->addLogger($this->loggerSink);
    $this->archiveService = new ArchiveService(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $logger,
      $this->container->get('database'),
    );
  }

  /**
   * The module installs the service and safe contact-data defaults.
   */
  public function testServiceAndSafeDefaultConfigAreInstalled(): void {
    self::assertInstanceOf(
      ArchiveService::class,
      $this->container->get('markaspot_archive.archive')
    );
    self::assertSame([
      'field_e_mail' => 'field_e_mail',
      'field_first_name' => 'field_first_name',
      'field_last_name' => 'field_last_name',
      'field_phone' => 'field_phone',
      'field_notification' => 'field_notification',
      'field_feedback' => 'field_feedback',
    ], $this->config('markaspot_archive.settings')->get('anonymize_fields'));
    self::assertArrayNotHasKey(
      'field_address',
      $this->config('markaspot_archive.settings')->get('anonymize_fields')
    );
  }

  /**
   * Supported field types are anonymized with type-aware values.
   */
  public function testSupportedFieldsAreAnonymized(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_phone' => '+49 221 123456',
      'field_first_name' => 'Jane',
      'field_notification' => 1,
    ]);

    $values = $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
      'field_phone' => 'field_phone',
      'field_first_name' => 'field_first_name',
      'field_notification' => 'field_notification',
    ]);

    self::assertArrayHasKey('field_e_mail', $values);
    self::assertNotSame(
      'citizen@example.org',
      $node->get('field_e_mail')->value
    );
    self::assertStringEndsWith(
      '@anonymized.off',
      $node->get('field_e_mail')->value
    );
    self::assertSame('+49-0123459995555', $node->get('field_phone')->value);
    self::assertNotSame('Jane', $node->get('field_first_name')->value);
    self::assertSame('0', (string) $node->get('field_notification')->value);
  }

  /**
   * Complex fields are skipped and logged instead of being blindly overwritten.
   */
  public function testEntityReferenceFieldIsSkippedAndLogged(): void {
    $node = $this->createRequest([
      'field_category' => $this->categoryTid,
    ]);

    $values = $this->archiveService->anonymize($node, [
      'field_category' => 'field_category',
    ]);

    self::assertSame([], $values);
    self::assertSame(
      $this->categoryTid,
      (int) $node->get('field_category')->target_id
    );
    self::assertCount(1, $this->loggerSink->records);
    self::assertSame(
      'field @field of type @type skipped, not anonymizable',
      (string) $this->loggerSink->records[0]['message']
    );
    self::assertSame(
      'field_category',
      $this->loggerSink->records[0]['context']['@field']
    );
    self::assertSame(
      'entity_reference',
      $this->loggerSink->records[0]['context']['@type']
    );
  }

  /**
   * Empty fields remain empty on the current node.
   */
  public function testEmptyFieldsRemainEmpty(): void {
    $node = $this->createRequest();

    $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
    ]);

    self::assertTrue($node->get('field_e_mail')->isEmpty());
  }

  /**
   * An empty field list is a no-op.
   */
  public function testEmptyAnonymizeFieldsListDoesNothing(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_notification' => 1,
    ]);

    $values = $this->archiveService->anonymize($node, []);

    self::assertSame([], $values);
    self::assertSame('citizen@example.org', $node->get('field_e_mail')->value);
    self::assertSame('1', (string) $node->get('field_notification')->value);
  }

  /**
   * List-form config fields are accepted while legacy numeric ids are ignored.
   */
  public function testListConfiguredFieldsAreAccepted(): void {
    $fields = $this->archiveService->normalizeConfiguredFields([
      0 => 'field_e_mail',
      1 => 123,
      2 => '123',
      'field_first_name' => 'field_first_name',
      'field_last_name' => 0,
      'field_middle_name' => '123',
    ]);

    self::assertSame([
      'field_e_mail' => 'field_e_mail',
      'field_first_name' => 'field_first_name',
    ], $fields);
  }

  /**
   * Previous node revisions are overwritten with the current anonymized value.
   */
  public function testPreviousRevisionFieldRowsAreAnonymized(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'first@example.org',
    ]);
    $first_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'second@example.org');
    $node->save();
    $second_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'current@example.org');
    $values = $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
    ]);
    $current_value = $node->get('field_e_mail')->value;
    $node->save();

    $updated = $this->archiveService->anonymizeRevisions($node, $values);

    self::assertGreaterThanOrEqual(2, $updated);
    self::assertStringEndsWith('@anonymized.off', $current_value);
    self::assertSame(
      $current_value,
      $this->revisionFieldValue($first_revision, 'field_e_mail')
    );
    self::assertSame(
      $current_value,
      $this->revisionFieldValue($second_revision, 'field_e_mail')
    );
    self::assertSame($current_value, $node->get('field_e_mail')->value);
  }

  /**
   * Legacy contact-only config also erases current and historic feedback.
   */
  public function testFeedbackIsErasedWithLegacyContactConfig(): void {
    $this->createNodeField('field_feedback', 'text_long');
    $node = $this->createRequest([
      'field_e_mail' => 'fictional@example.invalid',
      'field_feedback' => ['value' => 'Fictional private feedback', 'format' => 'plain_text'],
    ]);
    $first_revision = (int) $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->set('field_feedback', 'Fictional current feedback');
    $node->save();
    $second_revision = (int) $node->getRevisionId();
    $fields = ['field_e_mail' => 'field_e_mail'];
    self::assertContains('field_feedback', $this->archiveService->previewAnonymizeFields($node, $fields));
    $values = $this->archiveService->anonymize($node, $fields);
    self::assertTrue($node->get('field_feedback')->isEmpty());
    $node->setNewRevision(TRUE);
    $node->save();
    $this->archiveService->anonymizeRevisions($node, $values);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    self::assertTrue($storage->loadUnchanged($node->id())->get('field_feedback')->isEmpty());
    foreach ([$first_revision, $second_revision] as $vid) {
      self::assertTrue($storage->loadRevision($vid)->get('field_feedback')->isEmpty());
      self::assertSame('', $this->revisionFieldValue($vid, 'field_feedback'));
    }
  }

  /**
   * Staff anonymization keeps the request public and rewrites revisions.
   */
  public function testStaffCommandAnonymizesNodeAndPreviousRevisions(): void {
    $status = $this->createStatusTerm('Open');
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
        'field_first_name' => 'field_first_name',
        'field_notification' => 'field_notification',
      ])
      ->save();

    $node = $this->createRequest([
      'status' => TRUE,
      'field_status' => (int) $status->id(),
      'field_e_mail' => 'first@example.org',
      'field_phone' => '+49 221 111111',
      'field_first_name' => 'Jane',
      'field_notification' => 1,
    ]);
    $first_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'second@example.org');
    $node->set('field_phone', '+49 221 222222');
    $node->set('field_first_name', 'Janet');
    $node->save();
    $second_revision = (int) $node->getRevisionId();

    $this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions(['no-cascade' => TRUE])
    );

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $anonymized = $storage->load((int) $node->id());
    self::assertInstanceOf(Node::class, $anonymized);

    self::assertTrue($anonymized->isPublished());
    self::assertSame(
      (int) $status->id(),
      (int) $anonymized->get('field_status')->target_id
    );
    self::assertNotSame(
      'second@example.org',
      $anonymized->get('field_e_mail')->value
    );
    self::assertStringEndsWith(
      '@anonymized.off',
      $anonymized->get('field_e_mail')->value
    );
    self::assertSame(
      '+49-0123459995555',
      $anonymized->get('field_phone')->value
    );
    self::assertNotSame(
      'Janet',
      $anonymized->get('field_first_name')->value
    );
    self::assertSame(
      '0',
      (string) $anonymized->get('field_notification')->value
    );

    self::assertSame(
      $anonymized->get('field_e_mail')->value,
      $this->revisionFieldValue($first_revision, 'field_e_mail')
    );
    self::assertSame(
      $anonymized->get('field_e_mail')->value,
      $this->revisionFieldValue($second_revision, 'field_e_mail')
    );
    self::assertSame(
      $anonymized->get('field_phone')->value,
      $this->revisionFieldValue($first_revision, 'field_phone')
    );
    self::assertSame(
      (string) $anonymized->get('field_notification')->value,
      $this->revisionFieldValue($first_revision, 'field_notification')
    );

    $revision_ids = $this->nodeRevisionIds((int) $anonymized->id());
    self::assertCount(3, $revision_ids);
    $latest_revision = $storage->loadRevision(max($revision_ids));
    self::assertInstanceOf(Node::class, $latest_revision);
    $audit = RevisionLog::decode((string) $latest_revision->getRevisionLogMessage());
    self::assertSame(['anonymized'], $audit['events']);
    self::assertSame('', $audit['message']);
  }

  /**
   * By-mail anonymization targets exact matches and honors jurisdiction scope.
   */
  public function testStaffCommandByMailQueryAndJurisdictionFilter(): void {
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
      ])
      ->save();
    $jurisdiction_a = $this->createStatusTerm('Jurisdiction A');
    $jurisdiction_b = $this->createStatusTerm('Jurisdiction B');

    $match_a = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_jurisdiction' => (int) $jurisdiction_a->id(),
    ]);
    $match_b = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_jurisdiction' => (int) $jurisdiction_b->id(),
    ]);
    $this->createRequest([
      'field_e_mail' => 'citizen@example.org.invalid',
      'field_jurisdiction' => (int) $jurisdiction_a->id(),
    ]);

    $all_rows = $this->rowsToArray($this->archiveCommand()->anonymize(
      NULL,
      $this->commandOptions([
        'by-mail' => 'citizen@example.org',
        'dry-run' => TRUE,
        'no-cascade' => TRUE,
      ])
    ));
    $all_nids = array_column($all_rows, 'nid');
    sort($all_nids);
    self::assertSame([
      (int) $match_a->id(),
      (int) $match_b->id(),
    ], $all_nids);

    $jurisdiction_rows = $this->rowsToArray($this->archiveCommand()
      ->anonymize(
        NULL,
        $this->commandOptions([
          'by-mail' => 'citizen@example.org',
          'jurisdiction' => (int) $jurisdiction_a->id(),
          'dry-run' => TRUE,
          'no-cascade' => TRUE,
        ])
      ));
    self::assertSame([
      (int) $match_a->id(),
    ], array_column($jurisdiction_rows, 'nid'));
  }

  /**
   * Dry-run previews configured fields without saving anything.
   */
  public function testStaffCommandDryRunDoesNotWrite(): void {
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
      ])
      ->save();
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_phone' => '+49 221 123456',
    ]);
    $revision_id = (int) $node->getRevisionId();

    $rows = $this->rowsToArray($this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions([
        'dry-run' => TRUE,
        'no-cascade' => TRUE,
      ])
    ));

    self::assertSame([
      [
        'nid' => (int) $node->id(),
        'request_id' => '',
        'fields' => 'field_e_mail, field_phone',
      ],
    ], $rows);

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $unchanged = $storage->load((int) $node->id());
    self::assertInstanceOf(Node::class, $unchanged);
    self::assertSame('citizen@example.org', $unchanged->get(
      'field_e_mail'
    )->value);
    self::assertSame('+49 221 123456', $unchanged->get('field_phone')->value);
    self::assertSame($revision_id, (int) $unchanged->getRevisionId());
  }

  /**
   * Empty anonymize field config fails before the command can no-op.
   */
  public function testStaffCommandFailsWhenAnonymizeFieldsAreEmpty(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
    ]);
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [])
      ->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No anonymize fields are configured.');
    $this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions(['no-cascade' => TRUE])
    );
  }

  /**
   * Split cascade traversal is transitive and cycle-safe.
   */
  public function testCascadeWalkerIsTransitiveAndCycleSafe(): void {
    $links = [
      1 => [
        ['source_nid' => 1, 'target_nid' => 2],
        ['source_nid' => 4, 'target_nid' => 1],
      ],
      2 => [
        ['source_nid' => 2, 'target_nid' => 3],
        ['source_nid' => 2, 'target_nid' => 1],
      ],
      3 => [
        ['source_nid' => 3, 'target_nid' => 4],
      ],
      4 => [
        ['source_nid' => 4, 'target_nid' => 2],
      ],
    ];

    $method = new \ReflectionMethod(
      ArchiveCommands::class,
      'resolveLinkedNodeIds'
    );
    $resolved = $method->invoke(
      NULL,
      1,
      static fn(int $nid): array => $links[$nid] ?? []
    );
    sort($resolved);

    self::assertSame([1, 2, 3, 4], $resolved);
  }

  /**
   * Shared-table previous revisions are skipped with an operator warning.
   */
  public function testSharedTableRevisionFieldsAreLogged(): void {
    $node = $this->createRequest([
      'title' => 'Original title',
    ]);

    $node->setNewRevision(TRUE);
    $node->setTitle('Second title');
    $node->save();

    $node->setNewRevision(TRUE);
    $node->setTitle('Current title');
    $values = $this->archiveService->anonymize($node, [
      'title' => 'title',
    ]);
    $node->save();

    $updated = $this->archiveService->anonymizeRevisions($node, $values);

    self::assertSame(0, $updated);
    self::assertTrue($this->loggerHasRecord(
      LogLevel::WARNING,
      self::SHARED_TABLE_WARNING
    ));
  }

  /**
   * Queue failures stop after the third attempt and mark the node in state.
   */
  public function testArchiveQueueWorkerGivesUpAfterThreeAttempts(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(new \RuntimeException(
      'Anonymizer failed'
    ));
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);
    $worker->processItem([
      'nid' => (int) $node->id(),
      'attempts' => ArchiveQueueWorker::MAX_ATTEMPTS,
    ]);

    $failed_nids = (array) $this->container
      ->get('state')
      ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);

    self::assertArrayHasKey((int) $node->id(), $failed_nids);
    self::assertGreaterThan(0, $failed_nids[(int) $node->id()]);
    self::assertTrue($this->loggerHasRecord(
      LogLevel::CRITICAL,
      self::GIVE_UP_MESSAGE
    ));
  }

  /**
   * Failed items below the cap are requeued with an incremented attempt count.
   */
  public function testArchiveQueueWorkerRequeuesWithNextAttempt(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(new \RuntimeException(
      'Anonymizer failed'
    ));
    $queue = $this->createMock(QueueInterface::class);
    $queue
      ->expects(self::once())
      ->method('createItem')
      ->with([
        'nid' => (int) $node->id(),
        'attempts' => 2,
      ]);

    $worker = $this->archiveQueueWorker($archive_service, $queue);
    $worker->processItem([
      'nid' => (int) $node->id(),
      'attempts' => 1,
    ]);

    $failed_nids = (array) $this->container
      ->get('state')
      ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
    self::assertSame([], $failed_nids);
  }

  /**
   * Database failures suspend the whole queue without consuming an attempt.
   */
  public function testArchiveQueueWorkerSuspendsOnDatabaseException(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(
      new DatabaseExceptionWrapper('Database unavailable')
    );
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);

    try {
      $worker->processItem([
        'nid' => (int) $node->id(),
        'attempts' => 1,
      ]);
      self::fail('Expected a SuspendQueueException.');
    }
    catch (SuspendQueueException) {
      $failed_nids = (array) $this->container
        ->get('state')
        ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
      self::assertSame([], $failed_nids);
    }
  }

  /**
   * Queue-control exceptions from dependencies stay queue-level failures.
   */
  public function testArchiveQueueWorkerRethrowsSuspendQueueException(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(
      new SuspendQueueException('Queue paused')
    );
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);

    try {
      $worker->processItem([
        'nid' => (int) $node->id(),
        'attempts' => 1,
      ]);
      self::fail('Expected a SuspendQueueException.');
    }
    catch (SuspendQueueException) {
      $failed_nids = (array) $this->container
        ->get('state')
        ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
      self::assertSame([], $failed_nids);
    }
  }

  /**
   * A manual status change to archived anonymizes in the same save.
   */
  public function testManualArchiveTransitionAnonymizesNodeAndRevisions(): void {
    $this->listenToArchiveChannel();
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
        'field_notification' => 'field_notification',
      ])
      ->save();
    $first_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_phone', '+49 221 123456');
    $node->set('field_notification', 1);
    $node->save();

    $node->setNewRevision(TRUE);
    $node->set('field_status', $this->archivedStatusId());
    $node->save();

    $email = $node->get('field_e_mail')->value;
    self::assertStringEndsWith('@anonymized.off', $email);
    self::assertSame('+49-0123459995555', $node->get('field_phone')->value);
    self::assertSame('0', (string) $node->get('field_notification')->value);
    self::assertSame($email, $this->revisionFieldValue($first_revision, 'field_e_mail'));
    self::assertTrue($this->loggerHasRecord(
      LogLevel::NOTICE,
      'Node ID @nid anonymized on transition to the archived status: @fields'
    ));
  }

  /**
   * Saving an already archived node does not anonymize it again.
   */
  public function testSavingArchivedNodeDoesNotReanonymize(): void {
    $node = $this->createQueueRequest();
    $node->set('field_status', $this->archivedStatusId());
    $node->save();
    $email = $node->get('field_e_mail')->value;

    $reloaded = Node::load($node->id());
    $reloaded->setTitle('Edited after archiving');
    $reloaded->save();

    self::assertSame($email, $reloaded->get('field_e_mail')->value);
  }

  /**
   * The queue worker anonymizes once; the transition hook does not repeat it.
   */
  public function testQueueWorkerArchiveIsNotAnonymizedTwice(): void {
    $this->listenToArchiveChannel();
    $node = $this->createQueueRequest();
    $queue = $this->createMock(QueueInterface::class);
    $worker = $this->archiveQueueWorker(
      $this->container->get('markaspot_archive.archive'),
      $queue
    );

    $worker->processItem(['nid' => $node->id()]);

    $archived = Node::load($node->id());
    self::assertSame($this->archivedStatusId(), (string) $archived->get('field_status')->target_id);
    self::assertStringEndsWith('@anonymized.off', $archived->get('field_e_mail')->value);
    self::assertFalse($this->loggerHasRecord(
      LogLevel::NOTICE,
      'Node ID @nid anonymized on transition to the archived status: @fields'
    ));
  }

  /**
   * The backfill anonymizes only archived nodes with plain contact data.
   */
  public function testBackfillAnonymizesArchivedPlainContactData(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('anonymize', 0)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
      ])
      ->save();
    $archived = $this->archivedStatusId();
    $plain = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => 'plain@example.org',
      'changed' => 1000000000,
    ]);
    $phone_only = $this->createRequest([
      'field_status' => $archived,
      'field_phone' => '+49 221 654321',
    ]);
    $done = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => 'abc@anonymized.off',
    ]);
    $open = $this->createRequest([
      'field_e_mail' => 'open@example.org',
    ]);
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();

    $count = $this->container->get('markaspot_archive.archive')
      ->backfillArchived(10, 20)['changed'];

    self::assertSame(2, $count);
    $plain = Node::load($plain->id());
    self::assertStringEndsWith('@anonymized.off', $plain->get('field_e_mail')->value);
    self::assertSame(1000000000, (int) $plain->getChangedTime());
    self::assertSame('+49-0123459995555', Node::load($phone_only->id())->get('field_phone')->value);
    self::assertSame('abc@anonymized.off', Node::load($done->id())->get('field_e_mail')->value);
    self::assertSame('open@example.org', Node::load($open->id())->get('field_e_mail')->value);
  }

  /**
   * The backfill cleans plain contact data left in previous revisions.
   */
  public function testBackfillCleansPlainDataInPreviousRevisions(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();
    $node = $this->createRequest([
      'field_status' => $this->archivedStatusId(),
      'field_e_mail' => 'old@example.org',
    ]);
    $first_revision = (int) $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'abc@anonymized.off');
    $node->save();
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();

    $count = $this->container->get('markaspot_archive.archive')
      ->backfillArchived(10, 20)['changed'];

    self::assertSame(1, $count);
    $current = Node::load($node->id())->get('field_e_mail')->value;
    self::assertStringEndsWith('@anonymized.off', $current);
    self::assertSame($current, $this->revisionFieldValue($first_revision, 'field_e_mail'));
    self::assertSame(0, $this->container->get('markaspot_archive.archive')->backfillArchived(10, 20)['changed']);
  }

  /**
   * The backfill finds plain names left in previous revisions.
   */
  public function testBackfillCleansPlainNamesInPreviousRevisions(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('anonymize', 0)
      ->set('anonymize_fields', ['field_first_name' => 'field_first_name'])
      ->save();
    $node = $this->createRequest([
      'field_status' => $this->archivedStatusId(),
      'field_first_name' => 'Jane',
    ]);
    $first_revision = (int) $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->set('field_first_name', '0a1b2c3d4e');
    $node->save();
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();

    self::assertSame(1, $this->container->get('markaspot_archive.archive')->backfillArchived(10, 20)['changed']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) $this->revisionFieldValue($first_revision, 'field_first_name'));
    self::assertSame(0, $this->container->get('markaspot_archive.archive')->backfillArchived(10, 20)['changed']);
  }

  /**
   * The backfill honors its limit and starts over once it is exhausted.
   */
  public function testBackfillLimitAndCursorReset(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();
    $nids = [];
    foreach (['a', 'b', 'c'] as $name) {
      $nids[] = (int) $this->createRequest([
        'field_status' => $this->archivedStatusId(),
        'field_e_mail' => $name . '@example.org',
      ])->id();
    }
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');

    self::assertSame(2, $service->backfillArchived(2, 20)['changed']);
    self::assertSame($nids[1], (int) $state->get(ArchiveService::BACKFILL_CURSOR_STATE));
    self::assertSame(1, $service->backfillArchived(2, 20)['changed']);
    self::assertSame(0, $service->backfillArchived(2, 20)['changed']);
    self::assertSame(0, (int) $state->get(ArchiveService::BACKFILL_CURSOR_STATE));
  }

  /**
   * Disabled anonymization leaves transitions and the backfill alone.
   */
  public function testDisabledAnonymizationSkipsTransitionAndBackfill(): void {
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();

    $node->set('field_status', $this->archivedStatusId());
    $node->save();

    self::assertSame('citizen@example.org', $node->get('field_e_mail')->value);
    self::assertSame(0, $this->container->get('markaspot_archive.archive')->backfillArchived(10, 20)['changed']);
    self::assertSame('citizen@example.org', Node::load($node->id())->get('field_e_mail')->value);
  }

  /**
   * Archiving a report directly unpublishes it and anonymizes all revisions.
   */
  public function testDirectArchiveUnpublishesAndAnonymizesAllRevisions(): void {
    $this->listenToArchiveChannel();
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('unpublish', 1)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
      ])
      ->save();
    $first_revision = (int) $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->set('field_phone', '+49 221 123456');
    $node->save();
    $second_revision = (int) $node->getRevisionId();
    self::assertTrue($node->isPublished());

    // A status change outside the queue worker, as a staff member, the
    // dashboard or an Open311 status update saves it.
    $node->setNewRevision(TRUE);
    $node->set('field_status', $this->archivedStatusId());
    $node->save();

    $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->resetCache([$node->id()]);
    $archived = Node::load($node->id());
    self::assertFalse($archived->isPublished());
    $email = (string) $archived->get('field_e_mail')->value;
    self::assertStringEndsWith('@anonymized.off', $email);
    self::assertSame('+49-0123459995555', $archived->get('field_phone')->value);
    self::assertSame($email, $this->revisionFieldValue($first_revision, 'field_e_mail'));
    self::assertSame($email, $this->revisionFieldValue($second_revision, 'field_e_mail'));
    self::assertSame('+49-0123459995555', $this->revisionFieldValue($second_revision, 'field_phone'));
    $revision_ids = $this->nodeRevisionIds((int) $node->id());
    self::assertCount(3, $revision_ids);
    foreach ($revision_ids as $revision_id) {
      self::assertNotSame('citizen@example.org', $this->revisionFieldValue($revision_id, 'field_e_mail'));
      self::assertNotSame('+49 221 123456', $this->revisionFieldValue($revision_id, 'field_phone'));
    }
    self::assertTrue($this->loggerHasRecord(
      LogLevel::NOTICE,
      'Node ID @nid unpublished on transition to the archived status.'
    ));
  }

  /**
   * Unpublishing on a direct archive follows its own setting.
   */
  public function testDirectArchiveUnpublishFollowsItsSetting(): void {
    // Unpublish off: the archived report stays published, but is anonymized.
    $published = $this->createQueueRequest();
    $published->set('field_status', $this->archivedStatusId());
    $published->save();
    $published = Node::load($published->id());
    self::assertTrue($published->isPublished());
    self::assertStringEndsWith('@anonymized.off', $published->get('field_e_mail')->value);

    // Unpublish on, anonymize off: unpublished, contact data untouched.
    $plain = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('unpublish', 1)
      ->set('anonymize', 0)
      ->save();
    $plain->set('field_status', $this->archivedStatusId());
    $plain->save();
    $plain = Node::load($plain->id());
    self::assertFalse($plain->isPublished());
    self::assertSame('citizen@example.org', $plain->get('field_e_mail')->value);

    // A report created with the archived status is unpublished as well.
    $created = $this->createRequest([
      'field_status' => $this->archivedStatusId(),
      'field_e_mail' => 'created@example.org',
    ]);
    self::assertFalse(Node::load($created->id())->isPublished());
  }

  /**
   * Republishing an archived report is no transition and stays published.
   */
  public function testRepublishedArchivedReportStaysPublished(): void {
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    $node->set('field_status', $this->archivedStatusId());
    $node->save();
    self::assertFalse(Node::load($node->id())->isPublished());

    $republished = Node::load($node->id());
    $republished->setPublished();
    $republished->save();

    self::assertTrue(Node::load($node->id())->isPublished());
  }

  /**
   * The queue worker unpublishes once; the transition hook adds nothing.
   */
  public function testQueueWorkerArchiveUnpublishesWithoutTransitionNotice(): void {
    $this->listenToArchiveChannel();
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    $worker = $this->archiveQueueWorker(
      $this->container->get('markaspot_archive.archive'),
      $this->createMock(QueueInterface::class)
    );

    $worker->processItem(['nid' => $node->id()]);

    $archived = Node::load($node->id());
    self::assertSame($this->archivedStatusId(), (string) $archived->get('field_status')->target_id);
    self::assertFalse($archived->isPublished());
    self::assertFalse($this->loggerHasRecord(
      LogLevel::NOTICE,
      'Node ID @nid unpublished on transition to the archived status.'
    ));
  }

  /**
   * The catch-up unpublishes archived published reports once, nothing else.
   */
  public function testUnpublishBacklogUnpublishesArchivedReportsOnce(): void {
    $this->listenToArchiveChannel();
    $open = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();
    $archived = $this->archivedStatusId();
    // Archived while transition unpublishing did not exist yet: published,
    // contact data already anonymized.
    $published = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => 'abc@anonymized.off',
      'changed' => 1000000000,
    ]);
    $hidden = $this->createRequest([
      'field_status' => $archived,
      'status' => 0,
    ]);
    self::assertTrue(Node::load($published->id())->isPublished());
    $this->config('markaspot_archive.settings')
      ->set('unpublish', 1)
      ->set('anonymize', 1)
      ->save();
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');

    self::assertSame(1, $service->unpublishArchivedBacklog(10, 20)['changed']);

    $published = Node::load($published->id());
    self::assertFalse($published->isPublished());
    self::assertSame(1000000000, (int) $published->getChangedTime());
    self::assertSame('abc@anonymized.off', $published->get('field_e_mail')->value);
    self::assertFalse(Node::load($hidden->id())->isPublished());
    self::assertTrue(Node::load($open->id())->isPublished());
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    self::assertTrue($this->loggerHasRecord(
      LogLevel::NOTICE,
      'Unpublished @count archived service requests that were still published (@failed failed, up to node ID @nid).'
    ));

    // The next run finds nothing and ends the pass.
    self::assertSame(0, $service->unpublishArchivedBacklog(10, 20)['changed']);
    self::assertTrue($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_CURSOR_STATE));

    // A report staff republish after the pass stays published.
    $published->setPublished();
    $published->save();
    self::assertSame(0, $service->unpublishArchivedBacklog(10, 20)['changed']);
    self::assertTrue(Node::load($published->id())->isPublished());
  }

  /**
   * The catch-up honors its limit and follows the unpublish setting only.
   */
  public function testUnpublishBacklogLimitAndSetting(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();
    $nids = [];
    foreach (['a', 'b', 'c'] as $name) {
      $nids[] = (int) $this->createRequest([
        'field_status' => $this->archivedStatusId(),
        'field_e_mail' => $name . '@example.org',
      ])->id();
    }
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');

    // Unpublish off: nothing happens and the pass stays pending.
    self::assertSame(0, $service->unpublishArchivedBacklog(2, 20)['changed']);
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));

    // Unpublish on, anonymize off: unpublished, contact data untouched.
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    self::assertSame(2, $service->unpublishArchivedBacklog(2, 20)['changed']);
    self::assertSame($nids[1], (int) $state->get(ArchiveService::UNPUBLISH_BACKLOG_CURSOR_STATE));
    self::assertSame(1, $service->unpublishArchivedBacklog(2, 20)['changed']);
    self::assertSame(0, $service->unpublishArchivedBacklog(2, 20)['changed']);
    self::assertTrue($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    foreach ($nids as $i => $nid) {
      $node = Node::load($nid);
      self::assertFalse($node->isPublished());
      self::assertSame(['a', 'b', 'c'][$i] . '@example.org', $node->get('field_e_mail')->value);
    }
  }

  /**
   * The backfill command dry run counts both steps and writes nothing.
   */
  public function testBackfillCommandDryRunCountsWithoutWriting(): void {
    $nids = $this->createArchivedBacklog(3);
    $revisions = array_map(static fn (int $nid): int => (int) Node::load($nid)->getRevisionId(), $nids);

    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions(['dry-run' => TRUE])
    ));

    self::assertSame([
      ['unpublish', 'on', 3, 0, 0, 0, 3, 'Dry run: nothing was changed.'],
      ['anonymize', 'on', 3, 0, 0, 0, 3, 'Dry run: nothing was changed.'],
    ], array_map('array_values', $rows));
    foreach ($nids as $i => $nid) {
      $node = Node::load($nid);
      self::assertTrue($node->isPublished());
      self::assertSame('plain' . $i . '@example.org', $node->get('field_e_mail')->value);
      self::assertSame($revisions[$i], (int) $node->getRevisionId());
    }
  }

  /**
   * The command honors its limit, finishes in a rerun and stays idempotent.
   */
  public function testBackfillCommandLimitRerunAndIdempotence(): void {
    $nids = $this->createArchivedBacklog(5);
    $state = $this->container->get('state');

    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions(['limit' => 2, 'batch-size' => 1])
    ));
    self::assertSame(['unpublish', 'on', 5, 2, 2, 0, 3], array_slice(array_values($rows[0]), 0, 7));
    self::assertSame(['anonymize', 'on', 5, 2, 2, 0, 3], array_slice(array_values($rows[1]), 0, 7));
    self::assertSame('Limit reached: cron or the next run continues.', $rows[1]['note']);

    // Wherever cron left the backfill cursor, the command covers all.
    $state->set(ArchiveService::BACKFILL_CURSOR_STATE, max($nids));
    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions(['batch-size' => 2])
    ));
    self::assertSame(['unpublish', 'on', 3, 3, 3, 0, 0, 'The one-time pass has ended.'], array_values($rows[0]));
    self::assertSame(['anonymize', 'on', 3, 3, 3, 0, 0, ''], array_values($rows[1]));
    self::assertTrue($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      self::assertFalse($node->isPublished());
      self::assertStringEndsWith('@anonymized.off', $node->get('field_e_mail')->value);
      self::assertSame(1000000000, (int) $node->getChangedTime());
    }
    $revisions = array_map(static fn (int $nid): int => (int) Node::load($nid)->getRevisionId(), $nids);

    // A further run finds nothing and saves nothing.
    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions()
    ));
    self::assertSame(['unpublish', 'on', 0, 0, 0, 0, 0], array_slice(array_values($rows[0]), 0, 7));
    self::assertStringStartsWith('The one-time pass has ended;', $rows[0]['note']);
    self::assertSame(['anonymize', 'on', 0, 0, 0, 0, 0, ''], array_values($rows[1]));
    foreach ($nids as $i => $nid) {
      self::assertSame($revisions[$i], (int) Node::load($nid)->getRevisionId());
    }
  }

  /**
   * One step can run alone; the settings and the options are respected.
   */
  public function testBackfillCommandOnlyOptionAndSettings(): void {
    $nids = $this->createArchivedBacklog(2);
    $this->config('markaspot_archive.settings')->set('unpublish', 0)->save();

    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions(['only' => 'unpublish'])
    ));
    self::assertSame([['unpublish', 'off', 2, 0, 0, 0, 2, 'The "unpublish" setting is off: nothing is unpublished.']], array_map('array_values', $rows));

    $rows = $this->rowsToArray($this->archiveCommand()->backfill(
      $this->backfillOptions(['only' => 'anonymize'])
    ));
    self::assertCount(1, $rows);
    self::assertSame(['anonymize', 'on', 2, 2, 2, 0, 0, ''], array_values($rows[0]));
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      self::assertTrue($node->isPublished());
      self::assertStringEndsWith('@anonymized.off', $node->get('field_e_mail')->value);
    }

    foreach ([['only' => 'publish'], ['limit' => -1], ['batch-size' => 0]] as $invalid) {
      try {
        $this->archiveCommand()->backfill($this->backfillOptions($invalid));
        self::fail('Invalid options were accepted: ' . json_encode($invalid));
      }
      catch (\InvalidArgumentException) {
        // Expected.
      }
    }
  }

  /**
   * Creates archived, published requests with plain contact data.
   *
   * They reach the archived status while the settings are off, as requests
   * archived before transition handling existed; the settings are on after.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  private function createArchivedBacklog(int $count): array {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('anonymize', 0)->save();
    $nids = [];
    for ($i = 0; $i < $count; $i++) {
      $nids[] = (int) $this->createRequest([
        'field_status' => $this->archivedStatusId(),
        'field_e_mail' => 'plain' . $i . '@example.org',
        'changed' => 1000000000,
      ])->id();
    }
    $this->config('markaspot_archive.settings')
      ->set('anonymize', 1)
      ->set('unpublish', 1)
      ->save();
    return $nids;
  }

  /**
   * Returns backfill command options with Drush-like defaults.
   *
   * @param array<string, mixed> $overrides
   *   Option overrides.
   *
   * @return array<string, mixed>
   *   Command options.
   */
  private function backfillOptions(array $overrides = []): array {
    return $overrides + [
      'dry-run' => FALSE,
      'only' => NULL,
      'limit' => 0,
      'batch-size' => ArchiveCommands::BACKFILL_BATCH_SIZE,
    ];
  }

  /**
   * Returns the archived status term id from the archive settings.
   */
  private function archivedStatusId(): string {
    return (string) $this->config('markaspot_archive.settings')->get('status_archived');
  }

  /**
   * Routes the container archive service log channel into the test sink.
   */
  private function listenToArchiveChannel(): void {
    $this->container->get('logger.channel.markaspot_archive')
      ->addLogger($this->loggerSink);
  }

  /**
   * Creates a field on the service_request node type.
   */
  private function createNodeField(
    string $name,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'service_request', $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $name,
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Creates a service request node.
   */
  private function createRequest(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Broken streetlight',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a service request configured for queue processing.
   */
  private function createQueueRequest(): Node {
    $open_status = $this->createStatusTerm('Open');
    $archived_status = $this->createStatusTerm('Archived');
    $this->config('markaspot_archive.settings')
      ->set('status_archivable', [
        (int) $open_status->id() => (string) $open_status->id(),
      ])
      ->set('status_archived', (string) $archived_status->id())
      ->set('unpublish', 0)
      ->set('anonymize', 1)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
      ])
      ->save();

    return $this->createRequest([
      'field_status' => (int) $open_status->id(),
      'field_e_mail' => 'citizen@example.org',
    ]);
  }

  /**
   * Creates a service status term.
   */
  private function createStatusTerm(string $name): Term {
    $term = Term::create([
      'vid' => 'service_status',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Builds an archive service mock that fails during anonymization.
   */
  private function failingArchiveService(
    \Throwable $exception,
  ): ArchiveServiceInterface {
    $archive_service = $this->createMock(ArchiveServiceInterface::class);
    $archive_service
      ->method('normalizeConfiguredFields')
      ->willReturn([
        'field_e_mail' => 'field_e_mail',
      ]);
    $archive_service
      ->method('anonymize')
      ->willThrowException($exception);
    return $archive_service;
  }

  /**
   * Builds a queue worker for targeted failure handling assertions.
   */
  private function archiveQueueWorker(
    ArchiveServiceInterface $archive_service,
    QueueInterface $queue,
  ): ArchiveQueueWorker {
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory
      ->method('get')
      ->with('markaspot_archive_queue_worker')
      ->willReturn($queue);

    return new ArchiveQueueWorker(
      [],
      'markaspot_archive_queue_worker',
      [],
      $archive_service,
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->testLogger(),
      $queue_factory,
      $this->container->get('state'),
      $this->container->get('datetime.time')
    );
  }

  /**
   * Builds the staff anonymization command under test.
   */
  private function archiveCommand(): ArchiveCommands {
    return new ArchiveCommands(
      $this->archiveService,
      $this->container->get('config.factory'),
      $this->container->get('date.formatter'),
      $this->container->get('entity_type.manager'),
      $this->testLogger(),
      $this->container->get('database'),
      $this->container->get('state'),
      $this->container->get('lock'),
      NULL
    );
  }

  /**
   * Returns command options with Drush-like defaults.
   *
   * @param array<string, mixed> $overrides
   *   Option overrides.
   *
   * @return array<string, mixed>
   *   Command options.
   */
  private function commandOptions(array $overrides = []): array {
    return $overrides + [
      'by-mail' => NULL,
      'jurisdiction' => NULL,
      'dry-run' => FALSE,
      'no-cascade' => FALSE,
    ];
  }

  /**
   * Converts Drush rows into a plain assertion array.
   *
   * @return array<int, array<string, mixed>>
   *   Row arrays.
   */
  private function rowsToArray(?RowsOfFields $rows): array {
    self::assertInstanceOf(RowsOfFields::class, $rows);
    $result = [];
    foreach ($rows as $row) {
      $result[] = $row;
    }
    return $result;
  }

  /**
   * Returns node revision IDs without using deprecated storage APIs.
   *
   * @return int[]
   *   Revision IDs in ascending order.
   */
  private function nodeRevisionIds(int $nid): array {
    $values = $this->container
      ->get('database')
      ->select('node_revision', 'nr')
      ->fields('nr', ['vid'])
      ->condition('nid', $nid)
      ->orderBy('vid')
      ->execute()
      ->fetchCol();
    return array_map('intval', $values);
  }

  /**
   * Returns a test logger backed by the assertion sink.
   */
  private function testLogger(): LoggerChannel {
    $logger = new LoggerChannel('markaspot_archive');
    $logger->addLogger($this->loggerSink);
    return $logger;
  }

  /**
   * Returns TRUE when a matching log record exists.
   *
   * LoggerChannel translates PSR string levels to RFC 5424 integers before
   * delegating to registered loggers, so both representations must match.
   */
  private function loggerHasRecord(string $level, string $message): bool {
    $rfc_levels = [
      LogLevel::CRITICAL => RfcLogLevel::CRITICAL,
      LogLevel::WARNING => RfcLogLevel::WARNING,
      LogLevel::NOTICE => RfcLogLevel::NOTICE,
    ];
    $rfc_level = $rfc_levels[$level] ?? NULL;
    foreach ($this->loggerSink->records as $record) {
      if (
        ($record['level'] === $level || $record['level'] === $rfc_level)
        && (string) $record['message'] === $message
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads a revision field value directly from the revision field table.
   */
  private function revisionFieldValue(
    int $revision_id,
    string $field_name,
  ): ?string {
    $column = $field_name . '_value';
    $value = $this->container
      ->get('database')
      ->select('node_revision__' . $field_name, 'f')
      ->fields('f', [$column])
      ->condition('revision_id', $revision_id)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

}
