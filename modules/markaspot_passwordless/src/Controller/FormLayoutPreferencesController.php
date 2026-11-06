<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\markaspot_passwordless\Service\WorkspaceFormLayoutStore;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stores personal form layout preferences, never field configuration or grants.
 *
 * GET also returns the workspace default ("workspaceDefault") of the
 * jurisdiction named in the path, so the edit form can apply the order
 * personal layout, workspace default, built-in layout with one request, and
 * "workspaceDefaultWritable", whether the account may change that default
 * (the PATCH rule of WorkspaceFormLayoutController).
 */
final class FormLayoutPreferencesController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Stable identifiers, deliberately independent of translated labels.
   */
  public const SECTIONS = [
    'details', 'location', 'media', 'status_history', 'status_actions',
    'context', 'internal', 'service_provider', 'contact', 'related',
  ];

  /**
   * Constructs the personal preferences endpoint.
   */
  public function __construct(
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    private readonly UserDataInterface $userData,
    private readonly WorkspaceVisibilityInterface $visibility,
    private readonly ?DemoStaffAccount $demoAccount = NULL,
    private readonly ?WorkspaceFormLayoutStore $workspaceLayouts = NULL,
    private readonly ?ClassResolverInterface $classResolver = NULL,
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
      $container->get('user.data'),
      $container->get('markaspot_group.workspace_visibility'),
      $container->has('markaspot_fastmap.demo_staff_account')
        ? $container->get('markaspot_fastmap.demo_staff_account') : NULL,
      $container->get('markaspot_passwordless.workspace_form_layout'),
      $container->get('class_resolver'),
    );
  }

  /**
   * Reads or replaces the current account's layout for one jurisdiction.
   */
  public function preferences(Request $request, string $jurisdiction_id): JsonResponse {
    if (!$this->currentUser->isAuthenticated()) {
      return $this->respond(['error' => 'Authentication required.'], 403);
    }
    $jurisdiction = $this->resolveJurisdictionId($jurisdiction_id);
    if ($jurisdiction === NULL || !$this->visibility->allowsReadFor($this->currentUser, $jurisdiction)) {
      return $this->respond(['error' => 'Workspace unavailable.'], 403);
    }

    // Shared demo logins must never change another visitor's experience.
    if ($this->demoAccount?->isDemoAccount($this->currentUser)) {
      return $request->isMethodSafe()
        ? $this->respond([
          'layout' => NULL,
          'writable' => FALSE,
          'workspaceDefault' => $this->workspaceDefault($jurisdiction),
          'workspaceDefaultWritable' => FALSE,
        ])
        : $this->respond(['error' => 'Demo accounts keep no preferences.'], 403);
    }

    $uid = (int) $this->currentUser->id();
    $key = 'form_layout.' . $jurisdiction;
    if ($request->isMethodSafe()) {
      $stored = $this->userData->get('markaspot_passwordless', $uid, $key);
      return $this->respond([
        'layout' => self::validLayout($stored) ? $stored : NULL,
        'writable' => TRUE,
        'workspaceDefault' => $this->workspaceDefault($jurisdiction),
        'workspaceDefaultWritable' => $this->workspaceDefaultWritable($jurisdiction),
      ]);
    }

    if (strlen($request->getContent()) > 2048) {
      return $this->respond(['error' => 'Preference is too large.'], 400);
    }
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || array_keys($data) !== ['layout'] ||
      ($data['layout'] !== NULL && !self::validLayout($data['layout']))) {
      return $this->respond(['error' => 'Invalid form layout.'], 400);
    }

    // No caller-supplied uid, user UUID or namespace is accepted.
    if ($data['layout'] === NULL) {
      $this->userData->delete('markaspot_passwordless', $uid, $key);
    }
    else {
      $this->userData->set('markaspot_passwordless', $uid, $key, $data['layout']);
    }
    return $this->respond(['layout' => $data['layout'], 'writable' => TRUE]);
  }

  /**
   * Validates a complete, versioned partition of the known section IDs.
   */
  public static function validLayout(mixed $layout): bool {
    if (!is_array($layout) || count($layout) !== 3 ||
      ($layout['version'] ?? NULL) !== 1 ||
      !isset($layout['left'], $layout['right']) ||
      !is_array($layout['left']) || !is_array($layout['right']) ||
      !array_is_list($layout['left']) || !array_is_list($layout['right'])) {
      return FALSE;
    }
    $ids = array_merge($layout['left'], $layout['right']);
    if (count($ids) !== count(self::SECTIONS)) {
      return FALSE;
    }
    foreach ($ids as $id) {
      if (!is_string($id) || !in_array($id, self::SECTIONS, TRUE)) {
        return FALSE;
      }
    }
    return count(array_unique($ids)) === count(self::SECTIONS);
  }

  /**
   * Returns the workspace default that applies to a jurisdiction.
   *
   * @param int $jurisdiction
   *   The resolved jurisdiction ID; sub-jurisdictions get their root's value.
   *
   * @return array|null
   *   The workspace default, or NULL when none is set.
   */
  private function workspaceDefault(int $jurisdiction): ?array {
    return $this->workspaceLayouts?->get($jurisdiction);
  }

  /**
   * Whether the current account may change the workspace default.
   *
   * The PATCH rule of /api/tenant-settings/{jurisdiction_id}/form-layout:
   * the route's TenantSettingsController::accessCheck() for the jurisdiction
   * plus WorkspaceFormLayoutController::rootWriteAllowed(). Demo accounts
   * never get here; preferences() answers FALSE for them.
   *
   * @param int $jurisdiction
   *   The resolved jurisdiction ID.
   *
   * @return bool
   *   TRUE when a PATCH of the workspace default would be allowed.
   */
  private function workspaceDefaultWritable(int $jurisdiction): bool {
    if ($this->workspaceLayouts === NULL || $this->classResolver === NULL) {
      return FALSE;
    }
    $access = $this->classResolver->getInstanceFromDefinition(TenantSettingsController::class);
    if (!$access instanceof TenantSettingsController
      || !$access->accessCheck($this->currentUser, (string) $jurisdiction)->isAllowed()) {
      return FALSE;
    }
    return WorkspaceFormLayoutController::rootWriteAllowed($access, $this->currentUser, $jurisdiction, $this->workspaceLayouts->rootId($jurisdiction));
  }

  /**
   * Personal preferences must not enter any shared or browser HTTP cache.
   */
  private function respond(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
