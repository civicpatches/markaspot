<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Drupal\Core\Logger\LogMessageParserInterface;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Emits only the correlation contract fields, never raw context or exceptions.
 *
 * Callers must use diagnostic message templates, never request bodies or other
 * personal data as the message. Only operational numeric/boolean placeholders
 * and the correlation UUID are retained, alongside exception code locations.
 * Other values and common identifiers are redacted; arbitrary literal prose
 * cannot be classified safely.
 */
final class CorrelationJsonFormatter implements FormatterInterface {

  private const MAX_MESSAGE_BYTES = 16 * 1024;

  private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

  public function __construct(private readonly LogMessageParserInterface $parser) {}

  /**
   * {@inheritdoc}
   */
  public function format(LogRecord $record): string {
    $id = $record->context['correlation_id'] ?? NULL;
    return json_encode([
      'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
      'service' => 'drupal',
      'request_id' => CorrelationIdProvider::isValid($id) ? $id : NULL,
      'channel' => $this->truncate($this->redact($record->channel)),
      'level' => strtolower($record->level->getName()),
      'message' => $this->formatMessage($record),
    ], self::JSON_FLAGS) . "\n";
  }

  /**
   * {@inheritdoc}
   */
  public function formatBatch(array $records): string {
    return implode('', array_map($this->format(...), $records));
  }

  /**
   * Substitutes Drupal and PSR-3 placeholders without altering dblog's record.
   */
  private function formatMessage(LogRecord $record): string {
    $message = $record->message;
    $context = $record->context;
    $variables = $this->parser->parseMessagePlaceholders($message, $context);
    $replacements = [];
    foreach ($variables as $key => $value) {
      $name = ltrim($key, '@%:');
      if ($name === 'correlation_id' && CorrelationIdProvider::isValid($value)) {
        $replacements[$key] = $value;
      }
      elseif (in_array($key, ['%type', '%function', '%file', '%line'], TRUE)
        && (is_string($value) || is_int($value))) {
        $replacements[$key] = (string) $value;
      }
      elseif (in_array($name, [
        'count', 'total', 'limit', 'offset', 'duration', 'elapsed', 'status',
        'code', 'attempt', 'attempts', 'size', 'bytes', 'seconds', 'milliseconds',
      ], TRUE)
        && (is_int($value) || is_float($value) || is_bool($value))) {
        $replacements[$key] = (string) $value;
      }
      else {
        $replacements[$key] = '[redacted]';
      }
    }
    return $this->truncate($this->redact(strtr($message, $replacements)));
  }

  /**
   * Removes common personal identifiers and credential literals from messages.
   */
  private function redact(string $message): string {
    return preg_replace([
      '~https?://[^\s<>"\']+~i',
      '/[A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
      '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
      '/\b(?:[a-f0-9]{0,4}:){2,}[a-f0-9:.]*\b/i',
      '/\bBearer\s+\S+/i',
      '/(?<![a-z0-9])(?:cookie|authorization|user-agent|token|password|secret|api_key|apikey|x-api-key|pass)\s*[:=]\s*[^\r\n]*/i',
    ], '[redacted]', $message);
  }

  /**
   * Bounds both UTF-8 bytes and JSON escaping, keeping lines below FPM's limit.
   */
  private function truncate(string $message): string {
    $prefix = mb_strcut($message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8');
    $truncated = strlen($prefix) < strlen($message);
    // Control characters can expand sixfold in JSON. Bound the encoded prefix
    // too; the two quotes and truncation marker do not count toward the cap.
    if (strlen(json_encode($prefix, self::JSON_FLAGS)) > self::MAX_MESSAGE_BYTES + 2) {
      $low = 0;
      $high = strlen($prefix);
      while ($low < $high) {
        $mid = intdiv($low + $high + 1, 2);
        $candidate = mb_strcut($prefix, 0, $mid, 'UTF-8');
        if (strlen(json_encode($candidate, self::JSON_FLAGS)) <= self::MAX_MESSAGE_BYTES + 2) {
          $low = $mid;
        }
        else {
          $high = $mid - 1;
        }
      }
      $prefix = mb_strcut($prefix, 0, $low, 'UTF-8');
      $truncated = TRUE;
    }
    return $prefix . ($truncated ? '…[truncated]' : '');
  }

}
