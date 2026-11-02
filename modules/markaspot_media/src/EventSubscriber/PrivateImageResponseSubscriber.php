<?php

declare(strict_types=1);

namespace Drupal\markaspot_media\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prevents caches from retaining private photos after access is revoked.
 */
final class PrivateImageResponseSubscriber implements EventSubscriberInterface {

  /**
   * Applies after Drupal has checked file and derivative access.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if ($route === 'image.style_private' || $route === 'system.private_file_download'
      || ($route === 'system.files' && $request->attributes->get('scheme', 'private') === 'private')) {
      $response = $event->getResponse();
      $response->headers->set('Cache-Control', 'private, no-store');
      $response->headers->set('X-Cache-Policy', 'private, no-store');
      $response->headers->remove('ETag');
      $response->headers->remove('Last-Modified');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -1000]];
  }

}
