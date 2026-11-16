<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * Separates general staff capability from authority in a concrete workspace.
 */
final class StaffCapability {

  /**
   * Constructs the shared staff capability checker.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Preserves the general dashboard capability used by analysis budgets.
   */
  public function hasDashboardAccess(AccountInterface $account): bool {
    // Shipped moderator, editorial_board and tenant_admin roles have this
    // restricted dashboard permission; administrators inherit it as well.
    // Legacy/custom editorial roles may instead carry report editing rights.
    if ($account->hasPermission('access dashboard notifications')
      || $account->hasPermission('administer nodes')
      || $account->hasPermission('edit any service_request content')) {
      return TRUE;
    }

    if (!$this->entityTypeManager->hasDefinition('group_relationship')) {
      return FALSE;
    }

    $jurisdiction_type = $this->configFactory->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
    $memberships = $this->entityTypeManager->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $account->id(),
        'plugin_id' => 'group_membership',
      ]);
    foreach ($memberships as $membership) {
      if (!$membership instanceof GroupRelationshipInterface) {
        continue;
      }
      $group = $membership->getGroup();
      // The Nuxt dashboard admits elevated jurisdiction memberships, not plain
      // jur-member, derived jur-org_member or organisation-only memberships.
      // Unpublished-report access distinguishes jur-moderator, jur-editorial,
      // jur-tenant_admin and group admins without hard-coding their role IDs.
      if ($group->bundle() === $jurisdiction_type
        && $group->hasPermission('view unpublished group_node:service_request entity', $account)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks exactly the global exemptions, without a single-workspace fallback.
   */
  public function hasGlobalExemption(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || array_intersect(['administrator', 'editorial_board'], $account->getRoles()) !== []
      || $account->hasPermission('bypass node access');
  }

  /**
   * Requires staff rights and membership in this root, or elevation there.
   */
  public function canIntakeInWorkspace(AccountInterface $account, GroupInterface $root): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ($this->hasGlobalExemption($account)) {
      return TRUE;
    }
    if ($root->hasPermission('view unpublished group_node:service_request entity', $account)) {
      return TRUE;
    }
    $membership = $root->getMember($account);
    if (!$membership) {
      return FALSE;
    }
    return $account->hasPermission('access dashboard notifications')
      || $account->hasPermission('administer nodes')
      || $account->hasPermission('edit any service_request content');
  }

}
