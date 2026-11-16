<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WaitingReportsNotifier;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\NodeInterface;

/**
 * Lifecycle rules implemented as hook classes.
 *
 * The module implements hook_node_insert(), hook_group_presave() and
 * hook_group_delete() only here.
 */
final class WorkspaceLifecycleHooks {

  public function __construct(
    private readonly WorkspaceLifecycleGate $gate,
    private readonly AccountInterface $currentUser,
    private readonly WorkspaceOpening $opening,
    private readonly WaitingReportsNotifier $waitingReports,
  ) {}

  /**
   * Keeps an open map's opening and complete operator data.
   *
   * A refusal rolls the save back; the settings endpoint answers it with the
   * exception's status and its "operator_incomplete:" message.
   */
  #[Hook('group_presave')]
  public function groupPresave(GroupInterface $group): void {
    $this->opening->enforceOnSave($group);
  }

  /**
   * Drops the operator mail state of a deleted workspace.
   *
   * Runs inside the delete, so a teardown that rolls back keeps it.
   */
  #[Hook('group_delete')]
  public function groupDelete(GroupInterface $group): void {
    $this->waitingReports->onWorkspaceDelete($group);
  }

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

  /**
   * Notes a report held for review for the operator mail.
   *
   * Ordered after markaspot_nuxt, whose insert hook records the hold
   * (recordHold(), or holdAfterInsert() for a report whose workspace became
   * known only on insert), and so after markaspot_group as well. Still
   * inside the save transaction: a failed save leaves no note. The mail
   * itself goes out after the save (WaitingReportsNotifier::destruct()).
   */
  #[Hook('node_insert', order: new OrderAfter(modules: ['markaspot_group', 'markaspot_nuxt']))]
  public function noteWaitingReport(NodeInterface $node): void {
    $this->waitingReports->noteHeldReport($node, $this->currentUser);
  }

}
