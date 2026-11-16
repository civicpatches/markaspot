<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceTier;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the operator of an open map that reports wait for approval.
 *
 * Applies only to a workspace root with a valid lifecycle record on the
 * self-service platform, open and not blocked. Every other workspace never
 * gets a marker and never a mail.
 *
 * The save of a held report only marks the workspace (noteHeldReport(),
 * called from hook_node_insert() after markaspot_nuxt recorded the hold): it
 * overwrites the workspace's marker with a fresh one, inside the save
 * transaction, so a failed save leaves none behind. The mail goes out after
 * the save has committed: at the end of the request (destruct()) and from the
 * hourly cron (sendDue()), both through sendIfDue().
 *
 * Something is new for a workspace while its marker differs from the marker
 * the last decision handled. Nobody deletes a marker: a decision records the
 * marker it read as handled, so a report marked during the decision keeps its
 * workspace due.
 *
 * Rate ("only when something new arrived"): the first waiting report sends
 * at once. After a mail, the next one goes out 24 hours later at the
 * earliest, and only if a new report was held since. Nothing new, no mail,
 * however many reports still wait. The stamp is written under a lock per
 * workspace after a fresh check of the last mail time, before sending, so two
 * requests or a request and cron never send twice. When no recipient got
 * the mail, the stamp is taken back and cron tries again.
 *
 * Per workspace root ID, three collections hold nothing but times: the
 * marker of the latest held report, the marker the last decision handled, and
 * the time of the last mail. No report, no account, no address.
 */
class WaitingReportsNotifier implements DestructableInterface {

  /**
   * The mail key.
   */
  public const MAIL_KEY = 'workspace_reports_waiting';

  /**
   * Marker of the latest held report per workspace: ID => microtime string.
   */
  public const PENDING_COLLECTION = 'markaspot_fastmap.lifecycle.reports_waiting';

  /**
   * Marker the last decision handled per workspace: ID => microtime string.
   */
  public const HANDLED_COLLECTION = 'markaspot_fastmap.lifecycle.reports_waiting_handled';

  /**
   * Time of the last mail per workspace: ID => timestamp.
   */
  public const MAILED_COLLECTION = 'markaspot_fastmap.lifecycle.reports_waiting_mailed';

  /**
   * Seconds between two mails of one workspace, at the least.
   */
  public const INTERVAL = 86400;

  /**
   * Outcomes of sendIfDue().
   */
  public const SENT = 'sent';
  public const NOTHING_NEW = 'nothing_new';
  public const TOO_SOON = 'too_soon';
  public const NOTHING_WAITING = 'nothing_waiting';
  public const NOT_APPLICABLE = 'not_applicable';
  public const NO_RECIPIENT = 'no_recipient';
  public const NO_LINK = 'no_link';
  public const BUSY = 'busy';
  public const FAILED = 'failed';

  /**
   * The lock per workspace.
   */
  private const LOCK_PREFIX = 'markaspot_fastmap.reports_waiting:';

  /**
   * Seconds one stamp may hold the lock (a few key/value calls).
   */
  private const LOCK_TIMEOUT = 30.0;

  /**
   * The dashboard path of the report list, below the workspace link.
   */
  private const DASHBOARD_PATH = '/dashboard/requests';

  /**
   * Workspace IDs noted in this request, sent at its end.
   *
   * @var array<int, true>
   */
  private array $noted = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceLifecycle $lifecycle,
    private readonly WorkspaceLifecycleGate $gate,
    private readonly ReviewBeforePublish $reviewBeforePublish,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Marks the open workspace a report just held for review belongs to.
   *
   * Called from hook_node_insert(), ordered after markaspot_nuxt, whose
   * insert hook records the hold (recordHold() or holdAfterInsert()), and
   * after markaspot_group, which has derived field_jurisdiction by then. Only
   * the workspaces of field_jurisdiction count, as in the dashboard count; a
   * category shared with another map marks nothing there. A report created by
   * an account that holds a role in the workspace (the operator and their
   * team) marks nothing. Never throws.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report just inserted.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   */
  public function noteHeldReport(NodeInterface $node, AccountInterface $account): void {
    try {
      if ($node->bundle() !== 'service_request'
        || $node->isSyncing()
        || !WorkspaceTier::isSelfService($this->moduleHandler)) {
        return;
      }
      $workspaces = $this->recordWorkspaces($node);
      if ($workspaces === [] || !$this->reviewBeforePublish->isHeld($node)) {
        return;
      }
      foreach ($workspaces as $gid => $workspace) {
        if (!$this->isOpenAndUnblocked($workspace) || $this->gate->isWorkspaceTeam($workspace, $account)) {
          continue;
        }
        $this->pending()->set((string) $gid, sprintf('%.6F', $this->time->getCurrentMicroTime()));
        $this->noted[$gid] = TRUE;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not note a waiting report for the operator mail: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Sends the mails of the workspaces noted in this request.
   *
   * Runs at the end of the request, after the response and after every save
   * of the request has committed.
   */
  public function destruct(): void {
    $this->flush();
  }

  /**
   * Sends the mails of the workspaces noted so far and forgets them.
   *
   * @return array<int, string>
   *   Outcome per workspace ID.
   */
  public function flush(?int $now = NULL): array {
    $noted = array_keys($this->noted);
    $this->noted = [];
    $outcomes = [];
    foreach ($noted as $gid) {
      $outcomes[$gid] = $this->sendIfDue($gid, $now);
    }
    return $outcomes;
  }

  /**
   * Sends the pending mails that are due (hourly cron).
   *
   * Reads the markers once and visits only workspaces whose marker differs
   * from the handled one, never every group.
   *
   * @return array<int, string>
   *   Outcome per workspace ID.
   */
  public function sendDue(?int $now = NULL): array {
    if (!WorkspaceTier::isSelfService($this->moduleHandler)) {
      return [];
    }
    $outcomes = [];
    foreach ($this->dueIds() as $gid) {
      $outcomes[$gid] = $this->sendIfDue($gid, $now);
    }
    return $outcomes;
  }

  /**
   * The workspace IDs whose marker differs from the handled one.
   *
   * @return int[]
   *   Workspace IDs.
   */
  public function dueIds(): array {
    $handled = $this->handled()->getAll();
    $due = [];
    foreach ($this->pending()->getAll() as $gid => $marker) {
      if ($marker !== ($handled[$gid] ?? NULL)) {
        $due[] = (int) $gid;
      }
    }
    return $due;
  }

  /**
   * Decides, stamps and sends the mail of one workspace.
   *
   * Reads the marker and the handled marker first: nothing new, nothing to
   * do. A workspace that lost its record, is closed again or blocked, and a
   * workspace where nothing waits any more, get the marker read here as
   * handled. Within 24 hours of the last mail the workspace stays due,
   * without counting. Without a recipient or a link nothing is sent and it
   * stays due. Then, under the lock, the last mail time is read again, and
   * the stamp and the handled marker are written; the mails go out after the
   * lock. If no recipient got the mail, the stamp and the handled marker are
   * taken back. Never throws.
   *
   * @param int $gid
   *   The workspace root ID.
   * @param int|null $now
   *   The time to decide at; the current time by default.
   *
   * @return string
   *   One of the outcome constants.
   */
  public function sendIfDue(int $gid, ?int $now = NULL): string {
    $key = (string) $gid;
    try {
      $marker = $this->pending()->get($key);
      $handledBefore = $this->handled()->get($key);
      if ($marker === NULL || $marker === $handledBefore) {
        return self::NOTHING_NEW;
      }
      $workspace = $this->entityTypeManager->getStorage('group')->loadUnchanged($gid);
      if (!$workspace instanceof GroupInterface || !$this->applies($workspace)) {
        $this->settle($key, $handledBefore, $marker);
        return self::NOT_APPLICABLE;
      }
      if ($this->tooSoon($key, $now)) {
        return self::TOO_SOON;
      }
      $count = $this->countWaiting($gid);
      if ($count === 0) {
        $this->settle($key, $handledBefore, $marker);
        return self::NOTHING_WAITING;
      }
      $recipients = $this->recipients($workspace);
      if ($recipients === []) {
        $this->logger->warning('No operator mail about waiting reports for workspace @id: no active tenant admin with an e-mail address.', [
          '@id' => $key,
        ]);
        return self::NO_RECIPIENT;
      }
      $link = $this->dashboardUrl($workspace);
      if ($link === NULL) {
        $this->logger->error('No operator mail about waiting reports for workspace @id: the workspace has no slug or markaspot_fastmap.settings:workspace_base_url is empty.', [
          '@id' => $key,
        ]);
        return self::NO_LINK;
      }

      // Stamp before sending, under the lock and on a fresh read: no second
      // request and no cron run sends again for this marker.
      $lockName = self::LOCK_PREFIX . $gid;
      if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
        return self::BUSY;
      }
      try {
        if ($this->tooSoon($key, $now)) {
          return self::TOO_SOON;
        }
        $mailedBefore = $this->mailed()->get($key);
        $handledBefore = $this->handled()->get($key);
        $this->mailed()->set($key, $now ?? $this->time->getCurrentTime());
        $this->handled()->set($key, $marker);
      }
      finally {
        $this->lock->release($lockName);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Operator mail about waiting reports for workspace @id failed before sending: @message', [
        '@id' => $key,
        '@message' => $e->getMessage(),
      ]);
      return self::FAILED;
    }

    $params = [
      'workspace_name' => (string) $workspace->label(),
      'count' => (string) $count,
      'dashboard_url' => $link,
    ];
    $delivered = 0;
    foreach ($recipients as $user) {
      $delivered += $this->sendOne($workspace, $user, $params) ? 1 : 0;
    }
    if ($delivered > 0) {
      return self::SENT;
    }
    $this->restore($key, $mailedBefore, $handledBefore);
    return self::FAILED;
  }

  /**
   * Drops the state of a deleted workspace (hook_group_delete()).
   *
   * Also outside the self-service platform, so a mode switch leaves nothing
   * behind; three deletes on a rare event.
   */
  public function onWorkspaceDelete(GroupInterface $group): void {
    try {
      $this->forget((int) $group->id());
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not drop the operator mail state of deleted workspace @id: @message', [
        '@id' => (string) $group->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Removes the per-workspace state.
   */
  public function forget(int $gid): void {
    $this->pending()->delete((string) $gid);
    $this->handled()->delete((string) $gid);
    $this->mailed()->delete((string) $gid);
    unset($this->noted[$gid]);
  }

  /**
   * The accounts the mail goes to.
   *
   * Active accounts holding the tenant admin role of this workspace, with a
   * valid e-mail address; never the API key owner.
   *
   * @return \Drupal\user\UserInterface[]
   *   Accounts keyed by user ID.
   */
  public function recipients(GroupInterface $workspace): array {
    $uids = [];
    foreach (GroupMembership::loadByGroup($workspace, [$workspace->bundle() . '-tenant_admin']) as $membership) {
      $uid = (int) $membership->getEntityId();
      if ($uid > 0) {
        $uids[$uid] = $uid;
      }
    }
    if ($uids === []) {
      return [];
    }
    // Fresh from storage: an account blocked a moment ago, also in another
    // process, gets nothing.
    $storage = $this->entityTypeManager->getStorage('user');
    $storage->resetCache(array_values($uids));
    $recipients = [];
    foreach ($storage->loadMultiple(array_values($uids)) as $user) {
      if (!$user instanceof UserInterface
        || !$user->isActive()
        || $user->getAccountName() === 'api_user'
        || $user->hasRole('api_user')) {
        continue;
      }
      $mail = trim((string) $user->getEmail());
      if ($mail === '' || !OperatorDetails::isEmail($mail)) {
        continue;
      }
      $recipients[(int) $user->id()] = $user;
    }
    return $recipients;
  }

  /**
   * Counts the waiting reports as the dashboard counts them.
   *
   * Held and still unpublished, in the workspace and its descendants
   * (TenantSettingsController::countReviewBeforePublishPending()).
   */
  public function countWaiting(int $gid): int {
    return $this->reviewBeforePublish->countHeld($this->hierarchyResolver->getDescendantIds($gid));
  }

  /**
   * Whether the last mail of a workspace is less than 24 hours old.
   */
  private function tooSoon(string $key, ?int $now): bool {
    $last = $this->mailed()->get($key);
    return is_numeric($last) && ($now ?? $this->time->getCurrentTime()) - (int) $last < self::INTERVAL;
  }

  /**
   * Records a marker as handled, unless another decision moved on meanwhile.
   *
   * @param string $key
   *   The workspace ID.
   * @param mixed $handledBefore
   *   The handled marker read at the start of the decision.
   * @param mixed $marker
   *   The marker read at the start of the decision.
   */
  private function settle(string $key, mixed $handledBefore, mixed $marker): void {
    $lockName = self::LOCK_PREFIX . $key;
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      // Another decision runs; this marker stays due and is decided again.
      return;
    }
    try {
      if ($this->handled()->get($key) === $handledBefore) {
        $this->handled()->set($key, $marker);
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Takes back a stamp after a mail no recipient got.
   *
   * @param string $key
   *   The workspace ID.
   * @param mixed $mailedBefore
   *   The last mail time before the stamp, NULL for none.
   * @param mixed $handledBefore
   *   The handled marker before the stamp, NULL for none.
   */
  private function restore(string $key, mixed $mailedBefore, mixed $handledBefore): void {
    try {
      $mailedBefore === NULL ? $this->mailed()->delete($key) : $this->mailed()->set($key, $mailedBefore);
      $handledBefore === NULL ? $this->handled()->delete($key) : $this->handled()->set($key, $handledBefore);
      $this->logger->error('No recipient got the operator mail about waiting reports for workspace @id; it is tried again by cron.', [
        '@id' => $key,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not take back the operator mail stamp of workspace @id: @message', [
        '@id' => $key,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Whether a workspace root takes part in the mail.
   */
  private function applies(GroupInterface $workspace): bool {
    $root = $this->gate->recordWorkspace($workspace);
    return $root !== NULL
      && (int) $root->id() === (int) $workspace->id()
      && $this->isOpenAndUnblocked($workspace);
  }

  /**
   * Whether a record workspace is open and not blocked.
   */
  private function isOpenAndUnblocked(GroupInterface $workspace): bool {
    return $this->lifecycle->isOpen($workspace) && !WorkspaceOpening::isBlocked($workspace);
  }

  /**
   * The record workspaces of a report's field_jurisdiction, keyed by root ID.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Workspace roots with a valid record.
   */
  private function recordWorkspaces(NodeInterface $node): array {
    $field = $node->hasField('field_jurisdiction') ? $node->get('field_jurisdiction') : NULL;
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return [];
    }
    $workspaces = [];
    foreach ($field->referencedEntities() as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }
      $workspace = $this->gate->recordWorkspace($group);
      if ($workspace !== NULL) {
        $workspaces[(int) $workspace->id()] = $workspace;
      }
    }
    return $workspaces;
  }

  /**
   * The dashboard report list of a workspace, or NULL without a link.
   *
   * Built like the welcome mail builds its links
   * (_markaspot_fastmap_build_workspace_url() from workspace_base_url).
   */
  private function dashboardUrl(GroupInterface $workspace): ?string {
    $slug = $workspace->hasField('field_slug') && !$workspace->get('field_slug')->isEmpty()
      ? trim((string) $workspace->get('field_slug')->value)
      : '';
    $base = (string) ($this->configFactory->get('markaspot_fastmap.settings')->get('workspace_base_url') ?? '');
    if (!function_exists('_markaspot_fastmap_build_workspace_url')) {
      return NULL;
    }
    $url = _markaspot_fastmap_build_workspace_url($slug, $base);
    return $url !== NULL ? $url . self::DASHBOARD_PATH : NULL;
  }

  /**
   * Sends the mail to one account in its preferred language.
   *
   * @param \Drupal\group\Entity\GroupInterface $workspace
   *   The workspace root.
   * @param \Drupal\user\UserInterface $user
   *   The recipient.
   * @param array<string, string> $params
   *   Mail parameters.
   *
   * @return bool
   *   Whether the mail manager reported success.
   */
  private function sendOne(GroupInterface $workspace, UserInterface $user, array $params): bool {
    try {
      $result = $this->mailManager->mail(
        'markaspot_fastmap',
        self::MAIL_KEY,
        (string) $user->getEmail(),
        $user->getPreferredLangcode(),
        $params,
        $this->mailFrom(),
      );
      if (empty($result['result'])) {
        $this->logger->error('The operator mail about waiting reports for workspace @id was not sent to account @uid.', [
          '@id' => (string) $workspace->id(),
          '@uid' => (string) $user->id(),
        ]);
        return FALSE;
      }
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('The operator mail about waiting reports for workspace @id failed for account @uid: @message', [
        '@id' => (string) $workspace->id(),
        '@uid' => (string) $user->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * The configured reply address of the module's mails, or NULL.
   */
  private function mailFrom(): ?string {
    $from = (string) ($this->configFactory->get('markaspot_fastmap.settings')->get('mail_from') ?? '');
    return $from !== '' ? $from : NULL;
  }

  /**
   * Markers per workspace.
   */
  private function pending(): KeyValueStoreInterface {
    return $this->keyValue->get(self::PENDING_COLLECTION);
  }

  /**
   * Handled markers per workspace.
   */
  private function handled(): KeyValueStoreInterface {
    return $this->keyValue->get(self::HANDLED_COLLECTION);
  }

  /**
   * Mail times per workspace.
   */
  private function mailed(): KeyValueStoreInterface {
    return $this->keyValue->get(self::MAILED_COLLECTION);
  }

}
