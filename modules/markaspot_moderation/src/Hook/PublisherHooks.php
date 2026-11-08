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
    if ($query instanceof SelectInterface) {
      $held = $this->hold->heldIds();
      if ($held !== []) {
        $query->condition('base_table.nid', $held, 'NOT IN');
      }
    }
  }

  /**
   * Rechecks the same durable hold immediately before queued publication.
   */
  #[Hook('markaspot_publisher_hold_alter')]
  public function hold(bool &$held, NodeInterface $node): void {
    $held = $held || $this->hold->heldIds((int) $node->id()) !== [];
  }

}
