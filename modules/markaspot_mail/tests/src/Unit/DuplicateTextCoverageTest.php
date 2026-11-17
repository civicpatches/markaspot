<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every shipped locale supplies the complete duplicate explanation.
 */
#[Group('markaspot_mail')]
final class DuplicateTextCoverageTest extends UnitTestCase {

  /**
   * Mail and note templates are complete, with no private-number placeholder.
   */
  public function testShippedLanguages(): void {
    $root = dirname(__DIR__, 3) . '/config/install';
    $files = [$root . '/markaspot_mail.texts.yml', ...glob($root . '/language/*/markaspot_mail.texts.yml')];
    $this->assertCount(19, $files);
    foreach ($files as $file) {
      $text = Yaml::decode(file_get_contents($file))['status_duplicate'];
      $slots = [
        'subject', 'headline', 'intro', 'body_blocks', 'cta_label',
        'preheader', 'status_note', 'private_status_note',
      ];
      foreach ($slots as $slot) {
        $this->assertNotEmpty($text[$slot], "$file: $slot");
      }
      $this->assertStringContainsString('{{ citizen_term_', $text['subject']);
      $this->assertStringContainsString('@request_id', $text['status_note']);
      $this->assertStringNotContainsString('@request_id', $text['private_status_note']);
      $this->assertSame(['[node:latest_status_note]'], $text['body_blocks']);
    }
  }

  /**
   * Configurable citizen terms must not be preceded by gendered articles.
   */
  #[DataProvider('articlePatterns')]
  public function testNoArticleBeforeCitizenTerm(string $locale, string $articles): void {
    $file = dirname(__DIR__, 3) . "/config/install/language/$locale/markaspot_mail.texts.yml";
    $text = Yaml::decode(file_get_contents($file))['status_duplicate'];
    foreach ($text as $slot => $value) {
      foreach ((array) $value as $message) {
        $this->assertDoesNotMatchRegularExpression(
          '/\b(?:' . $articles . ')\s*\{\{\s*citizen_term_/iu',
          $message,
          "$locale: $slot must not require agreement with the configurable term.",
        );
      }
    }
  }

  /**
   * Articles and, in languages without articles, gendered demonstratives.
   */
  public static function articlePatterns(): array {
    return [
      'German' => ['de', 'der|die|das|den|dem|des|ein|eine|einen|einem|einer|eines'],
      'French' => ['fr', 'le|la|les|un|une|des|du|l[’\x27]'],
      'Spanish' => ['es', 'el|la|los|las|un|una|unos|unas'],
      'Italian' => ['it', 'il|lo|la|gli|le|un|uno|una|del|dello|della|dei|degli|delle|l[’\x27]|un[’\x27]'],
      'Dutch' => ['nl', 'de|het|een'],
      'Portuguese' => ['pt', 'o|a|os|as|um|uma|uns|umas|do|da|dos|das'],
      'Brazilian Portuguese' => ['pt-br', 'o|a|os|as|um|uma|uns|umas|do|da|dos|das'],
      'Polish' => ['pl', 'ten|ta|to|te|tego|tej|tym|tę|tamten|tamta|tamto|tamtym'],
      'Czech' => ['cs', 'ten|ta|to|ty|toho|té|tomu|tím|tu|tom'],
    ];
  }

}
