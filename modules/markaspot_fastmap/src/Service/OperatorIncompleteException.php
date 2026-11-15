<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses a save that would leave an open map without complete operator data.
 *
 * Thrown from the save of the workspace (hook_group_presave()), so every
 * writer gets it. The message starts with the machine code
 * "operator_incomplete:" and names the missing parts, the convention of the
 * other refusals raised from an entity save. A plain HttpException on
 * purpose, as WorkspaceNotOpenException explains.
 */
final class OperatorIncompleteException extends HttpException {

  /**
   * The machine code.
   */
  public const CODE = 'operator_incomplete';

  /**
   * Constructs the exception.
   *
   * @param string[] $missing
   *   The missing parts (OperatorDetails::missing()).
   */
  public function __construct(
    public readonly array $missing,
  ) {
    parent::__construct(422, self::CODE . ': The operator details of an open map must stay complete. Missing: ' . implode(', ', $missing) . '.');
  }

}
