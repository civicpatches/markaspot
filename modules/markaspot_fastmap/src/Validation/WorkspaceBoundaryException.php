<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Validation;

/**
 * Signals that a signup needs a confirmed reporting area.
 */
final class WorkspaceBoundaryException extends \InvalidArgumentException {}
