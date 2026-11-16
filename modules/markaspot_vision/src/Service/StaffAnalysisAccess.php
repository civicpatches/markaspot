<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_nuxt\Service\StaffCapability;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies dashboard staff sessions without trusting shared API accounts.
 */
class StaffAnalysisAccess {

  /**
   * Constructs the staff analysis access resolver.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a private session identifier for authenticated dashboard staff.
   */
  public function getRateLimitIdentifier(Request $request): ?string {
    if (!$this->currentUser->isAuthenticated() || !$request->hasSession()) {
      return NULL;
    }

    try {
      $session = $request->getSession();
      $session_id = $session->getId();
      if ($session_id === '' || (int) $session->get('uid', 0) !== (int) $this->currentUser->id()) {
        return NULL;
      }

      if ($this->hasDashboardAccess()) {
        // Staff budgets follow the real session, never a caller-chosen CSRF
        // header. Separate sessions of the same account get separate budgets.
        return 'sid:' . hash_hmac('sha256', $session_id, Settings::getHashSalt());
      }
    }
    catch (\Throwable) {
      // Incomplete session or membership state must not grant a larger budget.
    }

    return NULL;
  }

  /**
   * Matches the existing global and jurisdiction dashboard staff capabilities.
   */
  protected function hasDashboardAccess(): bool {
    return (new StaffCapability($this->configFactory, $this->entityTypeManager))
      ->hasDashboardAccess($this->currentUser);
  }

}
