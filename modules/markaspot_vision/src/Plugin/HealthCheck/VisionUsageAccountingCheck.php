<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports missing vision accounting and the optional vision daily limit.
 *
 * Lives in Vision so discovery does not depend on markaspot_ai being enabled.
 *
 * @HealthCheck(
 *   id = "vision_usage_accounting",
 *   label = @Translation("Vision token accounting"),
 *   severity = "info",
 *   description = @Translation("Reports whether vision usage is accounted for by markaspot_ai."),
 *   fix_hint = @Translation("For vision usage accounting, install markaspot_ai and enable token_tracking.enabled in markaspot_ai.settings. Optionally set token_tracking.vision_daily_limit to cap vision tokens per day."),
 * )
 */
final class VisionUsageAccountingCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected bool $accountingAvailable,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->has('markaspot_ai.token_tracking') && $container->has('markaspot_ai.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->moduleHandler->moduleExists('markaspot_ai')) {
      return $this->failWithSeverity('warning', 1, 'Vision is enabled without markaspot_ai. Analysis continues without usage accounting or daily token limits.');
    }
    if (!$this->accountingAvailable) {
      return $this->failWithSeverity('warning', 1, 'Vision is enabled but markaspot_ai accounting services are unavailable. Analysis continues without usage accounting or daily token limits.');
    }
    if (!$this->configFactory->get('markaspot_ai.settings')->get('token_tracking.enabled')) {
      return $this->failWithSeverity('warning', 1, 'Vision is enabled but markaspot_ai token_tracking.enabled is disabled or unset. Analysis continues without usage accounting or daily token limits.');
    }
    $limit = (int) $this->configFactory->get('markaspot_ai.settings')->get('token_tracking.vision_daily_limit');
    if ($limit <= 0) {
      return $this->pass('Vision usage is booked, no vision daily limit configured.');
    }
    return $this->pass(sprintf('Vision usage is booked, vision daily limit: %d tokens.', $limit));
  }

}
