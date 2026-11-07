<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_feedback\Unit;

use Drupal\Component\Utility\Html;
use Drupal\markaspot_feedback\Controller\FeedbackController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that citizen feedback keeps no file embed.
 */
#[CoversMethod(FeedbackController::class, 'stripFileEmbeds')]
#[Group('markaspot_feedback')]
final class FeedbackFileEmbedTest extends UnitTestCase {

  private const UUID = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

  /**
   * Feedback without the attribute names is returned byte for byte.
   */
  public function testFeedbackWithoutEmbedIsUnchanged(): void {
    $text = "Danke! Die Straße ist frei & sauber.\n<b>Top</b> <img src=\"x.jpg\"> data-entity";
    $this->assertSame($text, FeedbackController::stripFileEmbeds($text));
  }

  /**
   * No spelling of a file embed survives.
   */
  #[DataProvider('embeds')]
  public function testFileEmbedIsRemoved(string $text, string $expected, bool $embeds = TRUE): void {
    $this->assertSame($embeds ? [self::UUID] : [], $this->embeddedFileUuids($text), 'The feedback as sent.');
    $stripped = FeedbackController::stripFileEmbeds($text);
    $this->assertSame($expected, $stripped);
    $this->assertSame([], $this->embeddedFileUuids($stripped));
  }

  /**
   * File embeds in the spellings an HTML parser accepts.
   *
   * @return array<string, array{0: string, 1: string, 2?: bool}>
   *   The feedback, the stored text and whether the feedback as sent embeds
   *   the file.
   */
  public static function embeds(): array {
    $uuid = self::UUID;
    return [
      'double quotes' => ["See <img data-entity-type=\"file\" data-entity-uuid=\"$uuid\"> here", 'See <img> here'],
      'single quotes, other attributes kept' => [
        "<img src='a.jpg' data-entity-uuid='$uuid' data-entity-type='file' alt='x'>",
        "<img src='a.jpg' alt='x'>",
      ],
      'unquoted' => ["<img data-entity-type=file data-entity-uuid=$uuid>", '<img>'],
      'slash separated' => ["<img/data-entity-type=\"file\"/data-entity-uuid=\"$uuid\">", '<img//>'],
      'upper case, line breaks' => ["<IMG\nDATA-ENTITY-TYPE = \"file\"\r\nData-Entity-Uuid=\"$uuid\">", '<IMG>'],
      'removal joins a new embed' => [
        "<img data-entity-t data-entity-uuid=\"1\"ype=\"file\" data-entity-u data-entity-type=\"2\"uid=\"$uuid\">",
        '<img>',
        FALSE,
      ],
    ];
  }

  /**
   * Returns the file UUIDs the editor module would record for a text.
   *
   * @return list<string>
   *   The embedded file UUIDs.
   *
   * @see \Drupal\editor\Hook\EditorHooks::parseFileUuids()
   */
  private function embeddedFileUuids(string $text): array {
    $uuids = [];
    $xpath = new \DOMXPath(Html::load($text));
    foreach ($xpath->query('//*[@data-entity-type="file" and @data-entity-uuid]') as $node) {
      if ($node instanceof \DOMElement) {
        $uuids[] = $node->getAttribute('data-entity-uuid');
      }
    }
    return $uuids;
  }

}
