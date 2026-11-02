<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\markaspot_health\Plugin\HealthCheck\MediaIntakeScreeningCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Distinguishes cron latency, permanent holds and optional enrichment failure.
 */
#[Group('markaspot_health')]
final class MediaIntakeScreeningCheckTest extends UnitTestCase {

  /**
   * The fifteen-minute cloud cron does not trigger an early alarm.
   */
  #[DataProvider('healthCases')]
  public function testHealth(array $entry, bool $passed, string $severity, string $message): void {
    $store = new KeyValueMemoryFactory();
    $store->get('markaspot_vision.media_intake')->set(42, $entry + ['created' => 1000]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(2200);
    $check = new MediaIntakeScreeningCheck([], 'media_intake_screening', [
      'label' => 'Intake', 'severity' => 'error',
    ], $store, $time);
    $result = $check->run();
    $this->assertSame($passed, $result->passed);
    if (!$passed) {
      $this->assertSame($severity, $result->severity);
      $this->assertSame(1, $result->count);
    }
    $this->assertStringContainsString($message, $result->message);
  }

  /**
   * Boundary times and each completed-job outcome.
   */
  public static function healthCases(): array {
    return [
      'cron interval' => [['status' => 'pending', 'created' => 1300], TRUE, '', 'No failed'],
      'threshold' => [['status' => 'pending'], TRUE, '', 'No failed'],
      'stalled' => [['status' => 'pending', 'created' => 999], FALSE, 'error', 'twenty minutes'],
      'failed' => [['status' => 'failed'], FALSE, 'error', 'failed screening'],
      'silent hold' => [
        ['status' => 'done', 'analysis' => 'description_present'],
        FALSE, 'warning', 'neither privacy screening',
      ],
      'disabled' => [['status' => 'done', 'analysis' => 'disabled'], FALSE, 'warning', 'neither privacy screening'],
      'analysis complete' => [['status' => 'done', 'analysis' => 'done'], TRUE, '', 'No failed'],
      'existing analysis' => [['status' => 'done', 'analysis' => 'existing'], TRUE, '', 'No failed'],
      'blur complete' => [['status' => 'done', 'privacy_screened' => TRUE], TRUE, '', 'No failed'],
      'enrichment failed' => [
        ['status' => 'done', 'privacy_screened' => TRUE, 'analysis' => 'failed'],
        FALSE, 'warning', 'enrichment failed',
      ],
      'orphan' => [['status' => 'orphaned', 'created' => 0], TRUE, '', 'No failed'],
    ];
  }

}
