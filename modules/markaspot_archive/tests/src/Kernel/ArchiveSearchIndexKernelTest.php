<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_archive\Drush\Commands\ArchiveCommands;
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
    'search_api_test',
    'markaspot_archive',
  ];

  /**
   * Index ID.
   */
  private const INDEX = 'archive_requests';

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
   * Returns the e-mail addresses the test backend holds.
   *
   * @return string[]
   *   Indexed addresses.
   */
  private function indexedEmails(): array {
    $emails = [];
    foreach ($this->container->get('state')->get('search_api_test.backend.indexed.' . self::INDEX, []) as $fields) {
      array_push($emails, ...array_map('strval', $fields['field_e_mail'] ?? []));
    }
    return $emails;
  }

  /**
   * Returns the backfill command with the container services.
   */
  private function command(): ArchiveCommands {
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
