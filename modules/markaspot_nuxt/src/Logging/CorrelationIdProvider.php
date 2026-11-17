<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Logging;

use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lazily binds one correlation UUID to the main HTTP request.
 */
final class CorrelationIdProvider {

  /**
   * The private main-request attribute used across container rebuilds.
   */
  private const ATTRIBUTE = '_markaspot_correlation_id';

  /**
   * The ID of an HTTP bootstrap log waiting for its main request to be pushed.
   */
  private ?string $pendingId = NULL;

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly UuidInterface $uuid,
    private readonly string $sapi = PHP_SAPI,
  ) {}

  /**
   * Gets the main request's UUID, or NULL outside an HTTP request.
   *
   * The fallback is for middleware responses that bypass the request stack.
   */
  public function getId(?Request $fallback = NULL): ?string {
    $request = $this->requestStack->getMainRequest() ?? $fallback;
    if ($request === NULL) {
      // Drupal may log while building its container, before preHandle() pushes
      // the HTTP request. CLI jobs must never inherit HTTP-like environment.
      if (in_array($this->sapi, ['cli', 'phpdbg'], TRUE)) {
        return NULL;
      }
      return $this->pendingId ??= $this->resolve($_SERVER['HTTP_X_REQUEST_ID'] ?? NULL);
    }

    $id = $request->attributes->get(self::ATTRIBUTE);
    if (!self::isValid($id)) {
      $id = $this->pendingId ?? $this->resolve($request->headers->get('X-Request-Id'));
      $request->attributes->set(self::ATTRIBUTE, $id);
    }
    $this->pendingId = NULL;
    return $id;
  }

  /**
   * Replaces invalid inputs without retaining their raw values.
   */
  private function resolve(mixed $header): string {
    return self::isValid($header) ? strtolower($header) : $this->uuid->generate();
  }

  /**
   * Accepts only a complete RFC 4122 variant UUID v4 header value.
   */
  public static function isValid(mixed $id): bool {
    return is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) === 1;
  }

}
