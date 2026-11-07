<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

use Psr\Log\LoggerInterface;

/**
 * Shared hosting policy, independent of enabled Drupal modules.
 */
final class BlurPolicy {

  /**
   * Values that deliberately turn the requirement off.
   */
  private const DISABLED = ['0', 'false', 'no', 'off'];

  /**
   * Values that deliberately turn the requirement on.
   */
  private const ENABLED = ['1', 'true', 'yes', 'on'];

  /**
   * Whether this request has already warned about an unfamiliar value.
   */
  private static bool $warned = FALSE;

  /**
   * Reads the hosting requirement, failing closed for unfamiliar values.
   */
  public static function mode(LoggerInterface $logger): string {
    $value = self::value();
    if ($value === '') {
      // Only canonical ENV signals hosting; config and VISION_BLUR_URL do not.
      return trim((string) getenv('MARKASPOT_BLUR_URL')) !== '' ? 'auto-required' : 'auto-unprotected';
    }
    if (self::hasUnrecognizedValue() && !self::$warned) {
      $logger->warning('Unrecognized MARKASPOT_BLUR_REQUIRED value; enforcing blur for this request.');
      self::$warned = TRUE;
    }
    return in_array($value, self::DISABLED, TRUE) ? 'off' : 'strict';
  }

  /**
   * Reports a set value that is neither a known on nor a known off value.
   *
   * The mode is strict then, so status checks can tell a typo from a
   * deliberate strict setting.
   */
  public static function hasUnrecognizedValue(): bool {
    $value = self::value();
    return $value !== '' && !in_array($value, [...self::DISABLED, ...self::ENABLED], TRUE);
  }

  /**
   * Returns the normalized MARKASPOT_BLUR_REQUIRED value, unquoted.
   */
  private static function value(): string {
    $value = strtolower(trim((string) getenv('MARKASPOT_BLUR_REQUIRED')));
    if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], TRUE) && $value[0] === substr($value, -1)) {
      $value = trim(substr($value, 1, -1));
    }
    return $value;
  }

  /**
   * Reports whether the effective mode requires preprocessing.
   */
  public static function isRequired(LoggerInterface $logger): bool {
    return in_array(self::mode($logger), ['strict', 'auto-required'], TRUE);
  }

  /**
   * Checks environment-only credentials, including the existing legacy key.
   */
  public static function hasEnvironmentCredentials(): bool {
    return trim((string) getenv('MARKASPOT_BLUR_URL')) !== ''
      && (trim((string) getenv('MARKASPOT_BLUR_API_KEY')) !== '' || trim((string) getenv('AI_API_KEY')) !== '');
  }

}
