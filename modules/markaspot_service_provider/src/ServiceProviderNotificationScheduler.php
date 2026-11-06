<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\eca\Event\EntityEventInterface;
use Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Selects service requests whose provider notification is due.
 *
 * Cron queues the matching node IDs together with the request time of the
 * cron run (the "window"); the queue worker dispatches one
 * ServiceProviderEvents::NOTIFICATION_DUE event per node. The window of the
 * last notification is kept per node in key-value storage, so a node is
 * notified at most once per configured interval, even when the queue lags
 * behind or holds the same node twice. Because the window, not the time the
 * worker reaches the item, is compared and stored, an item processed by a
 * later cron run keeps the node in step with the cron hook's interval.
 * Nothing happens while the setting "enable_cron_notifications" is off.
 */
class ServiceProviderNotificationScheduler {

  /**
   * The queue that holds due notifications, one item per node.
   */
  public const QUEUE = 'markaspot_service_provider_notification';

  /**
   * Key-value collection: node ID => window of the last notification.
   *
   * The window is the request time of the cron run that queued the node, or
   * the worker's request time for items queued without one.
   */
  public const COLLECTION = 'markaspot_service_provider.notified';

  /**
   * Interval used when the configured value is missing or invalid.
   */
  public const DEFAULT_INTERVAL = 3600;

  /**
   * Constructs the scheduler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\markaspot_service_provider\ServiceProviderService $serviceProvider
   *   The service provider helper that resolves the provider's configured
   *   addresses (organisation scope applied).
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly TimeInterface $time,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ServiceProviderService $serviceProvider,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalises the configured interval to a positive number of seconds.
   *
   * @param mixed $value
   *   The configured "cron_interval" value.
   *
   * @return int
   *   The interval in seconds, DEFAULT_INTERVAL for empty or invalid values.
   */
  public static function normalizeInterval(mixed $value): int {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      $seconds = (int) $value;
      if ($seconds > 0) {
        return $seconds;
      }
    }
    return self::DEFAULT_INTERVAL;
  }

  /**
   * Whether a node is due for another notification.
   *
   * @param int|null $lastNotified
   *   The request time of the last notification, NULL if never notified.
   * @param int $now
   *   The current request time.
   * @param int $interval
   *   The notification interval in seconds.
   *
   * @return bool
   *   TRUE when the node was never notified or the interval has passed.
   */
  public static function isDue(?int $lastNotified, int $now, int $interval): bool {
    return $lastNotified === NULL || $now - $lastNotified >= $interval;
  }

  /**
   * Filters candidate node IDs down to those that are due.
   *
   * @param int[] $nids
   *   Candidate node IDs.
   * @param array<int|string, mixed> $lastNotified
   *   Stored notification times keyed by node ID. Values that are not
   *   integers count as "never notified".
   * @param int $now
   *   The current request time.
   * @param int $interval
   *   The notification interval in seconds.
   *
   * @return int[]
   *   The due node IDs, unique, in candidate order.
   */
  public static function selectDue(array $nids, array $lastNotified, int $now, int $interval): array {
    $due = [];
    foreach ($nids as $nid) {
      $nid = (int) $nid;
      if ($nid <= 0 || isset($due[$nid])) {
        continue;
      }
      $last = $lastNotified[$nid] ?? NULL;
      if (self::isDue(is_int($last) ? $last : NULL, $now, $interval)) {
        $due[$nid] = $nid;
      }
    }
    return array_values($due);
  }

  /**
   * Whether a service request is at the configured completion status.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request.
   * @param string $completionStatusTid
   *   The configured completion status term ID, '' when none is configured.
   *
   * @return bool
   *   TRUE when a completion status is configured and the node has it.
   */
  public static function isCompleted(NodeInterface $node, string $completionStatusTid): bool {
    if ($completionStatusTid === '' || !$node->hasField('field_status')) {
      return FALSE;
    }
    foreach ($node->get('field_status')->getValue() as $item) {
      if ((string) ($item['target_id'] ?? '') === $completionStatusTid) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the setting "enable_cron_notifications" is on.
   */
  public function isEnabled(): bool {
    return (bool) $this->settings()->get('enable_cron_notifications');
  }

  /**
   * Returns the configured notification interval in seconds.
   */
  public function interval(): int {
    return self::normalizeInterval($this->settings()->get('cron_interval'));
  }

  /**
   * Queues every service request whose provider notification is due.
   *
   * Also drops the stored notification times of nodes that no longer match,
   * so the key-value collection only holds current requests.
   *
   * @return int
   *   The number of queued nodes.
   */
  public function enqueueDue(): int {
    if (!$this->isEnabled()) {
      return 0;
    }
    if (!$this->eventsAvailable()) {
      $this->logger->warning('Provider notifications need the ECA module; no service request was queued.');
      return 0;
    }

    $candidates = $this->candidateIds();
    $store = $this->store();
    $known = $store->getAll();
    $candidate_keys = array_map('strval', $candidates);
    $stale = array_values(array_diff(array_map('strval', array_keys($known)), $candidate_keys));
    if ($stale !== []) {
      $store->deleteMultiple($stale);
    }

    $now = $this->time->getRequestTime();
    $due = self::selectDue($candidates, $known, $now, $this->interval());
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($due as $nid) {
      $queue->createItem(['nid' => $nid, 'window' => $now]);
    }
    return count($due);
  }

  /**
   * Dispatches the notification event for one node if it is still due.
   *
   * Every condition is checked again, because the node may have changed
   * since it was queued. The window is recorded before the event is
   * dispatched: a failing reaction is logged and not repeated within the
   * interval, so a broken ECA model cannot send a mail on every cron run.
   *
   * An item whose window is a full interval old or older is dropped: by then
   * the cron hook has run again and queued the node for the newer window, so
   * processing both would notify twice in a row.
   *
   * @param int $nid
   *   The node ID.
   * @param int|null $window
   *   The request time of the cron run that queued the node. NULL for items
   *   queued without one (for example added by hand): the worker's request
   *   time is used instead. A window in the future counts as now.
   *
   * @return bool
   *   TRUE when the event was dispatched without an error.
   */
  public function notify(int $nid, ?int $window = NULL): bool {
    if ($nid <= 0 || !$this->isEnabled() || !$this->eventsAvailable()) {
      return FALSE;
    }
    $now = $this->time->getRequestTime();
    $interval = $this->interval();
    $window = $window === NULL ? $now : min($window, $now);
    if ($now - $window >= $interval) {
      // A backlog item reached a full interval late. Notify it now instead of
      // dropping it: a newer duplicate for the same node then finds it
      // already notified, and a long queue tail is never starved.
      $window = $now;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== 'service_request'
      || !$node->isPublished()
      || !$node->hasField('field_service_provider')
      || $node->get('field_service_provider')->isEmpty()
      || self::isCompleted($node, $this->completionStatusTid())) {
      return FALSE;
    }

    $store = $this->store();
    $key = (string) $nid;
    $last = $store->get($key);
    if (!self::isDue(is_int($last) ? $last : NULL, $window, $interval)) {
      return FALSE;
    }

    // Resolves only addresses of a provider that matches the request's
    // organisation scope, the same rule the response endpoints apply.
    $emails = array_values(array_unique($this->serviceProvider->getServiceProviderEmails($node)));
    if ($emails === []) {
      $this->logger->notice('Service request @nid has no reachable provider address; no notification was dispatched.', ['@nid' => $nid]);
      return FALSE;
    }

    $store->set($key, $window);
    try {
      $this->eventDispatcher->dispatch(
        new ServiceProviderNotificationDueEvent($node, $emails),
        ServiceProviderEvents::NOTIFICATION_DUE,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Provider notification for service request @nid failed: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Finds published service requests with a provider and open status.
   *
   * @return int[]
   *   The candidate node IDs.
   */
  private function candidateIds(): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->exists('field_service_provider');

    $completion_tid = $this->completionStatusTid();
    $fields = $this->entityFieldManager->getFieldDefinitions('node', 'service_request');
    if ($completion_tid !== '' && isset($fields['field_status'])) {
      $open = $query->orConditionGroup()
        ->notExists('field_status')
        ->condition('field_status', $completion_tid, '<>');
      $query->condition($open);
    }

    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Returns the configured completion status term ID, '' when none is set.
   */
  private function completionStatusTid(): string {
    $tid = $this->settings()->get('completion_status_tid');
    return is_scalar($tid) ? trim((string) $tid) : '';
  }

  /**
   * Whether the event class can be loaded.
   *
   * The event implements an ECA interface; without the ECA module its class
   * cannot be declared, so nothing is queued or dispatched.
   */
  private function eventsAvailable(): bool {
    return interface_exists(EntityEventInterface::class);
  }

  /**
   * Returns the module settings.
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('markaspot_service_provider.settings');
  }

  /**
   * Returns the notification key-value store.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
