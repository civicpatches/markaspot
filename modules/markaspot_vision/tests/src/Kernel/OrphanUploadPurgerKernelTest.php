<?php

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\markaspot_vision\Service\OrphanUploadPurger;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests collection with SQL references, entity hooks and physical files.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
class OrphanUploadPurgerKernelTest extends KernelTestBase {

  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'image', 'node',
    'media', 'language', 'vision_orphan_test',
  ];

  /**
   * Production collector.
   */
  protected OrphanUploadPurger $purger;

  /**
   * Production original storage.
   */
  protected OriginalImageStore $originals;

  /**
   * Fixed future clock for deterministic age checks.
   */
  protected int $now;

  /**
   * Clock shared by collector test doubles.
   */
  protected TimeInterface $clock;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('config.storage.schema')->setClass(OrphanSchemaStorage::class);
    $container->register('stream_wrapper.private', PrivateStream::class)->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $c = $this->container;
    $this->now = time() + 200000;
    $clock = $this->clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn($this->now);
    $clock->method('getRequestTime')->willReturn($this->now);
    $this->purger = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $c->get('lock'),
      $c->get('state'), $clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
    );
    $c->set('markaspot_vision.orphan_upload_purger', $this->purger);
    $c->set('markaspot_vision.media_tenant_scope', new MediaTenantScopeResolver(
      $c->get('entity_type.manager'), $c->get('keyvalue'), $c->get('config.factory'), $c->get('state'),
    ));
    foreach (['user', 'file', 'node', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    require_once dirname(__DIR__, 3) . '/markaspot_vision.install';
    markaspot_vision_install();
    $c->get('database')->schema()->createTable(OriginalImageStore::TABLE, markaspot_vision_schema()[OriginalImageStore::TABLE]);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'file', 'image']);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    mkdir($this->siteDirectory . '/private', 0777, TRUE);
    $c->get('stream_wrapper_manager')->registerWrapper('private', PrivateStream::class, StreamWrapperInterface::LOCAL_NORMAL);
    $this->config('markaspot_vision.settings')->set('retain_originals', TRUE)->set('require_ai_screening', TRUE)->save();
    $this->originals = new OriginalImageStore(
      $c->get('config.factory'), $c->get('database'), $c->get('entity_type.manager'),
      $c->get('file_system'), $c->get('stream_wrapper_manager'), $clock,
      $c->get('state'), $c->get('logger.factory'), NULL, $c->get('Drupal\image\ImageDerivativeUtilities'),
    );
    $c->set('markaspot_vision.original_image_store', $this->originals);
    NodeType::create(['type' => 'service_request', 'name' => 'Test report'])->save();
    MediaType::create([
      'id' => 'request_image', 'label' => 'Test image', 'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createImageField('field_media_image', 'media', 'request_image');
    foreach (['field_request_media', 'field_other_media'] as $name) {
      $this->field('node', 'service_request', $name, 'entity_reference', ['target_type' => 'media']);
    }
    $this->field('media', 'request_image', 'field_ai_metadata', 'string_long');
    $this->field('media', 'request_image', 'field_ai_privacy_flag', 'boolean');
    $this->field('media', 'request_image', 'field_ai_privacy_issues', 'string_long');
  }

  /**
   * Defines a fixture field.
   */
  protected function field(string $entity, string $bundle, string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'entity_type' => $entity, 'field_name' => $name,
      'type' => $type, 'settings' => $settings,
    ])->save();
    FieldConfig::create(['entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $name, 'label' => $name])->save();
  }

  /**
   * Saves an old citizen upload with real image bytes and a screening verdict.
   */
  protected function upload(?File $file = NULL): Media {
    if (!$file) {
      $uri = 'public://orphan-' . bin2hex(random_bytes(4)) . '.png';
      file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
    }
    $media = Media::create([
      'bundle' => 'request_image', 'name' => 'Synthetic image', 'status' => 1,
      'created' => $this->now - 100000, 'changed' => $this->now - 100000,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Test'],
      'field_ai_metadata' => '{"description":"Safe scene"}',
    ]);
    $media->save();
    return $media;
  }

  /**
   * Install and update prepare an empty queue without losing existing work.
   */
  public function testPublicationQueueInitialization(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists(DatabaseQueue::TABLE_NAME));
    $schema->dropTable(DatabaseQueue::TABLE_NAME);
    markaspot_vision_update_11809();
    $this->assertTrue($schema->tableExists(DatabaseQueue::TABLE_NAME));
    $queue = $this->container->get('queue')->get(MediaIntakeScreening::QUEUE);
    $item_id = $queue->createItem(['media_id' => 123]);
    markaspot_vision_install();
    markaspot_vision_update_11809();
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame($item_id, $item->item_id);
    $this->assertSame(['media_id' => 123], $item->data);
  }

  /**
   * New and screened uploads stay private until the real report save.
   */
  public function testPublicationRequiresReport(): void {
    $media = $this->upload();
    $this->assertFalse(Media::load($media->id())->isPublished());
    $media->setPublished()->save();
    $this->assertFalse(Media::load($media->id())->isPublished());
    Node::create([
      'type' => 'service_request', 'title' => 'Test', 'status' => 1,
      'field_request_media' => $media->id(),
    ])->save();
    $this->assertTrue(Media::load($media->id())->isPublished());
  }

  /**
   * Published legacy orphans lose files, originals, derivatives and all stores.
   */
  public function testOldOrphanIsCompletelyDeleted(): void {
    $media = $this->upload();
    $file = $media->get('field_media_image')->entity;
    $uri = $file->getFileUri();
    $this->assertTrue($this->originals->isEnabled());
    $this->originals->retain($media, $uri);
    $original = $this->originals->find((int) $media->id())['uri'];
    $this->assertFileExists($original);
    $style = ImageStyle::create(['name' => 'orphan_test', 'label' => 'Test']);
    $style->save();
    $derivative = $style->buildUri($uri);
    $this->assertTrue($style->createDerivative($uri, $derivative));
    $original_derivative = $style->buildUri($original);
    $this->assertTrue($style->createDerivative($original, $original_derivative));
    foreach ([MediaIntakeScreening::STORE, 'markaspot_vision.report_media_hold'] as $store) {
      $this->container->get('keyvalue')->get($store)->set($media->id(), ['status' => 'pending']);
    }
    $this->container->get('keyvalue')->get(MediaTenantScopeResolver::STORE)->set($media->uuid(), 1);
    $this->container->get('keyvalue.expirable')->get(MediaAnalysisAccessGuard::STORE)->setWithExpire($media->uuid(), 'test', 86400);
    // Model pre-fix data without letting the new presave guard repair it.
    $this->container->get('database')->update('media_field_data')->fields(['status' => 1])->condition('mid', $media->id())->execute();
    // Direct SQL bypasses the entity cache that loadUnchanged() reads.
    $this->container->get('entity_type.manager')->getStorage('media')->resetCache();
    $this->assertSame(1, $this->purger->purge());
    $this->assertNull(Media::load($media->id()));
    $this->assertNull(File::load($file->id()));
    foreach ([$uri, $original, $derivative, $original_derivative] as $path) {
      $this->assertFileDoesNotExist($path);
    }
    $this->assertNull($this->originals->find((int) $media->id()));
    $this->assertSame([], $this->container->get('file.usage')->listUsage($file));
    foreach ([MediaIntakeScreening::STORE, 'markaspot_vision.report_media_hold'] as $store) {
      $this->assertFalse($this->container->get('keyvalue')->get($store)->has($media->id()));
    }
    $this->assertFalse($this->container->get('keyvalue')->get(MediaTenantScopeResolver::STORE)->has($media->uuid()));
    $this->assertFalse($this->container->get('keyvalue.expirable')->get(MediaAnalysisAccessGuard::STORE)->has($media->uuid()));
  }

  /**
   * Default, historical, nondefault and alternate-field references all survive.
   */
  public function testAllReportReferencesAreKept(): void {
    foreach (['current', 'historical', 'pending', 'other'] as $case) {
      $media = $this->upload();
      $field = $case === 'other' ? 'field_other_media' : 'field_request_media';
      $node = Node::create(['type' => 'service_request', 'title' => 'Test', $field => $media->id(), 'status' => 0]);
      $node->save();
      if ($case === 'historical') {
        $node->setNewRevision(TRUE);
        $node->set($field, []);
        $node->save();
      }
      if ($case === 'pending') {
        $node->set($field, [])->save();
        $node->setNewRevision(TRUE);
        $node->isDefaultRevision(FALSE);
        $node->set($field, $media->id())->save();
      }
      $this->assertSame(0, $this->purger->purge(), $case);
      $this->assertNotNull(Media::load($media->id()), $case);
    }
  }

  /**
   * Young, recently changed and editorially controlled media survive.
   */
  public function testGraceAndManualDecision(): void {
    $young = $this->upload();
    $young->setCreatedTime($this->now - 10)->save();
    $changed = $this->upload();
    $changed->setChangedTime($this->now - 10)->save();
    $manual = $this->upload();
    $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($manual->id(), TRUE);
    $this->assertSame(0, $this->purger->purge());
    foreach ([$young, $changed, $manual] as $media) {
      $this->assertNotNull(Media::load($media->id()));
    }
  }

  /**
   * Zero disables collection, a configured positive TTL is respected.
   */
  public function testTtlSetting(): void {
    $media = $this->upload();
    $this->setSetting('markaspot_vision_orphan_upload_ttl', 0);
    $this->assertSame(0, $this->purger->purge());
    $this->setSetting('markaspot_vision_orphan_upload_ttl', 300000);
    $this->assertSame(0, $this->purger->purge());
    $this->setSetting('markaspot_vision_orphan_upload_ttl', 86400);
    $this->assertSame(1, $this->purger->purge());
    $this->assertNull(Media::load($media->id()));
  }

  /**
   * Deleting an orphan must not delete a file used by a surviving media.
   */
  public function testSharedFileSurvives(): void {
    $orphan = $this->upload();
    $file = $orphan->get('field_media_image')->entity;
    $kept = $this->upload($file);
    Node::create(['type' => 'service_request', 'title' => 'Test', 'field_request_media' => $kept->id()])->save();
    $this->assertSame(1, $this->purger->purge());
    $this->assertNotNull(File::load($file->id()));
    $this->assertFileExists($file->getFileUri());
  }

  /**
   * Non-SQL lock backends remain locked until the outer SQL commit.
   */
  public function testReferenceLockOutlivesPostSaveHooks(): void {
    $media = $this->upload();
    $c = $this->container;
    $releases = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    $acquired = [];
    $lock->expects($this->exactly(2))->method('acquire')->willReturnCallback(static function (string $name) use (&$acquired): bool {
      $acquired[] = $name;
      return TRUE;
    });
    $lock->method('release')->willReturnCallback(static function () use (&$releases): void {
      $releases++;
    });
    $purger = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $lock,
      $c->get('state'), $this->clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
    );
    $c->set('markaspot_vision.orphan_upload_purger', $purger);
    $transaction = $c->get('database')->startTransaction();
    // Metadata-only saves must not acquire the worker lock.
    $media->save();
    Node::create(['type' => 'service_request', 'title' => 'Test', 'field_request_media' => $media->id()])->save();
    Node::create(['type' => 'service_request', 'title' => 'Second', 'field_request_media' => $media->id()])->save();
    $this->assertSame(0, $releases);
    unset($transaction);
    $this->assertSame(2, $releases);
    $this->assertSame([
      MediaIntakeScreening::QUEUE . ':' . $media->id(),
      'markaspot_open311.media_publication.' . $media->id(),
    ], $acquired);
  }

  /**
   * Young targets and unchanged references do not contend with screening.
   */
  public function testYoungAndUnchangedReferencesDoNotLock(): void {
    $media = $this->upload();
    $node = Node::create(['type' => 'service_request', 'title' => 'Old', 'field_request_media' => $media->id()]);
    $node->save();
    $young = $this->upload();
    $young->setCreatedTime($this->now)->save();
    $c = $this->container;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->never())->method('acquire');
    $lock->expects($this->never())->method('release');
    $purger = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $lock,
      $c->get('state'), $this->clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
    );
    $c->set('markaspot_vision.orphan_upload_purger', $purger);
    $media->setUnpublished()->save();
    $node->setTitle('Updated')->save();
    Node::create(['type' => 'service_request', 'title' => 'Young', 'field_request_media' => $young->id()])->save();
  }

  /**
   * References in deleted field tables still protect the uploaded media.
   */
  public function testDeletedFieldReferenceIsKept(): void {
    $media = $this->upload();
    Node::create(['type' => 'service_request', 'title' => 'Test', 'field_other_media' => $media->id()])->save();
    FieldConfig::load('node.service_request.field_other_media')->delete();
    $this->assertSame(0, $this->purger->purge());
    $this->assertNotNull(Media::load($media->id()));
  }

  /**
   * Protected candidates cannot starve later orphans out of the bounded batch.
   */
  public function testBatchCursorPassesProtectedUploads(): void {
    for ($i = 0; $i < 50; $i++) {
      $media = $this->upload();
      $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($media->id(), TRUE);
    }
    $orphan = $this->upload();
    $this->assertSame(0, $this->purger->purge());
    $this->assertSame(1, $this->purger->purge());
    $this->assertNull(Media::load($orphan->id()));
  }

  /**
   * A deleted upload cannot be attached from a stale dashboard form.
   */
  public function testStaleReferenceIsDropped(): void {
    $media = $this->upload();
    $this->assertSame(1, $this->purger->purge());
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Test',
      'field_request_media' => $media->id(), 'field_other_media' => $media->id(),
    ]);
    $node->save();
    $this->assertEquals($media->id(), $node->get('field_other_media')->target_id);
    $this->assertTrue(Node::load($node->id())->get('field_request_media')->isEmpty());
  }

  /**
   * Collection between the optimistic read and lock acquisition drops the link.
   */
  public function testPurgeDuringReferenceProtection(): void {
    $media = $this->upload();
    $c = $this->container;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static function () use ($media): bool {
      $media->delete();
      return TRUE;
    });
    $purger = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $lock,
      $c->get('state'), $this->clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
    );
    $c->set('markaspot_vision.orphan_upload_purger', $purger);
    $node = Node::create(['type' => 'service_request', 'title' => 'Race', 'field_request_media' => $media->id()]);
    $node->save();
    $this->assertTrue($node->get('field_request_media')->isEmpty());
  }

  /**
   * A permanently failing candidate cannot starve later media or file retries.
   */
  public function testFailuresDoNotBlockLaterCandidatesOrFileRetry(): void {
    $broken = $this->upload();
    $orphan = $this->upload();
    $file = $orphan->get('field_media_image')->entity;
    $state = $this->container->get('state');
    $state->set('vision_orphan_test.fail_media', (int) $broken->id());
    $state->set('vision_orphan_test.fail_file', (int) $file->id());
    $this->assertSame(1, $this->purger->purge());
    $this->assertNotNull(Media::load($broken->id()));
    $this->assertNull(Media::load($orphan->id()));
    $this->assertNotNull(File::load($file->id()));
    $pending = $this->container->get('keyvalue')->get(OrphanUploadPurger::PENDING_FILES);
    $this->assertTrue($pending->has($orphan->id() . ':' . $file->id()));
    $state->delete('vision_orphan_test.fail_file');
    $this->assertSame(0, $this->purger->purge());
    $this->assertNull(File::load($file->id()));
    $this->assertFalse($pending->has($orphan->id() . ':' . $file->id()));
  }

  /**
   * A parallel cron cannot consume a journal whose media deletion is busy.
   */
  public function testFileRetryWaitsForOriginatingMediaDeletion(): void {
    $media = $this->upload();
    $file = $media->get('field_media_image')->entity;
    $c = $this->container;
    $pending = $c->get('keyvalue')->get(OrphanUploadPurger::PENDING_FILES);
    $key = $media->id() . ':' . $file->id();
    $pending->set($key, ['mid' => (int) $media->id(), 'fid' => (int) $file->id()]);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->with(MediaIntakeScreening::QUEUE . ':' . $media->id(), 900)->willReturn(FALSE);
    $lock->expects($this->never())->method('release');
    $parallel = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $lock,
      $c->get('state'), $this->clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
    );
    $this->assertSame(0, $parallel->purge());
    $this->assertTrue($pending->has($key));
    $media->delete();
    $c->get('state')->set('vision_orphan_test.fail_file', (int) $file->id());
    $this->purger->purge();
    $this->assertTrue($pending->has($key));
    $c->get('state')->delete('vision_orphan_test.fail_file');
    $this->purger->purge();
    $this->assertFalse($pending->has($key));
    $this->assertNull(File::load($file->id()));
  }

  /**
   * Only removal of the final current report unpublishes and clears decisions.
   */
  public function testLastReportRemovalAndDeletionUnpublish(): void {
    $media = $this->upload();
    $first = Node::create([
      'type' => 'service_request', 'title' => 'First', 'status' => 1,
      'field_request_media' => $media->id(),
    ]);
    $first->save();
    $second = Node::create([
      'type' => 'service_request', 'title' => 'Second', 'status' => 1,
      'field_other_media' => $media->id(),
    ]);
    $second->save();
    $manual = $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual');
    $manual->set($media->id(), TRUE);
    $first->setNewRevision(TRUE);
    $first->set('field_request_media', [])->save();
    $this->assertTrue(Media::load($media->id())->isPublished());
    $this->assertTrue($manual->has($media->id()));
    $second->delete();
    $this->assertFalse(Media::load($media->id())->isPublished());
    $this->assertFalse($manual->has($media->id()));
    $this->assertSame(0, $this->purger->purge(), 'Historical references still retain bytes.');
    $first->set('field_request_media', $media->id())->save();
    $this->assertTrue(Media::load($media->id())->isPublished());
    $first->set('field_request_media', [])->save();
    $this->assertFalse(Media::load($media->id())->isPublished());
  }

  /**
   * Post-update batches content without changing configuration or offline data.
   */
  public function testPostUpdateUnpublishesOrphansInBatches(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.post_update.php';
    $media = $this->upload();
    $file = $media->get('field_media_image')->entity;
    $ids = [$media->id()];
    for ($i = 0; $i < 50; $i++) {
      $ids[] = $this->upload($file)->id();
    }
    $attached = array_pop($ids);
    Node::create([
      'type' => 'service_request', 'title' => 'Kept', 'status' => 1,
      'field_request_media' => $attached,
    ])->save();
    $manual = $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual');
    $manual->set($media->id(), TRUE);
    $this->container->get('database')->update('media_field_data')->fields(['status' => 1])->execute();
    // Direct SQL bypasses the entity cache that loadUnchanged() reads.
    $this->container->get('entity_type.manager')->getStorage('media')->resetCache();
    $config = $this->container->get('config.storage');
    $before = $config->readMultiple($config->listAll());
    // A small ID window forces two passes; the referenced image never becomes
    // a candidate, so only the 50 orphans reach the locked check.
    $sandbox = ['window' => 30];
    markaspot_vision_post_update_unpublish_orphan_request_images($sandbox);
    $this->assertLessThan(1, $sandbox['#finished']);
    $this->assertSame(30, $sandbox['processed']);
    $this->assertSame(0, $sandbox['skipped_busy']);
    markaspot_vision_post_update_unpublish_orphan_request_images($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(50, $sandbox['processed']);
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    foreach ($ids as $id) {
      $this->assertFalse($storage->loadUnchanged($id)->isPublished());
    }
    $this->assertTrue($storage->loadUnchanged($attached)->isPublished());
    $this->assertFalse($manual->has($media->id()));
    $this->assertSame($before, $config->readMultiple($config->listAll()));
    Node::create([
      'type' => 'service_request', 'title' => 'Later offline sync', 'status' => 1,
      'field_request_media' => $media->id(),
    ])->save();
    $this->assertTrue($storage->loadUnchanged($media->id())->isPublished());
  }

  /**
   * A deadlock against a parallel save is retried instead of aborting deploy.
   */
  public function testPostUpdateRetriesDeadlocks(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.post_update.php';
    $orphan = $this->upload();
    $this->container->get('database')->update('media_field_data')->fields(['status' => 1])->execute();
    // Direct SQL bypasses the entity cache that loadUnchanged() reads.
    $this->container->get('entity_type.manager')->getStorage('media')->resetCache();
    $c = $this->container;
    $real = $c->get('markaspot_vision.orphan_upload_purger');
    $purger = new class($real) extends OrphanUploadPurger {
      /**
       * Number of unpublishBatch() calls.
       */
      public int $calls = 0;

      /**
       * {@inheritdoc}
       */
      public function __construct(private readonly OrphanUploadPurger $inner) {}

      /**
       * {@inheritdoc}
       */
      public function unreferencedRequestImageIds(int $after, int $upto): array {
        return $this->inner->unreferencedRequestImageIds($after, $upto);
      }

      /**
       * {@inheritdoc}
       *
       * The entity hooks reach the service during the media save: every
       * public method forwards, the parent state was never initialised.
       */
      public function protectReferences(EntityInterface $entity): void {
        $this->inner->protectReferences($entity);
      }

      /**
       * {@inheritdoc}
       */
      public function protectReportDeletion(NodeInterface $report): void {
        $this->inner->protectReportDeletion($report);
      }

      /**
       * {@inheritdoc}
       */
      public function holdsPublicationLock(int $id): bool {
        return $this->inner->holdsPublicationLock($id);
      }

      /**
       * {@inheritdoc}
       */
      public function unpublishDetached(NodeInterface $report, ?NodeInterface $current = NULL): void {
        $this->inner->unpublishDetached($report, $current);
      }

      /**
       * {@inheritdoc}
       */
      public function unpublishIfUnreferenced(int $id): void {
        $this->inner->unpublishIfUnreferenced($id);
      }

      /**
       * {@inheritdoc}
       */
      public function releaseReferences(EntityInterface $entity): void {
        $this->inner->releaseReferences($entity);
      }

      /**
       * {@inheritdoc}
       */
      public function purge(): int {
        return $this->inner->purge();
      }

      /**
       * {@inheritdoc}
       */
      public function unpublishBatch(array $ids): int {
        if (++$this->calls === 1) {
          $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
          $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
          throw new DatabaseExceptionWrapper($pdo->getMessage(), 0, $pdo);
        }
        return $this->inner->unpublishBatch($ids);
      }

    };
    $c->set('markaspot_vision.orphan_upload_purger', $purger);
    $sandbox = [];
    markaspot_vision_post_update_unpublish_orphan_request_images($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(2, $purger->calls);
    $this->assertFalse($c->get('entity_type.manager')->getStorage('media')->loadUnchanged($orphan->id())->isPublished());
    $c->set('markaspot_vision.orphan_upload_purger', $real);
  }

  /**
   * Only lock conflicts count as retryable, other database errors do not.
   */
  public function testRetryableLockErrorClassification(): void {
    $deadlock = new \PDOException('Deadlock');
    $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
    $wait = new \PDOException('wait');
    $wait->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
    $missing = new \PDOException('missing');
    $missing->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];
    $this->assertTrue(OrphanUploadPurger::isRetryableLockError(new DatabaseExceptionWrapper('x', 0, $deadlock)));
    $this->assertTrue(OrphanUploadPurger::isRetryableLockError($wait));
    $this->assertFalse(OrphanUploadPurger::isRetryableLockError(new DatabaseExceptionWrapper('x', 0, $missing)));
    $this->assertFalse(OrphanUploadPurger::isRetryableLockError(new \RuntimeException('Reference storage is not SQL.')));
  }

  /**
   * Busy candidates, including the final ID, cannot abort or stall an update.
   */
  public function testPostUpdateSkipsBusyLocksAndFinishes(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.post_update.php';
    $first = $this->upload();
    $ready = $this->upload();
    $last = $this->upload();
    $c = $this->container;
    $c->get('database')->update('media_field_data')->fields(['status' => 1])->execute();
    // Direct SQL bypasses the entity cache that loadUnchanged() reads.
    $c->get('entity_type.manager')->getStorage('media')->resetCache();
    $manual = $c->get('keyvalue')->get('markaspot_open311.media_publication_manual');
    foreach ([$first, $ready, $last] as $media) {
      $manual->set($media->id(), TRUE);
    }
    $ready_lock = 'markaspot_open311.media_publication.' . $ready->id();
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(3))->method('acquire')
      ->willReturnCallback(static fn (string $name): bool => $name === $ready_lock);
    $lock->expects($this->never())->method('wait');
    $lock->expects($this->once())->method('release')->with($ready_lock);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Orphan image update skipped @count busy images; later lifecycle or purge runs can revisit them.',
      ['@count' => 2],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('markaspot_vision')->willReturn($logger);
    $purger = new OrphanUploadPurger(
      $c->get('entity_type.manager'), $c->get('entity_field.manager'),
      $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
      $c->get('keyvalue'), $c->get('file.usage'), $lock,
      $c->get('state'), $this->clock, $factory, $this->createMock(FeatureScopeResolver::class),
    );
    $c->set('markaspot_vision.orphan_upload_purger', $purger);
    $sandbox = [];
    markaspot_vision_post_update_unpublish_orphan_request_images($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(3, $sandbox['processed']);
    $this->assertSame(2, $sandbox['skipped_busy']);
    $this->assertSame((int) $last->id(), $sandbox['last_mid']);
    $storage = $c->get('entity_type.manager')->getStorage('media');
    foreach ([$first, $last] as $media) {
      $this->assertTrue($storage->loadUnchanged($media->id())->isPublished());
      $this->assertTrue($manual->has($media->id()));
    }
    $this->assertFalse($storage->loadUnchanged($ready->id())->isPublished());
    $this->assertFalse($manual->has($ready->id()));
    // A regular lifecycle pass can handle the skipped images once unlocked.
    $c->set('markaspot_vision.orphan_upload_purger', $this->purger);
    foreach ([$first, $last] as $media) {
      $this->purger->unpublishIfUnreferenced((int) $media->id());
      $this->assertFalse($storage->loadUnchanged($media->id())->isPublished());
      $this->assertFalse($manual->has($media->id()));
    }
  }

  /**
   * Batch queries retain translated/alternate links, but not old revisions.
   */
  public function testPostUpdateUsesAllCurrentReportMediaFields(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.post_update.php';
    ConfigurableLanguage::createFromLangcode('de')->save();
    FieldConfig::loadByName('node', 'service_request', 'field_other_media')->setTranslatable(TRUE)->save();
    $images = [];
    foreach (['current', 'other', 'translated', 'historical', 'pending'] as $case) {
      $images[$case] = $this->upload();
      $field = in_array($case, ['other', 'translated'], TRUE) ? 'field_other_media' : 'field_request_media';
      $node = Node::create([
        'type' => 'service_request', 'title' => 'Test', 'langcode' => 'en',
        $field => $case === 'translated' ? [] : $images[$case]->id(), 'status' => 0,
      ]);
      if ($case === 'translated') {
        $node->addTranslation('de', ['title' => 'Test', $field => $images[$case]->id()]);
      }
      $node->save();
      if (in_array($case, ['historical', 'pending'], TRUE)) {
        $node->setNewRevision(TRUE);
        $node->set($field, [])->save();
        if ($case === 'pending') {
          $node->setNewRevision(TRUE);
          $node->isDefaultRevision(FALSE);
          $node->set($field, $images[$case]->id())->save();
        }
      }
    }
    $this->container->get('database')->update('media_field_data')->fields(['status' => 1])->execute();
    // Direct SQL bypasses the entity cache that loadUnchanged() reads.
    $this->container->get('entity_type.manager')->getStorage('media')->resetCache();
    $manual = $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual');
    foreach ($images as $media) {
      $manual->set($media->id(), TRUE);
    }
    $sandbox = [];
    markaspot_vision_post_update_unpublish_orphan_request_images($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    foreach ($images as $case => $media) {
      $expected = in_array($case, ['current', 'other', 'translated'], TRUE);
      $this->assertSame($expected, $storage->loadUnchanged($media->id())->isPublished(), $case);
      $this->assertSame($expected, $manual->has($media->id()), $case);
    }
  }

  /**
   * Reference removal and deletion hold publication through the outer commit.
   */
  public function testDetachedPublicationLockOutlivesReportTransaction(): void {
    foreach (['remove', 'delete'] as $operation) {
      $media = $this->upload();
      $node = Node::create([
        'type' => 'service_request', 'title' => 'Guard', 'status' => 1,
        'field_request_media' => $media->id(),
      ]);
      $node->save();
      $c = $this->container;
      $released = [];
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->expects($this->once())->method('acquire')->with('markaspot_open311.media_publication.' . $media->id(), 900)->willReturn(TRUE);
      $lock->method('release')->willReturnCallback(static function (string $name) use (&$released): void {
        $released[] = $name;
      });
      $purger = new OrphanUploadPurger(
        $c->get('entity_type.manager'), $c->get('entity_field.manager'),
        $c->get('entity_field.deleted_fields_repository'), $c->get('database'),
        $c->get('keyvalue'), $c->get('file.usage'), $lock,
        $c->get('state'), $this->clock, $c->get('logger.factory'), $this->createMock(FeatureScopeResolver::class),
      );
      $c->set('markaspot_vision.orphan_upload_purger', $purger);
      $transaction = $c->get('database')->startTransaction();
      if ($operation === 'remove') {
        $node->set('field_request_media', [])->save();
      }
      else {
        $node->delete();
      }
      $this->assertTrue($purger->holdsPublicationLock((int) $media->id()));
      $this->assertSame([], $released);
      $this->assertFalse(Media::load($media->id())->isPublished());
      unset($transaction);
      $this->assertSame(['markaspot_open311.media_publication.' . $media->id()], $released);
      $this->assertFalse($purger->holdsPublicationLock((int) $media->id()));
      $c->set('markaspot_vision.orphan_upload_purger', $this->purger);
    }
  }

  /**
   * A delayed analysis result is republished by the post-commit queue worker.
   */
  public function testAnalysisFinishingBeforeReportCommitIsRetried(): void {
    $this->publicationRace(TRUE, FALSE);
  }

  /**
   * Optional screening must still retain a late positive privacy verdict.
   */
  public function testLegacyPublicationRaceRetainsPrivacyVerdict(): void {
    $this->publicationRace(FALSE, TRUE);
  }

  /**
   * Exercises the ordered race with strict or legacy publication policy.
   */
  protected function publicationRace(bool $strict, bool $privacy): void {
    $this->config('markaspot_vision.settings')->set('require_ai_screening', $strict)->save();
    $media = $this->upload();
    $media->setCreatedTime($this->now)->set('field_ai_metadata', [])->save();
    $c = $this->container;
    $queue = $c->get('queue')->get(MediaIntakeScreening::QUEUE);
    $queue->createQueue();
    $controller_lock = $c->get('lock');
    $report_lock = $this->createMock(LockBackendInterface::class);
    $report_lock->expects($this->never())->method('acquire');
    $report_lock->expects($this->never())->method('release');
    $c->set('lock', $report_lock);
    $transaction = $c->get('database')->startTransaction();
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Race', 'status' => 1,
      'field_request_media' => $media->id(),
    ]);
    $node->save();
    $c->set('lock', $controller_lock);
    $this->assertSame(1, $queue->numberOfItems());
    $this->assertFalse(Media::load($media->id())->isPublished());
    // Model the controller's late result: it observed no committed parent,
    // recorded safe metadata, and consequently left the upload unpublished.
    $publication_lock = 'markaspot_open311.media_publication.' . $media->id();
    $this->assertFalse($this->purger->holdsPublicationLock((int) $media->id()));
    $this->assertTrue($c->get('lock')->lockMayBeAvailable($publication_lock));
    // Exercise the controller's actual lock acquisition before its metadata
    // write; a commit-held publication guard would discard this late result.
    $this->assertTrue($c->get('lock')->acquire($publication_lock, 30));
    $media->set('field_ai_metadata', '{"description":"Analysed scene"}')
      ->set('field_ai_privacy_flag', $privacy)->setUnpublished()->save();
    $c->get('lock')->release($publication_lock);
    unset($transaction);
    $this->assertFalse(Media::load($media->id())->isPublished());
    $item = $queue->claimItem();
    $this->assertSame(['media_id' => (int) $media->id()], $item->data);
    $images = $this->createMock(ImageProcessingService::class);
    $images->expects($this->never())->method('processImages');
    $worker = new MediaIntakeScreening(
      $c->get('queue'), $c->get('keyvalue'), $c->get('lock'),
      $c->get('entity_type.manager'), $c->get('config.factory'), $this->clock,
      $images, $this->originals, $c->get('markaspot_vision.media_tenant_scope'),
      $this->createMock(FeatureScopeResolver::class), $c->get('logger.factory'),
    );
    $worker->process($item->data['media_id']);
    $queue->deleteItem($item);
    $this->assertSame(!$privacy, Media::load($media->id())->isPublished());
    $this->assertSame(0, $queue->numberOfItems());

    $this->config('markaspot_vision.settings')->set('require_ai_screening', TRUE)->save();
    // An incomplete retry must not perpetually enqueue itself on each cron.
    $media = Media::load($media->id());
    $media->set('field_ai_metadata', [])->setUnpublished()->save();
    $node->setTitle('Still awaiting analysis')->save();
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $worker->process($item->data['media_id']);
    $queue->deleteItem($item);
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertFalse(Media::load($media->id())->isPublished());
  }

  /**
   * Pre-existing intake work cannot replace a retry in the report transaction.
   */
  public function testPreexistingIntakeItemCannotConsumeReportRetry(): void {
    $this->config('markaspot_vision.settings')->set('imported_image_analysis', 'off')->save();
    $media = $this->upload();
    $media->setCreatedTime($this->now)->set('field_ai_metadata', [])->save();
    $c = $this->container;
    $queue = $c->get('queue')->get(MediaIntakeScreening::QUEUE);
    $queue->createItem(['media_id' => (int) $media->id()]);
    $store = $c->get('keyvalue')->get(MediaIntakeScreening::STORE);
    $store->set($media->id(), ['status' => 'pending', 'queued' => TRUE, 'attempts' => 0]);
    $visible = FALSE;
    // Model a separate worker connection that cannot see the pending report.
    $empty_query = $this->createMock(QueryInterface::class);
    $empty_query->method('accessCheck')->willReturnSelf();
    $empty_query->method('condition')->willReturnSelf();
    $empty_query->method('execute')->willReturn([]);
    $hidden_nodes = $this->createMock(EntityStorageInterface::class);
    $hidden_nodes->method('getQuery')->willReturn($empty_query);
    $hidden_nodes->method('loadMultiple')->willReturn([]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(static function ($type) use (&$visible, $hidden_nodes, $c) {
      return $type === 'node' && !$visible ? $hidden_nodes : $c->get('entity_type.manager')->getStorage($type);
    });
    $images = $this->createMock(ImageProcessingService::class);
    $images->method('blurSensitiveAreas')->willReturn(['blurred' => FALSE, 'processed' => TRUE]);
    $worker = new MediaIntakeScreening(
      $c->get('queue'), $c->get('keyvalue'), $c->get('lock'), $manager,
      $c->get('config.factory'), $this->clock, $images, $this->originals,
      $c->get('markaspot_vision.media_tenant_scope'), $this->createMock(FeatureScopeResolver::class), $c->get('logger.factory'),
    );
    $transaction = $c->get('database')->startTransaction();
    Node::create([
      'type' => 'service_request', 'title' => 'Pending report', 'status' => 1,
      'field_request_media' => $media->id(),
    ])->save();
    $this->assertSame(2, $queue->numberOfItems());
    $first = $queue->claimItem();
    $worker->process($first->data['media_id']);
    $queue->deleteItem($first);
    $this->assertSame('orphaned', $store->get($media->id())['status']);
    unset($transaction);
    $visible = TRUE;
    $this->assertSame(1, $queue->numberOfItems());
    $retry = $queue->claimItem();
    $worker->process($retry->data['media_id']);
    $queue->deleteItem($retry);
    $this->assertSame('done', $store->get($media->id())['status']);
    $this->assertTrue(Media::load($media->id())->isPublished());
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * Publication retry work rolls back together with the report insert.
   */
  public function testPublicationRetryRollsBackWithReport(): void {
    $media = $this->upload();
    $media->set('field_ai_metadata', [])->save();
    $queue = $this->container->get('queue')->get(MediaIntakeScreening::QUEUE);
    $queue->createQueue();
    $transaction = $this->container->get('database')->startTransaction();
    Node::create([
      'type' => 'service_request', 'title' => 'Rollback', 'status' => 1,
      'field_request_media' => $media->id(),
    ])->save();
    $this->assertSame(1, $queue->numberOfItems());
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertFalse($this->purger->holdsPublicationLock((int) $media->id()));
  }

  /**
   * Deleting media before a dashboard save does not break existing reports.
   */
  public function testExistingDanglingReferenceCanBeSaved(): void {
    $media = $this->upload();
    $node = Node::create(['type' => 'service_request', 'title' => 'Before', 'field_request_media' => $media->id()]);
    $node->save();
    $media->delete();
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $node->setTitle('After')->save();
    $this->assertSame('After', Node::load($node->id())->label());
    $this->assertEquals($media->id(), $node->get('field_request_media')->target_id);

    $this->field('user', 'user', 'field_stale_media', 'entity_reference', ['target_type' => 'media']);
    $user = User::create(['name' => 'Synthetic', 'field_stale_media' => $media->id()]);
    $user->save();
    $user->setUsername('Changed')->save();
    $this->assertSame('Changed', $user->getAccountName());
  }

}

/**
 * Includes Vision's actual configuration schema in the isolated kernel.
 */
class OrphanSchemaStorage extends ExtensionInstallStorage {

  /**
   * {@inheritdoc}
   */
  protected function getAllFolders() {
    return ['markaspot_vision.schema' => dirname(__DIR__, 3) . '/config/schema'] + parent::getAllFolders();
  }

}
