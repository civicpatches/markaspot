<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\markaspot_mail_inbound\Service\MailIntakeFidelity;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Attachment imports require both the private path and its stream wrapper.
 */
#[Group('markaspot_mail_inbound')]
final class PrivateImportRequirementsTest extends UnitTestCase {

  /**
   * Disabled imports are excluded; either missing storage part is an error.
   */
  public function testPrivateStorageRequirement(): void {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    require_once dirname(__DIR__, 3) . '/markaspot_mail_inbound.install';
    foreach ([0, 5, NULL] as $maximum) {
      foreach ([FALSE, TRUE] as $path) {
        foreach ([FALSE, TRUE] as $wrapper) {
          new Settings(['file_private_path' => $path ? '/fictional/private' : '']);
          $container = new ContainerBuilder();
          $container->set('string_translation', $this->getStringTranslationStub());
          $container->set('config.factory', $this->getConfigFactoryStub([
            'markaspot_mail_inbound.settings' => ['max_attachments' => $maximum],
          ]));
          $wrappers = $this->createMock(StreamWrapperManagerInterface::class);
          $wrappers->method('isValidScheme')->with('private')->willReturn($wrapper);
          $container->set('stream_wrapper_manager', $wrappers);
          $container->set('markaspot_mail_inbound.fidelity', $this->createMock(MailIntakeFidelity::class));
          $resolver = $this->createMock(MailboxResolver::class);
          $resolver->method('getMailboxes')->willReturn([]);
          $container->set('markaspot_mail_inbound.mailbox_resolver', $resolver);
          \Drupal::setContainer($container);
          $result = markaspot_mail_inbound_requirements('runtime');
          $expected = $maximum !== 0 && (!$path || !$wrapper);
          $this->assertSame($expected, isset($result['markaspot_mail_inbound_private_imports']));
          if ($expected) {
            $this->assertSame(REQUIREMENT_ERROR, $result['markaspot_mail_inbound_private_imports']['severity']);
            $this->assertStringContainsString('file_private_path', (string) $result['markaspot_mail_inbound_private_imports']['description']);
          }
          $this->assertSame([], markaspot_mail_inbound_requirements('install'));
        }
      }
    }
  }

}
