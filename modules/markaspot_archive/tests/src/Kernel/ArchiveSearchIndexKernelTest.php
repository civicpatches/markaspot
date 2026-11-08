<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_archive\Drush\Commands\ArchiveCommands;
use Drupal\markaspot_archive_search_test\Plugin\search_api\tracker\SilentlyFailingTracker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The archive catch-up command leaves no old contact data in a search index.
 *
 * Search API indexes a changed request at the end of the request or on cron.
 * The command indexes the archived requests waiting for it right away and
 * fails while an index still holds the old data. All addresses are
 * fictional.
 */
#[Group('markaspot_archive')]
#[RunTestsInSeparateProcesses]
final class ArchiveSearchIndexKernelTest extends KernelTestBase {

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
    'taxonomy',
    'search_api',
    'search_api_db',
    'search_api_test',
    'markaspot_archive',
    'markaspot_archive_search_test',
  ];

  /**
   * Index ID.
   */
  private const INDEX = 'archive_requests';

  /**
   * Text that replaces the free-text message of an anonymized report's logs.
   */
  private const PLACEHOLDER = 'Message removed when the report was anonymized.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('search_api', ['search_api_item']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service request', 'new_revision' => TRUE])->save();
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service category'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Service status'])->save();
    $this->installConfig(['system', 'user', 'field', 'node', 'search_api', 'markaspot_archive']);

    foreach (['field_e_mail' => 'email', 'field_status' => 'entity_reference'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $type === 'entity_reference' ? ['target_type' => 'taxonomy_term'] : [],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $name,
      ])->save();
    }
    $archived = Term::create(['vid' => 'service_status', 'name' => 'Archived']);
    $archived->save();
    $this->config('markaspot_archive.settings')
      ->set('status_archivable', [])
      ->set('status_archived', (string) $archived->id())
      ->set('unpublish', 0)
      ->set('anonymize', 0)
      ->set('anonymize_fields', ['field_e_mail' => 'field_e_mail'])
      ->save();

    Server::create(['id' => 'archive_test', 'name' => 'Test server', 'backend' => 'search_api_test'])->save();
    Index::create([
      'id' => self::INDEX,
      'name' => 'Requests',
      'server' => 'archive_test',
      'datasource_settings' => [
        'entity:node' => ['bundles' => ['default' => FALSE, 'selected' => ['service_request']]],
      ],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'field_e_mail' => [
          'label' => 'E-mail',
          'datasource_id' => 'entity:node',
          'property_path' => 'field_e_mail',
          'type' => 'string',
        ],
        'revision_log' => [
          'label' => 'Revision log',
          'datasource_id' => 'entity:node',
          'property_path' => 'revision_log',
          'type' => 'string',
        ],
      ],
      // Cron indexes: the item waits, as after a failed post-request index.
      'options' => ['index_directly' => FALSE, 'cron_limit' => 50],
    ])->save();
  }

  /**
   * The command indexes waiting archived requests and fails until it can.
   */
  public function testCommandIndexesAnonymizedRequests(): void {
    $archived = (string) $this->config('markaspot_archive.settings')->get('status_archived');
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'field_status' => $archived,
      'field_e_mail' => 'plain@example.org',
    ]);
    $node->save();
    $index = Index::load(self::INDEX);
    $index->indexItems();
    $this->assertSame(['plain@example.org'], $this->indexedEmails());
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $state = $this->container->get('state');

    // The backend fails: the old address stays in the index.
    $state->set('search_api_test.backend.exception.indexItems', TRUE);
    $result = $this->command()->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame(['anonymize', 'on', 1, 1, 1, 0, 0], array_slice(array_values($rows['anonymize']), 0, 7));
    $this->assertSame(['search index', 'on', 1, 1, 0, 1, 1], array_slice(array_values($rows['search index']), 0, 7));
    $this->assertSame(['plain@example.org'], $this->indexedEmails());
    $this->assertSame(1, $this->container->get('markaspot_archive.archive')->countSearchIndexBacklog());

    // The dry run reports the waiting item and changes nothing.
    $dry = $this->command()->backfill($this->options(['dry-run' => TRUE]));
    $this->assertSame(0, $dry->getExitCode());
    $this->assertSame(1, $this->rows($dry)['search index']['pending']);

    // Once the backend works, the next run indexes the anonymized address.
    $state->set('search_api_test.backend.exception.indexItems', FALSE);
    $result = $this->command()->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(0, $result->getExitCode());
    $this->assertSame(['search index', 'on', 1, 1, 1, 0, 0, ''], array_values($rows['search index']));
    $emails = $this->indexedEmails();
    $this->assertCount(1, $emails);
    $this->assertStringEndsWith('@anonymized.off', $emails[0]);
    $this->assertSame((string) Node::load($node->id())->get('field_e_mail')->value, $emails[0]);
    $this->assertSame(0, $this->container->get('markaspot_archive.archive')->countSearchIndexBacklog());
  }

  /**
   * An erasure without a save tracks the indexed request for reindexing.
   *
   * The contact fields were anonymized earlier; only the revision log still
   * holds personal data. The backfill replaces it directly, without saving
   * the request, so Search API would not notice: the indexed text must be
   * tracked for update all the same, and indexing replaces it.
   */
  public function testDirectErasureTracksTheIndexedRequest(): void {
    $node = $this->createArchivedRequestWithNote();
    $index = Index::load(self::INDEX);
    $index->indexItems();
    $service = $this->container->get('markaspot_archive.archive');
    $this->assertSame(['Call Jordan at +44 7700 900123.'], $this->indexedValues('revision_log'));
    $this->assertSame(0, $service->countSearchIndexBacklog(), 'Nothing waits for indexing.');
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();

    $this->assertSame(1, $service->backfillArchived(10, 20)['changed']);

    $item_id = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    $this->assertSame([$item_id], array_values($index->getTrackerInstance()->getRemainingItems(-1)));
    $this->assertSame(1, $service->countSearchIndexBacklog());
    $this->assertSame(['Call Jordan at +44 7700 900123.'], $this->indexedValues('revision_log'), 'Indexed only on the next indexing.');

    $this->assertSame(['processed' => 1, 'changed' => 1, 'failed' => 0], $service->indexSearchIndexBacklog());
    $this->assertSame([self::PLACEHOLDER], $this->indexedValues('revision_log'));
    $this->assertSame(0, $service->countSearchIndexBacklog());
  }

  /**
   * An index the catch-up cannot update fails the command and is named.
   *
   * A read-only index keeps whatever it holds: the command cannot replace
   * the old text there, so it must not report success.
   */
  public function testReadOnlyIndexFailsTheCommand(): void {
    $this->createArchivedRequestWithNote();
    $index = Index::load(self::INDEX);
    $index->indexItems();
    $index->set('read_only', TRUE)->save();
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();

    $dry = $this->command()->backfill($this->options(['dry-run' => TRUE]));
    $this->assertSame(0, $dry->getExitCode());
    $this->assertStringContainsString('Requests (archive_requests)', $this->rows($dry)['search index']['note']);

    $result = $this->command()->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame(['anonymize', 'on', 1, 1, 1, 0, 0], array_slice(array_values($rows['anonymize']), 0, 7));
    $this->assertStringContainsString('Requests (archive_requests)', $rows['search index']['note']);
    $this->assertSame(['Call Jordan at +44 7700 900123.'], $this->indexedValues('revision_log'));
  }

  /**
   * Indexing stops, and the command fails, once the lock cannot be renewed.
   */
  public function testIndexingStopsWhenTheLockIsLost(): void {
    $index = Index::load(self::INDEX);
    $index->setOption('cron_limit', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');
    $this->createArchivedRequestWithNote();
    $this->createArchivedRequestWithNote();
    $this->assertSame(2, $service->countSearchIndexBacklog());

    $this->assertSame(['processed' => 0, 'changed' => 0, 'failed' => 0], $service->indexSearchIndexBacklog(static fn (): bool => FALSE));
    $this->assertSame(2, $service->countSearchIndexBacklog());
    $chunks = 0;
    $this->assertSame(['processed' => 1, 'changed' => 1, 'failed' => 0], $service->indexSearchIndexBacklog(static function () use (&$chunks): bool {
      return ++$chunks <= 1;
    }));
    $this->assertSame(1, $service->countSearchIndexBacklog());

    // The command: the first acquisition and the six renewals of the
    // anonymize step succeed (before the first batch, after each of the two
    // requests, after that batch and after the empty batch that ends the
    // step); the renewal before the first indexing chunk fails.
    $calls = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static function () use (&$calls): bool {
      return ++$calls <= 6;
    });
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $result = $this->command($lock)->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame(['anonymize', 'on', 2, 2, 2, 0, 0], array_slice(array_values($rows['anonymize']), 0, 7));
    $this->assertSame(['search index', 'on', 2, 0, 0, 0, 2], array_slice(array_values($rows['search index']), 0, 7));
    $this->assertStringContainsString('lock', $rows['search index']['note']);
  }

  /**
   * A tracker update that fails silently rolls the erasure back.
   *
   * Search API's tracker catches its own write errors and only logs them.
   * Without a check, the erasure would commit while the indexed document
   * keeps the old text and nothing waits for indexing: the request would no
   * longer be a candidate and the old text would stay in the index.
   */
  public function testSilentTrackerFailureRollsBackTheErasure(): void {
    Index::load(self::INDEX)
      ->set('tracker_settings', ['markaspot_archive_silently_failing' => []])
      ->save();
    $noted = $this->createArchivedRequestWithNote();
    $plain = Node::create([
      'type' => 'service_request',
      'title' => 'Pothole',
      'field_status' => (string) $this->config('markaspot_archive.settings')->get('status_archived'),
      'field_e_mail' => 'plain@example.org',
    ]);
    $plain->save();
    $index = Index::load(self::INDEX);
    $index->indexItems();
    $this->assertSame(['Call Jordan at +44 7700 900123.'], $this->indexedValues('revision_log'));
    $this->assertContains('plain@example.org', $this->indexedEmails());
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');

    $state->set(SilentlyFailingTracker::FAIL_STATE, TRUE);
    $result = $this->command()->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame(['anonymize', 'on', 2, 2, 0, 2, 2], array_slice(array_values($rows['anonymize']), 0, 7));
    // Both erasures were rolled back: the requests stay candidates.
    $this->assertSame('Call Jordan at +44 7700 900123.', $this->currentRevisionLog($noted));
    $this->assertSame('plain@example.org', Node::load($plain->id())->get('field_e_mail')->value);
    $this->assertSame(2, $service->countBackfillCandidates());
    $this->assertSame(['Call Jordan at +44 7700 900123.'], $this->indexedValues('revision_log'));
    $this->assertContains('plain@example.org', $this->indexedEmails());

    // With a working tracker, the next run erases and reindexes both.
    $state->set(SilentlyFailingTracker::FAIL_STATE, FALSE);
    $result = $this->command()->backfill($this->options());
    $rows = $this->rows($result);
    $this->assertSame(0, $result->getExitCode());
    $this->assertSame(['anonymize', 'on', 2, 2, 2, 0, 0], array_slice(array_values($rows['anonymize']), 0, 7));
    $this->assertSame(['search index', 'on', 2, 2, 2, 0, 0], array_slice(array_values($rows['search index']), 0, 7));
    $this->assertSame(self::PLACEHOLDER, $this->currentRevisionLog($noted));
    $this->assertNotContains('Call Jordan at +44 7700 900123.', $this->indexedValues('revision_log'));
    $this->assertNotContains('plain@example.org', $this->indexedEmails());
    $this->assertSame(0, $service->countBackfillCandidates());
  }

  /**
   * A disabled index counts until its server holds nothing of it.
   *
   * Disabling an index removes its data from the server only while the
   * server is enabled, and a failed removal waits as a Search API task.
   */
  public function testDisabledIndexWithPendingRemovalFailsTheCommand(): void {
    $this->createArchivedRequestWithNote();
    $index = Index::load(self::INDEX);
    $index->indexItems();
    $this->config('markaspot_archive.settings')->set('anonymize', 1)->save();
    $service = $this->container->get('markaspot_archive.archive');
    $state = $this->container->get('state');
    $tasks = $this->container->get('search_api.task_manager');

    // The server fails to remove the index: the removal waits as a task.
    $state->set('search_api_test.backend.exception.removeIndex', TRUE);
    $index->disable()->save();
    $this->assertSame(1, $tasks->getTasksCount(['type' => 'removeIndex', 'index_id' => self::INDEX]));
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());

    // Detached from its server, the pending removal still counts.
    Index::load(self::INDEX)->setServer(NULL)->save();
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());
    $result = $this->command()->backfill($this->options());
    $this->assertSame(1, $result->getExitCode());
    $this->assertStringContainsString('Requests (archive_requests)', $this->rows($result)['search index']['note']);

    // The server has removed it now, but nothing records that for an index
    // without a server: it still counts.
    $state->set('search_api_test.backend.exception.removeIndex', FALSE);
    $this->container->get('search_api.server_task_manager')->execute();
    $this->assertSame(0, $tasks->getTasksCount(['index_id' => self::INDEX]));
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());
    $this->assertSame(1, $this->command()->backfill($this->options())->getExitCode());

    // Deleting the unused index clears the report.
    Index::load(self::INDEX)->delete();
    $this->assertSame([], $service->unprocessableSearchIndexes());
    $this->assertSame(0, $this->command()->backfill($this->options())->getExitCode());
  }

  /**
   * A disabled index detached from a database server still counts.
   *
   * Once the index is detached, nothing tells which backend held it or
   * whether that backend removed it, even when it was a database server
   * that dropped its tables.
   */
  public function testDetachedDisabledIndexCounts(): void {
    Server::create([
      'id' => 'archive_db',
      'name' => 'Database server',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default', 'min_chars' => 1],
    ])->save();
    Index::load(self::INDEX)->setServer(Server::load('archive_db'))->save();
    $this->createArchivedRequestWithNote();
    Index::load(self::INDEX)->indexItems();
    $service = $this->container->get('markaspot_archive.archive');
    Index::load(self::INDEX)->disable()->save();
    $this->assertFalse($this->container->get('keyvalue')->get('search_api_db.indexes')->has(self::INDEX));
    $this->assertSame([], $service->unprocessableSearchIndexes(), 'The database server dropped the tables.');

    Index::load(self::INDEX)->setServer(NULL)->save();
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());
  }

  /**
   * A disabled index on a database server counts while its tables remain.
   *
   * The database backend drops the tables of an index and forgets it when
   * the index is disabled. Disabled while the server was off, the index is
   * never removed: its tables keep the old text.
   */
  public function testDisabledIndexOnDatabaseServerCountsWhileItsTablesRemain(): void {
    Server::create([
      'id' => 'archive_db',
      'name' => 'Database server',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default', 'min_chars' => 1],
    ])->save();
    $index = Index::load(self::INDEX);
    $index->setServer(Server::load('archive_db'))->save();
    $this->createArchivedRequestWithNote();
    Index::load(self::INDEX)->indexItems();
    $service = $this->container->get('markaspot_archive.archive');
    $db_indexes = $this->container->get('keyvalue')->get('search_api_db.indexes');
    $this->assertTrue($db_indexes->has(self::INDEX));

    // Disabled on an enabled server: the backend drops the index.
    Index::load(self::INDEX)->disable()->save();
    $this->assertFalse($db_indexes->has(self::INDEX));
    $this->assertSame([], $service->unprocessableSearchIndexes());

    // Enabled and indexed again, then disabled while the server is off.
    Index::load(self::INDEX)->enable()->save();
    Index::load(self::INDEX)->indexItems();
    $this->assertTrue($db_indexes->has(self::INDEX));
    $server = Server::load('archive_db');
    $server->setSyncing(TRUE);
    $server->disable()->save();
    Index::load(self::INDEX)->disable()->save();
    $this->assertTrue($db_indexes->has(self::INDEX), 'The backend kept the tables.');
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());

    // Detaching the server does not remove the tables either.
    Index::load(self::INDEX)->setServer(NULL)->save();
    $this->assertSame([self::INDEX => 'Requests'], $service->unprocessableSearchIndexes());
  }

  /**
   * Losing the lock while the final chunk is indexed fails the command.
   */
  public function testLockLostInTheFinalIndexingChunkFailsTheCommand(): void {
    $service = $this->container->get('markaspot_archive.archive');
    $this->createArchivedRequestWithNote();
    $this->assertSame(1, $service->countSearchIndexBacklog());
    // The lease runs out while the only chunk is indexed: every renewal from
    // then on fails.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static fn (): bool => $service->countSearchIndexBacklog() > 0);

    $result = $this->command($lock)->backfill($this->options());
    $rows = $this->rows($result);

    $this->assertSame(0, $service->countSearchIndexBacklog(), 'The chunk was indexed.');
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame(['search index', 'on', 1, 1, 1, 0, 0], array_slice(array_values($rows['search index']), 0, 7));
    $this->assertStringContainsString('lock', $rows['search index']['note']);
  }

  /**
   * Creates an archived request with anonymized contact data and a note.
   */
  private function createArchivedRequestWithNote(): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'field_status' => (string) $this->config('markaspot_archive.settings')->get('status_archived'),
      'field_e_mail' => 'abc@anonymized.off',
    ]);
    $node->setRevisionLogMessage('Call Jordan at +44 7700 900123.');
    $node->save();
    return $node;
  }

  /**
   * Reads the stored revision log of the current revision of a request.
   */
  private function currentRevisionLog(Node $node): string {
    return (string) $this->container->get('database')
      ->select('node_revision', 'r')
      ->fields('r', ['revision_log'])
      ->condition('vid', Node::load($node->id())->getRevisionId())
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the e-mail addresses the test backend holds.
   *
   * @return string[]
   *   Indexed addresses.
   */
  private function indexedEmails(): array {
    return $this->indexedValues('field_e_mail');
  }

  /**
   * Returns the values of one field the test backend holds.
   *
   * @return string[]
   *   Indexed values of all items.
   */
  private function indexedValues(string $field): array {
    $values = [];
    foreach ($this->container->get('state')->get('search_api_test.backend.indexed.' . self::INDEX, []) as $fields) {
      array_push($values, ...array_map('strval', $fields[$field] ?? []));
    }
    return $values;
  }

  /**
   * Returns the backfill command with the container services.
   */
  private function command(?LockBackendInterface $lock = NULL): ArchiveCommands {
    if ($lock !== NULL) {
      $this->container->set('lock', $lock);
    }
    return ArchiveCommands::create($this->container);
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
  private function options(array $overrides = []): array {
    return $overrides + [
      'dry-run' => FALSE,
      'only' => 'anonymize',
      'limit' => 0,
      'batch-size' => ArchiveCommands::BACKFILL_BATCH_SIZE,
    ];
  }

  /**
   * Returns the result rows keyed by step.
   *
   * @return array<string, array<string, mixed>>
   *   Rows keyed by step.
   */
  private function rows(object $result): array {
    $rows = [];
    foreach ($result->getOutputData() as $row) {
      $rows[$row['step']] = $row;
    }
    return $rows;
  }

}
