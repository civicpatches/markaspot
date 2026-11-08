<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Unit;

use Drupal\markaspot_archive\RevisionLogScrubber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * An anonymized report keeps no free-text message in its revision logs.
 *
 * All names, addresses and numbers are fictional.
 */
#[CoversClass(RevisionLogScrubber::class)]
#[Group('markaspot_archive')]
final class RevisionLogScrubberTest extends UnitTestCase {

  /**
   * The fixed text that replaces a message.
   */
  private const PLACEHOLDER = 'Message removed when the report was anonymized.';

  /**
   * Flags of RevisionLog::build().
   */
  private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

  /**
   * The placeholder is stored as a fixed English text.
   */
  public function testPlaceholderIsFixed(): void {
    $this->assertSame(self::PLACEHOLDER, RevisionLogScrubber::PLACEHOLDER);
  }

  /**
   * Legacy free text is replaced as a whole, whatever it holds.
   *
   * No pattern could find these: international and bracketed numbers, a
   * short local number and a bare name.
   */
  public function testLegacyTextIsReplaced(): void {
    $logs = [
      'Call +44 7700 900123',
      'Call +49 (0)221 1234567',
      'Call 1234567',
      'Spoke to Jordan, she will send photos.',
      'Crew scheduled for Tuesday, ticket 0123-2026.',
      // Invalid UTF-8 does not stop the replacement.
      "Jordan \xC3\x28 called",
      // JSON that is no revision envelope is free text as well.
      '{"note":"call 0170 1234567"}',
      ' ',
    ];
    foreach ($logs as $log) {
      $this->assertTrue(RevisionLogScrubber::needsScrubbing($log), $log);
      $this->assertSame(self::PLACEHOLDER, RevisionLogScrubber::scrub($log), $log);
    }
  }

  /**
   * An envelope keeps every key; only its message is replaced.
   *
   * Events and changes are what readers of the history depend on, such as
   * a moderation hold; a status label quoting digits stays as it is.
   */
  public function testEnvelopeKeepsEventsAndChanges(): void {
    $envelope = [
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [[
        'field' => 'status',
        'before' => [['id' => '12', 'label' => 'Callback 02211234567']],
        'after' => [['id' => '13']],
      ]],
      'events' => ['moderation_hidden', 'unpublished'],
      'message' => 'Hidden after a call: Müller, +44 7700 900123.',
      'future_key' => ['kept' => TRUE],
    ];
    $log = json_encode($envelope, self::JSON_FLAGS);

    $this->assertTrue(RevisionLogScrubber::needsScrubbing($log));
    $scrubbed = RevisionLogScrubber::scrub($log);

    $expected = $envelope;
    $expected['message'] = self::PLACEHOLDER;
    $this->assertSame(json_encode($expected, self::JSON_FLAGS), $scrubbed);
    $this->assertStringNotContainsString('Müller', $scrubbed);
    $this->assertStringNotContainsString('7700', $scrubbed);
  }

  /**
   * Empty messages, empty logs and replaced messages stay byte-identical.
   */
  public function testCleanLogsAreUntouched(): void {
    $envelope = static fn (string $message, array $extra = []): string => json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [[
        'field' => 'status',
        'before' => [['id' => '1', 'label' => 'Callback 02211234567']],
        'after' => [['id' => '2']],
      ]],
      'events' => ['scope_sync'],
      'message' => $message,
    ] + $extra, self::JSON_FLAGS);
    $logs = [
      '',
      self::PLACEHOLDER,
      $envelope(''),
      $envelope(self::PLACEHOLDER),
      // Escaped encoding of another writer: nothing to replace, kept as is.
      json_encode(['schema' => 'civic.revision.v1', 'action' => 'created', 'changes' => [], 'message' => '']),
      // An envelope without a message holds no free text.
      json_encode(['schema' => 'civic.revision.v1', 'action' => 'created', 'changes' => []]),
    ];
    foreach ($logs as $log) {
      $this->assertFalse(RevisionLogScrubber::needsScrubbing($log), $log);
      $this->assertSame($log, RevisionLogScrubber::scrub($log), $log);
    }
  }

  /**
   * Replacing twice gives the same log as replacing once.
   */
  public function testReplacementIsIdempotent(): void {
    $logs = [
      'Reporter jordan@example.org asked for an update.',
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [],
        'events' => ['published'],
        // Escaped by another writer: the replacement uses the canonical flags.
        'message' => 'Stra' . mb_chr(0xDF) . 'e 4',
      ]),
    ];
    foreach ($logs as $log) {
      $once = RevisionLogScrubber::scrub($log);
      $this->assertNotSame($log, $once);
      $this->assertFalse(RevisionLogScrubber::needsScrubbing($once), $once);
      $this->assertSame($once, RevisionLogScrubber::scrub($once));
    }
    $decoded = json_decode(RevisionLogScrubber::scrub($logs[1]), TRUE);
    $this->assertSame(['published'], $decoded['events']);
    $this->assertSame(self::PLACEHOLDER, $decoded['message']);
  }

}
