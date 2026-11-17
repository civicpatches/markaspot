<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Replaces cached correlation headers on responses that bypass subscribers.
 */
final class CorrelationMiddleware implements HttpKernelInterface {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly CorrelationIdProvider $correlation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    $response = $this->httpKernel->handle($request, $type, $catch);
    $id = $this->correlation->getId($type === self::MAIN_REQUEST ? $request : NULL);
    if ($id !== NULL) {
      $response->headers->set('X-Request-Id', $id);
    }
    return $response;
  }

}
