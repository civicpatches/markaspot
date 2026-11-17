<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Psr\Log\AbstractLogger;

/**
 * Collects records received through Drupal's logger service tag.
 */
final class CorrelationTestLogger extends AbstractLogger {

  /**
   * The unmodified messages and context forwarded to this sink.
   *
   * @var array
   */
  public array $entries = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->entries[] = ['message' => $message, 'context' => $context];
  }

}
