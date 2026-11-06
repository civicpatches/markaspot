<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent;
use Drupal\markaspot_service_provider\Plugin\QueueWorker\ServiceProviderNotificationQueueWorker;
use Drupal\markaspot_service_provider\ServiceProviderEvents;
use Drupal\markaspot_service_provider\ServiceProviderNotificationScheduler;
use Drupal\markaspot_service_provider\ServiceProviderService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the selection and interval rules of due provider notifications.
 */
#[Group('markaspot_service_provider')]
#[CoversClass(ServiceProviderNotificationScheduler::class)]
#[CoversClass(ServiceProviderNotificationQueueWorker::class)]
final class ServiceProviderNotificationSchedulerTest extends UnitTestCase {

  /**
   * The current request time used by the scheduler under test.
   */
  private int $now = 1_000_000;

  /**
   * In-memory key-value storage shared by every scheduler of one test.
   */
  private KeyValueMemoryFactory $keyValue;

  /**
   * Dispatched events, in order.
   *
   * @var \Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent[]
   */
  private array $dispatched = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->keyValue = new KeyValueMemoryFactory();
  }

  /**
   * Provides last notification times and the expected due state.
   */
  public static function dueCases(): iterable {
    yield 'never notified' => [NULL, 1000, 3600, TRUE];
    yield 'same request time' => [1000, 1000, 3600, FALSE];
    yield 'one second short' => [1000, 4599, 3600, FALSE];
    yield 'exactly one interval later' => [1000, 4600, 3600, TRUE];
    yield 'long overdue' => [1000, 90000, 3600, TRUE];
    yield 'clock moved back' => [5000, 1000, 3600, FALSE];
  }

  /**
   * A node is due when never notified or once a full interval has passed.
   */
  #[DataProvider('dueCases')]
  public function testIsDue(?int $last, int $now, int $interval, bool $expected): void {
    $this->assertSame($expected, ServiceProviderNotificationScheduler::isDue($last, $now, $interval));
  }

  /**
   * Provides configured interval values and their normalised seconds.
   */
  public static function intervalCases(): iterable {
    yield 'integer' => [900, 900];
    yield 'numeric string from the settings form' => ['86400', 86400];
    yield 'missing' => [NULL, 3600];
    yield 'empty string' => ['', 3600];
    yield 'zero' => [0, 3600];
    yield 'negative' => [-60, 3600];
    yield 'garbage' => ['hourly', 3600];
  }

  /**
   * Empty or invalid intervals fall back to one hour, like before.
   */
  #[DataProvider('intervalCases')]
  public function testNormalizeInterval(mixed $value, int $expected): void {
    $this->assertSame($expected, ServiceProviderNotificationScheduler::normalizeInterval($value));
  }

  /**
   * Selection keeps due, unique, positive IDs in candidate order.
   */
  public function testSelectDue(): void {
    $last = [
      2 => 9_000,
      3 => 5_000,
      4 => 'not a timestamp',
    ];
    $this->assertSame(
      [1, 3, 4, 5],
      ServiceProviderNotificationScheduler::selectDue([1, 2, 3, 4, 3, 0, -1, 5], $last, 10_000, 3600),
    );
    $this->assertSame([], ServiceProviderNotificationScheduler::selectDue([], $last, 10_000, 3600));
  }

  /**
   * Only the configured completion status counts as completed.
   */
  public function testIsCompleted(): void {
    $done = $this->node(7, ['status_tid' => '42']);
    $open = $this->node(8, ['status_tid' => '41']);
    $no_status = $this->node(9, ['status_tid' => NULL]);
    $this->assertTrue(ServiceProviderNotificationScheduler::isCompleted($done, '42'));
    $this->assertFalse(ServiceProviderNotificationScheduler::isCompleted($open, '42'));
    $this->assertFalse(ServiceProviderNotificationScheduler::isCompleted($no_status, '42'));
    $this->assertFalse(ServiceProviderNotificationScheduler::isCompleted($done, ''));
  }

  /**
   * One event per node and interval, with the provider addresses as token.
   */
  public function testNotifyDispatchesOncePerInterval(): void {
    $node = $this->node(7);
    $scheduler = $this->scheduler([
      'nodes' => [7 => $node],
      'emails' => ['a@example.org', 'b@example.org', 'a@example.org'],
    ]);

    $this->assertTrue($scheduler->notify(7));
    $this->assertCount(1, $this->dispatched);
    $this->assertSame($node, $this->dispatched[0]->getEntity());
    $this->assertSame('a@example.org, b@example.org', $this->dispatched[0]->getEmail());
    $this->assertSame($this->now, $this->stored(7));

    // A duplicate queue item in the same window is dropped.
    $this->assertFalse($scheduler->notify(7));
    $this->now += 3599;
    $this->assertFalse($scheduler->notify(7));
    $this->assertCount(1, $this->dispatched);

    $this->now += 1;
    $this->assertTrue($scheduler->notify(7));
    $this->assertCount(2, $this->dispatched);
    $this->assertSame($this->now, $this->stored(7));
  }

  /**
   * Nodes that changed after queueing are skipped without a record.
   */
  public function testNotifySkipsNodesThatNoLongerMatch(): void {
    $nodes = [
      1 => $this->node(1, ['status_tid' => '42']),
      2 => $this->node(2, ['published' => FALSE]),
      3 => $this->node(3, ['provider' => FALSE]),
      4 => $this->node(4, ['bundle' => 'page']),
    ];
    $scheduler = $this->scheduler(['nodes' => $nodes]);
    foreach ([1, 2, 3, 4, 5, 0] as $nid) {
      $this->assertFalse($scheduler->notify($nid), "Node $nid");
      $this->assertNull($this->stored($nid));
    }
    $this->assertSame([], $this->dispatched);
  }

  /**
   * Providers without a configured address get no event and no record.
   */
  public function testNotifySkipsProvidersWithoutAddress(): void {
    $scheduler = $this->scheduler(['nodes' => [7 => $this->node(7)], 'emails' => []]);
    $this->assertFalse($scheduler->notify(7));
    $this->assertSame([], $this->dispatched);
    $this->assertNull($this->stored(7));
  }

  /**
   * With the setting off, nodes are neither loaded nor notified.
   */
  public function testDisabledSettingDoesNothing(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->expects($this->never())->method('get');
    $scheduler = $this->scheduler(['enabled' => FALSE, 'manager' => $manager, 'queue' => $queue_factory]);
    $this->assertFalse($scheduler->notify(7));
    $this->assertSame(0, $scheduler->enqueueDue());
    $this->assertSame([], $this->dispatched);
    $this->assertSame([], $this->keyValue->get(ServiceProviderNotificationScheduler::COLLECTION)->getAll());
  }

  /**
   * A failing reaction is logged and not retried within the interval.
   */
  public function testFailingReactionIsNotRepeatedWithinInterval(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $scheduler = $this->scheduler(['nodes' => [7 => $this->node(7)], 'throw' => TRUE, 'logger' => $logger]);
    $this->assertFalse($scheduler->notify(7));
    $this->assertSame($this->now, $this->stored(7));
    $this->assertFalse($scheduler->notify(7));
  }

  /**
   * Cron queues due candidates only and prunes records of finished nodes.
   */
  public function testEnqueueDueQueuesDueCandidatesAndPrunesRecords(): void {
    $store = $this->keyValue->get(ServiceProviderNotificationScheduler::COLLECTION);
    // 11 was notified in this window, 12 one interval ago, 99 left the set.
    $store->setMultiple(['11' => $this->now - 10, '12' => $this->now - 3600, '99' => $this->now - 10]);

    $or = $this->createMock(ConditionInterface::class);
    $or->expects($this->once())->method('notExists')->with('field_status')->willReturnSelf();
    $or->expects($this->once())->method('condition')->with('field_status', '42', '<>')->willReturnSelf();
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('exists')->with('field_service_provider')->willReturnSelf();
    $query->method('orConditionGroup')->willReturn($or);
    $query->method('execute')->willReturn(['10' => '10', '11' => '11', '12' => '12']);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);

    $queued = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(function ($data) use (&$queued) {
      $queued[] = $data;
      return count($queued);
    });
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->with(ServiceProviderNotificationScheduler::QUEUE)->willReturn($queue);

    $scheduler = $this->scheduler(['manager' => $manager, 'queue' => $queue_factory, 'status_field' => TRUE]);
    $this->assertSame(2, $scheduler->enqueueDue());
    // Each item carries the window of this cron run.
    $this->assertSame([['nid' => 10, 'window' => $this->now], ['nid' => 12, 'window' => $this->now]], $queued);
    $this->assertSame(['11', '12'], array_map('strval', array_keys($store->getAll())));
  }

  /**
   * The queue worker hands numeric node IDs and their window to the scheduler.
   */
  public function testQueueWorkerDelegatesNodeIds(): void {
    $calls = [];
    $scheduler = $this->createMock(ServiceProviderNotificationScheduler::class);
    $scheduler->method('notify')
      ->willReturnCallback(function (int $nid, ?int $window = NULL) use (&$calls): bool {
        $calls[] = [$nid, $window];
        return TRUE;
      });
    $worker = new ServiceProviderNotificationQueueWorker([], ServiceProviderNotificationScheduler::QUEUE, [], $scheduler);
    $items = [
      // Items queued before the window was added.
      ['nid' => 7],
      ['nid' => '8', 'window' => '1000'],
      ['nid' => 9, 'window' => 2000],
      ['nid' => 10, 'window' => 'soon'],
      ['nid' => 11, 'window' => 0],
      ['nid' => 'x', 'window' => 2000],
      ['nid' => 7.5],
      ['nid' => 0],
      'garbage',
      NULL,
      [],
    ];
    foreach ($items as $data) {
      $worker->processItem($data);
    }
    $this->assertSame([[7, NULL], [8, 1000], [9, 2000], [10, NULL], [11, NULL]], $calls);
  }

  /**
   * An item processed after its cron run keeps the node on the hook's cadence.
   *
   * Cron queues at T0 and the worker reaches the item at T0+300, because the
   * queue spilled into the next cron run. The stored window is T0, so the node
   * is due again when the hook runs at T0+interval, not one interval after
   * T0+300.
   */
  public function testNotifyUsesQueuedWindow(): void {
    $scheduler = $this->scheduler(['nodes' => [7 => $this->node(7)]]);
    $t0 = $this->now;
    $this->now = $t0 + 300;
    $this->assertTrue($scheduler->notify(7, $t0));
    $this->assertSame($t0, $this->stored(7));
    $this->assertTrue(ServiceProviderNotificationScheduler::isDue($this->stored(7), $t0 + 3600, 3600));

    // A duplicate item of the same window is dropped.
    $this->assertFalse($scheduler->notify(7, $t0));

    // The hook's next run at T0+interval queues the node and it is notified.
    $this->now = $t0 + 3600;
    $this->assertTrue($scheduler->notify(7, $t0 + 3600));
    $this->assertSame($t0 + 3600, $this->stored(7));
    $this->assertCount(2, $this->dispatched);
  }

  /**
   * An item a full interval old is dropped; the newer window notifies once.
   */
  public function testStaleWindowNotifiesOnceWithCurrentTime(): void {
    $scheduler = $this->scheduler(['nodes' => [7 => $this->node(7)]]);
    $t0 = $this->now;
    // Backlog: the T0 item is reached only after the hook queued T0+3600.
    $this->now = $t0 + 3600 + 30;
    $this->assertTrue($scheduler->notify(7, $t0));
    $this->assertSame($this->now, $this->stored(7));
    // The newer duplicate of the same node is not due any more.
    $this->assertFalse($scheduler->notify(7, $t0 + 3600));
    $this->assertSame($this->now, $this->stored(7));
    $this->assertCount(1, $this->dispatched);
  }

  /**
   * Items without a window use the worker's time; future windows count as now.
   */
  public function testWindowFallbacks(): void {
    $scheduler = $this->scheduler(['nodes' => [7 => $this->node(7), 8 => $this->node(8)]]);
    $this->assertTrue($scheduler->notify(7));
    $this->assertSame($this->now, $this->stored(7));
    $this->assertTrue($scheduler->notify(8, $this->now + 999));
    $this->assertSame($this->now, $this->stored(8));
  }

  /**
   * The service definition matches the constructor of this checkout.
   */
  public function testServiceDefinitionMatchesConstructor(): void {
    $services = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_service_provider.services.yml')['services'];
    $definition = $services['markaspot_service_provider.notification_scheduler'];
    $this->assertSame(ServiceProviderNotificationScheduler::class, $definition['class']);
    $constructor = new \ReflectionMethod(ServiceProviderNotificationScheduler::class, '__construct');
    $this->assertCount($constructor->getNumberOfParameters(), $definition['arguments']);
    $this->assertArrayHasKey('logger.channel.markaspot_service_provider', $services);
  }

  /**
   * Returns the stored notification time of a node.
   */
  private function stored(int $nid): mixed {
    return $this->keyValue->get(ServiceProviderNotificationScheduler::COLLECTION)->get((string) $nid);
  }

  /**
   * Creates a service request mock.
   *
   * @param int $nid
   *   The node ID.
   * @param array<string, mixed> $options
   *   Overrides: bundle, published, provider, status_tid.
   */
  private function node(int $nid, array $options = []): NodeInterface {
    $options += ['bundle' => 'service_request', 'published' => TRUE, 'provider' => TRUE, 'status_tid' => '41'];
    $provider = $this->createMock(FieldItemListInterface::class);
    $provider->method('isEmpty')->willReturn(!$options['provider']);
    $status = $this->createMock(FieldItemListInterface::class);
    $status->method('getValue')->willReturn($options['status_tid'] === NULL ? [] : [['target_id' => $options['status_tid']]]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('bundle')->willReturn($options['bundle']);
    $node->method('isPublished')->willReturn($options['published']);
    $fields = ['field_service_provider', 'field_status'];
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => in_array($name, $fields, TRUE));
    $node->method('get')->willReturnCallback(static fn(string $name) => $name === 'field_status' ? $status : $provider);
    return $node;
  }

  /**
   * Creates a scheduler with test doubles.
   *
   * @param array<string, mixed> $options
   *   Options: enabled, nodes, emails, throw, manager, queue, logger,
   *   status_field.
   */
  private function scheduler(array $options = []): ServiceProviderNotificationScheduler {
    $options += [
      'enabled' => TRUE,
      'nodes' => [],
      'emails' => ['provider@example.org'],
      'throw' => FALSE,
      'status_field' => FALSE,
    ];

    if (!isset($options['manager'])) {
      $nodes = $options['nodes'];
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturnCallback(static fn($nid) => $nodes[(int) $nid] ?? NULL);
      $options['manager'] = $this->createMock(EntityTypeManagerInterface::class);
      $options['manager']->method('getStorage')->with('node')->willReturn($storage);
    }

    $fields = $this->createMock(EntityFieldManagerInterface::class);
    $fields->method('getFieldDefinitions')->willReturn(
      $options['status_field'] ? ['field_status' => $this->createMock(FieldDefinitionInterface::class)] : [],
    );

    $config = $this->getConfigFactoryStub([
      'markaspot_service_provider.settings' => [
        'enable_cron_notifications' => $options['enabled'],
        'cron_interval' => 3600,
        'completion_status_tid' => '42',
      ],
    ]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $name = NULL) use ($options): object {
      $this->assertSame(ServiceProviderEvents::NOTIFICATION_DUE, $name);
      $this->assertInstanceOf(ServiceProviderNotificationDueEvent::class, $event);
      if ($options['throw']) {
        throw new \RuntimeException('Mail transport is down.');
      }
      $this->dispatched[] = $event;
      return $event;
    });

    $provider_service = $this->createMock(ServiceProviderService::class);
    $provider_service->method('getServiceProviderEmails')->willReturn($options['emails']);

    return new ServiceProviderNotificationScheduler(
      $options['manager'],
      $fields,
      $config,
      $options['queue'] ?? $this->createMock(QueueFactory::class),
      $this->keyValue,
      $time,
      $dispatcher,
      $provider_service,
      $options['logger'] ?? $this->createMock(LoggerChannelInterface::class),
    );
  }

}
