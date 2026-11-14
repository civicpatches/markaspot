<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Exception;

/**
 * A note draft with text declares no valid origin while the brief is on.
 *
 * The caller answers with a 400 "invalid_request" before any model call.
 */
final class InvalidNoteOriginException extends \InvalidArgumentException {

}
