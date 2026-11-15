<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Service\OperatorScrub;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the scrubbed shape of the live operator fields.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\OperatorScrub
 * @group markaspot_fastmap
 */
class OperatorScrubTest extends UnitTestCase {

  /**
   * A stored address row of a person, by property.
   */
  private const PERSON = [
    'langcode' => 'en',
    'country_code' => 'NL',
    'administrative_area' => 'NH',
    'locality' => 'Amsterdam',
    'dependent_locality' => 'Centrum',
    'postal_code' => '1011 AB',
    'sorting_code' => 'X',
    'address_line1' => 'Main street 1',
    'address_line2' => 'Back house',
    'address_line3' => NULL,
    'organization' => '',
    'given_name' => 'Jane',
    'additional_name' => 'Q.',
    'family_name' => 'Doe',
  ];

  /**
   * A person keeps the person shape; country and language stay.
   *
   * @covers ::address
   * @covers ::isScrubbedAddress
   */
  public function testPersonAddress(): void {
    $scrubbed = OperatorScrub::address(self::PERSON);
    $placeholders = WorkspaceLifecycle::SCRUBBED_OPERATOR;
    $this->assertSame([
      'langcode' => 'en',
      'country_code' => 'NL',
      'administrative_area' => NULL,
      'locality' => $placeholders['locality'],
      'dependent_locality' => NULL,
      'postal_code' => $placeholders['postal_code'],
      'sorting_code' => NULL,
      'address_line1' => $placeholders['address_line1'],
      'address_line2' => NULL,
      'address_line3' => NULL,
      'organization' => NULL,
      'given_name' => $placeholders['given_name'],
      'additional_name' => NULL,
      'family_name' => $placeholders['family_name'],
    ], $scrubbed);
    $this->assertFalse(OperatorScrub::isScrubbedAddress(self::PERSON));
    $this->assertTrue(OperatorScrub::isScrubbedAddress($scrubbed));
    $this->assertSame($scrubbed, OperatorScrub::address($scrubbed), 'Idempotent.');
    // Empty strings from the database count as cleared.
    $this->assertTrue(OperatorScrub::isScrubbedAddress(array_map(static fn($value) => $value ?? '', $scrubbed)));

    $encoded = (string) json_encode($scrubbed);
    foreach (['Jane', 'Doe', 'Main street', '1011', 'Amsterdam', 'Back house', 'Centrum', 'Q.'] as $needle) {
      $this->assertStringNotContainsString($needle, $encoded, $needle);
    }
  }

  /**
   * An organisation, or no name at all, gets the organisation placeholder.
   *
   * @covers ::address
   */
  public function testOrganisationAndNamelessAddress(): void {
    foreach ([
      'organisation' => ['organization' => 'Example Org', 'given_name' => 'Jane'] + self::PERSON,
      'no name' => ['given_name' => '', 'family_name' => NULL] + self::PERSON,
    ] as $label => $row) {
      $scrubbed = OperatorScrub::address($row);
      $this->assertSame(WorkspaceLifecycle::SCRUBBED_OPERATOR['organization'], $scrubbed['organization'], $label);
      $this->assertNull($scrubbed['given_name'], $label);
      $this->assertNull($scrubbed['family_name'], $label);
    }
    // Only the columns given are returned.
    $this->assertSame(
      ['country_code' => 'DE', 'locality' => 'Scrubbed'],
      OperatorScrub::address(['country_code' => 'DE', 'locality' => 'Berlin']),
    );
  }

  /**
   * A partly scrubbed row is not scrubbed.
   *
   * @covers ::isScrubbedAddress
   * @covers ::isScrubbedEmail
   */
  public function testPartlyScrubbedIsNot(): void {
    $scrubbed = OperatorScrub::address(self::PERSON);
    foreach (['address_line2' => 'Back house', 'given_name' => 'Jane', 'organization' => 'Example Org'] as $property => $value) {
      $this->assertFalse(OperatorScrub::isScrubbedAddress([$property => $value] + $scrubbed), $property);
    }
    $this->assertTrue(OperatorScrub::isScrubbedEmail(OperatorScrub::email()));
    $this->assertSame(WorkspaceLifecycle::SCRUBBED_OPERATOR['email'], OperatorScrub::email());
    foreach (['jane@example.org', '', NULL] as $value) {
      $this->assertFalse(OperatorScrub::isScrubbedEmail($value));
    }
  }

  /**
   * A legal notice gets one fixed text, whatever it held.
   *
   * @covers ::legalNotice
   * @covers ::isScrubbedLegalNotice
   */
  public function testLegalNotice(): void {
    $row = ['value' => '<p>Jane Doe, Main street 1</p>', 'format' => 'basic_html'];
    $scrubbed = OperatorScrub::legalNotice($row);
    $this->assertSame(['value' => OperatorScrub::LEGAL_NOTICE, 'format' => 'basic_html'], $scrubbed, 'The format stays.');
    $this->assertTrue(OperatorScrub::isScrubbedLegalNotice($scrubbed));
    $this->assertFalse(OperatorScrub::isScrubbedLegalNotice($row));
    foreach (['', NULL, '<!-- manual-override --><p>Jane</p>'] as $value) {
      $this->assertFalse(OperatorScrub::isScrubbedLegalNotice(['value' => $value, 'format' => 'basic_html']), var_export($value, TRUE));
    }
    $this->assertFalse(OperatorScrub::isScrubbedLegalNotice(['format' => 'basic_html']), 'No text column.');
    // Plain text: no markup, whatever the row's text format.
    $this->assertSame(strip_tags(OperatorScrub::LEGAL_NOTICE), OperatorScrub::LEGAL_NOTICE);
    foreach (['Jane', 'Doe', 'Main street', '@'] as $needle) {
      $this->assertStringNotContainsString($needle, OperatorScrub::LEGAL_NOTICE);
    }

    // A further text column (a summary) is emptied and counts when filled.
    $withSummary = $row + ['summary' => 'Jane Doe'];
    $this->assertSame(
      ['value' => OperatorScrub::LEGAL_NOTICE, 'format' => 'basic_html', 'summary' => NULL],
      OperatorScrub::legalNotice($withSummary),
    );
    $this->assertFalse(OperatorScrub::isScrubbedLegalNotice(['summary' => 'Jane Doe'] + $scrubbed));
    $this->assertTrue(OperatorScrub::isScrubbedLegalNotice(['summary' => ''] + $scrubbed));
  }

}
