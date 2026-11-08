<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_publisher\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins candidate selection, cron enqueueing and publication against storage.
 *
 * @group markaspot_publisher
 */
#[RunTestsInSeparateProcesses]
final class PublisherKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy',
    'markaspot_publisher',
  ];

  /**
   * Publisher configuration predates a config schema.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['markaspot_publisher.settings'];

  /**
   * The category under test.
   */
  private Term $category;

  /**
   * The publishable workflow status.
   */
  private Term $status;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
    foreach (['field_category', 'field_status'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_publish_days',
      'entity_type' => 'taxonomy_term',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_publish_days',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'service_category',
    ])->save();
    $this->category = Term::create(['vid' => 'service_category', 'name' => 'Road']);
    $this->category->save();
    $this->status = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $this->status->save();
    $this->config('markaspot_publisher.settings')
      ->set('default_days', 30)
      ->set('manual_unpublish_threshold', 6)
      ->set('status_publishable', [$this->status->id() => $this->status->id()])
      ->set('days', [$this->category->id() => 30])
      ->set('cron_enable', 1)
      ->set('cron_always_run', 1)
      ->save();
  }

  /**
   * Only overdue eligible reports are queued and published.
   */
  public function testDeadlineAndExclusions(): void {
    $overdue = $this->report(31);
    $recent = $this->report(29);
    $published = $this->report(31, ['status' => 1]);
    $excluded = $this->report(31, ['field_status' => NULL]);
    $manual = $this->report(31, ['changed' => time()]);
    $publishedRevision = $published->getRevisionId();

    markaspot_publisher_cron();
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame($overdue->id(), $item->data['nid']);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $worker->processItem($item->data);
    $queue->deleteItem($item);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertTrue($storage->load($overdue->id())->isPublished());
    $this->assertSame($this->status->id(), $storage->load($overdue->id())->get('field_status')->target_id);
    foreach ([$recent, $excluded, $manual] as $untouched) {
      $this->assertFalse($storage->load($untouched->id())->isPublished());
      $this->assertSame($untouched->getChangedTime(), $storage->load($untouched->id())->getChangedTime());
    }
    $this->assertTrue($storage->load($published->id())->isPublished());
    $this->assertSame($publishedRevision, $storage->load($published->id())->getRevisionId());
  }

  /**
   * A category override replaces the global publication delay.
   */
  public function testCategoryDeadlineOverride(): void {
    $this->category->set('field_publish_days', 2)->save();
    $overdue = $this->report(3);
    $this->report(1);
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $this->assertSame([(int) $overdue->id()], array_map('intval', array_keys($candidates)));
  }

  /**
   * Queue items cannot republish reports whose status changed after enqueueing.
   */
  public function testWorkerRechecksStatusAndPublication(): void {
    $report = $this->report(31);
    $published = $this->report(31, ['status' => 1]);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $report->set('field_status', NULL)->save();
    $worker->processItem(['nid' => $report->id()]);
    $worker->processItem(['nid' => $published->id()]);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertFalse($storage->load($report->id())->isPublished());
    $this->assertTrue($storage->load($published->id())->isPublished());
    $this->assertSame($published->getChangedTime(), $storage->load($published->id())->getChangedTime());
  }

  /**
   * Disabled cron and an interval that has not elapsed leave the queue empty.
   */
  public function testCronSwitchAndInterval(): void {
    $this->report(31);
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $this->config('markaspot_publisher.settings')->set('cron_enable', 0)->save();
    markaspot_publisher_cron();
    $this->assertSame(0, $queue->numberOfItems());
    $this->config('markaspot_publisher.settings')
      ->set('cron_enable', 1)->set('cron_always_run', 0)->set('cron_interval', 3600)->save();
    $this->container->get('state')->set('markaspot_publisher.last_run', time());
    markaspot_publisher_cron();
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * Saves a report with explicit creation and modification timestamps.
   */
  private function report(int $ageDays, array $values = []): Node {
    $created = time() - $ageDays * 86400;
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Publisher test report',
      'status' => 0,
      'created' => $created,
      'changed' => $created,
      'field_category' => $this->category->id(),
      'field_status' => $this->status->id(),
    ]);
    $node->save();
    return $node;
  }

}
