<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Hook;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\file\FileInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;

/**
 * Binds file references to the session that uploaded the file.
 *
 * Every file insert records its upload context, and every file, image and
 * formatted text field of every content entity type carries the
 * FileUploadBinding constraint that checks it, so JSON:API answers a foreign
 * or reused file with a 422.
 */
final class FileUploadBindingHooks {

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
   * Attaches the upload binding to every configurable file reference field.
   *
   * Every content entity type, not a list of known hosts: a tenant overlay
   * or a later module may expose another entity type with a file field to
   * anonymous clients, and each new reference to a fresh upload changes who
   * may blur it. Hosts written by their own upload controller (group
   * branding) or by the owning user pass through the same-request and
   * owner-session paths of the binding.
   *
   * The field type decides, not the field name, so a media type whose source
   * field has another name is covered as well, and so is every formatted text
   * field the editor module scans for embedded files. Base field overrides
   * arrive here too, because they do not inherit the base field's
   * constraints.
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
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return;
    }
    foreach ($fields as $field) {
      if ($field instanceof FieldConfigInterface && self::isBound($field)) {
        $field->addConstraint('FileUploadBinding', []);
      }
    }
  }

  /**
   * Attaches the upload binding to writable file reference base fields.
   *
   * File, image and formatted text base fields (taxonomy term descriptions,
   * for example) are bound like configurable ones. Read-only and computed
   * base fields are skipped: clients cannot set them, and media keep their
   * thumbnail, which is the source file itself, there.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The base field definitions.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   */
  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return;
    }
    foreach ($fields as $field) {
      if ($field instanceof BaseFieldDefinition
        && !$field->isReadOnly()
        && !$field->isComputed()
        && self::isBound($field)) {
        $field->addConstraint('FileUploadBinding', []);
      }
    }
  }

  /**
   * Whether a field references files the binding has to check.
   *
   * File and image fields reference files by ID. Formatted text fields
   * (subclasses of TextItemBase) reference them through data-entity-uuid
   * attributes: on every insert and update, the editor module records a file
   * usage for each such attribute in their raw value, whatever the text
   * format.
   */
  public static function isBound(FieldDefinitionInterface $field): bool {
    return self::isFileField($field) || self::isFormattedTextField($field);
  }

  /**
   * Whether a field is a file or image field.
   */
  public static function isFileField(FieldDefinitionInterface $field): bool {
    return is_a($field->getItemDefinition()->getClass(), FileItem::class, TRUE);
  }

  /**
   * Whether the editor module records file usage for a field's text.
   *
   * @see \Drupal\editor\Hook\EditorHooks::getFormattedTextFields()
   */
  public static function isFormattedTextField(FieldDefinitionInterface $field): bool {
    return is_subclass_of($field->getItemDefinition()->getClass(), TextItemBase::class);
  }

}
