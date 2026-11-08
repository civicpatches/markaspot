<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for ArchiveService.
 */
interface ArchiveServiceInterface {

  /**
   * Loads service requests that are eligible for archiving.
   *
   * @param bool $all
   *   TRUE to disable category rotation and result limits.
   * @param bool $advance_rotation
   *   TRUE to persist the next category rotation index.
   * @param bool $log
   *   TRUE to write operational notices.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Eligible service request nodes.
   */
  public function load(bool $all = FALSE, bool $advance_rotation = TRUE, bool $log = TRUE): array;

  /**
   * Reads anonymize_fields as a supported associative map or list.
   *
   * @param array $configured_fields
   *   Configured fields keyed by field machine name or stored as list values.
   *
   * @return array<string, string>
   *   Field machine names keyed by field machine name.
   */
  public function normalizeConfiguredFields(array $configured_fields): array;

  /**
   * Anonymizes supported fields on the current entity object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The entity to anonymize.
   * @param array $anonymize_fields
   *   Field machine names.
   *
   * @return array<string, array{type: string, value: int|string}>
   *   Anonymized values keyed by field machine name.
   */
  public function anonymize(EntityInterface $archivable, array $anonymize_fields): array;

  /**
   * Unpublishes a node whose status changes to the archived status.
   *
   * Runs on presave like ::anonymizeOnArchiveTransition(), so a request
   * archived outside the queue worker (a manual status change, an Open311
   * status update) is unpublished as well. Follows the "unpublish" setting,
   * independent of the "anonymize" setting. Saving an already archived node
   * again, for example after staff republished it, unpublishes nothing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   *
   * @return bool
   *   TRUE when a published translation was unpublished.
   */
  public function unpublishOnArchiveTransition(NodeInterface $node): bool;

  /**
   * Anonymizes a node whose status changes to the archived status.
   *
   * Runs on presave, so every way into the archive is covered: the queue
   * worker, a manual status change and Open311 status updates. Entities the
   * worker or the staff command anonymized in this request are skipped.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   *
   * @return array<string, array{type: string, value: int|string}>
   *   Anonymized values keyed by field machine name, empty if nothing changed.
   */
  public function anonymizeOnArchiveTransition(NodeInterface $node): array;

  /**
   * Completes an archive transition once the node is stored.
   *
   * Called from the insert and update hooks: anonymizes previous revisions,
   * replaces the messages of the revision logs and lets modules erase
   * derived data (::anonymizeRevisions()), for new and existing nodes alike.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The saved node.
   *
   * @return int
   *   Number of revision field rows updated.
   */
  public function finishArchiveTransition(NodeInterface $node): int;

  /**
   * Unpublishes archived requests that are still published, once.
   *
   * Catches up on requests archived before transition unpublishing existed,
   * for example by a manual status change. Follows the "unpublish" setting
   * and keeps the changed time. The pass walks all nodes once and then stops,
   * so a request staff republish afterwards stays published. Deleting the
   * state key markaspot_archive.unpublish_backlog_done runs it again.
   *
   * A request that fails is kept in the state key
   * markaspot_archive.unpublish_backlog_failed and retried on a later run
   * (not again in the same request); the pass does not end while one is left.
   * A request staff reopened or unpublished in the meantime is dropped.
   *
   * @param int $limit
   *   Maximum number of nodes to process.
   * @param int $time_budget
   *   Seconds after which no further node is started.
   *
   * @return array{processed: int, changed: int, failed: int}
   *   Nodes passed, unpublished and failed in this call. Nothing processed
   *   means the setting is off or the pass has ended.
   */
  public function unpublishArchivedBacklog(int $limit, int $time_budget): array;

  /**
   * Anonymizes archived requests that still hold plain contact data.
   *
   * Picks up requests archived before transition anonymization existed or
   * while anonymization was disabled, including plain values left in previous
   * revisions. E-mail, telephone and string fields reveal plain data; the
   * changed time of the requests is kept.
   *
   * @param int $limit
   *   Maximum number of nodes to process.
   * @param int $time_budget
   *   Seconds after which no further node is started.
   *
   * @return array{processed: int, changed: int, failed: int}
   *   Nodes passed, anonymized and failed in this call. Nothing processed
   *   means the setting is off or no candidate is left above the cursor; the
   *   next call then starts over at the first node.
   */
  public function backfillArchived(int $limit, int $time_budget): array;

  /**
   * Counts the requests the one-time unpublish pass still has to visit.
   *
   * Published archived requests above the pass cursor and failed ones still
   * waiting for a retry, independent of the "unpublish" setting. Zero once
   * the pass has ended.
   *
   * @return int
   *   Number of published archived requests left in the pass.
   */
  public function countUnpublishBacklog(): int;

  /**
   * Counts archived requests that still hold plain contact data.
   *
   * Covers the current and every previous revision, independent of the
   * "anonymize" setting and of the backfill cursor.
   *
   * @return int
   *   Number of archived requests the backfill would anonymize.
   */
  public function countBackfillCandidates(): int;

  /**
   * Counts search index items of archived requests awaiting indexing.
   *
   * Search API indexes a changed request at the end of the request or on
   * cron; until then the index still holds the text from before the
   * catch-up. A failed indexing leaves the item waiting for cron.
   *
   * @return int|null
   *   Number of index items, NULL when Search API is not enabled.
   */
  public function countSearchIndexBacklog(): ?int;

  /**
   * Returns the search indexes of requests the catch-up cannot update.
   *
   * Indexes with a node datasource that are read-only (enabled or not), or
   * enabled without a valid tracker or an enabled server: they may keep
   * text the anonymization removed. A disabled index is no such index, its
   * server dropped its data when it was disabled.
   *
   * @return array<string, string>
   *   Index labels keyed by index ID, empty without Search API.
   */
  public function unprocessableSearchIndexes(): array;

  /**
   * Indexes the waiting search index items of archived requests now.
   *
   * @return array{processed: int, changed: int, failed: int}
   *   Items attempted, indexed and not indexed.
   */
  public function indexSearchIndexBacklog(): array;

  /**
   * Updates previous revision field tables with anonymized values.
   *
   * Callers invoke this as the last step after saving the anonymized entity.
   * When values were anonymized it also invokes
   * hook_markaspot_report_anonymized() so modules can purge derived data, and
   * replaces the free-text message of the revision logs of the current and
   * every previous revision with a fixed placeholder, keeping their action
   * metadata (see \Drupal\markaspot_archive\RevisionLogScrubber). This
   * covers the staff anonymization command as well: an anonymized report
   * keeps no free-text revision message.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The saved entity whose previous revisions should be updated.
   * @param array $anonymized_values
   *   Values returned by ::anonymize().
   *
   * @return int
   *   Number of revision field rows updated.
   */
  public function anonymizeRevisions(EntityInterface $archivable, array $anonymized_values): int;

  /**
   * Returns non-empty supported field names for preview output.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The entity to inspect.
   * @param array $anonymize_fields
   *   Field machine names.
   *
   * @return string[]
   *   Field names that would be anonymized.
   */
  public function previewAnonymizeFields(EntityInterface $archivable, array $anonymize_fields): array;

  /**
   * Returns the archive retention period for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array{days: int, source: string}
   *   Retention days and source label.
   */
  public function getArchiveRetention(NodeInterface $node): array;

}
