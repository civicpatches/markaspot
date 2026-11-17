<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\markaspot_nuxt\MarkaspotNuxtServiceProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies module defaults and the site override boundary.
 */
#[Group('markaspot_nuxt')]
final class CorrelationDefaultsTest extends UnitTestCase {

  /**
   * All channels use the two sinks; site parameters can replace the defaults.
   */
  public function testDefaultsAndSiteOverrides(): void {
    $container = new ContainerBuilder();
    $container->setParameter('monolog.channel_handlers', [
      'default' => ['syslog'], 'php' => ['error_log'],
    ]);
    $container->setParameter('monolog.logger.processors', ['existing_processor']);
    $provider = new MarkaspotNuxtServiceProvider();
    $provider->register($container);
    $channels = $container->getParameter('monolog.channel_handlers');
    $this->assertSame(['default'], array_keys($channels));
    $this->assertSame(['correlation_drupal', 'correlation_stderr'], array_column($channels['default']['handlers'], 'name'));
    $this->assertNotContains('message_placeholder', $channels['default']['handlers'][0]['processors']);
    $this->assertSame(['existing_processor', 'correlation'], $container->getParameter('monolog.logger.processors'));
    // Drupal loads site YAML after module register(), before alter().
    $override = ['default' => ['null'], 'php' => ['syslog']];
    $container->setParameter('monolog.channel_handlers', $override);
    $provider->alter($container);
    $this->assertSame($override, $container->getParameter('monolog.channel_handlers'));

    $services = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/markaspot_nuxt.services.yml'))['services'];
    $this->assertSame('php://stderr', $services['monolog.handler.correlation_stderr']['arguments'][0]);
    $this->assertTrue($services['monolog.handler.correlation_stderr']['arguments'][2]);
  }

  /**
   * Reduced kernels without Monolog receive no unused logger parameters.
   */
  public function testWithoutMonolog(): void {
    $container = new ContainerBuilder();
    (new MarkaspotNuxtServiceProvider())->register($container);
    $this->assertFalse($container->hasParameter('monolog.channel_handlers'));
  }

}
