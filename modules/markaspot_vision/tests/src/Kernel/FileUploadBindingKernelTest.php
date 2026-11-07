<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Hook\FileUploadBindingHooks;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\markaspot_vision\Traits\FileUploadBindingTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Binds new file references to the session that uploaded the file.
 *
 * Real file, media, node and file_usage storage, the real guard, hooks and
 * constraint; each HTTP request is a fresh main request on the stack.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class FileUploadBindingKernelTest extends KernelTestBase {

  use FileUploadBindingTestTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'media', 'node', 'text', 'filter',
    'vision_upload_binding_test',
  ];

  /**
   * Source field of the catalog image media type.
   */
  private string $catalogSourceField = '';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('markaspot_vision.media_analysis_access_guard', MediaAnalysisAccessGuard::class)
      ->setArguments([
        new Reference('current_user'),
        new Reference('keyvalue.expirable'),
        new Reference('request_stack'),
      ])
      ->setPublic(TRUE);
    $container->register(FileUploadBindingHooks::class, FileUploadBindingHooks::class)
      ->setArguments([new Reference('markaspot_vision.media_analysis_access_guard')])
      ->setPublic(TRUE);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'media', 'node'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media', 'filter']);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    mkdir($this->siteDirectory . '/private', 0777, TRUE);
    $this->container->get('stream_wrapper_manager')
      ->registerWrapper('private', PrivateStream::class, StreamWrapperInterface::LOCAL_NORMAL);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    // Uid 1 holds every permission; keep it out of the citizen cases.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->createMediaType('image', ['id' => 'request_image', 'label' => 'Request image']);
    // A second image type gets a source field with another name.
    $catalog = $this->createMediaType('image', ['id' => 'catalog_image', 'label' => 'Catalog image']);
    $this->catalogSourceField = (string) $catalog->getSource()->getConfiguration()['source_field'];

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $this->createFileField('node', 'service_request', 'field_attachment', 'file', -1, 'public');
    $this->createFileField('node', 'service_request', 'field_request_image', 'image', 1, 'private');
    // Users are no anonymous-writable host of fresh uploads.
    $this->createFileField('user', 'user', 'field_portrait', 'image', 1, 'public');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The base class clears the session of the current request.
    $this->enterTeardownRequest();
    parent::tearDown();
  }

  /**
   * Every file and image field of media and nodes carries the binding.
   */
  public function testEveryFileFieldOfMediaAndNodesIsBound(): void {
    $fields = $this->container->get('entity_field.manager');
    $bound = [
      ['media', 'request_image', 'field_media_image'],
      ['media', 'catalog_image', $this->catalogSourceField],
      ['node', 'service_request', 'field_attachment'],
      ['node', 'service_request', 'field_request_image'],
    ];
    $this->assertNotSame('field_media_image', $this->catalogSourceField);
    foreach ($bound as [$type, $bundle, $name]) {
      $definition = $fields->getFieldDefinitions($type, $bundle)[$name] ?? NULL;
      $this->assertNotNull($definition, "$type.$bundle.$name exists.");
      $this->assertArrayHasKey('FileUploadBinding', $definition->getConstraints(), "$type.$bundle.$name is bound.");
    }
    $portrait = $fields->getFieldDefinitions('user', 'user')['field_portrait'];
    $this->assertArrayNotHasKey('FileUploadBinding', $portrait->getConstraints());
  }

  /**
   * Another anonymous session cannot claim a fresh upload, on any storage.
   */
  #[DataProvider('schemes')]
  public function testCrossSessionClaimIsRefused(string $scheme): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload($scheme);

    $this->enter($this->request('other-token'));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'A second session must not claim the upload.');

    $this->enter($this->request(NULL));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'A request without any upload context fails closed.');

    $this->enter($this->request('citizen-token'));
    $own = $this->media($file);
    $this->assertSame(0, $this->bindingViolations($own, 'field_media_image'), 'The uploading session attaches its own file.');
    $own->save();

    // Once attached, not even the uploading session can attach it twice.
    $this->enter($this->request('citizen-token'));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'A file in use cannot be attached again.');
  }

  /**
   * Storage schemes of public and private tenants.
   */
  public static function schemes(): iterable {
    yield 'public' => ['public'];
    yield 'private' => ['private'];
  }

  /**
   * Sibling bundles and node fields cannot claim a fresh upload either.
   *
   * Any other usage of the file would make the citizen's request image no
   * longer its only, oldest user, so its blur would be refused.
   */
  #[DataProvider('siblings')]
  public function testSiblingReferencesCannotClaimFreshUploads(string $sibling): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request('other-token'));
    [$entity, $field] = $this->sibling($sibling, $file);
    $this->assertSame(1, $this->bindingViolations($entity, $field), 'Another session cannot reference the upload.');

    $this->enter($this->request('citizen-token'));
    [$entity, $field] = $this->sibling($sibling, $file);
    $this->assertSame(0, $this->bindingViolations($entity, $field), 'The uploading session may reference its own file.');
    $request_image = $this->media($file);
    $this->assertSame(0, $this->bindingViolations($request_image, 'field_media_image'), 'The citizen still attaches the photo.');
    $request_image->save();

    // Once the photo is in use, no other reference may be added.
    $this->enter($this->request('citizen-token'));
    [$entity, $field] = $this->sibling($sibling, $file);
    $this->assertSame(1, $this->bindingViolations($entity, $field), 'A file in use cannot be referenced again.');
  }

  /**
   * Hosts that reference files besides the request image source field.
   */
  public static function siblings(): iterable {
    yield 'catalog image media' => ['catalog_image'];
    yield 'node file field' => ['field_attachment'];
    yield 'node image field' => ['field_request_image'];
  }

  /**
   * A file in use, or orphaned by its media, stays out of reach of others.
   */
  public function testUsedAndOrphanedFilesAreRefused(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $first = $this->media($file);
    $first->save();
    $this->assertNotEmpty($this->container->get('file.usage')->listUsage($file));

    $citizen = $this->createUser([]);
    $this->enter($this->request('other-token'), $citizen);
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'A used file is refused for non-editorial accounts.');

    $first->delete();
    $file = File::load($file->id());
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame([], $this->container->get('file.usage')->listUsage($file));
    $this->enter($this->request('other-token'));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'An orphaned upload cannot be claimed by another session.');
  }

  /**
   * Only "administer media" may attach any existing file.
   */
  public function testOnlyMediaAdministratorsBypassTheBinding(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $this->media($file)->save();

    $editor = $this->createUser(['administer media']);
    $this->enter($this->request('editor-token'), $editor);
    $this->assertSame(0, $this->bindingViolations($this->media($file), 'field_media_image'), 'administer media bypasses usage and binding.');
    $this->assertSame(0, $this->bindingViolations($this->node('field_attachment', $file), 'field_attachment'), 'The bypass covers node file fields.');

    $moderator = $this->createUser(['edit any request_image media']);
    $this->enter($this->request('moderator-token'), $moderator);
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'Editing any request image is no claim on foreign files.');

    $editorial = $this->createUser(['bypass node access']);
    $this->enter($this->request('editorial-token'), $editorial);
    $this->assertSame(1, $this->bindingViolations($this->node('field_attachment', $file), 'field_attachment'), 'Bypassing node access is no claim on foreign files.');
  }

  /**
   * Server-side paths that create file and media together need no record.
   */
  public function testFilesCreatedInTheSameRequestNeedNoFingerprint(): void {
    // Open311 media_url import: anonymous or API-key request, no CSRF header
    // and no session; the file is created and validated in one request.
    $this->enter($this->request(NULL));
    $file = $this->upload('private');
    $this->assertFalse($this->container->get('keyvalue.expirable')
      ->get(MediaAnalysisAccessGuard::STORE)->has(MediaAnalysisAccessGuard::FILE_PREFIX . $file->id()));
    $this->assertSame(0, $this->bindingViolations($this->media($file), 'field_media_image'), 'A file born in this request may be attached.');

    // The same file in a later request has no upload context at all.
    $this->enter($this->request(NULL));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'Without a record the binding fails closed.');
  }

  /**
   * A logged-in user's own cookie session may claim the user's upload.
   */
  public function testOwnerSessionClaimsOwnUpload(): void {
    $citizen = $this->createUser([]);
    $other = $this->createUser([]);
    $this->enter($this->request('first-token'), $citizen);
    $file = $this->upload('public');
    $this->assertSame((int) $citizen->id(), (int) $file->getOwnerId());

    // A rotated CSRF token, but the same user's real cookie session.
    $this->enter($this->request('second-token', $citizen), $citizen);
    $this->assertSame(0, $this->bindingViolations($this->media($file), 'field_media_image'), 'The owner session may attach its upload.');

    $this->enter($this->request('third-token', $other), $other);
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'Another user session may not.');

    // A shared API-key account has no cookie session: ownership alone is no
    // proof of the upload.
    $this->enter($this->request(NULL), $citizen);
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'), 'Ownership without a session is not enough.');
  }

  /**
   * Media updates only check references that change.
   */
  public function testUnchangedReferencesAreNotRechecked(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $media = $this->media($file);
    $media->save();

    $this->enter($this->request('staff-token'), $this->createUser([]));
    $media = Media::load($media->id());
    $this->assertInstanceOf(MediaInterface::class, $media);
    $media->setName('Renamed');
    $this->assertSame(0, $this->bindingViolations($media, 'field_media_image'), 'An unchanged source file is not checked again.');

    $this->enter($this->request('citizen-token'));
    $foreign = $this->upload('public');
    $this->enter($this->request('staff-token'), $this->createUser([]));
    $media->set('field_media_image', ['target_id' => $foreign->id(), 'alt' => 'Replaced']);
    $this->assertSame(1, $this->bindingViolations($media, 'field_media_image'), 'A replacement source is checked like a new one.');
  }

  /**
   * Staff add their own upload to a node next to existing attachments.
   *
   * The dashboard sends the whole attachment list on every update.
   */
  public function testNodeUpdateKeepsStoredAttachmentsAndChecksNewOnes(): void {
    $this->enter($this->request('citizen-token'));
    $first = $this->upload('public');
    $node = $this->node('field_attachment', $first);
    $node->save();

    $this->enter($this->request('citizen-token'));
    $foreign = $this->upload('public');

    $staff = $this->createUser([]);
    $this->enter($this->request('staff-token'), $staff);
    $own = $this->upload('public');

    $this->enter($this->request('staff-token'), $staff);
    $node = Node::load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    $node->get('field_attachment')->appendItem(['target_id' => $own->id()]);
    $this->assertSame(0, $this->bindingViolations($node, 'field_attachment'), 'Stored attachments stay and the own upload is added.');

    $node->get('field_attachment')->appendItem(['target_id' => $foreign->id()]);
    $this->assertSame(1, $this->bindingViolations($node, 'field_attachment'), 'A foreign upload is refused on update.');
  }

  /**
   * Deleting a file removes its recorded upload context.
   */
  public function testFileDeletionForgetsTheBinding(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $store = $this->container->get('keyvalue.expirable')->get(MediaAnalysisAccessGuard::STORE);
    $key = MediaAnalysisAccessGuard::FILE_PREFIX . $file->id();
    $this->assertTrue($store->has($key));

    $file->delete();
    $this->assertFalse($store->has($key));
  }

  /**
   * Builds an unsaved request_image media for the file.
   */
  private function media(FileInterface $file): MediaInterface {
    return Media::create([
      'bundle' => 'request_image',
      'name' => $file->getFilename(),
      'status' => 0,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Photo'],
    ]);
  }

  /**
   * Builds an unsaved service request node referencing the file.
   */
  private function node(string $field, FileInterface $file): NodeInterface {
    return Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'status' => 0,
      $field => ['target_id' => $file->id()],
    ]);
  }

  /**
   * Builds an unsaved sibling host referencing the file.
   *
   * @return array{0: \Drupal\Core\Entity\FieldableEntityInterface, 1: string}
   *   The entity and the name of the field that references the file.
   */
  private function sibling(string $sibling, FileInterface $file): array {
    if ($sibling === 'catalog_image') {
      $entity = Media::create([
        'bundle' => 'catalog_image',
        'name' => $file->getFilename(),
        $this->catalogSourceField => ['target_id' => $file->id(), 'alt' => 'Catalog'],
      ]);
      return [$entity, $this->catalogSourceField];
    }
    return [$this->node($sibling, $file), $sibling];
  }

  /**
   * Creates a file or image field.
   */
  private function createFileField(string $entity_type, string $bundle, string $name, string $type, int $cardinality, string $scheme): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => ['uri_scheme' => $scheme],
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'settings' => ['file_extensions' => 'png'],
    ])->save();
  }

}
