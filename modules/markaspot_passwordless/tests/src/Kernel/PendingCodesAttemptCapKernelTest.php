<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the attempt cap holds for every pending code of an address.
 *
 * Parallel code requests can leave more than one code pending. Wrong
 * guesses used to count against the newest code only, so an older one
 * accepted a correct guess after any number of misses.
 */
#[Group('markaspot_passwordless')]
#[RunTestsInSeparateProcesses]
final class PendingCodesAttemptCapKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'markaspot_passwordless'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('markaspot_passwordless', ['markaspot_passwordless_codes']);
    $this->installConfig(['markaspot_passwordless']);
  }

  /**
   * Inserts a pending code row and returns its id.
   */
  private function pendingCode(string $code, int $created): int {
    return (int) $this->container->get('database')
      ->insert('markaspot_passwordless_codes')
      ->fields([
        'email' => 'person@example.test',
        'code' => password_hash($code, PASSWORD_BCRYPT),
        'attempts' => 0,
        'created' => $created,
        'expires' => time() + 1800,
        'verified' => 0,
        'jurisdiction_id' => 0,
      ])
      ->execute();
  }

  /**
   * Misses count against every pending code, so an older one is capped too.
   */
  public function testOlderPendingCodeIsCappedByMisses(): void {
    $older = $this->pendingCode('111111', time() - 60);
    $newer = $this->pendingCode('222222', time());
    $service = $this->container->get('markaspot_passwordless.otp');

    for ($i = 0; $i < 3; $i++) {
      $result = $service->verifyCode('person@example.test', '999999', 0);
      $this->assertSame('Invalid verification code', $result['error']);
    }

    $attempts = $this->container->get('database')
      ->select('markaspot_passwordless_codes', 'c')
      ->fields('c', ['id', 'attempts'])
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame(3, (int) $attempts[$older]);
    $this->assertSame(3, (int) $attempts[$newer]);

    $result = $service->verifyCode('person@example.test', '111111', 0);
    $this->assertFalse($result['success']);
    $this->assertSame('Too many attempts. Please request a new code.', $result['error']);
  }

  /**
   * A miss in one scope does not touch codes of another scope.
   */
  public function testMissesStayInTheirScope(): void {
    $code = $this->pendingCode('111111', time());
    $service = $this->container->get('markaspot_passwordless.otp');

    $service->verifyCode('person@example.test', '999999', 42);

    $attempts = $this->container->get('database')
      ->select('markaspot_passwordless_codes', 'c')
      ->fields('c', ['attempts'])
      ->condition('id', $code)
      ->execute()
      ->fetchField();
    $this->assertSame(0, (int) $attempts);
  }

}
