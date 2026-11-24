<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Service\OperatorDetails;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the operator name, completeness and snapshot.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\OperatorDetails
 * @group markaspot_fastmap
 */
class OperatorDetailsTest extends UnitTestCase {

  use OperatorWorkspaceTrait;

  /**
   * Complete data of an organisation.
   */
  public const ORGANISATION = [
    'organization' => 'Example Org',
    'address_line1' => 'Main street 1',
    'postal_code' => '12345',
    'locality' => 'Town',
    'country_code' => 'DE',
  ];

  /**
   * The name: organisation first, else the person, never anything else.
   *
   * @covers ::name
   */
  public function testName(): void {
    $cases = [
      'organisation' => [self::ORGANISATION, 'Example Org'],
      'organisation wins over a person' => [
        ['given_name' => 'Jane', 'family_name' => 'Doe'] + self::ORGANISATION,
        'Example Org',
      ],
      'person' => [
        ['organization' => '', 'given_name' => ' Jane ', 'family_name' => 'Doe'] + self::ORGANISATION,
        'Jane Doe',
      ],
      'first name only' => [['organization' => '', 'given_name' => 'Jane'] + self::ORGANISATION, 'Jane'],
      'whitespace only' => [['organization' => '  ', 'given_name' => ' '] + self::ORGANISATION, NULL],
      'no address' => [NULL, NULL],
    ];
    foreach ($cases as $label => [$address, $expected]) {
      $this->assertSame($expected, OperatorDetails::name($this->operatorWorkspace($address, 'op@example.org')), $label);
    }
    $this->assertNull(OperatorDetails::name($this->operatorWorkspace(self::ORGANISATION, 'op@example.org', FALSE)), 'no fields');
  }

  /**
   * Completeness names each missing part.
   *
   * @covers ::missing
   */
  public function testMissing(): void {
    $person = ['organization' => '', 'given_name' => 'Jane', 'family_name' => 'Doe'] + self::ORGANISATION;
    $cases = [
      'complete organisation' => [self::ORGANISATION, 'op@example.org', []],
      'complete person' => [$person, 'op@example.org', []],
      'nothing' => [NULL, NULL, ['name', 'address_line1', 'postal_code', 'locality', 'country_code', 'email']],
      'no name' => [['organization' => ''] + self::ORGANISATION, 'op@example.org', ['name']],
      'family name missing' => [['family_name' => ''] + $person, 'op@example.org', ['family_name']],
      'given name missing' => [['given_name' => ' '] + $person, 'op@example.org', ['given_name']],
      'street missing' => [['address_line1' => ''] + self::ORGANISATION, 'op@example.org', ['address_line1']],
      'postal code and city missing' => [
        ['postal_code' => '', 'locality' => ''] + self::ORGANISATION,
        'op@example.org',
        ['postal_code', 'locality'],
      ],
      'country missing' => [['country_code' => ''] + self::ORGANISATION, 'op@example.org', ['country_code']],
      'country not a code' => [['country_code' => 'Germany'] + self::ORGANISATION, 'op@example.org', ['country_code']],
      'e-mail missing' => [self::ORGANISATION, NULL, ['email']],
      'e-mail invalid' => [self::ORGANISATION, 'no address', ['email']],
      'line break in the street' => [
        ['address_line1' => "Main\nstreet"] + self::ORGANISATION,
        'op@example.org',
        ['address_line1'],
      ],
    ];
    foreach ($cases as $label => [$address, $email, $expected]) {
      $this->assertSame($expected, OperatorDetails::missing($this->operatorWorkspace($address, $email)), $label);
    }
    $this->assertSame(OperatorDetails::PARTS, OperatorDetails::missing($this->operatorWorkspace(NULL, NULL, FALSE)), 'no fields');
  }

  /**
   * The snapshot has the shape the opening validator accepts.
   *
   * @covers ::snapshot
   * @covers ::personName
   */
  public function testSnapshot(): void {
    $snapshot = OperatorDetails::snapshot($this->operatorWorkspace(['given_name' => 'Jane'] + self::ORGANISATION, 'op@example.org'));
    $this->assertSame(self::ORGANISATION + ['email' => 'op@example.org'], $snapshot, 'An organisation, without the person halves.');
    $this->assertTrue(WorkspaceLifecycle::isValidOperatorSnapshot($snapshot));

    $person = ['organization' => '', 'given_name' => 'Jane', 'family_name' => 'Doe'] + self::ORGANISATION;
    $snapshot = OperatorDetails::snapshot($this->operatorWorkspace($person, 'op@example.org'));
    $this->assertSame([
      'given_name' => 'Jane',
      'family_name' => 'Doe',
      'address_line1' => 'Main street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'DE',
      'email' => 'op@example.org',
    ], $snapshot);
    $this->assertTrue(WorkspaceLifecycle::isValidOperatorSnapshot($snapshot));
    $this->assertSame(['given_name' => 'Jane', 'family_name' => 'Doe'], OperatorDetails::personName($this->operatorWorkspace($person, NULL)));

    $this->assertNull(OperatorDetails::snapshot($this->operatorWorkspace(['locality' => ''] + self::ORGANISATION, 'op@example.org')));
  }

  /**
   * Invisible, breaking and direction characters never make data complete.
   *
   * @covers ::missing
   * @covers ::name
   * @covers ::normalize
   * @covers ::isUsable
   * @covers ::isClean
   * @covers ::singleLine
   */
  public function testUnicodeRules(): void {
    $nbsp = "\u{00A0}";
    $zeroWidth = "\u{200B}";
    $refused = [
      'no-break spaces only' => ['organization' => $nbsp . $nbsp],
      'zero-width only' => ['organization' => $zeroWidth . "\u{2060}"],
      'line separator inside' => ['organization' => "Example\u{2028}Org"],
      'paragraph separator inside' => ['organization' => "Example\u{2029}Org"],
      'next line inside' => ['organization' => "Example\u{0085}Org"],
      'bidi override inside' => ['organization' => "Example \u{202E}groO"],
      'punctuation only' => ['organization' => "-'."],
    ];
    foreach ($refused as $label => $override) {
      $group = $this->operatorWorkspace($override + self::ORGANISATION, 'op@example.org');
      $this->assertSame(['name'], OperatorDetails::missing($group), $label);
      $this->assertNull(OperatorDetails::name($group), "$label: not shown as the name");
      $this->assertNull(OperatorDetails::snapshot($group), $label);
    }
    $this->assertSame(['address_line1'], OperatorDetails::missing($this->operatorWorkspace(['address_line1' => $zeroWidth] + self::ORGANISATION, 'op@example.org')));
    $this->assertSame(['locality'], OperatorDetails::missing($this->operatorWorkspace(['locality' => "Town\u{2028}x"] + self::ORGANISATION, 'op@example.org')));
    $this->assertSame(['postal_code'], OperatorDetails::missing($this->operatorWorkspace(['postal_code' => $nbsp] + self::ORGANISATION, 'op@example.org')));
    $this->assertSame(['email'], OperatorDetails::missing($this->operatorWorkspace(self::ORGANISATION, "op\u{202E}@example.org")));

    // Real names pass, with separators at the ends trimmed away.
    $accepted = [
      'accents and apostrophe' => ['organization' => '', 'given_name' => 'Zoë', 'family_name' => "O'Brien-Müller"],
      'trimmed no-break space' => ['organization' => $nbsp . 'Café Ünal' . $zeroWidth],
      'non-latin' => ['organization' => 'Ассоциация «Дерево»'],
      'digits in the street' => ['address_line1' => '1e Straat 12-14', 'locality' => "'s-Hertogenbosch"],
    ];
    foreach ($accepted as $label => $override) {
      $group = $this->operatorWorkspace($override + self::ORGANISATION, 'op@example.org');
      $this->assertSame([], OperatorDetails::missing($group), $label);
      $snapshot = OperatorDetails::snapshot($group);
      $this->assertNotNull($snapshot, $label);
      $this->assertTrue(WorkspaceLifecycle::isValidOperatorSnapshot($snapshot), $label);
    }
    $this->assertSame('Café Ünal', OperatorDetails::name($this->operatorWorkspace($accepted['trimmed no-break space'] + self::ORGANISATION, NULL)));

    // The same rule in isClean() (snapshot values) and singleLine() (mail).
    $this->assertTrue(OperatorDetails::isClean("O'Brien-Müller", TRUE));
    $this->assertFalse(OperatorDetails::isClean(' Doe', TRUE), 'Not normalized.');
    $this->assertFalse(OperatorDetails::isClean("Doe\u{202E}x", TRUE));
    $this->assertFalse(OperatorDetails::isClean('--', TRUE));
    $this->assertTrue(OperatorDetails::isClean('--', FALSE));
    $this->assertSame('Tree map Bcc: x', OperatorDetails::singleLine("{$nbsp}Tree map\u{2028}Bcc: x\u{202E}"));
    $this->assertSame('', OperatorDetails::normalize("\xC3\x28"), 'Invalid UTF-8.');
  }

}
