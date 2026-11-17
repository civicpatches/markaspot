<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Uuid\Php;
use Drupal\markaspot_nuxt\Logging\CorrelationIdProvider;
use Drupal\markaspot_nuxt\Logging\CorrelationMiddleware;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers cached responses that never dispatch kernel.response.
 */
#[Group('markaspot_nuxt')]
final class CorrelationMiddlewareTest extends UnitTestCase {

  /**
   * Cache hits receive fresh IDs, including a supplied valid upstream UUID.
   */
  public function testCacheHitsReplaceStoredHeader(): void {
    $stack = new RequestStack();
    $provider = new CorrelationIdProvider($stack, new Php());
    $inner = $this->createMock(HttpKernelInterface::class);
    $inner->method('handle')->willReturnCallback(static function (): Response {
      $response = new Response('cached body');
      $response->headers->set('X-Request-Id', 'old-cached-id');
      return $response;
    });
    $middleware = new CorrelationMiddleware($inner, $provider);
    $first = $middleware->handle(Request::create('/cached'));
    $second = $middleware->handle(Request::create('/cached'));
    $this->assertTrue(CorrelationIdProvider::isValid($first->headers->get('X-Request-Id')));
    $this->assertNotSame($first->headers->get('X-Request-Id'), $second->headers->get('X-Request-Id'));
    $request = Request::create('/cached');
    $request->headers->set('X-Request-Id', '01234567-89ab-4cde-8fab-0123456789ab');
    $this->assertSame($request->headers->get('X-Request-Id'), $middleware->handle($request)->headers->get('X-Request-Id'));
    $this->assertSame('cached body', $first->getContent());
    $this->assertNull($provider->getId());
  }

  /**
   * A cached subrequest still uses the main request's established correlation.
   */
  public function testCachedSubrequestUsesMainId(): void {
    $stack = new RequestStack();
    $main = Request::create('/main');
    $stack->push($main);
    $provider = new CorrelationIdProvider($stack, new Php());
    $id = $provider->getId();
    $inner = $this->createMock(HttpKernelInterface::class);
    $inner->method('handle')->willReturn(new Response());
    $response = (new CorrelationMiddleware($inner, $provider))->handle(Request::create('/sub'), HttpKernelInterface::SUB_REQUEST);
    $this->assertSame($id, $response->headers->get('X-Request-Id'));
    $this->assertSame($main, $stack->getMainRequest());
  }

}
