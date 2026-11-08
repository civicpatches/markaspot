<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Service;

use Drupal\Core\Database\Connection;
use Drupal\service_request\RevisionLog;

/**
 * Resolves moderation holds from the existing durable revision history.
 */
final class PublicationHold {

  /**
   * Candidate snapshot shared by all category queries in this request.
   *
   * @var int[]|null
   */
  private ?array $candidateIds = NULL;

  /**
   * Constructs the hold resolver.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Returns held report IDs, optionally restricted to one queued report.
   *
   * A current revision log is not a durable hold: ordinary edits replace its
   * events. Retained default revisions preserve the moderation decision until
   * a later explicit publication. Ignore drafts and revisions beyond the
   * current default revision, and decode events rather than trusting prose.
   * No additional field, state migration or time-based expiry is needed.
   *
   * @return int[]
   *   Unpublished reports hidden by moderation since their last publication.
   */
  public function heldIds(?int $nid = NULL): array {
    // Candidate selection scans history once, not once per category. Queue
    // rechecks always supply an ID and bypass this snapshot after moderation.
    if ($nid === NULL && $this->candidateIds !== NULL) {
      return $this->candidateIds;
    }
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node_revision', 'r', 'r.nid = n.nid AND r.vid <= n.vid');
    $query->join('node_field_revision', 'd', 'd.vid = r.vid AND d.langcode = n.langcode');
    $query->fields('r', ['nid', 'revision_log'])
      ->fields('d', ['status'])
      ->condition('n.type', 'service_request')
      ->condition('n.status', 0)
      ->condition('n.default_langcode', 1)
      ->condition('r.revision_default', 1)
      ->orderBy('r.vid', 'DESC');
    // Only publication boundaries and possible moderation events matter.
    $query->condition($query->orConditionGroup()
      ->condition('d.status', 1)
      ->condition('r.revision_log', '%' . $this->database->escapeLike('moderation_hidden') . '%', 'LIKE')
      ->condition('r.revision_log', '%' . $this->database->escapeLike('moderation_auto_hidden') . '%', 'LIKE'));
    if ($nid !== NULL) {
      $query->condition('n.nid', $nid);
    }
    $resolved = [];
    $held = [];
    foreach ($query->execute() as $row) {
      $id = (int) $row->nid;
      if (isset($resolved[$id])) {
        continue;
      }
      if ((bool) $row->status) {
        $resolved[$id] = TRUE;
        continue;
      }
      $events = RevisionLog::decode((string) $row->revision_log)['events'] ?? [];
      if (array_intersect(['moderation_hidden', 'moderation_auto_hidden'], $events)) {
        $held[] = $id;
        $resolved[$id] = TRUE;
      }
    }
    if ($nid === NULL) {
      $this->candidateIds = $held;
    }
    return $held;
  }

}
