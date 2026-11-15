<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests where the settings hooks put the lifecycle record, and where not.
 *
 * Runs the hook functions of this checkout's module file.
 *
 * @group markaspot_fastmap
 */
class LifecycleSettingsHookTest extends UnitTestCase {

  private const RECORD = '{"v":1,"acceptance":{"terms":"t-2026","dpa":"d-2026","declaration":"o-1","langcode":"de","accepted_at":1800000000,"uid":4242},"opening":null}';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';

    $config = $this->createMock(ImmutableConfig::class);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $container = new ContainerBuilder();
    $container->set('markaspot_fastmap.workspace_lifecycle', new WorkspaceLifecycle($factory, $this->createMock(TimeInterface::class)));
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
      ],
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

}
