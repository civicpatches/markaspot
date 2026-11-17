<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Drupal\monolog\Logger\Handler\DrupalHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Keeps every Drupal sink, including loggers registered late by Drush.
 */
final class CorrelationLoggersPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    $loggers = [];
    foreach ($container->findTaggedServiceIds('logger') as $id => $tags) {
      $loggers[$id] = max(array_map(static fn(array $tag): int => $tag['priority'] ?? 0, $tags));
    }
    arsort($loggers, SORT_NUMERIC);
    $handlers = [];
    foreach ($loggers as $id => $priority) {
      $handler = 'monolog.handler.drupal.' . preg_replace('/^logger\./', '', $id);
      if (!$container->has($handler)) {
        $container->register($handler, DrupalHandler::class)
          ->addArgument(new Reference($id))->setShared(FALSE);
      }
      $handlers[] = new Reference($handler);
    }
    $container->getDefinition('monolog.handler.correlation_drupal')
      ->replaceArgument(0, $handlers);
  }

}
