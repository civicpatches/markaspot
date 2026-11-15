<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException;
use Drupal\markaspot_group\EventSubscriber\EntityStorageExceptionSubscriber;
use Drupal\markaspot_open311\EventSubscriber\GeoreportEventSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests what a refused report answers at the HTTP entrances.
 *
 * The refusal is thrown from the save and wrapped by the entity storage.
 * JSON:API relies on the markaspot_group subscriber to unwrap it; the
 * GeoReport POST resource rethrows the wrapped HttpException itself and its
 * subscriber formats the Open311 error.
 *
 * @group markaspot_fastmap
 */
class WorkspaceNotOpenResponseTest extends UnitTestCase {

  /**
   * The refusal is a 403 that no authentication challenge rewrites.
   */
  public function testForbiddenWithCodeAndNoAccessDenial(): void {
    $exception = new WorkspaceNotOpenException();
    $this->assertSame(403, $exception->getStatusCode());
    $this->assertSame(WorkspaceLifecycleGate::MESSAGE, $exception->getMessage());
    $this->assertStringStartsWith('workspace_not_open:', $exception->getMessage());
    // Core turns an AccessDeniedHttpException of an anonymous request into a
    // 401 challenge where Basic Auth is enabled; this one stays a 403.
    $this->assertNotInstanceOf(AccessDeniedHttpException::class, $exception);
  }

  /**
   * The storage wrapper is unwrapped for JSON:API.
   */
  public function testStorageWrapperIsUnwrapped(): void {
    $refusal = new WorkspaceNotOpenException();
    $event = $this->event('/jsonapi/node/service_request', new EntityStorageException($refusal->getMessage(), 0, $refusal));
    (new EntityStorageExceptionSubscriber())->onException($event);
    $this->assertSame($refusal, $event->getThrowable());
  }

  /**
   * GeoReport POST answers 403 with the code in the Open311 error body.
   */
  public function testGeoreportResponse(): void {
    $path = $this->createMock(CurrentPathStack::class);
    $path->method('getPath')->willReturn('/georeport/v2/requests.json');
    $subscriber = new GeoreportEventSubscriber(new Serializer([], [new JsonEncoder()]), $path);

    $event = $this->event('/georeport/v2/requests.json', new WorkspaceNotOpenException());
    $subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame([
      'error' => [
        'code' => 403,
        'description' => WorkspaceLifecycleGate::MESSAGE,
      ],
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Builds an exception event for a POST to a path.
   */
  private function event(string $path, \Throwable $throwable): ExceptionEvent {
    return new ExceptionEvent(
      $this->createMock(HttpKernelInterface::class),
      Request::create($path, 'POST'),
      HttpKernelInterface::MAIN_REQUEST,
      $throwable,
    );
  }

}
