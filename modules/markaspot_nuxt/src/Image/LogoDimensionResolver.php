<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Image;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileInterface;

/**
 * Resolves the intrinsic size of a stored jurisdiction logo.
 *
 * The logo fields are plain file fields, so Drupal stores no dimensions. The
 * size is read from the file once and cached until the file entity is saved
 * again: a replaced logo saves the same entity, and its cache tags drop the
 * entry. A file that cannot be read is retried after a few minutes instead,
 * so a storage hiccup does not hide the size until the next cache clear.
 *
 * Sizes keep two decimals. Consumers use them as an aspect ratio, and
 * rounding a small viewBox such as 1.5 x 1 to whole pixels would distort it.
 */
class LogoDimensionResolver {

  /**
   * Upper bound for a logo read; uploads are limited to 500 KB.
   */
  private const MAX_BYTES = 512 * 1024;

  /**
   * Largest side accepted as a real logo size.
   */
  private const MAX_DIMENSION = 10000;

  /**
   * Seconds before an unreadable logo file is tried again.
   */
  private const RETRY_AFTER = 300;

  /**
   * Constructs a LogoDimensionResolver.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend for resolved sizes.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the intrinsic logo size in pixels, rounded to two decimals.
   *
   * @param \Drupal\file\FileInterface $file
   *   The logo file entity.
   *
   * @return array{width: float, height: float}|null
   *   The size, or NULL when it cannot be determined.
   */
  public function forFile(FileInterface $file): ?array {
    $cid = 'markaspot_nuxt:logo_size:' . $file->id() . ':' . $file->getChangedTime();
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data['size'] ?? NULL;
    }

    $uri = $file->getFileUri();
    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $expire = CacheBackendInterface::CACHE_PERMANENT;
    if (!in_array($extension, ['svg', 'png'], TRUE)) {
      $size = NULL;
    }
    else {
      $data = $this->readContents($uri);
      if ($data === NULL) {
        $size = NULL;
        $expire = $this->time->getRequestTime() + self::RETRY_AFTER;
      }
      else {
        $size = $this->parse($data, $extension);
      }
    }

    $this->cache->set($cid, ['size' => $size], $expire, $file->getCacheTags());
    return $size;
  }

  /**
   * Reads at most one byte more than the size limit from a stored file.
   *
   * @return string|null
   *   The contents, or NULL when the file cannot be opened or read.
   */
  private function readContents(string $uri): ?string {
    try {
      $handle = @fopen($uri, 'rb');
      if ($handle === FALSE) {
        $this->loggerFactory->get('markaspot_nuxt')->warning('Logo @uri could not be opened to read its size.', ['@uri' => $uri]);
        return NULL;
      }
      try {
        $data = stream_get_contents($handle, self::MAX_BYTES + 1);
      }
      finally {
        fclose($handle);
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('markaspot_nuxt')->warning('Logo @uri could not be read: @message', [
        '@uri' => $uri,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return is_string($data) ? $data : NULL;
  }

  /**
   * Parses the size from logo file contents.
   *
   * @return array{width: float, height: float}|null
   *   The size, or NULL when the file is empty, too large, declares entities
   *   or has no authoritative size within the accepted range.
   */
  private function parse(string $data, string $extension): ?array {
    // Uploads are sanitized and carry no DTD. Entity declarations can only
    // come from files stored before the sanitizer existed; this anonymous
    // path does not hand them to the XML parser at all.
    if ($data === '' || strlen($data) > self::MAX_BYTES || stripos($data, '<!ENTITY') !== FALSE) {
      return NULL;
    }

    $size = BrandingImageDimensions::fromData($data, $extension);
    if ($size === NULL) {
      return NULL;
    }
    $width = round($size['width'], 2);
    $height = round($size['height'], 2);
    if ($width <= 0 || $width > self::MAX_DIMENSION || $height <= 0 || $height > self::MAX_DIMENSION) {
      return NULL;
    }

    return ['width' => $width, 'height' => $height];
  }

}
