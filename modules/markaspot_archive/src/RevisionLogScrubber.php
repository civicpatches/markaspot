<?php

declare(strict_types=1);

namespace Drupal\markaspot_archive;

/**
 * Removes reporter contact data from revision log text.
 *
 * Revision logs keep human explanations verbatim (staff notes, Open311
 * reasons, legacy free-text logs), so they can quote a reporter's e-mail
 * address or telephone number. Anonymizing a report scrubs them by these
 * rules:
 *
 * - A civic.revision.v1 envelope keeps its schema, action, changes, events
 *   and every other key. Only its free-text "message" is scrubbed, and the
 *   envelope is written back in the canonical encoding of
 *   \Drupal\service_request\RevisionLog::build(). Event codes such as
 *   "moderation_hidden" therefore survive for every reader of the history.
 * - Any other log is legacy free text and is scrubbed as a whole.
 * - The reporter's own contact values (e-mail, telephone, names of the
 *   configured contact fields) become "[REDACTED]" wherever they occur as a
 *   whole word, case-insensitively. Values shorter than three characters are
 *   ignored, so short names cannot shred ordinary words.
 * - E-mail addresses become "[REDACTED email]", telephone numbers
 *   "[REDACTED phone]", with the patterns the AI PII redaction uses for
 *   report texts (German, international and local numbers of at least seven
 *   digits). IBANs and names nobody stored in a contact field stay: the
 *   patterns would destroy too much ordinary text.
 *
 * The patterns also select candidates in SQL (REGEXP on the log column), so
 * they only use constructs that PCRE, MySQL (ICU), MariaDB and PostgreSQL
 * read alike. Replacements never match them again: a scrubbed log is no
 * candidate any more.
 */
final class RevisionLogScrubber {

  /**
   * Replacement of a known reporter contact value.
   */
  public const VALUE_PLACEHOLDER = '[REDACTED]';

  /**
   * Replacement of an e-mail address.
   */
  public const EMAIL_PLACEHOLDER = '[REDACTED email]';

  /**
   * Replacement of a telephone number.
   */
  public const PHONE_PLACEHOLDER = '[REDACTED phone]';

  /**
   * E-mail addresses.
   */
  public const EMAIL_PATTERN = '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';

  /**
   * German, international and local telephone numbers.
   */
  public const PHONE_PATTERN = '([+]49|0049) *[1-9][0-9 /-]{6,15}[0-9]'
    . '|[+][1-48-9][0-9]{7,14}'
    . '|(?<![0-9])0[1-9][0-9 /-]{6,15}[0-9](?![0-9])';

  /**
   * Shortest known contact value that is replaced.
   */
  private const MIN_VALUE_LENGTH = 3;

  /**
   * Flags of RevisionLog::build().
   */
  private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

  /**
   * Returns the log without reporter contact data.
   *
   * @param string $log
   *   A stored revision log.
   * @param string[] $known_values
   *   The reporter's plain contact values.
   *
   * @return string
   *   The scrubbed log, identical to $log when nothing was found.
   */
  public static function scrub(string $log, array $known_values = []): string {
    if ($log === '') {
      return $log;
    }
    $envelope = json_decode($log, TRUE);
    if (
      is_array($envelope)
      && ($envelope['schema'] ?? NULL) === 'civic.revision.v1'
      && is_string($envelope['message'] ?? NULL)
    ) {
      $message = self::scrubText($envelope['message'], $known_values);
      if ($message === $envelope['message'] && !self::containsContactData($log)) {
        return $log;
      }
      $envelope['message'] = $message;
      // The canonical encoding also drops escape sequences a pattern could
      // match in the stored text, but not in the decoded message.
      return json_encode($envelope, self::JSON_FLAGS);
    }
    return self::scrubText($log, $known_values);
  }

  /**
   * Returns TRUE when a text matches the e-mail or telephone pattern.
   */
  public static function containsContactData(string $text): bool {
    return preg_match(self::regex(self::EMAIL_PATTERN), $text) === 1
      || preg_match(self::regex(self::PHONE_PATTERN), $text) === 1;
  }

  /**
   * Scrubs free text.
   *
   * @param string $text
   *   Free text.
   * @param string[] $known_values
   *   The reporter's plain contact values.
   */
  private static function scrubText(string $text, array $known_values): string {
    $text = preg_replace(self::regex(self::EMAIL_PATTERN), self::EMAIL_PLACEHOLDER, $text) ?? $text;
    $text = preg_replace(self::regex(self::PHONE_PATTERN), self::PHONE_PLACEHOLDER, $text) ?? $text;
    $values = array_filter(
      array_unique(array_map('trim', $known_values)),
      static fn (string $value): bool => mb_strlen($value) >= self::MIN_VALUE_LENGTH
    );
    // Longer values first: a full name must go before a part of it.
    usort($values, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    foreach ($values as $value) {
      $pattern = '~(?<![\p{L}\p{N}])' . preg_quote($value, '~') . '(?![\p{L}\p{N}])~iu';
      // Invalid UTF-8 fails the Unicode pattern: replace the plain value.
      $text = preg_replace($pattern, self::VALUE_PLACEHOLDER, $text)
        ?? str_ireplace($value, self::VALUE_PLACEHOLDER, $text);
    }
    return $text;
  }

  /**
   * Wraps a shared pattern for preg functions.
   *
   * The patterns are ASCII and match byte-wise, so invalid UTF-8 in a legacy
   * log cannot make them fail.
   */
  private static function regex(string $pattern): string {
    return '~' . $pattern . '~';
  }

}
