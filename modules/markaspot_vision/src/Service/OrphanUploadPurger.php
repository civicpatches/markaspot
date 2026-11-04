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

/**
 * Removes expired uploads only when no stored reference can still use them.
 */
class OrphanUploadPurger {

  /**
   * Serializes collection with media and file reference writes.
   */
  public const LOCK = 'markaspot_vision.orphan_upload_purge';

  /**
   * Last candidate examined, including protected uploads.
   */
  public const CURSOR = 'markaspot_vision.orphan_upload_cursor';

  /**
   * Saves holding the reference lock until their insert/update has completed.
   */
  protected array $saves = [];

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
  ) {}

  /**
   * Serializes reference writes and collection, including nondefault revisions.
   *
   * A stale dashboard/offline submission must fail validation, never save a
   * reference to an upload collected between loading the form and saving it.
   */
  public function protectReferences(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $ids = [];
    foreach ($entity->getTranslationLanguages() as $language) {
      $translation = $entity->getTranslation($language->getId());
      foreach ($translation->getFieldDefinitions() as $name => $definition) {
        if (in_array($definition->getSetting('target_type'), ['media', 'file'], TRUE)) {
          foreach ($translation->get($name) as $item) {
            if ($item->target_id) {
              $ids[$definition->getSetting('target_type')][$item->target_id] = $item->target_id;
            }
          }
        }
      }
    }
    if (!$ids) {
      return;
    }
    if (!$this->lock->acquire(self::LOCK, 900)) {
      $this->lock->wait(self::LOCK, 5);
      if (!$this->lock->acquire(self::LOCK, 900)) {
        throw new \RuntimeException('Upload cleanup is running. Please retry saving.');
      }
    }
    $key = spl_object_id($entity);
    $this->saves[$key] = TRUE;
    $transactions = $this->database->transactionManager();
    if ($transactions->inTransaction()) {
      // Redis and other non-SQL lock backends must stay locked until the SQL
      // references are visible, including when an outer transaction rolls back.
      $transactions->addPostTransactionCallback(function (bool $committed) use ($key): void {
        unset($this->saves[$key]);
        if (!$this->saves) {
          $this->lock->release(self::LOCK);
        }
      });
    }
    foreach ($ids as $type => $targets) {
      foreach ($targets as $id) {
        if (!$this->entityTypeManager->getStorage($type)->loadUnchanged($id)) {
          throw new \RuntimeException('A referenced upload no longer exists. Please upload it again.');
        }
      }
    }
  }

  /**
   * Releases the reference lock after the database write.
   */
  public function releaseReferences(EntityInterface $entity): void {
    if (!$this->database->transactionManager()->inTransaction() && isset($this->saves[spl_object_id($entity)])) {
      unset($this->saves[spl_object_id($entity)]);
      if (!$this->saves) {
        $this->lock->release(self::LOCK);
      }
    }
  }

  /**
   * Examines at most 50 old uploads per cron, independent of publication state.
   */
  public function purge(): int {
    $ttl = Settings::get('markaspot_vision_orphan_upload_ttl', 86400);
    if (!is_int($ttl) || $ttl <= 0 || !$this->lock->acquire(self::LOCK, 900)) {
      return 0;
    }
    $deleted = 0;
    $examined = 0;
    try {
      // Build the complete inventory before deleting anything. Unknown storage
      // or a missing table fails closed rather than silently losing references.
      if ($this->mayHaveOfflineReference()) {
        $this->loggerFactory->get('markaspot_vision')->notice('Orphan upload cleanup deferred: offline retries have no server-side upload lease.');
        return 0;
      }
      $media_references = $this->referenceColumns('media');
      $file_references = $this->referenceColumns('file');
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
        if (!$this->lock->acquire($worker_lock, 900)) {
          continue;
        }
        try {
          if (!$this->lock->acquire($publication_lock, 30)) {
            continue;
          }
          try {
            $media = $storage->loadUnchanged($id);
            if (!$media instanceof MediaInterface
              || $media->getCreatedTime() >= $cutoff || $media->getChangedTime() >= $cutoff
              || $this->keyValue->get('markaspot_open311.media_publication_manual')->has($id)
              || $this->isReferenced($media_references, (int) $id)) {
              continue;
            }
            $files = $this->mediaFiles($media);
            $media->delete();
            $deleted++;
            foreach ($files as $file) {
              if ($this->fileUsage->listUsage($file) === []
                && !$this->isReferenced($file_references, (int) $file->id())
                && !$this->entityTypeManager->getStorage('file')->getQuery()->accessCheck(FALSE)
                  ->condition('uri', $file->getFileUri())->condition('fid', $file->id(), '<>')->range(0, 1)->execute()) {
                $file->delete();
              }
            }
          }
          finally {
            $this->lock->release($publication_lock);
          }
        }
        finally {
          $this->lock->release($worker_lock);
        }
      }
      $this->state->set(self::CURSOR, count($ids) < 50 ? 0 : (int) end($ids));
    }
    catch (\Throwable $e) {
      // Do not include exceptions: storage paths and entity labels can be PII.
      $this->loggerFactory->get('markaspot_vision')->error('Orphan upload cleanup stopped because storage could not be checked or deleted safely (@type).', ['@type' => get_class($e)]);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    $this->loggerFactory->get('markaspot_vision')->notice('Orphan upload cleanup examined @examined media and deleted @deleted.', [
      '@examined' => $examined, '@deleted' => $deleted,
    ]);
    return $deleted;
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
   * Offline retries hold remote UUIDs in IndexedDB, without a server lease.
   *
   * Conservatively retain uploads when any jurisdiction permits offline work.
   * This also protects legacy uploads without a trusted jurisdiction binding.
   */
  protected function mayHaveOfflineReference(): bool {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return FALSE;
    }
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple() as $group) {
      if (!$group->hasField('field_nuxt_config')) {
        continue;
      }
      $raw = (string) $group->get('field_nuxt_config')->value;
      $config = $raw === '' ? [] : json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($config)) {
        throw new \RuntimeException('Offline policy is unavailable.');
      }
      $offline = $config['features']['offline'] ?? FALSE;
      if ($offline === TRUE || (is_array($offline) && ($offline['enabled'] ?? FALSE))) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
