<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\file\FileInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;

/**
 * Binds file references to the session that uploaded the file.
 *
 * Every file insert records its upload context, and every file and image
 * field of media and nodes carries the FileUploadBinding constraint that
 * checks it, so JSON:API answers a foreign or reused file with a 422.
 */
final class FileUploadBindingHooks {

  /**
   * Entity types whose file fields only take files of the current upload.
   *
   * Anonymous clients can create media of every bundle and service request
   * nodes, and each new reference to a fresh upload changes who may blur it.
   * Group branding files are only written by their own upload controller.
   */
  public const BOUND_ENTITY_TYPES = ['media', 'node'];

  /**
   * Constructs the hooks.
   */
  public function __construct(
    protected readonly MediaAnalysisAccessGuard $guard,
  ) {}

  /**
   * Records the upload context of a new file.
   */
  #[Hook('file_insert')]
  public function fileInsert(FileInterface $file): void {
    $this->guard->recordFileUpload($file);
  }

  /**
   * Forgets the upload context of a deleted file.
   */
  #[Hook('file_delete')]
  public function fileDelete(FileInterface $file): void {
    $this->guard->forgetFile($file);
  }

  /**
   * Attaches the upload binding to every file and image field.
   *
   * The field type decides, not the field name, so a media type whose source
   * field has another name is covered as well.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The bundle field definitions.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if (!in_array($entity_type->id(), self::BOUND_ENTITY_TYPES, TRUE)) {
      return;
    }
    foreach ($fields as $field) {
      if ($field instanceof FieldConfigInterface && is_a($field->getItemDefinition()->getClass(), FileItem::class, TRUE)) {
        $field->addConstraint('FileUploadBinding', []);
      }
    }
  }

}
