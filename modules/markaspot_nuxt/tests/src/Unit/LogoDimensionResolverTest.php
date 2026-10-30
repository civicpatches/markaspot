<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_nuxt\Image\LogoDimensionResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests resolving and caching the size of stored logos.
 */
#[CoversClass(LogoDimensionResolver::class)]
#[Group('markaspot_nuxt')]
final class LogoDimensionResolverTest extends UnitTestCase {

  /**
   * Temporary files created by a test.
   *
   * @var string[]
   */
  private array $paths = [];

  /**
   * The cache backend.
   */
  private MemoryBackend $cache;

  /**
   * The resolver under test.
   */
  private LogoDimensionResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_800_000_000);
    $time->method('getCurrentTime')->willReturn(1_800_000_000);
    $time->method('getRequestMicroTime')->willReturn(1_800_000_000.0);
    $time->method('getCurrentMicroTime')->willReturn(1_800_000_000.0);
    $this->cache = new MemoryBackend($time);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $this->resolver = new LogoDimensionResolver($this->cache, $loggerFactory, $time);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->paths as $path) {
      @unlink($path);
    }
    parent::tearDown();
  }

  /**
   * SVG sizes keep two decimals.
   */
  public function testResolvesSvgSize(): void {
    $file = $this->createFile(41, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 496.953 201.1"></svg>');
    $this->assertSame(['width' => 496.95, 'height' => 201.1], $this->resolver->forFile($file));
  }

  /**
   * A small viewBox keeps its aspect ratio.
   */
  public function testKeepsTheRatioOfSmallViewBoxes(): void {
    $file = $this->createFile(52, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1.5 1"></svg>');
    $this->assertSame(['width' => 1.5, 'height' => 1.0], $this->resolver->forFile($file));
  }

  /**
   * PNG sizes come from the image header.
   */
  public function testResolvesPngSize(): void {
    $file = $this->createFile(42, 'png', BrandingImageDimensionsTest::pngHeader(300, 100));
    $this->assertSame(['width' => 300.0, 'height' => 100.0], $this->resolver->forFile($file));
  }

  /**
   * The size is read once and then served from the cache.
   */
  public function testCachesTheSizeWithFileCacheTags(): void {
    $file = $this->createFile(43, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"></svg>');
    $this->assertSame(['width' => 200.0, 'height' => 100.0], $this->resolver->forFile($file));

    // A second read must not touch the file any more.
    unlink($this->paths[array_key_last($this->paths)]);
    $this->assertSame(['width' => 200.0, 'height' => 100.0], $this->resolver->forFile($file));

    $cached = $this->cache->get('markaspot_nuxt:logo_size:43:1700000000');
    $this->assertNotFalse($cached);
    $this->assertSame(['file:43'], $cached->tags);
  }

  /**
   * An unreadable file resolves to NULL and is retried after a few minutes.
   */
  public function testUnreadableFileIsRetriedLater(): void {
    $file = $this->createFileEntity(44, sys_get_temp_dir() . '/markaspot-missing-logo-' . uniqid() . '.svg');
    $this->assertNull($this->resolver->forFile($file));

    $cached = $this->cache->get('markaspot_nuxt:logo_size:44:1700000000');
    $this->assertNotFalse($cached);
    $this->assertSame(['size' => NULL], $cached->data);
    $this->assertSame(1_800_000_300, $cached->expire);
  }

  /**
   * A readable logo without an authoritative size is cached permanently.
   */
  public function testSizelessLogoIsCachedPermanently(): void {
    $file = $this->createFile(47, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100%"></svg>');
    $this->assertNull($this->resolver->forFile($file));

    $cached = $this->cache->get('markaspot_nuxt:logo_size:47:1700000000');
    $this->assertNotFalse($cached);
    $this->assertSame(MemoryBackend::CACHE_PERMANENT, $cached->expire);
  }

  /**
   * Sizes outside the accepted pixel range are dropped.
   */
  public function testRejectsImplausibleSizes(): void {
    $file = $this->createFile(48, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20000" height="100"></svg>');
    $this->assertNull($this->resolver->forFile($file));

    $file = $this->createFile(49, 'svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0.001 0.001"></svg>');
    $this->assertNull($this->resolver->forFile($file));
  }

  /**
   * SVGs declaring entities are not handed to the XML parser.
   */
  public function testSkipsSvgWithEntityDeclarations(): void {
    $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY w "200">]>'
      . '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"></svg>';
    $file = $this->createFile(50, 'svg', $svg);
    $this->assertNull($this->resolver->forFile($file));
  }

  /**
   * A plain DOCTYPE from an editor export does not block the size.
   */
  public function testAcceptsSvgWithPlainDoctype(): void {
    $svg = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
      . '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"></svg>';
    $file = $this->createFile(51, 'svg', $svg);
    $this->assertSame(['width' => 200.0, 'height' => 100.0], $this->resolver->forFile($file));
  }

  /**
   * Files other than SVG and PNG are not read.
   */
  public function testIgnoresOtherExtensions(): void {
    $file = $this->createFile(45, 'jpg', BrandingImageDimensionsTest::pngHeader(300, 100));
    $this->assertNull($this->resolver->forFile($file));
  }

  /**
   * Files larger than the upload limit are not parsed.
   */
  public function testRejectsOversizedFiles(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><!--'
      . str_repeat('x', 600 * 1024) . '--></svg>';
    $file = $this->createFile(46, 'svg', $svg);
    $this->assertNull($this->resolver->forFile($file));
  }

  /**
   * Writes a temporary logo file and returns a file entity stub for it.
   */
  private function createFile(int $id, string $extension, string $contents): FileInterface {
    $path = sys_get_temp_dir() . '/markaspot-logo-' . uniqid('', TRUE) . '.' . $extension;
    file_put_contents($path, $contents);
    $this->paths[] = $path;
    return $this->createFileEntity($id, $path);
  }

  /**
   * Creates a file entity stub for a path.
   */
  private function createFileEntity(int $id, string $path): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn((string) $id);
    $file->method('getFileUri')->willReturn($path);
    $file->method('getChangedTime')->willReturn(1_700_000_000);
    $file->method('getCacheTags')->willReturn(['file:' . $id]);
    return $file;
  }

}
