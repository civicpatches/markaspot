<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Authentication\AuthenticationCollectorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies cookie credentials and refuses every other applicable provider.
 */
final class StaffIntakeSession {

  /**
   * Constructs the session verifier.
   */
  public function __construct(
    private readonly AuthenticationCollectorInterface $providers,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns a private session binding, never a client-supplied identifier.
   */
  public function hash(Request $request): ?string {
    if (!$this->currentUser->isAuthenticated() || !$request->hasSession()) {
      return NULL;
    }
    try {
      $cookie = $this->providers->getProvider('cookie');
      if (!$cookie || !$cookie->applies($request)) {
        return NULL;
      }
      // Check all providers, including those with their own route filter.
      foreach ($this->providers->getSortedProviders() as $id => $provider) {
        if ($id !== 'cookie' && $provider->applies($request)) {
          return NULL;
        }
      }
      $session = $request->getSession();
      if ($session->getId() === '' || (int) $session->get('uid', 0) !== (int) $this->currentUser->id()) {
        return NULL;
      }
      return hash_hmac('sha256', $session->getId(), Settings::getHashSalt());
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
