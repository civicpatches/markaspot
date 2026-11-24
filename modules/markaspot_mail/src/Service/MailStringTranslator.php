<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Uses shipped interface translations for selected mails without Locale.
 */
final class MailStringTranslator {

  /**
   * Singular translations keyed by module, language and source string.
   *
   * @var array<string, array<string, array<string, string>>>
   */
  private array $catalogues = [];

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly TranslationInterface $stringTranslation,
  ) {}

  /**
   * Translates a mail string, using Drupal's normal placeholder escaping.
   */
  public function translate(string $source, array $args, string $langcode, string $module = 'markaspot_mail'): string {
    if (!$this->usesShippedTranslations()) {
      return (string) $this->stringTranslation->translate($source, $args, ['langcode' => $langcode]);
    }
    $this->catalogues[$module][$langcode] ??= $this->readCatalogue($module, $langcode);
    $translation = $this->catalogues[$module][$langcode][$source] ?? $source;
    return (string) new FormattableMarkup($translation, $args);
  }

  /**
   * Whether runtime mail translations come from the bundled catalogues.
   */
  public function usesShippedTranslations(): bool {
    return !$this->moduleHandler->moduleExists('locale');
  }

  /**
   * Reads the singular strings with no gettext context.
   *
   * @return array<string, string>
   *   Translations keyed by source string.
   */
  private function readCatalogue(string $module, string $langcode): array {
    if ($langcode === 'en' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $langcode)) {
      return [];
    }
    $directory = match ($module) {
      'markaspot_mail' => dirname(__DIR__, 2),
      'service_request' => dirname(__DIR__, 3) . '/service_request',
      default => NULL,
    };
    if ($directory === NULL) {
      return [];
    }
    $file = "$directory/translations/$langcode.po";
    if (!is_readable($file)) {
      $file = "$directory/translations/" . explode('-', $langcode)[0] . '.po';
    }
    if (!is_readable($file)) {
      return [];
    }
    $reader = new PoStreamReader();
    $reader->setURI($file);
    $reader->setLangcode($langcode);
    $translations = [];
    try {
      $reader->open();
      while ($item = $reader->readItem()) {
        $translation = $item->getTranslation();
        if (!$item->isPlural() && !$item->getContext() && is_string($translation) && $translation !== '') {
          $translations[$item->getSource()] = $translation;
        }
      }
    }
    finally {
      $reader->close();
    }
    return $translations;
  }

}
