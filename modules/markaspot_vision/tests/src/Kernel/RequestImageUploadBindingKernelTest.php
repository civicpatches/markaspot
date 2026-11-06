<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Hook\RequestImageUploadBindingHooks;
use Drupal\markaspot_vision\Plugin\Validation\Constraint\RequestImageUploadBindingConstraint;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Binds new request images to the session that uploaded their file.
 *
 * Real file, media and file_usage storage, the real guard, hooks and
 * constraint; each HTTP request is a fresh main request on the stack.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class RequestImageUploadBindingKernelTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'media',
    'vision_upload_binding_test',
  ];

  /**
   * Smallest valid PNG, so media metadata and thumbnails see a real image.
   */
  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

  /**
   * Running number for upload file names.
   */
  private int $uploads = 0;

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
    $container->register(RequestImageUploadBindingHooks::class, RequestImageUploadBindingHooks::class)
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
    foreach (['user', 'file', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media']);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    mkdir($this->siteDirectory . '/private', 0777, TRUE);
    $this->container->get('stream_wrapper_manager')
      ->registerWrapper('private', PrivateStream::class, StreamWrapperInterface::LOCAL_NORMAL);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    // Uid 1 holds every permission; keep it out of the citizen cases.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->createMediaType('image', ['id' => 'request_image', 'label' => 'Request image']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The base class clears the session of the current request.
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->enter($request);
    parent::tearDown();
  }

  /**
   * Another anonymous session cannot claim a fresh upload, on any storage.
   */
  #[DataProvider('schemes')]
  public function testCrossSessionClaimIsRefused(string $scheme): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload($scheme);

    $this->enter($this->request('other-token'));
    $this->assertBindingViolations(1, $this->media($file), 'A second session must not claim the upload.');

    $this->enter($this->request(NULL));
    $this->assertBindingViolations(1, $this->media($file), 'A request without any upload context fails closed.');

    $this->enter($this->request('citizen-token'));
    $own = $this->media($file);
    $this->assertBindingViolations(0, $own, 'The uploading session attaches its own file.');
    $own->save();

    // Once attached, not even the uploading session can attach it twice.
    $this->enter($this->request('citizen-token'));
    $this->assertBindingViolations(1, $this->media($file), 'A file in use cannot be attached again.');
  }

  /**
   * Storage schemes of public and private tenants.
   */
  public static function schemes(): iterable {
    yield 'public' => ['public'];
    yield 'private' => ['private'];
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
    $this->assertBindingViolations(1, $this->media($file), 'A used file is refused for non-editorial accounts.');

    $first->delete();
    $file = File::load($file->id());
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame([], $this->container->get('file.usage')->listUsage($file));
    $this->enter($this->request('other-token'));
    $this->assertBindingViolations(1, $this->media($file), 'An orphaned upload cannot be claimed by another session.');
  }

  /**
   * Editorial accounts may attach any existing file.
   */
  public function testEditorialAccountsBypassTheBinding(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');
    $this->media($file)->save();

    $editor = $this->createUser(['administer media']);
    $this->enter($this->request('editor-token'), $editor);
    $this->assertBindingViolations(0, $this->media($file), 'administer media bypasses usage and binding.');

    $moderator = $this->createUser(['edit any request_image media']);
    $this->enter($this->request('moderator-token'), $moderator);
    $this->assertBindingViolations(1, $this->media($file), 'Editing any request image is no claim on foreign files.');
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
    $this->assertBindingViolations(0, $this->media($file), 'A file born in this request may be attached.');

    // The same file in a later request has no upload context at all.
    $this->enter($this->request(NULL));
    $this->assertBindingViolations(1, $this->media($file), 'Without a record the binding fails closed.');
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
    $this->assertBindingViolations(0, $this->media($file), 'The owner session may attach its upload.');

    $this->enter($this->request('third-token', $other), $other);
    $this->assertBindingViolations(1, $this->media($file), 'Another user session may not.');

    // A shared API-key account has no cookie session: ownership alone is no
    // proof of the upload.
    $this->enter($this->request(NULL), $citizen);
    $this->assertBindingViolations(1, $this->media($file), 'Ownership without a session is not enough.');
  }

  /**
   * Updates only check references that change.
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
    $this->assertBindingViolations(0, $media, 'An unchanged source file is not checked again.');

    $this->enter($this->request('citizen-token'));
    $foreign = $this->upload('public');
    $this->enter($this->request('staff-token'), $this->createUser([]));
    $media->set('field_media_image', ['target_id' => $foreign->id(), 'alt' => 'Replaced']);
    $this->assertBindingViolations(1, $media, 'A replacement source is checked like a new one.');
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
   * Makes a new main request current, as a new HTTP request would.
   */
  private function enter(Request $request, ?AccountInterface $account = NULL): void {
    $stack = $this->container->get('request_stack');
    while ($stack->pop()) {
      // Drop every earlier request, including the bootstrap request.
    }
    $stack->push($request);
    $this->container->get('current_user')->setAccount($account ?? new AnonymousUserSession());
  }

  /**
   * Builds a request with an optional CSRF header and user session.
   */
  private function request(?string $csrf, ?AccountInterface $sessionUser = NULL): Request {
    $request = Request::create('/jsonapi/media/request_image', 'POST');
    if ($csrf !== NULL) {
      $request->headers->set('X-CSRF-Token', $csrf);
    }
    if ($sessionUser) {
      $session = new Session(new MockArraySessionStorage());
      $session->start();
      $session->set('uid', (int) $sessionUser->id());
      $request->setSession($session);
    }
    return $request;
  }

  /**
   * Stores an uploaded image as a temporary file of the current user.
   */
  private function upload(string $scheme): FileInterface {
    $uri = $scheme . '://upload-' . (++$this->uploads) . '.png';
    file_put_contents($uri, base64_decode(self::PNG));
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => 'image/png',
      'uid' => (int) $this->container->get('current_user')->id(),
    ]);
    $file->setTemporary();
    $file->save();
    return $file;
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
   * Asserts the number of upload binding violations on the source field.
   */
  private function assertBindingViolations(int $expected, MediaInterface $media, string $message): void {
    $count = 0;
    foreach ($media->validate()->getByField('field_media_image') as $violation) {
      if ($violation instanceof ConstraintViolation && $violation->getConstraint() instanceof RequestImageUploadBindingConstraint) {
        $count++;
      }
    }
    $this->assertSame($expected, $count, $message);
  }

}
