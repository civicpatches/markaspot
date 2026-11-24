<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LoggerInterface;

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
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
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
    if (!in_array($module, ['markaspot_mail', 'service_request'], TRUE)) {
      return [];
    }
    try {
      $directory = $this->appRoot . '/' . $this->moduleHandler->getModule($module)->getPath() . '/translations';
      $file = "$directory/$langcode.po";
      if (!is_file($file)) {
        $file = "$directory/" . explode('-', $langcode)[0] . '.po';
      }
      if (!is_file($file) || !is_readable($file)) {
        throw new \RuntimeException('The PO file is missing or unreadable.');
      }
      // Core's reader collects syntax errors and may return partial data.
      $reader = new class extends PoStreamReader {

        /**
         * Whether a header was read and no syntax errors were found.
         */
        public function isValid(): bool {
          return $this->getHeader() !== NULL && empty($this->errors);
        }

      };
      $reader->setURI($file);
      $reader->setLangcode($langcode);
      $reader->open();
      $translations = [];
      try {
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
      if (!$reader->isValid()) {
        throw new \RuntimeException('The PO file has invalid gettext syntax.');
      }
      return $translations;
    }
    catch (\Throwable $e) {
      // Cache the empty result so a catalogue is logged only once per request.
      $this->logger->warning('Using source mail strings for @module (@language): @message', [
        '@module' => $module,
        '@language' => $langcode,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
