<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Utility;

/**
 * Separates the usage that attaches a file from embeds in formatted text.
 *
 * The editor module records a file usage for every data-entity-uuid
 * attribute it finds in the raw value of a formatted text field, on every
 * insert and update, whatever the text format and whether or not the save
 * was validated. The upload binding refuses such embeds on validated saves,
 * but text written without validation, or rewritten after it (the service
 * request presave re-encodes the attributes JSON, the PII redaction rewrites
 * the body), still lands there. An embed is therefore no claim on an upload: it
 * neither blocks the uploading session's attach nor the photo's blur, and it
 * does not keep an orphaned upload alive.
 */
final class UploadFileUsage {

  /**
   * Usage modules that record embeds in formatted text.
   */
  public const EMBED_MODULES = ['editor'];

  /**
   * Drops embed usage from a FileUsageInterface::listUsage() list.
   *
   * @param array<string, array<string, array<int|string, int|string>>> $usage
   *   File usage keyed by module, type and ID.
   *
   * @return array<string, array<string, array<int|string, int|string>>>
   *   The usage of every other module.
   */
  public static function attachments(array $usage): array {
    return array_diff_key($usage, array_flip(self::EMBED_MODULES));
  }

}
