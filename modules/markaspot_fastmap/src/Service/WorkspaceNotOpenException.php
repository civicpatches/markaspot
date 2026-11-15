<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses a new report in a workspace that is not open yet.
 *
 * Thrown from the save of the report (hook_node_presave()), so no entrance
 * can skip it. The storage wraps it in an EntityStorageException; the
 * markaspot_group exception subscriber unwraps it for JSON:API, and the
 * GeoReport POST resource rethrows it, so both answer 403 with the message.
 *
 * A plain HttpException on purpose, not an AccessDeniedHttpException: core
 * turns an access denial for an anonymous request into an authentication
 * challenge (a 401 "No authentication credentials provided." where Basic
 * Auth is enabled), which would lose the code.
 */
final class WorkspaceNotOpenException extends HttpException {

  public function __construct() {
    parent::__construct(403, WorkspaceLifecycleGate::MESSAGE);
  }

}
