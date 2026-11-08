<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\markaspot_moderation\Service\PublicationHold;
use Drupal\node\NodeInterface;

/**
 * Keeps automatic publication from reversing a moderation decision.
 */
final class PublisherHooks {

  /**
   * Constructs the publisher hooks.
   */
  public function __construct(private readonly PublicationHold $hold) {}

  /**
   * Excludes moderation holds before the publisher's candidate limit applies.
   */
  #[Hook('query_markaspot_publisher_candidates_alter')]
  public function candidates(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface || $query->getMetaData('markaspot_moderation_hold_check')) {
      return;
    }

    // Keep RevisionLog decoding in PHP, but restrict longtext/history reads to
    // the publisher's bounded candidates instead of all unpublished reports.
    $candidates = clone $query;
    $candidates->addMetaData('markaspot_moderation_hold_check', TRUE);
    do {
      // Node entity queries select the revision ID first, then the node ID.
      $ids = array_map('intval', $candidates->execute()->fetchCol(1));
      $held = $this->hold->heldIds($ids);
      if ($held !== []) {
        // Refill the limited batch so held reports cannot starve other ones.
        $candidates->condition('base_table.nid', $held, 'NOT IN');
      }
    } while ($held !== []);

    // Only IDs whose history was checked may reach the queue, even if the
    // underlying candidates change between these two queries.
    $query->condition('base_table.nid', $ids ?: [0], 'IN');
  }

  /**
   * Rechecks the same durable hold immediately before queued publication.
   */
  #[Hook('markaspot_publisher_hold_alter')]
  public function hold(bool &$held, NodeInterface $node): void {
    $held = $held || $this->hold->heldIds([(int) $node->id()]) !== [];
  }

}
