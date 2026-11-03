<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards shared attachment defaults and preservation of tenant policies.
 */
#[Group('markaspot_mail')]
final class ServiceProviderAttachmentMailContractTest extends UnitTestCase {

  /**
   * Shared defaults must not permit provider-only spreadsheet MIME types.
   */
  public function testSharedDefaultsExcludeSpreadsheets(): void {
    $this->assertNotContains('application/vnd.ms-excel', self::defaults()['allowed_mime']);
    $this->assertNotContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', self::defaults()['allowed_mime']);
    require_once dirname(__DIR__, 3) . '/markaspot_mail.install';
    $this->assertFalse(function_exists('markaspot_mail_update_10018'));
  }

  /**
   * Seeding fills missing defaults, preserves tenant policy and is idempotent.
   */
  #[DataProvider('policyCases')]
  public function testSeedPreservesTenantPolicy(array $initial, array $expected, int $saves): void {
    require_once dirname(__DIR__, 3) . '/markaspot_mail.install';
    $state = $initial;
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static function (string $key) use (&$state): mixed {
      return $key === 'attachments' ? $state : ($state['allowed_mime'] ?? NULL);
    });
    $config->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$state, $config): Config {
      if ($key === 'attachments') {
        $state = $value;
      }
      else {
        $state['allowed_mime'] = $value;
      }
      return $config;
    });
    $config->expects($this->exactly($saves))->method('save')->willReturnSelf();
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('markaspot_mail.settings')->willReturn($config);
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);

    \_markaspot_mail_seed_attachment_defaults();
    $this->assertSame($expected, $state);
    \_markaspot_mail_seed_attachment_defaults();
    $this->assertSame($expected, $state);
  }

  /**
   * Shipped, absent, custom and disabled MIME policies.
   */
  public static function policyCases(): iterable {
    $current = self::defaults();
    yield 'current default' => [$current, $current, 0];
    yield 'absent defaults' => [[], $current, 1];
    $disabled = $current;
    $disabled['enabled'] = FALSE;
    $disabled['max_per_file_bytes'] = 512;
    $missing = $disabled;
    unset($missing['allowed_mime']);
    yield 'missing MIME preserves other settings' => [$missing, $disabled, 1];
    foreach ([[], ['application/pdf'], ['application/vnd.ms-excel'], 'invalid'] as $mime) {
      $custom = $disabled;
      $custom['allowed_mime'] = $mime;
      yield 'custom ' . json_encode($mime) => [$custom, $custom, 0];
    }
  }

  /**
   * Returns the shipped attachment defaults.
   */
  private static function defaults(): array {
    $config = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/config/install/markaspot_mail.settings.yml'));
    return $config['attachments'];
  }

}
