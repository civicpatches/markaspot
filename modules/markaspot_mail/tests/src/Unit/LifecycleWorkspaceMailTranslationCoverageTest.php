<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The lifecycle texts of the workspace mails are in the template and German.
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
    'Next step: name the operator of the map in the settings of your dashboard and open the map. Until then, only your team can use it.',
  ];

  /**
   * Each string is in the template and translated in de and de-ls.
   */
  public function testTemplateAndGermanCoverTheLifecycleTexts(): void {
    $dir = dirname(__DIR__, 3) . '/translations';
    $pot = file_get_contents($dir . '/markaspot_mail.pot');
    $this->assertIsString($pot);
    foreach (self::MSGIDS as $msgid) {
      $encoded = addcslashes($msgid, "\\\"");
      $this->assertStringContainsString('msgid "' . $encoded . '"', $pot, $msgid);
      foreach (['de', 'de-ls'] as $langcode) {
        $po = file_get_contents($dir . '/' . $langcode . '.po');
        $this->assertIsString($po);
        $pattern = '/msgid "' . preg_quote($encoded, '/') . "\"\\Rmsgstr \"(?!\")(?:[^\"\\\\]|\\\\.)+\"/u";
        $this->assertMatchesRegularExpression($pattern, $po, sprintf('Missing "%s" in %s.po.', $msgid, $langcode));
      }
    }
  }

}
