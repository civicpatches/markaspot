<?php

declare(strict_types=1);

namespace Drupal\markaspot_archive\Drush\Commands;

use Drupal\service_request\RevisionLog;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_archive\ArchiveService;
use Drupal\markaspot_archive\ArchiveServiceInterface;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for archive operations.
 */
final class ArchiveCommands extends DrushCommands {

  /**
   * Optional dashboard service storing split request links.
   */
  private const REQUEST_LINK_SERVICE = 'markaspot_dashboard.request_link';

  /**
   * Requests per catch-up batch of the backfill command.
   */
  public const BACKFILL_BATCH_SIZE = 200;

  /**
   * Seconds a catch-up batch of the backfill command may take.
   */
  private const BACKFILL_BATCH_TIME_BUDGET = 300;

  /**
   * Note of a catch-up step in which requests failed.
   */
  private const FAILED_NOTE = 'Some requests failed: a later run retries them. The markaspot_archive log names them and the reason.';

  /**
   * Note of a catch-up step stopped because the lock could not be renewed.
   */
  private const LOCK_LOST_NOTE = 'Stopped: the catch-up lock could not be renewed, so cron or another run may be working on the same requests. Later steps did not run; run the command again.';

  /**
   * TRUE when the running catch-up could not finish its work.
   *
   * Set for causes no row count shows, such as a search index the catch-up
   * cannot update or the loss of the catch-up lock.
   */
  private bool $incomplete = FALSE;

  /**
   * TRUE once the running catch-up could not renew its lock.
   */
  private bool $lockLost = FALSE;

  public function __construct(
    protected readonly ArchiveServiceInterface $archiveService,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelInterface $archiveLogger,
    protected readonly Connection $database,
    protected readonly StateInterface $state,
    protected readonly LockBackendInterface $lock,
    protected readonly ?object $requestLinkService,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the command via Drush command discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('markaspot_archive.archive'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('markaspot_archive'),
      $container->get('database'),
      $container->get('state'),
      $container->get('lock'),
      $container->has(self::REQUEST_LINK_SERVICE)
        ? $container->get(self::REQUEST_LINK_SERVICE) : NULL,
    );
  }

  /**
   * Shows service requests that would be archived.
   */
  #[CLI\Command(
    name: 'markaspot:archive:preview',
    aliases: ['mas:archive-preview'],
  )]
  #[CLI\Option(
    name: 'all',
    description: 'List all archive candidates instead of the cron window.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:preview',
    description: 'Preview archive candidates for the normal cron window.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:preview --all',
    description: 'Preview all archive candidates without cron caps.'
  )]
  public function preview(array $options = ['all' => FALSE]): void {
    $all = (bool) ($options['all'] ?? FALSE);
    $nodes = $this->archiveService->load($all, FALSE, FALSE);
    $config = $this->configFactory->get('markaspot_archive.settings');
    $anonymize_fields = [];
    if ((bool) $config->get('anonymize')) {
      $anonymize_fields = $this->archiveService->normalizeConfiguredFields(
        (array) $config->get('anonymize_fields')
      );
    }

    $rows = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $retention = $this->archiveService->getArchiveRetention($node);
      $fields = $this->archiveService->previewAnonymizeFields(
        $node,
        $anonymize_fields
      );
      $rows[] = [
        'nid' => $node->id(),
        'request_id' => $this->fieldValue($node, 'request_id'),
        'category' => $this->referencedLabel($node, 'field_category'),
        'status' => $this->referencedLabel($node, 'field_status'),
        'changed' => $this->dateFormatter->format(
          $node->getChangedTime(),
          'custom',
          'Y-m-d H:i:s'
        ),
        'retention' => sprintf(
          '%d days (%s)',
          $retention['days'],
          $retention['source']
        ),
        'fields' => implode(', ', $fields),
      ];
    }

    $this->io()->table([
      'nid',
      'request_id',
      'category',
      'status',
      'changed',
      'retention',
      'fields',
    ], $rows);
  }

  /**
   * Catches up on archived requests in one run instead of 50 per cron run.
   *
   * Runs the one-time unpublish pass and the anonymization backfill that cron
   * otherwise works through in steps of 50, with the same saves: the changed
   * time is kept and no mail goes out. Running it again is safe; finished
   * requests are no longer candidates.
   *
   * Then indexes the search index items of archived requests that wait for
   * indexing, so no index keeps the text from before (step "search index",
   * counted in index items, only with Search API). A search index the
   * catch-up cannot update, such as a read-only one, is named in the note.
   *
   * The command renews its lock before each batch and each indexing chunk.
   * When that fails, cron or another run may hold the lock: the command
   * stops, skips the later steps and fails.
   *
   * Exits with 1 when a request failed, when a step whose setting is on
   * still has requests left although no --limit was given, when a search
   * index cannot be updated or when the lock was lost. A dry run always
   * exits with 0.
   */
  #[CLI\Command(
    name: 'markaspot:archive:backfill',
    aliases: ['mas:archive-backfill'],
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Only count the archived requests each step would still process.',
  )]
  #[CLI\Option(
    name: 'only',
    description: 'Run one step only: "unpublish" or "anonymize".',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Process at most this many requests per step (0: all).',
  )]
  #[CLI\Option(
    name: 'batch-size',
    description: 'Requests per batch.',
  )]
  #[CLI\FieldLabels(labels: [
    'step' => 'Step',
    'setting' => 'Setting',
    'pending' => 'Pending before',
    'processed' => 'Processed',
    'changed' => 'Changed',
    'failed' => 'Failed',
    'remaining' => 'Pending after',
    'note' => 'Note',
  ])]
  #[CLI\Usage(
    name: 'markaspot:archive:backfill --dry-run',
    description: 'Count the archived requests still published or holding plain contact data.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:backfill --limit=100',
    description: 'Trial run on the first 100 requests of each step.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:backfill',
    description: 'Unpublish and anonymize every remaining archived request now.',
  )]
  public function backfill(
    array $options = [
      'dry-run' => FALSE,
      'only' => NULL,
      'limit' => 0,
      'batch-size' => self::BACKFILL_BATCH_SIZE,
    ],
  ): CommandResult {
    $only = (string) ($options['only'] ?? '');
    if (!in_array($only, ['', 'unpublish', 'anonymize'], TRUE)) {
      throw new \InvalidArgumentException(
        '--only must be "unpublish" or "anonymize".'
      );
    }
    $limit = filter_var(
      $options['limit'] ?? 0,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 0]]
    );
    $batch_size = filter_var(
      $options['batch-size'] ?? self::BACKFILL_BATCH_SIZE,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]]
    );
    if ($limit === FALSE || $batch_size === FALSE) {
      throw new \InvalidArgumentException(
        '--limit must be 0 or more and --batch-size 1 or more.'
      );
    }
    $dry_run = !empty($options['dry-run']);
    $this->incomplete = FALSE;
    $this->lockLost = FALSE;

    if (!$dry_run && !$this->lock->acquire(
      ArchiveService::CATCH_UP_LOCK,
      self::BACKFILL_BATCH_TIME_BUDGET + 60
    )) {
      throw new \RuntimeException(
        'Cron is running the archive catch-up right now. Try again in a minute.'
      );
    }
    try {
      $rows = [];
      if ($only !== 'anonymize') {
        $rows[] = $this->unpublishStep($dry_run, $limit, $batch_size);
      }
      // Without the lock, cron or another run may work on the same requests.
      if ($only !== 'unpublish' && !$this->lockLost) {
        $rows[] = $this->anonymizeStep($dry_run, $limit, $batch_size);
      }
      $search = $this->lockLost ? NULL : $this->searchIndexStep($dry_run);
      if ($search !== NULL) {
        $rows[] = $search;
      }
    }
    finally {
      if (!$dry_run) {
        $this->lock->release(ArchiveService::CATCH_UP_LOCK);
      }
    }
    $exit = $dry_run || (!$this->incomplete && $this->catchUpExitCode($rows, $limit) === self::EXIT_SUCCESS)
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $exit);
  }

  /**
   * Returns the exit code of a catch-up run.
   *
   * @param array<int, array<string, int|string>> $rows
   *   The result rows.
   * @param int $limit
   *   The --limit option, 0 for all.
   */
  private function catchUpExitCode(array $rows, int $limit): int {
    foreach ($rows as $row) {
      if ($row['setting'] !== 'on') {
        continue;
      }
      if ((int) $row['failed'] > 0 || ($limit === 0 && (int) $row['remaining'] > 0)) {
        return self::EXIT_FAILURE;
      }
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Runs or counts the one-time unpublish pass.
   *
   * @return array<string, int|string>
   *   The result row.
   */
  private function unpublishStep(bool $dry_run, int $limit, int $batch_size): array {
    $enabled = $this->configFactory->get('markaspot_archive.settings')
      ->get('unpublish') == 1;
    $row = $this->stepRow('unpublish', $enabled, $this->archiveService->countUnpublishBacklog());
    if ($this->state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE)) {
      $row['note'] = 'The one-time pass has ended; requests published now were republished by staff.';
    }
    elseif (!$enabled) {
      $row['note'] = 'The "unpublish" setting is off: nothing is unpublished.';
    }
    elseif ($dry_run) {
      $row['note'] = 'Dry run: nothing was changed.';
    }
    else {
      $this->runBatches('Unpublish', $row, $limit, $batch_size,
        fn (int $size): array => $this->archiveService->unpublishArchivedBacklog(
          $size,
          self::BACKFILL_BATCH_TIME_BUDGET
        ));
      if ($this->lockLost) {
        $row['note'] = self::LOCK_LOST_NOTE;
      }
      elseif ($row['failed'] > 0) {
        $row['note'] = self::FAILED_NOTE;
      }
      elseif ($this->state->get(ArchiveService::UNPUBLISH_BACKLOG_DONE_STATE)) {
        $row['note'] = 'The one-time pass has ended.';
      }
    }
    $row['remaining'] = $this->archiveService->countUnpublishBacklog();
    if ($row['note'] === '' && $row['remaining'] > 0) {
      $row['note'] = 'Limit reached: cron or the next run continues.';
    }
    return $row;
  }

  /**
   * Runs or counts the anonymization backfill over every archived request.
   *
   * @return array<string, int|string>
   *   The result row.
   */
  private function anonymizeStep(bool $dry_run, int $limit, int $batch_size): array {
    $enabled = $this->configFactory->get('markaspot_archive.settings')
      ->get('anonymize') == 1;
    $row = $this->stepRow('anonymize', $enabled, $this->archiveService->countBackfillCandidates());
    if (!$enabled) {
      $row['note'] = 'The "anonymize" setting is off: nothing is anonymized.';
    }
    elseif ($dry_run) {
      $row['note'] = 'Dry run: nothing was changed.';
    }
    else {
      // One full pass from the first archived request, wherever cron stopped.
      $this->state->set(ArchiveService::BACKFILL_CURSOR_STATE, 0);
      $this->runBatches('Anonymize', $row, $limit, $batch_size,
        fn (int $size): array => $this->archiveService->backfillArchived(
          $size,
          self::BACKFILL_BATCH_TIME_BUDGET
        ));
      $row['note'] = match (TRUE) {
        $this->lockLost => self::LOCK_LOST_NOTE,
        $row['failed'] > 0 => self::FAILED_NOTE,
        default => '',
      };
    }
    $row['remaining'] = $this->archiveService->countBackfillCandidates();
    if ($row['note'] === '' && $row['remaining'] > 0) {
      $row['note'] = 'Limit reached: cron or the next run continues.';
    }
    return $row;
  }

  /**
   * Indexes or counts the search index items of archived requests.
   *
   * @return array<string, int|string>|null
   *   The result row, NULL without Search API.
   */
  private function searchIndexStep(bool $dry_run): ?array {
    $pending = $this->archiveService->countSearchIndexBacklog();
    if ($pending === NULL) {
      return NULL;
    }
    $row = $this->stepRow('search index', TRUE, $pending);
    if ($dry_run) {
      $row['note'] = 'Dry run: nothing was changed.';
    }
    elseif ($pending > 0) {
      $result = $this->archiveService->indexSearchIndexBacklog(
        fn (): bool => $this->renewLock()
      );
      foreach (['processed', 'changed', 'failed'] as $key) {
        $row[$key] = $result[$key];
      }
      $row['remaining'] = (int) $this->archiveService->countSearchIndexBacklog();
      if ($this->lockLost) {
        $row['note'] = self::LOCK_LOST_NOTE;
      }
      elseif ($row['failed'] > 0 || $row['remaining'] > 0) {
        $row['note'] = 'Items failed to index: Search API retries them on cron. The markaspot_archive and search_api logs name the reason.';
      }
    }
    $unprocessable = $this->archiveService->unprocessableSearchIndexes();
    if ($unprocessable !== []) {
      $names = [];
      foreach ($unprocessable as $id => $label) {
        $names[] = sprintf('%s (%s)', $label, $id);
      }
      $row['note'] = trim($row['note'] . ' Cannot update ' . implode(', ', $names)
        . ': read-only, or without a valid tracker or server. They may keep text the anonymization removed; fix them and reindex, or clear them.');
      $this->incomplete = TRUE;
    }
    return $row;
  }

  /**
   * Returns an empty result row of one catch-up step.
   *
   * @return array<string, int|string>
   *   The row.
   */
  private function stepRow(string $step, bool $enabled, int $pending): array {
    return [
      'step' => $step,
      'setting' => $enabled ? 'on' : 'off',
      'pending' => $pending,
      'processed' => 0,
      'changed' => 0,
      'failed' => 0,
      'remaining' => $pending,
      'note' => '',
    ];
  }

  /**
   * Calls one catch-up step batch by batch until it ends or hits the limit.
   *
   * @param string $label
   *   Step label for the progress log.
   * @param array<string, int|string> $row
   *   The result row, updated with the batch results.
   * @param int $limit
   *   Maximum number of requests to process, 0 for all.
   * @param int $batch_size
   *   Requests per batch.
   * @param callable(int): array{processed: int, changed: int, failed: int} $batch
   *   Processes one batch of the given size.
   */
  private function runBatches(string $label, array &$row, int $limit, int $batch_size, callable $batch): void {
    while ($limit === 0 || $row['processed'] < $limit) {
      $size = $limit === 0 ? $batch_size : min($batch_size, $limit - (int) $row['processed']);
      // Keep the lock for the next batch, or stop.
      if (!$this->renewLock()) {
        break;
      }
      $result = $batch($size);
      if ($result['processed'] === 0) {
        break;
      }
      foreach (['processed', 'changed', 'failed'] as $key) {
        $row[$key] = (int) $row[$key] + $result[$key];
      }
      $this->archiveLogger->notice(
        '@step: @processed archived requests processed (@changed changed, @failed failed).',
        [
          '@step' => $label,
          '@processed' => $row['processed'],
          '@changed' => $row['changed'],
          '@failed' => $row['failed'],
        ]
      );
    }
  }

  /**
   * Renews the catch-up lock for the next batch or indexing chunk.
   *
   * A batch may take its whole time budget; the lease covers it plus a
   * minute. Once the lease ran out, cron or another run may have taken the
   * lock: then the renewal fails and the catch-up must stop.
   *
   * @return bool
   *   TRUE while this run holds the lock.
   */
  private function renewLock(): bool {
    if ($this->lockLost) {
      return FALSE;
    }
    if ($this->lock->acquire(ArchiveService::CATCH_UP_LOCK, self::BACKFILL_BATCH_TIME_BUDGET + 60)) {
      return TRUE;
    }
    $this->lockLost = TRUE;
    $this->incomplete = TRUE;
    $this->archiveLogger->error('The archive catch-up lost its lock and stopped; run markaspot:archive:backfill again.');
    return FALSE;
  }

  /**
   * Anonymizes reporter contact data for one or more service requests.
   */
  #[CLI\Command(
    name: 'markaspot:anonymize',
    aliases: ['mas:anonymize'],
  )]
  #[CLI\Argument(
    name: 'nid',
    description: 'Node ID of one service_request to anonymize.',
  )]
  #[CLI\Option(
    name: 'by-mail',
    description: 'Match nodes by exact field_e_mail value.',
  )]
  #[CLI\Option(
    name: 'jurisdiction',
    description: 'Limit --by-mail matches to a field_jurisdiction ID.',
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Print fields that would be anonymized without saving.',
  )]
  #[CLI\Option(
    name: 'no-cascade',
    description: 'Do not anonymize linked split requests.',
  )]
  #[CLI\FieldLabels(labels: [
    'nid' => 'nid',
    'request_id' => 'request_id',
    'fields' => 'fields',
  ])]
  #[CLI\Usage(
    name: 'markaspot:anonymize 123',
    description: 'Anonymize reporter contact fields on node 123.',
  )]
  #[CLI\Usage(
    name: 'markaspot:anonymize --by-mail=citizen@example.org',
    description: 'Anonymize all exact field_e_mail matches.',
  )]
  public function anonymize(
    ?int $nid = NULL,
    array $options = [
      'by-mail' => NULL,
      'jurisdiction' => NULL,
      'dry-run' => FALSE,
      'no-cascade' => FALSE,
    ],
  ): ?RowsOfFields {
    $fields = $this->configuredAnonymizeFields();
    $base_nids = $this->targetNodeIds($nid, $options);
    $cascade = empty($options['no-cascade']);
    $target_nids = $this->expandWithLinkedRequests($base_nids, $cascade);
    $dry_run = !empty($options['dry-run']);

    if ($dry_run) {
      return new RowsOfFields($this->previewRows($target_nids, $fields));
    }

    if ($base_nids === []) {
      $this->archiveLogger->notice('No matching service requests found.');
      return NULL;
    }

    if (!empty($options['by-mail']) && !$this->confirmByMailAnonymization(
      count($base_nids),
      count($target_nids)
    )) {
      $this->archiveLogger->notice('Anonymization aborted by operator.');
      return NULL;
    }

    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($target_nids);

    $anonymized = 0;
    $unchanged = 0;
    foreach ($target_nids as $target_nid) {
      $node = $nodes[$target_nid] ?? NULL;
      if (!$node instanceof NodeInterface) {
        $this->archiveLogger->warning(
          'Node ID @nid could not be loaded for anonymization.',
          ['@nid' => $target_nid]
        );
        continue;
      }
      if ($node->bundle() !== 'service_request') {
        $this->archiveLogger->warning(
          'Node ID @nid is not a service_request; skipping.',
          ['@nid' => $target_nid]
        );
        continue;
      }

      if ($this->anonymizeNode($node, $fields)) {
        $anonymized++;
      }
      else {
        $unchanged++;
      }
    }

    $this->archiveLogger->notice('@count requests anonymized.', [
      '@count' => $anonymized,
    ]);
    if ($unchanged > 0) {
      $this->archiveLogger->notice(
        '@count requests had no configured reporter contact data to ' .
        'anonymize.',
        ['@count' => $unchanged]
      );
    }
    return NULL;
  }

  /**
   * Returns configured anonymize fields or fails before any writes.
   *
   * @return array<string, string>
   *   Field machine names keyed by field machine name.
   */
  private function configuredAnonymizeFields(): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $fields = $this->archiveService->normalizeConfiguredFields(
      (array) $config->get('anonymize_fields')
    );
    if ($fields === []) {
      throw new \RuntimeException(
        'No anonymize fields are configured. Configure ' .
        'markaspot_archive.settings:anonymize_fields before running this ' .
        'command.'
      );
    }
    return $fields;
  }

  /**
   * Resolves the command target node IDs.
   *
   * @param int|null $nid
   *   Optional node ID argument.
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int[]
   *   Target node IDs.
   */
  private function targetNodeIds(?int $nid, array $options): array {
    $by_mail = $options['by-mail'] ?? NULL;
    $jurisdiction = $options['jurisdiction'] ?? NULL;
    if ($by_mail !== NULL && $by_mail !== '') {
      if ($nid !== NULL) {
        throw new \InvalidArgumentException(
          'Pass either <nid> or --by-mail, not both.'
        );
      }
      return $this->loadByMailTargetIds((string) $by_mail, $jurisdiction);
    }

    if ($jurisdiction !== NULL && $jurisdiction !== '') {
      throw new \InvalidArgumentException(
        '--jurisdiction can only be used together with --by-mail.'
      );
    }
    if ($nid === NULL) {
      throw new \InvalidArgumentException(
        'Pass a service_request node ID or --by-mail=EMAIL.'
      );
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException(sprintf(
        'Node ID %d was not found.',
        $nid
      ));
    }
    if ($node->bundle() !== 'service_request') {
      throw new \InvalidArgumentException(sprintf(
        'Node ID %d is not a service_request.',
        $nid
      ));
    }
    return [$nid];
  }

  /**
   * Loads service request IDs matching one exact reporter email value.
   *
   * @param string $email
   *   Exact field_e_mail value to match.
   * @param mixed $jurisdiction
   *   Optional field_jurisdiction target ID.
   *
   * @return int[]
   *   Matching node IDs.
   */
  private function loadByMailTargetIds(
    string $email,
    mixed $jurisdiction,
  ): array {
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_e_mail', $email);

    if ($jurisdiction !== NULL && $jurisdiction !== '') {
      $jurisdiction_id = filter_var(
        $jurisdiction,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
      );
      if ($jurisdiction_id === FALSE) {
        throw new \InvalidArgumentException(
          '--jurisdiction must be a positive integer.'
        );
      }
      $query->condition('field_jurisdiction', (int) $jurisdiction_id);
    }

    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Adds linked split requests unless the operator disabled the cascade.
   *
   * @param int[] $nids
   *   Initial target node IDs.
   * @param bool $cascade
   *   TRUE to resolve linked split requests.
   *
   * @return int[]
   *   Initial and linked node IDs.
   */
  private function expandWithLinkedRequests(array $nids, bool $cascade): array {
    $unique_nids = [];
    foreach ($nids as $nid) {
      $unique_nids[(int) $nid] = (int) $nid;
    }
    if (!$cascade || $unique_nids === []) {
      return array_values($unique_nids);
    }

    $request_link_service = $this->requestLinkService;
    if ($request_link_service === NULL) {
      $this->archiveLogger->notice(
        'Split cascade skipped; request link service is not available.'
      );
      return array_values($unique_nids);
    }

    if (!method_exists($request_link_service, 'getLinksForNode')) {
      $this->archiveLogger->notice(
        'Split cascade skipped; request link service cannot load links.'
      );
      return array_values($unique_nids);
    }

    $expanded = [];
    foreach ($unique_nids as $nid) {
      foreach (self::resolveLinkedNodeIds(
        $nid,
        static function (int $linked_nid) use ($request_link_service): array {
          return $request_link_service->getLinksForNode($linked_nid);
        }
      ) as $linked_nid) {
        $expanded[$linked_nid] = $linked_nid;
      }
    }

    return array_values($expanded);
  }

  /**
   * Walks split links in both directions and protects against cycles.
   *
   * @param int $start_nid
   *   The first node ID.
   * @param callable $links_for_node
   *   Callable returning rows with source_nid and target_nid.
   *
   * @return int[]
   *   Reached node IDs including the start node.
   */
  private static function resolveLinkedNodeIds(
    int $start_nid,
    callable $links_for_node,
  ): array {
    $visited = [$start_nid => $start_nid];
    $queue = [$start_nid];
    while ($queue !== []) {
      $current_nid = array_shift($queue);
      foreach ($links_for_node($current_nid) as $row) {
        foreach (['source_nid', 'target_nid'] as $key) {
          if (!isset($row[$key])) {
            continue;
          }
          $next_nid = (int) $row[$key];
          if ($next_nid <= 0 || isset($visited[$next_nid])) {
            continue;
          }
          $visited[$next_nid] = $next_nid;
          $queue[] = $next_nid;
        }
      }
    }

    return array_values($visited);
  }

  /**
   * Confirms a by-mail write operation without logging the email address.
   */
  private function confirmByMailAnonymization(
    int $matching_count,
    int $target_count,
  ): bool {
    $linked_count = max(0, $target_count - $matching_count);
    $message = sprintf(
      'Anonymize %d matching service request(s)',
      $matching_count
    );
    if ($linked_count > 0) {
      $message .= sprintf(
        ' and %d linked split request(s)',
        $linked_count
      );
    }
    return $this->io()->confirm($message . '?', FALSE);
  }

  /**
   * Builds dry-run rows without exposing field values.
   *
   * @param int[] $target_nids
   *   Target node IDs.
   * @param array<string, string> $fields
   *   Configured anonymize fields.
   *
   * @return array<int, array{nid: int, request_id: string, fields: string}>
   *   Rows for Drush table output.
   */
  private function previewRows(array $target_nids, array $fields): array {
    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($target_nids);

    $rows = [];
    foreach ($target_nids as $target_nid) {
      $node = $nodes[$target_nid] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      $rows[] = [
        'nid' => (int) $node->id(),
        'request_id' => $this->fieldValue($node, 'request_id'),
        'fields' => implode(
          ', ',
          $this->archiveService->previewAnonymizeFields($node, $fields)
        ),
      ];
    }
    return $rows;
  }

  /**
   * Anonymizes one service request and its historic revisions.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Service request node.
   * @param array<string, string> $fields
   *   Configured anonymize fields.
   *
   * @return bool
   *   TRUE when at least one configured field was anonymized.
   */
  private function anonymizeNode(NodeInterface $node, array $fields): bool {
    $transaction = $this->database->startTransaction();
    try {
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(0);
      $node->setRevisionCreationTime(\time());
      RevisionLog::setEvent($node, 'anonymized');

      $anonymized_values = $this->archiveService->anonymize($node, $fields);
      $node->save();

      $revision_rows = $this->archiveService->anonymizeRevisions(
        $node,
        $anonymized_values
      );
      unset($transaction);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->archiveLogger->error(
        'Node ID @nid reporter contact data anonymization failed: @error',
        [
          '@nid' => (int) $node->id(),
          '@error' => $e->getMessage(),
        ]
      );
      throw $e;
    }

    if ($anonymized_values === []) {
      $this->archiveLogger->notice(
        'Node ID @nid had no configured reporter contact data to anonymize.',
        ['@nid' => (int) $node->id()]
      );
      return FALSE;
    }

    if ($revision_rows > 0) {
      $this->archiveLogger->notice(
        'Node ID @nid previous revisions anonymized in @rows field rows.',
        [
          '@nid' => (int) $node->id(),
          '@rows' => $revision_rows,
        ]
      );
    }

    $this->archiveLogger->notice(
      'Node ID @nid reporter contact data anonymized.',
      ['@nid' => (int) $node->id()]
    );
    return TRUE;
  }

  /**
   * Returns a string field value without exposing configured PII fields.
   */
  private function fieldValue(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) $node->get($field_name)->value;
  }

  /**
   * Returns the label of an entity reference field.
   */
  private function referencedLabel(
    NodeInterface $node,
    string $field_name,
  ): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $entity = $node->get($field_name)->entity;
    return $entity instanceof EntityInterface ? $entity->label() : '';
  }

}
