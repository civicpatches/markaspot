<?php

/**
 * @file
 * Hooks for excluding reports from automatic publication.
 */

declare(strict_types=1);

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\node\NodeInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Vetoes publication immediately before the publisher queue worker saves.
 *
 * The worker calls this alter after loading an unpublished node and checking
 * its publishable status. Implementations may only set $held to TRUE, never
 * clear another module's veto. Read the hold store fresh when deciding a veto;
 * a cached candidate list can miss holds placed after enqueueing. Short-circuit
 * evaluation when $held is already TRUE is allowed. Use only the implementing
 * module's own store, without requiring a publisher class, service or
 * dependency.
 *
 * The worker skips held reports without saving or releasing their holds. A
 * \Exception from this alter is caught and logged, the queue item is dropped
 * and the report stays unpublished. Do not rely on that: an \Error aborts the
 * queue run.
 * This is not guaranteed to fail closed: core's database key/value store can
 * return "not held" on a database read error, allowing publication to proceed.
 * This hook covers only the publisher worker, not manual or other automated
 * publication paths. It does not change which unheld reports are eligible.
 *
 * @param bool $held
 *   Whether any implementation vetoes publication, initially FALSE.
 * @param \Drupal\node\NodeInterface $node
 *   The saved node being processed. The worker does not check the bundle, so
 *   implementations must not assume a particular one.
 *
 * @see hook_query_markaspot_publisher_candidates_alter()
 */
function hook_markaspot_publisher_hold_alter(bool &$held, NodeInterface $node): void {
  $held = $held || \Drupal::keyValue('example.publication_hold')->has((string) $node->id());
}

/**
 * Excludes held reports from the publisher's tagged candidate query.
 *
 * The markaspot_publisher_candidates tag is added to the node entity query.
 * Exclude held IDs before the per-category limit of 20 is applied, so held
 * reports do not occupy the batch. This filter alone cannot protect queued
 * items: also implement hook_markaspot_publisher_hold_alter() to recheck holds
 * when each item is processed. Store read failures can bypass this filter too.
 *
 * @param \Drupal\Core\Database\Query\AlterableInterface $query
 *   The tagged SQL query, with node IDs on base_table.nid.
 */
function hook_query_markaspot_publisher_candidates_alter(AlterableInterface $query): void {
  $held = array_keys(\Drupal::keyValue('example.publication_hold')->getAll());
  if ($held !== [] && $query instanceof SelectInterface) {
    $query->condition('base_table.nid', $held, 'NOT IN');
  }
}

/**
 * @} End of "addtogroup hooks".
 */
