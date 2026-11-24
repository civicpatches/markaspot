<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceTier;
use Psr\Log\LoggerInterface;

/**
 * Opens a map for public reports, and keeps an open map's operator complete.
 *
 * Applies to a workspace that itself carries a valid lifecycle record, on the
 * self-service platform. Opening writes the opening section of the record
 * (time, account, the group revision whose operator data were confirmed, a
 * snapshot of those data), saves the group as a new revision and sends one
 * mail to the platform support address. Visibility is not touched.
 */
class WorkspaceOpening {

  /**
   * The mail key of the support mail.
   */
  public const MAIL_KEY = 'workspace_opened_support';

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

  /**
   * Outcomes of resetOperator() besides NOT_APPLICABLE and BUSY.
   */
  public const NOTHING_TO_RESET = 'nothing_to_reset';
  public const WOULD_RESET = 'would_reset';
  public const RESET = 'reset';

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
      if (self::isBlocked($stored)) {
        return ['status' => self::BLOCKED];
      }
      if ($this->lifecycle->isOpen($stored)) {
        return ['status' => self::ALREADY_OPEN, 'group' => $stored];
      }
      $uid = (int) $account->id();
      $opening = $this->lifecycle->writeOpening($stored, $uid, OperatorDetails::snapshot($stored), (int) $stored->getRevisionId());
      self::setOpeningRevision($stored, $opening);
      $stored->save();
      $this->logger->notice('Workspace @id opened for public reports by account @uid.', [
        '@id' => (string) $stored->id(),
        '@uid' => (string) $uid,
      ]);
    }
    finally {
      $this->lock->release($lockName);
    }

    $this->sendSupportMail($stored, $opening, $account);
    return ['status' => self::OPENED, 'group' => $stored];
  }

  /**
   * Records the opening on the group revision about to be saved.
   */
  public static function setOpeningRevision(GroupInterface $group, array $opening): void {
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
   * Older revisions keep their values. The operator can enter correct data
   * and open the map again, which sends a new support mail.
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
      if ($parts === [] && !$wasOpen) {
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
      $this->lifecycle->removeOpening($stored);
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
   * Sends the support mail of an opening.
   *
   * Only to markaspot_fastmap.settings:support_email; never to the site
   * mail, mail_from or an account. No report and no citizen data.
   *
   * @return bool
   *   Whether the mail manager accepted the mail.
   */
  public function sendSupportMail(GroupInterface $group, array $opening, AccountInterface $account): bool {
    $to = $this->supportRecipient();
    if ($to === NULL) {
      $this->logger->error('No support mail for the opening of workspace @id: markaspot_fastmap.settings:support_email is empty or not a valid address.', [
        '@id' => (string) $group->id(),
      ]);
      return FALSE;
    }
    try {
      $result = $this->mailManager->mail('markaspot_fastmap', self::MAIL_KEY, $to, 'en', $this->mailParams($group, $opening, $account), $this->mailFrom());
    }
    catch (\Throwable $e) {
      $this->logger->error('The support mail for the opening of workspace @id failed: @message', [
        '@id' => (string) $group->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    if (empty($result['result'])) {
      $this->logger->error('The support mail for the opening of workspace @id was not sent.', [
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
   * The parameters of the support mail, from the confirmed snapshot.
   *
   * @return array<string, string>
   *   Mail parameters.
   */
  public function mailParams(GroupInterface $group, array $opening, AccountInterface $account): array {
    $operator = $opening['operator'];
    $name = $operator === NULL ? '(not provided)' : ($operator['organization'] ?? trim($operator['given_name'] . ' ' . $operator['family_name']));
    $slug = $group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()
      ? trim((string) $group->get('field_slug')->value)
      : '';
    // Every value one line of visible text, the map name included.
    return array_map([OperatorDetails::class, 'singleLine'], [
      'workspace_name' => (string) $group->label(),
      'workspace_slug' => $slug,
      'workspace_id' => (string) $group->id(),
      'workspace_url' => $this->workspaceUrl($slug),
      'operator_name' => $name,
      'operator_address' => $operator === NULL ? '(not provided)' : sprintf('%s, %s %s, %s', $operator['address_line1'], $operator['postal_code'], $operator['locality'], $operator['country_code']),
      'operator_email' => $operator['email'] ?? '(not provided)',
      'opened_by' => (string) $account->getEmail(),
      'opened_at' => gmdate('Y-m-d H:i', $opening['opened_at']) . ' UTC',
    ]);
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
