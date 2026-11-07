<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_ai\Utility\BlurPolicy;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

// The standalone policies are available when the AI module is disabled.
require_once dirname(__DIR__, 4) . '/markaspot_ai/src/Utility/SovereignPolicy.php';
require_once dirname(__DIR__, 4) . '/markaspot_ai/src/Utility/BlurPolicy.php';

/**
 * Detects markaspot_vision running without ENV overrides applied.
 *
 * The vision settings ENV overrides are required to live in settings.php
 * (not local settings) so cloud-image tenants pick them up. When that
 * indirection is missing, the runtime config resolves to whatever was
 * exported into config/sync, which on cloud-image installs is the stock
 * upstream OpenAI host with an empty key. Symptom: the AI categoriser
 * silently fails or hits a generic OpenAI account.
 *
 * Blur wiring follows the shared MARKASPOT_BLUR_REQUIRED policy: when blur is
 * mandatory (strict, or automatic with MARKASPOT_BLUR_URL in the
 * environment) the site switch and the configured URL are ignored, so only
 * the environment URL and key count, and missing ones are an error even
 * without AI analysis or vision settings, because no image is sent at all.
 * Otherwise the site switch decides, as before.
 *
 * @HealthCheck(
 *   id = "vision_env_drift",
 *   label = @Translation("Vision ENV overrides missing"),
 *   severity = "warning",
 *   description = @Translation("Checks vision and blur runtime wiring for AI analysis."),
 *   fix_hint = @Translation("Set MARKASPOT_VISION_* and MARKASPOT_BLUR_* envs."),
 * )
 */
class VisionEnvDriftCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->moduleHandler->moduleExists('markaspot_vision')) {
      return $this->pass('markaspot_vision not enabled; check skipped.');
    }

    // Mandatory blur depends on the environment only, so it is checked even
    // when the vision settings are missing.
    $blurLogger = new NullLogger();
    $blurMode = BlurPolicy::mode($blurLogger);
    $blurRequired = BlurPolicy::isRequired($blurLogger);
    $blurSummary = sprintf(' Blur mode: %s.', $blurMode);
    if (BlurPolicy::hasUnrecognizedValue()) {
      $blurSummary .= ' MARKASPOT_BLUR_REQUIRED has an unrecognised value, so blur is enforced.';
    }
    if ($blurRequired && !BlurPolicy::hasEnvironmentCredentials()) {
      return $this->failWithSeverity(
        'error',
        1,
        sprintf('Blur is mandatory (MARKASPOT_BLUR_REQUIRED mode %s), but ', $blurMode) .
        'MARKASPOT_BLUR_URL or MARKASPOT_BLUR_API_KEY is missing in the ' .
        'environment; no photo is sent for analysis.' . $blurSummary,
      );
    }

    $config = $this->configFactory->get('markaspot_vision.settings');
    if ($config->isNew()) {
      return $this->pass('markaspot_vision.settings not present; check skipped.' . $blurSummary);
    }

    $aiAnalysisRequired = $context['ai_analysis_required'] ?? NULL;
    if (!is_bool($aiAnalysisRequired)) {
      $aiAnalysisRequired = $this->hasAiAnalysisEnabled();
      if ($aiAnalysisRequired === NULL) {
        return $this->failWithSeverity(
          'error',
          1,
          'Unable to determine features.aiAnalysis from jurisdiction config.',
        );
      }
    }
    $failureSeverity = $aiAnalysisRequired ? 'error' : $this->severity();

    if (!SovereignPolicy::enforced()) {
      $apiKey = trim((string) $config->get('api_key'));
      if ($apiKey === '') {
        return $this->failWithSeverity(
          $failureSeverity,
          1,
          'markaspot_vision.settings.api_key is empty at runtime; ' .
          'MARKASPOT_VISION_API_KEY is not active.',
        );
      }

      $apiUrl = trim((string) $config->get('api_url'));
      if ($apiUrl === '') {
        return $this->failWithSeverity(
          $failureSeverity,
          1,
          'markaspot_vision.settings.api_url is empty at runtime; ' .
          'MARKASPOT_VISION_API_URL is not active.',
        );
      }
    }

    if ($aiAnalysisRequired) {
      // Mandatory blur ignores the site switch and the configured URL.
      $blurEnabled = $blurRequired || (bool) $config->get('enable_blur_preprocessing');
      if (!$blurEnabled) {
        return $this->failWithSeverity(
          'error',
          1,
          'features.aiAnalysis is enabled, but markaspot_vision blur ' .
          'preprocessing is disabled at runtime.',
        );
      }

      $blurUrl = $blurRequired
        ? trim((string) getenv('MARKASPOT_BLUR_URL'))
        : trim((string) $config->get('blur_service_url'));
      if ($blurUrl === '') {
        return $this->failWithSeverity(
          'error',
          1,
          'Blur preprocessing is enabled, but ' .
          'markaspot_vision.settings.blur_service_url is empty at runtime.',
        );
      }

      $blurHost = parse_url($blurUrl, PHP_URL_HOST);
      if ($blurHost === 'markaspot-vision') {
        return $this->failWithSeverity(
          'error',
          1,
          'features.aiAnalysis is enabled, but runtime blur config still ' .
          'points at the local markaspot-vision default.',
        );
      }

      $blurKey = trim((string) getenv('MARKASPOT_BLUR_API_KEY'));
      $legacyBlurKey = trim((string) getenv('AI_API_KEY'));
      if ($blurKey === '' && $legacyBlurKey === '') {
        return $this->failWithSeverity(
          'error',
          1,
          'features.aiAnalysis is enabled, but no blur bearer is available ' .
          'at runtime; set MARKASPOT_BLUR_API_KEY.',
        );
      }
    }

    $message = 'markaspot_vision ENV override is active.';
    if ($aiAnalysisRequired) {
      $message = trim((string) getenv('MARKASPOT_BLUR_API_KEY')) !== ''
        ? 'markaspot_vision and blur ENV overrides are active for AI analysis.'
        : 'markaspot_vision ENV override is active; blur bearer uses AI_API_KEY.';
    }
    if (SovereignPolicy::enforced()) {
      $message = 'Legacy Vision connection settings are inactive; see AI provider sovereignty for IONOS status.'
        . ($aiAnalysisRequired ? ' Blur wiring checks passed.' : ' AI analysis does not require blur wiring checks.');
    }
    return $this->pass($message . $blurSummary);
  }

  /**
   * Checks whether any jurisdiction publicly enables AI image analysis.
   *
   * @return bool|null
   *   TRUE when at least one jurisdiction enables or may default-enable AI
   *   analysis, FALSE when all readable configs explicitly disable it, or NULL
   *   when runtime state cannot be inspected reliably.
   */
  private function hasAiAnalysisEnabled(): ?bool {
    if ($this->entityTypeManager === NULL || !$this->moduleHandler->moduleExists('group')) {
      return NULL;
    }

    try {
      $jurisdictionGroupType = trim((string) $this->configFactory
        ->get('markaspot_open311.settings')
        ->get('jurisdiction_group_type')) ?: 'jur';
      $storage = $this->entityTypeManager->getStorage('group');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $jurisdictionGroupType)
        ->execute();
      if (!$ids) {
        return FALSE;
      }

      foreach ($storage->loadMultiple($ids) as $group) {
        if (
          !$group->hasField('field_nuxt_config') ||
          $group->get('field_nuxt_config')->isEmpty()
        ) {
          return TRUE;
        }
        $raw = (string) $group->get('field_nuxt_config')->value;
        $data = json_decode($raw, TRUE);
        if (!is_array($data)) {
          return TRUE;
        }
        if ($this->isAiAnalysisEnabledByConfig($data)) {
          return TRUE;
        }
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    return FALSE;
  }

  /**
   * Determines the AI analysis flag using the frontend-safe default.
   *
   * The public settings endpoint can leave aiAnalysis absent when the
   * jurisdiction config omits it. The Nuxt default config still enables
   * aiAnalysis, so Health must require runtime wiring unless the tenant
   * explicitly disables the flag.
   *
   * @param array<string, mixed> $config
   *   Decoded field_nuxt_config.
   */
  private function isAiAnalysisEnabledByConfig(array $config): bool {
    $features = $config['features'] ?? NULL;
    if (!is_array($features) || !array_key_exists('aiAnalysis', $features)) {
      return TRUE;
    }

    $value = $features['aiAnalysis'];
    if (is_bool($value)) {
      return $value;
    }
    if (is_array($value) && is_bool($value['enabled'] ?? NULL)) {
      return $value['enabled'];
    }

    return TRUE;
  }

}
