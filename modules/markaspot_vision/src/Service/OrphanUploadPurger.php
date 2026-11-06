<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Field\DeletedFieldsRepositoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;

/**
 * Removes expired uploads only when no stored reference can still use them.
 */
class OrphanUploadPurger {

  /**
   * Last candidate examined, including protected uploads.
   */
  public const CURSOR = 'markaspot_vision.orphan_upload_cursor';

  /**
   * Journal of files whose media deletion may already have committed.
   */
  public const PENDING_FILES = 'markaspot_vision.orphan_upload_files';

  /**
   * Cursor prevents one failed file from starving the journal.
   */
  public const FILE_CURSOR = 'markaspot_vision.orphan_upload_file_cursor';

  /**
   * Saves holding reference locks until their insert/update has completed.
   */
  protected array $saves = [];

  /**
   * Number of saves sharing each lock owned by this guard.
   */
  protected array $heldLocks = [];

  /**
   * Constructs the bounded orphan collector.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected DeletedFieldsRepositoryInterface $deletedFields,
    protected Connection $database,
    protected KeyValueFactoryInterface $keyValue,
    protected FileUsageInterface $fileUsage,
    protected LockBackendInterface $lock,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected FeatureScopeResolver $featureScopeResolver,
  ) {}

  /**
   * Serializes reference writes and collection, including nondefault revisions.
   *
   * Existing dangling links are tolerated. New report links to missing media
   * are dropped; unrelated entities keep their normal validation contract.
   */
  public function protectReferences(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $ids = [];
    $candidates = [];
    $original = $entity->getOriginal();
    foreach ($entity->getTranslationLanguages() as $language) {
      $translation = $entity->getTranslation($language->getId());
      $previous = $original && $original->hasTranslation($language->getId())
        ? $original->getTranslation($language->getId()) : NULL;
      foreach ($translation->getFieldDefinitions() as $name => $definition) {
        $type = $definition->getSetting('target_type');
        if ($type !== 'media' && !($type === 'file' && $entity instanceof MediaInterface && $entity->bundle() === 'request_image')) {
          continue;
        }
        $old_ids = $previous && $previous->hasField($name)
          ? array_column($previous->get($name)->getValue(), 'target_id') : [];
        $remove = [];
        foreach ($translation->get($name) as $delta => $item) {
          if (!$item->target_id || in_array($item->target_id, $old_ids)) {
            continue;
          }
          $target = $this->entityTypeManager->getStorage($type)->loadUnchanged($item->target_id);
          if ($type === 'media') {
            if (!$target) {
              // A stale online form may name an upload already collected. Keep
              // the report save usable, but never store that new broken link.
              // Unrelated entity types retain their own validation contract.
              if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'service_request' && $name === 'field_request_media') {
                $remove[] = $delta;
              }
              continue;
            }
            if (!$target instanceof MediaInterface || $target->bundle() !== 'request_image') {
              continue;
            }
          }
          $ids[$type][$item->target_id] = $item->target_id;
          $candidate = $type === 'media' ? $target : ($entity->isNew() ? NULL : $this->entityTypeManager->getStorage('media')->loadUnchanged($entity->id()));
          if ($candidate instanceof MediaInterface && $this->isPurgeCandidate($candidate)) {
            $candidates[(int) $candidate->id()] = (int) $candidate->id();
          }
        }
        foreach (array_reverse($remove) as $delta) {
          $translation->get($name)->removeItem($delta);
        }
      }
    }
    // A stable order avoids opposite lock orders for multi-image saves. Young
    // uploads and ordinary metadata/status saves never touch the worker lock.
    sort($candidates, SORT_NUMERIC);
    foreach ($candidates as $id) {
      $this->holdReferenceLock($entity, MediaIntakeScreening::QUEUE . ':' . $id);
    }
    if ($entity instanceof NodeInterface && $entity->bundle() === 'service_request') {
      $current_ids = $this->reportMediaIds($entity);
      $original_ids = $original instanceof NodeInterface ? $this->reportMediaIds($original) : [];
      $changed_ids = array_merge(array_diff($current_ids, $original_ids), array_diff($original_ids, $current_ids));
      $this->protectPublicationReferences($entity, $changed_ids, array_diff($current_ids, $original_ids));
    }
    foreach ($ids as $type => $targets) {
      foreach ($targets as $id) {
        if (!$this->entityTypeManager->getStorage($type)->loadUnchanged($id)) {
          if ($type === 'media') {
            foreach ($entity->getTranslationLanguages() as $language) {
              $translation = $entity->getTranslation($language->getId());
              if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'service_request' && $translation->hasField('field_request_media')) {
                $values = array_filter($translation->get('field_request_media')->getValue(), static fn (array $value): bool => (string) $value['target_id'] !== (string) $id);
                $translation->set('field_request_media', array_values($values));
              }
            }
            continue;
          }
          throw new \RuntimeException('A referenced upload file no longer exists. Please upload it again.');
        }
      }
    }
  }

  /**
   * Serializes report deletion with publication before references disappear.
   */
  public function protectReportDeletion(NodeInterface $report): void {
    if ($report->bundle() === 'service_request') {
      $this->protectPublicationReferences($report, $this->reportMediaIds($report));
    }
  }

  /**
   * Holds changed image associations through commit on every lock backend.
   */
  protected function protectPublicationReferences(NodeInterface $report, array $ids, array $added = []): void {
    sort($ids, SORT_NUMERIC);
    foreach (array_unique($ids) as $id) {
      $media = $this->entityTypeManager->getStorage('media')->loadUnchanged($id);
      if ($media instanceof MediaInterface && $media->bundle() === 'request_image') {
        // Do not block an in-flight citizen analysis from saving its metadata.
        // A new unpublished attachment gets a transactional publication retry
        // instead. Detachments and existing editorial/public states serialize.
        if (in_array($id, $added, TRUE) && !$media->isPublished()
          && !$this->keyValue->get('markaspot_open311.media_publication_manual')->has($id)) {
          continue;
        }
        $this->holdReferenceLock($report, 'markaspot_open311.media_publication.' . $id);
      }
    }
  }

  /**
   * Whether a nested publication helper must leave the guard's lock held.
   */
  public function holdsPublicationLock(int $id): bool {
    return isset($this->heldLocks['markaspot_open311.media_publication.' . $id]);
  }

  /**
   * Rechecks images from a report after removal or deletion of its references.
   */
  public function unpublishDetached(NodeInterface $report, ?NodeInterface $current = NULL): void {
    if ($report->bundle() !== 'service_request') {
      return;
    }
    $ids = array_diff($this->reportMediaIds($report), $current ? $this->reportMediaIds($current) : []);
    foreach ($ids as $id) {
      $this->unpublishIfUnreferenced($id);
    }
  }

  /**
   * Collects all current report media IDs, including translations.
   */
  protected function reportMediaIds(NodeInterface $report): array {
    $ids = [];
    foreach ($report->getTranslationLanguages() as $language) {
      $translation = $report->getTranslation($language->getId());
      foreach ($translation->getFieldDefinitions() as $name => $definition) {
        if ($definition->getSetting('target_type') === 'media') {
          foreach ($translation->get($name) as $item) {
            if ($item->target_id) {
              $ids[$item->target_id] = (int) $item->target_id;
            }
          }
        }
      }
    }
    return $ids;
  }

  /**
   * Removes public access and stale manual decisions, but retains image bytes.
   *
   * Current report references across every media field/translation count here.
   * Historical revisions still prevent deletion, but do not justify public
   * access after the image was removed from the current report.
   */
  public function unpublishIfUnreferenced(int $id): void {
    $name = 'markaspot_open311.media_publication.' . $id;
    $guarded = $this->holdsPublicationLock($id);
    if (!$guarded && !$this->lock->acquire($name, 30)) {
      $this->lock->wait($name, 5);
      if (!$this->lock->acquire($name, 30)) {
        throw new \RuntimeException('Image publication is busy. Please retry saving.');
      }
    }
    try {
      $media = $this->entityTypeManager->getStorage('media')->loadUnchanged($id);
      if (!$media instanceof MediaInterface || $media->bundle() !== 'request_image') {
        return;
      }
      $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)
        ->condition('type', 'service_request')->range(0, 1);
      $references = $query->orConditionGroup();
      foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $field) {
        if ($field->getSetting('target_type') === 'media') {
          $references->condition($field->getName() . '.target_id', $id);
        }
      }
      if ($references->count() && $query->condition($references)->execute()) {
        return;
      }
      $this->keyValue->get('markaspot_open311.media_publication_manual')->delete($id);
      $this->keyValue->get('markaspot_vision.report_media_hold')->delete($id);
      if ($media->isPublished()) {
        $media->setUnpublished()->save();
      }
    }
    finally {
      if (!$guarded) {
        $this->lock->release($name);
      }
    }
  }

  /**
   * Unpublishes one update batch without waiting for busy publication locks.
   *
   * @param int[] $ids
   *   At most 50 candidate media IDs selected by the post-update sandbox.
   *
   * @return int
   *   Number of busy candidates skipped for later lifecycle/collector runs.
   */
  public function unpublishBatch(array $ids): int {
    $acquired = [];
    $ready = [];
    $skipped = 0;
    $storage = $this->entityTypeManager->getStorage('media');
    sort($ids, SORT_NUMERIC);
    try {
      foreach (array_unique($ids) as $id) {
        $name = 'markaspot_open311.media_publication.' . $id;
        if (!$this->holdsPublicationLock((int) $id)) {
          if (!$this->lock->acquire($name, 900)) {
            $skipped++;
            continue;
          }
          $acquired[] = $name;
        }
        $ready[] = $id;
      }
      if ($ready) {
        // Read only after acquiring all locks. Aggregate each current media
        // field once per batch, with at most one result per candidate. Entity
        // queries include translations but exclude historical revisions.
        $referenced = [];
        $nodes = $this->entityTypeManager->getStorage('node');
        foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $field) {
          if ($field->getSetting('target_type') === 'media') {
            $target = $field->getName() . '.target_id';
            $rows = $nodes->getAggregateQuery()->accessCheck(FALSE)
              ->condition('type', 'service_request')
              ->condition($target, $ready, 'IN')->groupBy($target)->execute();
            foreach ($rows as $row) {
              $referenced[(int) reset($row)] = TRUE;
            }
          }
        }
        $storage->resetCache($ready);
        $orphans = [];
        foreach ($storage->loadMultiple($ready) as $id => $media) {
          if ($media instanceof MediaInterface && $media->bundle() === 'request_image' && !isset($referenced[$id])) {
            $orphans[$id] = $media;
          }
        }
        $this->keyValue->get('markaspot_open311.media_publication_manual')->deleteMultiple(array_keys($orphans));
        $this->keyValue->get('markaspot_vision.report_media_hold')->deleteMultiple(array_keys($orphans));
        foreach ($orphans as $media) {
          if ($media->isPublished()) {
            $media->setUnpublished()->save();
          }
        }
      }
    }
    finally {
      try {
        $storage->resetCache($ready);
      }
      finally {
        // A failed cache clear (deadlock against a parallel save) must not
        // keep the publication locks for their whole 900 s lease.
        foreach ($acquired as $name) {
          $this->lock->release($name);
        }
      }
    }
    if ($skipped) {
      $this->loggerFactory->get('markaspot_vision')->warning('Orphan image update skipped @count busy images; later lifecycle or purge runs can revisit them.', ['@count' => $skipped]);
    }
    return $skipped;
  }

  /**
   * Request images in (after, upto] that no current service request references.
   *
   * Cheap SQL pre-filter for the bulk post update. It reads the same data as
   * unpublishBatch(): the current field rows of every service_request
   * translation, no historical revision. unpublishBatch() re-checks each
   * candidate under its lock before it changes anything, so a report saved in
   * between is still honoured. Without SQL table storage for a media field the
   * whole window is returned and the locked check decides alone.
   *
   * @return int[]
   *   Media IDs in ascending order.
   */
  public function unreferencedRequestImageIds(int $after, int $upto): array {
    $query = $this->database->select('media_field_data', 'm')
      ->fields('m', ['mid'])
      ->condition('m.bundle', 'request_image')
      ->condition('m.mid', $after, '>')
      ->condition('m.mid', $upto, '<=')
      ->distinct()
      ->orderBy('m.mid');
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;
    $filtered = TRUE;
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $field) {
      if ($field->getSetting('target_type') !== 'media') {
        continue;
      }
      if ($mapping === NULL || !$mapping->requiresDedicatedTableStorage($field)) {
        $filtered = FALSE;
        break;
      }
      $table = $mapping->getDedicatedDataTableName($field);
      $column = $mapping->getFieldColumnName($field, 'target_id');
      $reference = $this->database->select($table, 'r');
      $reference->addExpression('1');
      $reference->where("r.$column = m.mid")
        ->condition('r.bundle', 'service_request')
        ->condition('r.deleted', 0);
      $query->notExists($reference);
    }
    if (!$filtered) {
      $query = $this->database->select('media_field_data', 'm')->fields('m', ['mid'])
        ->condition('m.bundle', 'request_image')->condition('m.mid', $after, '>')
        ->condition('m.mid', $upto, '<=')->distinct()->orderBy('m.mid');
    }
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Whether a database error is a deadlock or lock wait that can be retried.
   */
  public static function isRetryableLockError(\Throwable $error): bool {
    for ($e = $error; $e !== NULL; $e = $e->getPrevious()) {
      if ($e instanceof \PDOException) {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($state === '40001' || in_array($code, [1205, 1213], TRUE)) {
          return TRUE;
        }
      }
      if (preg_match('/SQLSTATE\[40001\]|Deadlock found|Lock wait timeout exceeded/', $e->getMessage())) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an upload can already be selected by this collector.
   */
  protected function isPurgeCandidate(MediaInterface $media): bool {
    $ttl = Settings::get('markaspot_vision_orphan_upload_ttl', 86400);
    return is_int($ttl) && $ttl > 0 && $media->bundle() === 'request_image'
      && $media->getCreatedTime() < $this->time->getCurrentTime() - $ttl
      && $media->getChangedTime() < $this->time->getCurrentTime() - $ttl;
  }

  /**
   * Holds a guard-owned per-media lock through the outermost SQL transaction.
   */
  protected function holdReferenceLock(EntityInterface $entity, string $name): void {
    $key = spl_object_id($entity);
    if (isset($this->saves[$key][$name])) {
      return;
    }
    if (!isset($this->heldLocks[$name])) {
      if (!$this->lock->acquire($name, 900)) {
        $this->lock->wait($name, 5);
        if (!$this->lock->acquire($name, 900)) {
          throw new \RuntimeException('Upload cleanup is running. Please retry saving.');
        }
      }
      $this->heldLocks[$name] = 0;
    }
    $first = !isset($this->saves[$key]);
    $this->saves[$key][$name] = TRUE;
    $this->heldLocks[$name]++;
    $transactions = $this->database->transactionManager();
    if ($first && $transactions->inTransaction()) {
      $transactions->addPostTransactionCallback(function (bool $committed) use ($key): void {
        $this->releaseSaveLocks($key);
      });
    }
  }

  /**
   * Releases locks after a write outside an explicit SQL transaction.
   */
  public function releaseReferences(EntityInterface $entity): void {
    if (!$this->database->transactionManager()->inTransaction()) {
      $this->releaseSaveLocks(spl_object_id($entity));
    }
  }

  /**
   * Releases only the final guard association, never a nested save's lock.
   */
  protected function releaseSaveLocks(int $key): void {
    foreach ($this->saves[$key] ?? [] as $name => $held) {
      if (--$this->heldLocks[$name] === 0) {
        unset($this->heldLocks[$name]);
        $this->lock->release($name);
      }
    }
    unset($this->saves[$key]);
  }

  /**
   * Examines at most 50 old uploads per cron, independent of publication state.
   */
  public function purge(): int {
    $ttl = Settings::get('markaspot_vision_orphan_upload_ttl', 86400);
    if (!is_int($ttl) || $ttl <= 0) {
      return 0;
    }
    $deleted = 0;
    $examined = 0;
    try {
      // Build the complete inventory before deleting anything. Unknown storage
      // or a missing table fails closed rather than silently losing references.
      $media_references = $this->referenceColumns('media');
      $file_references = $this->referenceColumns('file');
      $this->retryFiles($file_references);
      $storage = $this->entityTypeManager->getStorage('media');
      $cutoff = $this->time->getCurrentTime() - $ttl;
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('bundle', 'request_image')
        ->condition('created', $cutoff, '<')
        ->condition('changed', $cutoff, '<')
        ->condition('mid', (int) $this->state->get(self::CURSOR, 0), '>')
        ->sort('mid')->range(0, 50)->execute();
      foreach ($ids as $id) {
        $examined++;
        // Same order as interactive analysis and the intake worker.
        $worker_lock = MediaIntakeScreening::QUEUE . ':' . $id;
        $publication_lock = 'markaspot_open311.media_publication.' . $id;
        $worker_acquired = FALSE;
        try {
          $worker_acquired = $this->lock->acquire($worker_lock, 900);
          if (!$worker_acquired) {
            continue;
          }
          if (!$this->lock->acquire($publication_lock, 30)) {
            continue;
          }
          try {
            $media = $storage->loadUnchanged($id);
            if (!$media instanceof MediaInterface
              || $media->getCreatedTime() >= $cutoff || $media->getChangedTime() >= $cutoff
              || $this->keyValue->get('markaspot_open311.media_publication_manual')->has($id)
              || $this->isReferenced($media_references, (int) $id)
              || $this->mayHaveOfflineReference($media)) {
              continue;
            }
            $files = $this->mediaFiles($media);
            $pending = $this->keyValue->get(self::PENDING_FILES);
            foreach ($files as $file) {
              $pending->set($id . ':' . $file->id(), ['mid' => (int) $id, 'fid' => (int) $file->id()]);
            }
            $media->delete();
            $deleted++;
            foreach ($files as $file) {
              $this->deleteUnusedFile($file, $file_references);
              $pending->delete($id . ':' . $file->id());
            }
          }
          finally {
            $this->lock->release($publication_lock);
          }
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('markaspot_vision')->error('Orphan upload candidate could not be deleted safely (@type).', ['@type' => get_class($e)]);
        }
        finally {
          if ($worker_acquired) {
            $this->lock->release($worker_lock);
          }
          $this->state->set(self::CURSOR, (int) $id);
        }
      }
      $this->state->set(self::CURSOR, count($ids) < 50 ? 0 : (int) end($ids));
    }
    catch (\Throwable $e) {
      // Do not include exceptions: storage paths and entity labels can be PII.
      $this->loggerFactory->get('markaspot_vision')->error('Orphan upload cleanup stopped because storage could not be checked or deleted safely (@type).', ['@type' => get_class($e)]);
    }
    $this->loggerFactory->get('markaspot_vision')->notice('Orphan upload cleanup examined @examined media and deleted @deleted.', [
      '@examined' => $examined, '@deleted' => $deleted,
    ]);
    return $deleted;
  }

  /**
   * Retries a bounded, rotating slice of journaled files on a later cron pass.
   */
  protected function retryFiles(array $references): void {
    $pending = $this->keyValue->get(self::PENDING_FILES);
    $entries = $pending->getAll();
    ksort($entries, SORT_STRING);
    $cursor = (string) $this->state->get(self::FILE_CURSOR, '');
    $entries = array_slice(array_filter($entries, static fn ($key): bool => strcmp((string) $key, $cursor) > 0, ARRAY_FILTER_USE_KEY), 0, 50, TRUE);
    foreach ($entries as $key => $entry) {
      $name = MediaIntakeScreening::QUEUE . ':' . $entry['mid'];
      $acquired = FALSE;
      try {
        // The journal becomes visible before its originating media deletion.
        // A parallel collector must not clear it while that deletion is busy.
        $acquired = $this->lock->acquire($name, 900);
        if (!$acquired) {
          continue;
        }
        if (!$this->entityTypeManager->getStorage('media')->loadUnchanged($entry['mid'])) {
          $file = $this->entityTypeManager->getStorage('file')->loadUnchanged($entry['fid']);
          if ($file instanceof FileInterface) {
            $this->deleteUnusedFile($file, $references);
          }
        }
        $pending->delete($key);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('markaspot_vision')->error('Orphan upload file cleanup deferred (@type).', ['@type' => get_class($e)]);
      }
      finally {
        if ($acquired) {
          $this->lock->release($name);
        }
        $this->state->set(self::FILE_CURSOR, (string) $key);
      }
    }
    if (count($entries) < 50) {
      $this->state->set(self::FILE_CURSOR, '');
    }
  }

  /**
   * Rechecks every file reference before deleting a journaled upload file.
   */
  protected function deleteUnusedFile(FileInterface $file, array $references): void {
    if ($this->fileUsage->listUsage($file) === []
      && !$this->isReferenced($references, (int) $file->id())
      && !$this->entityTypeManager->getStorage('file')->getQuery()->accessCheck(FALSE)
        ->condition('uri', $file->getFileUri())->condition('fid', $file->id(), '<>')->range(0, 1)->execute()) {
      $file->delete();
    }
  }

  /**
   * Inventories current, translated, revision and deleted-field references.
   */
  protected function referenceColumns(string $target): array {
    $columns = [];
    $deleted = $this->deletedFields->getFieldStorageDefinitions();
    foreach ($this->entityTypeManager->getDefinitions() as $type => $definition) {
      if (!$definition->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      $fields = $this->entityFieldManager->getFieldStorageDefinitions($type);
      foreach ($deleted as $field) {
        if ($field->getTargetEntityTypeId() === $type) {
          $fields[$field->getUniqueStorageIdentifier()] = $field;
        }
      }
      foreach ($fields as $field) {
        if ($field->getType() === 'dynamic_entity_reference') {
          throw new \RuntimeException('Dynamic references need an explicit collector adapter.');
        }
        if ($field->getSetting('target_type') !== $target) {
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($type);
        if (!$storage instanceof SqlEntityStorageInterface) {
          throw new \RuntimeException('Reference storage is not SQL.');
        }
        $mapping = $storage->getTableMapping();
        $column = $mapping->getFieldColumnName($field, 'target_id');
        if ($mapping->requiresDedicatedTableStorage($field)) {
          $tables = [$mapping->getDedicatedDataTableName($field, $field->isDeleted())];
          if ($definition->isRevisionable()) {
            $tables[] = $mapping->getDedicatedRevisionTableName($field, $field->isDeleted());
          }
        }
        else {
          $tables = array_filter($mapping->getTableNames(), static fn ($table) => in_array($field->getName(), $mapping->getFieldNames($table), TRUE));
        }
        foreach ($tables as $table) {
          if (!$this->database->schema()->fieldExists($table, $column)) {
            throw new \RuntimeException('Reference column is unavailable.');
          }
          $columns[$table . ':' . $column] = [$table, $column];
        }
      }
    }
    return array_values($columns);
  }

  /**
   * Any stored reference counts, even in a historical or deleted field row.
   */
  protected function isReferenced(array $columns, int $id): bool {
    foreach ($columns as [$table, $column]) {
      if ($this->database->select($table, 'r')->fields('r', [$column])
        ->condition($column, $id)->range(0, 1)->execute()->fetchField() !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Collects file fields from every media revision before deleting the entity.
   */
  protected function mediaFiles(MediaInterface $media): array {
    $storage = $this->entityTypeManager->getStorage('media');
    $revisions = $storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('mid', $media->id())->execute();
    $files = [];
    foreach (array_keys($revisions) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      foreach ($revision->getTranslationLanguages() as $language) {
        $translation = $revision->getTranslation($language->getId());
        foreach ($translation->getFieldDefinitions() as $name => $definition) {
          if ($definition->getSetting('target_type') === 'file') {
            foreach ($translation->get($name)->referencedEntities() as $file) {
              if ($file instanceof FileInterface) {
                $files[$file->id()] = $file;
              }
            }
          }
        }
      }
    }
    return $files;
  }

  /**
   * Protects browser-only retries according to this upload's bound tenant.
   *
   * Unbound uploads and deleted jurisdictions use the installation default;
   * an unrelated workspace's self-service flag cannot pause this collector.
   */
  protected function mayHaveOfflineReference(MediaInterface $media): bool {
    $id = $this->keyValue->get(MediaTenantScopeResolver::STORE)->get($media->uuid());
    $jurisdiction = is_numeric($id) && $this->entityTypeManager->hasDefinition('group')
      ? $this->entityTypeManager->getStorage('group')->load($id) : NULL;
    return $jurisdiction instanceof GroupInterface
      ? $this->featureScopeResolver->isEnabledEffective('offline', $jurisdiction)
      : $this->featureScopeResolver->isPlatformFeatureEnabled('offline');
  }

}
