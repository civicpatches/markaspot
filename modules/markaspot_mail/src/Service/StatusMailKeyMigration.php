<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\eca\Entity\Eca;
use Drupal\eca\Entity\EcaStorage;

/**
 * An explicit, recoverable switch from fixed status branches to term keys.
 */
class StatusMailKeyMigration {

  public const LOCK = 'markaspot_mail.status_mail_keys';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected TimeInterface $time,
    protected StatusMailKeyAnalyzer $analyzer,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Reads base config entities (never language overrides) and status content.
   */
  public function analyze(): array {
    $storage = $this->entityTypeManager->getStorage('eca');
    $storage->resetCache();
    $old = $storage->load(StatusMailKeyAnalyzer::OLD_MODEL);
    $term = $storage->load(StatusMailKeyAnalyzer::TERM_MODEL);
    if (!$old && !$term) {
      return ['rows' => [], 'keys' => [], 'result' => 'nothing to migrate'];
    }
    if (!$old) {
      throw new \RuntimeException('Old model is missing: incomplete, cannot prove the expected keys.');
    }
    if (!$term) {
      throw new \RuntimeException('The term model is missing.');
    }
    foreach ([
      ['node', 'service_request', 'field_notification', 'boolean'],
      ['node', 'service_request', 'field_status', 'entity_reference'],
      ['node', 'service_request', 'field_e_mail', 'email'],
      ['taxonomy_term', 'service_status', 'field_notification_key', 'string'],
    ] as [$type, $bundle, $field, $fieldType]) {
      $definition = $this->fieldManager->getFieldDefinitions($type, $bundle)[$field] ?? NULL;
      if (!$definition || $definition->getType() !== $fieldType || $definition->getFieldStorageDefinition()->getCardinality() !== 1) {
        throw new \RuntimeException("Missing or incompatible $field field.");
      }
    }
    $statusField = $this->fieldManager->getFieldDefinitions('node', 'service_request')['field_status'];
    if ($statusField->getSetting('target_type') !== 'taxonomy_term') {
      throw new \RuntimeException('Incompatible status reference target.');
    }
    $terms = [];
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $termStorage->resetCache();
    foreach ($termStorage->loadMultiple() as $id => $entity) {
      $terms[$id] = [
        'name' => $entity->label(),
        'compatible' => $entity->bundle() === 'service_status' && $entity->hasField('field_notification_key'),
        'key' => $entity->hasField('field_notification_key') ? (string) $entity->get('field_notification_key')->value : '',
        'jurisdiction' => $entity->hasField('field_jurisdiction') ? implode(', ', array_column($entity->get('field_jurisdiction')->getValue(), 'target_id')) : '',
      ];
    }
    return $this->analyzer->analyze($old->toArray(), $term->toArray(), $terms);
  }

  /**
   * Applies a freshly analyzed plan while holding maintenance and cron locks.
   */
  public function apply(): array {
    $this->acquire();
    $backup = NULL;
    try {
      $plan = $this->analyze();
      if ($plan['result'] !== 'ready to migrate') {
        if ($plan['result'] === 'already migrated') {
          $this->checkRegistration();
        }
        return $plan;
      }
      $snapshot = $this->snapshot($plan['keys']);
      $backup = $this->writeBackup($snapshot);
      foreach ($plan['keys'] as $id => $key) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
        if ($term->get('field_notification_key')->isEmpty()) {
          $term->set('field_notification_key', $key)->save();
        }
      }
      $this->disableOldModel();
      $this->postCheck($plan['keys']);
      return $plan + ['backup' => $backup, 'completed' => 'migrated now'];
    }
    catch (\Throwable $e) {
      $restore = $backup === NULL ? '' : " Keep maintenance mode enabled. Restore with:\n  drush markaspot:status-mail-keys --restore=" . escapeshellarg($backup);
      throw new \RuntimeException($e->getMessage() . $restore, 0, $e);
    }
    finally {
      $this->lock->release('cron');
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Restores only the models and term keys in a validated before-state file.
   */
  public function restore(string $path): array {
    $this->acquire();
    try {
      $data = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->validateSnapshot($data);
      // Restore models last so the old sender is never enabled prematurely.
      foreach ($data['terms'] as $id => $saved) {
        $this->entityTypeManager->getStorage('taxonomy_term')->load($id)->set('field_notification_key', $saved['value'])->save();
      }
      $storage = $this->entityTypeManager->getStorage('eca');
      foreach ($data['models'] as $id => $values) {
        $model = $storage->load($id);
        foreach ($values as $key => $value) {
          $model->set($key, $value);
        }
        $model->save();
      }
      $storage->resetCache();
      foreach ($data['models'] as $id => $values) {
        if ($storage->load($id)->toArray() !== $values) {
          throw new \RuntimeException('Restored model differs from backup.');
        }
      }
      $terms = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms->resetCache();
      foreach ($data['terms'] as $id => $saved) {
        if ($terms->load($id)->get('field_notification_key')->getValue() !== $saved['value']) {
          throw new \RuntimeException('Restored key differs from backup.');
        }
      }
      $this->checkRegistration();
      return ['rows' => [], 'keys' => [], 'result' => 'restored; maintenance mode remains enabled'];
    }
    catch (\Throwable $e) {
      throw new \RuntimeException($e->getMessage() . " Keep maintenance mode enabled. Retry:\n  drush markaspot:status-mail-keys --restore=" . escapeshellarg($path), 0, $e);
    }
    finally {
      $this->lock->release('cron');
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Checks active cron/queue locks and leases before taking both run locks.
   */
  private function acquire(): void {
    if (!$this->state->get('system.maintenance_mode')) {
      throw new \RuntimeException('Maintenance mode is required.');
    }
    // Drupal cron has a lock; DatabaseQueue workers instead hold item leases.
    // Include persistent/custom cron and queue locks in the semaphore table.
    $query = $this->database->select('semaphore', 's')->fields('s', ['name'])->condition('expire', $this->time->getCurrentTime(), '>');
    foreach ($query->execute()->fetchCol() as $name) {
      if (preg_match('/cron|queue/i', $name)) {
        throw new \RuntimeException("Active cron or queue lock: $name.");
      }
    }
    if ($this->database->schema()->tableExists('queue') && $this->database->select('queue', 'q')->condition('expire', $this->time->getCurrentTime(), '>')->countQuery()->execute()->fetchField()) {
      throw new \RuntimeException('An active queue item lease exists.');
    }
    if (!$this->lock->acquire(self::LOCK, 3600)) {
      throw new \RuntimeException('Another status-mail migration holds the command lock.');
    }
    if (!$this->lock->acquire('cron', 3600)) {
      $this->lock->release(self::LOCK);
      throw new \RuntimeException('An active cron lock exists.');
    }
  }

  /**
   * Stores the old model through EcaStorage, which rebuilds subscriptions.
   */
  protected function disableOldModel(): void {
    $this->entityTypeManager->getStorage('eca')->load(StatusMailKeyAnalyzer::OLD_MODEL)->disable()->save();
  }

  /**
   * Checks persisted keys, model state and the effective event registration.
   */
  private function postCheck(array $keys): void {
    $plan = $this->analyze();
    if ($plan['result'] !== 'already migrated' || $plan['keys'] !== $keys) {
      throw new \RuntimeException('Migration post-check failed.');
    }
    $this->checkRegistration();
  }

  /**
   * Rebuilds and independently compares both models' registered events.
   */
  private function checkRegistration(): void {
    $storage = $this->entityTypeManager->getStorage('eca');
    if (!$storage instanceof EcaStorage) {
      throw new \RuntimeException('Expected EcaStorage for event registration.');
    }
    $storage->rebuildSubscribedEvents();
    $this->state->resetCache();
    $registered = $this->state->get('eca.subscribed', []);
    foreach ([StatusMailKeyAnalyzer::OLD_MODEL, StatusMailKeyAnalyzer::TERM_MODEL] as $id) {
      $model = $storage->load($id);
      $expected = [];
      if ($model->status()) {
        foreach ($model->getUsedEvents() as $eventId => $event) {
          $plugin = $event->getPlugin();
          $expected[$plugin->eventName()][$plugin->subscriberPriority()][$eventId] = $plugin->generateWildcard($id, $event);
        }
      }
      $actual = [];
      foreach ($registered as $name => $priorities) {
        foreach ($priorities as $priority => $models) {
          if (isset($models[$id])) {
            $actual[$name][$priority] = $models[$id];
          }
        }
      }
      if ($actual !== $expected) {
        throw new \RuntimeException("Event registration differs for $id.");
      }
    }
  }

  /**
   * Builds a minimal backup, containing no report or recipient data.
   */
  private function snapshot(array $keys): array {
    $data = [
      'version' => 1,
      'site_uuid' => $this->configFactory->get('system.site')->get('uuid'),
      'models' => [],
      'terms' => [],
    ];
    foreach ([StatusMailKeyAnalyzer::OLD_MODEL, StatusMailKeyAnalyzer::TERM_MODEL] as $id) {
      $data['models'][$id] = $this->entityTypeManager->getStorage('eca')->load($id)->toArray();
    }
    foreach ($keys as $id => $key) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
      $data['terms'][$id] = [
        'uuid' => $term->uuid(),
        'value' => $term->get('field_notification_key')->getValue(),
      ];
    }
    return $data;
  }

  /**
   * Rejects a backup from another site or changed entity identities.
   */
  private function validateSnapshot(array $data): void {
    if (($data['version'] ?? NULL) !== 1 || ($data['site_uuid'] ?? NULL) !== $this->configFactory->get('system.site')->get('uuid') || array_keys($data['models'] ?? []) !== [
      StatusMailKeyAnalyzer::OLD_MODEL, StatusMailKeyAnalyzer::TERM_MODEL,
    ] || empty($data['terms'])) {
      throw new \RuntimeException('Invalid backup or wrong site.');
    }
    foreach ($data['models'] as $id => $values) {
      $model = $this->entityTypeManager->getStorage('eca')->load($id);
      if (!$model instanceof Eca || $model->uuid() !== ($values['uuid'] ?? NULL) || ($values['id'] ?? '') !== $id) {
        throw new \RuntimeException('Backup model identity differs.');
      }
    }
    foreach ($data['terms'] as $id => $saved) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
      if (!$term || $term->bundle() !== 'service_status' || $term->uuid() !== ($saved['uuid'] ?? NULL) || !$term->hasField('field_notification_key') || !is_array($saved['value'] ?? NULL)) {
        throw new \RuntimeException('Backup term identity or key field differs.');
      }
    }
  }

  /**
   * Saves an exclusive, owner-readable backup before the first entity write.
   */
  private function writeBackup(array $snapshot): string {
    $base = Settings::get('file_private_path') ?: $this->fileSystem->getTempDirectory();
    $directory = $base . '/status-mail-keys';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \RuntimeException('Cannot create the status-mail backup directory.');
    }
    $path = $directory . '/' . gmdate('Ymd-His', $this->time->getCurrentTime()) . '-' . bin2hex(random_bytes(8)) . '.json';
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $handle = fopen($path, 'x');
    if ($handle === FALSE) {
      throw new \RuntimeException('Cannot create the before-state file.');
    }
    try {
      if (!chmod($path, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
        throw new \RuntimeException('Cannot save the complete before-state file.');
      }
    }
    finally {
      fclose($handle);
    }
    return $path;
  }

}
