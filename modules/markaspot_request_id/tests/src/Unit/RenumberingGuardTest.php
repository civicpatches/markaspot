<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_id\Unit;

use Drupal\node\Entity\Node;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ensures repair saves bypass all identified mutating business hooks.
 */
#[Group('markaspot_request_id')]
final class RenumberingGuardTest extends UnitTestCase {

  /**
   * No service, field processing, relation sync or notification is reached.
   */
  public function testRepairGuards(): void {
    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getFieldDefinitions', 'bundle'])
      ->getMock();
    $node->method('getFieldDefinitions')->willReturn([]);
    $node->expects($this->never())->method('bundle');
    $node->_markaspot_request_id_renumbering = 1;
    $modules = dirname(__DIR__, 4);
    foreach ([
      'markaspot_group' => ['entity_presave', 'node_presave', 'node_update'],
      'service_request' => ['node_presave'],
      'markaspot_geocoder' => ['node_presave'],
      'markaspot_facility' => ['node_presave'],
      'markaspot_ai' => ['node_update'],
      'markaspot_vision' => ['node_update'],
    ] as $module => $hooks) {
      require_once "$modules/$module/$module.module";
      foreach ($hooks as $hook) {
        ($module . '_' . $hook)($node);
      }
    }
  }

}
