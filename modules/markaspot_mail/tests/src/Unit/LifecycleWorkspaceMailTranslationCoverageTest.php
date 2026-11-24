<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests coverage for the localized signup mail copy.
 */
#[Group('markaspot_mail')]
final class LifecycleWorkspaceMailTranslationCoverageTest extends UnitTestCase {

  /**
   * Source strings of the lifecycle model in the workspace mails.
   */
  private const MSGIDS = [
    'Confirm your email address for @name',
    'Confirm your email address',
    'Welcome to @site! Confirm your email address to set up your workspace "@name".',
    'Click the button below to confirm your email address.',
    'Your @name workspace is ready',
    'Workspace "@name" is ready.',
    'Your workspace is ready',
    'Open your workspace',
    'Try it out: create your first test report directly on the map.',
    'A few demo reports are already in place. Edit or delete them anytime.',
  ];

  /**
   * Each string is in the template and translated in the shipped catalogues.
   */
  public function testTemplateAndGermanCoverTheLifecycleTexts(): void {
    $dir = dirname(__DIR__, 3) . '/translations';
    $pot = file_get_contents($dir . '/markaspot_mail.pot');
    $this->assertIsString($pot);
    $catalogues = glob($dir . '/*.po');
    $this->assertNotEmpty($catalogues);
    foreach (self::MSGIDS as $msgid) {
      $encoded = addcslashes($msgid, "\\\"");
      $this->assertStringContainsString('msgid "' . $encoded . '"', $pot, $msgid);
      foreach ($catalogues as $catalogue) {
        $po = file_get_contents($catalogue);
        $this->assertIsString($po);
        $pattern = '/msgid "' . preg_quote($encoded, '/') . "\"\\Rmsgstr \"(?!\")(?:[^\"\\\\]|\\\\.)+\"/u";
        $this->assertMatchesRegularExpression($pattern, $po, sprintf('Missing "%s" in %s.po.', $msgid, basename($catalogue, '.po')));
      }
    }
  }

}
