<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_ai\Utility\BlurPolicy;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

// Health also runs when the AI module is disabled.
require_once dirname(__DIR__, 4) . '/markaspot_ai/src/Utility/SovereignPolicy.php';
require_once dirname(__DIR__, 4) . '/markaspot_ai/src/Utility/BlurPolicy.php';

/**
 * Reports effective profile transports without sending content or credentials.
 *
 * @HealthCheck(
 *   id = "ai_provider_sovereignty",
 *   label = @Translation("AI provider sovereignty"),
 *   severity = "error",
 *   description = @Translation("Reports effective providers and blocked AI features."),
 *   fix_hint = @Translation("Configure IONOS models and its canonical endpoint; no fallback is permitted in sovereign mode."),
 * )
 */
class AiProviderSovereigntyCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'), $container->get('module_handler'));
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $ai = $this->configFactory->get('markaspot_ai.settings');
    $vision = $this->configFactory->get('markaspot_vision.settings');
    $enforced = SovereignPolicy::enforced();
    $default = $ai->get('default_provider') ?? 'openai';
    $features = [];
    if ($this->moduleHandler->moduleExists('markaspot_ai')) {
      foreach (['Text analysis', 'Assist', 'Sentiment'] as $feature) {
        $features[$feature] = ['chat', $default];
      }
      $features['Spam risk'] = ['chat', 'anthropic'];
      $features['PII names'] = ['chat', $ai->get('pii_redaction.provider') ?: $default];
      $features['Embeddings'] = ['embedding', $default];
      $features['Assist photos'] = ['vision', $default];
      if ($ai->get('nlp_service.enabled')) {
        $features['Local NLP'] = ['nlp', 'local_nlp'];
      }
    }
    foreach (['markaspot_mail_inbound' => 'Mail categorization', 'markaspot_fastmap' => 'Workspace provisioning'] as $module => $feature) {
      if ($this->moduleHandler->moduleExists($module)) {
        $features[$feature] = ['chat', $default];
      }
    }
    if ($this->moduleHandler->moduleExists('markaspot_mail_inbound') && $this->moduleHandler->moduleExists('markaspot_vision')) {
      $url = (string) (getenv('MARKASPOT_VISION_API_URL') ?: $vision->get('api_url'));
      $features['Mail photos'] = ['vision', parse_url($url, PHP_URL_HOST) ?: 'unconfigured'];
    }
    $blur = $vision->get('enable_blur_preprocessing') || BlurPolicy::isRequired(new NullLogger());
    if ($this->moduleHandler->moduleExists('markaspot_vision')) {
      $url = (string) (getenv('MARKASPOT_VISION_API_URL') ?: $vision->get('api_url'));
      $host = parse_url($url, PHP_URL_HOST) ?: 'unconfigured';
      $features['Vision'] = ['vision', $host];
      if ($blur) {
        $features['Blur'] = ['blur', 'local_blur'];
      }
    }
    if ($enforced && SovereignPolicy::mode() !== 'ionos' && !$features) {
      return $this->failWithSeverity('warning', 1, 'Unsupported sovereign provider setting; no AI modules enabled.');
    }
    $details = [];
    $errors = 0;
    $blocked = 0;
    $warnings = 0;
    foreach ($features as $feature => [$kind, $configured]) {
      $provider = $kind === 'blur' ? 'self-hosted image privacy service' : ($enforced && $kind !== 'nlp' ? 'ionos' : $configured);
      $status = $kind === 'blur' ? 'allowed' : 'configured';
      if ($kind === 'blur') {
        $required = BlurPolicy::isRequired(new NullLogger());
        $configUrl = (string) $vision->get('blur_service_url');
        $canonical = (string) getenv('MARKASPOT_BLUR_URL');
        if ($required) {
          $url = trim($canonical);
          $source = 'ENV MARKASPOT_BLUR_URL';
        }
        elseif ($configUrl !== '') {
          $url = $configUrl;
          $source = 'config';
        }
        elseif ($canonical !== '') {
          $url = $canonical;
          $source = 'ENV MARKASPOT_BLUR_URL';
        }
        else {
          $url = (string) getenv('VISION_BLUR_URL');
          $source = 'ENV VISION_BLUR_URL';
        }
        // Display the host only, never URL credentials, paths or queries.
        $host = parse_url($url, PHP_URL_HOST);
        $provider .= ': ' . ($host ?: 'unconfigured') . ' (source: ' . $source . ')';
        if ($enforced && (!$host || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE))) {
          $status = 'warning: missing or invalid blur destination';
          $warnings++;
        }
        elseif ($enforced && $source === 'config') {
          $status = 'warning: destination is administrator-configurable';
          $warnings++;
        }
      }
      if ($enforced && $kind !== 'blur') {
        try {
          if (SovereignPolicy::mode() !== 'ionos') {
            $status = 'blocked: unsupported sovereign provider setting';
          }
          else {
            // Endpoint violations remain errors even without credentials.
            if ($kind !== 'nlp') {
              SovereignPolicy::endpoint($this->configFactory, $kind === 'embedding' ? 'embeddings' : 'chat/completions', new NullLogger());
            }
            if (trim(SovereignPolicy::apiKey($this->configFactory)) === '') {
              $status = 'blocked: missing IONOS credential';
            }
            elseif ($kind === 'nlp') {
              $status = 'blocked: no compatible IONOS adapter';
            }
            elseif (trim((string) $this->configFactory->get('markaspot_ai.sovereign')->get($kind . '_model')) === '') {
              $status = 'blocked: missing IONOS model';
            }
          }
        }
        catch (\RuntimeException) {
          $status = 'error: non-IONOS endpoint';
          $errors++;
        }
        if (str_starts_with($status, 'blocked:')) {
          $blocked++;
        }
      }
      $details[] = ['feature' => $feature, 'provider' => $provider, 'status' => $status];
    }
    $message = ($enforced ? 'Operator policy enforced. ' : 'Operator policy not set. ')
      . implode('; ', array_map(static fn (array $row): string => $row['feature'] . ': ' . $row['provider'] . ' (' . $row['status'] . ')', $details));
    if ($errors || $blocked || $warnings) {
      return $this->failWithSeverity($errors ? 'error' : 'warning', $errors + $blocked + $warnings, $message, $details);
    }
    return new HealthCheckResult($this->getPluginId(), $this->label(), TRUE, 'info', 0, $message, details: $details);
  }

}
