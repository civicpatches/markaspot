<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_vision\Service\OrphanUploadPurger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins conservative protection of browser-only offline queue references.
 */
#[Group('markaspot_vision')]
class OrphanUploadPurgerTest extends UnitTestCase {

  /**
   * Any stored offline-enabled jurisdiction protects even unbound uploads.
   */
  #[DataProvider('offlineCases')]
  public function testOfflineProtection(string $config, bool $protected): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($config);
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($field);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$group]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('group')->willReturn(TRUE);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $purger = new OfflineProtectionPurger($manager);
    $this->assertSame($protected, $purger->hasOfflineReferences());
  }

  /**
   * Supported boolean and object feature settings, including legacy tenants.
   */
  public static function offlineCases(): array {
    return [
      'empty' => ['', FALSE],
      'default' => ['{}', FALSE],
      'disabled' => ['{"features":{"offline":false}}', FALSE],
      'enabled' => ['{"features":{"offline":true}}', TRUE],
      'object enabled' => ['{"features":{"offline":{"enabled":true}}}', TRUE],
      'object disabled' => ['{"features":{"offline":{"enabled":false}}}', FALSE],
    ];
  }

}

/**
 * Isolates the offline decision from the unrelated persistence collaborators.
 */
class OfflineProtectionPurger extends OrphanUploadPurger {

  /**
   * Constructs the read-only policy probe.
   */
  public function __construct(EntityTypeManagerInterface $manager) {
    $this->entityTypeManager = $manager;
  }

  /**
   * Exposes the production decision for the fixture groups.
   */
  public function hasOfflineReferences(): bool {
    return $this->mayHaveOfflineReference();
  }

}
