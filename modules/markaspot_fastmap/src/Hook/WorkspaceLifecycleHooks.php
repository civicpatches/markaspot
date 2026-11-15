<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\NodeInterface;

/**
 * Lifecycle rules that need an explicit hook order.
 *
 * The module implements hook_node_insert() only here.
 */
final class WorkspaceLifecycleHooks {

  public function __construct(
    private readonly WorkspaceLifecycleGate $gate,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Refuses a new report whose closed workspace is known only after insert.
   *
   * Markaspot_group derives field_jurisdiction on insert (a boundary hit, the
   * single root of a site); the presave check could not see it. Runs after
   * markaspot_group and inside the save transaction, so a refusal stores
   * nothing.
   */
  #[Hook('node_insert', order: new OrderAfter(modules: ['markaspot_group']))]
  public function nodeInsert(NodeInterface $node): void {
    $this->gate->refuseAfterInsert(
      $node,
      $this->currentUser,
      (bool) drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE),
    );
  }

}
