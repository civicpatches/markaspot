<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Holds new reports unpublished while their tenant reviews before publishing.
 *
 * The root-only feature flag reviewBeforePublish makes every new service
 * request of a tenant start unpublished, whichever path creates it: the
 * citizen form (JSON:API), GeoReport POST, the dashboard or mail intake. The
 * check runs on the entity save, so no create path can skip it. Each held
 * report is recorded so that automatic publication (markaspot_publisher)
 * skips it in the candidates query and rechecks the hold in the queue worker;
 * staff publishing the report by hand releases the hold. A failed hold-store
 * read is the exception: core key/value returns "not held" on a database error.
 *
 * With the flag off nothing changes: the publish default of the service
 * request content type applies, including tenants that already default to
 * unpublished. The flag only ever unpublishes, it never publishes a report.
 *
 * Hook order: hook_node_presave() decides and unpublishes,
 * hook_entity_presave() re-asserts it after Content Moderation derived the
 * published flag from the moderation state, hook_node_insert() records the
 * hold under the new node ID.
 * The record is written inside the save transaction, so a failed save leaves
 * no hold behind.
 *
 * Presave resolves the tenant from field_jurisdiction, the category or, on a
 * site with exactly one root jurisdiction, that root. When it still finds
 * none, hook_node_insert() checks again after markaspot_group derived
 * field_jurisdiction (markaspot_group sorts before markaspot_nuxt) and holds
 * the report there, inside the same transaction (holdAfterInsert()).
 */
final class ReviewBeforePublish {

  /**
   * The feature flag below features in field_nuxt_config.
   */
  public const FLAG = 'reviewBeforePublish';

  /**
   * Key/value collection of held reports, keyed by node ID.
   */
  public const COLLECTION = 'markaspot_nuxt.review_before_publish_hold';

  /**
   * New reports held in presave that do not have a node ID yet.
   *
   * @var \WeakMap<\Drupal\node\NodeInterface, true>
   */
  private \WeakMap $pending;

  /**
   * IDs of held reports, read once per request (see heldIds()).
   *
   * @var int[]|null
   */
  private ?array $heldIds = NULL;

  /**
   * TRUE while holdAfterInsert() re-saves a report.
   */
  private bool $resaving = FALSE;

  /**
   * The report re-saved by holdAfterInsert(), NULL outside that save.
   */
  private ?int $resavingNid = NULL;

  /**
   * Constructs the review-before-publish service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FeatureScopeResolver $featureScopeResolver,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ?ModerationInformationInterface $moderationInformation = NULL,
  ) {
    $this->pending = new \WeakMap();
  }

  /**
   * Holds a new report unpublished when its tenant reviews before publishing.
   *
   * Called from hook_node_presave(). Content synchronisation and migrations
   * (isSyncing) keep the published state they bring along.
   *
   * @return bool
   *   TRUE when the report is held.
   */
  public function holdNewReport(NodeInterface $node): bool {
    if ($node->bundle() !== 'service_request' || !$node->isNew() || $node->isSyncing()) {
      return FALSE;
    }
    if (!$this->requiresReview($node)) {
      return FALSE;
    }

    $node->setUnpublished();
    $this->setUnpublishedModerationState($node);
    $this->pending[$node] = TRUE;
    return TRUE;
  }

  /**
   * Keeps a held new report unpublished after Content Moderation's presave.
   *
   * Called from ReviewBeforePublishHooks::entityPresave(), ordered last, so
   * after every hook_node_presave() and Content Moderation's entity presave.
   */
  public function reassertHold(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface
      && $this->resavingNid !== NULL
      && (int) $entity->id() === $this->resavingNid) {
      // Content Moderation turns the late re-save into a forward revision,
      // because the default revision is still the published insert row. Keep
      // the unpublished state as the default revision.
      $entity->isDefaultRevision(TRUE);
      $entity->setUnpublished();
      return;
    }
    if ($entity instanceof NodeInterface
      && isset($this->pending[$entity])
      && $entity->isNew()
      && $entity->isPublished()) {
      $entity->setUnpublished();
    }
  }

  /**
   * Records the hold of a report saved for the first time.
   *
   * Called from hook_node_insert(), still inside the save transaction.
   *
   * @return bool
   *   TRUE when presave held the report and the hold is now recorded.
   */
  public function recordHold(NodeInterface $node): bool {
    if (!isset($this->pending[$node])) {
      return FALSE;
    }
    unset($this->pending[$node]);
    $this->writeHold($node);
    $this->logger->info('Service request @nid saved unpublished for review before publication.', [
      '@nid' => $node->id(),
    ]);
    return TRUE;
  }

  /**
   * Holds a new report whose tenant only became known on insert.
   *
   * Called from hook_node_insert() for a report that presave did not hold,
   * after markaspot_group's insert hook derived field_jurisdiction. A
   * published report is unpublished and saved again, still inside the insert
   * transaction, so the published row is never committed. markaspot_group's
   * sync flag is set for that save, the same guard markaspot_group uses for
   * its own insert-time save, so its presave takes no sync lock and its update
   * hook does not reconcile again.
   *
   * @return bool
   *   TRUE when the report is held.
   */
  public function holdAfterInsert(NodeInterface $node): bool {
    if ($this->resaving
      || $node->bundle() !== 'service_request'
      || $node->id() === NULL
      || $node->isSyncing()
      || !$this->requiresReview($node)
      || $this->isHeld($node)) {
      return FALSE;
    }

    if ($node->isPublished()) {
      $node->setUnpublished();
      $this->setUnpublishedModerationState($node);
      // A system revision like markaspot_group's insert-time scope sync; the
      // revision log records the unpublication.
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(0);
      $node->setRevisionCreationTime($this->time->getRequestTime());

      $group_sync = function_exists('_markaspot_group_set_syncing') && function_exists('_markaspot_group_is_syncing');
      $was_syncing = $group_sync && _markaspot_group_is_syncing();
      $this->resaving = TRUE;
      $this->resavingNid = (int) $node->id();
      if ($group_sync) {
        _markaspot_group_set_syncing(TRUE);
      }
      try {
        $node->save();
      }
      finally {
        $this->resaving = FALSE;
        $this->resavingNid = NULL;
        if ($group_sync) {
          _markaspot_group_set_syncing($was_syncing);
        }
      }
    }

    $this->writeHold($node);
    $this->logger->warning('Service request @nid was held for review only after insert: its tenant was resolved only on insert (boundary or single root), presave could not match it through field_jurisdiction or its category.', [
      '@nid' => $node->id(),
    ]);
    return TRUE;
  }

  /**
   * Releases the hold when a person publishes a held report.
   *
   * Called from hook_node_update(). The publisher's queue-time hold hook
   * prevents this path for held reports, except when a failed hold-store read
   * returns "not held" on a database error.
   */
  public function releaseOnPublish(NodeInterface $node): void {
    if ($node->bundle() !== 'service_request' || !$node->isPublished()) {
      return;
    }
    $original = $node->getOriginal();
    if ($original instanceof NodeInterface && $original->isPublished()) {
      return;
    }
    $this->release($node);
  }

  /**
   * Removes the hold of a report, if any.
   */
  public function release(NodeInterface $node): void {
    // Another instance (or process) may have released it already: always
    // forget the cached list.
    $this->heldIds = NULL;
    if ($node->id() !== NULL && $this->store()->has((string) $node->id())) {
      $this->store()->delete((string) $node->id());
    }
  }

  /**
   * Whether a saved report is held for review.
   */
  public function isHeld(NodeInterface $node): bool {
    return $node->id() !== NULL && $this->store()->has((string) $node->id());
  }

  /**
   * IDs of all held reports.
   *
   * Read once per request: markaspot_publisher runs its candidate query once
   * per category on every cron run. recordHold(), holdAfterInsert() and
   * release() reset the list.
   *
   * @return int[]
   *   Node IDs.
   */
  public function heldIds(): array {
    return $this->heldIds ??= array_map('intval', array_keys($this->store()->getAll()));
  }

  /**
   * Counts the held reports that are still unpublished in jurisdictions.
   *
   * @param int[] $jurisdiction_ids
   *   Jurisdiction group IDs, usually a tenant root and its descendants.
   *
   * @return int
   *   Held reports whose field_jurisdiction is one of the given IDs.
   */
  public function countHeld(array $jurisdiction_ids): int {
    $held = $this->heldIds();
    $jurisdiction_ids = array_values(array_filter(array_map('intval', $jurisdiction_ids)));
    if ($held === [] || $jurisdiction_ids === []) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $count = 0;
    foreach (array_chunk($held, 1000) as $chunk) {
      $count += (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'service_request')
        ->condition('status', 0)
        ->condition('nid', $chunk, 'IN')
        ->condition('field_jurisdiction', $jurisdiction_ids, 'IN')
        ->count()
        ->execute();
    }
    return $count;
  }

  /**
   * Keeps held reports out of the automatic publication candidates.
   *
   * Called from hook_query_markaspot_publisher_candidates_alter().
   */
  public function excludeHeldReports(AlterableInterface $query): void {
    $held = $this->heldIds();
    if ($held !== [] && $query instanceof SelectInterface) {
      $query->condition('base_table.nid', $held, 'NOT IN');
    }
  }

  /**
   * Whether the tenant of a report reviews new reports before publishing.
   *
   * Every jurisdiction the report names (field_jurisdiction) or inherits from
   * its category counts. For a new citizen report field_jurisdiction is often
   * still empty in presave (markaspot_group fills it on insert), so the
   * category decides. Without either, a site with exactly one root
   * jurisdiction resolves to that root, as markaspot_group does on insert.
   * The flag is tenant-scoped: FeatureScopeResolver reads it from the root of
   * each jurisdiction, never from a child.
   */
  public function requiresReview(NodeInterface $node): bool {
    $storage = $this->entityTypeManager->getStorage('group');
    foreach ($this->jurisdictionIds($node) as $jurisdiction_id) {
      $jurisdiction = $storage->load($jurisdiction_id);
      if ($jurisdiction instanceof GroupInterface
        && $this->featureScopeResolver->isEnabledEffective(self::FLAG, $jurisdiction, FALSE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Collects the jurisdiction IDs of a report.
   *
   * Falls back to the only root jurisdiction when neither the report nor its
   * category names one (single-root municipality, category created without
   * field_jurisdiction, report without category). With several roots the
   * insert-time check decides once markaspot_group has derived the field.
   *
   * @return int[]
   *   Group IDs, without duplicates.
   */
  private function jurisdictionIds(NodeInterface $node): array {
    $ids = $this->referencedIds($node, 'field_jurisdiction');
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category = $node->get('field_category')->entity;
      if ($category instanceof FieldableEntityInterface) {
        $ids = array_merge($ids, $this->referencedIds($category, 'field_jurisdiction'));
      }
    }
    if ($ids === []) {
      $roots = $this->hierarchyResolver->getAllRootJurisdictionIds();
      if (count($roots) === 1) {
        $ids = [(int) reset($roots)];
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Reads the target IDs of an entity reference field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The reference field.
   *
   * @return int[]
   *   Positive target IDs.
   */
  private function referencedIds(FieldableEntityInterface $entity, string $field_name): array {
    if (!$entity->hasField($field_name)) {
      return [];
    }
    $field = $entity->get($field_name);
    if (!$field instanceof EntityReferenceFieldItemListInterface) {
      return [];
    }
    $ids = [];
    foreach ($field as $item) {
      $target_id = (int) ($item->target_id ?? 0);
      if ($target_id > 0) {
        $ids[] = $target_id;
      }
    }
    return $ids;
  }

  /**
   * Moves a moderated report to an unpublished moderation state.
   *
   * Content Moderation derives the published flag from the moderation state
   * in its entity presave, which runs after hook_node_presave(); a held report
   * must therefore also carry an unpublished state. A state that is already
   * unpublished stays. Otherwise the workflow's initial state for new content
   * is used when it is unpublished (usually draft), and only then the lightest
   * unpublished state, so a new report never lands in a state like archived.
   */
  private function setUnpublishedModerationState(NodeInterface $node): void {
    if ($this->moderationInformation === NULL || !$node->hasField('moderation_state')) {
      return;
    }
    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    if ($workflow === NULL) {
      return;
    }
    $type = $workflow->getTypePlugin();
    $current = (string) ($node->get('moderation_state')->value ?? '');
    if ($current !== '' && $type->hasState($current)) {
      $state = $type->getState($current);
      if ($state instanceof ContentModerationState && !$state->isPublishedState()) {
        return;
      }
    }
    $initial = $type instanceof ContentModerationInterface ? $this->initialStateId($type, $node) : NULL;
    if ($initial !== NULL && $type->hasState($initial)) {
      $state = $type->getState($initial);
      if ($state instanceof ContentModerationState && !$state->isPublishedState()) {
        $node->set('moderation_state', $initial);
        return;
      }
    }
    foreach ($type->getStates() as $state) {
      if ($state instanceof ContentModerationState && !$state->isPublishedState()) {
        $node->set('moderation_state', $state->id());
        return;
      }
    }
  }

  /**
   * The moderation state the workflow gives new content.
   *
   * For a new report this is ContentModeration::getInitialState(). After
   * insert the report is no longer new, and getInitialState() would answer
   * from its published flag instead, so the configured default is read the
   * same way getInitialState() reads it for new content.
   */
  private function initialStateId(ContentModerationInterface $type, NodeInterface $node): ?string {
    if ($node->isNew()) {
      try {
        return (string) $type->getInitialState($node)->id();
      }
      catch (\InvalidArgumentException) {
        return NULL;
      }
    }
    $configured = $type->getConfiguration()['default_moderation_state'] ?? '';
    return is_string($configured) && $configured !== '' ? $configured : 'draft';
  }

  /**
   * Stores the hold of a saved report.
   */
  private function writeHold(NodeInterface $node): void {
    $this->store()->set((string) $node->id(), [
      'created' => $this->time->getRequestTime(),
    ]);
    $this->heldIds = NULL;
  }

  /**
   * The key/value store of held reports.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
