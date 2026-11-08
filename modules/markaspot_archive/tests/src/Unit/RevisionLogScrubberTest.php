<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Unit;

use Drupal\markaspot_archive\RevisionLogScrubber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Revision log scrubbing keeps structure and removes contact data.
 *
 * All names, addresses and numbers are fictional.
 */
#[CoversClass(RevisionLogScrubber::class)]
#[Group('markaspot_archive')]
final class RevisionLogScrubberTest extends UnitTestCase {

  /**
   * Legacy free text loses addresses, numbers and the reporter's values.
   */
  public function testLegacyTextIsScrubbedWhole(): void {
    $log = 'Jordan Fictional called from 0221 123456 and +49 170 1234567, wrote '
      . 'to staff@example.test and jordan.f@example.org. Jordanstraße 4.';

    $scrubbed = RevisionLogScrubber::scrub($log, ['Jordan', 'Fictional', 'jordan.f@example.org', 'Al']);

    $this->assertSame(
      '[REDACTED] [REDACTED] called from [REDACTED phone] and [REDACTED phone], wrote '
      . 'to [REDACTED email] and [REDACTED email]. Jordanstraße 4.',
      $scrubbed
    );
  }

  /**
   * An envelope keeps every key; only its message is scrubbed.
   */
  public function testEnvelopeKeepsStructureAndEvents(): void {
    $envelope = [
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [['field' => 'status', 'before' => [['id' => '12']], 'after' => [['id' => '13']]]],
      'events' => ['moderation_hidden', 'unpublished'],
      'message' => 'Hidden after a call: Müller, 0170 1234567.',
      'future_key' => ['kept' => TRUE],
    ];
    $log = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $decoded = json_decode(RevisionLogScrubber::scrub($log, ['Müller']), TRUE);

    $this->assertSame('Hidden after a call: [REDACTED], [REDACTED phone].', $decoded['message']);
    unset($decoded['message'], $envelope['message']);
    $this->assertSame($envelope, $decoded);
  }

  /**
   * Logs without contact data stay byte-identical.
   */
  public function testNeutralLogsAreUntouched(): void {
    $logs = [
      'Crew scheduled for 12.11.2026, request 1034-2026, cost 1.250,00 EUR.',
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [['field' => 'responsibility', 'before' => [['id' => '102']], 'after' => [['id' => '1034']]]],
        'events' => ['scope_sync'],
        'message' => '',
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      // An escaped envelope stays as it is when it holds nothing to scrub.
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'created',
        'changes' => [],
        'events' => [],
        'message' => 'Stra' . mb_chr(0xDF) . 'e',
      ]),
      '',
    ];
    foreach ($logs as $log) {
      $this->assertSame($log, RevisionLogScrubber::scrub($log, ['Jordan']));
      $this->assertFalse(RevisionLogScrubber::containsContactData($log));
    }
  }

  /**
   * A scrubbed log never matches the patterns again.
   *
   * The SQL candidate query uses the same patterns: a scrubbed log is no
   * candidate any more.
   */
  public function testScrubbingIsIdempotent(): void {
    $logs = [
      'Call 0049 221 1234567 or mail a.b@example.org',
      // The escaped "a with macron" reads like a local number only in the
      // stored text; the canonical encoding removes the escape.
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [],
        'events' => ['published'],
        'message' => mb_chr(0x101) . ' 1234567',
      ]),
    ];
    foreach ($logs as $log) {
      $this->assertTrue(RevisionLogScrubber::containsContactData($log));
      $once = RevisionLogScrubber::scrub($log);
      $this->assertFalse(RevisionLogScrubber::containsContactData($once), $once);
      $this->assertSame($once, RevisionLogScrubber::scrub($once));
    }
    $canonical = json_decode(RevisionLogScrubber::scrub($logs[1]), TRUE);
    $this->assertSame(mb_chr(0x101) . ' 1234567', $canonical['message']);
    $this->assertStringNotContainsString('\\u0101', RevisionLogScrubber::scrub($logs[1]));
    $this->assertSame(['published'], $canonical['events']);
  }

  /**
   * Invalid UTF-8 in a legacy log does not stop the scrubbing.
   */
  public function testInvalidUtf8IsScrubbed(): void {
    $log = "Jordan \xC3\x28 jordan@example.org 0221 123456";
    $this->assertSame("[REDACTED] \xC3\x28 [REDACTED email] [REDACTED phone]", RevisionLogScrubber::scrub($log, ['jordan']));
  }

}
