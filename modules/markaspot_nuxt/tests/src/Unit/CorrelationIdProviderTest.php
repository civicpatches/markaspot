<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\markaspot_nuxt\Logging\CorrelationIdProvider;
use Drupal\markaspot_nuxt\Logging\CorrelationProcessor;
use Drupal\markaspot_nuxt\Logging\CorrelationResponseSubscriber;
use Drupal\Tests\UnitTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests strict input validation and request-scoped correlation.
 */
#[Group('markaspot_nuxt')]
final class CorrelationIdProviderTest extends UnitTestCase {

  private const UUID = '01234567-89ab-4cde-8fab-0123456789ab';

  /**
   * Invalid headers never survive into the request context or response.
   */
  #[DataProvider('invalidHeaders')]
  public function testInvalidHeaders(?string $header): void {
    $stack = new RequestStack();
    $request = Request::create('/');
    if ($header !== NULL) {
      $request->headers->set('X-Request-Id', $header);
    }
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects($this->once())->method('generate')->willReturn(self::UUID);
    $provider = new CorrelationIdProvider($stack, $uuid);
    $this->assertNull($provider->getId());
    $stack->push($request);
    $this->assertSame(self::UUID, $provider->getId());
    $this->assertSame(self::UUID, $provider->getId());

    $response = new Response();
    $response->headers->set('X-Request-Id', 'stale');
    (new CorrelationResponseSubscriber($provider))->onResponse(new ResponseEvent(
      $this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response,
    ));
    $this->assertSame(self::UUID, $response->headers->get('X-Request-Id'));
  }

  /**
   * Values outside the UUID v4 contract, including log injection payloads.
   */
  public static function invalidHeaders(): array {
    return [
      'missing' => [NULL],
      'empty' => [''],
      'report number' => ['2026-12345'],
      'wrong version' => ['01234567-89ab-1cde-8fab-0123456789ab'],
      'wrong variant' => ['01234567-89ab-4cde-7fab-0123456789ab'],
      'newline' => [self::UUID . "\n"],
      'spaces' => [' ' . self::UUID],
      'multiple' => [self::UUID . ',' . self::UUID],
      'injection' => ["bad\n{\"request_id\":\"forged\"}"],
      'oversized' => [str_repeat('a', 10000)],
    ];
  }

  /**
   * A subrequest and rebuilt provider reuse the lazily adopted main UUID.
   */
  public function testMainRequestIsReused(): void {
    $stack = new RequestStack();
    $main = Request::create('/main');
    $main->headers->set('X-Request-Id', strtoupper(self::UUID));
    $stack->push($main);
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects($this->never())->method('generate');
    $provider = new CorrelationIdProvider($stack, $uuid);
    $this->assertSame(self::UUID, $provider->getId());
    $main->headers->set('X-Request-Id', 'changed after first log');
    $sub = Request::create('/sub');
    $sub->headers->set('X-Request-Id', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    $stack->push($sub);
    $this->assertSame(self::UUID, (new CorrelationIdProvider($stack, $uuid))->getId());
    $response = new Response();
    (new CorrelationResponseSubscriber($provider))->onResponse(new ResponseEvent(
      $this->createMock(HttpKernelInterface::class), $sub, HttpKernelInterface::SUB_REQUEST, $response,
    ));
    $this->assertSame(self::UUID, $response->headers->get('X-Request-Id'));
    $stack->pop();
    $stack->pop();
    $this->assertNull($provider->getId());
  }

  /**
   * Cached channels/processors never cache the previous request's UUID.
   */
  public function testSeparateRequestsAndCli(): void {
    $stack = new RequestStack();
    $provider = new CorrelationIdProvider($stack, new Php());
    $processor = new CorrelationProcessor($provider);
    $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'Example', [
      'request_id' => '2026-0042', '@request_id' => '2026-0042',
      '%request_id' => '2026-0042', 'correlation_id' => 'forged',
      '@correlation_id' => 'forged',
    ]);
    $cli = $processor($record);
    $this->assertNull($cli->context['correlation_id']);
    $this->assertSame('', $cli->context['@correlation_id']);
    $stack->push(Request::create('/first'));
    $first = $processor($record)->context;
    $this->assertTrue(CorrelationIdProvider::isValid($first['correlation_id']));
    $this->assertSame($first['correlation_id'], $first['@correlation_id']);
    foreach (['request_id', '@request_id', '%request_id'] as $key) {
      $this->assertSame('2026-0042', $first[$key]);
      $this->assertSame('2026-0042', $cli->context[$key]);
    }
    $stack->pop();
    $stack->push(Request::create('/second'));
    $this->assertNotSame($first['correlation_id'], $processor($record)->context['correlation_id']);
    $this->assertSame('forged', $record->context['correlation_id']);
  }

  /**
   * HTTP bootstrap logs bind the same validated UUID when the request arrives.
   */
  public function testHttpBootstrapBindsToMainRequest(): void {
    $original = $_SERVER['HTTP_X_REQUEST_ID'] ?? NULL;
    try {
      $_SERVER['HTTP_X_REQUEST_ID'] = strtoupper(self::UUID);
      $stack = new RequestStack();
      $provider = new CorrelationIdProvider($stack, new Php(), 'fpm-fcgi');
      $this->assertSame(self::UUID, $provider->getId());
      $this->assertSame(self::UUID, $provider->getId());
      $stack->push(Request::create('/'));
      $this->assertSame(self::UUID, $provider->getId());
      $stack->pop();
      $_SERVER['HTTP_X_REQUEST_ID'] = "untrusted\nbody";
      $next = $provider->getId();
      $this->assertTrue(CorrelationIdProvider::isValid($next));
      $this->assertNotSame(self::UUID, $next);
      $stack->push(Request::create('/next'));
      $this->assertSame($next, $provider->getId());
      $this->assertNull((new CorrelationIdProvider(new RequestStack(), new Php(), 'cli'))->getId());
    }
    finally {
      if ($original === NULL) {
        unset($_SERVER['HTTP_X_REQUEST_ID']);
      }
      else {
        $_SERVER['HTTP_X_REQUEST_ID'] = $original;
      }
    }
  }

}
