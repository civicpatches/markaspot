<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceTier;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides the rules of the lifecycle model for reports.
 *
 * Applies only to a workspace whose root carries a valid lifecycle record,
 * and only on the self-service platform. Everything else, including every
 * workspace without a record, keeps today's rules. The platform switch plays
 * no part here: records are data, the switch only decides whether
 * provisioning writes new ones.
 *
 * A workspace that is not open:
 * - takes no new report from anyone but its own members acting through
 *   their own login session, and platform administrators (closed intake);
 * - publishes nothing new: a new report starts unpublished, and a publish
 *   attempt on any translation is undone (publication gate). The example
 *   reports provisioning seeds are exempt from both.
 *
 * A report belongs to every workspace of its jurisdiction set: the resolved
 * jurisdiction, every value of field_jurisdiction and the jurisdiction of its
 * category (the set ReviewBeforePublish builds, with the single root of a
 * one-root site). A closed workspace anywhere in that set closes the report.
 *
 * In a workspace with a record whose effective tier is Free, the Free limit
 * of published reports binds workspace staff too; only platform
 * administrators skip it. Paid workspaces keep today's rule.
 */
class WorkspaceLifecycleGate {

  /**
   * The machine code of a refused report.
   */
  public const CODE = 'workspace_not_open';

  /**
   * The message of a refused report, starting with the code.
   */
  public const MESSAGE = self::CODE . ': This map is not open for reports yet.';

  /**
   * Max-age in seconds of the public settings of a map that is not open.
   *
   * Lowers the cacheability max-age of the response (Drupal's own caches,
   * which the opening also clears through the cache tag). The HTTP
   * Cache-Control header follows system.performance and the UI keeps its own
   * settings cache; neither is bounded by this value.
   */
  public const NOT_OPEN_SETTINGS_MAX_AGE = 60;

  public function __construct(
    private readonly WorkspaceLifecycle $lifecycle,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RequestStack $requestStack,
    private readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
    private readonly ?ReviewBeforePublish $reviewBeforePublish = NULL,
  ) {}

  /**
   * Returns the workspace root under the new model, if the group is in one.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The root with a valid record on the self-service platform, else NULL.
   */
  public function recordWorkspace(?GroupInterface $group): ?GroupInterface {
    if (!$group instanceof GroupInterface || !WorkspaceTier::isSelfService($this->moduleHandler)) {
      return NULL;
    }
    $root = $group;
    $rootId = $this->hierarchyResolver?->getRootJurisdictionId((int) $group->id());
    if ($rootId !== NULL && $rootId !== (int) $group->id()) {
      $loaded = $this->entityTypeManager->getStorage('group')->load($rootId);
      if ($loaded instanceof GroupInterface) {
        $root = $loaded;
      }
    }
    return $this->lifecycle->hasValidRecord($root) ? $root : NULL;
  }

  /**
   * Whether the group belongs to a workspace that is not open yet.
   */
  public function isClosed(?GroupInterface $group): bool {
    $workspace = $this->recordWorkspace($group);
    return $workspace !== NULL && $this->lifecycle->isNotOpen($workspace);
  }

  /**
   * The open state for the public settings response.
   *
   * @return bool|null
   *   TRUE or FALSE for a workspace under the new model, NULL for every
   *   other workspace (the key is then left out).
   */
  public function openState(?GroupInterface $group): ?bool {
    $workspace = $this->recordWorkspace($group);
    return $workspace === NULL ? NULL : $this->lifecycle->isOpen($workspace);
  }

  /**
   * Applies both rules on the save of a report (hook_node_presave()).
   *
   * Refuses a new report of an account that may not create one there; keeps
   * a new report, a publish attempt on any translation and a published
   * report moved into a closed workspace unpublished. Cheap checks first:
   * the jurisdiction is resolved only for new reports and publish attempts
   * on the self-service platform.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report being saved.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param callable(): ?\Drupal\group\Entity\GroupInterface $resolveGroup
   *   Resolves the report's jurisdiction.
   * @param bool $seeding
   *   TRUE while provisioning seeds its example reports.
   *
   * @throws \Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException
   *   For a refused new report.
   */
  public function enforceOnSave(NodeInterface $node, AccountInterface $account, callable $resolveGroup, bool $seeding): void {
    if (!$this->applies($node)) {
      return;
    }

    if ($node->isNew()) {
      $candidates = $this->candidates($node, $resolveGroup());
      foreach ($candidates as $group) {
        if ($this->refusesNewReport($node, $group, $account, $seeding)) {
          throw new WorkspaceNotOpenException();
        }
      }
      if (!$seeding && $this->anyClosed($candidates)) {
        $this->unpublish($this->publishedTranslations($node));
      }
      return;
    }

    // Plain edits resolve nothing: no translation becomes published and the
    // report joins no new workspace while published.
    $original = $this->original($node);
    $attempts = $this->publishAttempts($node, $original);
    $published = $this->publishedTranslations($node);
    $added = $original instanceof NodeInterface
      ? array_diff($this->jurisdictionIds($node), $this->jurisdictionIds($original))
      : [];
    if ($attempts === [] && ($added === [] || $published === [])) {
      return;
    }

    if ($attempts !== [] && $this->anyClosed($this->candidates($node, $resolveGroup()))) {
      $this->unpublish($attempts);
    }
    // A published report that now also belongs to a closed workspace is a
    // publish attempt there, whatever the order of the values.
    if ($added !== [] && $this->anyClosed($this->loadGroups($added))) {
      $this->unpublish($this->publishedTranslations($node));
    }
  }

  /**
   * Refuses a new report once markaspot_group has derived its jurisdiction.
   *
   * Called from hook_node_insert(), ordered after markaspot_group: a report
   * whose workspace is known only now (a boundary hit, a single root) is
   * checked again. The exception rolls the save back, so nothing is stored.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report just inserted.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param bool $seeding
   *   TRUE while provisioning seeds its example reports.
   *
   * @throws \Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException
   *   For a report in a closed workspace by an account that may not create
   *   one there.
   */
  public function refuseAfterInsert(NodeInterface $node, AccountInterface $account, bool $seeding): void {
    if ($seeding || !$this->applies($node)) {
      return;
    }
    foreach ($this->candidates($node, NULL) as $group) {
      if ($this->refusesIn($group, $account)) {
        throw new WorkspaceNotOpenException();
      }
    }
  }

  /**
   * Whether a new report in a closed workspace is refused.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report being validated or saved.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The jurisdiction the report resolves to.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account creating it (the current user).
   * @param bool $seeding
   *   TRUE while provisioning seeds its example reports.
   */
  public function refusesNewReport(NodeInterface $node, ?GroupInterface $group, AccountInterface $account, bool $seeding = FALSE): bool {
    if ($node->bundle() !== 'service_request' || !$node->isNew() || $seeding || $this->isExemptSave($node)) {
      return FALSE;
    }
    return $this->refusesIn($group, $account);
  }

  /**
   * Whether an account may create reports in a closed workspace.
   *
   * Members holding a role in the workspace through their own membership,
   * and platform administrators, each acting through their own login
   * session. Never the API key owner, and never a request authenticated by
   * anything but the login session.
   *
   * The discriminator is the uid in the server-side session: the cookie
   * provider authenticates from it, and only a login writes it. The API key
   * provider sets the current user from a header without touching the
   * session, so a key request has no session uid, or that of another
   * account. A client holds only the opaque session id and cannot place a
   * uid in the session by any header.
   */
  public function mayCreateInClosedWorkspace(GroupInterface $workspace, AccountInterface $account): bool {
    if (!$this->actsAsOwnAccount($account)) {
      return FALSE;
    }
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }
    return $this->holdsRoleIn($workspace, $account);
  }

  /**
   * Whether an account is the workspace's own team.
   *
   * Holds a role in the workspace through its own membership, whatever the
   * authentication. Never the API key owner, which is a member of every
   * workspace and stands for the public on Open311, and never a platform
   * administrator without a role there. Used for the operator mail only;
   * the closed intake keeps the session condition of
   * mayCreateInClosedWorkspace().
   */
  public function isWorkspaceTeam(GroupInterface $workspace, AccountInterface $account): bool {
    if ($account->isAnonymous()
      || in_array('api_user', $account->getRoles(), TRUE)
      || $account->getAccountName() === 'api_user') {
      return FALSE;
    }
    return $this->holdsRoleIn($workspace, $account);
  }

  /**
   * Whether a logged-in account other than the API key owner acts as itself.
   */
  private function actsAsOwnAccount(AccountInterface $account): bool {
    if ($account->isAnonymous() || in_array('api_user', $account->getRoles(), TRUE)) {
      return FALSE;
    }
    return $this->actsThroughOwnSession($account);
  }

  /**
   * Whether an account holds a role in the workspace through its membership.
   */
  private function holdsRoleIn(GroupInterface $workspace, AccountInterface $account): bool {
    /** @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface $roles */
    $roles = $this->entityTypeManager->getStorage('group_role');
    return $roles->loadByUserAndGroup($account, $workspace, FALSE) !== [];
  }

  /**
   * Whether an account may publish above the workspace's tier limit.
   *
   * The one decision behind the presave path of the module and the TierLimit
   * constraint. 'administer nodes' (platform administrators) always skips the
   * limit. The global 'bypass mas validation' of workspace staff skips it
   * only in workspaces the account belongs to, and never in a workspace with
   * a lifecycle record whose effective tier is Free: there the Free limit
   * binds the operator too. A paid workspace with a record is treated like a
   * paid workspace without one.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The report's resolved jurisdiction group, if any.
   */
  public function accountBypassesTierLimit(AccountInterface $account, ?GroupInterface $group): bool {
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }
    if (!$account->hasPermission('bypass mas validation')) {
      return FALSE;
    }
    if (!$group instanceof GroupInterface) {
      return TRUE;
    }
    $workspace = $this->recordWorkspace($group);
    if ($workspace !== NULL && WorkspaceTier::resolve($workspace, TRUE) === 'free') {
      return FALSE;
    }
    // Without the group module file (unit tests) staff never bypass.
    return function_exists('_markaspot_group_user_is_member_in_jurisdiction_scope')
      && _markaspot_group_user_is_member_in_jurisdiction_scope($account, (int) $group->id());
  }

  /**
   * Whether the group belongs to a workspace with a valid record.
   *
   * Such a workspace never expires: the expiry reminder and the teardown of
   * demo workspaces leave it alone, and a billing update may not give it an
   * expiry or take its tier away.
   */
  public function isRecordWorkspace(?GroupInterface $group): bool {
    return $this->recordWorkspace($group) !== NULL;
  }

  /**
   * Whether a new report in the group's workspace is refused for an account.
   */
  private function refusesIn(?GroupInterface $group, AccountInterface $account): bool {
    $workspace = $this->recordWorkspace($group);
    if ($workspace === NULL || !$this->lifecycle->isNotOpen($workspace)) {
      return FALSE;
    }
    return !$this->mayCreateInClosedWorkspace($workspace, $account);
  }

  /**
   * Whether the rules can apply to this save at all.
   */
  private function applies(NodeInterface $node): bool {
    return $node->bundle() === 'service_request'
      && !$this->isExemptSave($node)
      && WorkspaceTier::isSelfService($this->moduleHandler);
  }

  /**
   * The groups a report belongs to: the resolved one and its set.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Jurisdiction groups, without duplicates.
   */
  private function candidates(NodeInterface $node, ?GroupInterface $resolved): array {
    $groups = $this->loadGroups($this->jurisdictionIds($node));
    // The resolver prefers a boundary hit from any workspace tree. It counts
    // only when the report names no jurisdiction at all, or when it lies in
    // the tree of one the report names: a boundary of a foreign workspace
    // never closes a report meant for another map. Where the report finally
    // lands markaspot_group decides on insert, and the insert recheck covers
    // that.
    if ($resolved instanceof GroupInterface && ($groups === [] || $this->sharesRoot($resolved, $groups))) {
      $groups[(int) $resolved->id()] = $resolved;
    }
    return $groups;
  }

  /**
   * Whether a group lies in the workspace tree of one of the given groups.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The groups of the report's jurisdiction set.
   */
  private function sharesRoot(GroupInterface $group, array $groups): bool {
    $root = $this->rootId($group);
    foreach ($groups as $member) {
      if ($this->rootId($member) === $root) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The root jurisdiction id of a group (its own id without a resolver).
   */
  private function rootId(GroupInterface $group): int {
    return (int) ($this->hierarchyResolver?->getRootJurisdictionId((int) $group->id()) ?? $group->id());
  }

  /**
   * The jurisdiction set of a report.
   *
   * Every value of field_jurisdiction, the jurisdiction of the category and
   * the single root of a one-root site, as ReviewBeforePublish builds it.
   * Without that service (unit tests) the values of field_jurisdiction.
   *
   * A jurisdiction belongs to the report, not to a translation: the set of
   * the default translation counts for every translation.
   *
   * @return int[]
   *   Group IDs.
   */
  private function jurisdictionIds(NodeInterface $node): array {
    $ids = $this->ownJurisdictionIds($node);
    $untranslated = $node->getUntranslated();
    if ($untranslated instanceof NodeInterface && $untranslated !== $node) {
      $ids = array_values(array_unique(array_merge($ids, $this->ownJurisdictionIds($untranslated))));
    }
    return $ids;
  }

  /**
   * The jurisdiction set of one translation of a report.
   *
   * @return int[]
   *   Group IDs.
   */
  private function ownJurisdictionIds(NodeInterface $node): array {
    if ($this->reviewBeforePublish !== NULL) {
      return $this->reviewBeforePublish->jurisdictionIds($node);
    }
    $field = $node->hasField('field_jurisdiction') ? $node->get('field_jurisdiction') : NULL;
    if (!$field instanceof EntityReferenceFieldItemListInterface) {
      return [];
    }
    $ids = [];
    foreach ($field->referencedEntities() as $group) {
      $ids[] = (int) $group->id();
    }
    return array_values(array_unique($ids));
  }

  /**
   * Loads jurisdiction groups by ID.
   *
   * @param int[] $ids
   *   Group IDs.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups, keyed by ID.
   */
  private function loadGroups(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $groups = [];
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple($ids) as $group) {
      if ($group instanceof GroupInterface) {
        $groups[(int) $group->id()] = $group;
      }
    }
    return $groups;
  }

  /**
   * Whether any of the groups belongs to a closed workspace.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The groups.
   */
  private function anyClosed(array $groups): bool {
    foreach ($groups as $group) {
      if ($this->isClosed($group)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The stored state of an existing report.
   */
  private function original(NodeInterface $node): ?NodeInterface {
    $original = $node->getOriginal()
      ?? $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    return $original instanceof NodeInterface ? $original : NULL;
  }

  /**
   * Every translation of a report, the active one included.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Translations keyed by language code.
   */
  private function translations(NodeInterface $node): array {
    $translations = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      if ($translation instanceof NodeInterface) {
        $translations[$langcode] = $translation;
      }
    }
    return $translations !== [] ? $translations : [$node->language()->getId() => $node];
  }

  /**
   * The translations of a report that are published in this save.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Translations keyed by language code.
   */
  private function publishedTranslations(NodeInterface $node): array {
    return array_filter($this->translations($node), static fn(NodeInterface $translation): bool => $translation->isPublished());
  }

  /**
   * The translations this save publishes that were not published before.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Translations keyed by language code.
   */
  private function publishAttempts(NodeInterface $node, ?NodeInterface $original): array {
    $attempts = [];
    foreach ($this->publishedTranslations($node) as $langcode => $translation) {
      $before = $original?->hasTranslation($langcode) ? $original->getTranslation($langcode) : NULL;
      if (!$before instanceof NodeInterface || !$before->isPublished()) {
        $attempts[$langcode] = $translation;
      }
    }
    return $attempts;
  }

  /**
   * Sets translations unpublished.
   *
   * @param \Drupal\node\NodeInterface[] $translations
   *   The translations.
   */
  private function unpublish(array $translations): void {
    foreach ($translations as $translation) {
      $translation->setUnpublished();
    }
  }

  /**
   * Whether the current request runs on the account's own login session.
   */
  private function actsThroughOwnSession(AccountInterface $account): bool {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      return FALSE;
    }
    $uid = $request->getSession()->get('uid');
    return is_numeric($uid) && (int) $uid > 0 && (int) $uid === (int) $account->id();
  }

  /**
   * Saves outside both rules: content synchronisation and database updates.
   */
  private function isExemptSave(NodeInterface $node): bool {
    return $node->isSyncing() || (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === 'update');
  }

}
