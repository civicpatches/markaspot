<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins each conjunct of the automatic media publication rule.
 */
#[Group('markaspot_vision')]
final class MediaPublicationRuleTest extends UnitTestCase {

  /**
   * Only screened, clean images of published reports are released.
   */
  #[DataProvider('publicationCases')]
  public function testPublication(bool $nodePublished, string $metadata, bool $flag, string $issues, ?array $intake, bool $strict, bool $manual, bool $publish, bool $busy = FALSE, bool $required = FALSE, bool $restore = FALSE): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_vision.settings' => ['require_ai_screening' => $strict]]));
    $keyValue = new KeyValueMemoryFactory();
    $container->set('keyvalue', $keyValue);
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->with(['media_id' => 42])->willReturn(1);
    $queues = $this->createMock(QueueFactory::class);
    $queues->method('get')->with(MediaIntakeScreening::QUEUE)->willReturn($queue);
    $container->set('queue', $queues);
    $lock = new NullLockBackend();
    if ($busy) {
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->method('acquire')->willReturn(FALSE);
      $lock->expects($this->once())->method('wait')->with('markaspot_open311.media_publication.42', 5);
      $queue = $this->createMock(QueueInterface::class);
      $queue->expects($this->once())->method('createItem')->with(['media_id' => 42])->willReturn(1);
      $queues = $this->createMock(QueueFactory::class);
      $queues->method('get')->with(MediaIntakeScreening::QUEUE)->willReturn($queue);
      $container->set('queue', $queues);
    }
    $container->set('lock', $lock);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container->set('logger.factory', $loggers);
    \Drupal::setContainer($container);
    $source = tempnam(sys_get_temp_dir(), 'fictional-publication-');
    file_put_contents($source, 'Fictional screened bytes');
    if (!empty($intake['privacy_screened'])) {
      $intake['status'] ??= 'done';
      $intake['processed'] ??= TRUE;
      $intake['prepared_hash'] ??= hash('sha256', 'Fictional screened bytes');
    }
    if ($intake !== NULL) {
      $keyValue->get(MediaIntakeScreening::STORE)->set(42, $intake);
    }
    if ($restore) {
      $keyValue->get('markaspot_vision.report_media_hold')->set(42, TRUE);
    }
    if ($manual) {
      $keyValue->get('markaspot_open311.media_publication_manual')->set(42, TRUE);
    }
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->with(42)->willReturn($media);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('media')->willReturn($storage);
    $container->set('entity_type.manager', $manager);
    $media->method('isPublished')->willReturn(FALSE);
    $media->method('hasField')->willReturn(TRUE);
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($source);
    $media->method('get')->willReturnCallback(function (string $name) use ($metadata, $flag, $issues, $file) {
      $item = $this->createMock(FieldItemListInterface::class);
      $item->method('__isset')->with('value')->willReturn(TRUE);
      $item->method('__get')->willReturn(match ($name) {
        'field_media_image' => $file,
        'field_ai_metadata' => $metadata,
        'field_ai_privacy_flag' => $flag,
        'field_ai_privacy_issues' => $issues,
      });
      return $item;
    });
    $media->expects($publish ? $this->once() : $this->never())->method('setPublished');
    $media->expects($publish ? $this->once() : $this->never())->method('save');
    $references = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $references->method('isEmpty')->willReturn(FALSE);
    $references->method('referencedEntities')->willReturn([$media]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isPublished')->willReturn($nodePublished);
    $node->method('get')->with('field_request_media')->willReturn($references);
    $oldRequired = getenv('MARKASPOT_BLUR_REQUIRED');
    putenv('MARKASPOT_BLUR_REQUIRED=' . (int) $required);
    try {
      _markaspot_vision_publish_safe_media($node);
      if (($intake['prepared_hash'] ?? '') === 'wrong') {
        $this->assertSame('failed', $keyValue->get(MediaIntakeScreening::STORE)->get(42)['status']);
      }
    }
    finally {
      putenv($oldRequired === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $oldRequired);
      unlink($source);
    }
  }

  /**
   * Direct media saves cannot bypass the imported-image checkpoint.
   */
  #[DataProvider('directPublicationCases')]
  public function testDirectPublication(string $status, bool $validHash, bool $processed, bool $required, bool $requiredMarker, bool $new, bool $blocked, ?string $analysis = NULL): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
    $oldRequired = getenv('MARKASPOT_BLUR_REQUIRED');
    putenv('MARKASPOT_BLUR_REQUIRED=' . (int) $required);
    $source = tempnam(sys_get_temp_dir(), 'publication-');
    file_put_contents($source, 'Fictional image');
    try {
      $container = new ContainerBuilder();
      $store = new KeyValueMemoryFactory();
      $store->get(MediaIntakeScreening::STORE)->set(42, ['status' => $status]);
      $container->set('keyvalue', $store);
      $logger = $this->createMock(LoggerChannelInterface::class);
      $logger->expects($blocked && !$new ? $this->once() : $this->never())->method('warning');
      $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggers->method('get')->willReturn($logger);
      $container->set('logger.factory', $loggers);
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('range')->willReturnSelf();
      $query->method('execute')->willReturn([1]);
      $parents = $this->createMock(EntityStorageInterface::class);
      $parents->method('getQuery')->willReturn($query);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->method('getStorage')->with('node')->willReturn($parents);
      $container->set('entity_type.manager', $manager);
      \Drupal::setContainer($container);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($source);
      $image = $this->createMock(FieldItemListInterface::class);
      $image->method('__get')->willReturnCallback(fn ($key) => $key === 'entity' ? $file : 7);
      $metadata = $this->createMock(FieldItemListInterface::class);
      $metadata->method('__get')->willReturn(json_encode(['intake_screening' => [
        'privacy_screened' => $processed, 'processed' => $processed,
        'source_sha256' => $validHash ? hash_file('sha256', $source) : 'stale',
        'blur_required' => $requiredMarker, 'analysis' => $analysis,
      ]]));
      $original = $this->createMock(MediaInterface::class);
      $original->method('isPublished')->willReturn(FALSE);
      $original->method('get')->with('field_media_image')->willReturn($image);
      $media = $this->createMock(MediaInterface::class);
      $media->method('id')->willReturn(42);
      $media->method('isNew')->willReturn($new);
      $media->method('bundle')->willReturn('request_image');
      $published = TRUE;
      $media->method('isPublished')->willReturnCallback(static function () use (&$published): bool {
        return $published;
      });
      $media->method('getOriginal')->willReturn($new ? NULL : $original);
      $media->method('get')->willReturnMap([
        ['field_media_image', $image], ['field_ai_metadata', $metadata],
      ]);
      $media->expects($blocked || $new ? $this->once() : $this->never())->method('setUnpublished')
        ->willReturnCallback(static function () use (&$published, $media) {
          $published = FALSE;
          return $media;
        });
      markaspot_vision_media_presave($media);
    }
    finally {
      unlink($source);
      putenv($oldRequired === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $oldRequired);
    }
  }

  /**
   * Status, exact bytes and current blur policy must all pass.
   */
  public static function directPublicationCases(): array {
    return [
      'pending' => ['pending', TRUE, TRUE, FALSE, FALSE, FALSE, TRUE],
      'new intake' => ['pending', TRUE, TRUE, FALSE, FALSE, TRUE, TRUE],
      'stale' => ['done', FALSE, TRUE, FALSE, FALSE, FALSE, TRUE],
      'unprocessed' => ['done', TRUE, FALSE, FALSE, FALSE, FALSE, TRUE],
      'policy changed' => ['done', TRUE, TRUE, TRUE, FALSE, FALSE, TRUE],
      'AI without required blur' => ['done', TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, 'done'],
      'AI cannot bypass required blur' => ['done', TRUE, FALSE, TRUE, FALSE, FALSE, TRUE, 'done'],
      'AI stale bytes' => ['done', FALSE, FALSE, FALSE, FALSE, FALSE, TRUE, 'done'],
      'valid optional' => ['done', TRUE, TRUE, FALSE, FALSE, FALSE, FALSE],
      'valid required' => ['done', TRUE, TRUE, TRUE, TRUE, FALSE, FALSE],
    ];
  }

  /**
   * Untrusted editors cannot write screening verdicts or fingerprints.
   */
  public function testScreeningFieldsRequireMediaAdministration(): void {
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
    foreach (['field_ai_metadata', 'field_ai_privacy_flag', 'field_ai_privacy_issues', 'field_media_image'] as $name) {
      foreach ([FALSE, TRUE] as $admin) {
        $definition = $this->createMock(FieldDefinitionInterface::class);
        $definition->method('getTargetEntityTypeId')->willReturn('media');
        $definition->method('getName')->willReturn($name);
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('administer media')->willReturn($admin);
        $result = markaspot_vision_entity_field_access('edit', $definition, $account);
        $this->assertSame(!$admin && $name !== 'field_media_image', $result->isForbidden());
        if ($name !== 'field_media_image') {
          $this->assertContains('user.permissions', $result->getCacheContexts());
        }
        $this->assertTrue(markaspot_vision_entity_field_access('view', $definition, $account)->isNeutral());
      }
    }
  }

  /**
   * Independent publication guards, including explicit editorial authority.
   */
  public static function publicationCases(): array {
    return [
      'clean screened published' => [TRUE, '{}', FALSE, '', ['privacy_screened' => TRUE], TRUE, FALSE, TRUE],
      'draft report' => [FALSE, '{}', FALSE, '', ['privacy_screened' => TRUE], TRUE, FALSE, FALSE],
      'source replaced' => [
        TRUE, '{}', FALSE, '',
        ['privacy_screened' => TRUE, 'prepared_hash' => 'wrong'],
        TRUE, FALSE, FALSE,
      ],
      'strict missing marker' => [TRUE, '', FALSE, '', NULL, TRUE, FALSE, FALSE],
      'privacy flag' => [TRUE, '{}', TRUE, '', NULL, TRUE, FALSE, FALSE],
      'privacy issues' => [TRUE, '{}', FALSE, 'Fictional personal information', NULL, TRUE, FALSE, FALSE],
      'pending import legacy' => [TRUE, '', FALSE, '', ['status' => 'pending'], FALSE, FALSE, FALSE],
      'failed import legacy' => [TRUE, '{}', FALSE, '', ['status' => 'failed'], FALSE, FALSE, FALSE],
      'manual unpublished' => [TRUE, '{}', FALSE, '', NULL, TRUE, TRUE, FALSE],
      // NULL analysis leaves no AI metadata or privacy verdict on the upload.
      'legacy required blur NULL analysis' => [TRUE, '', FALSE, '', NULL, FALSE, FALSE, FALSE, FALSE, TRUE],
      'legacy required blur report restore' => [TRUE, '', FALSE, '', NULL, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE],
      'legacy ordinary media waits for post-commit retry' => [TRUE, '', FALSE, '', NULL, FALSE, FALSE, FALSE],
      'busy publication lock' => [TRUE, '{}', FALSE, '', NULL, TRUE, FALSE, FALSE, TRUE],
    ];
  }

}
