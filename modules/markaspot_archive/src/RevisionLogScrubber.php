<?php

declare(strict_types=1);

namespace Drupal\markaspot_archive;

/**
 * Removes the free-text messages from the revision logs of a report.
 *
 * Revision logs keep human explanations verbatim (staff notes, Open311
 * reasons, legacy free-text logs). They can quote a reporter's name,
 * address or telephone number in any spelling, and once the contact fields
 * are anonymized nothing tells which words were personal. Anonymizing a
 * report therefore replaces every message with a fixed placeholder:
 *
 * - A civic.revision.v1 envelope keeps its schema, action, changes, events
 *   and every other key in their order. Only a non-empty "message" becomes
 *   the placeholder, and the envelope is written back with the flags of
 *   \Drupal\service_request\RevisionLog::build(). Event codes such as
 *   "moderation_hidden" and the status changes therefore survive for every
 *   reader of the history.
 * - Any other non-empty log is legacy free text and becomes the placeholder.
 * - Empty logs, envelopes without a message or with an empty one, and
 *   messages already replaced stay byte-identical: replacing is idempotent.
 *
 * The placeholder is stored in English, like the action metadata of the
 * envelope; a reader of the history may translate this fixed text.
 */
final class RevisionLogScrubber {

  /**
   * The text that replaces a free-text message.
   */
  public const PLACEHOLDER = 'Message removed when the report was anonymized.';

  /**
   * Schema of a structured revision log.
   */
  public const SCHEMA = 'civic.revision.v1';

  /**
   * Flags of RevisionLog::build().
   */
  public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

  /**
   * Returns the log with its free-text message replaced.
   *
   * @param string $log
   *   A stored revision log.
   *
   * @return string
   *   The log with the placeholder as its message, identical to $log when it
   *   holds no message or the placeholder already.
   */
  public static function scrub(string $log): string {
    if ($log === '' || $log === self::PLACEHOLDER) {
      return $log;
    }
    $envelope = json_decode($log, TRUE);
    if (!is_array($envelope) || ($envelope['schema'] ?? NULL) !== self::SCHEMA) {
      return self::PLACEHOLDER;
    }
    $message = $envelope['message'] ?? '';
    if ($message === '' || $message === self::PLACEHOLDER) {
      return $log;
    }
    $envelope['message'] = self::PLACEHOLDER;
    return json_encode($envelope, self::JSON_FLAGS);
  }

  /**
   * Returns TRUE when the log still holds a free-text message.
   */
  public static function needsScrubbing(string $log): bool {
    return self::scrub($log) !== $log;
  }

}
