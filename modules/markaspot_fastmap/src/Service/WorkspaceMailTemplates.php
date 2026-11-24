<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Resolves the shared plain and branded signup mail templates.
 */
final class WorkspaceMailTemplates {

  /**
   * Shipped configuration, cached per language.
   *
   * @var array<string, array>
   */
  private array $shipped = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Resolves each field from the locale override, shipped locale, or base.
   *
   * @return array{subject: string, body: string}
   *   The resolved template, with empty strings for missing fields.
   */
  public function resolve(string $key, string $langcode): array {
    $override = $this->languageManager instanceof ConfigurableLanguageManagerInterface
      ? $this->languageManager->getLanguageConfigOverride($langcode, 'markaspot_fastmap.mail')->get($key)
      : NULL;
    $base = $this->configFactory->get('markaspot_fastmap.mail')->getOriginal($key, FALSE);
    $sources = [
      $override,
      $langcode !== 'en' ? $this->shipped($langcode)[$key] ?? NULL : NULL,
      $base,
      $this->shipped('en')[$key] ?? NULL,
    ];
    $template = ['subject' => '', 'body' => ''];
    foreach ($sources as $source) {
      foreach ($template as $field => $value) {
        if ($value === '' && is_string($source[$field] ?? NULL)) {
          $template[$field] = $source[$field];
        }
      }
    }
    return $template;
  }

  /**
   * Reads the shipped config for a language.
   */
  public function shipped(string $langcode): array {
    if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $langcode)) {
      return [];
    }
    if (!isset($this->shipped[$langcode])) {
      $directory = dirname(__DIR__, 2) . '/config/install';
      if ($langcode !== 'en') {
        $directory .= '/language/' . $langcode;
      }
      $file = $directory . '/markaspot_fastmap.mail.yml';
      $this->shipped[$langcode] = is_file($file) ? (array) Yaml::decode((string) file_get_contents($file)) : [];
    }
    return $this->shipped[$langcode];
  }

}
