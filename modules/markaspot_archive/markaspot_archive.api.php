<?php

/**
 * @file
 * Hooks provided by the Mark-a-Spot Archive module.
 */

declare(strict_types=1);

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Acts after a report's personal data was anonymized.
 *
 * Invoked by the archive anonymisation after the anonymized entity was saved
 * (queue worker, archive status transition, catch-up backfill and drush
 * commands), by the backfill for archived reports whose contact fields are
 * already anonymized while hook_markaspot_archive_pending_erasure() reported
 * derived data, and by the GDPR erasure form. The report itself is kept, so
 * hook_entity_delete() does not run: purge data derived from the report's
 * original content here. Implementations must be idempotent.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The anonymized report.
 */
function hook_markaspot_report_anonymized(EntityInterface $entity): void {
  \Drupal::database()->delete('mymodule_derived_text')
    ->condition('entity_id', $entity->id())
    ->execute();
}

/**
 * Returns archived reports whose derived personal data is not erased yet.
 *
 * The archive backfill and markaspot:archive:backfill use it to find
 * archived reports whose contact fields are already anonymized while data
 * derived from them still holds personal data, so that cron keeps working on
 * them and the command reports them as pending. For each returned report the
 * backfill invokes hook_markaspot_report_anonymized(); afterwards the report
 * must no longer be returned.
 *
 * @param \Drupal\Core\Database\Query\SelectInterface $archived_nids
 *   Subquery selecting the node IDs of archived service requests, for use
 *   with an IN condition.
 * @param int $after_nid
 *   Only node IDs above this one.
 * @param int|null $limit
 *   Maximum number of node IDs, NULL for all.
 *
 * @return int[]
 *   Node IDs in ascending order.
 */
function hook_markaspot_archive_pending_erasure(SelectInterface $archived_nids, int $after_nid, ?int $limit): array {
  $query = \Drupal::database()->select('mymodule_derived_text', 'd')
    ->fields('d', ['entity_id'])
    ->condition('d.entity_id', $archived_nids, 'IN')
    ->condition('d.entity_id', $after_nid, '>')
    ->distinct()
    ->orderBy('d.entity_id');
  if ($limit !== NULL) {
    $query->range(0, $limit);
  }
  return array_map('intval', $query->execute()->fetchCol());
}

/**
 * @} End of "addtogroup hooks".
 */
