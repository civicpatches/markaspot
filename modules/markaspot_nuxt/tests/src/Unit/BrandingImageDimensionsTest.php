<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\markaspot_nuxt\Image\BrandingImageDimensions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading the intrinsic size of SVG and PNG branding assets.
 */
#[CoversClass(BrandingImageDimensions::class)]
#[Group('markaspot_nuxt')]
final class BrandingImageDimensionsTest extends UnitTestCase {

  /**
   * SVG sizes come from absolute attributes or the viewBox.
   */
  #[DataProvider('svgProvider')]
  public function testSvgDimensions(string $svg, ?array $expected): void {
    $this->assertSame($expected, BrandingImageDimensions::fromData($svg, 'svg'));
  }

  /**
   * Provides SVG markup and the expected size.
   */
  public static function svgProvider(): array {
    $ns = 'xmlns="http://www.w3.org/2000/svg"';
    return [
      'unitless attributes' => [
        "<svg $ns width=\"200\" height=\"100\"></svg>",
        ['width' => 200.0, 'height' => 100.0],
      ],
      'px and decimals' => [
        "<svg $ns width=\"120.5px\" height=\"60px\"></svg>",
        ['width' => 120.5, 'height' => 60.0],
      ],
      'percentage falls back to the viewBox' => [
        "<svg $ns width=\"100%\" height=\"100%\" viewBox=\"0 0 512 341\"></svg>",
        ['width' => 512.0, 'height' => 341.0],
      ],
      'viewBox only, comma separated' => [
        "<svg $ns viewBox=\"0,0,24,12\"></svg>",
        ['width' => 24.0, 'height' => 12.0],
      ],
      'relative units without viewBox' => [
        "<svg $ns width=\"10em\" height=\"5em\"></svg>",
        NULL,
      ],
      'zero viewBox' => [
        "<svg $ns viewBox=\"0 0 0 10\"></svg>",
        NULL,
      ],
      'overflowing viewBox' => [
        "<svg $ns viewBox=\"0 0 1e400 1e400\"></svg>",
        NULL,
      ],
      'overflowing attributes' => [
        "<svg $ns width=\"" . str_repeat('9', 400) . '" height="' . str_repeat('9', 400) . '"></svg>',
        NULL,
      ],
      'missing SVG namespace' => [
        '<svg width="200" height="100"></svg>',
        NULL,
      ],
      'not XML' => [
        'not an svg',
        NULL,
      ],
      'other root element' => [
        '<html xmlns="http://www.w3.org/1999/xhtml"></html>',
        NULL,
      ],
    ];
  }

  /**
   * PNG sizes come from the image header.
   */
  public function testPngDimensions(): void {
    $this->assertSame(
      ['width' => 300.0, 'height' => 100.0],
      BrandingImageDimensions::fromData(self::pngHeader(300, 100), 'png'),
    );
  }

  /**
   * Bytes that are not a PNG yield no size.
   */
  public function testInvalidPngHasNoDimensions(): void {
    $this->assertNull(BrandingImageDimensions::fromData('GIF89a', 'png'));
    $this->assertNull(BrandingImageDimensions::fromData('', 'png'));
  }

  /**
   * Extensions other than SVG and PNG are not parsed.
   */
  public function testUnsupportedExtensionHasNoDimensions(): void {
    $this->assertNull(BrandingImageDimensions::fromData(self::pngHeader(300, 100), 'jpg'));
  }

  /**
   * Builds a PNG signature and IHDR chunk for the given size.
   */
  public static function pngHeader(int $width, int $height): string {
    $ihdr = pack('NN', $width, $height) . "\x08\x06\x00\x00\x00";
    return "\x89PNG\r\n\x1A\n"
      . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
  }

}
