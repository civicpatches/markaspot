<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\Validation\Constraint;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\markaspot_vision\Hook\FileUploadBindingHooks;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\markaspot_vision\Utility\UploadFileUsage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Refuses file references whose file another session uploaded or uses.
 *
 * Applies to every newly referenced file of a bound field, for accounts
 * without "administer media": the file must not be in use yet (embeds in
 * formatted text do not count, see UploadFileUsage), and it must
 * come from this request, from the same upload session or from the current
 * user's own cookie session. File and image fields reference a file by ID;
 * formatted text fields embed it with a data-entity-uuid attribute, parsed
 * exactly as the editor module parses it before it records the usage.
 * References the stored entity already holds are not checked again.
 */
final class FileUploadBindingConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Permission that may attach any existing file.
   *
   * Deliberately not "edit any request_image media" or "bypass node access":
   * staff roles hold those globally, also on stacks with several
   * jurisdictions, and must not claim another tenant's fresh upload.
   */
  public const BYPASS_PERMISSION = 'administer media';

  /**
   * Elements whose file the editor module records as used by the entity.
   *
   * @see \Drupal\editor\Hook\EditorHooks::parseFileUuids()
   */
  public const EMBED_XPATH = '//*[@data-entity-type="file" and @data-entity-uuid]';

  /**
   * Constructs the validator.
   */
  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileUsageInterface $fileUsage,
    protected readonly MediaAnalysisAccessGuard $guard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
      $container->get('markaspot_vision.media_analysis_access_guard'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FileUploadBindingConstraint || !$value instanceof FieldItemListInterface) {
      return;
    }
    $entity = $value->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    if ($this->currentUser->hasPermission(self::BYPASS_PERMISSION)) {
      return;
    }

    if (FileUploadBindingHooks::isFormattedTextField($value->getFieldDefinition())) {
      $this->validateEmbeds($value, $entity, $constraint);
    }
    else {
      $this->validateReferences($value, $entity, $constraint);
    }
  }

  /**
   * Starts a refusal, named after what the client can do about it.
   */
  protected function refuse(FileUploadBindingConstraint $constraint): ConstraintViolationBuilderInterface {
    if ($this->guard->hasUploadContext()) {
      return $this->context->buildViolation($constraint->message)
        ->setCode(FileUploadBindingConstraint::REFUSED_ERROR);
    }
    return $this->context->buildViolation($constraint->noUploadSessionMessage)
      ->setCode(FileUploadBindingConstraint::NO_UPLOAD_SESSION_ERROR);
  }

  /**
   * Checks the files a file or image field newly references.
   */
  protected function validateReferences(FieldItemListInterface $items, FieldableEntityInterface $entity, FileUploadBindingConstraint $constraint): void {
    $previous = NULL;
    foreach ($items as $delta => $item) {
      $file = $item->entity ?? NULL;
      // An unsaved file is created by the code that validates, and a missing
      // one is reported by the reference constraints.
      if (!$file instanceof FileInterface || $file->isNew()) {
        continue;
      }
      $previous ??= $this->previousTargets($entity, $items->getName());
      if (isset($previous[(int) $file->id()])) {
        continue;
      }
      if (!$this->mayAttach($file)) {
        $this->refuse($constraint)
          ->atPath((string) $delta)
          ->addViolation();
      }
    }
  }

  /**
   * Checks the files a formatted text field newly embeds.
   *
   * The editor module concatenates the field's items before it parses them,
   * so a reference is reported on the field, not on one item. Embeds of
   * unknown UUIDs record no usage and are skipped like the editor module
   * skips them.
   */
  protected function validateEmbeds(FieldItemListInterface $items, FieldableEntityInterface $entity, FileUploadBindingConstraint $constraint): void {
    $uuids = self::embeddedFileUuids($items);
    if ($uuids === []) {
      return;
    }
    $uuids = array_values(array_diff($uuids, $this->previousEmbeds($entity, $items->getName())));
    if ($uuids === []) {
      return;
    }
    foreach ($this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $uuids]) as $file) {
      if ($file instanceof FileInterface && !$this->mayAttach($file)) {
        $this->refuse($constraint)->addViolation();
      }
    }
  }

  /**
   * Whether the current request may add a reference to an existing file.
   *
   * Embeds in formatted text do not make a file used: they may have landed
   * without validation, and must not lock the uploading session out.
   */
  protected function mayAttach(FileInterface $file): bool {
    return UploadFileUsage::attachments($this->fileUsage->listUsage($file)) === []
      && $this->guard->canClaimFile($file);
  }

  /**
   * Returns the file UUIDs the editor module finds in a formatted text field.
   *
   * Mirrors EditorHooks::getFileUuidsByField() for one field: the raw value
   * of every item, followed by its summary on text_with_summary fields, in
   * one string and whatever the text format, parsed with Html::load() and
   * the same XPath. A text without the attribute name cannot match it, so
   * it is not parsed.
   *
   * @return list<string>
   *   The embedded file UUIDs, each once.
   *
   * @see \Drupal\editor\Hook\EditorHooks::getFileUuidsByField()
   */
  public static function embeddedFileUuids(FieldItemListInterface $items): array {
    $text = '';
    foreach ($items as $item) {
      $text .= self::scalar($item->value);
      if ($item->getFieldDefinition()->getType() === 'text_with_summary') {
        $text .= self::scalar($item->summary);
      }
    }
    if (stripos($text, 'data-entity-uuid') === FALSE) {
      return [];
    }
    $uuids = [];
    foreach ((new \DOMXPath(Html::load($text)))->query(self::EMBED_XPATH) as $node) {
      if ($node instanceof \DOMElement) {
        $uuids[$node->getAttribute('data-entity-uuid')] = TRUE;
      }
    }
    return array_map('strval', array_keys($uuids));
  }

  /**
   * Returns a text property as the string the editor module concatenates.
   */
  private static function scalar(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Returns the file IDs the stored entity already references in the field.
   *
   * @return array<int, true>
   *   File IDs keyed by ID.
   */
  protected function previousTargets(FieldableEntityInterface $entity, string $field_name): array {
    $targets = [];
    foreach ($this->storedTranslations($entity, $field_name) as $translation) {
      foreach ($translation->get($field_name) as $item) {
        if ($item->target_id !== NULL && $item->target_id !== '') {
          $targets[(int) $item->target_id] = TRUE;
        }
      }
    }
    return $targets;
  }

  /**
   * Returns the file UUIDs the stored entity already embeds in the field.
   *
   * @return list<string>
   *   The embedded file UUIDs.
   */
  protected function previousEmbeds(FieldableEntityInterface $entity, string $field_name): array {
    $uuids = [];
    foreach ($this->storedTranslations($entity, $field_name) as $translation) {
      array_push($uuids, ...self::embeddedFileUuids($translation->get($field_name)));
    }
    return array_values(array_unique($uuids));
  }

  /**
   * Returns every stored translation of the entity that has the field.
   *
   * Every stored translation counts, so editing one translation does not
   * re-check files another translation already holds.
   *
   * @return list<\Drupal\Core\Entity\FieldableEntityInterface>
   *   The stored translations, none for a new entity.
   */
  protected function storedTranslations(FieldableEntityInterface $entity, string $field_name): array {
    if ($entity->isNew() || $entity->id() === NULL) {
      return [];
    }
    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if (!$original instanceof FieldableEntityInterface || !$original->hasField($field_name)) {
      return [];
    }
    $translations = [$original];
    if ($original instanceof TranslatableInterface) {
      foreach (array_keys($original->getTranslationLanguages()) as $langcode) {
        $translations[] = $original->getTranslation($langcode);
      }
    }
    return $translations;
  }

}
