<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects request IDs that are ambiguous within a jurisdiction hierarchy.
 *
 * @HealthCheck(
 *   id = "duplicate_request_id",
 *   label = @Translation("Duplicate request IDs within a jurisdiction"),
 *   severity = "error",
 *   description = @Translation("Finds service requests sharing a request ID within the same root jurisdiction, breaking Open311 detail lookups."),
 *   fix_hint = @Translation("Assign unique request IDs to the listed nodes within their root jurisdiction."),
 * )
 */
final class DuplicateRequestIdCheck extends HealthCheckPluginBase {

  /**
   * Maximum duplicate groups included as examples.
   */
  private const DETAIL_LIMIT = 10;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $schema = $this->database->schema();
    if (!$schema->tableExists('node_field_data')
      || !$schema->fieldExists('node_field_data', 'request_id')
      || !$schema->tableExists('node__field_jurisdiction')) {
      return $this->pass('Request ID or jurisdiction storage not present; check skipped.');
    }

    $scope = $this->scopedRequestsSql();
    // Count duplicate groups before limiting examples. No entities are loaded,
    // and healthy sites execute only this aggregate query plus schema checks.
    $duplicates = $this->database->query($scope . '
      SELECT jurisdiction_id, request_id, COUNT(*) AS duplicate_count,
        COUNT(*) OVER () AS total_groups
      FROM scoped_requests
      GROUP BY jurisdiction_id, request_id
      HAVING COUNT(*) > 1
      ORDER BY jurisdiction_id, request_id
      LIMIT ' . self::DETAIL_LIMIT)->fetchAll();

    if ($duplicates === []) {
      return $this->pass('No duplicate request IDs within a root jurisdiction.');
    }

    $details = [];
    $examples = [];
    foreach ($duplicates as $duplicate) {
      // Read IDs only on failure. SQL string aggregation can silently truncate
      // large groups (e.g. MySQL group_concat_max_len), hiding affected nodes.
      $nids = array_map('intval', $this->database->query($scope . '
        SELECT nid FROM scoped_requests
        WHERE jurisdiction_id = :gid AND request_id = :request_id
        ORDER BY nid', [
        ':gid' => $duplicate->jurisdiction_id,
        ':request_id' => $duplicate->request_id,
      ])->fetchCol());
      $details[] = [
        'jurisdiction_id' => (int) $duplicate->jurisdiction_id,
        'request_id' => $duplicate->request_id,
        'count' => (int) $duplicate->duplicate_count,
        'nids' => $nids,
      ];
      $examples[] = sprintf('%d: %s x%d (nids %s)',
        $duplicate->jurisdiction_id,
        $duplicate->request_id,
        $duplicate->duplicate_count,
        implode(', ', $nids),
      );
    }

    $count = (int) $duplicates[0]->total_groups;
    return $this->fail(
      $count,
      sprintf('%d duplicate request ID group(s) within a root jurisdiction. %s', $count, implode('; ', $examples)),
      $details,
      max(0, $count - self::DETAIL_LIMIT),
    );
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
      SELECT n.nid, n.request_id, $jurisdiction AS jurisdiction_id
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
