<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests translation coverage for the workspace verification sign-in hint.
 */
#[Group('markaspot_mail')]
final class WorkspaceVerificationTranslationCoverageTest extends UnitTestCase {

  /**
   * Source string explaining sign-in after workspace verification.
   */
  private const REQUIRED_MSGID = 'Open the link in the browser you signed up with to be signed in automatically. In any other browser you can sign in with a code that we send to this email address.';

  /**
   * Tests every shipped locale translates the verification sign-in hint.
   */
  public function testAllShippedTranslationsContainVerificationHint(): void {
    $encodedMsgid = preg_quote(addcslashes(self::REQUIRED_MSGID, "\\\""), '/');
    $pattern = '/msgid "' . $encodedMsgid . "\"\\Rmsgstr \"(?<translation>(?!\")(?:[^\"\\\\]|\\\\.)*)\"/u";

    foreach (WordingOverrideTranslationCoverageTest::SHIPPED_LOCALES as $langcode) {
      $source = file_get_contents($this->translationDir() . '/' . $langcode . '.po');
      $this->assertIsString($source);
      $this->assertMatchesRegularExpression(
        $pattern,
        $source,
        sprintf('Missing non-empty "%s" translation in %s.po.', self::REQUIRED_MSGID, $langcode),
      );
    }
  }

  /**
   * Tests the template catalog contains the verification sign-in hint.
   */
  public function testPotContainsVerificationHint(): void {
    $source = file_get_contents($this->translationDir() . '/markaspot_mail.pot');
    $this->assertIsString($source);
    $this->assertStringContainsString(
      'msgid "' . addcslashes(self::REQUIRED_MSGID, "\\\"") . '"',
      $source,
      sprintf('Missing source string "%s" in markaspot_mail.pot.', self::REQUIRED_MSGID),
    );
  }

  /**
   * Gets the module translation directory.
   */
  private function translationDir(): string {
    return dirname(__DIR__, 3) . '/translations';
  }

}
