<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_stats\Unit;

use Drupal\markaspot_stats\EventSubscriber\DeprecatedStatsAliasSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins public aliases, access parity and one notice per request.
 *
 * @group markaspot_stats
 */
class DeprecatedStatsAliasSubscriberTest extends UnitTestCase {

  /**
   * Each alias uses the original controller and access contract.
   */
  public function testAliasesAndNotices(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_stats.routing.yml');
    foreach (['annual', 'status', 'categories'] as $name) {
      $canonical = $routes['markaspot_stats.' . $name];
      $alias = $routes['markaspot_stats.api_' . $name];
      $this->assertSame('/api' . $canonical['path'], $alias['path']);
      unset($canonical['path'], $alias['path']);
      $this->assertSame($canonical, $alias);
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->exactly(2))->method('notice')->with(
        $this->stringContains('removed in the release after 11.9.247'),
        ['@route' => '/stats/' . $name],
      );
      $subscriber = new DeprecatedStatsAliasSubscriber($logger);
      for ($requestNumber = 0; $requestNumber < 2; $requestNumber++) {
        $request = new Request([], [], ['_route' => 'markaspot_stats.api_' . $name]);
        $event = new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn() => NULL, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onController($event);
        $subscriber->onController($event);
      }
    }
  }

  /**
   * Canonical endpoints and unrelated requests never log a deprecation.
   */
  public function testCanonicalRoutesAreSilent(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $subscriber = new DeprecatedStatsAliasSubscriber($logger);
    foreach (['annual', 'status', 'categories', 'unrelated'] as $name) {
      $event = new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn() => NULL, new Request([], [], ['_route' => 'markaspot_stats.' . $name]), HttpKernelInterface::MAIN_REQUEST);
      $subscriber->onController($event);
    }
  }

}
