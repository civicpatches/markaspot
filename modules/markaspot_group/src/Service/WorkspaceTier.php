<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves workspace entitlements without treating missing SaaS tiers as paid.
 */
final class WorkspaceTier {

  /**
   * Matches the platform mode used by the feature scope resolver.
   */
  public static function isSelfService(ModuleHandlerInterface $modules): bool {
    $mode = Settings::get('markaspot_operating_mode');
    return $mode === 'saas'
      || ($mode !== 'self_hosted' && $modules->moduleExists('markaspot_fastmap'));
  }

  /**
   * Returns a SaaS tier, or the operator-managed tier outside SaaS.
   */
  public static function resolve(GroupInterface $group, bool $selfService): ?string {
    $tier = $group->hasField('field_tier') ? (string) $group->get('field_tier')->value : '';
    if (!$selfService) {
      return $tier !== '' ? $tier : NULL;
    }
    return in_array($tier, ['free', 'starter', 'pro', 'heart'], TRUE) ? $tier : 'free';
  }

  /**
   * Preserves operator-managed case assignment and the explicit showcase.
   */
  public static function allowsCaseAssignment(GroupInterface $group, bool $selfService, bool $showcase): bool {
    $selfService = $selfService && !$showcase;
    $stored = self::resolve($group, FALSE);
    if ($stored === NULL && (!$selfService || $showcase)) {
      return TRUE;
    }
    return in_array(self::resolve($group, $selfService), ['pro', 'heart', 'community', 'premium'], TRUE);
  }

}
