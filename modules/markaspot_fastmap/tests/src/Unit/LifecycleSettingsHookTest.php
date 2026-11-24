<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\markaspot_fastmap\Service\LegalNoticeGenerator;
use Psr\Log\NullLogger;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Tests\UnitTestCase;

/**
 * Tests where the settings hooks put the lifecycle record, and where not.
 *
 * Runs the hook functions of this checkout's module file.
 *
 * @group markaspot_fastmap
 */
class LifecycleSettingsHookTest extends UnitTestCase {

  use OperatorWorkspaceTrait;

  private const RECORD = '{"v":1,"acceptance":{"terms":"t-2026","dpa":"d-2026","declaration":"o-1","langcode":"de","accepted_at":1800000000,"uid":4242},"opening":null}';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheTags')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(-1);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $lifecycle = new WorkspaceLifecycle($factory, $this->createMock(TimeInterface::class));
    $container = new ContainerBuilder();
    $container->set('markaspot_fastmap.workspace_lifecycle', $lifecycle);
    $container->set('config.factory', $factory);
    $container->set('markaspot_fastmap.legal_notice_generator', new LegalNoticeGenerator(
      $this->createMock(TwigEnvironment::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      new NullLogger(),
    ));
    // The public alter asks the gate for the open state (self-hosted here:
    // no open key; WorkspaceLifecycleGateTest covers the state itself).
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $container->set('markaspot_fastmap.workspace_lifecycle_gate', new WorkspaceLifecycleGate(
      $lifecycle,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      new RequestStack(),
    ));
    \Drupal::setContainer($container);
  }

  /**
   * Builds a workspace double that only has the record field.
   */
  private function workspace(?string $record): GroupInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn($record === NULL);
    $items->method('__get')->with('value')->willReturn($record);
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === WorkspaceLifecycle::FIELD);
    $group->method('get')->willReturnCallback(fn(string $name) => $name === WorkspaceLifecycle::FIELD
      ? $items
      : $this->createMock(FieldItemListInterface::class));
    return $group;
  }

  /**
   * The dashboard settings carry the lifecycle object for a valid record.
   */
  public function testDashboardSettingsCarryLifecycleForValidRecord(): void {
    $data = ['jurisdiction_id' => 9];
    markaspot_fastmap_markaspot_nuxt_tenant_general_settings_alter($data, $this->workspace(self::RECORD));

    $this->assertSame([
      'jurisdiction_id' => 9,
      'lifecycle' => [
        'accepted_at' => 1800000000,
        'versions' => ['terms' => 't-2026', 'dpa' => 'd-2026', 'declaration' => 'o-1'],
        'langcode' => 'de',
        'open' => FALSE,
        'opened_at' => NULL,
        'hasOperator' => FALSE,
        // This double has no operator fields at all.
        'operator_missing' => ['name', 'address_line1', 'postal_code', 'locality', 'country_code', 'email'],
      ],
      'hasOperator' => FALSE,
      'legalNotice' => \Drupal::service('markaspot_fastmap.legal_notice_generator')->platformNotice(),
    ], $data);
    // The account reference is not part of it.
    $this->assertStringNotContainsString('4242', (string) json_encode($data));
  }

  /**
   * No lifecycle object for every other workspace.
   */
  public function testDashboardSettingsOmitLifecycleOtherwise(): void {
    foreach ([NULL, '', 'not json', '{"v":1,"acceptance":{"terms":"t"},"opening":null}', '{"v":2}'] as $record) {
      $data = ['jurisdiction_id' => 9];
      markaspot_fastmap_markaspot_nuxt_tenant_general_settings_alter($data, $this->workspace($record));
      $this->assertSame(['jurisdiction_id' => 9], $data, var_export($record, TRUE));
    }
    $data = ['jurisdiction_id' => 9];
    markaspot_fastmap_markaspot_nuxt_tenant_general_settings_alter($data, NULL);
    $this->assertSame(['jurisdiction_id' => 9], $data);
  }

  /**
   * The public settings response never carries the record.
   */
  public function testPublicSettingsNeverCarryRecord(): void {
    $settings = ['jurisdiction' => ['name' => 'Map']];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->workspace(self::RECORD));

    $this->assertArrayNotHasKey('lifecycle', $settings);
    $this->assertArrayNotHasKey('lifecycle', $settings['jurisdiction']);
    $encoded = (string) json_encode($settings);
    foreach (['t-2026', 'd-2026', 'o-1', '4242', '1800000000', WorkspaceLifecycle::FIELD] as $needle) {
      $this->assertStringNotContainsString($needle, $encoded);
    }
  }

  /**
   * A workspace double with a record and operator data, for the alters.
   */
  private function operatorRecordWorkspace(?string $record, ?array $address, ?string $email): GroupInterface {
    $group = $this->operatorWorkspace($address, $email, TRUE, [
      WorkspaceLifecycle::FIELD => $record,
      'field_legal_notice' => '<p>Notice</p>',
    ]);
    $group->method('id')->willReturn(3);
    $group->method('getCacheTags')->willReturn(['group:3']);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    return $group;
  }

  /**
   * Switches the platform to self-service for the public alter.
   */
  private function selfService(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Dashboard settings: missing operator parts and the person name halves.
   */
  public function testDashboardSettingsCarryOperatorParts(): void {
    $address = [
      'organization' => '',
      'given_name' => 'Jane',
      'family_name' => '',
      'address_line1' => 'Main street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'NL',
    ];
    $data = [
      'jurisdiction_id' => 3,
      'field_jurisdiction_address' => array_diff_key($address, ['given_name' => 1, 'family_name' => 1]),
    ];
    markaspot_fastmap_markaspot_nuxt_tenant_general_settings_alter($data, $this->operatorRecordWorkspace(self::RECORD, $address, 'jane@example.org'));
    $this->assertSame(['family_name'], $data['lifecycle']['operator_missing']);
    $this->assertSame('Jane', $data['field_jurisdiction_address']['given_name']);
    $this->assertSame('', $data['field_jurisdiction_address']['family_name']);

    // Without a record the address keeps today's keys.
    $data = ['jurisdiction_id' => 3, 'field_jurisdiction_address' => ['organization' => '']];
    markaspot_fastmap_markaspot_nuxt_tenant_general_settings_alter($data, $this->operatorRecordWorkspace(NULL, $address, 'jane@example.org'));
    $this->assertSame(['jurisdiction_id' => 3, 'field_jurisdiction_address' => ['organization' => '']], $data);
  }

  /**
   * The record of an open map.
   */
  private function openRecord(): string {
    return (string) json_encode(['opening' => [
      'opened_at' => 1800001000,
      'uid' => 7,
      'revision_id' => 2,
      'operator' => [
        'organization' => 'Example Org',
        'address_line1' => 'Main street 1',
        'postal_code' => '12345',
        'locality' => 'Town',
        'country_code' => 'DE',
        'email' => 'op@example.org',
      ],
    ]] + json_decode(self::RECORD, TRUE));
  }

  /**
   * Before opening, the public settings carry no operator block at all.
   */
  public function testNoOperatorBlockBeforeOpening(): void {
    $this->selfService();
    $address = [
      'organization' => '',
      'given_name' => 'Jane',
      'family_name' => 'Doe',
      'address_line1' => 'Home street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'DE',
    ];
    $settings = ['jurisdiction' => [], 'operator' => [
      'name' => 'Tree map',
      'email' => 'jane@example.org',
      'address' => $address,
      'legalNotice' => '<p>Jane Doe, Home street 1</p>',
    ]];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->operatorRecordWorkspace(self::RECORD, $address, 'jane@example.org'));
    $this->assertArrayNotHasKey('operator', $settings);
    $this->assertFalse($settings['jurisdiction']['open']);
    foreach (['Jane', 'Doe', 'Home street', 'jane@example.org'] as $needle) {
      $this->assertStringNotContainsString($needle, (string) json_encode($settings), $needle);
    }
  }

  /**
   * Public settings of an open record workspace name the operator, not the map.
   */
  public function testPublicOperatorNameForRecordWorkspace(): void {
    $this->selfService();
    $address = [
      'address_line1' => 'Main street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'DE',
    ];
    // The controller puts the map name there; the address block as built.
    $controllerOperator = ['name' => 'Tree map', 'email' => 'op@example.org', 'address' => ['organization' => '']];
    $cases = [
      'organisation' => [['organization' => 'Example Org'] + $address, 'Example Org'],
      'person' => [['organization' => '', 'given_name' => 'Jane', 'family_name' => 'Doe'] + $address, 'Jane Doe'],
      'neither' => [['organization' => ''] + $address, NULL],
    ];
    foreach ($cases as $label => [$operatorAddress, $expected]) {
      $settings = ['jurisdiction' => ['name' => 'Tree map'], 'operator' => $controllerOperator];
      markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->operatorRecordWorkspace($this->openRecord(), $operatorAddress, 'op@example.org'));
      if ($expected === NULL) {
        $this->assertArrayNotHasKey('operator', $settings, $label);
        $this->assertFalse($settings['hasOperator']);
        $this->assertSame('platform', $settings['legalNotice']['source']);
        $this->assertSame('https://www.civicpatches.de/impressum', $settings['legalNotice']['url']);
        continue;
      }
      else {
        $this->assertSame($expected, $settings['operator']['name'], $label);
      }
      $this->assertStringNotContainsString('"name":"Tree map"', (string) json_encode($settings['operator']), "$label: never the map name");
      $this->assertSame('op@example.org', $settings['operator']['email'], "$label: the rest stays");
      $this->assertTrue($settings['hasOperator']);
      $this->assertSame(['source' => 'operator', 'html' => '<p>Notice</p>'], $settings['legalNotice']);
    }

    // An operator block with nothing but the map name disappears.
    $settings = ['jurisdiction' => [], 'operator' => ['name' => 'Tree map']];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->operatorRecordWorkspace($this->openRecord(), NULL, NULL));
    $this->assertArrayNotHasKey('operator', $settings);
  }

  /**
   * Every other workspace keeps today's operator block.
   */
  public function testPublicOperatorUnchangedWithoutRecord(): void {
    $operator = ['name' => 'Tree map', 'email' => 'op@example.org', 'address' => ['organization' => 'Example Org']];
    foreach (['self-service, no record' => 'saas', 'municipality with a record' => 'self_hosted'] as $label => $mode) {
      new Settings(['markaspot_operating_mode' => $mode]);
      $record = $mode === 'saas' ? NULL : self::RECORD;
      $settings = ['jurisdiction' => [], 'operator' => $operator];
      markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->operatorRecordWorkspace($record, ['organization' => 'Example Org'], 'op@example.org'));
      $this->assertSame($operator, $settings['operator'], $label);
      $this->assertArrayNotHasKey('open', $settings['jurisdiction'], $label);
    }
  }

  /**
   * The public settings of a map that is not open are cached briefly.
   */
  public function testMaxAgeCapWhileNotOpen(): void {
    $this->selfService();
    $open = json_encode(['opening' => [
      'opened_at' => 1800001000,
      'uid' => 7,
      'revision_id' => 2,
      'operator' => [
        'organization' => 'Example Org',
        'address_line1' => 'Main street 1',
        'postal_code' => '12345',
        'locality' => 'Town',
        'country_code' => 'DE',
        'email' => 'op@example.org',
      ],
    ]] + json_decode(self::RECORD, TRUE));
    $cases = [
      'not open' => [self::RECORD, FALSE, WorkspaceLifecycleGate::NOT_OPEN_SETTINGS_MAX_AGE],
      'open' => [$open, TRUE, 3600],
      'no record' => [NULL, NULL, 3600],
    ];
    foreach ($cases as $label => [$record, $state, $maxAge]) {
      $metadata = (new CacheableMetadata())->setCacheMaxAge(3600);
      $settings = ['jurisdiction' => []];
      markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->operatorRecordWorkspace($record, NULL, NULL), $metadata);
      $this->assertSame($state, $settings['jurisdiction']['open'] ?? NULL, $label);
      $this->assertSame($maxAge, $metadata->getCacheMaxAge(), $label);
      if ($record !== NULL) {
        $this->assertContains('group:3', $metadata->getCacheTags(), "$label: the opening save invalidates it");
      }
    }
  }

  /**
   * A child jurisdiction without operator data names the record workspace.
   */
  public function testChildJurisdictionOperatorName(): void {
    $this->selfService();
    $address = [
      'address_line1' => 'Main street 1',
      'postal_code' => '12345',
      'locality' => 'Town',
      'country_code' => 'DE',
    ];
    $root = $this->operatorRecordWorkspace($this->openRecord(), ['organization' => 'Root Org'] + $address, 'op@example.org');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(3)->willReturn($root);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')->willReturn(3);
    \Drupal::getContainer()->set('markaspot_fastmap.workspace_lifecycle_gate', new WorkspaceLifecycleGate(
      \Drupal::service('markaspot_fastmap.workspace_lifecycle'),
      $entityTypeManager,
      $this->createMock(ModuleHandlerInterface::class),
      new RequestStack(),
      $resolver,
    ));

    $cases = [
      'child without operator data' => [NULL, 'Root Org'],
      'child with its own operator' => [['organization' => 'Child Org'] + $address, 'Child Org'],
    ];
    foreach ($cases as $label => [$childAddress, $expected]) {
      $child = $this->operatorWorkspace($childAddress, $childAddress === NULL ? NULL : 'child@example.org', TRUE, ['field_legal_notice' => '<p>Child</p>']);
      $child->method('id')->willReturn(9);
      $child->method('language')->willReturn(new Language(['id' => 'en']));
      $settings = ['jurisdiction' => [], 'operator' => ['name' => 'Child map']];
      markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $child);
      $this->assertTrue($settings['jurisdiction']['open'], "$label: the root's state");
      $this->assertSame($expected, $settings['operator']['name'], $label);
    }
  }

}
