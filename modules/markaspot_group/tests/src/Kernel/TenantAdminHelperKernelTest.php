<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/src/Service/TenantAdminHelper.php';

/**
 * Pins the distinct membership, publisher and tenant administrator scopes.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class TenantAdminHelperKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'entity',
    'flexible_permissions', 'group',
  ];

  /**
   * Consolidation must preserve the three different authorization scopes.
   */
  public function testMembershipRoleScopes(): void {
    foreach (['user', 'group', 'group_relationship', 'group_config_wrapper'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installConfig(['system', 'user', 'group']);
    $type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction']);
    $type->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$storage->load('jur-group_membership')) {
      $storage->createFromPlugin($type, 'group_membership')->save();
    }
    $user = User::create(['name' => 'Scoped staff', 'status' => 1]);
    $user->save();
    $groups = [];
    foreach (['member', 'moderator', 'tenant_admin'] as $roleName) {
      $roleId = 'jur-' . $roleName;
      GroupRole::create([
        'id' => $roleId, 'label' => $roleName,
        'group_type' => 'jur', 'scope' => 'individual',
      ])->save();
      $group = Group::create(['type' => 'jur', 'label' => $roleName]);
      $group->save();
      $group->addMember($user, ['group_roles' => [$roleId]]);
      $groups[$roleName] = (int) $group->id();
    }
    $this->assertEqualsCanonicalizing(array_values($groups), TenantAdminHelper::getUserMemberJurisdictionIds($user));
    $this->assertEqualsCanonicalizing([$groups['moderator'], $groups['tenant_admin']], TenantAdminHelper::getUserPublishCapableJurisdictionIds($user));
    $this->assertSame([$groups['tenant_admin']], TenantAdminHelper::getUserJurisdictionIds($user));
    $this->assertTrue(TenantAdminHelper::userHasJurAdminInAnyGroup($user));
    $this->assertSame([], TenantAdminHelper::getUserMemberJurisdictionIds(User::create(['uid' => 0])));
  }

}
