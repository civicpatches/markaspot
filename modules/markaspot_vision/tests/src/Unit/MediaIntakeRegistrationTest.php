<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface as DrupalQueueInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins association ordering and recoverability of queue backend failures.
 */
#[Group('markaspot_vision')]
final class MediaIntakeRegistrationTest extends UnitTestCase {

  /**
   * Registration holds media immediately; enqueueing waits for association.
   */
  #[DataProvider('associationCases')]
  public function testRegistration(bool $associated): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(42);
    $nodes = $associated ? [1 => $this->createMock(NodeInterface::class)] : [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($nodes));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($nodes);
    $storage->method('load')->with(42)->willReturn($media);
    $storage->method('loadUnchanged')->with(42)->willReturn($media);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($storage);
    $keyValue = new KeyValueMemoryFactory();
    $queue = $this->createMock(DrupalQueueInterface::class);
    $queue->expects($associated ? $this->exactly(2) : $this->never())->method('createItem')
      ->with(['media_id' => 42])->willReturnOnConsecutiveCalls(FALSE, 17);
    $queues = $this->createMock(QueueFactory::class);
    $queues->method('get')->with(MediaIntakeScreening::QUEUE)->willReturn($queue);
    $images = $this->createMock(ImageProcessingService::class);
    $images->expects($this->never())->method('blurSensitiveAreas');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($associated ? $this->once() : $this->never())->method('error');
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($logger);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(12345);
    $service = new MediaIntakeScreening(
      $queues, $keyValue, new NullLockBackend(), $entities, $this->getConfigFactoryStub([]), $time,
      $images, $this->createMock(OriginalImageStore::class), $this->createMock(MediaTenantScopeResolver::class),
      $this->createMock(FeatureScopeResolver::class), $loggers,
    );
    $service->register($media);
    $this->assertFalse($keyValue->get(MediaIntakeScreening::STORE)->get(42)['queued']);
    $service->enqueue($media);
    if (!$associated) {
      $service->process(42);
    }
    $this->assertSame(0, $keyValue->get(MediaIntakeScreening::STORE)->get(42)['attempts']);
    $service->recoverPending();
    $service->enqueue($media);
    $this->assertSame($associated, $keyValue->get(MediaIntakeScreening::STORE)->get(42)['queued']);
  }

  /**
   * A report not saved yet and a queue backend initially refusing work.
   */
  public static function associationCases(): array {
    return ['before association' => [FALSE], 'recover failed insertion' => [TRUE]];
  }

  /**
   * Cron revokes AI-only publication without needing another report save.
   */
  #[DataProvider('policyRecoveryCases')]
  public function testRequiredPolicyRecovery(bool $required, bool $processed, bool $blurRequired, bool $busy = FALSE, bool $saveFailure = FALSE): void {
    $old = getenv('MARKASPOT_BLUR_REQUIRED');
    putenv('MARKASPOT_BLUR_REQUIRED=' . (int) $required);
    try {
      $recover = $required && (!$processed || !$blurRequired) && !$busy;
      $keyValue = new KeyValueMemoryFactory();
      $store = $keyValue->get(MediaIntakeScreening::STORE);
      $original = [
        'status' => 'done', 'queued' => TRUE, 'attempts' => 4,
        'processed' => $processed, 'blur_required' => $blurRequired,
        'analysis' => 'done', 'prepared_hash' => 'current-source',
        'next_attempt' => 99999,
      ];
      $store->set(42, $original);
      $mid = $saveFailure ? 43 : 42;
      if ($saveFailure) {
        $store->set($mid, $original);
      }
      $published = TRUE;
      $media = $this->createMock(MediaInterface::class);
      $media->method('id')->willReturn($mid);
      $media->expects($recover ? $this->once() : $this->never())->method('setUnpublished')
        ->willReturnCallback(function () use (&$published, $media) {
          $published = FALSE;
          return $media;
        });
      $media->expects($recover ? $this->once() : $this->never())->method('save')->willReturn(2);
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('execute')->willReturn([1]);
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('getQuery')->willReturn($query);
      $storage->method('loadMultiple')->willReturn([$this->createMock(NodeInterface::class)]);
      $storage->method('load')->with($mid)->willReturn($media);
      $broken = $this->createMock(MediaInterface::class);
      $broken->expects($saveFailure ? $this->once() : $this->never())->method('save')
        ->willThrowException(new EntityStorageException('Synthetic save failure.'));
      $storage->method('loadUnchanged')->willReturnCallback(static fn($id) => $saveFailure && (int) $id === 42 ? $broken : $media);
      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('getStorage')->willReturn($storage);
      $queue = $this->createMock(DrupalQueueInterface::class);
      $queue->expects($recover ? $this->once() : $this->never())->method('createItem')
        ->with(['media_id' => $mid])->willReturnCallback(function () use (&$published, $store, $mid) {
          $this->assertFalse($published, 'Publication is revoked before dispatch.');
          $this->assertSame('pending', $store->get($mid)['status']);
          return 1;
        });
      $queues = $this->createMock(QueueFactory::class);
      $queues->method('get')->willReturn($queue);
      $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
      $logger = $this->createMock(LoggerChannelInterface::class);
      $logger->expects($saveFailure ? $this->once() : $this->never())->method('error');
      $loggers->method('get')->willReturn($logger);
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->method('acquire')->willReturnCallback(static fn(string $name): bool => !$busy || !str_starts_with($name, 'markaspot_open311.media_publication.'));
      $service = new MediaIntakeScreening(
        $queues, $keyValue, $lock, $entities, $this->getConfigFactoryStub([]),
        $this->createMock(TimeInterface::class), $this->createMock(ImageProcessingService::class),
        $this->createMock(OriginalImageStore::class), $this->createMock(MediaTenantScopeResolver::class),
        $this->createMock(FeatureScopeResolver::class), $loggers,
      );
      $service->recoverPending();
      $entry = $store->get($mid);
      if ($recover) {
        $this->assertSame('pending', $entry['status']);
        $this->assertTrue($entry['queued']);
        $this->assertSame(0, $entry['attempts']);
        $this->assertArrayNotHasKey('next_attempt', $entry);
        $this->assertSame('done', $entry['analysis']);
        $this->assertSame('current-source', $entry['prepared_hash']);
        if ($saveFailure) {
          $this->assertSame($original, $store->get(42), 'The failed hold remains recoverable.');
        }
        else {
          $service->recoverPending();
        }
      }
      else {
        $this->assertSame($original, $entry);
      }
    }
    finally {
      putenv($old === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $old);
    }
  }

  /**
   * Policy changes, valid blur checkpoints and busy publication locks.
   */
  public static function policyRecoveryCases(): array {
    return [
      'AI only' => [TRUE, FALSE, FALSE],
      'optional blur completed' => [TRUE, TRUE, FALSE],
      'required but not processed' => [TRUE, FALSE, TRUE],
      'required blur completed' => [TRUE, TRUE, TRUE],
      'still optional' => [FALSE, FALSE, FALSE],
      'publication lock busy' => [TRUE, FALSE, FALSE, TRUE],
      'broken first entity' => [TRUE, FALSE, FALSE, FALSE, TRUE],
    ];
  }

}
