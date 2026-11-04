<?php

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\markaspot_vision\Service\OrphanUploadPurger;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
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
    'media', 'vision_orphan_test',
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
    $lock->expects($this->once())->method('acquire')->with(MediaIntakeScreening::QUEUE . ':' . $media->id(), 900)->willReturn(TRUE);
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
    $this->assertSame(1, $releases);
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
