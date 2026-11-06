<?php

declare(strict_types=1);

namespace Drupal\service_request\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\StatusNoteRequirement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Rejects a status change to a note-required status without a new note.
 */
final class ServiceRequestStatusNoteRequiredConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly StatusNoteRequirement $requirement,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('service_request.status_note_requirement'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceRequestStatusNoteRequiredConstraint
      || !$value instanceof FieldItemListInterface) {
      return;
    }
    $node = $value->getEntity();
    if (!$node instanceof NodeInterface) {
      return;
    }
    $term = $this->requirement->missingNote($node);
    if ($term === NULL) {
      return;
    }
    $this->context->buildViolation($constraint->message)
      ->setParameter('@status', (string) $term->label())
      ->setCode(StatusNoteRequirement::ERROR_CODE)
      ->addViolation();
  }

}
