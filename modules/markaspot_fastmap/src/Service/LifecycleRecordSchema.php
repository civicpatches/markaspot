<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;

/**
 * Brings existing sites to the install state of the lifecycle record.
 *
 * Files in config/optional reach fresh installs only. This adds, on an
 * existing site, the platform switch (off), the JSON:API exclusion of the
 * record field and the field itself. Idempotent; writes no workspace data.
 * The exclusion is written before the field exists, because JSON:API Extras
 * exposes every field its resource config does not list.
 */
final class LifecycleRecordSchema {

  /**
   * The JSON:API resource config of the jurisdiction group.
   */
  public const RESOURCE_CONFIG = 'jsonapi_extras.jsonapi_resource_config.group--jur';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $optionalConfigDirectory,
  ) {}

  /**
   * Applies the missing parts.
   *
   * @return string[]
   *   One message per step.
   */
  public function apply(): array {
    return [
      $this->ensureSwitch(),
      $this->ensureJsonApiExclusion(),
      $this->ensureField(),
    ];
  }

  /**
   * Adds the platform switch, off, when the key is missing.
   */
  private function ensureSwitch(): string {
    $config = $this->configFactory->getEditable('markaspot_fastmap.settings');
    if ($config->get('lifecycle_enabled') !== NULL) {
      return 'Lifecycle switch already present.';
    }
    $config->set('lifecycle_enabled', FALSE)->save();
    return 'Added the lifecycle switch (off).';
  }

  /**
   * Disables the record field on the jurisdiction JSON:API resource.
   */
  private function ensureJsonApiExclusion(): string {
    $config = $this->configFactory->getEditable(self::RESOURCE_CONFIG);
    if ($config->isNew()) {
      // Field permissions still deny every non-administrator.
      return 'Skipped the JSON:API exclusion: the jurisdiction resource is not installed.';
    }
    $fields = $config->get('resourceFields') ?: [];
    $entry = [
      'disabled' => TRUE,
      'fieldName' => WorkspaceLifecycle::FIELD,
      'publicName' => WorkspaceLifecycle::FIELD,
      'enhancer' => ['id' => ''],
    ];
    if (($fields[WorkspaceLifecycle::FIELD] ?? NULL) === $entry) {
      return 'The lifecycle record is already disabled on JSON:API.';
    }
    $fields[WorkspaceLifecycle::FIELD] = $entry;
    $config->set('resourceFields', $fields)->save();
    return 'Disabled field_lifecycle_record on the jurisdiction JSON:API resource.';
  }

  /**
   * Creates the field storage and the jur instance from the shipped config.
   *
   * An existing storage or instance of that name is checked against what is
   * shipped. Settings that can be corrected safely are corrected (field
   * permission type, translatability); a different field type or
   * cardinality stops the update with an exception.
   */
  private function ensureField(): string {
    $source = new FileStorage($this->optionalConfigDirectory);
    $storageId = 'group.' . WorkspaceLifecycle::FIELD;
    $fieldId = 'group.jur.' . WorkspaceLifecycle::FIELD;
    $created = [];
    $corrected = [];

    $storageStorage = $this->entityTypeManager->getStorage('field_storage_config');
    $storage = $storageStorage->load($storageId);
    if (!$storage) {
      $data = $source->read('field.storage.' . $storageId);
      if (!is_array($data)) {
        throw new \RuntimeException('The shipped lifecycle record field storage config is missing.');
      }
      $storageStorage->create($data)->save();
      $created[] = 'storage';
    }
    elseif ($storage instanceof FieldStorageConfigInterface) {
      $corrected = $this->correctStorage($storage);
    }

    if (!$this->entityTypeManager->getStorage('group_type')->load('jur')) {
      return $created === []
        ? 'Skipped the lifecycle record field: no jur group type.' . $this->correctedSuffix($corrected)
        : 'Created the lifecycle record field storage; no jur group type for the instance.';
    }

    $fieldStorage = $this->entityTypeManager->getStorage('field_config');
    $field = $fieldStorage->load($fieldId);
    if (!$field) {
      $data = $source->read('field.field.' . $fieldId);
      if (!is_array($data)) {
        throw new \RuntimeException('The shipped lifecycle record field config is missing.');
      }
      $fieldStorage->create($data)->save();
      $created[] = 'instance';
    }
    elseif ($field instanceof FieldConfigInterface) {
      $corrected = array_merge($corrected, $this->correctInstance($field));
    }

    // The field is written by the platform only: never in the admin form.
    $formDisplay = $this->entityTypeManager->getStorage('entity_form_display')->load('group.jur.default');
    if ($formDisplay && $formDisplay->getComponent(WorkspaceLifecycle::FIELD) !== NULL) {
      $formDisplay->removeComponent(WorkspaceLifecycle::FIELD)->save();
      $corrected[] = 'removed from the jur form display';
    }

    return ($created === []
      ? 'The lifecycle record field already exists.'
      : 'Created the lifecycle record field: ' . implode(', ', $created) . '.') . $this->correctedSuffix($corrected);
  }

  /**
   * Checks an existing field storage against the shipped one.
   *
   * @return string[]
   *   The corrections made.
   *
   * @throws \RuntimeException
   *   When the field type or cardinality differ: the stored values would not
   *   fit the record, so the update must not continue.
   */
  private function correctStorage(FieldStorageConfigInterface $storage): array {
    if ($storage->getType() !== 'string_long') {
      throw new \RuntimeException(sprintf(
        'Field storage group.%s exists with type "%s"; the lifecycle record needs "string_long". Resolve the conflicting field by hand, then run the update again.',
        WorkspaceLifecycle::FIELD,
        $storage->getType(),
      ));
    }
    if ($storage->getCardinality() !== 1) {
      throw new \RuntimeException(sprintf(
        'Field storage group.%s exists with cardinality %d; the lifecycle record needs 1. Resolve the conflicting field by hand, then run the update again.',
        WorkspaceLifecycle::FIELD,
        $storage->getCardinality(),
      ));
    }
    $corrected = [];
    if ($storage->getThirdPartySetting('field_permissions', 'permission_type') !== 'custom') {
      $storage->setThirdPartySetting('field_permissions', 'permission_type', 'custom');
      $corrected[] = 'field permission type custom';
    }
    if ($storage->isTranslatable()) {
      $storage->setTranslatable(FALSE);
      $corrected[] = 'storage not translatable';
    }
    if ($corrected !== []) {
      $storage->save();
    }
    return $corrected;
  }

  /**
   * Checks an existing field instance against the shipped one.
   *
   * A default value would copy a record into every new workspace at
   * create(), also with the switch off, so it is removed.
   *
   * @return string[]
   *   The corrections made.
   */
  private function correctInstance(FieldConfigInterface $field): array {
    $corrected = [];
    if ($field->isTranslatable()) {
      $field->setTranslatable(FALSE);
      $corrected[] = 'instance not translatable';
    }
    if ($field->getDefaultValueLiteral() !== []) {
      $field->setDefaultValue([]);
      $corrected[] = 'default value removed';
    }
    if ((string) $field->getDefaultValueCallback() !== '') {
      $field->setDefaultValueCallback('');
      $corrected[] = 'default value callback removed';
    }
    if ($corrected !== []) {
      $field->save();
    }
    return $corrected;
  }

  /**
   * Formats the corrections for the update message.
   */
  private function correctedSuffix(array $corrected): string {
    return $corrected === [] ? '' : ' Corrected: ' . implode(', ', $corrected) . '.';
  }

}
