<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Refuses file references whose file another session uploaded or uses.
 *
 * Applies to every newly referenced file of a bound field, for accounts
 * without "administer media": the file must not be in use yet, and it must
 * come from this request, from the same upload session or from the current
 * user's own cookie session. References the stored entity already holds are
 * not checked again.
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

    $previous = $this->previousTargets($entity, $value->getName());
    foreach ($value as $delta => $item) {
      $file = $item->entity ?? NULL;
      // An unsaved file is created by the code that validates, and a missing
      // one is reported by the reference constraints.
      if (!$file instanceof FileInterface || $file->isNew()) {
        continue;
      }
      if (isset($previous[(int) $file->id()])) {
        continue;
      }
      if ($this->fileUsage->listUsage($file) !== [] || !$this->guard->canClaimFile($file)) {
        $this->context->buildViolation($constraint->message)
          ->atPath((string) $delta)
          ->addViolation();
      }
    }
  }

  /**
   * Returns the file IDs the stored entity already references in the field.
   *
   * Every stored translation counts, so editing one translation does not
   * re-check files another translation already holds.
   *
   * @return array<int, true>
   *   File IDs keyed by ID.
   */
  protected function previousTargets(FieldableEntityInterface $entity, string $field_name): array {
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
    $targets = [];
    foreach ($translations as $translation) {
      foreach ($translation->get($field_name) as $item) {
        if ($item->target_id !== NULL && $item->target_id !== '') {
          $targets[(int) $item->target_id] = TRUE;
        }
      }
    }
    return $targets;
  }

}
