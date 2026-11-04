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
