<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_publisher\Kernel;

use Drupal\Core\Database\Database;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/PublisherKernelTest.php';

/**
 * Verifies bounded history reads without starving eligible publisher reports.
 *
 * @group markaspot_publisher
 */
#[RunTestsInSeparateProcesses]
final class PublicationHoldCandidatesKernelTest extends PublisherKernelTest {

  /**
   * Only this regression needs moderation; inherited tests keep their setup.
   */
  public function testBoundedHistoryAndRefill(): void {
    $this->enableModules(['markaspot_moderation', 'service_request']);
    $this->installSchema('markaspot_moderation', ['markaspot_moderation_flags']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    $database = $this->container->get('database');
    $heldIds = [];
    for ($i = 0; $i < 21; $i++) {
      $report = $this->report(31);
      $this->container->get('markaspot_moderation.service')->hideRequest((int) $report->id(), 0);
      $heldIds[] = (int) $report->id();
    }
    $database->update('node_field_data')
      ->fields(['changed' => time() - 31 * 86400])
      ->condition('nid', $heldIds, 'IN')->execute();
    $allowedIds = [];
    for ($i = 0; $i < 20; $i++) {
      $allowedIds[] = (int) $this->report(31)->id();
    }
    // These reports must never enter a moderation history read.
    $this->report(1);
    $this->report(31, ['field_status' => NULL]);
    $this->report(31, ['field_category' => NULL]);

    Database::startLog('publisher_hold_candidates');
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $queries = Database::getLog('publisher_hold_candidates');
    $this->assertEqualsCanonicalizing($allowedIds, array_map('intval', array_keys($candidates)));
    $historyReads = 0;
    foreach ($queries as $entry) {
      $sql = str_replace(['`', '"'], '', $entry['query']);
      if (!str_contains($sql, 'r.revision_log')) {
        continue;
      }
      $historyReads++;
      $this->assertMatchesRegularExpression('/n\.nid IN \(([^)]+)\)/', $sql);
      preg_match('/n\.nid IN \(([^)]+)\)/', $sql, $matches);
      $ids = array_map(static fn(string $placeholder): int => (int) $entry['args'][trim($placeholder)], explode(',', $matches[1]));
      $this->assertLessThanOrEqual(20, count($ids));
      $this->assertSame([], array_diff($ids, $heldIds, $allowedIds));
    }
    $this->assertGreaterThan(1, $historyReads);
  }

}
