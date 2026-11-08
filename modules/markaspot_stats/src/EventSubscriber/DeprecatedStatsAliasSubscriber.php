<?php

declare(strict_types=1);

namespace Drupal\markaspot_stats\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Announces the one-release migration window for public statistics aliases.
 */
final class DeprecatedStatsAliasSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the alias subscriber.
   */
  public function __construct(private readonly LoggerInterface $logger) {}

  /**
   * Logs at most once for each request, after routing and access checks.
   */
  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $replacement = match ($request->attributes->get('_route')) {
      'markaspot_stats.api_annual' => '/stats/annual',
      'markaspot_stats.api_status' => '/stats/status',
      'markaspot_stats.api_categories' => '/stats/categories',
      default => NULL,
    };
    if ($replacement === NULL || $request->attributes->get('_stats_alias_logged')) {
      return;
    }
    $request->attributes->set('_stats_alias_logged', TRUE);
    $this->logger->notice('Deprecated statistics alias: use @route; this alias will be removed in the release after 11.9.249.', ['@route' => $replacement]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'onController'];
  }

}
