<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use Drupal\markaspot_health\Plugin\HealthCheck\AiProviderSovereigntyCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Effective providers, unavailable features, and invalid origins are distinct.
 */
#[Group('markaspot_health')]
class AiProviderSovereigntyCheckTest extends UnitTestCase {

  /**
   * Reports all features without exposing credentials or making HTTP requests.
   */
  #[DataProvider('states')]
  public function testStatus(string $mode, string $url, string $visionModel, bool $blur, string $severity, bool $passed, bool $nlp = FALSE): void {
    $saved = [];
    foreach ([
      'MARKASPOT_AI_SOVEREIGN' => $mode,
      'MARKASPOT_BLUR_REQUIRED' => '0',
      'MARKASPOT_VISION_API_URL' => '',
      'IONOS_AI_API_KEY' => '',
      'MARKASPOT_AI_IONOS_KEY' => '',
      'MARKASPOT_BLUR_URL' => 'https://blur.example/blur',
    ] as $key => $value) {
      $saved[$key] = getenv($key);
      putenv("$key=$value");
    }
    new Settings([]);
    try {
      $factory = $this->getConfigFactoryStub([
        'markaspot_ai.settings' => [
          'default_provider' => 'azure',
          'nlp_service' => ['enabled' => $nlp],
          'pii_redaction' => ['provider' => 'local_nlp'],
          'providers' => ['ionos' => ['api_url' => $url, 'api_key' => 'synthetic-key']],
        ],
        'markaspot_ai.sovereign' => [
          'chat_model' => 'chat',
          'embedding_model' => 'embed',
          'vision_model' => $visionModel,
        ],
        'markaspot_vision.settings' => ['api_url' => 'https://vision.invalid/v1', 'enable_blur_preprocessing' => $blur],
      ]);
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturn(TRUE);
      $check = new AiProviderSovereigntyCheck([], 'ai_provider_sovereignty', ['label' => 'AI provider sovereignty'], $factory, $modules);
      $result = $check->run();
      $this->assertSame($passed, $result->passed);
      $this->assertSame($severity, $result->severity);
      $this->assertCount(11 + (int) $blur + (int) $nlp, $result->details);
      foreach ($result->details as $row) {
        if ($row['feature'] === 'Blur') {
          $this->assertSame('self-hosted image privacy service: blur.example (source: ENV MARKASPOT_BLUR_URL)', $row['provider']);
          $this->assertSame('allowed', $row['status']);
          $this->assertStringContainsString('Blur: ' . $row['provider'] . ' (allowed)', $result->message);
        }
        elseif ($row['feature'] === 'Local NLP') {
          $this->assertSame('local_nlp', $row['provider']);
          $this->assertStringStartsWith('blocked:', $row['status']);
        }
        elseif ($mode !== '') {
          $this->assertSame('ionos', $row['provider']);
        }
      }
      $this->assertStringContainsString('PII names: ' . ($mode === '' ? 'local_nlp' : 'ionos'), $result->message);
      $this->assertStringContainsString('Vision:', $result->message);
      $this->assertStringContainsString('Spam risk: ' . ($mode === '' ? 'anthropic' : 'ionos'), $result->message);
    }
    finally {
      foreach ($saved as $key => $value) {
        putenv($value === FALSE ? $key : "$key=$value");
      }
    }
  }

  /**
   * Generic credentials never make an unconfigured IONOS provider ready.
   */
  #[DataProvider('missingCredentials')]
  public function testMissingIonosCredentialBlocksEveryAiFeature(string $credential, string $endpoint, string $severity): void {
    $saved = [];
    foreach ([
      'MARKASPOT_AI_SOVEREIGN' => 'ionos',
      'IONOS_AI_API_KEY' => '',
      'MARKASPOT_AI_IONOS_KEY' => '',
      'MARKASPOT_AI_PROVIDER' => 'azure',
      'MARKASPOT_AI_API_KEY' => 'synthetic-azure-key',
      'MARKASPOT_BLUR_REQUIRED' => '0',
    ] as $key => $value) {
      $saved[$key] = getenv($key);
      putenv("$key=$value");
    }
    try {
      $factory = $this->getConfigFactoryStub([
        'markaspot_ai.settings' => [
          'providers' => ['ionos' => ['api_url' => $endpoint, 'api_key' => $credential]],
          'nlp_service' => ['enabled' => TRUE],
        ],
        'markaspot_ai.sovereign' => ['chat_model' => 'chat', 'vision_model' => 'vision', 'embedding_model' => 'embed'],
        'markaspot_vision.settings' => [],
      ]);
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturn(TRUE);
      $result = (new AiProviderSovereigntyCheck([], 'ai_provider_sovereignty', [], $factory, $modules))->run();
      $this->assertFalse($result->passed);
      $this->assertSame($severity, $result->severity);
      $this->assertCount(12, $result->details);
      foreach ($result->details as $row) {
        $expected = $severity === 'error' && $row['feature'] !== 'Local NLP' ? 'error: non-IONOS endpoint' : 'blocked: missing IONOS credential';
        $this->assertSame($expected, $row['status'], $row['feature']);
      }
      $this->assertStringNotContainsString('synthetic-azure-key', $result->message);
    }
    finally {
      foreach ($saved as $key => $value) {
        putenv($value === FALSE ? $key : "$key=$value");
      }
    }
  }

  /**
   * Missing keys do not hide endpoint violations; whitespace is not a key.
   */
  public static function missingCredentials(): iterable {
    yield 'empty' => ['', SovereignPolicy::API_URL, 'warning'];
    yield 'whitespace' => ['   ', SovereignPolicy::API_URL, 'warning'];
    yield 'empty with invalid endpoint' => ['', 'https://wrong.example/v1', 'error'];
  }

  /**
   * Blur labels follow runtime precedence without displaying URL secrets.
   */
  #[DataProvider('blurSources')]
  public function testBlurSource(string $required, string $configUrl, string $canonical, string $legacy, string $host, string $source, bool $passed, ?string $status = NULL): void {
    $saved = [];
    foreach ([
      'MARKASPOT_AI_SOVEREIGN' => 'ionos',
      'MARKASPOT_BLUR_REQUIRED' => $required,
      'MARKASPOT_BLUR_URL' => $canonical,
      'VISION_BLUR_URL' => $legacy,
    ] as $key => $value) {
      $saved[$key] = getenv($key);
      putenv("$key=$value");
    }
    try {
      $factory = $this->getConfigFactoryStub([
        'markaspot_ai.settings' => [
          'providers' => ['ionos' => [
            'api_url' => SovereignPolicy::API_URL,
            'api_key' => 'synthetic-key',
          ]],
        ],
        'markaspot_ai.sovereign' => ['vision_model' => 'vision'],
        'markaspot_vision.settings' => ['enable_blur_preprocessing' => TRUE, 'blur_service_url' => $configUrl],
      ]);
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturnCallback(static fn ($module) => $module === 'markaspot_vision');
      $result = (new AiProviderSovereigntyCheck([], 'ai_provider_sovereignty', [], $factory, $modules))->run();
      $this->assertSame($passed, $result->passed);
      $this->assertSame($passed ? 'info' : 'warning', $result->severity);
      $row = array_column($result->details, NULL, 'feature')['Blur'];
      $this->assertSame("self-hosted image privacy service: $host (source: $source)", $row['provider']);
      $this->assertSame($status ?? ($passed ? 'allowed' : 'warning: destination is administrator-configurable'), $row['status']);
      $this->assertStringNotContainsString('secret', $result->message);
    }
    finally {
      foreach ($saved as $key => $value) {
        putenv($value === FALSE ? $key : "$key=$value");
      }
    }
  }

  /**
   * Required and optional destinations match ImageProcessingService resolution.
   */
  public static function blurSources(): iterable {
    $config = 'https://user:secret@config.example/private?key=secret';
    $env = 'https://env.example/private?key=secret';
    yield 'required ignores config' => ['1', $config, $env, '', 'env.example', 'ENV MARKASPOT_BLUR_URL', TRUE];
    yield 'auto required ignores config' => ['', $config, $env, '', 'env.example', 'ENV MARKASPOT_BLUR_URL', TRUE];
    yield 'optional config wins' => ['0', $config, $env, '', 'config.example', 'config', FALSE];
    yield 'canonical fallback' => ['0', '', $env, '', 'env.example', 'ENV MARKASPOT_BLUR_URL', TRUE];
    yield 'required empty' => [
      '1',
      $config,
      '',
      '',
      'unconfigured',
      'ENV MARKASPOT_BLUR_URL',
      FALSE,
      'warning: missing or invalid blur destination',
    ];
    yield 'non HTTP scheme' => [
      '1',
      '',
      'ftp://env.example/blur',
      '',
      'env.example',
      'ENV MARKASPOT_BLUR_URL',
      FALSE,
      'warning: missing or invalid blur destination',
    ];
    yield 'malformed ENV' => [
      '1',
      '',
      'not-a-url',
      '',
      'unconfigured',
      'ENV MARKASPOT_BLUR_URL',
      FALSE,
      'warning: missing or invalid blur destination',
    ];
    yield 'legacy fallback' => [
      '0',
      '',
      '',
      'https://legacy.example/secret',
      'legacy.example',
      'ENV VISION_BLUR_URL',
      TRUE,
    ];
  }

  /**
   * Invalid operator values warn even without enabled AI modules.
   */
  public function testInvalidPolicyWithoutFeatures(): void {
    $previous = getenv('MARKASPOT_AI_SOVEREIGN');
    putenv('MARKASPOT_AI_SOVEREIGN=typo');
    try {
      $factory = $this->getConfigFactoryStub(['markaspot_ai.settings' => [], 'markaspot_vision.settings' => []]);
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturn(FALSE);
      $check = new AiProviderSovereigntyCheck([], 'ai_provider_sovereignty', [], $factory, $modules);
      $this->assertSame('warning', $check->run()->severity);
      $this->assertFalse($check->run()->passed);
    }
    finally {
      putenv($previous === FALSE ? 'MARKASPOT_AI_SOVEREIGN' : "MARKASPOT_AI_SOVEREIGN=$previous");
    }
  }

  /**
   * Legacy config is dormant; invalid IONOS endpoints remain errors.
   */
  public static function states(): iterable {
    yield 'enforced' => ['ionos', SovereignPolicy::API_URL, 'vision', FALSE, 'info', TRUE];
    yield 'missing vision' => ['ionos', SovereignPolicy::API_URL, '', FALSE, 'warning', FALSE];
    yield 'blur allowed' => ['ionos', SovereignPolicy::API_URL, 'vision', TRUE, 'info', TRUE];
    yield 'blur allowed without model' => ['ionos', SovereignPolicy::API_URL, '', TRUE, 'warning', FALSE];
    yield 'blur allowed NLP blocked' => ['ionos', SovereignPolicy::API_URL, 'vision', TRUE, 'warning', FALSE, TRUE];
    yield 'blur allowed wrong AI endpoint' => ['ionos', 'https://azure.invalid/v1', 'vision', TRUE, 'error', FALSE];
    yield 'wrong endpoint' => ['ionos', 'https://azure.invalid/v1', 'vision', FALSE, 'error', FALSE];
    yield 'invalid mode' => ['typo', SovereignPolicy::API_URL, 'vision', FALSE, 'warning', FALSE];
    yield 'legacy' => ['', SovereignPolicy::API_URL, '', FALSE, 'info', TRUE];
  }

}
