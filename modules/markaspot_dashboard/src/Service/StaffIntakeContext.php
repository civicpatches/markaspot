<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Binds consent exemption to one primary object and one initial save attempt.
 */
final class StaffIntakeContext {

  /**
   * Primary objects keyed by the request which validated them.
   *
   * @var \WeakMap<\Symfony\Component\HttpFoundation\Request, \Drupal\node\NodeInterface>
   */
  private \WeakMap $primaries;

  /**
   * Constructs the request-local context.
   */
  public function __construct() {
    $this->primaries = new \WeakMap();
  }

  /**
   * Registers only the exact object validated by the collection controller.
   */
  public function bind(Request $request, NodeInterface $node): void {
    $this->primaries[$request] = $node;
  }

  /**
   * Takes the exemption once, so another save needs a new ticket.
   */
  public function take(Request $request, NodeInterface $node): bool {
    if (($this->primaries[$request] ?? NULL) !== $node) {
      return FALSE;
    }
    unset($this->primaries[$request]);
    return TRUE;
  }

  /**
   * Removes unclaimed state after either success or failure of the create.
   */
  public function clear(Request $request): void {
    unset($this->primaries[$request]);
  }

}
