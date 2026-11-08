<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\eca\EventSubscriber\DynamicSubscriber;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\Utility as SearchApiUtility;

/**
 * Class ArchiveService finds all service requests that can be archived.
 */
class ArchiveService implements ArchiveServiceInterface {

  /**
   * Archived nodes the backfill anonymizes per cron run at most.
   */
  public const BACKFILL_LIMIT = 50;

  /**
   * Seconds the backfill may spend per cron run.
   */
  public const BACKFILL_TIME_BUDGET = 20;

  /**
   * Lock shared by the cron catch-up and the operator command.
   */
  public const CATCH_UP_LOCK = 'markaspot_archive.catch_up';

  /**
   * State key of the last node id the backfill has processed.
   */
  public const BACKFILL_CURSOR_STATE = 'markaspot_archive.backfill_last_nid';

  /**
   * State key of the last node id the unpublish catch-up has processed.
   */
  public const UNPUBLISH_BACKLOG_CURSOR_STATE = 'markaspot_archive.unpublish_backlog_last_nid';

  /**
   * State key set once the unpublish catch-up has passed every node.
   */
  public const UNPUBLISH_BACKLOG_DONE_STATE = 'markaspot_archive.unpublish_backlog_done';

  /**
   * State key of the node IDs the unpublish catch-up failed to unpublish.
   */
  public const UNPUBLISH_BACKLOG_FAILED_STATE = 'markaspot_archive.unpublish_backlog_failed';

  /**
   * Telephone value written by ::anonymizedValue().
   */
  protected const ANONYMIZED_PHONE = '+49-0123459995555';

  /**
   * Field types whose stored values show whether they were anonymized.
   */
  protected const DETECTABLE_TYPES = [
    'email',
    'telephone',
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Field erased to an empty value instead of a token.
   */
  protected const ERASED_FIELD = 'field_feedback';

  /**
   * Entities anonymized during this request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, true>
   */
  protected \WeakMap $anonymizedEntities;

  /**
   * Anonymized values waiting for the revision cleanup after the save.
   *
   * @var \WeakMap<\Drupal\node\NodeInterface, array>
   */
  protected \WeakMap $pendingRevisionValues;

  /**
   * Node IDs the unpublish catch-up failed on during this request.
   *
   * They are retried on a later run, not again in the same one: the operator
   * command calls the catch-up batch by batch until nothing is processed.
   *
   * @var array<int, true>
   */
  protected array $unpublishFailedThisRequest = [];

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ArchiveService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    LoggerChannelInterface $logger,
    Connection $database,
    protected ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
    $this->database = $database;
    $this->anonymizedEntities = new \WeakMap();
    $this->pendingRevisionValues = new \WeakMap();
  }

  /**
   * Helper function.
   *
   * @param array $array
   *   Array with keys.
   *
   * @return array
   *   Return flattened array
   */
  public function arrayFlatten(array $array) {
    $result = [];
    foreach ($array as $value) {
      array_push($result, $value);
    }
    return $result;
  }

  /**
   * Load service requests.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Return nodes.
   */
  public function load(bool $all = FALSE, bool $advance_rotation = TRUE, bool $log = TRUE): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    // Global default days before archive if term override is empty.
    $default_days = (int) $config->get('default_days');
    // Status terms eligible for archiving.
    $status_tids = $this->arrayFlatten((array) $config->get('status_archivable'));
    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // Load service category term IDs.
    $category_items = $term_storage->loadTree('service_category');
    $category_ids = array_map(function ($item) {
      return $item->tid;
    }, $category_items);

    // Get a rotating subset of categories to process.
    $last_processed_index = $this->state->get('markaspot_archive.last_category_index', 0);

    // Process up to 10 categories per run, with rotation for fairness.
    $total_categories = count($category_ids);
    $batch_size = 10;

    if ($last_processed_index >= $total_categories) {
      $last_processed_index = 0;
    }

    // Get the categories for this run.
    if ($all) {
      $categories = $category_ids;
    }
    elseif ($total_categories <= $batch_size) {
      // If we have fewer than batch_size categories, just process all of them.
      $categories = $category_ids;
    }
    else {
      // Create a rotating window through the categories.
      $categories = array_slice($category_ids, $last_processed_index, $batch_size);
      // If we're near the end, wrap around to the beginning.
      if (count($categories) < $batch_size) {
        $categories = array_merge(
          $categories,
          array_slice($category_ids, 0, $batch_size - count($categories))
        );
      }
      // Update the index for next time.
      if ($advance_rotation) {
        $this->state->set('markaspot_archive.last_category_index',
          ($last_processed_index + $batch_size) % $total_categories);
      }
    }

    if ($log) {
      $this->logger->notice(
        'Processing categories @start to @end of @total (batch size: @batch)',
        [
          '@start' => $last_processed_index + 1,
          '@end' => $all ? $total_categories : min($last_processed_index + $batch_size, $total_categories),
          '@total' => $total_categories,
          '@batch' => $all ? $total_categories : $batch_size,
        ]
      );
    }
    $nids = [];
    if ($log) {
      $this->logger->notice('Processing @count categories in this run', ['@count' => count($categories)]);
    }
    foreach ($categories as $category_tid) {
      // Determine archive threshold days: term override or default.
      $term = $term_storage->load($category_tid);
      $retention = $this->getArchiveRetentionForCategory((int) $category_tid, $default_days);
      $day = $retention['days'];
      // Calculate cutoff timestamp.
      $date = strtotime('-' . $day . ' days');

      // Count query first to determine how many potential nodes would match.
      $count_query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN');
      $count_query->accessCheck(FALSE);
      $count_result = $count_query->count()->execute();

      // Debug the query.
      if ($log) {
        $this->logger->notice(
          'Category @cat (@term): days=@days, cutoff=@cutoff, status_tids=@status, potential_matches=@count',
          [
            '@cat' => $category_tid,
            '@term' => $term ? $term->label() : 'unknown',
            '@days' => $day,
            '@cutoff' => date('Y-m-d H:i:s', $date),
            '@status' => implode(',', $status_tids),
            '@count' => $count_result,
          ]
        );
      }

      // Now run the actual query with range limit.
      $query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN');
      if (!$all) {
        // Limit to 20 nodes per category.
        $query->range(0, 20);
      }
      $query->accessCheck(FALSE);

      $result = $query->execute();
      if (!empty($result)) {
        $nids = array_merge($nids, $result);
        if ($log) {
          $this->logger->notice('Found @count archivable nodes for category @cat', [
            '@count' => count($result),
            '@cat' => $category_tid,
          ]);
        }
      }
    }

    // Return a limited number of nodes to process this run.
    if (!$all) {
      $nids = array_slice($nids, 0, 50);
    }
    if ($log) {
      $this->logger->notice('Returning @count nodes for archiving', ['@count' => count($nids)]);
    }
    return $node_storage->loadMultiple($nids);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeConfiguredFields(array $configured_fields): array {
    $fields = [];
    foreach ($configured_fields as $field_name => $value) {
      $name = is_numeric($field_name) ? $value : $field_name;
      if (
        !is_string($name)
        || $name === ''
        || !is_scalar($value)
        || is_numeric($value)
        || $value === ''
        || $value === FALSE
      ) {
        continue;
      }
      $fields[$name] = $name;
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymize(EntityInterface $archivable, array $anonymize_fields): array {
    if (!$archivable instanceof FieldableEntityInterface) {
      return [];
    }

    $anonymized_values = [];
    foreach ($this->fieldNames($anonymize_fields) as $field_name) {
      if (!$archivable->hasField($field_name)) {
        continue;
      }

      $items = $archivable->get($field_name);
      $type = $items->getFieldDefinition()->getType();
      $value = $field_name === 'field_feedback' ? '' : $this->anonymizedValue($type);
      if ($value === NULL) {
        if ($this->fieldHasValue($archivable, $field_name)) {
          $this->logger->warning('field @field of type @type skipped, not anonymizable', [
            '@field' => $field_name,
            '@type' => $type,
          ]);
        }
        continue;
      }

      $anonymized_values[$field_name] = [
        'type' => $type,
        'value' => $value,
      ];
      foreach ($this->fieldableTranslations($archivable) as $translation) {
        $translation_items = $translation->get($field_name);
        if ($translation_items->isEmpty()) {
          continue;
        }
        foreach ($translation_items as $item) {
          $item->set('value', $value);
          if ($type === 'text_with_summary') {
            $item->set('summary', $value);
          }
        }
      }
    }

    $this->anonymizedEntities[$archivable] = TRUE;
    return $anonymized_values;
  }

  /**
   * {@inheritdoc}
   */
  public function unpublishOnArchiveTransition(NodeInterface $node): bool {
    $config = $this->configFactory->get('markaspot_archive.settings');
    if ($config->get('unpublish') != 1 || !$this->isArchiveTransition($node)) {
      return FALSE;
    }
    $unpublished = $this->unpublishTranslations($node);
    if ($unpublished) {
      $this->logger->notice(
        'Node ID @nid unpublished on transition to the archived status.',
        ['@nid' => $node->isNew() ? 'new' : $node->id()]
      );
    }
    return $unpublished;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymizeOnArchiveTransition(NodeInterface $node): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    if ($config->get('anonymize') != 1 || !$this->isArchiveTransition($node)) {
      return [];
    }
    // The queue worker and the staff command anonymize before they save.
    if (isset($this->anonymizedEntities[$node])) {
      return [];
    }

    $fields = $this->normalizeConfiguredFields((array) $config->get('anonymize_fields'));
    if ($fields === []) {
      return [];
    }
    $anonymized_values = $this->anonymize($node, $fields);
    if ($anonymized_values === []) {
      return [];
    }
    // Completed after the save: a request created in the archived status
    // gets its derived data, such as the original text PII redaction keeps,
    // only during the save, and its revision log is stored then.
    $this->pendingRevisionValues[$node] = $anonymized_values;
    $this->logger->notice(
      'Node ID @nid anonymized on transition to the archived status: @fields',
      [
        '@nid' => $node->isNew() ? 'new' : $node->id(),
        '@fields' => implode(', ', array_keys($anonymized_values)),
      ]
    );
    return $anonymized_values;
  }

  /**
   * {@inheritdoc}
   */
  public function finishArchiveTransition(NodeInterface $node): int {
    if (!isset($this->pendingRevisionValues[$node])) {
      return 0;
    }
    $anonymized_values = $this->pendingRevisionValues[$node];
    unset($this->pendingRevisionValues[$node]);
    return $this->anonymizeRevisions($node, $anonymized_values);
  }

  /**
   * {@inheritdoc}
   */
  public function unpublishArchivedBacklog(
    int $limit = self::BACKFILL_LIMIT,
    int $time_budget = self::BACKFILL_TIME_BUDGET,
  ): array {
    $result = ['processed' => 0, 'changed' => 0, 'failed' => 0];
    $config = $this->configFactory->get('markaspot_archive.settings');
    $archived_status = (string) $config->get('status_archived');
    if (
      $config->get('unpublish') != 1
      || $archived_status === ''
      || $this->state->get(self::UNPUBLISH_BACKLOG_DONE_STATE)
    ) {
      return $result;
    }

    // Requests that failed on an earlier run come first.
    $failed = $this->unpublishFailures();
    $retries = array_slice(array_values(array_diff($failed, array_keys($this->unpublishFailedThisRequest))), 0, $limit);
    $cursor = (int) $this->state->get(self::UNPUBLISH_BACKLOG_CURSOR_STATE, 0);
    $fresh = count($retries) < $limit
      ? $this->publishedArchivedCandidates($archived_status, $cursor, $limit - count($retries))
      : [];
    if ($retries === [] && $fresh === []) {
      // Every node has been passed once and no failure is left to retry.
      // Later archive transitions unpublish on presave, and a report staff
      // republish afterwards stays published.
      if ($failed === []) {
        $this->state->set(self::UNPUBLISH_BACKLOG_DONE_STATE, TRUE);
        $this->state->delete(self::UNPUBLISH_BACKLOG_CURSOR_STATE);
        $this->state->delete(self::UNPUBLISH_BACKLOG_FAILED_STATE);
      }
      return $result;
    }

    $started = microtime(TRUE);
    foreach ([...$retries, ...$fresh] as $nid) {
      if (microtime(TRUE) - $started > $time_budget) {
        break;
      }
      $nid = (int) $nid;
      if (!in_array($nid, $retries, TRUE)) {
        $cursor = $nid;
      }
      $result['processed']++;
      try {
        $changed = $this->changeArchivedRequest($nid, $archived_status, TRUE, function (NodeInterface $node): bool {
          if (!$this->unpublishTranslations($node)) {
            return FALSE;
          }
          // Keep the changed time: unpublishing is no change of the request.
          $node->setSyncing(TRUE);
          $node->save();
          return TRUE;
        });
        if ($changed) {
          $result['changed']++;
        }
        // Unpublished, or no longer archived or published: nothing to retry.
        unset($failed[$nid]);
      }
      catch (DatabaseException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        $result['failed']++;
        $failed[$nid] = $nid;
        $this->unpublishFailedThisRequest[$nid] = TRUE;
        $this->logger->warning(
          'Archived node @nid could not be unpublished, a later run retries it: @error',
          ['@nid' => $nid, '@error' => $e->getMessage()]
        );
      }
    }
    $this->state->set(self::UNPUBLISH_BACKLOG_CURSOR_STATE, $cursor);
    if ($failed === []) {
      $this->state->delete(self::UNPUBLISH_BACKLOG_FAILED_STATE);
    }
    else {
      $this->state->set(self::UNPUBLISH_BACKLOG_FAILED_STATE, $failed);
    }

    if ($result['changed'] > 0 || $result['failed'] > 0) {
      $this->logger->notice(
        'Unpublished @count archived service requests that were still published (@failed failed, up to node ID @nid).',
        ['@count' => $result['changed'], '@failed' => $result['failed'], '@nid' => $cursor]
      );
    }
    return $result;
  }

  /**
   * Returns the node IDs the unpublish catch-up has yet to retry.
   *
   * @return array<int, int>
   *   Node IDs keyed by node ID.
   */
  protected function unpublishFailures(): array {
    $failed = [];
    foreach ((array) $this->state->get(self::UNPUBLISH_BACKLOG_FAILED_STATE, []) as $nid) {
      $failed[(int) $nid] = (int) $nid;
    }
    return $failed;
  }

  /**
   * {@inheritdoc}
   */
  public function backfillArchived(
    int $limit = self::BACKFILL_LIMIT,
    int $time_budget = self::BACKFILL_TIME_BUDGET,
  ): array {
    $result = ['processed' => 0, 'changed' => 0, 'failed' => 0];
    $config = $this->configFactory->get('markaspot_archive.settings');
    $archived_status = (string) $config->get('status_archived');
    if ($config->get('anonymize') != 1 || $archived_status === '') {
      return $result;
    }
    $fields = $this->normalizeConfiguredFields((array) $config->get('anonymize_fields'));
    if ($fields === []) {
      return $result;
    }
    $detectable = $this->detectableFields($fields);

    $cursor = (int) $this->state->get(self::BACKFILL_CURSOR_STATE, 0);
    $candidates = $this->backfillCandidates($archived_status, $detectable, $cursor, $limit);
    if ($candidates === []) {
      // Start over on the next run to catch nodes archived meanwhile.
      if ($cursor > 0) {
        $this->state->set(self::BACKFILL_CURSOR_STATE, 0);
      }
      return $result;
    }

    $started = microtime(TRUE);
    foreach ($candidates as $nid => $plain_contact_data) {
      if (microtime(TRUE) - $started > $time_budget) {
        break;
      }
      $cursor = (int) $nid;
      $result['processed']++;
      try {
        $changed = $this->changeArchivedRequest((int) $nid, $archived_status, FALSE, $plain_contact_data
          ? fn (NodeInterface $node): bool => $this->anonymizeArchivedRequest($node, $fields, $detectable)
          : fn (NodeInterface $node): bool => $this->eraseRetainedPersonalData($node));
        if ($changed) {
          $result['changed']++;
        }
      }
      catch (DatabaseException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        $result['failed']++;
        $this->logger->warning(
          'Archived node @nid could not be anonymized: @error',
          ['@nid' => $nid, '@error' => $e->getMessage()]
        );
      }
    }
    $this->state->set(self::BACKFILL_CURSOR_STATE, $cursor);

    if ($result['changed'] > 0 || $result['failed'] > 0) {
      $this->logger->notice(
        'Anonymized @count archived service requests with plain personal data (@failed failed, up to node ID @nid).',
        ['@count' => $result['changed'], '@failed' => $result['failed'], '@nid' => $cursor]
      );
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function countUnpublishBacklog(): int {
    $archived_status = (string) $this->configFactory
      ->get('markaspot_archive.settings')
      ->get('status_archived');
    if ($archived_status === '' || $this->state->get(self::UNPUBLISH_BACKLOG_DONE_STATE)) {
      return 0;
    }
    $cursor = (int) $this->state->get(self::UNPUBLISH_BACKLOG_CURSOR_STATE, 0);
    $pending = $this->publishedArchivedCandidates($archived_status, $cursor, NULL);
    $failed = array_diff($this->unpublishFailures(), $pending);
    if ($failed !== []) {
      // Failures below the cursor that are still archived and published.
      $pending = [...$pending, ...$this->publishedArchivedCandidates($archived_status, 0, NULL, array_values($failed))];
    }
    return count($pending);
  }

  /**
   * {@inheritdoc}
   */
  public function countBackfillCandidates(): int {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $archived_status = (string) $config->get('status_archived');
    $fields = $this->normalizeConfiguredFields((array) $config->get('anonymize_fields'));
    if ($archived_status === '' || $fields === []) {
      return 0;
    }
    return count($this->backfillCandidates($archived_status, $this->detectableFields($fields), 0, NULL));
  }

  /**
   * {@inheritdoc}
   */
  public function countSearchIndexBacklog(): ?int {
    $backlog = $this->searchIndexBacklog();
    return $backlog === NULL ? NULL : array_sum(array_map('count', $backlog));
  }

  /**
   * {@inheritdoc}
   */
  public function indexSearchIndexBacklog(): array {
    $result = ['processed' => 0, 'changed' => 0, 'failed' => 0];
    $storage = $this->entityTypeManager->getStorage('search_api_index');
    foreach ($this->searchIndexBacklog() ?? [] as $index_id => $item_ids) {
      $index = $storage->load($index_id);
      if (!$index instanceof IndexInterface || $index->isReadOnly()) {
        continue;
      }
      $batch_size = max(1, (int) ($index->getOption('cron_limit', 50) ?: 50));
      foreach (array_chunk($item_ids, $batch_size) as $chunk) {
        $result['processed'] += count($chunk);
        try {
          $indexed = count($index->indexSpecificItems($index->loadItemsMultiple($chunk)));
        }
        catch (\Exception $e) {
          $indexed = 0;
          $this->logger->warning('Search index @index could not index @count archived service requests: @error', [
            '@index' => $index_id,
            '@count' => count($chunk),
            '@error' => $e->getMessage(),
          ]);
        }
        $result['changed'] += $indexed;
        $result['failed'] += count($chunk) - $indexed;
      }
    }
    return $result;
  }

  /**
   * Returns the search index items of archived requests awaiting indexing.
   *
   * Search API tracks every save of a request, syncing ones included, and
   * indexes the item at the end of the request (index_directly) or on cron.
   * Until then the index still holds the old text. Items of indexes that are
   * disabled or have no node datasource are left out.
   *
   * @return array<string, string[]>|null
   *   Item IDs keyed by index ID, NULL when Search API is not enabled.
   */
  protected function searchIndexBacklog(): ?array {
    if ($this->moduleHandler === NULL || !$this->moduleHandler->moduleExists('search_api')) {
      return NULL;
    }
    $archived_status = (string) $this->configFactory->get('markaspot_archive.settings')->get('status_archived');
    $archived = $archived_status === '' ? NULL : $this->archivedRequestsQuery($archived_status);
    $backlog = [];
    if ($archived === NULL) {
      return $backlog;
    }
    foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
      if (
        !$index instanceof IndexInterface
        || !$index->status()
        || !$index->isValidDatasource('entity:node')
        || !$index->hasValidTracker()
      ) {
        continue;
      }
      $items = [];
      foreach ($index->getTrackerInstance()->getRemainingItems(-1, 'entity:node') as $item_id) {
        [, $raw_id] = SearchApiUtility::splitCombinedId((string) $item_id);
        $items[(int) strtok((string) $raw_id, ':')][] = (string) $item_id;
      }
      $pending = [];
      foreach (array_chunk(array_keys($items), 1000) as $nids) {
        $query = clone $archived;
        $query->condition('archived.entity_id', $nids, 'IN');
        foreach ($query->execute()->fetchCol() as $nid) {
          array_push($pending, ...$items[(int) $nid]);
        }
      }
      if ($pending !== []) {
        $backlog[(string) $index->id()] = array_values(array_unique($pending));
      }
    }
    return $backlog;
  }

  /**
   * Returns the configured fields whose values reveal plain contact data.
   *
   * E-mail and telephone values show whether they were anonymized; string
   * and text values are anonymized to a 10 character hex token, and
   * field_feedback to an empty value. Booleans are left out: a plain flag
   * identifies nobody.
   *
   * @param array $fields
   *   Configured field machine names.
   *
   * @return array<string, string>
   *   Field types keyed by field machine name.
   */
  protected function detectableFields(array $fields): array {
    $detectable = [];
    foreach ($this->fieldNames($fields) as $field_name) {
      $type = $this->nodeFieldStorage($field_name)?->getType();
      if (in_array($type, self::DETECTABLE_TYPES, TRUE)) {
        $detectable[$field_name] = $type;
      }
    }
    return $detectable;
  }

  /**
   * Returns archived requests that still hold personal data.
   *
   * Three sources: plain values of the configured contact fields in any
   * revision, revision logs that still hold a free-text message (see
   * RevisionLogScrubber), and data derived from the report that modules
   * report through
   * hook_markaspot_archive_pending_erasure(), such as the original text PII
   * redaction retained.
   *
   * @param string $archived_status
   *   The archived status term id.
   * @param array<string, string> $detectable
   *   Field types keyed by field machine name.
   * @param int $cursor
   *   Only node ids above this one are returned.
   * @param int|null $limit
   *   Maximum number of node ids, NULL for all.
   *
   * @return array<int, bool>
   *   TRUE for requests whose contact fields still hold plain values, FALSE
   *   for those with only revision logs or derived data left, keyed by node
   *   id in ascending order.
   */
  protected function backfillCandidates(string $archived_status, array $detectable, int $cursor, ?int $limit): array {
    $candidates = [];
    foreach ($this->contactFieldCandidates($archived_status, $detectable, $cursor, $limit) as $nid) {
      $candidates[$nid] = TRUE;
    }
    $other = [
      ...$this->revisionLogCandidates($archived_status, $cursor, $limit),
      ...$this->derivedDataCandidates($archived_status, $cursor, $limit),
    ];
    foreach ($other as $nid) {
      $candidates[(int) $nid] ??= FALSE;
    }
    ksort($candidates);
    // Each source returned its first $limit node ids above the cursor, so the
    // first $limit of the union are complete.
    return $limit === NULL ? $candidates : array_slice($candidates, 0, $limit, TRUE);
  }

  /**
   * Returns archived requests with plain contact data in any revision.
   *
   * Joins the revision tables of the contact fields with the current status,
   * so values left in previous revisions are found as well.
   *
   * @param string $archived_status
   *   The archived status term id.
   * @param array<string, string> $detectable
   *   Field types keyed by field machine name.
   * @param int $cursor
   *   Only node ids above this one are returned.
   * @param int|null $limit
   *   Maximum number of node ids, NULL for all.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  protected function contactFieldCandidates(string $archived_status, array $detectable, int $cursor, ?int $limit): array {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof SqlContentEntityStorage) {
      return [];
    }
    $mapping = $storage->getTableMapping();
    $status = $this->nodeFieldStorage('field_status');
    if ($status === NULL || !$mapping->requiresDedicatedTableStorage($status)) {
      return [];
    }
    $status_table = $mapping->getDedicatedDataTableName($status);
    $status_column = $mapping->getFieldColumnName($status, 'target_id');

    $nids = [];
    foreach ($detectable as $field_name => $type) {
      $definition = $this->nodeFieldStorage($field_name);
      if ($definition === NULL || !$mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }
      $column = 'r.' . $mapping->getFieldColumnName($definition, 'value');
      $query = $this->database->select($mapping->getDedicatedRevisionTableName($definition), 'r');
      $query->join($status_table, 's', 's.entity_id = r.entity_id');
      $query->addField('r', 'entity_id');
      $query->condition('s.bundle', 'service_request')
        ->condition('s.' . $status_column, $archived_status)
        ->condition('r.entity_id', $cursor, '>')
        ->condition($column, '', '<>');
      match (TRUE) {
        // Any stored text left is plain.
        $field_name === self::ERASED_FIELD => NULL,
        $type === 'email' => $query->condition($column, '%' . $this->database->escapeLike('@anonymized.off'), 'NOT LIKE'),
        $type === 'telephone' => $query->condition($column, self::ANONYMIZED_PHONE, '<>'),
        default => $query->condition($column, '^[0-9a-f]{10}$', 'NOT REGEXP'),
      };
      $query->distinct()->orderBy('r.entity_id');
      if ($limit !== NULL) {
        $query->range(0, $limit);
      }
      $nids = array_merge($nids, array_map('intval', $query->execute()->fetchCol()));
    }
    $nids = array_values(array_unique($nids));
    sort($nids);
    return $limit === NULL ? $nids : array_slice($nids, 0, $limit);
  }

  /**
   * Returns archived requests whose revision logs still hold a message.
   *
   * Only the decoded free text counts, as RevisionLogScrubber decides it:
   * the action metadata of an envelope, such as a status label quoting a
   * number, keeps no request a candidate. A replaced log is no candidate.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  protected function revisionLogCandidates(string $archived_status, int $cursor, ?int $limit): array {
    $archived = $this->archivedRequestsQuery($archived_status);
    $columns = $this->revisionLogColumns('node');
    if ($archived === NULL || $columns === NULL) {
      return [];
    }
    $query = $this->revisionLogQuery($columns);
    $query->condition('r.' . $columns['id'], $archived, 'IN')
      ->condition('r.' . $columns['id'], $cursor, '>')
      ->orderBy('r.' . $columns['id']);
    $nids = [];
    foreach ($query->execute() as $row) {
      $nid = (int) $row->entity_id;
      if (isset($nids[$nid]) || !RevisionLogScrubber::needsScrubbing((string) $row->log)) {
        continue;
      }
      $nids[$nid] = $nid;
      if ($limit !== NULL && count($nids) >= $limit) {
        break;
      }
    }
    return array_values($nids);
  }

  /**
   * Returns the revision table and the columns holding the revision log.
   *
   * @return array{table: string, id: string, revision: string, log: string}|null
   *   Table and column names, NULL for entity types without revision logs.
   */
  protected function revisionLogColumns(string $entity_type_id): ?array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $columns = [
      'table' => (string) $entity_type->getRevisionTable(),
      'id' => (string) $entity_type->getKey('id'),
      'revision' => (string) $entity_type->getKey('revision'),
      'log' => (string) ($entity_type->getRevisionMetadataKey('revision_log_message') ?? ''),
    ];
    return in_array('', $columns, TRUE) ? NULL : $columns;
  }

  /**
   * Returns a query for revision logs that may still hold a message.
   *
   * Selects entity_id, revision_id and log of the revision table. The
   * conditions only narrow the rows down cheaply; RevisionLogScrubber
   * decides on each of them. Left out are empty logs, replaced legacy logs
   * and envelopes in the canonical encoding of RevisionLog::build(), which
   * ends with the message, whose message is empty or replaced.
   *
   * @param array{table: string, id: string, revision: string, log: string} $columns
   *   See ::revisionLogColumns().
   */
  protected function revisionLogQuery(array $columns): SelectInterface {
    $log = 'r.' . $columns['log'];
    $envelope = '%' . $this->database->escapeLike('"schema":' . json_encode(RevisionLogScrubber::SCHEMA)) . '%';
    $query = $this->database->select($columns['table'], 'r');
    $query->addField('r', $columns['id'], 'entity_id');
    $query->addField('r', $columns['revision'], 'revision_id');
    $query->addField('r', $columns['log'], 'log');
    $query->isNotNull($log)
      ->condition($log, '', '<>')
      ->condition($log, RevisionLogScrubber::PLACEHOLDER, '<>');
    // No envelope, or one whose message is neither empty nor replaced.
    $open_message = $query->andConditionGroup();
    foreach (['', RevisionLogScrubber::PLACEHOLDER] as $message) {
      $suffix = '"message":' . json_encode($message, RevisionLogScrubber::JSON_FLAGS) . '}';
      $open_message->condition($log, '%' . $this->database->escapeLike($suffix), 'NOT LIKE');
    }
    $query->condition($query->orConditionGroup()
      ->condition($log, $envelope, 'NOT LIKE')
      ->condition($open_message));
    return $query;
  }

  /**
   * Returns archived requests whose derived data modules still have to erase.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  protected function derivedDataCandidates(string $archived_status, int $cursor, ?int $limit): array {
    $archived = $this->archivedRequestsQuery($archived_status);
    if ($this->moduleHandler === NULL || $archived === NULL) {
      return [];
    }
    $nids = [];
    $this->moduleHandler->invokeAllWith(
      'markaspot_archive_pending_erasure',
      function (callable $hook) use (&$nids, $archived, $cursor, $limit): void {
        foreach ((array) $hook(clone $archived, $cursor, $limit) as $nid) {
          $nids[] = (int) $nid;
        }
      }
    );
    $nids = array_values(array_unique($nids));
    sort($nids);
    return $nids;
  }

  /**
   * Returns a query for the node ids of archived service requests.
   */
  protected function archivedRequestsQuery(string $archived_status): ?SelectInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $status = $this->nodeFieldStorage('field_status');
    if (!$storage instanceof SqlContentEntityStorage || $status === NULL) {
      return NULL;
    }
    $mapping = $storage->getTableMapping();
    if (!$mapping->requiresDedicatedTableStorage($status)) {
      return NULL;
    }
    return $this->database->select($mapping->getDedicatedDataTableName($status), 'archived')
      ->fields('archived', ['entity_id'])
      ->condition('archived.bundle', 'service_request')
      ->condition('archived.' . $mapping->getFieldColumnName($status, 'target_id'), $archived_status);
  }

  /**
   * Anonymizes the contact fields of an archived request in all revisions.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The reloaded, locked request.
   * @param array<string, string> $fields
   *   Configured anonymize fields.
   * @param array<string, string> $detectable
   *   Field types keyed by field machine name.
   *
   * @return bool
   *   TRUE when the request was anonymized.
   */
  protected function anonymizeArchivedRequest(NodeInterface $node, array $fields, array $detectable): bool {
    $anonymized_values = $this->anonymize($node, $fields);
    // A field empty on the current revision may still hold plain data in
    // previous revisions.
    foreach ($detectable as $field_name => $type) {
      if (!isset($anonymized_values[$field_name]) && $node->hasField($field_name)) {
        $anonymized_values[$field_name] = [
          'type' => $type,
          'value' => $field_name === self::ERASED_FIELD ? '' : $this->anonymizedValue($type),
        ];
      }
    }
    if ($anonymized_values === []) {
      return FALSE;
    }
    // Keep the changed time: anonymizing is no change of the request.
    $node->setSyncing(TRUE);
    $node->save();
    $this->anonymizeRevisions($node, $anonymized_values);
    return TRUE;
  }

  /**
   * Erases what is left of an archived request whose contact data is clean.
   *
   * Contact fields anonymized earlier, but revision logs or derived data such
   * as a retained PII original still hold personal data. The request itself
   * is not saved: no entity hook runs and no revision is added.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The reloaded, locked request.
   *
   * @return bool
   *   TRUE, the erasure ran.
   */
  protected function eraseRetainedPersonalData(NodeInterface $node): bool {
    $this->moduleHandler?->invokeAll('markaspot_report_anonymized', [$node]);
    $this->scrubRevisionLogs($node);
    $this->entityTypeManager->getStorage('node')->resetCache([$node->id()]);
    return TRUE;
  }

  /**
   * Returns published service requests that hold the archived status.
   *
   * @param string $archived_status
   *   The archived status term id.
   * @param int $cursor
   *   Only node ids above this one are returned.
   * @param int|null $limit
   *   Maximum number of node ids, NULL for all.
   * @param int[] $only
   *   Only these node ids, if any.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  protected function publishedArchivedCandidates(string $archived_status, int $cursor, ?int $limit, array $only = []): array {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof SqlContentEntityStorage) {
      return [];
    }
    $mapping = $storage->getTableMapping();
    $status = $this->nodeFieldStorage('field_status');
    $data_table = $storage->getDataTable();
    if ($status === NULL || $data_table === NULL || !$mapping->requiresDedicatedTableStorage($status)) {
      return [];
    }
    $query = $this->database->select($data_table, 'n');
    $query->join($mapping->getDedicatedDataTableName($status), 's', 's.entity_id = n.nid');
    $query->addField('n', 'nid');
    $query->condition('n.type', 'service_request')
      ->condition('n.status', 1)
      ->condition('n.nid', $cursor, '>')
      ->condition('s.' . $mapping->getFieldColumnName($status, 'target_id'), $archived_status);
    if ($only !== []) {
      $query->condition('n.nid', $only, 'IN');
    }
    $query->distinct()->orderBy('n.nid');
    if ($limit !== NULL) {
      $query->range(0, $limit);
    }
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Changes one archived request of the catch-up under a row lock.
   *
   * Staff can reopen a request between the candidate query and its turn. The
   * catch-up must neither anonymize nor unpublish it then: anonymization is
   * irreversible, and saving the stale copy would undo the staff change. The
   * request is locked, its status read from storage and the request
   * reloaded, all in one transaction with the change. ECA stays off for the
   * load and the change (see ::withoutEca()).
   *
   * @param int $nid
   *   The node ID.
   * @param string $archived_status
   *   The archived status term ID.
   * @param bool $published
   *   TRUE when the request must also still be published.
   * @param callable(\Drupal\node\NodeInterface): bool $change
   *   Changes the reloaded request; returns TRUE when it changed something.
   *
   * @return bool
   *   TRUE when the request was changed.
   */
  protected function changeArchivedRequest(int $nid, string $archived_status, bool $published, callable $change): bool {
    $transaction = $this->database->startTransaction();
    try {
      return $this->withoutEca(function () use ($nid, $archived_status, $published, $change): bool {
        $node = $this->lockArchivedRequest($nid, $archived_status, $published)
          ? $this->entityTypeManager->getStorage('node')->loadUnchanged($nid)
          : NULL;
        if (
          !$node instanceof NodeInterface
          || $node->bundle() !== 'service_request'
          || (string) $node->get('field_status')->target_id !== $archived_status
        ) {
          $this->logger->notice(
            'Node ID @nid changed after the archive catch-up selected it and was left as it is.',
            ['@nid' => $nid]
          );
          return FALSE;
        }
        return $change($node);
      });
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      // Commits, or ends the rolled back transaction.
      unset($transaction);
    }
  }

  /**
   * Runs a catch-up change with ECA processing switched off.
   *
   * The catch-up re-saves old archived requests as a system write. Syncing
   * saves keep the Mark-a-Spot notifications quiet, but ECA dispatches its
   * entity events for them as well, so a tenant model could mail on these
   * saves (lhm, for example, mails the organisation when field_feedback
   * changes, and the anonymization empties it). DynamicSubscriber::setActive()
   * is ECA's documented runtime switch for this; the previous state is
   * restored afterwards, as markaspot_request_id_update_11902() does. Staff
   * saves and archive transitions keep running ECA as before. The settings.php
   * switch $settings['eca_disable'] would turn ECA off for the whole site.
   *
   * @param callable(): T $callback
   *   The change.
   *
   * @return T
   *   The result of the change.
   *
   * @template T
   */
  protected function withoutEca(callable $callback): mixed {
    if (
      !class_exists(DynamicSubscriber::class)
      || !method_exists(DynamicSubscriber::class, 'setActive')
      || !method_exists(DynamicSubscriber::class, 'isActive')
    ) {
      return $callback();
    }
    $active = DynamicSubscriber::isActive();
    DynamicSubscriber::setActive(FALSE);
    try {
      return $callback();
    }
    finally {
      DynamicSubscriber::setActive($active);
    }
  }

  /**
   * Locks a request and checks its stored status.
   *
   * Every save of a node updates its base table row first. Locking that row
   * waits for a save in progress and makes later saves wait for the
   * catch-up; the status rows are then read with the latest committed data.
   *
   * @return bool
   *   TRUE while the request has the archived status (and is published, if
   *   asked for).
   */
  protected function lockArchivedRequest(int $nid, string $archived_status, bool $published): bool {
    $storage = $this->entityTypeManager->getStorage('node');
    $status = $this->nodeFieldStorage('field_status');
    if (!$storage instanceof SqlContentEntityStorage || $status === NULL) {
      return FALSE;
    }
    $mapping = $storage->getTableMapping();
    $this->database->select((string) $storage->getBaseTable(), 'n')
      ->fields('n', ['nid'])
      ->condition('n.nid', $nid)
      ->forUpdate()
      ->execute()
      ->fetchField();
    $statuses = $this->database->select($mapping->getDedicatedDataTableName($status), 's')
      ->fields('s', [$mapping->getFieldColumnName($status, 'target_id')])
      ->condition('s.entity_id', $nid)
      ->condition('s.deleted', 0)
      ->forUpdate()
      ->execute()
      ->fetchCol();
    if ($statuses === [] || array_unique(array_map('strval', $statuses)) !== [$archived_status]) {
      return FALSE;
    }
    if (!$published) {
      return TRUE;
    }
    return (bool) $this->database->select((string) $storage->getDataTable(), 'd')
      ->fields('d', ['nid'])
      ->condition('d.nid', $nid)
      ->condition('d.status', 1)
      ->forUpdate()
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Unpublishes every published translation of a node.
   *
   * @return bool
   *   TRUE when a translation was unpublished.
   */
  protected function unpublishTranslations(NodeInterface $node): bool {
    $unpublished = FALSE;
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $translation = $node->getTranslation($langcode);
      if ($translation->isPublished()) {
        $translation->setUnpublished();
        $unpublished = TRUE;
      }
    }
    return $unpublished;
  }

  /**
   * Returns the storage definition of a configurable node field.
   */
  protected function nodeFieldStorage(string $field_name): ?FieldStorageDefinitionInterface {
    $definition = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('node.' . $field_name);
    return $definition instanceof FieldStorageDefinitionInterface ? $definition : NULL;
  }

  /**
   * Returns TRUE when the save in progress moves a request into the archive.
   *
   * A request moves into the archive when its status is the archived status
   * and it is new or its unchanged node had another status. Saving an already
   * archived request again is no transition.
   */
  protected function isArchiveTransition(NodeInterface $node): bool {
    if ($node->bundle() !== 'service_request' || !$node->hasField('field_status')) {
      return FALSE;
    }
    $archived_status = (string) $this->configFactory
      ->get('markaspot_archive.settings')
      ->get('status_archived');
    if ($archived_status === '' || (string) $node->get('field_status')->target_id !== $archived_status) {
      return FALSE;
    }
    $original = $this->originalNode($node);
    return $original === NULL || (string) $original->get('field_status')->target_id !== $archived_status;
  }

  /**
   * Returns the unchanged node of the save in progress, if any.
   */
  protected function originalNode(NodeInterface $node): ?NodeInterface {
    // getOriginal() exists since Drupal 11.2; the property before.
    $original = method_exists($node, 'getOriginal')
      ? $node->getOriginal()
      : ($node->original ?? NULL);
    return $original instanceof NodeInterface ? $original : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymizeRevisions(EntityInterface $archivable, array $anonymized_values): int {
    if ($anonymized_values === []) {
      return 0;
    }
    // Both anonymisation paths (queue worker and drush) end here after saving
    // the anonymised entity. Modules holding data derived from the report
    // (e.g. AI reasoning that may quote the reporter) purge it now; the
    // anonymisation itself never deletes the report.
    if ($this->moduleHandler !== NULL) {
      $this->moduleHandler->invokeAll('markaspot_report_anonymized', [$archivable]);
    }

    if (!$archivable instanceof FieldableEntityInterface || !$archivable instanceof RevisionableInterface) {
      return 0;
    }
    $updated = $this->anonymizePreviousRevisions($archivable, $anonymized_values);
    $scrubbed = $this->scrubRevisionLogs($archivable);
    if ($updated > 0 || $scrubbed > 0) {
      $this->entityTypeManager->getStorage($archivable->getEntityTypeId())->resetCache([$archivable->id()]);
    }
    return $updated;
  }

  /**
   * Overwrites the anonymized fields in the previous revisions.
   *
   * @return int
   *   Number of revision field rows updated.
   */
  protected function anonymizePreviousRevisions(FieldableEntityInterface&RevisionableInterface $archivable, array $anonymized_values): int {
    $storage = $this->entityTypeManager->getStorage($archivable->getEntityTypeId());
    if (!$storage instanceof RevisionableStorageInterface) {
      return 0;
    }

    $current_revision_id = (int) $archivable->getRevisionId();
    $revision_ids = array_map('intval', $storage->revisionIds($archivable));
    $previous_revision_ids = array_values(array_filter(
      $revision_ids,
      static function (int $revision_id) use ($current_revision_id): bool {
        return $revision_id !== $current_revision_id;
      }
    ));
    if ($previous_revision_ids === []) {
      return 0;
    }

    $updated = 0;
    foreach ($anonymized_values as $field_name => $info) {
      if (!$archivable->hasField($field_name) || !isset($info['value'])) {
        continue;
      }

      $column_info = $this->revisionColumnInfo(
        $archivable,
        $field_name,
        (string) ($info['type'] ?? '')
      );
      if ($column_info === NULL) {
        $this->logger->warning(
          'Revisions of field @field use a shared table and were not ' .
          'anonymized; previous revisions may retain data.',
          ['@field' => $field_name]
        );
        continue;
      }

      $fields = [
        $column_info['columns']['value'] => $info['value'],
      ];
      if (isset($column_info['columns']['summary'])) {
        $fields[$column_info['columns']['summary']] = $info['value'];
      }

      // Direct revision table updates avoid entity hooks and new revision rows.
      $updated += $this->database->update($column_info['table'])
        ->fields($fields)
        ->condition('entity_id', $archivable->id())
        ->condition('revision_id', $previous_revision_ids, 'IN')
        ->execute();
    }
    return $updated;
  }

  /**
   * Replaces the free-text message of every revision log of an entity.
   *
   * Covers the current and all previous revisions; see RevisionLogScrubber
   * for the rules. Rows are updated directly, like the previous revision
   * fields, so no hook runs and no revision is added.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved, anonymized entity.
   *
   * @return int
   *   Number of revision logs changed.
   */
  protected function scrubRevisionLogs(EntityInterface $entity): int {
    $columns = $this->revisionLogColumns($entity->getEntityTypeId());
    if ($columns === NULL || $entity->isNew()) {
      return 0;
    }
    $rows = $this->revisionLogQuery($columns)
      ->condition('r.' . $columns['id'], $entity->id())
      ->execute()
      ->fetchAllKeyed(1, 2);

    $changed = 0;
    foreach ($rows as $revision_id => $log) {
      $scrubbed = RevisionLogScrubber::scrub((string) $log);
      if ($scrubbed === (string) $log) {
        continue;
      }
      $this->database->update($columns['table'])
        ->fields([$columns['log'] => $scrubbed])
        ->condition($columns['revision'], $revision_id)
        ->execute();
      $changed++;
      // A later save of the same object must not write the old text back.
      if (
        $entity instanceof RevisionLogInterface
        && $entity instanceof RevisionableInterface
        && (int) $entity->getRevisionId() === (int) $revision_id
      ) {
        $entity->setRevisionLogMessage($scrubbed);
      }
    }
    if ($changed > 0) {
      $this->logger->notice('Removed the free-text message from @count revision logs of @type @id.', [
        '@count' => $changed,
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
      ]);
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function previewAnonymizeFields(EntityInterface $archivable, array $anonymize_fields): array {
    if (!$archivable instanceof FieldableEntityInterface) {
      return [];
    }

    $fields = [];
    foreach ($this->fieldNames($anonymize_fields) as $field_name) {
      if (!$archivable->hasField($field_name)) {
        continue;
      }
      $items = $archivable->get($field_name);
      if (
        !$this->fieldHasValue($archivable, $field_name)
        || !$this->isAnonymizableType($items->getFieldDefinition()->getType())
      ) {
        continue;
      }
      $fields[] = $field_name;
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getArchiveRetention(NodeInterface $node): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $default_days = (int) $config->get('default_days');
    $category_tid = 0;
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category_tid = (int) $node->get('field_category')->target_id;
    }
    return $this->getArchiveRetentionForCategory($category_tid, $default_days);
  }

  /**
   * Returns supported field names from a field list or associative map.
   *
   * @param array $fields
   *   Field names as values or keys.
   *
   * @return string[]
   *   Field machine names.
   */
  protected function fieldNames(array $fields): array {
    $names = [];
    foreach ($fields as $key => $value) {
      $field_name = is_numeric($key) ? $value : $key;
      if (is_string($field_name) && $field_name !== '') {
        $names[$field_name] = $field_name;
      }
    }
    // Existing contact-field configurations must also erase citizen free text.
    if ($names !== []) {
      $names['field_feedback'] = 'field_feedback';
    }
    return array_values($names);
  }

  /**
   * Builds an anonymized value for supported field types.
   *
   * @param string $type
   *   Field type.
   *
   * @return int|string|null
   *   Replacement value, or NULL when the type is not supported.
   */
  protected function anonymizedValue(string $type): int|string|null {
    switch ($type) {
      case 'email':
        return $this->randomToken() . '@anonymized.off';

      case 'telephone':
        return self::ANONYMIZED_PHONE;

      case 'boolean':
        return 0;

      case 'string':
      case 'string_long':
      case 'text':
      case 'text_long':
      case 'text_with_summary':
        return $this->randomToken();
    }

    return NULL;
  }

  /**
   * Returns TRUE when a field type has a safe anonymization strategy.
   */
  protected function isAnonymizableType(string $type): bool {
    return in_array($type, [
      'email',
      'telephone',
      'boolean',
      'string',
      'string_long',
      'text',
      'text_long',
      'text_with_summary',
    ], TRUE);
  }

  /**
   * Builds a short opaque replacement token.
   */
  protected function randomToken(): string {
    return bin2hex(random_bytes(5));
  }

  /**
   * Returns TRUE when any translation has a non-empty field value.
   */
  protected function fieldHasValue(FieldableEntityInterface $entity, string $field_name): bool {
    foreach ($this->fieldableTranslations($entity) as $translation) {
      if (!$translation->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns all fieldable translations for an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   Fieldable entity.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface[]
   *   Fieldable translations keyed by language code.
   */
  protected function fieldableTranslations(FieldableEntityInterface $entity): array {
    if (!$entity instanceof TranslatableInterface) {
      return [$entity->language()->getId() => $entity];
    }

    $translations = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableEntityInterface) {
          $translations[$langcode] = $translation;
        }
      }
    }

    return $translations ?: [$entity->language()->getId() => $entity];
  }

  /**
   * Returns the dedicated revision table and value column for a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   Fieldable entity.
   * @param string $field_name
   *   Field machine name.
   * @param string $type
   *   Field type.
   *
   * @return array{table: string, columns: array<string, string>}|null
   *   Revision table information, or NULL if the field cannot be updated.
   */
  protected function revisionColumnInfo(FieldableEntityInterface $entity, string $field_name, string $type): ?array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof SqlContentEntityStorage) {
      return NULL;
    }

    $field_storage = $entity->get($field_name)->getFieldDefinition()->getFieldStorageDefinition();
    $table_mapping = $storage->getTableMapping();
    $table = $table_mapping->getDedicatedRevisionTableName($field_storage);
    $value_column = $table_mapping->getFieldColumnName($field_storage, 'value');
    $schema = $this->database->schema();
    if (!$schema->tableExists($table) || !$schema->fieldExists($table, $value_column)) {
      return NULL;
    }

    $columns = [
      'value' => $value_column,
    ];
    if ($type === 'text_with_summary') {
      $summary_column = $table_mapping->getFieldColumnName($field_storage, 'summary');
      if ($schema->fieldExists($table, $summary_column)) {
        $columns['summary'] = $summary_column;
      }
    }

    return [
      'table' => $table,
      'columns' => $columns,
    ];
  }

  /**
   * Returns the active retention period for a category.
   *
   * @param int $category_tid
   *   Service category term ID.
   * @param int $default_days
   *   Default retention days.
   *
   * @return array{days: int, source: string}
   *   Retention days and source.
   */
  protected function getArchiveRetentionForCategory(int $category_tid, int $default_days): array {
    if ($category_tid > 0) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($category_tid);
      if ($term && $term->hasField('field_archive_days') && !$term->get('field_archive_days')->isEmpty()) {
        $override = (int) $term->get('field_archive_days')->value;
        if ($override > 0) {
          return [
            'days' => $override,
            'source' => 'term override',
          ];
        }
      }
    }

    return [
      'days' => $default_days,
      'source' => 'default',
    ];
  }

}
