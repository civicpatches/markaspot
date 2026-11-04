<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies accounting at the shared vision transport boundary.
 */
#[CoversClass(ImageProcessingService::class)]
#[Group('markaspot_vision')]
final class VisionTokenAccountingTest extends UnitTestCase {

  /**
   * A real 1x1 PNG for header validation at the transport boundary.
   */
  private static function validImage(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=');
  }

  /**
   * API configuration without credentials.
   */
  private const API = [
    'url' => 'https://test.openai.azure.com/openai/deployments/test/chat/completions',
    'model' => 'configured-deployment',
    'headers' => [],
  ];

  /**
   * Creates the real accounting client with an observed token tracker.
   */
  private function service(?TokenTrackingService $tracking, array $responses, array &$history, ?LoggerInterface $channel = NULL, array $methods = ['wait'], ?StateInterface $state = NULL, ?TimeInterface $time = NULL): ImageProcessingService {
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $factory = $this->getConfigFactoryStub(['markaspot_vision.settings' => [
      'api_url' => self::API['url'],
      'ai_model' => self::API['model'],
    ]]);
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn($channel ?? new NullLogger());
    if ($state === NULL) {
      $values = [];
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturnCallback(static function ($key) use (&$values) {
        return $values[$key] ?? NULL;
      });
      $state->method('set')->willReturnCallback(static function ($key, $value) use (&$values): void {
        $values[$key] = $value;
      });
    }
    return $this->getMockBuilder(ImageProcessingService::class)->setConstructorArgs([
      $client, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class), $logger,
      $state, $time ?? $this->createMock(TimeInterface::class),
      NULL, NULL, NULL, $tracking ? new AiClientService($client, $factory, $logger, $tracking) : NULL, $tracking,
    ])->onlyMethods($methods)->getMock();
  }

  /**
   * Calls the actual retry transport without generating an image derivative.
   */
  private function send(ImageProcessingService $service, array $payload = []): array {
    return (new \ReflectionMethod($service, 'sendRequestWithRetry'))->invoke($service, self::API, $payload + ['response_format' => ['type' => 'json_schema']]);
  }

  /**
   * A provider response with a valid privacy verdict and token counters.
   */
  private function response(array $extra = [], int $status = 200): Response {
    return new Response($status, [], json_encode($extra + [
      'model' => 'effective-model-2026',
      'usage' => ['prompt_tokens' => 41, 'completion_tokens' => 9],
      'choices' => [['message' => ['content' => '{"privacy_flag":false}']]],
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Checks before HTTP and books the actual model and a known media ID once.
   */
  public function testCheckBeforeRequestAndEffectiveModel(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $history = [];
    $tracking->expects($this->once())->method('checkVisionLimit')->willReturnCallback(function () use (&$history): bool {
      self::assertCount(0, $history);
      return TRUE;
    });
    $tracking->expects($this->once())->method('logUsage')
      ->with('azure', 'effective-model-2026', 'vision', 41, 9, 'media', 73);
    $service = $this->service($tracking, [$this->response()], $history);
    (new \ReflectionProperty(ImageProcessingService::class, 'accountingOptions'))->setValue($service, [
      'entity_type' => 'media', 'entity_id' => 73,
    ]);
    $this->send($service);
    self::assertCount(1, $history);
  }

  /**
   * Alternate counters and missing model use the configured deployment.
   */
  public function testInputOutputCountersAndModelFallback(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->method('checkVisionLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage')
      ->with('azure', 'configured-deployment', 'vision', 12, 3, NULL, NULL);
    $history = [];
    $service = $this->service($tracking, [$this->response([
      'model' => '', 'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
    ])], $history);
    $this->send($service);
  }

  /**
   * Untrusted model names cannot overflow the usage column or contain controls.
   */
  #[DataProvider('providerModels')]
  public function testBoundedProviderModel(string $reported, string $expected): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->method('checkVisionLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage')
      ->with('azure', $expected, 'vision', 41, 9, NULL, NULL);
    $history = [];
    $this->send($this->service($tracking, [$this->response(['model' => $reported])], $history));
    self::assertCount(1, $history);
  }

  /**
   * Long, mixed and entirely non-ASCII provider deployment names.
   */
  public static function providerModels(): array {
    return [
      'long' => [str_repeat('x', 200), str_repeat('x', 64)],
      'mixed' => ["déploiement 模型\n\t name", 'dploiementname'],
      'non-ASCII' => ['模型ä', 'configured-deployment'],
    ];
  }

  /**
   * Screening has its own operation; scoped attribution cannot leak.
   */
  public function testScreeningOperationAndScopeReset(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->exactly(2))->method('checkVisionLimit')->willReturn(TRUE);
    $bookings = [];
    $tracking->expects($this->exactly(2))->method('logUsage')->willReturnCallback(
      static function (...$args) use (&$bookings): void {
        $bookings[] = $args;
      });
    $history = [];
    $service = $this->service($tracking, [$this->response(), $this->response()], $history);
    self::assertSame(['privacy_flag' => FALSE, 'privacy_issues' => []], $service->screenPrivacy(self::validImage(), 'image/png', [], 73));
    $this->send($service);
    self::assertSame(['azure', 'effective-model-2026', 'vision_screening', 41, 9, 'media', 73], $bookings[0]);
    self::assertSame(['azure', 'effective-model-2026', 'vision', 41, 9, NULL, NULL], $bookings[1]);
  }

  /**
   * Over-limit screening fails closed without HTTP or a usage row.
   */
  public function testOverLimitScreening(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->once())->method('checkVisionLimit')->willReturn(FALSE);
    $tracking->expects($this->never())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [], $history);
    self::assertNull($service->screenPrivacy(self::validImage(), 'image/png'));
    self::assertSame([], $history);
  }

  /**
   * Missing accounting permits analysis and screening, warning once per day.
   */
  public function testUnavailableAccounting(): void {
    $history = [];
    $warnings = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(static function ($message, $context = []) use (&$warnings): void {
      if (str_contains($message, 'markaspot_ai')) {
        $warnings[] = $context['@operation'];
      }
    });
    $service = $this->service(NULL, [$this->response([], 503), $this->response(), $this->response()], $history, $logger);
    $result = $this->send($service);
    self::assertSame('{"privacy_flag":false}', $result['choices'][0]['message']['content']);
    self::assertSame(['privacy_flag' => FALSE, 'privacy_issues' => []], $service->screenPrivacy(self::validImage(), 'image/png'));
    self::assertCount(3, $history);
    self::assertSame(['vision'], $warnings, 'Retries and screening share the daily accounting warning.');
  }

  /**
   * Separate service instances share a site warning and renew it the next day.
   */
  public function testAccountingWarningOncePerSiteDay(): void {
    $now = strtotime('2026-11-04 12:00:00 UTC');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(static function () use (&$now): int {
      return $now;
    });
    $date = NULL;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('markaspot_vision.unaccounted_usage_warning_date')->willReturnCallback(static function () use (&$date) {
      return $date;
    });
    $state->expects($this->exactly(2))->method('set')->with('markaspot_vision.unaccounted_usage_warning_date', $this->anything())->willReturnCallback(static function ($key, $value) use (&$date): void {
      $date = $value;
    });
    $warnings = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(static function ($message) use (&$warnings): void {
      if (str_contains($message, 'without token accounting')) {
        $warnings[] = $message;
      }
    });
    for ($request = 0; $request < 3; $request++) {
      if ($request === 2) {
        $now += 86400;
      }
      $history = [];
      $service = $this->service(NULL, [$this->response()], $history, $logger, ['wait'], $state, $time);
      $this->send($service);
      self::assertCount(1, $history);
      self::assertCount($request === 2 ? 2 : 1, $warnings);
    }
    self::assertSame('2026-11-05', $date);
  }

  /**
   * The real tracker neither queries the limit nor writes usage when disabled.
   */
  #[DataProvider('disabledTracking')]
  public function testDisabledTracking(?bool $enabled): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $database->expects($this->never())->method('insert');
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn(new NullLogger());
    $tracking = new TokenTrackingService($database, $this->getConfigFactoryStub([
      'markaspot_ai.settings' => ['token_tracking' => ['enabled' => $enabled, 'daily_limit' => 1]],
    ]), $logger);
    $history = [];
    $service = $this->service($tracking, [$this->response()], $history);
    $result = $this->send($service);
    self::assertSame('{"privacy_flag":false}', $result['choices'][0]['message']['content']);
    self::assertCount(1, $history);
  }

  /**
   * Explicitly disabled and legacy unset tracking both allow provider calls.
   */
  public static function disabledTracking(): array {
    return ['disabled' => [FALSE], 'unset' => [NULL]];
  }

  /**
   * Unlimited vision still sends and books even with a tiny text budget.
   */
  #[DataProvider('unlimitedVisionLimits')]
  public function testUnlimitedVisionStillBooks(?int $limit): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn(new NullLogger());
    $tracking = $this->getMockBuilder(TokenTrackingService::class)->setConstructorArgs([
      $database,
      $this->getConfigFactoryStub(['markaspot_ai.settings' => ['token_tracking' => [
        'enabled' => TRUE, 'daily_limit' => 1, 'vision_daily_limit' => $limit,
      ]]]),
      $logger,
    ])->onlyMethods(['logUsage'])->getMock();
    $tracking->expects($this->once())->method('logUsage')->with('azure', 'effective-model-2026', 'vision', 41, 9);
    $history = [];
    $service = $this->service($tracking, [$this->response()], $history);
    $result = $this->send($service);
    self::assertSame('{"privacy_flag":false}', $result['choices'][0]['message']['content']);
    self::assertCount(1, $history);
  }

  /**
   * Both legacy missing configuration and explicit zero disable vision limits.
   */
  public static function unlimitedVisionLimits(): array {
    return ['missing' => [NULL], 'zero' => [0]];
  }

  /**
   * Interactive media attribution keeps retries; the queue opts out explicitly.
   */
  #[DataProvider('retryPolicies')]
  public function testScreenedRetryPolicy(bool $interactive, int $attempts): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->exactly($attempts))->method('checkVisionLimit')->willReturn(TRUE);
    $tracking->expects($this->exactly($attempts))->method('logUsage')
      ->with('azure', 'effective-model-2026', 'vision', 41, 9, 'media', 73);
    $history = [];
    $service = $this->service($tracking, [
      $this->response([], 503), $this->response([], 503), $this->response(),
    ], $history, NULL, ['wait', 'processImages']);
    $service->method('processImages')->willReturnCallback(fn () => $this->send($service));
    try {
      if ($interactive) {
        self::assertNotNull($service->processScreenedImages([], 'en', 41, NULL, 73));
      }
      else {
        $service->processScreenedImages([], 'en', 41, NULL, 73, FALSE);
        self::fail('Queue enrichment must not retry uncertain submissions.');
      }
    }
    catch (\RuntimeException $e) {
      self::assertFalse($interactive);
      self::assertSame(503, $e->getCode());
    }
    self::assertCount($attempts, $history);
    self::assertSame([], (new \ReflectionProperty(ImageProcessingService::class, 'accountingOptions'))->getValue($service));
  }

  /**
   * Citizen and staff share the interactive path; intake explicitly opts out.
   */
  public static function retryPolicies(): array {
    return ['interactive' => [TRUE, 3], 'queue' => [FALSE, 1]];
  }

  /**
   * A failed consumed attempt is booked, then the next attempt is checked.
   */
  public function testRetryAccountsEachConsumedAttemptOnce(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $history = [];
    $checks = 0;
    $tracking->expects($this->exactly(2))->method('checkVisionLimit')->willReturnCallback(function () use (&$history, &$checks): bool {
      self::assertCount($checks++, $history);
      return TRUE;
    });
    $tracking->expects($this->exactly(2))->method('logUsage')
      ->with('azure', 'effective-model-2026', 'vision', 41, 9, NULL, NULL);
    $service = $this->service($tracking, [$this->response([], 503), $this->response()], $history);
    $this->send($service);
    self::assertCount(2, $history);
  }

  /**
   * Transport failures and rejected parameters without usage book nothing.
   */
  public function testUnconsumedRetryAndParameterRepair(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->exactly(3))->method('checkVisionLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [
      new ConnectException('Offline', new Request('POST', self::API['url'])),
      new Response(400, [], '{"error":{"param":"max_tokens","code":"unsupported_parameter","message":"Unsupported parameter: max_tokens"}}'),
      $this->response(),
    ], $history);
    $this->send($service, ['max_tokens' => 300]);
    self::assertCount(3, $history);
    self::assertSame(['response_format' => ['type' => 'json_schema'], 'max_completion_tokens' => 300], json_decode((string) $history[2]['request']->getBody(), TRUE));
  }

  /**
   * Usage from a failed attempt can exhaust the budget before its retry.
   */
  public function testRetryStopsWhenBudgetIsExhausted(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->exactly(2))->method('checkVisionLimit')->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $tracking->expects($this->once())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [$this->response([], 503)], $history);
    try {
      $this->send($service);
      self::fail('The retry must be blocked.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('daily token limit', $e->getMessage());
    }
    self::assertCount(1, $history);
  }

  /**
   * A paid response is still accounted for when content validation fails.
   */
  public function testInvalidContentIsBooked(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->method('checkVisionLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [$this->response(['choices' => []])], $history);
    $this->expectException(\RuntimeException::class);
    $this->send($service, ['response_format' => ['type' => 'json_schema']]);
  }

}
