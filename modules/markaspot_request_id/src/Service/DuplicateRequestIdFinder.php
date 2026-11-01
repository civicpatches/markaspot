<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_id\Service;

use Drupal\Core\Database\Connection;

/**
 * Finds ambiguous current request IDs using their default root jurisdiction.
 */
final class DuplicateRequestIdFinder {

  /**
   * Constructs the finder.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Whether this site has the storage required for jurisdiction-scoped IDs.
   */
  public function isAvailable(): bool {
    $schema = $this->database->schema();
    return $schema->tableExists('node_field_data')
      && $schema->fieldExists('node_field_data', 'request_id')
      && $schema->tableExists('node__field_jurisdiction');
  }

  /**
   * Finds duplicate groups, optionally limiting examples, with the full total.
   *
   * @return object[]
   *   Root jurisdiction, request ID, duplicate count and total group count.
   */
  public function findGroups(?int $limit = NULL): array {
    if (!$this->isAvailable()) {
      return [];
    }
    return $this->database->query($this->scopedRequestsSql() . '
      SELECT jurisdiction_id, request_id, COUNT(*) AS duplicate_count,
        COUNT(*) OVER () AS total_groups
      FROM scoped_requests
      GROUP BY jurisdiction_id, request_id
      HAVING COUNT(*) > 1
      ORDER BY jurisdiction_id, request_id'
      . ($limit === NULL ? '' : ' LIMIT ' . max(1, $limit)))->fetchAll();
  }

  /**
   * Lists group members, oldest first, without loading entities or revisions.
   *
   * @return int[]
   *   Node IDs ordered by creation time, then node ID.
   */
  public function findNodeIds(int $jurisdictionId, string $requestId, ?int $limit = NULL): array {
    return array_map('intval', $this->database->query($this->scopedRequestsSql() . '
      SELECT nid FROM scoped_requests
      WHERE jurisdiction_id = :gid AND request_id = :request_id
      ORDER BY created, nid'
      . ($limit === NULL ? '' : ' LIMIT ' . max(1, $limit)), [
        ':gid' => $jurisdictionId,
        ':request_id' => $requestId,
      ])->fetchCol());
  }

  /**
   * Builds current, default-language requests scoped to their root counter.
   */
  private function scopedRequestsSql(): string {
    $hierarchy = '';
    $join = '';
    $jurisdiction = 'j.field_jurisdiction_target_id';
    $schema = $this->database->schema();
    if ($schema->tableExists('group__field_parent_jurisdiction')
      && $schema->tableExists('groups_field_data')) {
      // Only current default-language, first-value parent references count.
      // UNION also prevents corrupt cycles from looping forever.
      $hierarchy = '
        parents AS (
          SELECT g.id, p.field_parent_jurisdiction_target_id AS parent_id
          FROM {groups_field_data} g
          INNER JOIN {group__field_parent_jurisdiction} p
            ON p.entity_id = g.id AND p.langcode = g.langcode
            AND p.deleted = 0 AND p.delta = 0
          INNER JOIN {groups_field_data} parent
            ON parent.id = p.field_parent_jurisdiction_target_id
            AND parent.default_langcode = 1
          WHERE g.default_langcode = 1
        ),
        roots (id, root_id) AS (
          SELECT g.id, g.id FROM {groups_field_data} g
          LEFT JOIN parents p ON p.id = g.id
          WHERE g.default_langcode = 1 AND p.id IS NULL
          UNION
          SELECT p.id, r.root_id FROM parents p
          INNER JOIN roots r ON r.id = p.parent_id
        ),';
      $join = 'LEFT JOIN roots r ON r.id = j.field_jurisdiction_target_id';
      // Missing groups or invalid hierarchies must not hide local duplicates.
      $jurisdiction = 'COALESCE(r.root_id, j.field_jurisdiction_target_id)';
    }

    return "WITH RECURSIVE $hierarchy scoped_requests AS (
      SELECT n.nid, n.created, n.request_id, $jurisdiction AS jurisdiction_id
      FROM {node_field_data} n
      INNER JOIN {node__field_jurisdiction} j
        ON j.entity_id = n.nid AND j.langcode = n.langcode
        AND j.deleted = 0 AND j.delta = 0
      $join
      WHERE n.type = 'service_request' AND n.default_langcode = 1
        AND n.request_id IS NOT NULL AND n.request_id <> ''
        AND j.field_jurisdiction_target_id IS NOT NULL
    )";
  }

}
