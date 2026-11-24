<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /api/fastmap/workspace/{jurisdiction_id}/open: "Open map".
 *
 * The route grants access exactly as the tenant settings routes do
 * (TenantSettingsController::accessCheck(): platform administrators and the
 * tenant admins of this jurisdiction), on the browser's own login session
 * with the CSRF header token. Decisions live in WorkspaceOpening.
 */
class WorkspaceOpeningController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The opening service.
   */
  protected WorkspaceOpening $opening;

  /**
   * The lifecycle record service.
   */
  protected WorkspaceLifecycle $lifecycle;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->opening = $container->get('markaspot_fastmap.workspace_opening');
    $instance->lifecycle = $container->get('markaspot_fastmap.workspace_lifecycle');
    return $instance;
  }

  /**
   * Opens the map. Body: {"confirmed": true}.
   */
  public function open(string $jurisdiction_id, Request $request): JsonResponse {
    $group = $this->loadWorkspace($jurisdiction_id);
    if ($group === NULL) {
      return $this->notApplicable();
    }
    $data = json_decode($request->getContent(), TRUE);
    $confirmed = is_array($data) && ($data['confirmed'] ?? NULL) === TRUE;

    try {
      $result = $this->opening->open($group, $this->currentUser(), $confirmed);
    }
    catch (\Throwable $e) {
      $this->getLogger('markaspot_fastmap')->error('Opening workspace @id failed: @message', [
        '@id' => (string) $group->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->json(['error' => 'The map could not be opened.', 'code' => 'open_failed'], 500);
    }

    return match ($result['status']) {
      WorkspaceOpening::OPENED, WorkspaceOpening::ALREADY_OPEN => $this->json([
        'status' => $result['status'],
        'lifecycle' => $this->lifecycle->dashboardSummary($result['group']),
      ]),
      WorkspaceOpening::CONFIRMATION_REQUIRED => $this->json([
        'error' => 'Confirm that you want to open the map.',
        'code' => WorkspaceOpening::CONFIRMATION_REQUIRED,
      ], 422),
      WorkspaceOpening::BLOCKED => $this->json([
        'error' => 'This map is blocked. Contact support.',
        'code' => WorkspaceOpening::BLOCKED,
      ], 409),
      WorkspaceOpening::BUSY => $this->json([
        'error' => 'The map is being opened. Try again in a moment.',
        'code' => WorkspaceOpening::BUSY,
      ], 409),
      default => $this->notApplicable(),
    };
  }

  /**
   * The answer for a workspace outside the lifecycle model.
   */
  private function notApplicable(): JsonResponse {
    return $this->json([
      'error' => 'This map cannot be opened.',
      'code' => WorkspaceOpening::NOT_APPLICABLE,
    ], 404);
  }

  /**
   * Loads a jurisdiction workspace by ID or slug.
   */
  private function loadWorkspace(string $identifier): ?GroupInterface {
    $id = $this->resolveJurisdictionId($identifier);
    if ($id === NULL) {
      return NULL;
    }
    $group = $this->entityTypeManager()->getStorage('group')->load($id);
    return $this->isJurisdictionGroup($group) ? $group : NULL;
  }

  /**
   * JSON response that is never cached.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private']);
  }

}
