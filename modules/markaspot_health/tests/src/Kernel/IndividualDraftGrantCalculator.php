<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\group\PermissionScopeInterface;

/**
 * Test calculator granting the draft permission in one group, no role.
 *
 * Stands in for any calculator that adds an individual group permission
 * without a membership, which Group honours for that one group.
 */
final class IndividualDraftGrantCalculator extends PermissionCalculatorBase {

  /**
   * State key holding ['uid' => int, 'gid' => int].
   */
  public const STATE_KEY = 'markaspot_health_test.individual_draft_grant';

  /**
   * Constructs the calculator.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculated = parent::calculatePermissions($account, $scope);
    $grant = $this->state->get(self::STATE_KEY);
    if ($scope === PermissionScopeInterface::INDIVIDUAL_ID
      && is_array($grant)
      && (int) $account->id() === (int) $grant['uid']) {
      $calculated->addItem(new CalculatedPermissionsItem(
        PermissionScopeInterface::INDIVIDUAL_ID,
        (int) $grant['gid'],
        ['view unpublished group_node:service_request entity'],
        FALSE,
      ));
    }
    return $calculated;
  }

}
