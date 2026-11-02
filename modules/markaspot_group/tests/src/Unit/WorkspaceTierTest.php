<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceTier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers tierless SaaS and operator-managed jurisdiction entitlements.
 */
#[Group('markaspot_group')]
final class WorkspaceTierTest extends UnitTestCase {

  /**
   * The mode wins over the installed Fastmap module.
   */
  public function testExplicitOperatingMode(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('markaspot_fastmap')->willReturn(TRUE);
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertFalse(WorkspaceTier::isSelfService($modules));
    new Settings(['markaspot_operating_mode' => 'saas']);
    $this->assertTrue(WorkspaceTier::isSelfService($modules));
    new Settings([]);
    $this->assertTrue(WorkspaceTier::isSelfService($modules));
  }

  /**
   * Missing or unknown tiers never unlock paid self-service features.
   */
  #[DataProvider('tiers')]
  public function testTierResolution(?string $tier, bool $selfService, ?string $expected, bool $assignment): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->with('field_tier')->willReturn($tier !== NULL);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($tier);
    $group->method('get')->with('field_tier')->willReturn($field);
    $this->assertSame($expected, WorkspaceTier::resolve($group, $selfService));
    $this->assertSame($assignment, WorkspaceTier::allowsCaseAssignment($group, $selfService, FALSE));
    if (in_array($tier, ['', NULL, 'community', 'premium'], TRUE)) {
      $this->assertTrue(WorkspaceTier::allowsCaseAssignment($group, $selfService, TRUE));
    }
  }

  /**
   * Provides SaaS and operator-managed cases.
   */
  public static function tiers(): array {
    return [
      [NULL, TRUE, 'free', FALSE], ['', TRUE, 'free', FALSE],
      ['unknown', TRUE, 'free', FALSE], ['community', TRUE, 'free', FALSE],
      ['pro', TRUE, 'pro', TRUE], ['heart', TRUE, 'heart', TRUE],
      [NULL, FALSE, NULL, TRUE], ['', FALSE, NULL, TRUE],
      ['community', FALSE, 'community', TRUE],
      ['premium', TRUE, 'free', FALSE],
    ];
  }

  /**
   * Showcase assignment depends on the raw flag, not public demo eligibility.
   */
  public function testShowcaseJurisdictionUsesRawFlag(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.module';
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->with('field_tier')->willReturn(TRUE);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn('community');
    $group->method('get')->with('field_tier')->willReturn($field);
    $container = new ContainerBuilder();
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $container->set('module_handler', $modules);
    new Settings(['markaspot_operating_mode' => 'saas']);
    foreach (['enabled' => TRUE, 'disabled' => FALSE] as $flag => $expected) {
      $container->set('config.factory', $this->getConfigFactoryStub([
        'markaspot_nuxt.settings' => ['enterprise_showcase' => $flag],
      ]));
      \Drupal::setContainer($container);
      $this->assertSame($expected, _markaspot_group_case_assignment_enabled_for_jurisdiction($group));
    }
  }

}
