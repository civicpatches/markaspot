<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flexible_permissions\CalculatedPermissionsInterface;
use Drupal\group\Access\GroupPermissionCalculatorInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports API key accounts that hold the group draft permission.
 *
 * For every account an API key maps to and that lacks the Open311 advanced
 * permission, the check lists each group holding service requests in which
 * the account holds 'view unpublished group_node:service_request entity'.
 * It evaluates the account's effective group permissions as Group calculates
 * them, across the individual, insider and outsider scopes and admin roles,
 * instead of comparing role names, so tenant-specific roles are judged by
 * what they grant. Naming the role that provides the permission is best
 * effort.
 *
 * The check only reads: it repairs nothing and invalidates no cache. It is
 * site-wide and names accounts and groups of every tenant.
 *
 * @HealthCheck(
 *   id = "key_account_unpublished_read",
 *   label = @Translation("API key account holds group draft permission"),
 *   severity = "warning",
 *   description = @Translation("Lists groups holding service requests in which an account behind an API key holds the group permission 'view unpublished group_node:service_request entity' without having 'access open311 advanced properties', with the providing role where one exists."),
 *   fix_hint = @Translation("Remove the permission from the named group role or remove the key account from the group. If the key is meant to hold it, give the key account 'access open311 advanced properties'."),
 * )
 */
final class KeyAccountUnpublishedReadCheck extends HealthCheckPluginBase {

  /**
   * The group permission the check looks for.
   *
   * The "view own unpublished" variant is deliberately not checked: a key
   * account does not author citizen reports.
   */
  public const DRAFT_PERMISSION = 'view unpublished group_node:service_request entity';

  /**
   * The global permission that exempts a key account from the check.
   */
  public const ADVANCED_PERMISSION = 'access open311 advanced properties';

  /**
   * The group relation plugin whose groups are checked.
   */
  private const RELATION_PLUGIN = 'group_node:service_request';

  /**
   * Maximum number of findings kept in the details payload.
   */
  private const DETAILS_LIMIT = 25;

  /**
   * Maximum number of findings spelled out in the message.
   */
  private const MESSAGE_LIMIT = 5;

  /**
   * Synchronized roles granting the permission, keyed by scope and type.
   *
   * @var array<string, \Drupal\group\Entity\GroupRoleInterface[]>
   */
  private array $synchronizedRoles = [];

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?GroupPermissionCalculatorInterface $permissionCalculator = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->has('group_permission.calculator') ? $container->get('group_permission.calculator') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if ($this->permissionCalculator === NULL) {
      return $this->pass('Group permission calculator not available; check skipped.');
    }
    foreach (['api_key', 'group', 'group_role', 'group_relationship', 'group_relationship_type'] as $entityTypeId) {
      if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
        return $this->pass(sprintf('Entity type %s not available; check skipped.', $entityTypeId));
      }
    }

    $groupTypeIds = $this->serviceRequestGroupTypeIds();
    if ($groupTypeIds === []) {
      return $this->pass('No group type holds service requests; check skipped.');
    }

    $keyAccounts = $this->keyAccounts();
    if ($keyAccounts === []) {
      return $this->pass('No API key maps to an account; check skipped.');
    }

    $this->synchronizedRoles = [];
    $checked = 0;
    $affectedPairs = 0;
    $findingCount = 0;
    $details = [];
    foreach ($keyAccounts as ['account' => $account, 'keys' => $keyIds]) {
      if ($account->hasPermission(self::ADVANCED_PERMISSION)) {
        continue;
      }
      $checked++;
      $label = sprintf('%s (%d), key %s', $account->getAccountName(), (int) $account->id(), implode(', ', $keyIds));
      foreach ($this->accountFindings($account, $label, $groupTypeIds, self::DETAILS_LIMIT - count($details)) as $finding) {
        $findingCount++;
        $affectedPairs += $finding['affected'];
        if (isset($finding['row'])) {
          $details[] = $finding['row'];
        }
      }
    }

    if ($findingCount === 0) {
      return $this->pass(sprintf(
        'No API key account without the advanced permission holds the group permission "%s" (%d account(s) checked).',
        self::DRAFT_PERMISSION,
        $checked,
      ));
    }

    $summaries = array_map(
      static fn(array $row): string => sprintf('%s in %s via %s', $row['account'], $row['group'], $row['roles']),
      array_slice($details, 0, self::MESSAGE_LIMIT),
    );
    $more = $findingCount - count($summaries);

    return $this->fail(
      $affectedPairs,
      sprintf(
        'In %d account-group pair(s), an API key account without the advanced permission holds the group permission "%s": %s%s.',
        $affectedPairs,
        self::DRAFT_PERMISSION,
        implode('; ', $summaries),
        $more > 0 ? sprintf('; and %d more finding(s)', $more) : '',
      ),
      $details,
      $findingCount - count($details),
    );
  }

  /**
   * Returns the group types that have service requests as group content.
   *
   * @return string[]
   *   Group type IDs.
   */
  private function serviceRequestGroupTypeIds(): array {
    $ids = [];
    $types = $this->entityTypeManager->getStorage('group_relationship_type')
      ->loadByProperties(['content_plugin' => self::RELATION_PLUGIN]);
    foreach ($types as $type) {
      $groupTypeId = $type->get('group_type');
      if (is_string($groupTypeId) && $groupTypeId !== '') {
        $ids[$groupTypeId] = $groupTypeId;
      }
    }
    return array_values($ids);
  }

  /**
   * Loads the accounts API keys authenticate as.
   *
   * @return array<int, array{account: \Drupal\user\UserInterface, keys: string[]}>
   *   Accounts with the IDs of the keys that map to them, keyed by user ID.
   */
  private function keyAccounts(): array {
    $keysByUuid = [];
    foreach ($this->entityTypeManager->getStorage('api_key')->loadMultiple() as $key) {
      $uuid = $key->get('user_uuid');
      if (is_string($uuid) && $uuid !== '') {
        $keysByUuid[$uuid][] = (string) $key->id();
      }
    }
    if ($keysByUuid === []) {
      return [];
    }

    $accounts = [];
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['uuid' => array_keys($keysByUuid)]);
    foreach ($users as $user) {
      if (!$user instanceof UserInterface || $user->isAnonymous()) {
        continue;
      }
      $keyIds = $keysByUuid[$user->uuid()] ?? [];
      sort($keyIds);
      $accounts[(int) $user->id()] = ['account' => $user, 'keys' => $keyIds];
    }
    ksort($accounts);
    return $accounts;
  }

  /**
   * Collects the findings of one key account from one permission snapshot.
   *
   * Mirrors how Group decides a permission in a group: the individual item
   * of that group applies with or without membership, then the insider item
   * of the group type for members or the outsider item for everyone else.
   *
   * @param \Drupal\user\UserInterface $account
   *   The key account.
   * @param string $label
   *   The account label used in findings.
   * @param string[] $groupTypeIds
   *   Group types that hold service requests.
   * @param int $rowBudget
   *   How many findings may still be formatted as detail rows.
   *
   * @return array<int, array{affected: int, row?: array<string, string>}>
   *   Findings, each with the number of groups it covers and, within the
   *   budget, its detail row.
   */
  private function accountFindings(UserInterface $account, string $label, array $groupTypeIds, int $rowBudget): array {
    $permissions = $this->permissionCalculator->calculateFullPermissions($account);

    // Memberships once per account. The relationship carries the group type,
    // so no group needs to be loaded to sort memberships by type.
    $memberships = [];
    $memberIdsByType = [];
    $relationships = $this->entityTypeManager->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $account->id(),
        'plugin_id' => 'group_membership',
      ]);
    foreach ($relationships as $relationship) {
      if (!$relationship instanceof GroupRelationshipInterface) {
        continue;
      }
      $gid = (int) $relationship->getGroupId();
      $memberships[$gid] = $relationship;
      $memberIdsByType[(string) $relationship->getGroupTypeId()][] = $gid;
    }

    // Group IDs with the permission, each with the scope that provides it.
    $hits = [];
    foreach ($this->itemsWithPermission($permissions, PermissionScopeInterface::INDIVIDUAL_ID) as $identifier) {
      $hits[(int) $identifier] = 'individual';
    }
    $individualOnly = array_diff_key($hits, $memberships);
    $individualTypes = $this->groupTypesOf(array_keys($individualOnly));
    foreach (array_keys($hits) as $gid) {
      $type = isset($memberships[$gid]) ? (string) $memberships[$gid]->getGroupTypeId() : ($individualTypes[$gid] ?? NULL);
      if ($type === NULL || !in_array($type, $groupTypeIds, TRUE)) {
        unset($hits[$gid]);
      }
    }
    $insiderTypes = array_intersect($this->itemsWithPermission($permissions, PermissionScopeInterface::INSIDER_ID), $groupTypeIds);
    foreach ($insiderTypes as $type) {
      foreach ($memberIdsByType[$type] ?? [] as $gid) {
        $hits[$gid] ??= 'insider';
      }
    }
    ksort($hits);

    $findings = [];
    $rowGroupIds = array_slice(array_keys($hits), 0, max(0, $rowBudget));
    $groups = $rowGroupIds === [] ? [] : $this->entityTypeManager->getStorage('group')->loadMultiple($rowGroupIds);
    foreach (array_keys($hits) as $gid) {
      $finding = ['affected' => 1];
      if (isset($groups[$gid])) {
        $group = $groups[$gid];
        $type = $group->bundle();
        $roles = [];
        if (isset($memberships[$gid])) {
          $roles = $memberships[$gid]->get('group_roles')->referencedEntities();
          if (in_array($type, $insiderTypes, TRUE)) {
            $roles = array_merge($roles, $this->synchronizedRolesFor($account, $type, PermissionScopeInterface::INSIDER_ID));
          }
        }
        $finding['row'] = [
          'account' => $label,
          'group' => sprintf('%s (%d)', $group->label(), $gid),
          'group_type' => $type,
          'scope' => isset($memberships[$gid]) ? 'member' : 'individual without membership',
          'roles' => $this->describeRoles($roles),
        ];
      }
      $findings[] = $finding;
    }

    // An outsider item covers every group of its type the account is not a
    // member of and has no individual item for: one finding per type.
    $outsiderTypes = array_intersect($this->itemsWithPermission($permissions, PermissionScopeInterface::OUTSIDER_ID), $groupTypeIds);
    sort($outsiderTypes);
    foreach ($outsiderTypes as $type) {
      $excluded = array_merge($memberIdsByType[$type] ?? [], array_keys($hits));
      $query = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $type);
      if ($excluded !== []) {
        $query->condition('id', $excluded, 'NOT IN');
      }
      $count = (int) $query->count()->execute();
      if ($count === 0) {
        continue;
      }
      $finding = ['affected' => $count];
      if (count($findings) < $rowBudget) {
        $finding['row'] = [
          'account' => $label,
          'group' => sprintf('%d %s group(s) without membership not listed individually', $count, $type),
          'group_type' => $type,
          'scope' => 'outsider',
          'roles' => $this->describeRoles($this->synchronizedRolesFor($account, $type, PermissionScopeInterface::OUTSIDER_ID)),
        ];
      }
      $findings[] = $finding;
    }

    return $findings;
  }

  /**
   * Returns the identifiers of a scope's items that carry the permission.
   *
   * @return string[]
   *   Group IDs for the individual scope, group type IDs otherwise.
   */
  private function itemsWithPermission(CalculatedPermissionsInterface $permissions, string $scope): array {
    $identifiers = [];
    foreach ($permissions->getItemsByScope($scope) as $item) {
      if ($item->hasPermission(self::DRAFT_PERMISSION)) {
        $identifiers[] = (string) $item->getIdentifier();
      }
    }
    return $identifiers;
  }

  /**
   * Looks up the group types of groups the account is not a member of.
   *
   * @param int[] $groupIds
   *   Group IDs.
   *
   * @return array<int, string>
   *   Group type IDs keyed by group ID; missing groups are left out.
   */
  private function groupTypesOf(array $groupIds): array {
    if ($groupIds === []) {
      return [];
    }
    $types = [];
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple($groupIds) as $group) {
      $types[(int) $group->id()] = $group->bundle();
    }
    return $types;
  }

  /**
   * Loads synchronized group roles of a scope that grant the permission.
   *
   * @return \Drupal\group\Entity\GroupRoleInterface[]
   *   Matching group roles.
   */
  private function synchronizedRolesFor(UserInterface $account, string $groupTypeId, string $scope): array {
    $key = $scope . ':' . $groupTypeId . ':' . implode(',', $account->getRoles());
    if (!isset($this->synchronizedRoles[$key])) {
      $roles = $this->entityTypeManager->getStorage('group_role')->loadByProperties([
        'group_type' => $groupTypeId,
        'scope' => $scope,
        'global_role' => $account->getRoles(),
      ]);
      $this->synchronizedRoles[$key] = array_values(array_filter(
        $roles,
        fn($role): bool => $role instanceof GroupRoleInterface && $this->grantsPermission($role),
      ));
    }
    return $this->synchronizedRoles[$key];
  }

  /**
   * Checks whether a group role grants the permission.
   */
  private function grantsPermission(GroupRoleInterface $role): bool {
    return $role->isAdmin() || in_array(self::DRAFT_PERMISSION, $role->getPermissions(), TRUE);
  }

  /**
   * Describes the roles that provide the permission.
   *
   * @param array<int, mixed> $roles
   *   Candidate group roles.
   *
   * @return string
   *   Role IDs with scope, or a note that no group role explains it.
   */
  private function describeRoles(array $roles): string {
    $labels = [];
    foreach ($roles as $role) {
      if (!$role instanceof GroupRoleInterface || !$this->grantsPermission($role)) {
        continue;
      }
      $labels[(string) $role->id()] = sprintf(
        '%s (%s%s)',
        $role->id(),
        $role->getScope(),
        $role->isAdmin() ? ', admin' : '',
      );
    }
    if ($labels === []) {
      return 'no group role (granted by a permission calculator)';
    }
    ksort($labels);
    return implode(', ', $labels);
  }

}
