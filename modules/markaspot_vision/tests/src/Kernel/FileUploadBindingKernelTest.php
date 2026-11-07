<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Hook\FileUploadBindingHooks;
use Drupal\markaspot_vision\Plugin\Validation\Constraint\FileUploadBindingConstraint;
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
use Symfony\Component\Validator\ConstraintViolation;

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
    // Records a file usage for every data-entity-uuid embed in formatted text.
    'editor',
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
    $this->createFileField('user', 'user', 'field_portrait', 'image', 1, 'public');
    $this->createTextField('body', 'text_with_summary', 1);
    $this->createTextField('field_notes', 'text_long', -1);
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
   * Every writable file and image field of a content entity is bound.
   */
  public function testEveryFileFieldOfContentEntitiesIsBound(): void {
    $fields = $this->container->get('entity_field.manager');
    $bound = [
      ['media', 'request_image', 'field_media_image'],
      ['media', 'catalog_image', $this->catalogSourceField],
      ['node', 'service_request', 'field_attachment'],
      ['node', 'service_request', 'field_request_image'],
      // No list of known hosts: any other content entity type is bound too.
      ['user', 'user', 'field_portrait'],
    ];
    $this->assertNotSame('field_media_image', $this->catalogSourceField);
    foreach ($bound as [$type, $bundle, $name]) {
      $definition = $fields->getFieldDefinitions($type, $bundle)[$name] ?? NULL;
      $this->assertNotNull($definition, "$type.$bundle.$name exists.");
      $this->assertArrayHasKey('FileUploadBinding', $definition->getConstraints(), "$type.$bundle.$name is bound.");
    }

    $unbound = [
      // Read-only: media derive the thumbnail from their source file.
      ['media', 'request_image', 'thumbnail'],
      ['node', 'service_request', 'title'],
      ['user', 'user', 'name'],
    ];
    foreach ($unbound as [$type, $bundle, $name]) {
      $definition = $fields->getFieldDefinitions($type, $bundle)[$name];
      $this->assertArrayNotHasKey('FileUploadBinding', $definition->getConstraints(), "$type.$bundle.$name is not bound.");
    }

    // An override of a read-only base field is skipped like the base field.
    $media_base = $fields->getBaseFieldDefinitions('media');
    BaseFieldOverride::createFromBaseFieldDefinition($media_base['thumbnail'], 'request_image')
      ->setLabel('Request image thumbnail')
      ->save();
    $thumbnail = $fields->getFieldDefinitions('media', 'request_image')['thumbnail'];
    $this->assertInstanceOf(BaseFieldOverride::class, $thumbnail);
    $this->assertArrayNotHasKey('FileUploadBinding', $thumbnail->getConstraints(), 'The overridden thumbnail stays unbound.');

    // A config entity type is never bound, even if handed a field.
    $field = FieldConfig::loadByName('node', 'service_request', 'field_attachment');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setConstraints([]);
    $config_fields = ['field_attachment' => $field];
    $this->container->get(FileUploadBindingHooks::class)->entityBundleFieldInfoAlter(
      $config_fields,
      $this->container->get('entity_type.manager')->getDefinition('node_type'),
      'service_request',
    );
    $this->assertArrayNotHasKey('FileUploadBinding', $field->getConstraints());
  }

  /**
   * A fresh upload cannot be claimed through another content entity type.
   */
  public function testOtherContentEntityTypesCannotClaimFreshUploads(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $portrait = ['target_id' => $file->id(), 'alt' => 'Portrait'];

    $this->enter($this->request('other-token'));
    $account = User::create(['name' => 'portrait', 'field_portrait' => $portrait]);
    $this->assertSame(1, $this->bindingViolations($account, 'field_portrait'), 'A user picture cannot claim a foreign upload.');

    $this->enter($this->request('citizen-token'));
    $account = User::create(['name' => 'portrait', 'field_portrait' => $portrait]);
    $this->assertSame(0, $this->bindingViolations($account, 'field_portrait'), 'The uploading session may use its own file.');
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
   * Formatted text fields carry the binding, plain string fields do not.
   */
  public function testFormattedTextFieldsAreBound(): void {
    $definitions = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'service_request');
    $this->assertArrayHasKey('FileUploadBinding', $definitions['body']->getConstraints());
    $this->assertArrayHasKey('FileUploadBinding', $definitions['field_notes']->getConstraints());
    $this->assertArrayNotHasKey('FileUploadBinding', $definitions['title']->getConstraints());
  }

  /**
   * Another session cannot embed a fresh upload in formatted text.
   *
   * The editor module would record a usage for the embed on save, whatever
   * the text format, and the citizen's request image would no longer be the
   * file's only user. Through a validated save (JSON:API, Open311) the embed
   * is refused, so no usage lands and the citizen still attaches the photo.
   *
   * @param string $field
   *   The text field.
   * @param list<array{value: string, summary?: string}> $items
   *   The field items, with "%s" for the file UUID.
   */
  #[DataProvider('foreignEmbeds')]
  public function testForeignEmbedsAreRefusedBeforeTheEditorRecordsThem(string $field, array $items): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request('other-token'));
    $report = $this->textNode($field, $items, $file);
    $this->assertGreaterThan(0, $this->bindingViolations($report, $field), 'The foreign embed is refused.');
    // A validated save path stops here, so the editor module records nothing.
    $this->assertSame([], $this->container->get('file.usage')->listUsage($file));

    $this->enter($this->request('citizen-token'));
    $request_image = $this->media($file);
    $this->assertSame(0, $this->bindingViolations($request_image, 'field_media_image'), 'The citizen still attaches the photo.');
    $request_image->save();
    // The source field and the thumbnail both count; only the media uses it.
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertSame(['file'], array_keys($usage));
    $this->assertSame([(int) $request_image->id()], array_keys($usage['file']['media'] ?? []));
  }

  /**
   * Embeds the editor module finds, in the forms it accepts them.
   */
  public static function foreignEmbeds(): iterable {
    yield 'quoted span, plain text format' => [
      'body',
      [['value' => '<p>Pothole <span data-entity-type="file" data-entity-uuid="%s">here</span></p>']],
    ];
    yield 'unquoted and upper case' => [
      'field_notes',
      [['value' => '<IMG DATA-ENTITY-TYPE=file DATA-ENTITY-UUID=%s>']],
    ];
    yield 'summary of a text with summary' => [
      'body',
      [['value' => 'Pothole', 'summary' => '<img data-entity-type="file" data-entity-uuid="%s">']],
    ];
    // The editor module parses all items of a field as one string.
    yield 'split over two items' => [
      'field_notes',
      [
        ['value' => '<span data-entity-type="file" '],
        ['value' => 'data-entity-uuid="%s">x</span>'],
      ],
    ];
  }

  /**
   * Media administrators embed any file, and stored embeds stay untouched.
   */
  public function testBypassAndStoredEmbedsStillSave(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $this->media($file)->save();

    $admin = $this->createUser(['administer media']);
    $this->enter($this->request('admin-token'), $admin);
    $page = $this->textNode('body', [['value' => '<img data-entity-type="file" data-entity-uuid="%s">']], $file);
    $this->assertSame(0, $this->bindingViolations($page, 'body'), 'administer media may embed a file in use.');
    $page->save();
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEquals([(int) $page->id() => 1], $usage['editor']['node'] ?? NULL, 'The editor module records the embed.');

    // Anyone else may keep editing the node: the stored embed is not checked.
    $this->enter($this->request('staff-token'), $this->createUser([]));
    $page = Node::load($page->id());
    $this->assertInstanceOf(NodeInterface::class, $page);
    $page->setTitle('Renamed');
    $this->assertSame(0, $this->bindingViolations($page, 'body'), 'An unchanged embed is not checked again.');
    $page->save();

    // A new embed next to the stored one is checked.
    $this->enter($this->request('citizen-token'));
    $foreign = $this->upload('public');
    $this->enter($this->request('staff-token'), $this->createUser([]));
    $page = Node::load($page->id());
    $this->assertInstanceOf(NodeInterface::class, $page);
    $page->set('body', [
      'value' => $page->get('body')->value . sprintf('<img data-entity-type="file" data-entity-uuid="%s">', $foreign->uuid()),
      'format' => 'plain_text',
    ]);
    $this->assertSame(1, $this->bindingViolations($page, 'body'), 'Only the new embed is refused.');

    // Unknown UUIDs record no usage, so they are no claim either.
    $page->set('body', [
      'value' => '<img data-entity-type="file" data-entity-uuid="00000000-0000-4000-8000-000000000000">',
      'format' => 'plain_text',
    ]);
    $this->assertSame(0, $this->bindingViolations($page, 'body'));
  }

  /**
   * An embed saved without validation does not lock the citizen out.
   *
   * Server-side writers save without validation, and the service request
   * presave re-encodes the attributes JSON after it, which turns escaped
   * markup into real markup. The editor module records such embeds, so they
   * must count as no use of the upload.
   */
  public function testEmbedsSavedWithoutValidationDoNotBlockTheUpload(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request('other-token'));
    $escaped = '{"note":"\\u003cimg data-entity-type=file data-entity-uuid=%s\\u003e"}';
    $report = $this->textNode('field_notes', [['value' => $escaped]], $file);
    $this->assertSame(0, $this->bindingViolations($report, 'field_notes'), 'Escaped markup is no embed yet.');
    // What the presave re-encode stores after validation.
    $reencoded = json_encode(json_decode((string) $report->get('field_notes')->value, TRUE));
    $report->set('field_notes', ['value' => $reencoded, 'format' => 'plain_text']);
    $report->save();
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEquals([(int) $report->id() => 1], $usage['editor']['node'] ?? NULL, 'The re-encoded embed was recorded.');

    $this->enter($this->request('citizen-token'));
    $request_image = $this->media($file);
    $this->assertSame(0, $this->bindingViolations($request_image, 'field_media_image'), 'The embed does not block the attach.');
    $request_image->save();

    // The embed is still no claim for anyone else.
    $this->enter($this->request('other-token'));
    $this->assertSame(1, $this->bindingViolations($this->media($file), 'field_media_image'));
  }

  /**
   * The uploading session may embed its upload and still attach it.
   */
  public function testOwnEmbedKeepsTheUploadAttachable(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request('citizen-token'));
    $report = $this->textNode('body', [['value' => '<img data-entity-type="file" data-entity-uuid="%s">']], $file);
    $this->assertSame(0, $this->bindingViolations($report, 'body'), 'The uploading session may embed its file.');
    $report->save();
    $this->assertArrayHasKey('editor', $this->container->get('file.usage')->listUsage($file));

    $this->enter($this->request('citizen-token'));
    $this->assertSame(0, $this->bindingViolations($this->media($file), 'field_media_image'), 'The embed does not count as use.');
  }

  /**
   * A request without token and session cookie is told what it lacks.
   *
   * Uploading again cannot help such a client, so its refusal names the
   * missing X-CSRF-Token header and session cookie. Other refusals keep the
   * one message that says nothing about the file.
   */
  public function testRefusalNamesTheMissingUploadSession(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request(NULL));
    $violation = $this->bindingViolation($this->media($file), 'field_media_image');
    $this->assertSame(FileUploadBindingConstraint::NO_UPLOAD_SESSION_ERROR, $violation->getCode());
    $this->assertStringContainsString('X-CSRF-Token', (string) $violation->getMessage());

    $this->enter($this->request('other-token'));
    $violation = $this->bindingViolation($this->media($file), 'field_media_image');
    $this->assertSame(FileUploadBindingConstraint::REFUSED_ERROR, $violation->getCode());
    $this->assertSame('This file cannot be attached. Please upload it again.', (string) $violation->getMessage());
  }

  /**
   * Returns the single upload binding violation on a field.
   */
  private function bindingViolation(FieldableEntityInterface $entity, string $field_name): ConstraintViolation {
    $found = [];
    foreach ($entity->validate()->getByField($field_name) as $violation) {
      if ($violation instanceof ConstraintViolation && $violation->getConstraint() instanceof FileUploadBindingConstraint) {
        $found[] = $violation;
      }
    }
    $this->assertCount(1, $found);
    return $found[0];
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
   * Builds an unsaved service request with formatted text embedding a file.
   *
   * @param string $field
   *   The text field.
   * @param list<array{value: string, summary?: string}> $items
   *   The field items, with "%s" for the file UUID.
   * @param \Drupal\file\FileInterface $file
   *   The embedded file.
   */
  private function textNode(string $field, array $items, FileInterface $file): NodeInterface {
    $values = [];
    foreach ($items as $item) {
      $values[] = array_map(static fn (string $text): string => sprintf($text, $file->uuid()), $item) + ['format' => 'plain_text'];
    }
    return Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'status' => 0,
      $field => $values,
    ]);
  }

  /**
   * Creates a formatted text field on service requests.
   */
  private function createTextField(string $name, string $type, int $cardinality): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'service_request',
    ])->save();
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
