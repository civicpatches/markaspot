<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\node\NodeInterface;

/**
 * Overrides the status mail only while a duplicate closure is being saved.
 *
 * The existing ECA status-change and consent gates still decide whether to
 * dispatch. No term, entity field or automation configuration is changed.
 */
final class DuplicateNotification {

  /**
   * Kept reports for the currently executing saves, indexed by closed node ID.
   *
   * @var array<int, \Drupal\node\NodeInterface>
   */
  private static array $keptReports = [];

  /**
   * Saves a closure with a scoped mail override, including on failure.
   */
  public static function save(NodeInterface $closed, NodeInterface $kept): void {
    $id = (int) $closed->id();
    $previous = self::$keptReports[$id] ?? NULL;
    self::$keptReports[$id] = $kept;
    try {
      $closed->save();
    }
    finally {
      unset(self::$keptReports[$id]);
      if ($previous !== NULL) {
        self::$keptReports[$id] = $previous;
      }
    }
  }

  /**
   * Returns the kept report only for a save currently in this scope.
   */
  public static function keptReport(NodeInterface $closed): ?NodeInterface {
    return self::$keptReports[(int) $closed->id()] ?? NULL;
  }

}
