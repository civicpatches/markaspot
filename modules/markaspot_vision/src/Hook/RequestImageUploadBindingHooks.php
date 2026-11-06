<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;

/**
 * Binds request image uploads to the session that uploaded the file.
 *
 * Every file insert records its upload context, and the source field of
 * request_image media carries the RequestImageUploadBinding constraint that
 * checks it, so JSON:API answers a foreign or reused file with a 422.
 */
final class RequestImageUploadBindingHooks {

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
   * Attaches the upload binding to the request image source field.
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
    if ($entity_type->id() !== 'media' || $bundle !== 'request_image') {
      return;
    }
    $field = $fields['field_media_image'] ?? NULL;
    if ($field instanceof FieldConfigInterface) {
      $field->addConstraint('RequestImageUploadBinding', []);
    }
  }

}
