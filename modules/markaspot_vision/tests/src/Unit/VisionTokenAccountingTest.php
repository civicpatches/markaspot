<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
  private function service(TokenTrackingService $tracking, array $responses, array &$history): ImageProcessingService {
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $factory = $this->getConfigFactoryStub(['markaspot_vision.settings' => [
      'api_url' => self::API['url'],
      'ai_model' => self::API['model'],
    ]]);
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn(new NullLogger());
    return $this->getMockBuilder(ImageProcessingService::class)->setConstructorArgs([
      $client, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class), $logger,
      $this->createMock(StateInterface::class), $this->createMock(TimeInterface::class),
      NULL, NULL, NULL, new AiClientService($client, $factory, $logger, $tracking), $tracking,
    ])->onlyMethods(['wait'])->getMock();
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
    $tracking->expects($this->once())->method('checkLimit')->willReturnCallback(function () use (&$history): bool {
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
    $tracking->method('checkLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage')
      ->with('azure', 'configured-deployment', 'vision', 12, 3, NULL, NULL);
    $history = [];
    $service = $this->service($tracking, [$this->response([
      'model' => '', 'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
    ])], $history);
    $this->send($service);
  }

  /**
   * Screening has its own operation; scoped attribution cannot leak.
   */
  public function testScreeningOperationAndScopeReset(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->exactly(2))->method('checkLimit')->willReturn(TRUE);
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
    $tracking->expects($this->once())->method('checkLimit')->willReturn(FALSE);
    $tracking->expects($this->never())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [], $history);
    self::assertNull($service->screenPrivacy(self::validImage(), 'image/png'));
    self::assertSame([], $history);
  }

  /**
   * Unavailable accounting also blocks the transport.
   */
  public function testUnavailableAccounting(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->expects($this->never())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [], $history);
    (new \ReflectionProperty($service, 'tokenTracking'))->setValue($service, NULL);
    self::assertNull($service->screenPrivacy(self::validImage(), 'image/png'));
    self::assertSame([], $history);
  }

  /**
   * A failed consumed attempt is booked, then the next attempt is checked.
   */
  public function testRetryAccountsEachConsumedAttemptOnce(): void {
    $tracking = $this->createMock(TokenTrackingService::class);
    $history = [];
    $checks = 0;
    $tracking->expects($this->exactly(2))->method('checkLimit')->willReturnCallback(function () use (&$history, &$checks): bool {
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
    $tracking->expects($this->exactly(3))->method('checkLimit')->willReturn(TRUE);
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
    $tracking->expects($this->exactly(2))->method('checkLimit')->willReturnOnConsecutiveCalls(TRUE, FALSE);
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
    $tracking->method('checkLimit')->willReturn(TRUE);
    $tracking->expects($this->once())->method('logUsage');
    $history = [];
    $service = $this->service($tracking, [$this->response(['choices' => []])], $history);
    $this->expectException(\RuntimeException::class);
    $this->send($service, ['response_format' => ['type' => 'json_schema']]);
  }

}
