<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Refuses request images whose file another session uploaded or uses.
 *
 * Applies to every newly referenced file of a request_image media, for
 * accounts without "administer media": the file must not be in use yet, and
 * it must come from this request, from the same upload session or from the
 * current user's own cookie session. Unchanged references on an update are
 * not checked again.
 */
final class RequestImageUploadBindingConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Permission that may attach any existing file to a request image.
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
    if (!$constraint instanceof RequestImageUploadBindingConstraint || !$value instanceof FieldItemListInterface) {
      return;
    }
    $media = $value->getEntity();
    if (!$media instanceof MediaInterface || $media->bundle() !== 'request_image') {
      return;
    }
    if ($this->currentUser->hasPermission(self::BYPASS_PERMISSION)) {
      return;
    }

    $previous = $this->previousTargets($media, $value->getName());
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
   * Returns the file IDs the stored media already references.
   *
   * @return array<int, true>
   *   File IDs keyed by ID.
   */
  protected function previousTargets(MediaInterface $media, string $field_name): array {
    if ($media->isNew() || $media->id() === NULL) {
      return [];
    }
    $original = $this->entityTypeManager->getStorage('media')->loadUnchanged($media->id());
    if (!$original instanceof MediaInterface || !$original->hasField($field_name)) {
      return [];
    }
    $targets = [];
    foreach ($original->get($field_name) as $item) {
      if ($item->target_id !== NULL && $item->target_id !== '') {
        $targets[(int) $item->target_id] = TRUE;
      }
    }
    return $targets;
  }

}
