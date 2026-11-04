<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Proves no toolkit or remote decoder sees oversized request images.
 */
#[CoversClass(ImageProcessingService::class)]
#[CoversClass(AttributeFillingService::class)]
#[Group('markaspot_vision')]
final class ImagePixelGuardIntegrationTest extends UnitTestCase {

  /**
   * Advertises 100 megapixels in a 33-byte PNG header.
   */
  private function bomb(): string {
    $ihdr = 'IHDR' . pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
  }

  /**
   * Both the image style and HTTP boundaries must stay untouched.
   */
  private function service(): ImageProcessingService {
    $http = $this->createMock(Client::class);
    $http->expects($this->never())->method('post');
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn(new NullLogger());
    return new ImageProcessingService(
      $http, $this->getConfigFactoryStub(['markaspot_vision.settings' => []]), $entities,
      $this->createMock(FileSystemInterface::class), $logger,
      $this->createMock(StateInterface::class), $this->createMock(TimeInterface::class),
    );
  }

  /**
   * Main analysis fails before even loading the derivative style.
   */
  public function testAnalysisStopsBeforeDerivativeGeneration(): void {
    $path = tempnam(sys_get_temp_dir(), 'vision-bomb-');
    file_put_contents($path, $this->bomb());
    try {
      self::assertNull($this->service()->processImages([$path]));
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Blur refuses the image even when privacy preprocessing is disabled.
   */
  public function testBlurStopsBeforeRemoteDecode(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('pixel limit');
    $this->service()->blurSensitiveAreas($this->bomb(), 'image/png');
  }

  /**
   * Privacy screening cannot return a safe verdict for a rejected raster.
   */
  public function testScreeningFailsClosed(): void {
    self::assertNull($this->service()->screenPrivacy($this->bomb(), 'image/png'));
  }

  /**
   * Attribute filling checks the source before its separate wide derivative.
   */
  public function testAttributeFillingStopsBeforeDerivativeGeneration(): void {
    $path = tempnam(sys_get_temp_dir(), 'attribute-bomb-');
    file_put_contents($path, $this->bomb());
    $service = (new \ReflectionClass(AttributeFillingService::class))->newInstanceWithoutConstructor();
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->expects($this->never())->method('getStorage');
    (new \ReflectionProperty($service, 'entityTypeManager'))->setValue($service, $entities);
    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('pixel limit');
      (new \ReflectionMethod($service, 'getStyledImagePath'))->invoke($service, $path);
    }
    finally {
      unlink($path);
    }
  }

}
