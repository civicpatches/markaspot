<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Binds a new file reference to the upload session that created the file.
 *
 * The citizen frontend uploads the file and creates the request_image media
 * in two requests. Without this binding, another session that learns the file
 * UUID in between could reference the fresh upload first, from its own media
 * of any bundle or from a node file field, and so block the photo's blur.
 */
#[Constraint(
  id: 'FileUploadBinding',
  label: new TranslatableMarkup('File upload binding', options: ['context' => 'Validation']),
)]
final class FileUploadBindingConstraint extends SymfonyConstraint {

  /**
   * Message for a file that the current request may not attach.
   *
   * One message for every refusal, so it does not reveal whether a file
   * exists, is in use or belongs to another upload.
   */
  public string $message = 'This file cannot be attached. Please upload it again.';

}
