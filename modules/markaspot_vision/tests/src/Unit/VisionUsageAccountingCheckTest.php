<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_vision\Plugin\HealthCheck\VisionUsageAccountingCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Covers the operator-visible accounting gap without enabling optional modules.
 */
#[CoversClass(VisionUsageAccountingCheck::class)]
#[Group('markaspot_vision')]
final class VisionUsageAccountingCheckTest extends UnitTestCase {

  /**
   * The manager discovers the plugin from Vision without the AI module.
   */
  public function testDiscoveryWithoutAiModule(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static fn ($name) => $name === 'markaspot_vision');
    $container = new ContainerBuilder();
    $container->set('module_handler', $modules);
    $container->set('config.factory', $this->getConfigFactoryStub([]));
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
    $manager = new HealthCheckPluginManager(new \ArrayObject([
      'Drupal\\markaspot_vision' => dirname(__DIR__, 3) . '/src',
    ]), $this->createMock(CacheBackendInterface::class), $modules);
    $definition = $manager->getDefinition('vision_usage_accounting');
    self::assertSame('markaspot_vision', $definition['provider']);
    $plugin = $manager->createInstance('vision_usage_accounting');
    self::assertInstanceOf(VisionUsageAccountingCheck::class, $plugin);
    $result = $plugin->run();
    self::assertSame('warning', $result->severity);
    self::assertFalse($result->passed);
    self::assertStringContainsString('without markaspot_ai', $result->message);
  }

  /**
   * Optional services and configuration determine warning versus info.
   */
  #[DataProvider('accountingStates')]
  public function testAccountingState(bool $installed, bool $tracker, bool $client, ?bool $enabled, string $severity, string $message): void {
    $container = new ContainerBuilder();
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('markaspot_ai')->willReturn($installed);
    $container->set('module_handler', $modules);
    $container->set('config.factory', $this->getConfigFactoryStub([
      'markaspot_ai.settings' => ['token_tracking' => ['enabled' => $enabled]],
    ]));
    if ($tracker) {
      $container->set('markaspot_ai.token_tracking', new \stdClass());
    }
    if ($client) {
      $container->set('markaspot_ai.client', new \stdClass());
    }
    $plugin = VisionUsageAccountingCheck::create($container, [], 'vision_usage_accounting', [
      'label' => 'Vision token accounting', 'severity' => 'info',
    ]);
    $result = $plugin->run();
    self::assertSame($severity, $result->severity);
    self::assertSame($severity === 'info', $result->passed);
    self::assertSame($severity === 'info' ? 0 : 1, $result->count);
    self::assertStringContainsString($message, $result->message);
  }

  /**
   * Missing module, incomplete services, disabled/unset tracking and accounted.
   */
  public static function accountingStates(): array {
    return [
      'module missing' => [FALSE, FALSE, FALSE, NULL, 'warning', 'without markaspot_ai'],
      'tracker missing' => [TRUE, FALSE, TRUE, TRUE, 'warning', 'services are unavailable'],
      'client missing' => [TRUE, TRUE, FALSE, TRUE, 'warning', 'services are unavailable'],
      'tracking disabled' => [TRUE, TRUE, TRUE, FALSE, 'warning', 'disabled or unset'],
      'tracking unset' => [TRUE, TRUE, TRUE, NULL, 'warning', 'disabled or unset'],
      'accounted' => [TRUE, TRUE, TRUE, TRUE, 'info', 'usage is accounted for'],
    ];
  }

}
