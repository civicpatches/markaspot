<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_media\Unit;

use Drupal\markaspot_media\EventSubscriber\PrivateImageResponseSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Private originals and image styles must be reauthorized on every request.
 */
#[Group('markaspot_media')]
final class PrivateImageResponseSubscriberTest extends UnitTestCase {

  /**
   * Cache headers change without granting download access.
   */
  #[DataProvider('routes')]
  public function testPrivateResponsesHaveNoCacheLifetime(string $route, string $scheme, bool $private): void {
    $request = new Request();
    $request->attributes->add(['_route' => $route, 'scheme' => $scheme]);
    $response = new Response('', 403, [
      'Cache-Control' => 'private, max-age=900',
      'ETag' => 'old',
      'Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT',
    ]);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    (new PrivateImageResponseSubscriber())->onResponse($event);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame($private, $response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame(!$private, $response->headers->has('ETag'));
    if ($private) {
      $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
      $this->assertFalse($response->headers->has('Last-Modified'));
    }
  }

  /**
   * Provides download routes and an unrelated public response.
   */
  public static function routes(): array {
    return [
      ['system.files', 'private', TRUE],
      ['system.private_file_download', 'private', TRUE],
      ['image.style_private', 'private', TRUE],
      ['system.files', 'public', FALSE],
      ['entity.node.canonical', 'private', FALSE],
    ];
  }

}
