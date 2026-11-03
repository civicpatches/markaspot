<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * Operator policy shared by all profile AI transports, even without AI enabled.
 */
final class SovereignPolicy {

  /**
   * The only permitted origin and API prefix in sovereign mode.
   */
  public const API_URL = 'https://openai.inference.de-txl.ionos.com/v1';

  /**
   * Reads ENV first, then the settings.php equivalent, never editable config.
   */
  public static function mode(): string {
    $env = trim((string) getenv('MARKASPOT_AI_SOVEREIGN'));
    return strtolower($env !== '' ? $env : trim((string) Settings::get('markaspot_ai_sovereign', '')));
  }

  /**
   * Unknown non-empty values also enforce the boundary, failing closed.
   */
  public static function enforced(): bool {
    return self::mode() !== '';
  }

  /**
   * Overrides both persisted provider selections and per-call overrides.
   */
  public static function provider(string $configured): string {
    return self::enforced() ? 'ionos' : $configured;
  }

  /**
   * Returns a configured model or blocks without including submitted content.
   */
  public static function model(ConfigFactoryInterface $factory, string $feature, LoggerInterface $logger): string {
    if (self::mode() !== 'ionos') {
      self::block($logger, 'Unsupported sovereign provider setting.');
    }
    $model = trim((string) $factory->get('markaspot_ai.sovereign')->get($feature . '_model'));
    if ($model === '') {
      self::block($logger, 'No IONOS model configured for ' . $feature . '.');
    }
    return $model;
  }

  /**
   * Resolves the existing IONOS provider, ignoring generic URL overrides.
   */
  public static function endpoint(ConfigFactoryInterface $factory, string $operation, LoggerInterface $logger): string {
    $base = rtrim(trim((string) $factory->get('markaspot_ai.settings')->get('providers.ionos.api_url')), '/');
    $url = $base . '/' . $operation;
    self::assertEndpoint($url, $logger);
    return $url;
  }

  /**
   * Checks the final URL immediately before HTTP dispatch.
   */
  public static function assertEndpoint(string $url, LoggerInterface $logger): void {
    $allowed = [self::API_URL . '/chat/completions', self::API_URL . '/embeddings'];
    if (self::enforced() && (self::mode() !== 'ionos' || !in_array($url, $allowed, TRUE))) {
      self::block($logger, 'Non-IONOS endpoint blocked.');
    }
  }

  /**
   * Resolves only credentials explicitly assigned to IONOS.
   */
  public static function apiKey(ConfigFactoryInterface $factory): string {
    return (string) (getenv('IONOS_AI_API_KEY') ?: getenv('MARKASPOT_AI_IONOS_KEY') ?: $factory->get('markaspot_ai.settings')->get('providers.ionos.api_key') ?: '');
  }

  /**
   * Rejects transports for which no compatible IONOS adapter exists.
   */
  public static function block(LoggerInterface $logger, string $reason): never {
    $message = 'AI provider sovereignty: ' . $reason;
    $logger->error($message);
    throw new \RuntimeException($message);
  }

}
