<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceTier;
use Psr\Log\LoggerInterface;

/**
 * Manages the opening and support closure of a public intake.
 *
 * Applies to a workspace that itself carries a valid lifecycle record, on the
 * self-service platform. Opening writes the opening section of the record
 * (time, account, prior group revision and optional operator snapshot),
 * saves a new revision and sends one mail to platform support. A support
 * closure suspends reopening until the hold is explicitly cleared.
 */
class WorkspaceOpening {

  /**
   * The mail key of the support mail.
   */
  public const MAIL_KEY = 'workspace_opened_support';
  public const OPERATOR_MAIL_KEY = 'workspace_operator_changed_support';
  public const LIMIT_MAIL_KEY = 'workspace_auto_open_limited_support';

  private const AUTO_OPEN_EVENT = 'markaspot_fastmap.auto_open';
  private const AUTO_OPEN_IDENTIFIER = 'platform';
  private const DAY = 86400;

  /**
   * The revision log message of the opening. No personal data.
   */
  public const REVISION_LOG = 'Map opened for public reports.';

  /**
   * Outcomes of open().
   */
  public const OPENED = 'opened';
  public const ALREADY_OPEN = 'already_open';
  public const NOT_APPLICABLE = 'not_applicable';
  public const CONFIRMATION_REQUIRED = 'confirmation_required';
  public const BUSY = 'busy';
  public const BLOCKED = 'workspace_blocked';
  private const AUTO_OPEN_LIMITED = 'auto_open_limited';

  /**
   * Outcomes of resetOperator() besides NOT_APPLICABLE and BUSY.
   */
  public const NOTHING_TO_RESET = 'nothing_to_reset';
  public const WOULD_RESET = 'would_reset';
  public const RESET = 'reset';
  public const HOLD_CLEARED = 'hold_cleared';
  public const NO_HOLD = 'no_hold';

  /**
   * The revision log message of a support reset. No personal data.
   */
  public const RESET_REVISION_LOG = 'Operator details removed by support; map closed for public reports.';

  /**
   * The generated legal notice, which repeats the operator's details.
   */
  public const LEGAL_NOTICE_FIELD = 'field_legal_notice';

  /**
   * Address properties a support reset clears. The country stays.
   */
  public const RESET_ADDRESS_PROPERTIES = [
    'organization',
    'given_name',
    'additional_name',
    'family_name',
    'address_line1',
    'address_line2',
    'address_line3',
    'postal_code',
    'locality',
    'dependent_locality',
    'administrative_area',
    'sorting_code',
  ];

  /**
   * The lock per workspace shared by opening and reset.
   */
  private const LOCK_PREFIX = 'markaspot_fastmap.workspace_open:';

  /**
   * Seconds one opening may hold the lock.
   */
  private const LOCK_TIMEOUT = 30.0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceLifecycle $lifecycle,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * Whether the group itself carries a valid record on this platform.
   *
   * A child jurisdiction of a record workspace does not: its admins cannot
   * open the parent's map.
   */
  public function isRecordWorkspace(GroupInterface $group): bool {
    return WorkspaceTier::isSelfService($this->moduleHandler)
      && $this->lifecycle->hasValidRecord($group);
  }

  /**
   * Opens the map.
   *
   * Under a lock per workspace and on the stored state: an open map answers
   * with its existing opening and no mail, so two requests never produce two
   * openings or two mails. The support mail goes out after the save; a
   * failed mail is logged and does not undo the opening.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The workspace (the route's jurisdiction).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account; the route has checked its access.
   * @param bool $confirmed
   *   Whether the administrator confirmed opening the map.
   *
   * @return array{status: string, group?: \Drupal\group\Entity\GroupInterface}
   *   The outcome; "group" is the stored workspace for OPENED and
   *   ALREADY_OPEN.
   */
  public function open(GroupInterface $group, AccountInterface $account, bool $confirmed): array {
    if (!$this->isRecordWorkspace($group)) {
      return ['status' => self::NOT_APPLICABLE];
    }
    if (!$confirmed) {
      return ['status' => self::CONFIRMATION_REQUIRED];
    }
    return $this->openWorkspace($group, $account, FALSE);
  }

  /**
   * Attempts automatic opening after a workspace-scoped session starts.
   *
   * The caller supplies the authenticated session account and the workspace
   * bound to the claimed signup token or verified login code.
   */
  public function openAfterLogin(int|string $workspace, AccountInterface $account): void {
    if ($account->isAnonymous() || $workspace === 0 || $workspace === '') {
      return;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('group');
      if (is_int($workspace)) {
        $group = $storage->load($workspace);
      }
      else {
        $matches = $storage->loadByProperties(['type' => 'jur', 'field_slug' => $workspace]);
        $group = count($matches) === 1 ? reset($matches) : NULL;
      }
      if ($group instanceof GroupInterface && $this->isRecordWorkspace($group)
        && ($this->lifecycle->read($group)['auto_open_pending'] ?? FALSE) === TRUE) {
        $this->openWorkspace($group, $account, TRUE);
      }
    }
    catch (\Throwable $e) {
      // Opening is a side effect of login. Keep the established session.
      $this->logger->error('Automatic opening of workspace @workspace failed: @message', [
        '@workspace' => $workspace,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Opens the stored workspace under the same lock for both entry points.
   *
   * @return array{status: string, group?: \Drupal\group\Entity\GroupInterface}
   *   The opening outcome.
   */
  private function openWorkspace(GroupInterface $group, AccountInterface $account, bool $automatic): array {

    $lockName = self::LOCK_PREFIX . $group->id();
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      $this->lock->wait($lockName, 15);
      if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
        return ['status' => self::BUSY];
      }
    }

    try {
      $stored = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      if (!$stored instanceof GroupInterface || !$this->isRecordWorkspace($stored)) {
        return ['status' => self::NOT_APPLICABLE];
      }
      // Support unblocks first; nobody opens a blocked or unpublished map.
      $record = $this->lifecycle->read($stored);
      if (self::isBlocked($stored) || WorkspaceLifecycle::hasSupportHold($record ?? [])) {
        return ['status' => self::BLOCKED];
      }
      if ($this->lifecycle->isOpen($stored)) {
        return ['status' => self::ALREADY_OPEN, 'group' => $stored];
      }
      $uid = (int) $account->id();
      if ($automatic) {
        if (($record['auto_open_pending'] ?? FALSE) !== TRUE || ($record['acceptance']['uid'] ?? NULL) !== $uid) {
          return ['status' => self::NOT_APPLICABLE];
        }
        /** @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface $roles */
        $roles = $this->entityTypeManager->getStorage('group_role');
        $roles->resetUserGroupRoleCache($account, $stored);
        if (!isset($roles->loadByUserAndGroup($account, $stored, FALSE)['jur-tenant_admin'])) {
          return ['status' => self::NOT_APPLICABLE];
        }
        $reserved = $this->reserveAutomaticOpening();
        if ($reserved === NULL) {
          return ['status' => self::BUSY];
        }
      }
      if ($automatic && !$reserved) {
        $this->lifecycle->requireManualOpening($stored);
        $stored->save();
        $opening = NULL;
      }
      else {
        $opening = $this->lifecycle->writeOpening($stored, $uid, OperatorDetails::snapshot($stored), (int) $stored->getRevisionId());
        self::setOpeningRevision($stored, $opening);
        $stored->save();
        $this->logger->notice('Workspace @id opened for public reports by account @uid.', [
          '@id' => (string) $stored->id(),
          '@uid' => (string) $uid,
        ]);
      }
    }
    finally {
      $this->lock->release($lockName);
    }

    if ($opening === NULL) {
      $this->sendSupport(self::LIMIT_MAIL_KEY, $stored, $this->workspaceMailParams($stored));
      return ['status' => self::AUTO_OPEN_LIMITED];
    }
    $this->sendSupportMail($stored, $opening, $account);
    return ['status' => self::OPENED, 'group' => $stored];
  }

  /**
   * Reserves a platform-wide slot before writing an automatic opening.
   *
   * The lock makes the flood check and registration atomic across workspaces.
   * NULL means the lock is busy, FALSE means the quota is exhausted.
   */
  private function reserveAutomaticOpening(): ?bool {
    if (!$this->lock->acquire(self::AUTO_OPEN_EVENT, self::LOCK_TIMEOUT)) {
      $this->lock->wait(self::AUTO_OPEN_EVENT, 15);
      if (!$this->lock->acquire(self::AUTO_OPEN_EVENT, self::LOCK_TIMEOUT)) {
        return NULL;
      }
    }
    try {
      $limit = (int) ($this->configFactory->get('markaspot_fastmap.settings')->get('auto_open_daily_limit') ?? 25);
      if ($limit <= 0 || !$this->flood->isAllowed(self::AUTO_OPEN_EVENT, $limit, self::DAY, self::AUTO_OPEN_IDENTIFIER)) {
        return FALSE;
      }
      $this->flood->register(self::AUTO_OPEN_EVENT, self::DAY, self::AUTO_OPEN_IDENTIFIER);
      return TRUE;
    }
    finally {
      $this->lock->release(self::AUTO_OPEN_EVENT);
    }
  }

  /**
   * Records the opening on the group revision about to be saved.
   */
  private static function setOpeningRevision(GroupInterface $group, array $opening): void {
    $group->setNewRevision(TRUE);
    $group->setRevisionUserId($opening['uid']);
    $group->setRevisionCreationTime($opening['opened_at']);
    $group->setRevisionLogMessage(self::REVISION_LOG);
  }

  /**
   * Preserves the stored lifecycle record on ordinary group saves.
   *
   * Only WorkspaceLifecycle may change the record. This also protects
   * openings and support resets against stale settings saves.
   */
  public function enforceOnSave(GroupInterface $group): void {
    // Consumed on every save, so a mark never outlives the save it was for.
    $ownWrite = $this->lifecycle->consumeOwnWrite($group);
    if ($group->isNew()
      || $group->isSyncing()
      || !$group->hasField(WorkspaceLifecycle::FIELD)
      || !WorkspaceTier::isSelfService($this->moduleHandler)) {
      return;
    }
    $original = $group->getOriginal()
      ?? $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
    if (!$original instanceof GroupInterface) {
      return;
    }

    // Only WorkspaceLifecycle writes the record. A save built from a stale
    // entity (loaded before an opening or a support reset) gets the stored
    // record back, open or not.
    $storedRecord = $original->get(WorkspaceLifecycle::FIELD)->value;
    if (!$ownWrite && $group->get(WorkspaceLifecycle::FIELD)->value !== $storedRecord) {
      $group->set(WorkspaceLifecycle::FIELD, $storedRecord);
      $this->logger->warning('A save of workspace @id tried to change the lifecycle record; the stored record was kept.', [
        '@id' => (string) $group->id(),
      ]);
    }
  }

  /**
   * Removes false operator data from a workspace and closes its map again.
   *
   * The support path when an operator named someone else: clears the
   * operator fields of the address (the country stays), the contact e-mail
   * and the legal notice in every translation (hand-edited ones included),
   * takes the opening back and saves one new revision, marked as syncing so
   * enforceOnSave() lets it through. The legal notice is not regenerated in
   * that save; it is generated again when the operator saves new details.
   * Older revisions keep their values. Reopening requires support to clear
   * the hold explicitly.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The workspace.
   * @param bool $apply
   *   FALSE for a dry run that only reports.
   *
   * @return array{status: string, parts?: string[], was_open?: bool}
   *   The outcome; "parts" names the fields that hold a value (no values).
   */
  public function resetOperator(GroupInterface $group, bool $apply): array {
    if (!$this->isRecordWorkspace($group)) {
      return ['status' => self::NOT_APPLICABLE];
    }
    $lockName = self::LOCK_PREFIX . $group->id();
    if ($apply && !$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return ['status' => self::BUSY];
    }
    try {
      $stored = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      if (!$stored instanceof GroupInterface || !$this->isRecordWorkspace($stored)) {
        return ['status' => self::NOT_APPLICABLE];
      }
      $address = $stored->hasField(OperatorDetails::ADDRESS_FIELD) && !$stored->get(OperatorDetails::ADDRESS_FIELD)->isEmpty()
        ? (array) $stored->get(OperatorDetails::ADDRESS_FIELD)->first()?->toArray()
        : [];
      $parts = [];
      foreach (self::RESET_ADDRESS_PROPERTIES as $property) {
        if (OperatorDetails::normalize($address[$property] ?? '') !== '') {
          $parts[] = $property;
        }
      }
      if ($stored->hasField(OperatorDetails::EMAIL_FIELD) && !$stored->get(OperatorDetails::EMAIL_FIELD)->isEmpty()) {
        $parts[] = 'email';
      }
      $noticeLanguages = [];
      if ($stored->hasField(self::LEGAL_NOTICE_FIELD)) {
        foreach (array_keys($stored->getTranslationLanguages()) as $langcode) {
          if (!$stored->getTranslation($langcode)->get(self::LEGAL_NOTICE_FIELD)->isEmpty()) {
            $noticeLanguages[] = $langcode;
          }
        }
      }
      if ($noticeLanguages !== []) {
        $parts[] = 'legal_notice';
      }
      $record = $this->lifecycle->read($stored);
      $wasOpen = ($record['opening'] ?? NULL) !== NULL;
      if ($parts === [] && !$wasOpen && WorkspaceLifecycle::hasSupportHold($record ?? [])) {
        return ['status' => self::NOTHING_TO_RESET, 'parts' => [], 'was_open' => FALSE];
      }
      if (!$apply) {
        return ['status' => self::WOULD_RESET, 'parts' => $parts, 'was_open' => $wasOpen];
      }

      $country = OperatorDetails::normalize($address['country_code'] ?? '');
      if ($stored->hasField(OperatorDetails::ADDRESS_FIELD)) {
        $stored->set(OperatorDetails::ADDRESS_FIELD, $country !== '' ? ['country_code' => $country] : NULL);
      }
      if ($stored->hasField(OperatorDetails::EMAIL_FIELD)) {
        $stored->set(OperatorDetails::EMAIL_FIELD, NULL);
      }
      foreach ($noticeLanguages as $langcode) {
        $stored->getTranslation($langcode)->set(self::LEGAL_NOTICE_FIELD, NULL);
      }
      $this->lifecycle->setClosedBySupport($stored, TRUE);
      $stored->setSyncing(TRUE);
      $stored->setNewRevision(TRUE);
      $stored->setRevisionUserId(0);
      $stored->setRevisionLogMessage(self::RESET_REVISION_LOG);
      // The legal notice stays empty: markaspot_fastmap_group_update() would
      // regenerate it from the emptied address in a second save. Its own
      // recursion guard (keyed by group ID) keeps it out of this save.
      $regenerating = &drupal_static(WorkspaceLifecycle::LEGAL_NOTICE_REGENERATING, []);
      $regenerating[(int) $stored->id()] = TRUE;
      try {
        $stored->save();
      }
      finally {
        unset($regenerating[(int) $stored->id()]);
        $stored->setSyncing(FALSE);
      }
      $this->logger->warning('Operator details of workspace @id removed by support; the map is closed again.', [
        '@id' => (string) $stored->id(),
      ]);
      return ['status' => self::RESET, 'parts' => $parts, 'was_open' => $wasOpen];
    }
    finally {
      if ($apply) {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Clears the hold without reopening the map. Called from Drush.
   */
  public function clearSupportHold(GroupInterface $group): string {
    if (!$this->isRecordWorkspace($group)) {
      return self::NOT_APPLICABLE;
    }
    $lockName = self::LOCK_PREFIX . $group->id();
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return self::BUSY;
    }
    try {
      $stored = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      if (!$stored instanceof GroupInterface || !$this->isRecordWorkspace($stored)) {
        return self::NOT_APPLICABLE;
      }
      if (!WorkspaceLifecycle::hasSupportHold($this->lifecycle->read($stored) ?? [])) {
        return self::NO_HOLD;
      }
      $this->lifecycle->setClosedBySupport($stored, FALSE);
      $stored->setNewRevision(TRUE);
      $stored->setRevisionUserId(0);
      $stored->setRevisionLogMessage('Support hold on opening lifted.');
      $stored->save();
      return self::HOLD_CLEARED;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Whether a workspace is blocked or unpublished.
   */
  public static function isBlocked(GroupInterface $group): bool {
    if (!$group->isPublished()) {
      return TRUE;
    }
    return $group->hasField('field_visibility')
      && strtolower(trim((string) $group->get('field_visibility')->value)) === 'blocked';
  }

  /**
   * Notifies support after complete operator details change on an open map.
   */
  public function notifyOperatorChange(GroupInterface $group, GroupInterface $original): void {
    if (!$this->isRecordWorkspace($group) || !$this->lifecycle->isOpen($group)) {
      return;
    }
    $details = $this->operatorMailParams($group, OperatorDetails::snapshot($group));
    if ($details === [] || $details === $this->operatorMailParams($original, OperatorDetails::snapshot($original))) {
      return;
    }
    $id = (string) $group->id();
    $event = 'markaspot_fastmap.operator_changed_support';
    $lockName = $event . ':' . $id;
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return;
    }
    try {
      if (!$this->flood->isAllowed($event, 1, self::DAY, $id)) {
        return;
      }
      $this->flood->register($event, self::DAY, $id);
    }
    finally {
      $this->lock->release($lockName);
    }
    $this->sendSupport(self::OPERATOR_MAIL_KEY, $group, $this->workspaceMailParams($group) + $details);
  }

  /**
   * Sends the opening notification and reports whether it was accepted.
   */
  public function sendSupportMail(GroupInterface $group, array $opening, AccountInterface $account): bool {
    return $this->sendSupport(self::MAIL_KEY, $group, $this->mailParams($group, $opening, $account));
  }

  /**
   * Sends a workspace notification to platform support.
   *
   * Only to markaspot_fastmap.settings:support_email; never to the site
   * mail, mail_from or an account. No report and no citizen data.
   */
  private function sendSupport(string $key, GroupInterface $group, array $params): bool {
    $to = $this->supportRecipient();
    if ($to === NULL) {
      $this->logger->error('No support mail for workspace @id: markaspot_fastmap.settings:support_email is empty or not a valid address.', [
        '@id' => (string) $group->id(),
      ]);
      return FALSE;
    }
    try {
      $langcode = $key !== self::MAIL_KEY && $group->language()->getId() === 'de' ? 'de' : 'en';
      $result = $this->mailManager->mail('markaspot_fastmap', $key, $to, $langcode, $params, $this->mailFrom());
    }
    catch (\Throwable $e) {
      $this->logger->error('The support mail for workspace @id failed: @message', [
        '@id' => (string) $group->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    if (empty($result['result'])) {
      $this->logger->error('The support mail for workspace @id was not sent.', [
        '@id' => (string) $group->id(),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * The support address, or NULL when it is empty or not one valid address.
   */
  public function supportRecipient(): ?string {
    $value = $this->configFactory->get('markaspot_fastmap.settings')->get('support_email');
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return OperatorDetails::isEmail($value) && preg_match('/[\s,;<>]/', $value) !== 1 ? $value : NULL;
  }

  /**
   * The opening mail parameters, with operator details when available.
   *
   * @return array<string, string>
   *   Mail parameters.
   */
  public function mailParams(GroupInterface $group, array $opening, AccountInterface $account): array {
    return $this->workspaceMailParams($group) + $this->operatorMailParams($group, $opening['operator']) + [
      'operator_name' => '(not provided)',
      'operator_address' => '(not provided)',
      'operator_email' => '(not provided)',
      'opened_by' => OperatorDetails::singleLine($account->getEmail()),
      'opened_at' => gmdate('Y-m-d H:i', $opening['opened_at']) . ' UTC',
    ];
  }

  /**
   * Workspace identity and link for support notifications.
   */
  private function workspaceMailParams(GroupInterface $group): array {
    $slug = $group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()
      ? trim((string) $group->get('field_slug')->value)
      : '';
    // Every value one line of visible text, the map name included.
    return array_map([OperatorDetails::class, 'singleLine'], [
      'workspace_name' => (string) $group->label(),
      'workspace_slug' => $slug,
      'workspace_id' => (string) $group->id(),
      'workspace_url' => $this->workspaceUrl($slug),
    ]);
  }

  /**
   * The complete operator details shown in the notice, in plain text.
   */
  private function operatorMailParams(GroupInterface $group, ?array $operator): array {
    if ($operator === NULL) {
      return [];
    }
    $name = $operator['organization'] ?? trim($operator['given_name'] . ' ' . $operator['family_name']);
    $line2 = OperatorDetails::singleLine($group->get(OperatorDetails::ADDRESS_FIELD)->first()?->getValue()['address_line2'] ?? '');
    $street = $operator['address_line1'] . ($line2 === '' ? '' : ', ' . $line2);
    return [
      'operator_name' => $name,
      'operator_address' => sprintf('%s, %s %s, %s', $street, $operator['postal_code'], $operator['locality'], $operator['country_code']),
      'operator_email' => $operator['email'],
    ];
  }

  /**
   * The public link of a map, as the module's other mails build it.
   *
   * Same rule as _markaspot_fastmap_build_workspace_url(): a {slug}
   * placeholder in workspace_base_url, else the slug appended.
   */
  private function workspaceUrl(string $slug): string {
    $base = (string) ($this->configFactory->get('markaspot_fastmap.settings')->get('workspace_base_url') ?? '');
    if ($slug === '' || $base === '') {
      return '(no public link configured)';
    }
    return str_contains($base, '{slug}')
      ? str_replace(['{slug}', '{id}'], [$slug, ''], $base)
      : rtrim($base, '/') . '/' . $slug;
  }

  /**
   * The configured reply address of the module's mails, or NULL.
   */
  private function mailFrom(): ?string {
    $from = (string) ($this->configFactory->get('markaspot_fastmap.settings')->get('mail_from') ?? '');
    return $from !== '' ? $from : NULL;
  }

}
