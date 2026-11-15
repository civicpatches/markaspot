<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Decides how the live operator fields of a workspace look after a scrub.
 *
 * For dump scrubbing of workspaces that carry a lifecycle record: their
 * address (field_jurisdiction_address) and contact e-mail
 * (field_jurisdiction_e_mail) may hold a natural person. A scrubbed address
 * gets the fixed values of WorkspaceLifecycle::SCRUBBED_OPERATOR in the
 * same shape (organisation or person), keeps its country and language and
 * loses every other part. The result is complete operator data, so a
 * scrubbed copy of an open map stays complete and open.
 *
 * The generated legal notice (field_legal_notice) repeats the operator's
 * name and address, and may have been edited by hand. Each of its rows gets
 * one fixed text instead of a regenerated notice: regenerating needs the
 * rendering service, the templates and the language of every revision and
 * translation, which a table sweep does not have, and a hand-edited notice
 * cannot be regenerated without losing what was edited.
 *
 * Works on plain property maps (property name => stored value), so the
 * scrub script can apply it to the rows of the data and revision tables.
 */
final class OperatorScrub {

  /**
   * Address properties that keep their value.
   */
  public const KEPT = ['country_code', 'langcode'];

  /**
   * Address properties that get a fixed value.
   */
  public const PLACEHOLDERS = ['organization', 'given_name', 'family_name', 'address_line1', 'postal_code', 'locality'];

  /**
   * The scrubbed values of an address, for the properties given.
   *
   * An address with a person name and no organisation keeps the person
   * shape; every other address gets the organisation placeholder.
   *
   * @param array<string, mixed> $values
   *   Stored values by property name (columns that exist on the table).
   *
   * @return array<string, string|null>
   *   The same properties with their scrubbed values; NULL clears one.
   */
  public static function address(array $values): array {
    $text = static fn(string $property): string => OperatorDetails::normalize($values[$property] ?? '');
    $person = $text('organization') === '' && ($text('given_name') !== '' || $text('family_name') !== '');
    $placeholders = WorkspaceLifecycle::SCRUBBED_OPERATOR;
    if ($person) {
      $placeholders['organization'] = NULL;
    }
    else {
      $placeholders['given_name'] = NULL;
      $placeholders['family_name'] = NULL;
    }

    $scrubbed = [];
    foreach ($values as $property => $value) {
      if (in_array($property, self::KEPT, TRUE)) {
        $scrubbed[$property] = $value === NULL ? NULL : (string) $value;
      }
      elseif (in_array($property, self::PLACEHOLDERS, TRUE)) {
        $scrubbed[$property] = $placeholders[$property];
      }
      else {
        $scrubbed[$property] = NULL;
      }
    }
    return $scrubbed;
  }

  /**
   * Whether an address is already what address() makes of it.
   *
   * @param array<string, mixed> $values
   *   Stored values by property name. An empty string and NULL count alike.
   */
  public static function isScrubbedAddress(array $values): bool {
    return self::normalRow(self::address($values)) === self::normalRow($values);
  }

  /**
   * The text that replaces a legal notice in a scrubbed copy.
   *
   * Plain text: it is stored under whatever text format the row has.
   */
  public const LEGAL_NOTICE = 'Legal notice removed from this copy of the data.';

  /**
   * The scrubbed values of a legal notice row.
   *
   * The text gets the fixed sentence, the text format stays, and any other
   * column (a summary of a text-with-summary storage) is emptied.
   *
   * @param array<string, mixed> $values
   *   Stored values by property name (columns that exist on the table).
   *
   * @return array<string, string|null>
   *   The same properties with their scrubbed values.
   */
  public static function legalNotice(array $values): array {
    $scrubbed = [];
    foreach ($values as $property => $value) {
      $scrubbed[$property] = match ($property) {
        'value' => self::LEGAL_NOTICE,
        'format' => $value === NULL ? NULL : (string) $value,
        default => NULL,
      };
    }
    return $scrubbed;
  }

  /**
   * Whether a legal notice row is already what legalNotice() makes of it.
   *
   * @param array<string, mixed> $values
   *   Stored values by property name. An empty string and NULL count alike.
   */
  public static function isScrubbedLegalNotice(array $values): bool {
    return array_key_exists('value', $values)
      && self::normalRow(self::legalNotice($values)) === self::normalRow($values);
  }

  /**
   * Treats empty strings like NULL, for row comparisons.
   *
   * @return array<string, string|null>
   *   The row.
   */
  private static function normalRow(array $row): array {
    return array_map(
      static fn(mixed $value): ?string => $value === NULL || $value === '' ? NULL : (string) $value,
      $row,
    );
  }

  /**
   * The scrubbed contact e-mail.
   */
  public static function email(): string {
    return WorkspaceLifecycle::SCRUBBED_OPERATOR['email'];
  }

  /**
   * Whether a stored contact e-mail is the scrubbed one.
   */
  public static function isScrubbedEmail(mixed $value): bool {
    return $value === self::email();
  }

}
