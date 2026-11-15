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
    ], $lifecycle->settingsSummary($this->group(json_encode($record))));

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
    $open = $this->group(json_encode(['opening' => ['at' => 1800001000]] + $record));
    $cases = [
      'not open' => [$notOpen, TRUE, FALSE, TRUE],
      'open' => [$open, TRUE, TRUE, FALSE],
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
      'opening present' => ['opening' => ['name' => 'Jane Doe']] + $clean,
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
