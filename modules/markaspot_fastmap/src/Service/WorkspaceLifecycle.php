<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_group\Service\WorkspaceTier;

/**
 * Reads, validates and writes the lifecycle record of a workspace.
 *
 * The record is one JSON document in field_lifecycle_record on the
 * jurisdiction group. It is the opt-in into the self-service lifecycle: a
 * workspace without a valid record keeps the rules it had before. Shape:
 *
 * @code
 * {"v":1,"acceptance":{"terms":"..","dpa":"..","declaration":"..",
 *  "langcode":"..","accepted_at":<unix>,"uid":<account id>},"opening":null}
 * @endcode
 *
 * Opening a map sets "opening" to:
 *
 * @code
 * {"opened_at":<unix>,"uid":<account id>,"revision_id":<group revision>,
 *  "operator":{"organization":".." | "given_name":"..","family_name":"..",
 *   "address_line1":"..","postal_code":"..","locality":"..",
 *   "country_code":"..","email":".."}}
 * @endcode
 *
 * Only server code writes the record: provisioning sets the acceptance, the
 * opening service sets the opening. The field is hidden from JSON:API and
 * from the public settings response.
 */
final class WorkspaceLifecycle {

  /**
   * The group field holding the record.
   */
  public const FIELD = 'field_lifecycle_record';

  /**
   * The record format version.
   */
  public const RECORD_VERSION = 1;

  /**
   * The accepted documents, in record order.
   */
  public const DOCUMENTS = ['terms', 'dpa', 'declaration'];

  /**
   * The top-level keys of a record.
   */
  private const RECORD_KEYS = ['v', 'acceptance', 'opening'];

  /**
   * The keys of the acceptance section.
   */
  private const ACCEPTANCE_KEYS = ['terms', 'dpa', 'declaration', 'langcode', 'accepted_at', 'uid'];

  /**
   * The keys of the opening section.
   */
  private const OPENING_KEYS = ['opened_at', 'uid', 'revision_id', 'operator'];

  /**
   * The address and contact keys of the operator snapshot.
   */
  private const SNAPSHOT_KEYS = ['address_line1', 'postal_code', 'locality', 'country_code', 'email'];

  /**
   * The name keys of an operator snapshot: an organisation or a person.
   */
  private const SNAPSHOT_NAME_KEYS = [['organization'], ['given_name', 'family_name']];

  /**
   * Fixed values that replace the operator's personal data in a scrub.
   *
   * They keep the snapshot valid, so a scrubbed copy stays open. The country
   * code is kept.
   */
  public const SCRUBBED_OPERATOR = [
    'organization' => 'Scrubbed operator',
    'given_name' => 'Scrubbed',
    'family_name' => 'Operator',
    'address_line1' => 'Scrubbed street 1',
    'postal_code' => '00000',
    'locality' => 'Scrubbed',
    'email' => 'operator@example.invalid',
  ];

  /**
   * A document version: short, no spaces, no markup.
   */
  private const VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/D';

  /**
   * A UI language code such as "en", "de" or "de-ls".
   */
  private const LANGCODE_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D';

  /**
   * Key of the recursion guard of markaspot_fastmap_group_update().
   *
   * The drupal_static() entry, keyed by group ID, that keeps the legal notice
   * from being regenerated inside a save. Set by the hook around its own
   * save and by WorkspaceOpening::resetOperator().
   */
  public const LEGAL_NOTICE_REGENERATING = 'markaspot_fastmap_group_update:regenerating';

  /**
   * Entities whose record this class wrote and that are not saved yet.
   *
   * Static, so the mark holds whichever instance wrote it (provisioning can
   * build its own instance).
   *
   * @var \WeakMap<\Drupal\Core\Entity\FieldableEntityInterface, true>|null
   */
  private static ?\WeakMap $ownWrites = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {}

  /**
   * Whether this service wrote the entity's record since its last save.
   *
   * Consumed by the save rule (WorkspaceOpening::enforceOnSave()): the mark
   * holds for the next save of that entity object only. Every other writer
   * of an existing workspace gets the stored record back.
   */
  public function consumeOwnWrite(FieldableEntityInterface $group): bool {
    $marks = self::ownWrites();
    $own = isset($marks[$group]);
    unset($marks[$group]);
    return $own;
  }

  /**
   * Marks the entity's record as written by this class.
   */
  private static function markOwnWrite(FieldableEntityInterface $group): void {
    $marks = self::ownWrites();
    $marks[$group] = TRUE;
  }

  /**
   * The marks of own writes.
   *
   * @return \WeakMap<\Drupal\Core\Entity\FieldableEntityInterface, true>
   *   The shared map.
   */
  private static function ownWrites(): \WeakMap {
    return self::$ownWrites ??= new \WeakMap();
  }

  /**
   * Whether the platform switch is on.
   *
   * Only a stored boolean TRUE counts, and only on the self-service
   * platform: outside it no rule of the model applies, so no record is
   * written either.
   */
  public function isEnabled(): bool {
    return $this->configFactory->get('markaspot_fastmap.settings')->get('lifecycle_enabled') === TRUE
      && $this->isSelfService();
  }

  /**
   * Whether the platform is in self-service mode.
   *
   * Without the module handler (a caller that builds this service itself)
   * the module list is not known; this class only runs with
   * markaspot_fastmap enabled, which WorkspaceTier::isSelfService() takes as
   * self-service unless the operating mode says self-hosted.
   */
  private function isSelfService(): bool {
    if ($this->moduleHandler !== NULL) {
      return WorkspaceTier::isSelfService($this->moduleHandler);
    }
    return Settings::get('markaspot_operating_mode') !== 'self_hosted';
  }

  /**
   * Builds the acceptance of a signup request, stamped with the request time.
   *
   * @param mixed $input
   *   The "acceptance" member of the signup body.
   *
   * @return array|null
   *   The normalized acceptance, or NULL when the input is not a complete,
   *   well-formed acceptance. The client never sets the time.
   */
  public function acceptanceFromSignup(mixed $input): ?array {
    if (!is_array($input)) {
      return NULL;
    }
    $acceptance = [];
    foreach (self::DOCUMENTS as $document) {
      $version = $input[$document] ?? NULL;
      if (!self::isVersion($version)) {
        return NULL;
      }
      $acceptance[$document] = $version;
    }
    $langcode = is_string($input['langcode'] ?? NULL) ? strtolower(trim($input['langcode'])) : '';
    if (!self::isLangcode($langcode)) {
      return NULL;
    }
    $acceptance['langcode'] = $langcode;
    $acceptance['accepted_at'] = $this->time->getRequestTime();
    return $acceptance;
  }

  /**
   * Whether an acceptance is complete enough to provision a workspace with.
   */
  public static function isValidAcceptance(mixed $acceptance): bool {
    if (!is_array($acceptance)) {
      return FALSE;
    }
    foreach (self::DOCUMENTS as $document) {
      if (!self::isVersion($acceptance[$document] ?? NULL)) {
        return FALSE;
      }
    }
    return self::isLangcode($acceptance['langcode'] ?? NULL)
      && is_int($acceptance['accepted_at'] ?? NULL)
      && $acceptance['accepted_at'] > 0;
  }

  /**
   * Whether a decoded record is valid.
   *
   * Valid means: version 1 and an acceptance section with all three document
   * versions and a positive acceptance time.
   */
  public static function isValidRecord(mixed $record): bool {
    if (!is_array($record) || ($record['v'] ?? NULL) !== self::RECORD_VERSION) {
      return FALSE;
    }
    $acceptance = $record['acceptance'] ?? NULL;
    if (!is_array($acceptance)) {
      return FALSE;
    }
    foreach (self::DOCUMENTS as $document) {
      if (!self::isVersion($acceptance[$document] ?? NULL)) {
        return FALSE;
      }
    }
    return is_int($acceptance['accepted_at'] ?? NULL) && $acceptance['accepted_at'] > 0;
  }

  /**
   * Decodes a stored record.
   *
   * @return array|null
   *   The record when it parses and is valid, NULL otherwise.
   */
  public static function parse(?string $json): ?array {
    if ($json === NULL || $json === '') {
      return NULL;
    }
    try {
      $record = json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return self::isValidRecord($record) ? $record : NULL;
  }

  /**
   * Builds a new record from an acceptance and the accepting account.
   *
   * @throws \InvalidArgumentException
   *   When the acceptance is invalid or the account id is not positive.
   */
  public static function buildRecord(array $acceptance, int $uid): array {
    if (!self::isValidAcceptance($acceptance) || $uid <= 0) {
      throw new \InvalidArgumentException('A lifecycle record needs a valid acceptance and an account.');
    }
    $section = [];
    foreach (self::DOCUMENTS as $document) {
      $section[$document] = $acceptance[$document];
    }
    $section['langcode'] = $acceptance['langcode'];
    $section['accepted_at'] = $acceptance['accepted_at'];
    $section['uid'] = $uid;
    return [
      'v' => self::RECORD_VERSION,
      'acceptance' => $section,
      'opening' => NULL,
    ];
  }

  /**
   * Whether the entity can carry a record.
   */
  public function hasRecordField(FieldableEntityInterface $group): bool {
    return $group->hasField(self::FIELD);
  }

  /**
   * Returns the valid record of a workspace, or NULL.
   */
  public function read(FieldableEntityInterface $group): ?array {
    if (!$group->hasField(self::FIELD) || $group->get(self::FIELD)->isEmpty()) {
      return NULL;
    }
    $value = $group->get(self::FIELD)->value;
    return self::parse(is_string($value) ? $value : NULL);
  }

  /**
   * Whether the workspace carries a valid record (the new model).
   */
  public function hasValidRecord(FieldableEntityInterface $group): bool {
    return $this->read($group) !== NULL;
  }

  /**
   * Whether the workspace has a valid record and has been opened.
   *
   * Only a well-formed opening counts: a malformed one leaves the map not
   * open, so the intake stays closed.
   */
  public function isOpen(FieldableEntityInterface $group): bool {
    $record = $this->read($group);
    return $record !== NULL && self::isValidOpening($record['opening'] ?? NULL);
  }

  /**
   * Returns the valid opening section of a workspace, or NULL.
   */
  public function opening(FieldableEntityInterface $group): ?array {
    $record = $this->read($group);
    $opening = $record['opening'] ?? NULL;
    return self::isValidOpening($opening) ? $opening : NULL;
  }

  /**
   * Whether a value is a well-formed opening section.
   *
   * Exactly the known keys: a positive time and revision id, an account id
   * (0 after a scrub) and a complete operator snapshot.
   */
  public static function isValidOpening(mixed $opening): bool {
    return is_array($opening)
      && self::hasExactKeys($opening, self::OPENING_KEYS)
      && is_int($opening['opened_at']) && $opening['opened_at'] > 0
      && is_int($opening['uid']) && $opening['uid'] >= 0
      && is_int($opening['revision_id']) && $opening['revision_id'] > 0
      && self::isValidOperatorSnapshot($opening['operator']);
  }

  /**
   * Whether a value is a complete operator snapshot.
   *
   * An organisation or a person (first and last name), then street, postal
   * code, city, an ISO country code and an e-mail address. Every value is
   * clean in the sense of OperatorDetails::isClean(): no hidden, breaking or
   * direction characters, no separators at the ends, a letter or digit in
   * names, street and city. Nothing else.
   */
  public static function isValidOperatorSnapshot(mixed $snapshot): bool {
    if (!is_array($snapshot)) {
      return FALSE;
    }
    $shape = NULL;
    foreach (self::SNAPSHOT_NAME_KEYS as $nameKeys) {
      if (self::hasExactKeys($snapshot, array_merge($nameKeys, self::SNAPSHOT_KEYS))) {
        $shape = $nameKeys;
      }
    }
    if ($shape === NULL) {
      return FALSE;
    }
    foreach ($snapshot as $key => $value) {
      if (!OperatorDetails::isClean($value, in_array($key, OperatorDetails::TEXT_PARTS, TRUE)) || mb_strlen($value) > 255) {
        return FALSE;
      }
    }
    return preg_match('/^[A-Z]{2}$/D', $snapshot['country_code']) === 1
      && OperatorDetails::isEmail($snapshot['email']);
  }

  /**
   * Writes the opening section. The caller saves the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $group
   *   The workspace carrying a valid record that is not open.
   * @param int $uid
   *   The account that opens the map.
   * @param array $operator
   *   The operator snapshot (OperatorDetails::snapshot()).
   * @param int $revisionId
   *   The group revision whose operator data were confirmed.
   *
   * @return array
   *   The opening section as written.
   *
   * @throws \LogicException
   *   When the workspace has no valid record or is open already.
   * @throws \InvalidArgumentException
   *   When the account, revision or snapshot is invalid.
   */
  public function writeOpening(FieldableEntityInterface $group, int $uid, array $operator, int $revisionId): array {
    $record = $this->read($group);
    if ($record === NULL) {
      throw new \LogicException('The workspace has no valid lifecycle record.');
    }
    if (self::isValidOpening($record['opening'] ?? NULL)) {
      throw new \LogicException('The workspace is open already.');
    }
    $opening = [
      'opened_at' => $this->time->getRequestTime(),
      'uid' => $uid,
      'revision_id' => $revisionId,
      'operator' => $operator,
    ];
    if ($uid <= 0 || !self::isValidOpening($opening)) {
      throw new \InvalidArgumentException('An opening needs an account, a revision and complete operator data.');
    }
    $record['opening'] = $opening;
    self::markOwnWrite($group);
    $group->set(self::FIELD, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $opening;
  }

  /**
   * Whether the workspace has a valid record and is not opened yet.
   *
   * Every valid record is either open or not open: an opening that is not a
   * well-formed section counts as not open. A workspace without a valid
   * record is neither.
   */
  public function isNotOpen(FieldableEntityInterface $group): bool {
    return $this->hasValidRecord($group) && !$this->isOpen($group);
  }

  /**
   * Sets the acceptance record on a workspace. The caller saves the entity.
   *
   * @throws \InvalidArgumentException
   *   When the acceptance or account is invalid.
   * @throws \RuntimeException
   *   When the entity has no record field.
   */
  public function writeAcceptance(FieldableEntityInterface $group, array $acceptance, int $uid): void {
    if (!$group->hasField(self::FIELD)) {
      throw new \RuntimeException('The workspace has no lifecycle record field.');
    }
    $record = self::buildRecord($acceptance, $uid);
    self::markOwnWrite($group);
    $group->set(self::FIELD, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * Builds the lifecycle object of the dashboard settings response.
   *
   * @return array|null
   *   NULL for a workspace without a valid record.
   */
  public function settingsSummary(FieldableEntityInterface $group): ?array {
    $record = $this->read($group);
    if ($record === NULL) {
      return NULL;
    }
    $acceptance = $record['acceptance'];
    $versions = [];
    foreach (self::DOCUMENTS as $document) {
      $versions[$document] = $acceptance[$document];
    }
    $opening = $record['opening'] ?? NULL;
    $open = self::isValidOpening($opening);
    return [
      'accepted_at' => $acceptance['accepted_at'],
      'versions' => $versions,
      'langcode' => is_string($acceptance['langcode'] ?? NULL) ? $acceptance['langcode'] : NULL,
      'open' => $open,
      'opened_at' => $open ? $opening['opened_at'] : NULL,
    ];
  }

  /**
   * Takes the opening back. The caller saves the entity.
   *
   * Only for the support reset of false operator data
   * (WorkspaceOpening::resetOperator()): the map is closed again and the
   * operator can open it anew. The acceptance stays.
   *
   * @return bool
   *   Whether the record held an opening section (valid or not).
   *
   * @throws \LogicException
   *   When the workspace has no valid record.
   */
  public function removeOpening(FieldableEntityInterface $group): bool {
    $record = $this->read($group);
    if ($record === NULL) {
      throw new \LogicException('The workspace has no valid lifecycle record.');
    }
    if (($record['opening'] ?? NULL) === NULL) {
      return FALSE;
    }
    $record['opening'] = NULL;
    self::markOwnWrite($group);
    $group->set(self::FIELD, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return TRUE;
  }

  /**
   * Builds the lifecycle object for the workspace's administrators.
   *
   * The settings summary plus the operator parts still missing for opening
   * (empty when complete).
   *
   * @return array|null
   *   NULL for a workspace without a valid record.
   */
  public function dashboardSummary(FieldableEntityInterface $group): ?array {
    $summary = $this->settingsSummary($group);
    if ($summary === NULL) {
      return NULL;
    }
    return $summary + ['operator_missing' => OperatorDetails::missing($group)];
  }

  /**
   * Returns the record with its personal data removed, for dump scrubbing.
   *
   * The record is rebuilt from the known shape only. The account references
   * are zeroed and the operator snapshot of an opening gets the fixed
   * SCRUBBED_OPERATOR values in the same shape; versions, language, times,
   * revision and country stay, so a scrubbed copy keeps the workspace under
   * the same rules, open or not. Anything outside that shape (an extra key,
   * a malformed opening, an invalid value) cannot be rebuilt.
   *
   * @return array|null
   *   The scrubbed record, or NULL when the record cannot be rebuilt and
   *   must be looked at by hand.
   */
  public static function scrub(array $record): ?array {
    if (!self::hasExactKeys($record, self::RECORD_KEYS)
      || $record['v'] !== self::RECORD_VERSION) {
      return NULL;
    }
    $opening = $record['opening'];
    if ($opening !== NULL) {
      if (!self::isValidOpening($opening)) {
        return NULL;
      }
      $operator = [];
      foreach ($opening['operator'] as $key => $value) {
        $operator[$key] = self::SCRUBBED_OPERATOR[$key] ?? $value;
      }
      $opening = [
        'opened_at' => $opening['opened_at'],
        'uid' => 0,
        'revision_id' => $opening['revision_id'],
        'operator' => $operator,
      ];
    }
    $acceptance = $record['acceptance'];
    if (!is_array($acceptance)
      || !self::hasExactKeys($acceptance, self::ACCEPTANCE_KEYS)
      || !self::isValidAcceptance($acceptance)
      || !is_int($acceptance['uid'])
      || $acceptance['uid'] < 0) {
      return NULL;
    }
    $section = [];
    foreach (self::DOCUMENTS as $document) {
      $section[$document] = $acceptance[$document];
    }
    $section['langcode'] = $acceptance['langcode'];
    $section['accepted_at'] = $acceptance['accepted_at'];
    $section['uid'] = 0;
    return [
      'v' => self::RECORD_VERSION,
      'acceptance' => $section,
      'opening' => $opening,
    ];
  }

  /**
   * Whether a decoded record holds no personal data.
   *
   * Strict: only the exact known shape counts as clean, and only when it is
   * already what scrub() makes of it (zeroed accounts, the fixed operator
   * values). Anything else is not.
   */
  public static function isScrubbed(mixed $record): bool {
    if (!is_array($record)) {
      return FALSE;
    }
    $scrubbed = self::scrub($record);
    return $scrubbed !== NULL && self::canonical($scrubbed) === self::canonical($record);
  }

  /**
   * Sorts the keys of a nested array, for an order-independent comparison.
   */
  private static function canonical(array $value): array {
    ksort($value);
    foreach ($value as $key => $item) {
      if (is_array($item)) {
        $value[$key] = self::canonical($item);
      }
    }
    return $value;
  }

  /**
   * Whether an array has exactly the given keys, in any order.
   */
  private static function hasExactKeys(array $value, array $keys): bool {
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    return $actual === $keys;
  }

  /**
   * Whether a value is a well-formed document version.
   */
  private static function isVersion(mixed $value): bool {
    return is_string($value) && preg_match(self::VERSION_PATTERN, $value) === 1;
  }

  /**
   * Whether a value is a well-formed UI language code.
   */
  private static function isLangcode(mixed $value): bool {
    return is_string($value) && strlen($value) <= 12 && preg_match(self::LANGCODE_PATTERN, $value) === 1;
  }

}
