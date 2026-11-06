<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider\Plugin\ECA\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaEvent;
use Drupal\eca\Attribute\Token;
use Drupal\eca\Event\Tag;
use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent;
use Drupal\markaspot_service_provider\ServiceProviderEvents;

/**
 * ECA event for due service provider notifications.
 *
 * The [node] and [entity] tokens are provided by ECA core because the event
 * implements EntityEventInterface.
 */
#[EcaEvent(
  id: 'service_provider_notification_due',
  event_name: ServiceProviderEvents::NOTIFICATION_DUE,
  event_class: ServiceProviderNotificationDueEvent::class,
  subscriber_priority: 0,
  tags: Tag::RUNTIME,
  label: new TranslatableMarkup('Service provider notification due'),
  description: new TranslatableMarkup('Cron dispatches this at most once per notification interval for each published service request that has an assigned provider and is not completed.'),
)]
class ServiceProviderNotificationEvent extends EventBase {

  /**
   * {@inheritdoc}
   */
  public static function definitions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  #[Token(
    name: 'sp_email',
    description: 'The service provider email addresses, separated by commas.',
    classes: [ServiceProviderNotificationDueEvent::class],
  )]
  public function getData(string $key): mixed {
    if ($key === 'sp_email' && $this->event instanceof ServiceProviderNotificationDueEvent) {
      return $this->event->getEmail();
    }
    return parent::getData($key);
  }

  /**
   * {@inheritdoc}
   */
  public function hasData(string $key): bool {
    if ($key === 'sp_email') {
      return $this->event instanceof ServiceProviderNotificationDueEvent;
    }
    return parent::hasData($key);
  }

}
