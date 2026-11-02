<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Service;

/**
 * Verifies source-bound screening for blur reuse and media publication.
 */
final class ScreeningMarker {

  /**
   * Publication also accepts completed AI when hosting does not require blur.
   */
  public static function allowsPublication(array $marker, string|false $hash, bool $required): bool {
    return self::accepts($marker, $hash, $required)
      || (!$required && $hash !== FALSE && ($marker['source_sha256'] ?? '') === $hash
        && in_array($marker['analysis'] ?? '', ['done', 'existing'], TRUE));
  }

  /**
   * Only a successful blur under the applicable policy can be reused.
   */
  public static function accepts(array $marker, string|false $hash, bool $required): bool {
    return $hash !== FALSE
      && ($marker['privacy_screened'] ?? FALSE) === TRUE
      && ($marker['processed'] ?? FALSE) === TRUE
      && ($marker['source_sha256'] ?? '') === $hash
      && (!$required || ($marker['blur_required'] ?? FALSE) === TRUE);
  }

}
