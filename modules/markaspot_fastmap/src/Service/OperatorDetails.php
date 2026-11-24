<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * The operator of a map, read from the workspace's own fields.
 *
 * The operator is an organisation or a person (first and last name) with a
 * postal address and a contact e-mail. Stored in field_jurisdiction_address
 * (organization, given_name, family_name, address_line1, postal_code,
 * locality, country_code) and field_jurisdiction_e_mail. One place decides
 * the name and the completeness, for the settings responses, the legal
 * notice and the opening of a map.
 */
final class OperatorDetails {

  /**
   * The address field.
   */
  public const ADDRESS_FIELD = 'field_jurisdiction_address';

  /**
   * The contact e-mail field.
   */
  public const EMAIL_FIELD = 'field_jurisdiction_e_mail';

  /**
   * The parts that make the operator data complete, in display order.
   *
   * "name" stands for the organisation or both person names; when a person
   * name is started but not finished, the missing half is named instead.
   */
  public const PARTS = ['name', 'address_line1', 'postal_code', 'locality', 'country_code', 'email'];

  /**
   * Parts that must contain at least one letter or digit.
   */
  public const TEXT_PARTS = ['organization', 'given_name', 'family_name', 'address_line1', 'locality'];

  /**
   * The operator name: the organisation, else first and last name.
   *
   * Never the map name.
   *
   * @return string|null
   *   The name, or NULL when neither is set.
   */
  public static function name(FieldableEntityInterface $group): ?string {
    $address = self::address($group);
    $name = $address['organization'] !== ''
      ? $address['organization']
      : trim($address['given_name'] . ' ' . $address['family_name']);
    return $name !== '' ? $name : NULL;
  }

  /**
   * The parts of the operator data that are missing.
   *
   * @return string[]
   *   Values of PARTS, plus "given_name" or "family_name" for a person name
   *   with one half missing. Empty when the data is complete.
   */
  public static function missing(FieldableEntityInterface $group): array {
    // Values that are not usable (see isUsable()) count as missing.
    $address = self::address($group);
    $missing = [];
    if ($address['organization'] === '') {
      if ($address['given_name'] === '' && $address['family_name'] === '') {
        $missing[] = 'name';
      }
      else {
        foreach (['given_name', 'family_name'] as $half) {
          if ($address[$half] === '') {
            $missing[] = $half;
          }
        }
      }
    }
    foreach (['address_line1', 'postal_code', 'locality'] as $part) {
      if ($address[$part] === '') {
        $missing[] = $part;
      }
    }
    if (preg_match('/^[A-Z]{2}$/D', $address['country_code']) !== 1) {
      $missing[] = 'country_code';
    }
    if (!self::isEmail(self::email($group))) {
      $missing[] = 'email';
    }
    return $missing;
  }

  /**
   * The snapshot of complete operator data, as the opening stores it.
   *
   * @return array|null
   *   Either "organization" or "given_name" and "family_name", then the
   *   address and the e-mail; NULL when the data is not complete.
   */
  public static function snapshot(FieldableEntityInterface $group): ?array {
    if (self::missing($group) !== []) {
      return NULL;
    }
    $address = self::address($group);
    $snapshot = $address['organization'] !== ''
      ? ['organization' => $address['organization']]
      : ['given_name' => $address['given_name'], 'family_name' => $address['family_name']];
    return $snapshot + [
      'address_line1' => $address['address_line1'],
      'postal_code' => $address['postal_code'],
      'locality' => $address['locality'],
      'country_code' => $address['country_code'],
      'email' => self::email($group),
    ];
  }

  /**
   * The person name halves of the address, for the dashboard form.
   *
   * @return array{given_name: string, family_name: string}
   *   Both halves, empty strings when not set.
   */
  public static function personName(FieldableEntityInterface $group): array {
    $address = self::address($group, FALSE);
    return ['given_name' => $address['given_name'], 'family_name' => $address['family_name']];
  }

  /**
   * Whether a value is a well-formed e-mail address.
   */
  public static function isEmail(mixed $value): bool {
    return is_string($value)
      && self::isUsable($value, FALSE)
      && (new EmailValidator())->isValid($value);
  }

  /**
   * Trims Unicode separators, control and format characters at both ends.
   *
   * @return string
   *   The trimmed value; '' for anything that is not valid UTF-8 text.
   */
  public static function normalize(mixed $value): string {
    if (!is_scalar($value)) {
      return '';
    }
    $trimmed = preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', (string) $value);
    return is_string($trimmed) ? $trimmed : '';
  }

  /**
   * Whether a normalized value can be shown on one line of the imprint.
   *
   * Not empty, no control, format, private-use or unassigned character and no
   * line or paragraph separator inside (no hidden text, no bidi override, no
   * line break), and for names, street and city at least one letter or
   * digit.
   *
   * @param string $value
   *   The value, normalized with normalize().
   * @param bool $needsLetterOrDigit
   *   Whether the value must contain a letter or a digit.
   */
  public static function isUsable(string $value, bool $needsLetterOrDigit): bool {
    return $value !== ''
      && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $value) === 0
      && (!$needsLetterOrDigit || preg_match('/[\p{L}\p{N}]/u', $value) === 1);
  }

  /**
   * Whether a stored value is clean: already normalized and usable.
   *
   * The rule for values in an opening snapshot.
   */
  public static function isClean(mixed $value, bool $needsLetterOrDigit): bool {
    return is_string($value)
      && $value === self::normalize($value)
      && self::isUsable($value, $needsLetterOrDigit);
  }

  /**
   * Makes a free text one line: hidden and breaking characters become spaces.
   *
   * For values that are shown but not validated, such as the map name in
   * the support mail.
   */
  public static function singleLine(mixed $value): string {
    $text = self::normalize($value);
    $text = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $text);
    return is_string($text) ? self::normalize($text) : '';
  }

  /**
   * The normalized address properties this class reads.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $group
   *   The workspace.
   * @param bool $usableOnly
   *   Whether values that are not usable read as ''.
   *
   * @return array<string, string>
   *   Keyed by property name, empty strings for missing values.
   */
  private static function address(FieldableEntityInterface $group, bool $usableOnly = TRUE): array {
    $properties = [
      'organization',
      'given_name',
      'family_name',
      'address_line1',
      'postal_code',
      'locality',
      'country_code',
    ];
    $values = array_fill_keys($properties, '');
    if (!$group->hasField(self::ADDRESS_FIELD) || $group->get(self::ADDRESS_FIELD)->isEmpty()) {
      return $values;
    }
    $item = $group->get(self::ADDRESS_FIELD)->first();
    if ($item === NULL) {
      return $values;
    }
    foreach (array_keys($values) as $property) {
      $value = self::normalize($item->get($property)->getValue());
      $usable = self::isUsable($value, in_array($property, self::TEXT_PARTS, TRUE));
      $values[$property] = $usable || !$usableOnly ? $value : '';
    }
    return $values;
  }

  /**
   * The normalized contact e-mail, '' when absent.
   */
  private static function email(FieldableEntityInterface $group): string {
    if (!$group->hasField(self::EMAIL_FIELD) || $group->get(self::EMAIL_FIELD)->isEmpty()) {
      return '';
    }
    return self::normalize($group->get(self::EMAIL_FIELD)->value);
  }

}
