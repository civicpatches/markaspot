<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Binds a new request image to the upload session that created its file.
 *
 * The citizen frontend uploads the file and creates the request_image media
 * in two requests. Without this binding, another session that learns the file
 * UUID in between could attach the fresh upload to its own media first and
 * become the photo's oldest user.
 */
#[Constraint(
  id: 'RequestImageUploadBinding',
  label: new TranslatableMarkup('Request image upload binding', options: ['context' => 'Validation']),
)]
final class RequestImageUploadBindingConstraint extends SymfonyConstraint {

  /**
   * Message for a file that the current request may not attach.
   *
   * One message for every refusal, so it does not reveal whether a file
   * exists, is in use or belongs to another upload.
   */
  public string $message = 'This file cannot be attached to a new photo. Please upload the photo again.';

}
