<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
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

}
