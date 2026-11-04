<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Records every transport call across hostile config and runtime overrides.
 */
#[Group('markaspot_ai')]
class SovereignTransportTest extends UnitTestCase {

  /**
   * A real 1x1 PNG for header validation at the transport boundary.
   */
  private static function validImage(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=');
  }

  /**
   * Saved process environment.
   */
  private array $environment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'MARKASPOT_AI_SOVEREIGN', 'MARKASPOT_AI_API_URL', 'MARKASPOT_AI_API_KEY',
      'MARKASPOT_VISION_API_URL', 'MARKASPOT_VISION_API_KEY', 'IONOS_AI_API_KEY',
      'MARKASPOT_AI_IONOS_KEY', 'MARKASPOT_BLUR_REQUIRED',
      'MARKASPOT_BLUR_URL', 'MARKASPOT_BLUR_API_KEY', 'AI_API_KEY', 'VISION_BLUR_URL',
    ] as $key) {
      $this->environment[$key] = getenv($key);
      putenv($key);
    }
    putenv('MARKASPOT_AI_SOVEREIGN=ionos');
    putenv('MARKASPOT_AI_API_URL=https://azure.invalid/v1');
    putenv('MARKASPOT_VISION_API_URL=https://openai.invalid/v1');
    putenv('MARKASPOT_AI_API_KEY=wrong-generic-key');
    putenv('MARKASPOT_VISION_API_KEY=wrong-vision-key');
    putenv('MARKASPOT_BLUR_REQUIRED=0');
    new Settings([]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $key => $value) {
      putenv($value === FALSE ? $key : "$key=$value");
    }
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Configuration intentionally disagrees with the operator policy.
   */
  private function factory(array $models = [], string $url = SovereignPolicy::API_URL): ConfigFactoryInterface {
    return $this->getConfigFactoryStub([
      'markaspot_ai.settings' => [
        'default_provider' => 'anthropic',
        'providers' => [
          'ionos' => ['api_url' => $url, 'api_key' => 'ionos-key'],
          'anthropic' => ['api_url' => 'https://anthropic.invalid/v1', 'chat_model' => 'claude-test'],
        ],
      ],
      'markaspot_ai.sovereign' => $models + [
        'chat_model' => 'ionos-chat',
        'vision_model' => 'ionos-vision',
        'embedding_model' => 'ionos-embed',
      ],
      'markaspot_vision.settings' => [
        'api_url' => 'https://azure.invalid/vision',
        'ai_model' => 'azure-model',
        'enable_blur_preprocessing' => TRUE,
      ],
    ]);
  }

  /**
   * Creates the real AI client with a recorded HTTP boundary.
   */
  private function client(ClientInterface $http, ConfigFactoryInterface $factory): AiClientService {
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->method('checkLimit')->willReturn(TRUE);
    $tracking->method('checkVisionLimit')->willReturn(TRUE);
    return new AiClientService($http, $factory, $this->loggerFactory(), $tracking);
  }

  /**
   * Supplies a logger without storing any synthetic citizen text.
   */
  private function loggerFactory(): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    return $factory;
  }

  /**
   * All caller overrides and generic ENV endpoints lose to IONOS.
   */
  #[DataProvider('operations')]
  public function testOnlyIonosReceivesContent(string $operation, string $provider, bool $image): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options) use ($operation, $image): Response {
      $this->assertSame(SovereignPolicy::API_URL . ($operation === 'embed' ? '/embeddings' : '/chat/completions'), $url);
      $this->assertFalse($options['allow_redirects']);
      $this->assertSame('Bearer ionos-key', $options['headers']['Authorization']);
      $this->assertSame($operation === 'embed' ? 'ionos-embed' : ($image ? 'ionos-vision' : 'ionos-chat'), $options['json']['model']);
      return new Response(200, [], '{"choices":[{"message":{"content":"OK"}}],"data":[],"usage":{"total_tokens":1}}');
    });
    $client = $this->client($http, $this->factory());
    $options = ['provider' => $provider, 'model' => 'non-ionos-model'];
    if ($operation === 'embed') {
      $client->embed('Synthetic report', $options);
    }
    else {
      $content = $image ? [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AA==']]] : 'Synthetic report';
      $client->chat([['role' => 'user', 'content' => $content]], $options);
    }
  }

  /**
   * Includes every legacy provider and the image-bearing Assist path.
   */
  public static function operations(): iterable {
    foreach (['openai', 'azure', 'anthropic', 'local_nlp', 'ionos'] as $provider) {
      yield ['chat', $provider, FALSE];
      yield ['chat', $provider, TRUE];
      yield ['embed', $provider, FALSE];
    }
  }

  /**
   * A poisoned IONOS config is blocked before the first request.
   */
  public function testNonIonosEndpointIsBlocked(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $this->expectExceptionMessage('Non-IONOS endpoint blocked');
    $this->client($http, $this->factory([], 'https://azure.invalid/v1'))->chat([]);
  }

  /**
   * Empty models cannot fall back to generic OpenAI defaults.
   */
  #[DataProvider('missingModels')]
  public function testMissingModelBlocks(string $feature): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $client = $this->client($http, $this->factory([$feature . '_model' => '']));
    $this->expectExceptionMessage('No IONOS model configured for ' . $feature);
    if ($feature === 'embedding') {
      $client->embed('Synthetic report');
    }
    else {
      $content = $feature === 'vision' ? [['type' => 'image_url']] : 'Synthetic report';
      $client->chat([['role' => 'user', 'content' => $content]]);
    }
  }

  /**
   * Supported model slots.
   */
  public static function missingModels(): iterable {
    yield ['chat'];
    yield ['vision'];
    yield ['embedding'];
  }

  /**
   * Settings is an operator input; unknown values never disable protection.
   */
  public function testSettingsAndInvalidMode(): void {
    putenv('MARKASPOT_AI_SOVEREIGN');
    new Settings(['markaspot_ai_sovereign' => 'ionos']);
    $this->assertTrue(SovereignPolicy::enforced());
    putenv('MARKASPOT_AI_SOVEREIGN=typo');
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $this->expectExceptionMessage('Unsupported sovereign provider setting');
    $this->client($http, $this->factory())->chat([]);
  }

  /**
   * Direct local NLP calls cannot bypass the primary PII policy.
   */
  public function testLocalNlpIsBlocked(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $nlp = new NlpClientService($http, $this->factory(), $this->loggerFactory());
    $this->assertFalse($nlp->isAvailable());
    $this->expectExceptionMessage('Local NLP is unavailable');
    $nlp->redactPii('Synthetic report');
  }

  /**
   * Builds the vision transport without unrelated filesystem dependencies.
   */
  private function vision(ClientInterface $http, ConfigFactoryInterface $factory): ImageProcessingService {
    $vision = (new \ReflectionClass(ImageProcessingService::class))->newInstanceWithoutConstructor();
    foreach (['httpClient' => $http, 'configFactory' => $factory, 'logger' => $this->createMock(LoggerInterface::class)] as $property => $value) {
      (new \ReflectionProperty($vision, $property))->setValue($vision, $value);
    }
    $tracking = $this->createMock(TokenTrackingService::class);
    $tracking->method('checkLimit')->willReturn(TRUE);
    $tracking->method('checkVisionLimit')->willReturn(TRUE);
    (new \ReflectionProperty($vision, 'tokenTracking'))->setValue($vision, $tracking);
    (new \ReflectionProperty($vision, 'aiClient'))->setValue($vision, new AiClientService($http, $factory, $this->loggerFactory(), $tracking));
    return $vision;
  }

  /**
   * Self-hosted privacy preprocessing remains independent of the AI provider.
   */
  public function testBlurAndPrivacyScreeningUseSeparateTransports(): void {
    putenv('MARKASPOT_BLUR_URL=http://self-hosted-blur:8200/blur');
    putenv('MARKASPOT_BLUR_API_KEY=blur-key');
    $calls = [];
    $http = $this->createMock(Client::class);
    $http->expects($this->exactly(2))->method('post')->willReturnCallback(function ($url, $options) use (&$calls): Response {
      $calls[] = $url;
      $this->assertFalse($options['allow_redirects']);
      if (count($calls) === 1) {
        $this->assertSame('http://self-hosted-blur:8200/blur', $url);
        $this->assertSame('Bearer blur-key', $options['headers']['Authorization']);
        $this->assertSame(self::validImage(), $options['multipart'][0]['contents']);
        return new Response(200, ['X-Image-Blurred' => 'true'], self::validImage() . 'synthetic-blurred');
      }
      $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', $url);
      $this->assertSame('Bearer ionos-key', $options['headers']['Authorization']);
      $this->assertSame('ionos-vision', $options['json']['model']);
      $this->assertSame('data:image/png;base64,' . base64_encode(self::validImage() . 'synthetic-blurred'), $options['json']['messages'][1]['content'][1]['image_url']['url']);
      return new Response(200, [], json_encode(['choices' => [['message' => ['content' => '{"privacy_flag":false,"privacy_issues":[]}']]]]));
    });
    $http->expects($this->never())->method('request');
    $vision = $this->vision($http, $this->factory());
    $blur = $vision->blurSensitiveAreas(self::validImage(), 'image/png');
    $this->assertTrue($blur['processed']);
    $this->assertSame(['privacy_flag' => FALSE, 'privacy_issues' => []], $vision->screenPrivacy($blur['contents'], 'image/png'));
    $this->assertSame(['http://self-hosted-blur:8200/blur', SovereignPolicy::API_URL . '/chat/completions'], $calls);
  }

  /**
   * The separate vision transport uses IONOS and disables redirect forwarding.
   */
  public function testVisionTransport(): void {
    $http = $this->createMock(Client::class);
    $http->expects($this->once())->method('post')->willReturnCallback(function ($url, $options): Response {
      $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', $url);
      $this->assertFalse($options['allow_redirects']);
      $this->assertSame('Bearer ionos-key', $options['headers']['Authorization']);
      $content = json_encode([
        'category' => 0, 'is_reportable_issue' => FALSE, 'description' => 'synthetic',
        'alt_text' => [], 'hazard_flag' => FALSE, 'hazard_issues' => [],
        'privacy_flag' => FALSE, 'privacy_issues' => [], 'privacy_image_flags' => [],
        'hazard_level' => 0, 'hazard_category' => NULL, 'attributes' => [],
      ]);
      return new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]));
    });
    $factory = $this->factory();
    $vision = $this->vision($http, $factory);
    $config = (new \ReflectionMethod($vision, 'getApiConfig'))->invoke($vision, $factory->get('markaspot_vision.settings'));
    $this->assertSame('ionos-vision', $config['model']);
    (new \ReflectionMethod($vision, 'sendRequestWithRetry'))->invoke($vision, $config, ['messages' => []]);
  }

  /**
   * Provisioning must recognize IONOS-only keys and ignore generic keys.
   */
  public function testProvisioningCredentialPreflight(): void {
    $factories = [
      1 => $this->factory(),
      0 => $this->getConfigFactoryStub(['markaspot_ai.settings' => []]),
    ];
    foreach ($factories as $expected => $factory) {
      $service = (new \ReflectionClass(WorkspaceProvisioningService::class))->newInstanceWithoutConstructor();
      (new \ReflectionProperty($service, 'aiClient'))->setValue($service, $this->createMock(AiClientService::class));
      (new \ReflectionProperty($service, 'configFactory'))->setValue($service, $factory);
      $method = new \ReflectionMethod($service, 'isAiConfigured');
      $this->assertSame((bool) $expected, $method->invoke($service));
    }
  }

  /**
   * Without policy, legacy provider, model and URL overrides still apply.
   */
  public function testUnrestrictedBehaviorIsUnchanged(): void {
    putenv('MARKASPOT_AI_SOVEREIGN');
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options): Response {
      $this->assertSame('https://azure.invalid/v1/chat/completions', $url);
      $this->assertSame('legacy-model', $options['json']['model']);
      $this->assertArrayNotHasKey('allow_redirects', $options);
      return new Response(200, [], '{"choices":[{"message":{"content":"OK"}}]}');
    });
    $this->client($http, $this->factory())->chat([], ['provider' => 'openai', 'model' => 'legacy-model']);
  }

}
