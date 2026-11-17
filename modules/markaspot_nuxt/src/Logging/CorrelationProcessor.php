<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Enriches the record before any handler receives it, including dblog.
 */
final class CorrelationProcessor implements ProcessorInterface {

  public function __construct(private readonly CorrelationIdProvider $correlation) {}

  /**
   * {@inheritdoc}
   */
  public function __invoke(LogRecord $record): LogRecord {
    $id = $this->correlation->getId();
    $context = $record->context;
    $context['correlation_id'] = $id;
    // Dblog retains prefixed variables, even when absent from the template.
    // Use an empty string outside HTTP: NULL placeholders are deprecated.
    $context['@correlation_id'] = $id ?? '';
    return $record->with(context: $context);
  }

}
