<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_publisher\Kernel;

use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/PublisherKernelTest.php';

/**
 * Exercises the moderation/publisher integration with real revision writers.
 *
 * @group markaspot_publisher
 */
#[RunTestsInSeparateProcesses]
final class ModerationPublicationKernelTest extends PublisherKernelTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy',
    'markaspot_publisher', 'markaspot_moderation', 'service_request',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('markaspot_moderation', ['markaspot_moderation_flags']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
  }

  /**
   * Both inherited candidate tests still run with moderation enabled.
   */
  public function testCandidateQueryTag(): void {
    $this->enableModules(['publisher_hold_test']);
    $excluded = $this->report(31);
    $allowed = $this->report(31);
    $this->container->get('state')->set('publisher_hold_test.excluded', $excluded->id());
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $this->assertSame([(int) $allowed->id()], array_map('intval', array_keys($candidates)));
  }

  /**
   * Moderation survives the publication deadline and unrelated later edits.
   */
  public function testHiddenReportRemainsUnpublished(): void {
    $hidden = $this->report(31, ['status' => 1]);
    $this->container->get('markaspot_moderation.service')->hideRequest((int) $hidden->id(), 0);
    $hidden = $this->reload($hidden);
    $events = RevisionLog::decode((string) $hidden->getRevisionLogMessage())['events'];
    $this->assertContains('moderation_hidden', $events);
    $hidden->setTitle('Edited after moderation')->save();
    $this->age($hidden);
    $normal = $this->report(31);

    markaspot_publisher_cron();
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame($normal->id(), $item->data['nid']);
    $this->worker()->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertFalse($this->reload($hidden)->isPublished());
    $this->assertTrue($this->reload($normal)->isPublished());
  }

  /**
   * A report hidden after enqueueing must be refused by the worker too.
   */
  public function testHiddenAfterEnqueueing(): void {
    $report = $this->report(31);
    markaspot_publisher_cron();
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $item = $queue->claimItem();
    $this->assertSame($report->id(), $item->data['nid']);
    $this->container->get('markaspot_moderation.service')->hideRequest((int) $report->id(), 0);
    $this->worker()->processItem($item->data);
    $this->assertFalse($this->reload($report)->isPublished());
    $queue->deleteItem($item);
  }

  /**
   * Explicit republication releases the historical moderation decision.
   */
  public function testExplicitPublicationReleasesHold(): void {
    $report = $this->report(31, ['status' => 1]);
    $this->container->get('markaspot_moderation.service')->hideRequest((int) $report->id(), 0);
    $report = $this->reload($report);
    $report->setPublished()->save();
    $report->setUnpublished()->save();
    $this->age($report);
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $this->assertArrayHasKey($report->id(), $candidates);
    $this->worker()->processItem(['nid' => $report->id()]);
    $this->assertTrue($this->reload($report)->isPublished());
  }

  /**
   * Bypasses save-time timestamp normalization to simulate elapsed time.
   */
  private function age(NodeInterface $node): void {
    $this->container->get('database')->update('node_field_data')
      ->fields(['created' => time() - 40 * 86400, 'changed' => time() - 31 * 86400])
      ->condition('nid', $node->id())->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
  }

  /**
   * Reloads a report without the storage cache.
   */
  private function reload(NodeInterface $node): NodeInterface {
    return $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * Creates the actual queue worker through its plugin factory.
   */
  private function worker(): object {
    return $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
  }

}
