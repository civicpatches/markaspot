<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\markaspot_fastmap\Service\TierConfigService;

/**
 * Tier config that lets a publish lose the race for the last slot.
 *
 * While $race is set, the first published-count read (the TierLimit
 * constraint) sees one report fewer than stored; every later read (the
 * presave backstop under the lock) sees the stored count.
 */
class RacingTierConfig extends TierConfigService {

  /**
   * Whether the next validation reads one report fewer.
   */
  public static bool $race = FALSE;

  /**
   * Reads done in the current race.
   */
  private static int $reads = 0;

  /**
   * {@inheritdoc}
   */
  public function countRequests(int $groupId, string $period): int {
    $count = parent::countRequests($groupId, $period);
    if (!self::$race || $period !== 'published') {
      self::$reads = 0;
      return $count;
    }
    return self::$reads++ === 0 ? max(0, $count - 1) : $count;
  }

}
