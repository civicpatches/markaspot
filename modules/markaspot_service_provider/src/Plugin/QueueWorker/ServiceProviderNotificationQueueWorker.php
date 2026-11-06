<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_service_provider\ServiceProviderNotificationScheduler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dispatches one due provider notification event per queued node.
 *
 * Items are arrays with the node ID ("nid") and the request time of the cron
 * run that queued it ("window"); items without a window are still processed.
 */
#[QueueWorker(
  id: ServiceProviderNotificationScheduler::QUEUE,
  title: new TranslatableMarkup('Service provider notifications'),
  cron: ['time' => 60],
)]
final class ServiceProviderNotificationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\markaspot_service_provider\ServiceProviderNotificationScheduler $scheduler
   *   The notification scheduler.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ServiceProviderNotificationScheduler $scheduler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_service_provider.notification_scheduler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      return;
    }
    $nid = self::positiveInt($data['nid'] ?? NULL);
    if ($nid !== NULL) {
      // Items queued before the window was added carry no window.
      $this->scheduler->notify($nid, self::positiveInt($data['window'] ?? NULL));
    }
  }

  /**
   * Returns an integer or digit string as int, NULL for anything else.
   */
  private static function positiveInt(mixed $value): ?int {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      $value = (int) $value;
      return $value > 0 ? $value : NULL;
    }
    return NULL;
  }

}
