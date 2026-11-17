<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns the same correlation UUID on main and subrequest responses.
 */
final class CorrelationResponseSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly CorrelationIdProvider $correlation) {}

  /**
   * Adds the header even when the request did not produce any log entries.
   */
  public function onResponse(ResponseEvent $event): void {
    $id = $this->correlation->getId();
    if ($id !== NULL) {
      $event->getResponse()->headers->set('X-Request-Id', $id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -1000]];
  }

}
