<?php

declare(strict_types=1);

namespace Drupal\service_request\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Requires a status note when a report moves to a status that asks for one.
 *
 * Keep the message in line with StatusNoteRequirement::message().
 *
 * @Constraint(
 *   id = "ServiceRequestStatusNoteRequired",
 *   label = @Translation("Service request status note required", context = "Validation"),
 *   type = "field"
 * )
 */
final class ServiceRequestStatusNoteRequiredConstraint extends Constraint {

  /**
   * Message shown when the required note is missing.
   */
  public string $message = 'The status @status requires a status note. Add a note that explains the change.';

}
