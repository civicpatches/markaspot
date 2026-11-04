<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

use Drupal\Core\Site\Settings;
use Drupal\Component\Utility\Bytes;

/**
 * Rejects oversized rasters using headers, before any pixel decoding.
 */
final class ImagePixelGuard {

  /**
   * Default pixel ceiling.
   *
   * Override in settings.php with
   * $settings['markaspot_vision_max_image_pixels'].
   */
  public const MAX_IMAGE_PIXELS = 40000000;

  /**
   * Checks a file or Drupal stream-wrapper URI without loading its raster.
   */
  public static function assertSafePath(string $uri): void {
    self::assertDimensions(@getimagesize($uri));
  }

  /**
   * Checks encoded bytes before forwarding them to a decoder or blur service.
   */
  public static function assertSafeBytes(string $contents): void {
    self::assertDimensions(@getimagesizefromstring($contents));
  }

  /**
   * Enforces the pixel ceiling and a conservative local decode memory budget.
   */
  private static function assertDimensions(array|false $size): void {
    $maximum = Settings::get('markaspot_vision_max_image_pixels', self::MAX_IMAGE_PIXELS);
    if (!is_int($maximum) || $maximum <= 0) {
      throw new \RuntimeException('Invalid vision image pixel limit.');
    }
    if ($size === FALSE || $size[0] <= 0 || $size[1] <= 0) {
      throw new \RuntimeException('Cannot verify image dimensions; refusing image processing.');
    }
    // Division avoids overflowing on attacker-controlled header dimensions.
    if ($size[0] > intdiv($maximum, $size[1])) {
      throw new \RuntimeException('Image exceeds the vision pixel limit; refusing image processing.');
    }
    $configuredMemory = (string) ini_get('memory_limit');
    $memoryLimit = $configuredMemory === '-1' ? -1 : (int) Bytes::toNumber($configuredMemory);
    if ($memoryLimit > 0) {
      // Allow for two RGBA buffers plus working space. A compressed file size
      // limit cannot protect GD: even a tiny PNG can expand to gigabytes.
      $available = max(0, $memoryLimit - memory_get_usage(TRUE) - 16 * 1024 * 1024);
      if ($size[0] * $size[1] > intdiv($available, 8)) {
        throw new \RuntimeException('Image exceeds the available decode memory budget; refusing image processing.');
      }
    }
  }

}
