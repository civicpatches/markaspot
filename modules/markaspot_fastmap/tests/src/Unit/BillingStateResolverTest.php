<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the billing state, with and without the lifecycle record scope.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\BillingStateResolver
 * @group markaspot_fastmap
 */
class BillingStateResolverTest extends UnitTestCase {

  /**
   * Record workspaces read Free, active; old ones read as before.
   *
   * @covers ::resolve
   */
  public function testRecordScope(): void {
    $resolver = new BillingStateResolver();
    $cases = [
      // [tier, customer, subscription, expiry, old state, record state].
      'free, nothing else' => ['free', NULL, NULL, NULL, 'unknown', 'free_permanent'],
      'aborted checkout' => ['free', 'cus_1', NULL, NULL, 'canceled', 'free_permanent'],
      'canceled paid plan' => ['free', 'cus_1', NULL, NULL, 'canceled', 'free_permanent'],
      'no tier, customer' => [NULL, 'cus_1', NULL, NULL, 'pending_checkout', 'free_permanent'],
      'free with subscription' => ['free', 'cus_1', 'sub_1', NULL, 'free_permanent', 'free_permanent'],
      'paid' => ['pro', 'cus_1', 'sub_1', NULL, 'paid', 'paid'],
      'tier change in the portal' => ['starter', 'cus_1', 'sub_1', NULL, 'paid', 'paid'],
      'demo' => [NULL, NULL, NULL, 1800000000, 'demo', 'demo'],
    ];
    foreach ($cases as $label => [$tier, $customer, $subscription, $expiry, $old, $record]) {
      $this->assertSame($old, $resolver->resolve($tier, $customer, $subscription, $expiry), "$label (no record)");
      $this->assertSame($record, $resolver->resolve($tier, $customer, $subscription, $expiry, TRUE), "$label (record)");
    }
  }

}
