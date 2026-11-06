<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\markaspot_passwordless\Service\WorkspaceFormLayoutStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and writes the workspace default of the edit form section layout.
 *
 * Route access is TenantSettingsController::accessCheck(): a tenant admin of
 * the jurisdiction or one of its parents, or a platform administrator. The
 * default is stored on the root jurisdiction, so a write through a
 * sub-jurisdiction also requires that access for the root.
 */
final class WorkspaceFormLayoutController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Largest accepted request body in bytes.
   */
  private const MAX_BODY_BYTES = 2048;

  /**
   * Constructs the controller.
   */
  public function __construct(
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    private readonly WorkspaceFormLayoutStore $store,
    private readonly ClassResolverInterface $classResolver,
    private readonly ?DemoStaffAccount $demoAccount = NULL,
  ) {
    $this->currentUser = $account;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_passwordless.workspace_form_layout'),
      $container->get('class_resolver'),
      $container->has('markaspot_fastmap.demo_staff_account')
        ? $container->get('markaspot_fastmap.demo_staff_account') : NULL,
    );
  }

  /**
   * Returns the workspace default that applies to a jurisdiction.
   *
   * GET /api/tenant-settings/{jurisdiction_id}/form-layout
   * 200 {"editLayout": <layout or null>}.
   */
  public function getLayout(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->resolveJurisdictionId($jurisdiction_id);
    if ($jurisdiction === NULL) {
      return $this->respond(['error' => 'Jurisdiction not found.'], 404);
    }
    if ($this->isDemoAccount()) {
      return $this->respond(['error' => 'Demo accounts cannot use this function.'], 403);
    }
    return $this->respond(['editLayout' => $this->store->get($jurisdiction)]);
  }

  /**
   * Replaces or resets the workspace default.
   *
   * PATCH /api/tenant-settings/{jurisdiction_id}/form-layout with exactly
   * {"editLayout": <layout or null>}; NULL resets to the built-in layout.
   * 200 {"editLayout": <stored value>}, 400 for a body that is not JSON or
   * too large, 403 for demo accounts and for a sub-jurisdiction admin without
   * access to the root, 422 for any other body or an invalid layout.
   */
  public function updateLayout(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->resolveJurisdictionId($jurisdiction_id);
    if ($jurisdiction === NULL) {
      return $this->respond(['error' => 'Jurisdiction not found.'], 404);
    }
    if ($this->isDemoAccount()) {
      return $this->respond(['error' => 'Demo accounts cannot use this function.'], 403);
    }

    $content = (string) $request->getContent();
    if (strlen($content) > self::MAX_BODY_BYTES) {
      return $this->respond(['error' => 'Request body is too large.'], 400);
    }
    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return $this->respond(['error' => 'Invalid JSON body.'], 400);
    }
    if (array_keys($data) !== ['editLayout']
      || ($data['editLayout'] !== NULL && !FormLayoutPreferencesController::validLayout($data['editLayout']))) {
      return $this->respond(['error' => 'Invalid form layout.'], 422);
    }

    $root = $this->store->rootId($jurisdiction);
    // A root write passed the route check already; only a write through a
    // sub-jurisdiction needs the access controller.
    if ($root !== $jurisdiction && !self::rootWriteAllowed($this->tenantSettingsAccess(), $this->currentUser, $jurisdiction, $root)) {
      return $this->respond(['error' => 'Only a tenant admin of the root jurisdiction can change the workspace default.'], 403);
    }

    try {
      $stored = $this->store->set($jurisdiction, $data['editLayout']);
    }
    catch (\InvalidArgumentException $e) {
      return $this->respond(['error' => $e->getMessage()], 409);
    }
    return $this->respond(['editLayout' => $stored]);
  }

  /**
   * The root part of the write rule for the workspace default.
   *
   * The default is stored on the root, so a write through a sub-jurisdiction
   * also needs tenant settings access to the root. Together with the route
   * access check on the requested jurisdiction this is the whole PATCH rule;
   * FormLayoutPreferencesController reports the same rule to the edit form as
   * "workspaceDefaultWritable".
   *
   * @param \Drupal\markaspot_nuxt\Controller\TenantSettingsController $access
   *   The controller that owns the tenant settings access rule.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account that wants to write.
   * @param int $jurisdiction
   *   The resolved jurisdiction of the request.
   * @param int $root
   *   The root jurisdiction that stores the default.
   *
   * @return bool
   *   TRUE when the jurisdiction is its own root or the account has tenant
   *   settings access to the root.
   */
  public static function rootWriteAllowed(TenantSettingsController $access, AccountInterface $account, int $jurisdiction, int $root): bool {
    return $root === $jurisdiction || $access->accessCheck($account, (string) $root)->isAllowed();
  }

  /**
   * Whether the current account is the shared staff account of a demo.
   */
  private function isDemoAccount(): bool {
    return $this->demoAccount?->isDemoAccount($this->currentUser) ?? FALSE;
  }

  /**
   * Returns the controller that owns the tenant settings access rule.
   */
  private function tenantSettingsAccess(): TenantSettingsController {
    return $this->classResolver->getInstanceFromDefinition(TenantSettingsController::class);
  }

  /**
   * Builds a response that no shared or browser cache keeps.
   */
  private function respond(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
