<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Translates mail copy using shipped translations on sites without Locale.
 */
trait MailStringTranslationTrait {

  use StringTranslationTrait;

  /**
   * Translates a source string in the recipient's language.
   */
  private function translateMail(string $source, array $args, string $langcode): string {
    return $this->mailStrings?->translate($source, $args, $langcode)
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString -- Builders pass source literals.
      ?? (string) new TranslatableMarkup($source, $args, ['langcode' => $langcode], $this->getStringTranslation());
  }

}
