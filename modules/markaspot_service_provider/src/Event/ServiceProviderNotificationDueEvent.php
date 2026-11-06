<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;
use Drupal\eca\Event\EntityEventInterface;
use Drupal\node\NodeInterface;

/**
 * Event dispatched by cron when a provider notification is due.
 *
 * One event is dispatched per service request and notification interval. The
 * request is the event entity, so ECA models receive it as [entity] and
 * [node]; the provider addresses are available as [sp_email].
 *
 * @see \Drupal\markaspot_service_provider\ServiceProviderEvents::NOTIFICATION_DUE
 */
final class ServiceProviderNotificationDueEvent extends Event implements EntityEventInterface {

  /**
   * Constructs a new ServiceProviderNotificationDueEvent.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string[] $emails
   *   The provider's configured addresses (organisation scope applied).
   */
  public function __construct(
    private readonly NodeInterface $node,
    private readonly array $emails,
  ) {}

  /**
   * Gets the service request node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity(): EntityInterface {
    return $this->node;
  }

  /**
   * Gets the provider addresses as a list.
   *
   * @return string[]
   *   The addresses, without duplicates.
   */
  public function getEmails(): array {
    return $this->emails;
  }

  /**
   * Gets the provider addresses as one recipient string.
   *
   * @return string
   *   The addresses separated by ", ", usable as a mail recipient list.
   */
  public function getEmail(): string {
    return implode(', ', $this->emails);
  }

}
