<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_request_id\Service\DuplicateRequestIdFinder;
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
    protected ?DuplicateRequestIdFinder $finder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->has('markaspot_request_id.duplicate_finder') ? $container->get('markaspot_request_id.duplicate_finder') : NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->finder?->isAvailable()) {
      return $this->pass('Request ID or jurisdiction storage not present; check skipped.');
    }

    $duplicates = $this->finder->findGroups(self::DETAIL_LIMIT);

    if ($duplicates === []) {
      return $this->pass('No duplicate request IDs within a root jurisdiction.');
    }

    $details = [];
    $examples = [];
    foreach ($duplicates as $duplicate) {
      // Read IDs only on failure. SQL string aggregation can silently truncate
      // large groups (e.g. MySQL group_concat_max_len), hiding affected nodes.
      $nids = $this->finder->findNodeIds((int) $duplicate->jurisdiction_id, $duplicate->request_id);
      sort($nids, SORT_NUMERIC);
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

}
