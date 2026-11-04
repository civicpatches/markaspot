<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OrphanUploadPurger;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins tenant-specific protection of browser-only offline queue references.
 */
#[Group('markaspot_vision')]
class OrphanUploadPurgerTest extends UnitTestCase {

  /**
   * Only the bound jurisdiction or installation default controls this upload.
   */
  #[DataProvider('offlineCases')]
  public function testOfflineProtection(mixed $binding, bool $exists, bool $tenant_enabled, bool $default, bool $protected): void {
    $group = $exists ? $this->createMock(GroupInterface::class) : NULL;
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('loadMultiple');
    $storage->method('load')->willReturn($group);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('group')->willReturn(TRUE);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $policy = $this->createMock(FeatureScopeResolver::class);
    $resolved = is_numeric($binding) && $exists;
    $policy->expects($resolved ? $this->once() : $this->never())->method('isEnabledEffective')
      ->with('offline', $group)->willReturn($tenant_enabled);
    $policy->expects($resolved ? $this->never() : $this->once())->method('isPlatformFeatureEnabled')
      ->with('offline')->willReturn($default);
    $key_value = new KeyValueMemoryFactory();
    $key_value->get(MediaTenantScopeResolver::STORE)->set('synthetic-upload', $binding);
    $media = $this->createMock(MediaInterface::class);
    $media->method('uuid')->willReturn('synthetic-upload');
    $purger = new OfflineProtectionPurger($manager, $key_value, $policy);
    $this->assertSame($protected, $purger->hasOfflineReferences($media));
  }

  /**
   * One workspace's opt-in cannot pause other workspaces or unbound uploads.
   */
  public static function offlineCases(): array {
    return [
      'tenant A offline' => [11, TRUE, TRUE, FALSE, TRUE],
      'tenant B online' => [22, TRUE, FALSE, TRUE, FALSE],
      'legacy default off' => [NULL, FALSE, TRUE, FALSE, FALSE],
      'legacy default on' => [NULL, FALSE, FALSE, TRUE, TRUE],
      'new unbound' => [MediaTenantScopeResolver::UNBOUND_MARKER, FALSE, TRUE, FALSE, FALSE],
      'deleted jurisdiction' => [33, FALSE, TRUE, FALSE, FALSE],
      'deleted jurisdiction default on' => [33, FALSE, FALSE, TRUE, TRUE],
    ];
  }

}

/**
 * Isolates the offline decision from unrelated persistence collaborators.
 */
class OfflineProtectionPurger extends OrphanUploadPurger {

  /**
   * Constructs the policy probe.
   */
  public function __construct(EntityTypeManagerInterface $manager, KeyValueMemoryFactory $key_value, FeatureScopeResolver $policy) {
    $this->entityTypeManager = $manager;
    $this->keyValue = $key_value;
    $this->featureScopeResolver = $policy;
  }

  /**
   * Exposes the production decision for the fixture upload.
   */
  public function hasOfflineReferences(MediaInterface $media): bool {
    return $this->mayHaveOfflineReference($media);
  }

}
