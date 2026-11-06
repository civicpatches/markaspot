<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Kernel;

use Drupal\Core\Queue\QueueInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent;
use Drupal\markaspot_service_provider\Plugin\QueueWorker\ServiceProviderNotificationQueueWorker;
use Drupal\markaspot_service_provider\ServiceProviderEvents;
use Drupal\markaspot_service_provider\ServiceProviderNotificationScheduler;
use Drupal\markaspot_service_provider\ServiceProviderService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the cron queue and event of due service provider notifications.
 */
#[Group('markaspot_service_provider')]
#[RunTestsInSeparateProcesses]
final class ServiceProviderNotificationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy',
    'markaspot_service_provider',
  ];

  /**
   * The request time the time service reports.
   */
  private int $now = 1_800_000_000;

  /**
   * Dispatched notifications as [nid, sp_email].
   *
   * @var array<int, array{0: int, 1: string}>
   */
  private array $dispatched = [];

  /**
   * A provider with one address.
   */
  private Term $provider;

  /**
   * A provider without any address.
   */
  private Term $silentProvider;

  /**
   * The open status term.
   */
  private Term $open;

  /**
   * The completion status term.
   */
  private Term $done;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'markaspot_service_provider']);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getRequestMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $time->method('getCurrentMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $this->container->set('datetime.time', $time);

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    // The module's optional configuration may already ship the vocabularies.
    foreach (['service_provider' => 'Providers', 'service_status' => 'Status'] as $vid => $name) {
      if (!Vocabulary::load($vid)) {
        Vocabulary::create(['vid' => $vid, 'name' => $name])->save();
      }
    }
    foreach ([
      ['taxonomy_term', 'service_provider', 'field_sp_email', 'email', [], -1],
      ['node', 'service_request', 'field_service_provider', 'entity_reference', ['target_type' => 'taxonomy_term'], 1],
      ['node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term'], 1],
    ] as [$entity_type, $bundle, $name, $type, $settings, $cardinality]) {
      // Optional module configuration may already provide these fields.
      if (!FieldStorageConfig::loadByName($entity_type, $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => $entity_type,
          'type' => $type,
          'settings' => $settings,
          'cardinality' => $cardinality,
        ])->save();
      }
      if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'label' => $name,
        ])->save();
      }
    }

    $this->provider = Term::create([
      'vid' => 'service_provider',
      'name' => 'Provider',
      'field_sp_email' => 'provider@example.org',
    ]);
    $this->provider->save();
    $this->silentProvider = Term::create(['vid' => 'service_provider', 'name' => 'No address']);
    $this->silentProvider->save();
    $this->open = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $this->open->save();
    $this->done = Term::create(['vid' => 'service_status', 'name' => 'Done']);
    $this->done->save();

    $this->container->get('event_dispatcher')->addListener(
      ServiceProviderEvents::NOTIFICATION_DUE,
      function (ServiceProviderNotificationDueEvent $event): void {
        $this->dispatched[] = [(int) $event->getNode()->id(), $event->getEmail()];
      },
    );
  }

  /**
   * With the shipped default (off) cron queues and dispatches nothing.
   */
  public function testDisabledByDefaultQueuesNothing(): void {
    $this->assertFalse($this->config('markaspot_service_provider.settings')->get('enable_cron_notifications'));
    $report = $this->report();

    $this->container->get('module_handler')->invoke('markaspot_service_provider', 'cron');
    $this->assertSame(0, $this->queue()->numberOfItems());
    $this->assertNull($this->container->get('state')->get('markaspot_service_provider.next_execution'));

    $scheduler = $this->scheduler();
    $this->assertSame(0, $scheduler->enqueueDue());
    $this->assertFalse($scheduler->notify((int) $report->id()));
    $this->assertSame(0, $this->queue()->numberOfItems());
    $this->assertSame([], $this->dispatched);
    $this->assertSame([], $this->container->get('keyvalue')->get(ServiceProviderNotificationScheduler::COLLECTION)->getAll());
  }

  /**
   * Each matching node is dispatched once per interval; others never.
   */
  public function testOneDispatchPerNodePerInterval(): void {
    $this->enable();
    $open = $this->report(['field_status' => $this->open->id()]);
    $no_status = $this->report();
    $completed = $this->report(['field_status' => $this->done->id()]);
    $this->report(['status' => 0]);
    $this->report(['field_service_provider' => NULL]);
    $silent = $this->report(['field_service_provider' => $this->silentProvider->id()]);
    $scheduler = $this->scheduler();
    $email = 'provider@example.org';

    $this->assertSame(3, $scheduler->enqueueDue());
    $this->assertSame([(int) $open->id(), (int) $no_status->id(), (int) $silent->id()], $this->queuedIds());
    $this->processQueue($scheduler);
    $this->assertSame([[(int) $open->id(), $email], [(int) $no_status->id(), $email]], $this->dispatched);

    // Same window: notified nodes are not queued again and a stray duplicate
    // is dropped. The node without a reachable address keeps no record, so it
    // is queued again and dropped again.
    $this->assertSame(1, $scheduler->enqueueDue());
    $this->assertSame([(int) $silent->id()], $this->queuedIds());
    $this->queue()->createItem(['nid' => (int) $open->id()]);
    $this->processQueue($scheduler);
    $this->now += 3599;
    $this->assertSame(1, $scheduler->enqueueDue());
    $this->processQueue($scheduler);
    $this->assertCount(2, $this->dispatched);

    // One full interval later both are due again; the completed one is not.
    $this->now += 1;
    $this->assertSame(3, $scheduler->enqueueDue());
    $this->processQueue($scheduler);
    $this->assertCount(4, $this->dispatched);
    $this->assertNotContains((int) $completed->id(), array_column($this->dispatched, 0));
    $this->assertSame(
      [(string) $open->id() => $this->now, (string) $no_status->id() => $this->now],
      $this->container->get('keyvalue')->get(ServiceProviderNotificationScheduler::COLLECTION)->getAll(),
    );
  }

  /**
   * A node completed while queued is skipped, and its record is pruned.
   */
  public function testCompletionAfterQueueingSkipsAndPrunes(): void {
    $this->enable();
    $report = $this->report(['field_status' => $this->open->id()]);
    $scheduler = $this->scheduler();
    $store = $this->container->get('keyvalue')->get(ServiceProviderNotificationScheduler::COLLECTION);

    $this->assertSame(1, $scheduler->enqueueDue());
    $this->processQueue($scheduler);
    $this->assertCount(1, $this->dispatched);
    $this->assertSame($this->now, $store->get((string) $report->id()));

    $this->now += 3600;
    $this->assertSame(1, $scheduler->enqueueDue());
    $report->set('field_status', $this->done->id())->save();
    $this->processQueue($scheduler);
    $this->assertCount(1, $this->dispatched);

    // The next cron run no longer selects it and drops its record.
    $this->assertSame(0, $scheduler->enqueueDue());
    $this->assertNull($store->get((string) $report->id()));
  }

  /**
   * The cron hook queues once per interval; the worker plugin dispatches.
   *
   * Runs the module's hook, service and plugin definitions, so it covers the
   * wiring that the other tests construct by hand.
   */
  public function testCronHookQueuesOncePerInterval(): void {
    $this->enable();
    $report = $this->report();

    $this->container->get('module_handler')->invoke('markaspot_service_provider', 'cron');
    $this->assertSame(1, $this->queue()->numberOfItems());
    $this->assertSame($this->now + 3600, $this->container->get('state')->get('markaspot_service_provider.next_execution'));

    // Within the interval the hook does not query again.
    $this->now += 60;
    $this->container->get('module_handler')->invoke('markaspot_service_provider', 'cron');
    $this->assertSame(1, $this->queue()->numberOfItems());

    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(ServiceProviderNotificationScheduler::QUEUE);
    $this->assertInstanceOf(ServiceProviderNotificationQueueWorker::class, $worker);
    while ($item = $this->queue()->claimItem()) {
      $worker->processItem($item->data);
      $this->queue()->deleteItem($item);
    }
    $this->assertSame([[(int) $report->id(), 'provider@example.org']], $this->dispatched);
  }

  /**
   * Turns cron notifications on with a one-hour interval.
   */
  private function enable(): void {
    $this->config('markaspot_service_provider.settings')
      ->set('enable_cron_notifications', TRUE)
      ->set('cron_interval', 3600)
      ->set('completion_status_tid', (string) $this->done->id())
      ->save();
  }

  /**
   * Creates a service request with the default provider.
   *
   * @param array<string, mixed> $values
   *   Field values that override the defaults.
   */
  private function report(array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Example report',
      'uid' => 0,
      'status' => 1,
      'field_service_provider' => $this->provider->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds the scheduler from the container's services.
   *
   * Constructed here rather than taken from the container, so the test
   * exercises this checkout's class also when the module's service
   * definitions are read from another checkout.
   */
  private function scheduler(): ServiceProviderNotificationScheduler {
    return new ServiceProviderNotificationScheduler(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('config.factory'),
      $this->container->get('queue'),
      $this->container->get('keyvalue'),
      $this->container->get('datetime.time'),
      $this->container->get('event_dispatcher'),
      new ServiceProviderService(
        $this->container->get('config.factory'),
        $this->container->get('entity_type.manager'),
        $this->container->get('logger.factory'),
      ),
      $this->container->get('logger.factory')->get('markaspot_service_provider'),
    );
  }

  /**
   * Returns the notification queue.
   */
  private function queue(): QueueInterface {
    return $this->container->get('queue')->get(ServiceProviderNotificationScheduler::QUEUE);
  }

  /**
   * Returns the queued node IDs without removing them.
   *
   * @return int[]
   *   The node IDs in queue order.
   */
  private function queuedIds(): array {
    $rows = $this->container->get('database')->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', ServiceProviderNotificationScheduler::QUEUE)
      ->orderBy('item_id')
      ->execute()
      ->fetchCol();
    return array_map(
      static fn(string $data): int => (int) unserialize($data, ['allowed_classes' => FALSE])['nid'],
      $rows,
    );
  }

  /**
   * Processes every queued item with the worker, as cron would.
   */
  private function processQueue(ServiceProviderNotificationScheduler $scheduler): void {
    $worker = new ServiceProviderNotificationQueueWorker([], ServiceProviderNotificationScheduler::QUEUE, [], $scheduler);
    while ($item = $this->queue()->claimItem()) {
      $worker->processItem($item->data);
      $this->queue()->deleteItem($item);
    }
  }

}
