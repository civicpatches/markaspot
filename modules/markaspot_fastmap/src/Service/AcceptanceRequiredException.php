<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Thrown when a customer workspace is provisioned without a valid acceptance.
 *
 * Raised by the provisioning service while the lifecycle switch is on, before
 * anything is written.
 */
final class AcceptanceRequiredException extends \RuntimeException {

}
