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
 * Guards provider upload MIME support and preservation of tenant policies.
 */
#[Group('markaspot_mail')]
final class ServiceProviderAttachmentMailContractTest extends UnitTestCase {

  /**
   * Every extension accepted by the provider upload has a mail MIME default.
   */
  public function testUploadExtensionsHaveMailMimeDefaults(): void {
    $root = dirname(__DIR__, 5);
    $field = Yaml::decode(file_get_contents($root . '/modules/service_request/config/install/field.field.node.service_request.field_sp_attachment.yml'));
    $mimeByExtension = [
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    foreach (explode(' ', $field['settings']['file_extensions']) as $extension) {
      $this->assertArrayHasKey($extension, $mimeByExtension);
      $this->assertContains($mimeByExtension[$extension], self::defaults()['allowed_mime']);
    }
  }

  /**
   * The update is additive only for shipped defaults, and is idempotent.
   */
  #[DataProvider('policyCases')]
  public function testUpdatePreservesTenantPolicy(array $initial, array $expected, int $saves): void {
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

    \markaspot_mail_update_10018();
    $this->assertSame($expected, $state);
    \markaspot_mail_update_10018();
    $this->assertSame($expected, $state);
  }

  /**
   * Shipped, absent, custom and disabled MIME policies.
   */
  public static function policyCases(): iterable {
    $current = self::defaults();
    $legacy = $current;
    $legacy['allowed_mime'] = array_values(array_diff($current['allowed_mime'], [
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]));
    yield 'historic default' => [$legacy, $current, 1];
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
