<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Site\Settings;
use Drupal\markaspot_ai\Utility\ImagePixelGuard;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises header-only checks without allocating the claimed pixel raster.
 */
#[CoversClass(ImagePixelGuard::class)]
#[Group('markaspot_ai')]
final class ImagePixelGuardTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * A PNG header advertising arbitrary dimensions, never decoded in a test.
   */
  public static function header(int $width, int $height): string {
    $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
  }

  /**
   * A tiny compressed input can advertise dimensions far beyond memory.
   */
  #[DataProvider('unsafeImages')]
  public function testUnsafeBytesAreRejected(string $bytes): void {
    self::assertLessThan(100, strlen($bytes));
    $this->expectException(\RuntimeException::class);
    ImagePixelGuard::assertSafeBytes($bytes);
  }

  /**
   * Header-only bombs, zero dimensions and unrecognised bytes.
   */
  public static function unsafeImages(): array {
    return [
      '100 megapixels' => [self::header(10000, 10000)],
      'overflow dimensions' => [self::header(2147483647, 2147483647)],
      'zero width' => [self::header(0, 100)],
      'invalid data' => ['not an image'],
    ];
  }

  /**
   * The file path check runs before a toolkit would load the raster.
   */
  public function testOversizedFileIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'pixel-guard-');
    file_put_contents($path, self::header(10000, 10000));
    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('pixel limit');
      ImagePixelGuard::assertSafePath($path);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * The site limit accepts its exact boundary and rejects one extra pixel.
   */
  public function testConfiguredBoundary(): void {
    new Settings(['markaspot_vision_max_image_pixels' => 100]);
    ImagePixelGuard::assertSafeBytes(self::header(10, 10));
    $this->expectException(\RuntimeException::class);
    ImagePixelGuard::assertSafeBytes(self::header(101, 1));
  }

  /**
   * Misconfiguration must not turn the guard off.
   */
  public function testInvalidConfigurationFailsClosed(): void {
    new Settings(['markaspot_vision_max_image_pixels' => 0]);
    $this->expectException(\RuntimeException::class);
    ImagePixelGuard::assertSafeBytes(self::header(1, 1));
  }

  /**
   * A nominally allowed image must still fit in the PHP decode budget.
   */
  public function testAvailableMemoryGuard(): void {
    $previous = ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(TRUE) + 20 * 1024 * 1024));
    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('decode memory budget');
      ImagePixelGuard::assertSafeBytes(self::header(1000, 1000));
    }
    finally {
      ini_set('memory_limit', $previous);
    }
  }

  /**
   * Unlimited PHP memory still enforces the finite pixel ceiling.
   */
  public function testUnlimitedMemoryRetainsPixelLimit(): void {
    $previous = ini_get('memory_limit');
    ini_set('memory_limit', '-1');
    try {
      ImagePixelGuard::assertSafeBytes(self::header(8000, 5000));
      $this->expectException(\RuntimeException::class);
      ImagePixelGuard::assertSafeBytes(self::header(8001, 5000));
    }
    finally {
      ini_set('memory_limit', $previous);
    }
  }

}
