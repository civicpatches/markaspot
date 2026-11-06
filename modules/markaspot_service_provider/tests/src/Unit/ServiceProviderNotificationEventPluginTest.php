<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\eca\Attribute\EcaEvent;
use Drupal\eca\Attribute\Token;
use Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent;
use Drupal\markaspot_service_provider\Event\ServiceProviderResponseEvent;
use Drupal\markaspot_service_provider\Plugin\ECA\Event\ServiceProviderNotificationEvent;
use Drupal\markaspot_service_provider\ServiceProviderEvents;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ECA event plugin of due provider notifications.
 */
#[Group('markaspot_service_provider')]
#[CoversClass(ServiceProviderNotificationEvent::class)]
#[CoversClass(ServiceProviderNotificationDueEvent::class)]
final class ServiceProviderNotificationEventPluginTest extends UnitTestCase {

  /**
   * The plugin subscribes to the cron event with the request as entity.
   */
  public function testPluginDefinition(): void {
    $attributes = (new \ReflectionClass(ServiceProviderNotificationEvent::class))->getAttributes(EcaEvent::class);
    $this->assertCount(1, $attributes);
    $definition = $attributes[0]->newInstance();
    $this->assertSame('service_provider_notification_due', $definition->id);
    $this->assertSame(ServiceProviderEvents::NOTIFICATION_DUE, $definition->event_name);
    $this->assertSame(ServiceProviderNotificationDueEvent::class, $definition->event_class);

    $node = $this->createMock(NodeInterface::class);
    $event = new ServiceProviderNotificationDueEvent($node, ['a@example.org']);
    $this->assertSame($node, $event->getEntity());
    $this->assertSame($node, $event->getNode());
  }

  /**
   * The sp_email token is declared where the ECA token browser looks for it.
   */
  public function testTokenIsDeclaredOnGetData(): void {
    $tokens = array_map(
      static fn(\ReflectionAttribute $attribute): Token => $attribute->newInstance(),
      (new \ReflectionMethod(ServiceProviderNotificationEvent::class, 'getData'))->getAttributes(Token::class),
    );
    $this->assertSame(['sp_email'], array_map(static fn(Token $token): string => $token->name, $tokens));
    $this->assertSame([ServiceProviderNotificationDueEvent::class], $tokens[0]->classes);
  }

  /**
   * The token carries all provider addresses for the due event only.
   */
  public function testSpEmailToken(): void {
    $plugin = (new \ReflectionClass(ServiceProviderNotificationEvent::class))->newInstanceWithoutConstructor();
    $node = $this->createMock(NodeInterface::class);

    $plugin->setEvent(new ServiceProviderNotificationDueEvent($node, ['a@example.org', 'b@example.org']));
    $this->assertTrue($plugin->hasData('sp_email'));
    $this->assertSame('a@example.org, b@example.org', $plugin->getData('sp_email'));
    $this->assertNull($plugin->getData('completion_notes'));
    $this->assertFalse($plugin->hasData('completion_notes'));

    $plugin->setEvent(new ServiceProviderResponseEvent($node, 'a@example.org', 'Done.'));
    $this->assertFalse($plugin->hasData('sp_email'));
    $this->assertNull($plugin->getData('sp_email'));
  }

}
