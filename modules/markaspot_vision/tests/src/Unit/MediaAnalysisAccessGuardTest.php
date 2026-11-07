<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\NullStorageExpirable;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests media analysis access binding.
 */
#[CoversClass(MediaAnalysisAccessGuard::class)]
#[Group('markaspot_vision')]
class MediaAnalysisAccessGuardTest extends UnitTestCase {

  /**
   * Creates a mocked request_image media entity.
   */
  protected function createMedia(bool $updateAccess = FALSE, int $ownerId = 0): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('request_image');
    $media->method('uuid')->willReturn('media-uuid');
    $media->method('getOwnerId')->willReturn($ownerId);
    $media->method('access')
      ->with('update')
      ->willReturn($updateAccess);
    return $media;
  }

  /**
   * Creates a request with a CSRF token header.
   */
  protected function createRequest(string $token): Request {
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('X-CSRF-Token', $token);
    return $request;
  }

  /**
   * Creates a guard with the given store.
   */
  protected function createGuard(
    KeyValueStoreExpirableInterface $store,
    bool $isAnonymous = TRUE,
    int $uid = 0,
    ?RequestStack $requestStack = NULL,
  ): MediaAnalysisAccessGuard {
    new Settings(['hash_salt' => 'markaspot-vision-test-salt']);

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($isAnonymous);
    $account->method('id')->willReturn($uid);

    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')
      ->with(MediaAnalysisAccessGuard::STORE)
      ->willReturn($store);

    return new MediaAnalysisAccessGuard($account, $factory, $requestStack ?? new RequestStack());
  }

  /**
   * Creates an in-memory expirable key/value store for unit tests.
   */
  protected function createMemoryStore(): KeyValueStoreExpirableInterface {
    return new class(MediaAnalysisAccessGuard::STORE) extends NullStorageExpirable {

      /**
       * Stored values.
       *
       * @var array<string, mixed>
       */
      protected array $values = [];

      /**
       * {@inheritdoc}
       */
      public function get($key, $default = NULL) {
        return $this->values[$key] ?? $default;
      }

      /**
       * {@inheritdoc}
       */
      public function setWithExpire($key, $value, $expire) {
        $this->values[$key] = $value;
      }

      /**
       * {@inheritdoc}
       */
      public function has($key) {
        return array_key_exists($key, $this->values);
      }

      /**
       * {@inheritdoc}
       */
      public function delete($key) {
        unset($this->values[$key]);
      }

    };
  }

  /**
   * Shared API keys alone do not authorize image analysis.
   */
  public function testApiKeyUpdateAccessWithoutUploadFingerprintIsDenied(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);

    $guard = $this->createGuard($store, FALSE, 42);
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('apikey', 'shared-api-key');

    $this->assertFalse($guard->canAnalyze($this->createMedia(TRUE, 42), $request));
  }

  /**
   * Matching upload fingerprints authorize image analysis.
   */
  public function testMatchingUploadFingerprintAllowsAnalysis(): void {
    $store = $this->createMemoryStore();
    $guard = $this->createGuard($store);
    $media = $this->createMedia(FALSE);
    $request = $this->createRequest('csrf-token');

    $guard->recordUpload($media, $request);
    $this->assertTrue($guard->canAnalyze($media, $request));
  }

  /**
   * Mismatched upload fingerprints deny image analysis.
   */
  public function testMismatchedUploadFingerprintDeniesAnalysis(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')
      ->with('media-uuid')
      ->willReturn('csrf:not-the-current-token');

    $guard = $this->createGuard($store);
    $this->assertFalse($guard->canAnalyze($this->createMedia(FALSE), $this->createRequest('csrf-token')));
  }

  /**
   * Tests rate limiting uses stable, non-reversible upload identifiers.
   */
  public function testRateLimitIdentifierUsesHashedCsrfToken(): void {
    $guard = $this->createGuard($this->createMemoryStore());

    $identifier = $guard->getRateLimitIdentifier($this->createRequest('csrf-token'));

    $this->assertNotNull($identifier);
    $this->assertStringStartsWith('csrf:', $identifier);
    $this->assertStringNotContainsString('csrf-token', $identifier);
    $this->assertSame(
      $identifier,
      $guard->getRateLimitIdentifier($this->createRequest('csrf-token')),
    );
    $this->assertNotSame(
      $identifier,
      $guard->getRateLimitIdentifier($this->createRequest('different-token')),
    );
  }

  /**
   * Authenticated sessions may analyze media with update access.
   */
  public function testAuthenticatedSessionUpdateAccessAllowsAnalysis(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);

    $session = $this->createMock(SessionInterface::class);
    $session->method('get')->with('uid', 0)->willReturn(42);

    $request = Request::create('/api/vision/analyze', 'POST');
    $request->setSession($session);

    $guard = $this->createGuard($store, FALSE, 42);
    $this->assertTrue($guard->canAnalyze($this->createMedia(TRUE, 42), $request));
  }

  /**
   * Creates a mocked file entity.
   */
  protected function createFile(int $fid, int $ownerId = 0): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($fid);
    $file->method('getOwnerId')->willReturn($ownerId);
    return $file;
  }

  /**
   * Only the uploading session may claim a file from another request.
   */
  public function testFileClaimFollowsTheUploadFingerprint(): void {
    $store = $this->createMemoryStore();
    $stack = new RequestStack();
    $guard = $this->createGuard($store, TRUE, 0, $stack);
    $file = $this->createFile(7);

    $upload = $this->createRequest('citizen-token');
    $stack->push($upload);
    $guard->recordFileUpload($file);
    $this->assertTrue($store->has(MediaAnalysisAccessGuard::FILE_PREFIX . '7'));
    $this->assertStringStartsWith('csrf:', (string) $store->get(MediaAnalysisAccessGuard::FILE_PREFIX . '7'));
    $this->assertStringNotContainsString('citizen-token', (string) $store->get(MediaAnalysisAccessGuard::FILE_PREFIX . '7'));

    // A later request: the main request differs from the upload request.
    $stack->pop();
    $stack->push($this->createRequest('citizen-token'));
    $this->assertTrue($guard->canClaimFile($file));

    $stack->pop();
    $stack->push($this->createRequest('other-token'));
    $this->assertFalse($guard->canClaimFile($file));

    $stack->pop();
    $stack->push(Request::create('/jsonapi/media/request_image', 'POST'));
    $this->assertFalse($guard->canClaimFile($file));

    $guard->forgetFile($file);
    $this->assertFalse($store->has(MediaAnalysisAccessGuard::FILE_PREFIX . '7'));
  }

  /**
   * Files born in the same main request need no fingerprint.
   */
  public function testFileClaimTrustsTheCreatingRequest(): void {
    $store = $this->createMemoryStore();
    $stack = new RequestStack();
    $guard = $this->createGuard($store, TRUE, 0, $stack);
    $file = $this->createFile(8);

    $import = Request::create('/georeport/v2/requests.json', 'POST');
    $stack->push($import);
    $guard->recordFileUpload($file);
    $this->assertFalse($store->has(MediaAnalysisAccessGuard::FILE_PREFIX . '8'));
    $this->assertTrue($guard->canClaimFile($file));

    $stack->pop();
    $stack->push(Request::create('/georeport/v2/requests.json', 'POST'));
    $this->assertFalse($guard->canClaimFile($file));
  }

  /**
   * A user's own cookie session may claim the user's upload.
   */
  public function testFileClaimAllowsTheOwnersCookieSession(): void {
    $store = $this->createMemoryStore();
    $stack = new RequestStack();
    $guard = $this->createGuard($store, FALSE, 42, $stack);

    $session = $this->createMock(SessionInterface::class);
    $session->method('get')->with('uid', 0)->willReturn(42);
    $session->method('getId')->willReturn('session-id');
    $request = Request::create('/jsonapi/media/request_image', 'POST');
    $request->setSession($session);
    $stack->push($request);

    $this->assertTrue($guard->canClaimFile($this->createFile(9, 42)));
    $this->assertFalse($guard->canClaimFile($this->createFile(10, 43)));

    // Without a session the same account is a shared API-key client.
    $stack->pop();
    $stack->push(Request::create('/jsonapi/media/request_image', 'POST'));
    $this->assertFalse($guard->canClaimFile($this->createFile(9, 42)));
  }

  /**
   * Only a CSRF header or a session cookie gives a request an upload context.
   */
  public function testUploadContextNeedsTokenOrSessionCookie(): void {
    $stack = new RequestStack();
    $guard = $this->createGuard($this->createMemoryStore(), TRUE, 0, $stack);
    $this->assertFalse($guard->hasUploadContext(), 'No request, no context.');

    $this->assertTrue($guard->hasUploadContext($this->createRequest('token')));
    $this->assertFalse($guard->hasUploadContext($this->createRequest('  ')), 'An empty token is no context.');

    $session = $this->createMock(SessionInterface::class);
    $session->method('getName')->willReturn('SSESSabc');
    $session->method('getId')->willReturn('lazy-session-id');
    $request = Request::create('/jsonapi/media/request_image', 'POST');
    $request->setSession($session);
    $this->assertFalse($guard->hasUploadContext($request), 'A session the client did not send is no context.');

    $request->cookies->set('SSESSabc', 'session-id');
    $stack->push($request);
    $this->assertTrue($guard->hasUploadContext(), 'A session cookie is.');
  }

}
