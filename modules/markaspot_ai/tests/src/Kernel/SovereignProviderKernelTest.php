<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Real Drupal configuration and HTTP middleware enforce the operator boundary.
 */
#[Group('markaspot_ai')]
#[RunTestsInSeparateProcesses]
class SovereignProviderKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * This transport test uses real config without booting all feature modules.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Previous operator setting.
   */
  private array $environment = [];

  /**
   * Recorded HTTP requests.
   */
  private array $history = [];

  /**
   * Queued offline responses.
   */
  private MockHandler $handler;

  /**
   * Real provider client.
   */
  private AiClientService $client;

  /**
   * Shared HTTP stack records both blur and AI transports.
   */
  private Client $http;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'MARKASPOT_AI_SOVEREIGN', 'IONOS_AI_API_KEY', 'MARKASPOT_AI_IONOS_KEY',
      'MARKASPOT_BLUR_REQUIRED', 'MARKASPOT_BLUR_URL', 'MARKASPOT_BLUR_API_KEY',
    ] as $key) {
      $this->environment[$key] = getenv($key);
      putenv($key);
    }
    putenv('MARKASPOT_AI_SOVEREIGN=ionos');
    $factory = $this->container->get('config.factory');
    $factory->getEditable('markaspot_ai.settings')->setData([
      'default_provider' => 'azure',
      'pii_redaction' => ['provider' => 'local_nlp'],
      'providers' => ['ionos' => ['api_url' => SovereignPolicy::API_URL, 'api_key' => 'synthetic-key']],
      'token_tracking' => ['enabled' => FALSE],
    ])->save();
    $defaults = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/config/install/markaspot_ai.sovereign.yml'));
    $factory->getEditable('markaspot_ai.sovereign')->setData($defaults)->save();
    $this->handler = new MockHandler();
    $stack = HandlerStack::create($this->handler);
    $stack->push(Middleware::history($this->history));
    $tracking = new TokenTrackingService($this->container->get('database'), $factory, $this->container->get('logger.factory'));
    $this->http = new Client(['handler' => $stack]);
    $this->client = new AiClientService($this->http, $factory, $this->container->get('logger.factory'), $tracking);
    $this->container->set('markaspot_ai.client', $this->client);
    $nlp = $this->createMock(NlpClientService::class);
    $nlp->expects($this->never())->method('isAvailable');
    $nlp->expects($this->never())->method('redactPii');
    $this->container->set('markaspot_ai.nlp_client', $nlp);
    require_once dirname(__DIR__, 3) . '/markaspot_ai.module';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $key => $value) {
      putenv($value === FALSE ? $key : "$key=$value");
    }
    parent::tearDown();
  }

  /**
   * Image-bearing Assist calls gate, send and report the same Vision model.
   */
  #[DataProvider('imageAssistOperations')]
  public function testImageAssistModel(string $operation, string $chatModel, bool $retry = FALSE): void {
    $factory = $this->container->get('config.factory');
    $factory->getEditable('markaspot_ai.sovereign')
      ->set('chat_model', $chatModel)->set('vision_model', 'vision-only')->save();
    $images = [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,c3ludGhldGlj']]];
    $service = $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getNodeImages', 'getJurisdictionPrompt', 'parseServiceDefinition'])->getMock();
    $service->method('getNodeImages')->willReturn($images);
    $service->method('getJurisdictionPrompt')->willReturn('');
    $service->method('parseServiceDefinition')->willReturn([
      'colour' => ['code' => 'colour', 'datatype' => 'string'],
    ]);
    $logged = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(static function ($message, $context = []) use (&$logged): void {
      $logged[] = $context;
    });
    foreach (['aiClient' => $this->client, 'configFactory' => $factory, 'logger' => $logger] as $property => $value) {
      (new \ReflectionProperty($service, $property))->setValue($service, $value);
    }
    $category = $this->createMock(TermInterface::class);
    $category->method('label')->willReturn('Synthetic category');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('entity')->willReturn($category);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(1);
    $node->method('getTitle')->willReturn('Synthetic report');
    $node->method('label')->willReturn('Synthetic report');
    $node->method('hasField')->willReturnCallback(static fn ($name) => $name === 'field_category');
    $node->method('get')->with('field_category')->willReturn($field);
    $content = match ($operation) {
      'fillAttributes' => '{"colour":"red"}',
      'generateDescription' => 'Synthetic description.',
      'assistForm' => '{"body":"Synthetic description."}',
    };
    if ($retry) {
      $refusal = ['content' => '', 'refusal' => 'Synthetic refusal.'];
      $this->handler->append(new Response(200, [], json_encode(['choices' => [['message' => $refusal]]])));
    }
    // Omit response.model: reported identity must still match the sent model.
    $this->handler->append(new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]])));
    $result = match ($operation) {
      'fillAttributes' => $service->fillAttributes($node, TRUE, 'en', FALSE),
      'generateDescription' => $service->generateDescription($node, 'en'),
      'assistForm' => $service->assistForm($node, ['body'], 'en'),
    };
    $this->assertNotNull($result);
    $expectedModel = $retry ? $chatModel : 'vision-only';
    $this->assertSame($expectedModel, $result['model']);
    $this->assertCount($retry ? 2 : 1, $this->history);
    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertSame('vision-only', $payload['model']);
    $this->assertSame($images[0], $payload['messages'][1]['content'][1]);
    if ($retry) {
      $retryPayload = json_decode((string) $this->history[1]['request']->getBody(), TRUE);
      $this->assertSame($chatModel, $retryPayload['model']);
      $this->assertCount(1, $retryPayload['messages'][1]['content']);
      $this->assertSame('text', $retryPayload['messages'][1]['content'][0]['type']);
    }
    $this->assertContains($expectedModel, array_column($logged, '@model'));
  }

  /**
   * An empty or different chat slot cannot gate or mislabel image operations.
   */
  public static function imageAssistOperations(): iterable {
    yield 'refusal retries text' => ['fillAttributes', 'text-only', TRUE];
    foreach (['fillAttributes', 'generateDescription', 'assistForm'] as $operation) {
      foreach (['', 'text-only'] as $model) {
        yield $operation . ':' . $model => [$operation, $model];
      }
    }
  }

  /**
   * Updates add defaults once and retain an explicitly disabled Vision model.
   */
  public function testUpdatePreservesDisabledModel(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $extensions = $this->createMock(ModuleExtensionList::class);
    $extensions->method('getPath')->with('markaspot_ai')->willReturn(dirname(__DIR__, 3));
    $this->container->set('extension.list.module', $extensions);
    $factory = $this->container->get('config.factory');
    $factory->getEditable('markaspot_ai.sovereign')->delete();
    markaspot_ai_update_11904();
    $this->assertSame('BAAI/bge-m3', $factory->get('markaspot_ai.sovereign')->get('embedding_model'));
    $factory->getEditable('markaspot_ai.sovereign')->set('vision_model', '')->save();
    markaspot_ai_update_11904();
    $this->assertSame('', $factory->get('markaspot_ai.sovereign')->get('vision_model'));
  }

  /**
   * A 307 must never forward a citizen payload to a different origin.
   */
  public function testRedirectCannotForwardContent(): void {
    $this->handler->append(new Response(307, ['Location' => 'https://azure.invalid/capture']));
    $failed = FALSE;
    try {
      $this->client->chat(
        [['role' => 'user', 'content' => 'Synthetic citizen report']],
        ['provider' => 'anthropic', 'max_attempts' => 1],
      );
    }
    catch (\Exception) {
      $failed = TRUE;
    }
    $this->assertTrue($failed, 'The redirect must fail the request.');
    $this->assertCount(1, $this->history);
    $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', (string) $this->history[0]['request']->getUri());
    $this->assertFalse($this->history[0]['options']['allow_redirects']);
  }

  /**
   * Real Guzzle dispatch keeps blur separate and rejects blur redirects.
   */
  #[DataProvider('blurResponses')]
  public function testSovereignImagePipeline(bool $required, int $blurStatus): void {
    putenv('MARKASPOT_BLUR_REQUIRED=' . (int) $required);
    putenv('MARKASPOT_BLUR_URL=http://self-hosted-blur:8200/blur');
    putenv('MARKASPOT_BLUR_API_KEY=synthetic-blur-key');
    $path = tempnam(sys_get_temp_dir(), 'sovereign-image-');
    $original = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=');
    // Distinct, valid output bytes let the test detect accidental forwarding of
    // the original. PNG decoders ignore this synthetic trailing marker.
    $blurred = $original . 'synthetic-blurred';
    file_put_contents($path, $original);
    try {
      $this->config('markaspot_vision.settings')->setData([
        'enable_blur_preprocessing' => TRUE,
        'api_url' => 'https://azure.invalid/vision',
        'ai_model' => 'non-ionos-model',
        'image_prompt' => 'Describe the synthetic image.',
      ])->save();
      $style = $this->createMock(ImageStyleInterface::class);
      $style->method('buildUri')->willReturn($path);
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturn($style);
      $storage->method('loadByProperties')->willReturn([]);
      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('getStorage')->willReturn($storage);
      $c = $this->container;
      $images = new ImageProcessingService(
        $this->http, $c->get('config.factory'), $entities, $c->get('file_system'),
        $c->get('logger.factory'), $c->get('state'), $c->get('datetime.time'),
        aiClient: $this->client,
        tokenTracking: new TokenTrackingService($c->get('database'), $c->get('config.factory'), $c->get('logger.factory')),
      );
      $this->handler->append(new Response($blurStatus, [
        'X-Image-Blurred' => 'true', 'Location' => 'https://openai.invalid/capture',
      ], $blurred));
      $this->handler->append(new Response(200, [], json_encode([
        'choices' => [['message' => ['content' => '{"privacy_flag":false,"privacy_issues":[]}']]],
      ])));
      $result = $images->processImages([$path], 'en');
      $this->assertCount($blurStatus === 200 ? 2 : 1, $this->history);
      $this->assertSame('http://self-hosted-blur:8200/blur', (string) $this->history[0]['request']->getUri());
      $this->assertSame('Bearer synthetic-blur-key', $this->history[0]['request']->getHeaderLine('Authorization'));
      $this->assertStringContainsString($original, (string) $this->history[0]['request']->getBody());
      foreach ($this->history as $request) {
        $this->assertFalse($request['options']['allow_redirects']);
      }
      if ($blurStatus !== 200) {
        $this->assertNull($result);
        return;
      }
      $this->assertNotNull($result);
      $this->assertTrue($result['blur_results'][$path]['processed']);
      $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', (string) $this->history[1]['request']->getUri());
      $this->assertSame('Bearer synthetic-key', $this->history[1]['request']->getHeaderLine('Authorization'));
      $payload = json_decode((string) $this->history[1]['request']->getBody(), TRUE);
      $this->assertSame('mistralai/Mistral-Small-24B-Instruct', $payload['model']);
      $this->assertSame('data:image/png;base64,' . base64_encode($blurred), $payload['messages'][1]['content'][1]['image_url']['url']);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Both optional configured blur and required blur must suppress redirects.
   */
  public static function blurResponses(): iterable {
    yield 'optional blur' => [FALSE, 200];
    yield 'required blur' => [TRUE, 200];
    yield 'optional redirect' => [FALSE, 307];
    yield 'required redirect' => [TRUE, 307];
  }

  /**
   * PII ignores local selection, and an unusable IONOS answer has no fallback.
   */
  public function testPiiOverrideAndNoFallback(): void {
    $this->handler->append(new Response(200, [], '{"choices":[{"message":{"content":"unusable"}}]}'));
    $result = _markaspot_ai_detect_names_via_llm('Synthetic report', 'local_nlp', 'wrong-key', 'test');
    $this->assertTrue($result->isFailed());
    $this->assertCount(1, $this->history);
    $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', (string) $this->history[0]['request']->getUri());
    $this->assertSame('Bearer synthetic-key', $this->history[0]['request']->getHeaderLine('Authorization'));
  }

  /**
   * Name detection works with the IONOS-only credentials and shipped defaults.
   */
  public function testSuccessfulPiiUsesIonos(): void {
    $this->handler->append(new Response(200, [], '{"choices":[{"message":{"content":"{\"names_found\":[]}"}}]}'));
    $result = _markaspot_ai_detect_names_via_llm('Synthetic report', 'azure', '', 'test');
    $this->assertFalse($result->isFailed());
    $this->assertCount(1, $this->history);
    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertSame('mistralai/Mistral-Small-24B-Instruct', $payload['model']);
    $this->assertSame('synthetic-key', _markaspot_ai_resolve_provider_key('openai', []));
  }

}
