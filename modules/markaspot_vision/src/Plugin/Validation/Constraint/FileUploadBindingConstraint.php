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
 * of any bundle, from a file field of any other content entity or from a
 * data-entity-uuid embed in formatted text, and so block the photo's blur.
 */
#[Constraint(
  id: 'FileUploadBinding',
  label: new TranslatableMarkup('File upload binding', options: ['context' => 'Validation']),
)]
final class FileUploadBindingConstraint extends SymfonyConstraint {

  /**
   * Violation code of a refusal of a request with an upload context.
   */
  public const REFUSED_ERROR = 'markaspot_vision.file_upload_binding.refused';

  /**
   * Violation code of a refusal of a request without any upload context.
   */
  public const NO_UPLOAD_SESSION_ERROR = 'markaspot_vision.file_upload_binding.no_upload_session';

  /**
   * Message for a file that the current request may not attach.
   *
   * One message for every refusal, so it does not reveal whether a file
   * exists, is in use or belongs to another upload.
   */
  public string $message = 'This file cannot be attached. Please upload it again.';

  /**
   * Message for a refusal of a request without any upload context.
   *
   * Without an X-CSRF-Token header and a session cookie, no upload of another
   * request can ever be attached, so uploading again the same way cannot
   * help. The message names only what the request lacks, nothing about the
   * file.
   */
  public string $noUploadSessionMessage = 'This file cannot be attached: the request carries neither an X-CSRF-Token header nor a session cookie. Fetch a token from /session/token, send it with the upload and with this request, then upload the file again.';

}
