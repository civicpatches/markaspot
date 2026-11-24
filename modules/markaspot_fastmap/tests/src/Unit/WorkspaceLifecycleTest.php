<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the lifecycle record: switch, validation, record, summary, scrub.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceLifecycle
 * @group markaspot_fastmap
 */
class WorkspaceLifecycleTest extends UnitTestCase {

  private const REQUEST_TIME = 1_800_000_000;

  /**
   * Builds the service with a stored switch value.
   */
  private function lifecycle(mixed $switch = NULL, ?ModuleHandlerInterface $moduleHandler = NULL): WorkspaceLifecycle {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $key === 'lifecycle_enabled' ? $switch : NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('markaspot_fastmap.settings')->willReturn($config);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);
    return new WorkspaceLifecycle($factory, $time, $moduleHandler);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * The switch counts only on the self-service platform.
   *
   * @covers ::isEnabled
   */
  public function testSwitchCountsOnlyInSelfServiceMode(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static fn(string $module): bool => $module === 'markaspot_fastmap');
    foreach (['saas' => TRUE, NULL => TRUE, 'self_hosted' => FALSE] as $mode => $enabled) {
      new Settings(['markaspot_operating_mode' => $mode === '' ? NULL : $mode]);
      $label = $mode === '' ? 'mode unset' : $mode;
      $this->assertSame($enabled, $this->lifecycle(TRUE, $modules)->isEnabled(), "$label, with the module handler");
      $this->assertSame($enabled, $this->lifecycle(TRUE)->isEnabled(), "$label, without the module handler");
      $this->assertFalse($this->lifecycle(FALSE, $modules)->isEnabled(), "$label, switch off");
    }
  }

  /**
   * A valid acceptance as provisioning receives it.
   */
  private function acceptance(array $override = []): array {
    return $override + [
      'terms' => 'terms-2026.11',
      'dpa' => 'dpa_1.2',
      'declaration' => '1.0',
      'langcode' => 'en',
      'accepted_at' => self::REQUEST_TIME,
    ];
  }

  /**
   * Builds a group double whose record field holds a value.
   */
  private function group(?string $value, bool $hasField = TRUE): FieldableEntityInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn($value === NULL);
    $items->method('__get')->with('value')->willReturn($value);
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->with(WorkspaceLifecycle::FIELD)->willReturn($hasField);
    $group->method('get')->with(WorkspaceLifecycle::FIELD)->willReturn($items);
    return $group;
  }

  /**
   * Only a stored boolean TRUE turns the switch on.
   *
   * @covers ::isEnabled
   */
  public function testSwitchIsStrict(): void {
    $this->assertTrue($this->lifecycle(TRUE)->isEnabled());
    foreach ([NULL, FALSE, 1, '1', 'true', [TRUE]] as $value) {
      $this->assertFalse($this->lifecycle($value)->isEnabled(), var_export($value, TRUE));
    }
  }

  /**
   * The signup acceptance takes versions and language, the time is ours.
   *
   * @covers ::acceptanceFromSignup
   */
  public function testAcceptanceFromSignup(): void {
    $acceptance = $this->lifecycle()->acceptanceFromSignup([
      'terms' => 'v1',
      'dpa' => 'v2',
      'declaration' => 'v3',
      'langcode' => ' De ',
      'accepted_at' => 12,
      'uid' => 1,
      'extra' => 'dropped',
    ]);
    $this->assertSame([
      'terms' => 'v1',
      'dpa' => 'v2',
      'declaration' => 'v3',
      'langcode' => 'de',
      'accepted_at' => self::REQUEST_TIME,
    ], $acceptance);
    $this->assertTrue(WorkspaceLifecycle::isValidAcceptance($acceptance));
  }

  /**
   * Malformed versions and languages are refused.
   *
   * @covers ::acceptanceFromSignup
   * @covers ::isValidAcceptance
   */
  public function testInvalidAcceptanceIsRefused(): void {
    $base = ['terms' => 'v1', 'dpa' => 'v2', 'declaration' => 'v3', 'langcode' => 'en'];
    $cases = [
      NULL,
      'v1',
      [],
      ['terms' => ''] + $base,
      ['terms' => str_repeat('x', 33)] + $base,
      ['dpa' => 'a b'] + $base,
      ['dpa' => 'ä'] + $base,
      ['declaration' => "1\n"] + $base,
      ['declaration' => 1.0] + $base,
      ['langcode' => ''] + $base,
      ['langcode' => 'english'] + $base,
      ['langcode' => 'en_gb'] + $base,
      ['langcode' => 'e n'] + $base,
      array_diff_key($base, ['declaration' => TRUE]),
    ];
    foreach ($cases as $index => $input) {
      $this->assertNull($this->lifecycle()->acceptanceFromSignup($input), "case $index");
    }
    $this->assertSame(32, strlen((string) $this->lifecycle()->acceptanceFromSignup(['terms' => str_repeat('x', 32)] + $base)['terms']));

    $this->assertFalse(WorkspaceLifecycle::isValidAcceptance(NULL));
    $this->assertFalse(WorkspaceLifecycle::isValidAcceptance($this->acceptance(['accepted_at' => 0])));
    $this->assertFalse(WorkspaceLifecycle::isValidAcceptance($this->acceptance(['accepted_at' => '1800000000'])));
    $this->assertFalse(WorkspaceLifecycle::isValidAcceptance(array_diff_key($this->acceptance(), ['langcode' => TRUE])));
    $this->assertTrue(WorkspaceLifecycle::isValidAcceptance($this->acceptance()));
  }

  /**
   * The record validator.
   *
   * @covers ::isValidRecord
   * @covers ::parse
   * @covers ::buildRecord
   */
  public function testRecordValidator(): void {
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    $this->assertSame([
      'v' => 1,
      'acceptance' => [
        'terms' => 'terms-2026.11',
        'dpa' => 'dpa_1.2',
        'declaration' => '1.0',
        'langcode' => 'en',
        'accepted_at' => self::REQUEST_TIME,
        'uid' => 7,
      ],
      'opening' => NULL,
    ], $record);
    $this->assertSame(
      '{"v":1,"acceptance":{"terms":"terms-2026.11","dpa":"dpa_1.2","declaration":"1.0","langcode":"en","accepted_at":1800000000,"uid":7},"opening":null}',
      json_encode($record)
    );
    $this->assertSame($record, WorkspaceLifecycle::parse(json_encode($record)));

    $invalid = [
      'v2' => ['v' => 2] + $record,
      'v as string' => ['v' => '1'] + $record,
      'no acceptance' => ['acceptance' => NULL] + $record,
      'no dpa' => ['acceptance' => array_diff_key($record['acceptance'], ['dpa' => TRUE])] + $record,
      'zero time' => ['acceptance' => ['accepted_at' => 0] + $record['acceptance']] + $record,
      'negative time' => ['acceptance' => ['accepted_at' => -1] + $record['acceptance']] + $record,
    ];
    foreach ($invalid as $label => $candidate) {
      $this->assertFalse(WorkspaceLifecycle::isValidRecord($candidate), $label);
      $this->assertNull(WorkspaceLifecycle::parse(json_encode($candidate)), $label);
    }
    foreach ([NULL, '', 'null', '[]', '{', '"x"'] as $json) {
      $this->assertNull(WorkspaceLifecycle::parse($json));
    }

    // A scrubbed record (account zeroed) stays a valid record.
    $this->assertTrue(WorkspaceLifecycle::isValidRecord((array) WorkspaceLifecycle::scrub($record)));
  }

  /**
   * A record needs a valid acceptance and a real account.
   *
   * @covers ::buildRecord
   */
  public function testBuildRecordRefusesInvalidInput(): void {
    foreach ([[$this->acceptance(), 0], [$this->acceptance(['dpa' => '']), 7]] as [$acceptance, $uid]) {
      try {
        WorkspaceLifecycle::buildRecord($acceptance, $uid);
        $this->fail('buildRecord() accepted invalid input.');
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Reading and writing on the entity.
   *
   * @covers ::read
   * @covers ::writeAcceptance
   * @covers ::hasRecordField
   */
  public function testReadAndWrite(): void {
    $lifecycle = $this->lifecycle(TRUE);
    $this->assertNull($lifecycle->read($this->group(NULL)));
    $this->assertNull($lifecycle->read($this->group('not json')));
    $this->assertNull($lifecycle->read($this->group('x', FALSE)));
    $this->assertFalse($lifecycle->hasRecordField($this->group(NULL, FALSE)));

    $written = NULL;
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->willReturn(TRUE);
    $group->expects($this->once())->method('set')
      ->with(WorkspaceLifecycle::FIELD, $this->callback('is_string'))
      ->willReturnCallback(function (string $field, string $value) use (&$written, $group) {
        $written = $value;
        return $group;
      });
    $lifecycle->writeAcceptance($group, $this->acceptance(), 9);
    $this->assertSame(9, WorkspaceLifecycle::parse($written)['acceptance']['uid']);
    $this->assertSame(WorkspaceLifecycle::parse($written), $lifecycle->read($this->group($written)));

    $noField = $this->createMock(FieldableEntityInterface::class);
    $noField->method('hasField')->willReturn(FALSE);
    $noField->expects($this->never())->method('set');
    $this->expectException(\RuntimeException::class);
    $lifecycle->writeAcceptance($noField, $this->acceptance(), 9);
  }

  /**
   * The settings summary exists only for a valid record.
   *
   * @covers ::settingsSummary
   */
  public function testSettingsSummaryOnlyForValidRecord(): void {
    $lifecycle = $this->lifecycle();
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(['langcode' => 'de']), 7);
    $this->assertSame([
      'accepted_at' => self::REQUEST_TIME,
      'versions' => ['terms' => 'terms-2026.11', 'dpa' => 'dpa_1.2', 'declaration' => '1.0'],
      'langcode' => 'de',
      'open' => FALSE,
      'opened_at' => NULL,
    ], $lifecycle->settingsSummary($this->group(json_encode($record))));
    $open = ['opening' => $this->opening()] + $record;
    $this->assertSame(
      ['open' => TRUE, 'opened_at' => 1_800_001_000],
      array_intersect_key(
        (array) $lifecycle->settingsSummary($this->group(json_encode($open))),
        ['open' => 1, 'opened_at' => 1],
      ),
    );
    // Nothing of the snapshot or the accounts reaches the summary.
    $encoded = (string) json_encode($lifecycle->settingsSummary($this->group(json_encode($open))));
    foreach (['Example Org', 'Main street', 'operator@example.org', '"uid"', 'revision'] as $needle) {
      $this->assertStringNotContainsString($needle, $encoded);
    }

    $this->assertNull($lifecycle->settingsSummary($this->group(NULL)));
    $this->assertNull($lifecycle->settingsSummary($this->group(NULL, FALSE)));
    $this->assertNull($lifecycle->settingsSummary($this->group('{"v":1,"acceptance":{},"opening":null}')));
    // The summary does not depend on the switch: records stay data.
    $this->assertNotNull($this->lifecycle(FALSE)->settingsSummary($this->group(json_encode($record))));
  }

  /**
   * Open, not open, or neither (no valid record).
   *
   * @covers ::hasValidRecord
   * @covers ::isOpen
   * @covers ::isNotOpen
   */
  public function testOpenPredicates(): void {
    $lifecycle = $this->lifecycle();
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    $notOpen = $this->group(json_encode($record));
    $open = $this->group(json_encode(['opening' => $this->opening()] + $record));
    $cases = [
      'not open' => [$notOpen, TRUE, FALSE, TRUE],
      'open' => [$open, TRUE, TRUE, FALSE],
      'prior opening with hold' => [
        $this->group(json_encode(['opening' => $this->opening(), 'closed_by_support' => TRUE] + $record)),
        TRUE,
        FALSE,
        TRUE,
      ],
      'malformed hold must be cleared explicitly' => [
        $this->group(json_encode(['opening' => $this->opening(), 'closed_by_support' => NULL] + $record)),
        TRUE,
        FALSE,
        TRUE,
      ],
      // A malformed opening keeps the map closed.
      'malformed opening' => [
        $this->group(json_encode(['opening' => ['at' => 1800001000]] + $record)),
        TRUE,
        FALSE,
        TRUE,
      ],
      'opening without operator' => [
        $this->group(json_encode(['opening' => array_diff_key($this->opening(), ['operator' => 1])] + $record)),
        TRUE,
        FALSE,
        TRUE,
      ],
      'no record' => [$this->group(NULL), FALSE, FALSE, FALSE],
      'no field' => [$this->group(NULL, FALSE), FALSE, FALSE, FALSE],
      'invalid record' => [$this->group('{"v":1,"acceptance":{},"opening":null}'), FALSE, FALSE, FALSE],
      'opening not an array' => [$this->group(json_encode(['opening' => 'yes'] + $record)), TRUE, FALSE, TRUE],
    ];
    foreach ($cases as $label => [$group, $valid, $isOpen, $isNotOpen]) {
      $this->assertSame($valid, $lifecycle->hasValidRecord($group), $label);
      $this->assertSame($isOpen, $lifecycle->isOpen($group), $label);
      $this->assertSame($isNotOpen, $lifecycle->isNotOpen($group), $label);
    }
    // Records are data: the switch plays no part.
    $this->assertTrue($this->lifecycle(FALSE)->isNotOpen($notOpen));
  }

  /**
   * A valid opening section, as the opening service writes it.
   */
  private function opening(array $override = [], ?array $operator = NULL): array {
    return $override + [
      'opened_at' => 1_800_001_000,
      'uid' => 7,
      'revision_id' => 12,
      'operator' => $operator ?? [
        'organization' => 'Example Org',
        'address_line1' => 'Main street 1',
        'postal_code' => '12345',
        'locality' => 'Town',
        'country_code' => 'DE',
        'email' => 'operator@example.org',
      ],
    ];
  }

  /**
   * A person as operator.
   */
  private function personOperator(): array {
    return [
      'given_name' => 'Jane',
      'family_name' => 'Doe',
      'address_line1' => 'Main street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'NL',
      'email' => 'jane@example.org',
    ];
  }

  /**
   * The opening validator: exact keys, valid values, organisation or person.
   *
   * @covers ::isValidOpening
   * @covers ::isValidOperatorSnapshot
   */
  public function testOpeningValidator(): void {
    $this->assertTrue(WorkspaceLifecycle::isValidOpening($this->opening()));
    $this->assertTrue(WorkspaceLifecycle::isValidOpening($this->opening([], $this->personOperator())));
    $this->assertTrue(WorkspaceLifecycle::isValidOpening($this->opening(['uid' => 0])), 'A scrubbed account stays valid.');

    $operator = $this->opening()['operator'];
    $invalid = [
      'null' => NULL,
      'string' => 'open',
      'old placeholder' => ['at' => 1800001000],
      'extra key' => $this->opening() + ['note' => 'x'],
      'missing revision' => array_diff_key($this->opening(), ['revision_id' => 1]),
      'zero time' => $this->opening(['opened_at' => 0]),
      'time as string' => $this->opening(['opened_at' => '1800001000']),
      'negative account' => $this->opening(['uid' => -1]),
      'zero revision' => $this->opening(['revision_id' => 0]),
      'organisation and person' => $this->opening([], $operator + ['given_name' => 'Jane', 'family_name' => 'Doe']),
      'half a person' => $this->opening([], array_diff_key($this->personOperator(), ['family_name' => 1])),
      'no name' => $this->opening([], array_diff_key($operator, ['organization' => 1])),
      'extra operator key' => $this->opening([], $operator + ['phone' => '123']),
      'empty street' => $this->opening([], ['address_line1' => ' '] + $operator),
      'line break' => $this->opening([], ['locality' => "Town\nBcc: x@example.org"] + $operator),
      'lower-case country' => $this->opening([], ['country_code' => 'de'] + $operator),
      'country name' => $this->opening([], ['country_code' => 'Germany'] + $operator),
      'invalid e-mail' => $this->opening([], ['email' => 'not an address'] + $operator),
      'number as value' => $this->opening([], ['postal_code' => 12345] + $operator),
      'too long' => $this->opening([], ['organization' => str_repeat('x', 256)] + $operator),
      'no-break spaces only' => $this->opening([], ['organization' => "\u{00A0}\u{00A0}"] + $operator),
      'zero-width street' => $this->opening([], ['address_line1' => "\u{200B}"] + $operator),
      'line separator inside' => $this->opening([], ['locality' => "Town\u{2028}x"] + $operator),
      'bidi override inside' => $this->opening([], ['organization' => "Example \u{202E}groO"] + $operator),
      'leading space' => $this->opening([], ['organization' => ' Example Org'] + $operator),
      'name without letters' => $this->opening([], ['organization' => '-'] + $operator),
    ];
    foreach ($invalid as $label => $opening) {
      $this->assertFalse(WorkspaceLifecycle::isValidOpening($opening), $label);
    }
  }

  /**
   * Writing the opening: once, on a valid record, with complete data.
   *
   * @covers ::writeOpening
   * @covers ::opening
   */
  public function testWriteOpening(): void {
    $lifecycle = $this->lifecycle();
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    $operator = $this->opening()['operator'];

    $written = NULL;
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->willReturn(TRUE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn(FALSE);
    $items->method('__get')->with('value')->willReturn(json_encode($record));
    $group->method('get')->willReturn($items);
    $group->expects($this->once())->method('set')
      ->willReturnCallback(function (string $field, string $value) use (&$written, $group) {
        $written = $value;
        return $group;
      });

    $opening = $lifecycle->writeOpening($group, 9, $operator, 41);
    $this->assertSame([
      'opened_at' => self::REQUEST_TIME,
      'uid' => 9,
      'revision_id' => 41,
      'operator' => $operator,
    ], $opening);
    $stored = WorkspaceLifecycle::parse($written);
    $this->assertSame($opening, $stored['opening']);
    $this->assertSame($record['acceptance'], $stored['acceptance'], 'The acceptance is kept.');
    $this->assertTrue($lifecycle->isOpen($this->group($written)));
    $this->assertSame($opening, $lifecycle->opening($this->group($written)));
    $this->assertNull($lifecycle->opening($this->group(json_encode($record))));

    // Refused: already open, no record, invalid input.
    $refusals = [
      'already open' => [$written, 9, $operator, 41, \LogicException::class],
      'no record' => [NULL, 9, $operator, 41, \LogicException::class],
      'no account' => [json_encode($record), 0, $operator, 41, \InvalidArgumentException::class],
      'no revision' => [json_encode($record), 9, $operator, 0, \InvalidArgumentException::class],
      'incomplete operator' => [
        json_encode($record),
        9,
        array_diff_key($operator, ['email' => 1]),
        41,
        \InvalidArgumentException::class,
      ],
    ];
    foreach ($refusals as $label => [$value, $uid, $snapshot, $revision, $exception]) {
      $target = $this->createMock(FieldableEntityInterface::class);
      $target->method('hasField')->willReturn(TRUE);
      $targetItems = $this->createMock(FieldItemListInterface::class);
      $targetItems->method('isEmpty')->willReturn($value === NULL);
      $targetItems->method('__get')->with('value')->willReturn($value);
      $target->method('get')->willReturn($targetItems);
      $target->expects($this->never())->method('set');
      try {
        $lifecycle->writeOpening($target, $uid, $snapshot, $revision);
        $this->fail("$label: no refusal");
      }
      catch (\LogicException | \InvalidArgumentException $e) {
        $this->assertInstanceOf($exception, $e, $label);
      }
    }
  }

  /**
   * Support closure holds the map closed and keeps the acceptance.
   *
   * @covers ::setClosedBySupport
   */
  public function testSupportClosure(): void {
    $lifecycle = $this->lifecycle();
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    foreach (['valid opening' => $this->opening(), 'malformed opening' => ['at' => 1], 'pending' => NULL] as $label => $opening) {
      $written = NULL;
      $group = $this->createMock(FieldableEntityInterface::class);
      $group->method('hasField')->willReturn(TRUE);
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('isEmpty')->willReturn(FALSE);
      $items->method('__get')->with('value')->willReturn(json_encode(array_merge($record, [
        'opening' => $opening,
        'auto_open_pending' => TRUE,
      ])));
      $group->method('get')->willReturn($items);
      $group->expects($this->once())->method('set')->willReturnCallback(function (string $field, string $value) use (&$written, $group) {
        $written = $value;
        return $group;
      });
      $lifecycle->setClosedBySupport($group, TRUE);
      $closed = WorkspaceLifecycle::parse($written);
      $this->assertSame($record + ['closed_by_support' => TRUE], $closed, $label);
      $this->assertTrue($lifecycle->isNotOpen($this->group($written)), $label);
      $scrubbed = WorkspaceLifecycle::scrub($closed);
      $this->assertTrue($scrubbed['closed_by_support']);
      $this->assertTrue(WorkspaceLifecycle::isScrubbed($scrubbed));
    }

    $this->expectException(\LogicException::class);
    $lifecycle->setClosedBySupport($this->group(NULL), TRUE);
  }

  /**
   * An opening is scrubbed to fixed values and stays open and valid.
   *
   * @covers ::scrub
   * @covers ::isScrubbed
   */
  public function testScrubOpening(): void {
    $lifecycle = $this->lifecycle();
    foreach (['organisation' => NULL, 'person' => $this->personOperator()] as $label => $operator) {
      $record = ['opening' => $this->opening([], $operator)] + WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
      $this->assertFalse(WorkspaceLifecycle::isScrubbed($record), $label);
      $scrubbed = WorkspaceLifecycle::scrub($record);
      $this->assertNotNull($scrubbed, $label);
      $this->assertTrue(WorkspaceLifecycle::isScrubbed($scrubbed), $label);
      $this->assertSame($scrubbed, WorkspaceLifecycle::scrub($scrubbed), "$label: idempotent");

      // A scrubbed copy behaves like the original: valid, open, same time.
      $group = $this->group(json_encode($scrubbed));
      $this->assertTrue($lifecycle->isOpen($group), $label);
      $this->assertSame(1_800_001_000, $lifecycle->settingsSummary($group)['opened_at'], $label);
      $this->assertSame(0, $scrubbed['opening']['uid'], $label);
      $this->assertSame(0, $scrubbed['acceptance']['uid'], $label);
      $this->assertSame(12, $scrubbed['opening']['revision_id'], $label);
      $this->assertSame(array_keys($record['opening']['operator']), array_keys($scrubbed['opening']['operator']), "$label: same shape");
      $this->assertSame($record['opening']['operator']['country_code'], $scrubbed['opening']['operator']['country_code'], "$label: country kept");

      $encoded = (string) json_encode($scrubbed);
      foreach (['Example Org', 'Jane', 'Doe', 'Main street', '12345', 'Town', '@example.org'] as $needle) {
        $this->assertStringNotContainsString($needle, $encoded, "$label: $needle removed");
      }
    }

    $withoutOperator = $this->opening(['operator' => NULL]);
    $record = ['opening' => $withoutOperator] + WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    $scrubbed = WorkspaceLifecycle::scrub($record);
    $this->assertTrue(WorkspaceLifecycle::isScrubbed($scrubbed));
    $this->assertNull($scrubbed['opening']['operator']);
    $this->assertSame(0, $scrubbed['opening']['uid']);
    $this->assertTrue($lifecycle->isOpen($this->group(json_encode($scrubbed))));

    // Partly scrubbed is not scrubbed: a real value left, or a real account.
    $clean = WorkspaceLifecycle::scrub(['opening' => $this->opening()] + WorkspaceLifecycle::buildRecord($this->acceptance(), 7));
    $left = $clean;
    $left['opening']['operator']['email'] = 'operator@example.org';
    $this->assertFalse(WorkspaceLifecycle::isScrubbed($left));
    $account = $clean;
    $account['opening']['uid'] = 7;
    $this->assertFalse(WorkspaceLifecycle::isScrubbed($account));
    // Same values in another key order are clean.
    $reordered = $clean;
    $reordered['opening'] = array_reverse($clean['opening'], TRUE);
    $reordered['opening']['operator'] = array_reverse($clean['opening']['operator'], TRUE);
    $this->assertTrue(WorkspaceLifecycle::isScrubbed($reordered));
  }

  /**
   * Scrubbing rebuilds the known shape with the account zeroed.
   *
   * @covers ::scrub
   * @covers ::isScrubbed
   */
  public function testScrub(): void {
    $record = WorkspaceLifecycle::buildRecord($this->acceptance(), 7);
    $this->assertFalse(WorkspaceLifecycle::isScrubbed($record));
    $scrubbed = WorkspaceLifecycle::scrub($record);
    $this->assertSame(0, $scrubbed['acceptance']['uid']);
    $this->assertSame($record['acceptance']['terms'], $scrubbed['acceptance']['terms']);
    $this->assertTrue(WorkspaceLifecycle::isScrubbed($scrubbed));
    $this->assertTrue(WorkspaceLifecycle::isValidRecord($scrubbed));
    // Key order does not matter, values and keys do.
    $this->assertTrue(WorkspaceLifecycle::isScrubbed(array_reverse($scrubbed, TRUE)));
    $this->assertSame($scrubbed, WorkspaceLifecycle::scrub($scrubbed));
  }

  /**
   * Anything outside the known shape cannot be rebuilt and is not clean.
   *
   * @covers ::scrub
   * @covers ::isScrubbed
   */
  public function testScrubIsStrict(): void {
    $clean = WorkspaceLifecycle::scrub(WorkspaceLifecycle::buildRecord($this->acceptance(), 7));
    $acceptance = $clean['acceptance'];
    $cases = [
      'string acceptance' => ['acceptance' => 'Jane Doe, Main Street 1'] + $clean,
      'null acceptance' => ['acceptance' => NULL] + $clean,
      'extra top-level key' => $clean + ['note' => 'Jane Doe'],
      'missing opening' => array_diff_key($clean, ['opening' => TRUE]),
      'malformed opening' => ['opening' => ['name' => 'Jane Doe']] + $clean,
      'opening with an extra key' => ['opening' => $this->opening() + ['name' => 'Jane Doe']] + $clean,
      'operator with an extra key' => ['opening' => $this->opening([], $this->opening()['operator'] + ['phone' => '0123'])] + $clean,
      'extra acceptance key' => ['acceptance' => $acceptance + ['mail' => 'jane@example.test']] + $clean,
      'missing uid' => ['acceptance' => array_diff_key($acceptance, ['uid' => TRUE])] + $clean,
      'uid as string' => ['acceptance' => ['uid' => '0'] + $acceptance] + $clean,
      'negative uid' => ['acceptance' => ['uid' => -1] + $acceptance] + $clean,
      'missing langcode' => ['acceptance' => array_diff_key($acceptance, ['langcode' => TRUE])] + $clean,
      'invalid version' => ['acceptance' => ['terms' => 'Jane Doe'] + $acceptance] + $clean,
      'invalid time' => ['acceptance' => ['accepted_at' => 0] + $acceptance] + $clean,
      'version 2' => ['v' => 2] + $clean,
    ];
    foreach ($cases as $label => $record) {
      $this->assertNull(WorkspaceLifecycle::scrub($record), $label);
      $this->assertFalse(WorkspaceLifecycle::isScrubbed($record), $label);
    }
    foreach ([NULL, 'x', 0, []] as $value) {
      $this->assertFalse(WorkspaceLifecycle::isScrubbed($value));
    }
  }

  /**
   * The shipped config keeps the switch off and the field closed.
   *
   * Read from this checkout, relative to the test file, not from the module
   * path the site resolves.
   */
  public function testShippedConfigKeepsFieldClosed(): void {
    $module = dirname(__DIR__, 3);
    $settings = Yaml::decode((string) file_get_contents($module . '/config/install/markaspot_fastmap.settings.yml'));
    $this->assertFalse($settings['lifecycle_enabled']);

    $schema = Yaml::decode((string) file_get_contents($module . '/config/schema/markaspot_fastmap.schema.yml'));
    $this->assertSame('boolean', $schema['markaspot_fastmap.settings']['mapping']['lifecycle_enabled']['type']);

    $storage = Yaml::decode((string) file_get_contents($module . '/config/optional/field.storage.group.field_lifecycle_record.yml'));
    $this->assertSame('string_long', $storage['type']);
    $this->assertSame(1, $storage['cardinality']);
    $this->assertFalse($storage['translatable']);
    $this->assertSame('custom', $storage['third_party_settings']['field_permissions']['permission_type']);

    $field = Yaml::decode((string) file_get_contents($module . '/config/optional/field.field.group.jur.field_lifecycle_record.yml'));
    $this->assertSame('jur', $field['bundle']);
    $this->assertFalse($field['required']);

    $resource = Yaml::decode((string) file_get_contents(dirname($module) . '/markaspot_nuxt/config/optional/jsonapi_extras.jsonapi_resource_config.group--jur.yml'));
    $this->assertSame([
      'disabled' => TRUE,
      'fieldName' => WorkspaceLifecycle::FIELD,
      'publicName' => WorkspaceLifecycle::FIELD,
      'enhancer' => ['id' => ''],
    ], $resource['resourceFields'][WorkspaceLifecycle::FIELD]);

    // No role ships a permission on the field.
    foreach (glob(dirname($module, 2) . '/{config,modules/*/config}/{install,optional}/user.role.*.yml', GLOB_BRACE) ?: [] as $file) {
      $this->assertStringNotContainsString('field_lifecycle_record', (string) file_get_contents($file), basename($file));
    }
  }

}
