<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Drupal\service_request\RevisionLog;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Lock\LockBackendInterface;
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
use Drupal\node\NodeInterface;
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
   * Node whose next save fails, if any.
   */
  private ?int $failingNid = NULL;

  /**
   * Node whose save into the archived status fails, if any.
   */
  private ?int $failingArchiveNid = NULL;

  /**
   * Node whose derived-data cleanup fails, if any.
   */
  private ?int $failingCleanupNid = NULL;

  /**
   * Node whose save makes the catch-up lose its lock, if any.
   */
  private ?int $lockLostOnSaveOf = NULL;

  /**
   * TRUE once the catch-up lock counts as lost.
   */
  private bool $lockLost = FALSE;

  /**
   * Node whose saves fail with a database error, if any.
   */
  private ?int $databaseErrorNid = NULL;

  /**
   * Database error the chosen node's saves fail with.
   */
  private ?\Throwable $databaseError = NULL;

  /**
   * Saves of the chosen node that still fail.
   */
  private int $databaseErrorsLeft = 0;

  /**
   * Saves of the chosen node that failed.
   */
  private int $databaseErrorsThrown = 0;

  /**
   * Transaction of a second connection holding a row lock, if any.
   */
  private ?Transaction $heldLock = NULL;

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
   * Text that replaces the free-text message of an anonymized report's logs.
   */
  private const PLACEHOLDER = 'Message removed when the report was anonymized.';

  /**
   * Warning logged before a transaction is retried after a lock conflict.
   */
  private const LOCK_CONFLICT_RETRY = 'Node ID @nid hit a database lock conflict (attempt @attempt of @max) and is tried again: @error';

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
    RevisionLog::setMessage($node, 'Janet asked to be called back.');
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
    // The staff note quoting the reporter is gone as well.
    self::assertSame(self::PLACEHOLDER, RevisionLog::decode($this->revisionLog($second_revision))['message']);
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
   * A failing archive save leaves the request as it was, then succeeds.
   *
   * The worker archives, unpublishes and anonymizes in one save: when it
   * fails, the request keeps its eligible status, its contact data and its
   * publication. A non-archived request is never left anonymized.
   */
  public function testQueueWorkerFailingArchiveSaveChangesNothing(): void {
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    $this->failingArchiveNid = (int) $node->id();
    $revisions = $this->nodeRevisionIds((int) $node->id());
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::once())->method('createItem')->with(['nid' => (int) $node->id(), 'attempts' => 2]);
    $worker = $this->archiveQueueWorker($this->container->get('markaspot_archive.archive'), $queue);

    $worker->processItem(['nid' => $node->id(), 'attempts' => 1]);

    $this->assertRequestUntouched((int) $node->id(), $revisions);

    // Once the cause is gone, the retry archives the request completely.
    $this->failingArchiveNid = NULL;
    $worker->processItem(['nid' => $node->id(), 'attempts' => 2]);
    $archived = $this->reloadNode((int) $node->id());
    self::assertSame($this->archivedStatusId(), (string) $archived->get('field_status')->target_id);
    self::assertFalse($archived->isPublished());
    self::assertStringEndsWith('@anonymized.off', (string) $archived->get('field_e_mail')->value);
    foreach ($this->nodeRevisionIds((int) $node->id()) as $vid) {
      self::assertNotSame('citizen@example.org', $this->revisionFieldValue($vid, 'field_e_mail'));
    }
  }

  /**
   * A failing cleanup after the archive save rolls the save back too.
   */
  public function testQueueWorkerFailingCleanupRollsBackTheArchive(): void {
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    $this->failingCleanupNid = (int) $node->id();
    $revisions = $this->nodeRevisionIds((int) $node->id());
    $worker = $this->archiveQueueWorker(
      $this->container->get('markaspot_archive.archive'),
      $this->createMock(QueueInterface::class)
    );

    $worker->processItem(['nid' => $node->id(), 'attempts' => 1]);

    $this->assertRequestUntouched((int) $node->id(), $revisions);
  }

  /**
   * The worker rechecks the status under the lock and leaves a changed one.
   */
  public function testArchiveRequestRechecksTheStoredStatus(): void {
    $node = $this->createQueueRequest();
    $service = $this->container->get('markaspot_archive.archive');
    // Staff moved the request to a status that is not archivable.
    $other = $this->createStatusTerm('In progress');
    $node->set('field_status', $other->id());
    $node->save();
    $revisions = $this->nodeRevisionIds((int) $node->id());

    self::assertSame(ArchiveServiceInterface::ARCHIVE_NOT_ARCHIVABLE, $service->archiveRequest((int) $node->id()));
    self::assertSame($revisions, $this->nodeRevisionIds((int) $node->id()));
    self::assertSame('citizen@example.org', $this->reloadNode((int) $node->id())->get('field_e_mail')->value);
    self::assertSame(ArchiveServiceInterface::ARCHIVE_MISSING, $service->archiveRequest(999999));
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
   * The backfill also finds revision logs and text fields with plain data.
   *
   * The contact e-mail of all of these requests is already anonymized.
   */
  public function testBackfillFindsRevisionLogsFeedbackAndTextFields(): void {
    $this->createQueueRequest();
    $this->createNodeField('field_feedback', 'text_long');
    $this->createNodeField('field_reporter_note', 'string_long');
    $this->config('markaspot_archive.settings')
      ->set('anonymize', 0)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_reporter_note' => 'field_reporter_note',
      ])
      ->save();
    $archived = $this->archivedStatusId();
    $done = 'abc@anonymized.off';

    $log_only = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'field_status' => $archived,
      'field_e_mail' => $done,
      'changed' => 1000000000,
    ]);
    RevisionLog::setMessage($log_only, 'Call back at 0170 1234567.');
    $log_only->save();
    $feedback = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => $done,
      'field_feedback' => 'Thanks, fixed quickly.',
    ]);
    $feedback_revision = (int) $feedback->getRevisionId();
    $feedback->setNewRevision(TRUE);
    $feedback->set('field_feedback', NULL);
    $feedback->save();
    $note = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => $done,
      'field_reporter_note' => 'Ring twice, second floor.',
    ]);
    $clean = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => $done,
    ]);
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');
    $log_revisions = $this->nodeRevisionIds((int) $log_only->id());
    $clean_revision = (int) $clean->getRevisionId();

    self::assertSame(3, $service->countBackfillCandidates());
    self::assertSame(3, $service->backfillArchived(10, 20)['changed']);

    // Only the log changed: no save, no revision, same changed time.
    self::assertSame($log_revisions, $this->nodeRevisionIds((int) $log_only->id()));
    self::assertSame(self::PLACEHOLDER, RevisionLog::decode($this->revisionLog($log_revisions[0]))['message']);
    self::assertSame(1000000000, (int) Node::load($log_only->id())->getChangedTime());
    self::assertSame('', (string) $this->revisionFieldValue($feedback_revision, 'field_feedback'));
    self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) Node::load($note->id())->get('field_reporter_note')->value);
    self::assertSame($clean_revision, (int) Node::load($clean->id())->getRevisionId());

    self::assertSame(0, $service->countBackfillCandidates());
    self::assertSame(0, $service->backfillArchived(10, 20)['changed']);
  }

  /**
   * The backfill finds personal data held only in the summary of a text.
   *
   * The value is a token already, so only the summary column shows the
   * plain data, in the current revision of one request and in a previous
   * revision of another. Both columns are anonymized in every revision.
   */
  public function testBackfillFindsPersonalDataOnlyInTheSummary(): void {
    $this->createQueueRequest();
    $this->createNodeField('field_reporter_text', 'text_with_summary');
    $this->config('markaspot_archive.settings')
      ->set('anonymize', 0)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_reporter_text' => 'field_reporter_text',
      ])
      ->save();
    $archived = $this->archivedStatusId();
    $text = static fn (string $summary): array => [
      'value' => '0a1b2c3d4e',
      'summary' => $summary,
      'format' => 'plain_text',
    ];
    $current = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => 'abc@anonymized.off',
      'field_reporter_text' => $text('plain@example.org'),
    ]);
    $historic = $this->createRequest([
      'field_status' => $archived,
      'field_e_mail' => 'abc@anonymized.off',
      'field_reporter_text' => $text('Jordan, second floor'),
    ]);
    $historic->setNewRevision(TRUE);
    $historic->set('field_reporter_text', $text('5f4e3d2c1b'));
    $historic->save();
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');

    self::assertSame(2, $service->countBackfillCandidates());
    self::assertSame(2, $service->backfillArchived(10, 20)['changed']);

    foreach ([$current, $historic] as $node) {
      $rows = $this->container->get('database')
        ->select('node_revision__field_reporter_text', 'f')
        ->fields('f', ['field_reporter_text_value', 'field_reporter_text_summary'])
        ->condition('entity_id', $node->id())
        ->execute()
        ->fetchAll(\PDO::FETCH_NUM);
      self::assertNotEmpty($rows);
      foreach ($rows as [$value, $summary]) {
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) $value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) $summary);
      }
    }
    self::assertSame(0, $service->countBackfillCandidates());
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
   * Archiving replaces the message of every revision log, keeping events.
   *
   * Covers civic.revision.v1 envelopes, whose events and changes readers of
   * the history rely on (moderation holds), and legacy free-text logs. No
   * message survives, a neutral staff note included; empty messages stay
   * empty.
   */
  public function testArchiveReplacesTheMessageOfEveryRevisionLog(): void {
    $node = $this->createQueueRequest();
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
        'field_first_name' => 'field_first_name',
      ])
      ->save();
    $created_vid = (int) $node->getRevisionId();
    $created_log = $this->revisionLog($created_vid);
    self::assertSame('', RevisionLog::decode($created_log)['message']);
    $node->set('field_first_name', 'Jordan');
    $node->set('field_phone', '+49 221 123456');
    $node->setNewRevision(TRUE);
    $node->save();

    // Moderation hid the report with a note quoting the reporter.
    $node->setNewRevision(TRUE);
    $node->setUnpublished();
    RevisionLog::setEvent($node, 'moderation_hidden');
    RevisionLog::setMessage($node, 'Hidden after a call with Jordan at +44 7700 900123, write to citizen@example.org.');
    $node->save();
    $hidden_vid = (int) $node->getRevisionId();
    $hidden_before = RevisionLog::decode($this->revisionLog($hidden_vid));
    self::assertSame(['moderation_hidden', 'unpublished'], $hidden_before['events']);

    // A legacy free-text log and a neutral note.
    $node->setNewRevision(TRUE);
    $node->setTitle('Broken streetlight, pole 12');
    $node->save();
    $legacy_vid = (int) $node->getRevisionId();
    $this->container->get('database')->update('node_revision')
      ->fields(['revision_log' => 'Jordan asked for an update, call +49 (0)221 1234567.'])
      ->condition('vid', $legacy_vid)
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $node = Node::load($node->id());
    $node->setNewRevision(TRUE);
    RevisionLog::setMessage($node, 'Crew scheduled for Tuesday, ticket 1034-2026.');
    $node->save();
    $neutral_vid = (int) $node->getRevisionId();

    // Staff archive the report with a final note.
    $node->setNewRevision(TRUE);
    $node->set('field_status', $this->archivedStatusId());
    RevisionLog::setMessage($node, 'Archived; Jordan (call 1234567) was informed.');
    $node->save();
    $archive_vid = (int) $node->getRevisionId();

    foreach ($this->nodeRevisionIds((int) $node->id()) as $vid) {
      $log = $this->revisionLog($vid);
      foreach (['citizen@example.org', 'Jordan', '7700', '1234567', 'Tuesday'] as $plain) {
        self::assertStringNotContainsString($plain, $log, "Revision $vid");
      }
    }
    self::assertSame($created_log, $this->revisionLog($created_vid), 'An empty message stays empty.');
    $hidden_after = RevisionLog::decode($this->revisionLog($hidden_vid));
    self::assertSame(self::PLACEHOLDER, $hidden_after['message']);
    unset($hidden_before['message'], $hidden_after['message']);
    self::assertSame($hidden_before, $hidden_after);
    self::assertSame(self::PLACEHOLDER, $this->revisionLog($legacy_vid));
    self::assertSame(self::PLACEHOLDER, RevisionLog::decode($this->revisionLog($neutral_vid))['message']);
    $archive_log = RevisionLog::decode($this->revisionLog($archive_vid));
    self::assertSame(self::PLACEHOLDER, $archive_log['message']);
    self::assertSame('status', $archive_log['changes'][0]['field']);
    self::assertSame(0, $this->container->get('markaspot_archive.archive')->countBackfillCandidates());

    // Saving the same object again must not bring the old note back.
    self::assertSame($this->revisionLog($archive_vid), $node->getRevisionLogMessage());
    $node->setNewRevision(TRUE);
    $node->setTitle('Broken streetlight, archived');
    $node->save();
    self::assertSame('', RevisionLog::decode($this->revisionLog((int) $node->getRevisionId()))['message']);
  }

  /**
   * The backfill replaces every free-text message, then finds nothing more.
   *
   * Only the decoded message counts: a status label quoting a number in the
   * changes of an envelope keeps no report a candidate. Events, changes,
   * empty and already replaced messages stay byte-identical, and no
   * revision is added.
   */
  public function testBackfillReplacesMessagesUntilNoneIsLeft(): void {
    $this->createQueueRequest();
    $archived = $this->archivedStatusId();
    $done = 'abc@anonymized.off';
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $envelope = static fn (string $message, array $events = []): string => json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [[
        'field' => 'status',
        'before' => [['id' => '1', 'label' => 'Callback 02211234567']],
        'after' => [['id' => $archived]],
      ]],
      'events' => $events,
      'message' => $message,
    ], $flags);

    $label_only = $this->createRequest(['field_status' => $archived, 'field_e_mail' => $done]);
    $noted = $this->createRequest(['field_status' => $archived, 'field_e_mail' => $done]);
    for ($i = 0; $i < 4; $i++) {
      $noted->setNewRevision(TRUE);
      $noted->setTitle('Broken streetlight ' . $i);
      $noted->save();
    }
    $vids = $this->nodeRevisionIds((int) $noted->id());
    $logs = [
      $vids[0] => $envelope(''),
      $vids[1] => $envelope('Call 0221 1234567 after five.', ['moderation_hidden']),
      $vids[2] => 'Call +44 7700 900123, ask for Jordan.',
      $vids[3] => $envelope(self::PLACEHOLDER),
      $vids[4] => $envelope('', ['scope_sync']),
    ];
    $label_vid = (int) $label_only->getRevisionId();
    $database = $this->container->get('database');
    foreach ($logs + [$label_vid => $envelope('')] as $vid => $log) {
      $database->update('node_revision')->fields(['revision_log' => $log])->condition('vid', $vid)->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $service = $this->container->get('markaspot_archive.archive');

    self::assertSame(1, $service->countBackfillCandidates());
    self::assertSame(['processed' => 1, 'changed' => 1, 'failed' => 0], $service->backfillArchived(10, 20));

    self::assertSame($vids, $this->nodeRevisionIds((int) $noted->id()));
    $hidden = json_decode($logs[$vids[1]], TRUE);
    $hidden['message'] = self::PLACEHOLDER;
    $expected = [
      $vids[0] => $logs[$vids[0]],
      $vids[1] => json_encode($hidden, $flags),
      $vids[2] => self::PLACEHOLDER,
      $vids[3] => $logs[$vids[3]],
      $vids[4] => $logs[$vids[4]],
    ];
    foreach ($expected as $vid => $log) {
      self::assertSame($log, $this->revisionLog($vid), "Revision $vid");
    }
    self::assertSame($envelope(''), $this->revisionLog($label_vid));
    self::assertSame(['moderation_hidden'], RevisionLog::decode($this->revisionLog($vids[1]))['events']);

    self::assertSame(0, $service->countBackfillCandidates());
    self::assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $service->backfillArchived(10, 20));
    $this->container->get('state')->set(ArchiveService::BACKFILL_CURSOR_STATE, 0);
    self::assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $service->backfillArchived(10, 20));
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
   * A request staff reopen after the candidate query is left alone.
   *
   * Anonymization is irreversible, and saving the stale copy would undo the
   * staff change: both catch-ups recheck the stored status before a change.
   */
  public function testCatchUpLeavesRequestsReopenedAfterSelection(): void {
    $nids = $this->createArchivedBacklog(2);

    $this->racingArchiveService($nids[0])->backfillArchived(10, 20);
    $this->racingArchiveService($nids[1])->unpublishArchivedBacklog(10, 20);

    $reopened = Node::load($nids[0]);
    self::assertSame($this->openStatusId(), (string) $reopened->get('field_status')->target_id);
    self::assertSame('plain0@example.org', $reopened->get('field_e_mail')->value);
    self::assertTrue($reopened->isPublished(), 'The unpublish pass reopened copy stays published.');
    $other = Node::load($nids[1]);
    self::assertSame($this->openStatusId(), (string) $other->get('field_status')->target_id);
    self::assertStringEndsWith('@anonymized.off', $other->get('field_e_mail')->value);
    self::assertTrue($other->isPublished(), 'Reopened before its unpublish turn.');
  }

  /**
   * A failed unpublish is retried on a later run and keeps the pass open.
   */
  public function testUnpublishBacklogRetriesFailuresBeforeItEnds(): void {
    $nids = $this->createArchivedBacklog(3);
    $this->failingNid = $nids[1];
    $state = $this->container->get('state');

    $run = $this->freshArchiveService();
    self::assertSame(['processed' => 3, 'changed' => 2, 'failed' => 1], $run->unpublishArchivedBacklog(10, 20));
    // The same run does not try again, and the pass does not end.
    self::assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $run->unpublishArchivedBacklog(10, 20));
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    self::assertSame([$nids[1] => $nids[1]], $state->get(ArchiveService::UNPUBLISH_BACKLOG_FAILED_STATE));
    self::assertSame(1, $run->countUnpublishBacklog());
    self::assertTrue(Node::load($nids[1])->isPublished());

    // The next cron run retries it; it still fails.
    self::assertSame(['processed' => 1, 'changed' => 0, 'failed' => 1], $this->freshArchiveService()->unpublishArchivedBacklog(10, 20));
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));

    // Once the cause is gone, a later run unpublishes it and the pass ends.
    $this->failingNid = NULL;
    $run = $this->freshArchiveService();
    self::assertSame(['processed' => 1, 'changed' => 1, 'failed' => 0], $run->unpublishArchivedBacklog(10, 20));
    self::assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $run->unpublishArchivedBacklog(10, 20));
    self::assertTrue($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_FAILED_STATE));
    self::assertSame(0, $run->countUnpublishBacklog());
    self::assertFalse(Node::load($nids[1])->isPublished());
  }

  /**
   * The command exits with a failure while the cleanup is incomplete.
   */
  public function testBackfillCommandFailsWhileCleanupIsIncomplete(): void {
    $nids = $this->createArchivedBacklog(2);
    $this->failingNid = $nids[0];

    $result = $this->archiveCommand($this->freshArchiveService())->backfill($this->backfillOptions());
    self::assertSame(1, $result->getExitCode());
    $rows = $this->backfillRows($result);
    self::assertSame(['unpublish', 'on', 2, 2, 1, 1, 1], array_slice(array_values($rows[0]), 0, 7));
    self::assertSame(['anonymize', 'on', 2, 2, 1, 1, 1], array_slice(array_values($rows[1]), 0, 7));
    self::assertStringStartsWith('Some requests failed: a later run retries them.', $rows[0]['note']);
    self::assertStringStartsWith('Some requests failed: a later run retries them.', $rows[1]['note']);
    self::assertTrue(Node::load($nids[0])->isPublished());
    self::assertSame('plain0@example.org', Node::load($nids[0])->get('field_e_mail')->value);

    // A failure fails a --limit run too; a dry run never fails.
    $limited = $this->archiveCommand($this->freshArchiveService())->backfill($this->backfillOptions(['limit' => 1]));
    self::assertSame(1, $limited->getExitCode(), 'The retried request failed again.');
    $this->failingNid = NULL;
    self::assertSame(0, $this->archiveCommand($this->freshArchiveService())->backfill($this->backfillOptions(['dry-run' => TRUE]))->getExitCode());

    $result = $this->archiveCommand($this->freshArchiveService())->backfill($this->backfillOptions());
    self::assertSame(0, $result->getExitCode());
    $rows = $this->backfillRows($result);
    self::assertSame(['unpublish', 'on', 1, 1, 1, 0, 0, 'The one-time pass has ended.'], array_values($rows[0]));
    self::assertSame(['anonymize', 'on', 1, 1, 1, 0, 0, ''], array_values($rows[1]));
    self::assertFalse(Node::load($nids[0])->isPublished());
    self::assertStringEndsWith('@anonymized.off', Node::load($nids[0])->get('field_e_mail')->value);
  }

  /**
   * Fails the save of the chosen node, like a broken presave of a tenant.
   */
  #[Hook('node_presave')]
  public function failChosenNodeSave(NodeInterface $node): void {
    if ($this->failingNid !== NULL && (int) $node->id() === $this->failingNid) {
      throw new \RuntimeException('Synthetic save failure');
    }
    if (
      $this->failingArchiveNid !== NULL
      && (int) $node->id() === $this->failingArchiveNid
      && (string) $node->get('field_status')->target_id === $this->archivedStatusId()
    ) {
      throw new \RuntimeException('Synthetic archive save failure');
    }
  }

  /**
   * Fails the derived-data cleanup of the chosen node.
   */
  #[Hook('markaspot_report_anonymized')]
  public function failChosenCleanup(NodeInterface $node): void {
    if ($this->failingCleanupNid !== NULL && (int) $node->id() === $this->failingCleanupNid) {
      throw new \RuntimeException('Synthetic cleanup failure');
    }
  }

  /**
   * The command stops and fails once it cannot renew the catch-up lock.
   *
   * Cron or another run may hold the lock after the lease ran out; going on
   * would overlap with it.
   */
  public function testBackfillCommandStopsWhenTheLockIsLost(): void {
    $nids = $this->createArchivedBacklog(3);
    $calls = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    // The first acquisition and one renewal succeed, every later one fails.
    $lock->method('acquire')->willReturnCallback(static function () use (&$calls): bool {
      return ++$calls <= 2;
    });

    $result = $this->archiveCommand(NULL, $lock)->backfill($this->backfillOptions(['batch-size' => 1]));

    self::assertSame(1, $result->getExitCode());
    $rows = $this->backfillRows($result);
    self::assertCount(1, $rows, 'No further step runs.');
    self::assertSame(['unpublish', 'on', 3, 1, 1, 0, 2], array_slice(array_values($rows[0]), 0, 7));
    self::assertStringContainsString('lock', $rows[0]['note']);
    self::assertFalse(Node::load($nids[0])->isPublished());
    self::assertTrue(Node::load($nids[1])->isPublished());
    foreach ($nids as $i => $nid) {
      self::assertSame('plain' . $i . '@example.org', Node::load($nid)->get('field_e_mail')->value);
    }
  }

  /**
   * Losing the lock during the final operation fails the command.
   *
   * The final request of a limited run is processed after the last renewal:
   * the command must notice the loss afterwards, record neither progress nor
   * the end of the pass, and fail.
   */
  public function testBackfillCommandFailsWhenTheLockIsLostInTheFinalOperation(): void {
    $nids = $this->createArchivedBacklog(1);
    $state = $this->container->get('state');
    $this->lockLostOnSaveOf = $nids[0];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(fn (): bool => !$this->lockLost);

    $result = $this->archiveCommand($this->freshArchiveService(), $lock)
      ->backfill($this->backfillOptions(['only' => 'unpublish', 'limit' => 1]));

    self::assertTrue($this->lockLost);
    self::assertFalse(Node::load($nids[0])->isPublished(), 'The operation ran.');
    self::assertSame(1, $result->getExitCode());
    $rows = $this->backfillRows($result);
    self::assertSame(['unpublish', 'on', 1, 1, 1, 0, 0], array_slice(array_values($rows[0]), 0, 7));
    self::assertStringContainsString('lock', $rows[0]['note']);
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_CURSOR_STATE), 'No progress recorded.');
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
  }

  /**
   * The unpublish pass does not record its end after the lock was lost.
   */
  public function testUnpublishPassEndIsNotRecordedWithoutTheLock(): void {
    $this->createQueueRequest();
    $this->config('markaspot_archive.settings')->set('unpublish', 1)->save();
    $state = $this->container->get('state');
    $calls = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    // The command's acquisition and its renewal before the batch succeed;
    // the lease runs out before the pass records its end.
    $lock->method('acquire')->willReturnCallback(static function () use (&$calls): bool {
      return ++$calls <= 2;
    });

    $result = $this->archiveCommand($this->freshArchiveService(), $lock)
      ->backfill($this->backfillOptions(['only' => 'unpublish']));

    self::assertSame(1, $result->getExitCode());
    self::assertStringContainsString('lock', $this->backfillRows($result)[0]['note']);
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE));
  }

  /**
   * The backfill does not reset its cursor once the lock is lost.
   *
   * Another run that holds the lock now may have moved the cursor; an
   * expired run must not move it back.
   */
  public function testBackfillCursorResetNeedsTheLock(): void {
    $this->createArchivedBacklog(1);
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');
    $state->set(ArchiveService::BACKFILL_CURSOR_STATE, 900);

    self::assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $service->backfillArchived(10, 20, static fn (): bool => FALSE));
    self::assertSame(900, $state->get(ArchiveService::BACKFILL_CURSOR_STATE));

    // While the lock is held, the backfill starts over.
    self::assertSame(0, $service->backfillArchived(10, 20, static fn (): bool => TRUE)['processed']);
    self::assertSame(0, $state->get(ArchiveService::BACKFILL_CURSOR_STATE));
  }

  /**
   * The cron catch-up stops, and records no progress, once it loses the lock.
   */
  public function testCronCatchUpStopsWhenTheLockIsLost(): void {
    $nids = $this->createArchivedBacklog(3);
    // Only the catch-up part of cron runs.
    $this->config('markaspot_archive.settings')->set('cron_enable', 0)->save();
    $state = $this->container->get('state');
    $calls = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    // Cron acquires the lock; the renewal after the first request fails.
    $lock->method('acquire')->willReturnCallback(static function () use (&$calls): bool {
      return ++$calls <= 1;
    });
    $this->container->set('lock', $lock);

    $this->container->get('module_handler')->invoke('markaspot_archive', 'cron');

    self::assertSame(2, $calls, 'No renewal after the loss.');
    self::assertFalse($this->reloadNode($nids[0])->isPublished());
    self::assertTrue($this->reloadNode($nids[1])->isPublished());
    self::assertTrue($this->reloadNode($nids[2])->isPublished());
    self::assertNull($state->get(ArchiveService::UNPUBLISH_BACKLOG_CURSOR_STATE), 'No progress recorded.');
    // The anonymize step did not start.
    foreach ($nids as $i => $nid) {
      self::assertSame('plain' . $i . '@example.org', $this->reloadNode($nid)->get('field_e_mail')->value);
    }
    self::assertNull($state->get(ArchiveService::BACKFILL_CURSOR_STATE));

    // The anonymize step: the acquisition and the renewal before the step
    // succeed, the renewal after its first request fails.
    $this->config('markaspot_archive.settings')->set('unpublish', 0)->save();
    $calls = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static function () use (&$calls): bool {
      return ++$calls <= 2;
    });
    $this->container->set('lock', $lock);

    $this->container->get('module_handler')->invoke('markaspot_archive', 'cron');

    self::assertSame(3, $calls, 'No renewal after the loss.');
    self::assertStringEndsWith('@anonymized.off', $this->reloadNode($nids[0])->get('field_e_mail')->value);
    self::assertSame('plain1@example.org', $this->reloadNode($nids[1])->get('field_e_mail')->value);
    self::assertSame('plain2@example.org', $this->reloadNode($nids[2])->get('field_e_mail')->value);
    self::assertNull($state->get(ArchiveService::BACKFILL_CURSOR_STATE), 'No progress recorded.');
  }

  /**
   * Archiving from the queue does not retry a lock conflict itself.
   *
   * Its save runs the hooks of an ordinary status change, which may send
   * mail a rollback cannot take back; a retry would send it again. One
   * attempt is made, the transaction is rolled back, and the queue retries
   * the item on a later run.
   */
  public function testArchiveRequestDoesNotRetryLockConflict(): void {
    $node = $this->createQueueRequest();
    $nid = (int) $node->id();
    $revisions = $this->nodeRevisionIds($nid);
    $service = $this->retryingArchiveService();
    $this->failSavesWith($nid, $this->databaseError('40001', 1213, 'Deadlock found when trying to get lock'), 1);

    try {
      $service->archiveRequest($nid);
      self::fail('The deadlock is rethrown.');
    }
    catch (EntityStorageException $e) {
      self::assertInstanceOf(DatabaseExceptionWrapper::class, $e->getPrevious());
    }
    self::assertSame(1, $this->databaseErrorsThrown, 'Exactly one attempt.');
    self::assertSame([], $service->backoffs);
    self::assertFalse($this->loggerHasRecord(LogLevel::WARNING, self::LOCK_CONFLICT_RETRY));
    $this->assertRequestUntouched($nid, $revisions);

    // The queue worker requeues the item for a later run, again after one
    // attempt.
    $this->failSavesWith($nid, $this->databaseError('40001', 1213, 'Deadlock found when trying to get lock'), PHP_INT_MAX);
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::once())->method('createItem')->with(['nid' => $nid, 'attempts' => 2]);
    $this->archiveQueueWorker($service, $queue)->processItem(['nid' => $nid, 'attempts' => 1]);
    self::assertSame(1, $this->databaseErrorsThrown);
    self::assertSame([], $service->backoffs);
    $this->assertRequestUntouched($nid, $revisions);

    // Once the conflict is gone, the later run archives the request in one
    // combined revision.
    $this->failSavesWith($nid, $this->databaseError('40001', 1213, 'Deadlock found when trying to get lock'), 0);
    self::assertSame(ArchiveServiceInterface::ARCHIVE_DONE, $service->archiveRequest($nid));
    $archived = $this->reloadNode($nid);
    self::assertSame($this->archivedStatusId(), (string) $archived->get('field_status')->target_id);
    self::assertCount(count($revisions) + 1, $this->nodeRevisionIds($nid));
    foreach ($this->nodeRevisionIds($nid) as $vid) {
      self::assertNotSame('citizen@example.org', $this->revisionFieldValue($vid, 'field_e_mail'));
    }
  }

  /**
   * The catch-up retries a deadlock and leaves the request a candidate.
   */
  public function testCatchUpRetriesDeadlock(): void {
    $nids = $this->createArchivedBacklog(2);
    $service = $this->retryingArchiveService();
    $this->failSavesWith($nids[0], $this->databaseError('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'), 1);

    self::assertSame(['processed' => 2, 'changed' => 2, 'failed' => 0], $service->backfillArchived(10, 20));
    self::assertSame([1], $service->backoffs);
    self::assertSame(1, $this->databaseErrorsThrown, 'The second attempt succeeded.');
    self::assertStringEndsWith('@anonymized.off', $this->reloadNode($nids[0])->get('field_e_mail')->value);
    self::assertTrue($this->loggerHasRecord(LogLevel::WARNING, self::LOCK_CONFLICT_RETRY));

    // Every attempt fails: the request is counted as failed and stays a
    // candidate for the next run.
    $nids = $this->createArchivedBacklog(1);
    $this->failSavesWith($nids[0], $this->databaseError('40001', 1213, 'Deadlock found when trying to get lock'), PHP_INT_MAX);
    $service = $this->retryingArchiveService();
    self::assertSame(['processed' => 1, 'changed' => 0, 'failed' => 1], $service->backfillArchived(10, 20));
    self::assertSame(3, $this->databaseErrorsThrown);
    self::assertSame([1, 2], $service->backoffs);
    self::assertSame('plain0@example.org', $this->reloadNode($nids[0])->get('field_e_mail')->value);
    self::assertSame(1, $service->countBackfillCandidates());

    // Other database errors are not retried. Each run below starts over
    // from the first archived request.
    $state = $this->container->get('state');
    $state->delete(ArchiveService::BACKFILL_CURSOR_STATE);
    $this->failSavesWith($nids[0], $this->databaseError('42S02', 1146, "Table 'node__field_x' doesn't exist"), PHP_INT_MAX);
    $service = $this->retryingArchiveService();
    self::assertSame(['processed' => 1, 'changed' => 0, 'failed' => 1], $service->backfillArchived(10, 20));
    self::assertSame(1, $this->databaseErrorsThrown);
    self::assertSame([], $service->backoffs);

    // Inside an outer transaction a deadlock rolled back that one as well:
    // only its owner can retry.
    $this->failSavesWith($nids[0], $this->databaseError('40001', 1213, 'Deadlock found when trying to get lock'), 1);
    $service = $this->retryingArchiveService();
    $state->delete(ArchiveService::BACKFILL_CURSOR_STATE);
    $outer = $this->container->get('database')->startTransaction();
    self::assertSame(['processed' => 1, 'changed' => 0, 'failed' => 1], $service->backfillArchived(10, 20));
    $outer->rollBack();
    unset($outer);
    self::assertSame(1, $this->databaseErrorsThrown);
    self::assertSame([], $service->backoffs);
    self::assertSame('plain0@example.org', $this->reloadNode($nids[0])->get('field_e_mail')->value);
  }

  /**
   * A real lock wait timeout: the queue gives up, the catch-up retries.
   *
   * A second connection holds the row locks. Archiving from the queue runs
   * into the lock wait timeout once and leaves the request as it was. The
   * catch-up runs into it as well, the lock is released during its backoff
   * and the next attempt anonymizes the request.
   */
  public function testRealLockWaitTimeoutIsRetriedOnlyByTheCatchUp(): void {
    $database = $this->container->get('database');
    if ($database->databaseType() !== 'mysql') {
      self::markTestSkipped('Needs InnoDB row locks.');
    }
    $backlog = $this->createArchivedBacklog(1);
    $queued = (int) $this->createRequest([
      'field_status' => (int) $this->openStatusId(),
      'field_e_mail' => 'citizen@example.org',
    ])->id();
    $revisions = $this->nodeRevisionIds($queued);
    Database::addConnectionInfo('archive_other', 'default', Database::getConnectionInfo('default')['default']);
    try {
      $other = Database::getConnection('default', 'archive_other');
      $this->heldLock = $other->startTransaction();
      $other->select('node', 'n')->fields('n', ['nid'])->condition('n.nid', [$queued, $backlog[0]], 'IN')->forUpdate()->execute()->fetchCol();
      $database->query('SET SESSION innodb_lock_wait_timeout = 1');
      $service = $this->retryingArchiveService(function (): void {
        // Commits the other connection's transaction.
        $this->heldLock = NULL;
      });

      try {
        $service->archiveRequest($queued);
        self::fail('The lock wait timeout is rethrown.');
      }
      catch (DatabaseExceptionWrapper $e) {
        self::assertStringContainsString('1205', $e->getMessage());
      }
      self::assertSame([], $service->backoffs, 'Exactly one attempt.');
      self::assertNotNull($this->heldLock, 'The other connection still holds the locks.');
      $this->assertRequestUntouched($queued, $revisions);

      self::assertSame(['processed' => 1, 'changed' => 1, 'failed' => 0], $service->backfillArchived(10, 20));
      self::assertSame([1], $service->backoffs);
      self::assertStringEndsWith('@anonymized.off', (string) $this->reloadNode($backlog[0])->get('field_e_mail')->value);
    }
    finally {
      $this->heldLock = NULL;
      Database::removeConnection('archive_other');
    }
  }

  /**
   * Marks the catch-up lock as lost when the chosen node is saved.
   */
  #[Hook('node_update')]
  public function loseLockOnChosenSave(NodeInterface $node): void {
    if ($this->lockLostOnSaveOf !== NULL && (int) $node->id() === $this->lockLostOnSaveOf) {
      $this->lockLost = TRUE;
    }
  }

  /**
   * Fails the saves of the chosen node with the chosen database error.
   */
  #[Hook('node_presave')]
  public function failChosenSaveWithDatabaseError(NodeInterface $node): void {
    if (
      $this->databaseErrorNid !== NULL
      && $this->databaseError !== NULL
      && (int) $node->id() === $this->databaseErrorNid
      && $this->databaseErrorsLeft > 0
    ) {
      $this->databaseErrorsLeft--;
      $this->databaseErrorsThrown++;
      throw $this->databaseError;
    }
  }

  /**
   * Makes the next saves of a node fail with a database error.
   */
  private function failSavesWith(int $nid, \Throwable $error, int $count): void {
    $this->databaseErrorNid = $nid;
    $this->databaseError = $error;
    $this->databaseErrorsLeft = $count;
    $this->databaseErrorsThrown = 0;
  }

  /**
   * Builds a database error the way the MySQL driver wraps it.
   */
  private function databaseError(string $sqlstate, int $code, string $message): DatabaseExceptionWrapper {
    $pdo = new \PDOException(sprintf('SQLSTATE[%s]: General error: %d %s', $sqlstate, $code, $message));
    $pdo->errorInfo = [$sqlstate, $code, $message];
    return new DatabaseExceptionWrapper($pdo->getMessage(), 0, $pdo);
  }

  /**
   * Returns an archive service that records its lock conflict backoffs.
   */
  private function retryingArchiveService(?\Closure $on_backoff = NULL): ArchiveService {
    return new class(
      $on_backoff,
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->testLogger(),
      $this->container->get('database'),
      $this->container->get('module_handler'),
    ) extends ArchiveService {

      /**
       * Attempts after which the service backed off.
       *
       * @var int[]
       */
      public array $backoffs = [];

      public function __construct(
        private readonly ?\Closure $onBackoff,
        mixed ...$arguments,
      ) {
        parent::__construct(...$arguments);
      }

      /**
       * {@inheritdoc}
       */
      protected function lockConflictBackoff(int $attempt): void {
        $this->backoffs[] = $attempt;
        if ($this->onBackoff !== NULL) {
          ($this->onBackoff)();
        }
      }

    };
  }

  /**
   * The backfill command dry run counts both steps and writes nothing.
   */
  public function testBackfillCommandDryRunCountsWithoutWriting(): void {
    $nids = $this->createArchivedBacklog(3);
    $revisions = array_map(static fn (int $nid): int => (int) Node::load($nid)->getRevisionId(), $nids);

    $rows = $this->backfillRows($this->archiveCommand()->backfill(
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

    $result = $this->archiveCommand()->backfill(
      $this->backfillOptions(['limit' => 2, 'batch-size' => 1])
    );
    // Requests left after a --limit run are no failure.
    self::assertSame(0, $result->getExitCode());
    $rows = $this->backfillRows($result);
    self::assertSame(['unpublish', 'on', 5, 2, 2, 0, 3], array_slice(array_values($rows[0]), 0, 7));
    self::assertSame(['anonymize', 'on', 5, 2, 2, 0, 3], array_slice(array_values($rows[1]), 0, 7));
    self::assertSame('Limit reached: cron or the next run continues.', $rows[1]['note']);

    // Wherever cron left the backfill cursor, the command covers all.
    $state->set(ArchiveService::BACKFILL_CURSOR_STATE, max($nids));
    $rows = $this->backfillRows($this->archiveCommand()->backfill(
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
    $rows = $this->backfillRows($this->archiveCommand()->backfill(
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

    $rows = $this->backfillRows($this->archiveCommand()->backfill(
      $this->backfillOptions(['only' => 'unpublish'])
    ));
    self::assertSame([['unpublish', 'off', 2, 0, 0, 0, 2, 'The "unpublish" setting is off: nothing is unpublished.']], array_map('array_values', $rows));

    $rows = $this->backfillRows($this->archiveCommand()->backfill(
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
   * Returns an archive service that lets staff reopen a request.
   *
   * The request is reopened by an ordinary save right after the catch-up
   * selected its candidates, before it processes them.
   */
  private function racingArchiveService(int $reopen_nid): ArchiveService {
    return new class(
      $reopen_nid,
      (int) $this->openStatusId(),
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->testLogger(),
      $this->container->get('database'),
      $this->container->get('module_handler'),
    ) extends ArchiveService {

      public function __construct(
        private int $reopenNid,
        private readonly int $openStatus,
        mixed ...$arguments,
      ) {
        parent::__construct(...$arguments);
      }

      /**
       * {@inheritdoc}
       */
      protected function backfillCandidates(string $archived_status, array $detectable, int $cursor, ?int $limit): array {
        $candidates = parent::backfillCandidates($archived_status, $detectable, $cursor, $limit);
        $this->reopen();
        return $candidates;
      }

      /**
       * {@inheritdoc}
       */
      protected function publishedArchivedCandidates(string $archived_status, int $cursor, ?int $limit, array $only = []): array {
        $candidates = parent::publishedArchivedCandidates($archived_status, $cursor, $limit, $only);
        $this->reopen();
        return $candidates;
      }

      /**
       * Reopens the request once, as staff would.
       */
      private function reopen(): void {
        if ($this->reopenNid === 0) {
          return;
        }
        $node = Node::load($this->reopenNid);
        $node->set('field_status', $this->openStatus);
        $node->save();
        $this->reopenNid = 0;
      }

    };
  }

  /**
   * Asserts that a failed archive left the request as it was.
   *
   * @param int $nid
   *   The node ID.
   * @param int[] $revisions
   *   Revision IDs before the attempt.
   */
  private function assertRequestUntouched(int $nid, array $revisions): void {
    $node = $this->reloadNode($nid);
    self::assertSame($this->openStatusId(), (string) $node->get('field_status')->target_id);
    self::assertSame('citizen@example.org', $node->get('field_e_mail')->value);
    self::assertTrue($node->isPublished());
    self::assertSame($revisions, $this->nodeRevisionIds($nid));
    foreach ($revisions as $vid) {
      self::assertSame('citizen@example.org', $this->revisionFieldValue($vid, 'field_e_mail'));
    }
  }

  /**
   * Loads a node from storage, bypassing the entity caches.
   */
  private function reloadNode(int $nid): Node {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($nid);
    self::assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * Returns the open status term id from the archive settings.
   */
  private function openStatusId(): string {
    return (string) array_key_first((array) $this->config('markaspot_archive.settings')->get('status_archivable'));
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
   * Builds an archive service mock whose archiving fails.
   */
  private function failingArchiveService(
    \Throwable $exception,
  ): ArchiveServiceInterface {
    $archive_service = $this->createMock(ArchiveServiceInterface::class);
    $archive_service
      ->expects(self::once())
      ->method('archiveRequest')
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
  private function archiveCommand(?ArchiveService $service = NULL, ?LockBackendInterface $lock = NULL): ArchiveCommands {
    return new ArchiveCommands(
      $service ?? $this->archiveService,
      $this->container->get('config.factory'),
      $this->container->get('date.formatter'),
      $this->container->get('entity_type.manager'),
      $this->testLogger(),
      $this->container->get('database'),
      $this->container->get('state'),
      $lock ?? $this->container->get('lock'),
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
   * Returns a new archive service, as the next cron run would build it.
   */
  private function freshArchiveService(): ArchiveService {
    return new ArchiveService(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->testLogger(),
      $this->container->get('database'),
      $this->container->get('module_handler'),
    );
  }

  /**
   * Converts the rows of a backfill command result into arrays.
   *
   * @return array<int, array<string, mixed>>
   *   Row arrays.
   */
  private function backfillRows(CommandResult $result): array {
    return $this->rowsToArray($result->getOutputData());
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
   * Reads the stored revision log of a node revision.
   */
  private function revisionLog(int $revision_id): string {
    return (string) $this->container->get('database')
      ->select('node_revision', 'r')
      ->fields('r', ['revision_log'])
      ->condition('vid', $revision_id)
      ->execute()
      ->fetchField();
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
