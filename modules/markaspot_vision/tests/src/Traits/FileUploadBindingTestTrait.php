<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Traits;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Plugin\Validation\Constraint\FileUploadBindingConstraint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Simulates upload and attach requests for the file upload binding.
 *
 * Each HTTP request is a fresh main request on the stack, optionally with a
 * CSRF header and a cookie session of a user.
 */
trait FileUploadBindingTestTrait {

  /**
   * Running number for upload file names.
   */
  private int $uploads = 0;

  /**
   * Makes a new main request current, as a new HTTP request would.
   */
  protected function enter(Request $request, ?AccountInterface $account = NULL): void {
    $stack = $this->container->get('request_stack');
    while ($stack->pop()) {
      // Drop every earlier request, including the bootstrap request.
    }
    $stack->push($request);
    $this->container->get('current_user')->setAccount($account ?? new AnonymousUserSession());
  }

  /**
   * Leaves a request with a session for the base class teardown.
   */
  protected function enterTeardownRequest(): void {
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->enter($request);
  }

  /**
   * Builds a request with an optional CSRF header and user session.
   */
  protected function request(?string $csrf, ?AccountInterface $sessionUser = NULL): Request {
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
  protected function upload(string $scheme): FileInterface {
    $uri = $scheme . '://upload-' . (++$this->uploads) . '.png';
    // Smallest valid PNG, so media metadata and thumbnails see a real image.
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
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
   * Counts the upload binding violations on one field of an entity.
   */
  protected function bindingViolations(FieldableEntityInterface $entity, string $field_name): int {
    $count = 0;
    foreach ($entity->validate()->getByField($field_name) as $violation) {
      if ($violation instanceof ConstraintViolation && $violation->getConstraint() instanceof FileUploadBindingConstraint) {
        $count++;
      }
    }
    return $count;
  }

}
