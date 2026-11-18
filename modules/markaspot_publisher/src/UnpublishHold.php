<?php

declare(strict_types=1);

namespace Drupal\markaspot_publisher;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Stores unpublish holds and shares their IDs across candidate queries.
 */
final class UnpublishHold {

  /**
   * Held node IDs keyed by ID, or NULL before the first candidate query.
   */
  private ?array $heldIds = NULL;

  /**
   * The durable hold collection.
   */
  private readonly KeyValueStoreInterface $store;

  /**
   * Constructs the hold store.
   */
  public function __construct(KeyValueFactoryInterface $keyValue, private readonly TimeInterface $time) {
    $this->store = $keyValue->get('markaspot_publisher.unpublish_hold');
  }

  /**
   * Records the first time a report was held, including on repeated erasure.
   */
  public function hold(int $nid): void {
    $this->store->setIfNotExists((string) $nid, ['time' => $this->time->getRequestTime()]);
    if ($this->heldIds !== NULL) {
      $this->heldIds[$nid] = $nid;
    }
  }

  /**
   * Removes the hold from storage and any candidate list read this request.
   */
  public function release(int $nid): void {
    $this->store->delete((string) $nid);
    unset($this->heldIds[$nid]);
  }

  /**
   * Returns the held IDs to exclude before each category's batch limit.
   */
  public function heldIds(): array {
    if ($this->heldIds === NULL) {
      $ids = array_keys($this->store->getAll());
      $this->heldIds = array_combine($ids, $ids);
    }
    return $this->heldIds;
  }

  /**
   * Checks storage afresh immediately before queued publication.
   */
  public function isHeld(int $nid): bool {
    return $this->store->has((string) $nid);
  }

}
