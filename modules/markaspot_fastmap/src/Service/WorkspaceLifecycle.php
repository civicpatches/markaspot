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
 * Only server code writes the record: provisioning sets the acceptance, a
 * later step sets the opening. The field is hidden from JSON:API and from the
 * public settings response.
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
   * A document version: short, no spaces, no markup.
   */
  private const VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/D';

  /**
   * A UI language code such as "en", "de" or "de-ls".
   */
  private const LANGCODE_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {}

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
    return [
      'accepted_at' => $acceptance['accepted_at'],
      'versions' => $versions,
      'langcode' => is_string($acceptance['langcode'] ?? NULL) ? $acceptance['langcode'] : NULL,
      'open' => is_array($record['opening'] ?? NULL),
    ];
  }

  /**
   * Returns the record with its personal data removed, for dump scrubbing.
   *
   * The record is rebuilt from the known shape only, with the account
   * reference zeroed; versions, language and time stay, so a scrubbed copy
   * keeps the workspace under the same rules. Anything outside that shape
   * (an extra key, an opening section, an invalid value) cannot be rebuilt.
   *
   * @return array|null
   *   The scrubbed record, or NULL when the record cannot be rebuilt and
   *   must be looked at by hand.
   */
  public static function scrub(array $record): ?array {
    if (!self::hasExactKeys($record, self::RECORD_KEYS)
      || $record['v'] !== self::RECORD_VERSION
      || $record['opening'] !== NULL) {
      return NULL;
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
      'opening' => NULL,
    ];
  }

  /**
   * Whether a decoded record holds no personal data.
   *
   * Strict: only the exact known shape with valid values and a zeroed
   * account counts as clean. Anything else is not.
   */
  public static function isScrubbed(mixed $record): bool {
    return is_array($record)
      && self::scrub($record) !== NULL
      && $record['acceptance']['uid'] === 0;
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
