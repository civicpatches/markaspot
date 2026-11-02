<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runtime diagnostics cover active imports without private storage.
 */
#[Group('markaspot_open311')]
final class PrivateImportRequirementsTest extends UnitTestCase {

  /**
   * Disabled endpoints are excluded; active imports require private storage.
   */
  public function testPrivateStorageRequirement(): void {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    require_once dirname(__DIR__, 3) . '/markaspot_open311.install';
    foreach ([FALSE, TRUE] as $enabled) {
      foreach ([FALSE, TRUE] as $private) {
        new Settings(['file_private_path' => $private ? '/fictional/private' : '']);
        $container = new ContainerBuilder();
        $container->set('string_translation', $this->getStringTranslationStub());
        $container->set('config.factory', $this->getConfigFactoryStub([
          'rest.resource.georeport_request_index_resource' => [
            'status' => $enabled, 'configuration' => ['POST' => ['supported_formats' => ['json']]],
          ],
        ]));
        $wrappers = $this->createMock(StreamWrapperManagerInterface::class);
        $wrappers->method('isValidScheme')->with('private')->willReturn($private);
        $container->set('stream_wrapper_manager', $wrappers);
        \Drupal::setContainer($container);
        $result = markaspot_open311_requirements('runtime');
        $this->assertSame($enabled && !$private, isset($result['markaspot_open311_private_imports']));
        if ($result) {
          $this->assertSame(REQUIREMENT_ERROR, $result['markaspot_open311_private_imports']['severity']);
          $this->assertStringContainsString('file_private_path', (string) $result['markaspot_open311_private_imports']['description']);
        }
        $this->assertSame([], markaspot_open311_requirements('install'));
      }
    }
  }

}
