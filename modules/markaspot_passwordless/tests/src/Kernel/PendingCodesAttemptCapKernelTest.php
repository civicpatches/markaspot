<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
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
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('markaspot_passwordless.otp')
      ->setClass(CompetingCodeOtpService::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('markaspot_passwordless', ['markaspot_passwordless_codes']);
    $this->installConfig(['markaspot_passwordless']);
  }

  /**
   * Inserts a pending code row and returns its id.
   */
  private function pendingCode(string $code, int $created, int $jurisdiction_id = 0): int {
    return (int) $this->container->get('database')
      ->insert('markaspot_passwordless_codes')
      ->fields([
        'email' => 'person@example.test',
        'code' => password_hash($code, PASSWORD_BCRYPT),
        'attempts' => 0,
        'created' => $created,
        'expires' => time() + 1800,
        'verified' => 0,
        'jurisdiction_id' => $jurisdiction_id,
      ])
      ->execute();
  }

  /**
   * Returns verified state keyed by id, in id order.
   */
  private function verifiedById(): array {
    return array_map('intval', $this->container->get('database')
      ->select('markaspot_passwordless_codes', 'c')
      ->fields('c', ['id', 'verified'])
      ->orderBy('id')
      ->execute()
      ->fetchAllKeyed());
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
   * A miss in one scope counts there and does not touch another scope.
   */
  public function testMissesStayInTheirScope(): void {
    $platform = $this->pendingCode('111111', time());
    $workspace = $this->pendingCode('222222', time(), 42);
    $service = $this->container->get('markaspot_passwordless.otp');

    $service->verifyCode('person@example.test', '999999', 42);

    $attempts = $this->container->get('database')
      ->select('markaspot_passwordless_codes', 'c')
      ->fields('c', ['id', 'attempts'])
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame(1, (int) $attempts[$workspace]);
    $this->assertSame(0, (int) $attempts[$platform]);
  }

  /**
   * A code that appears while a new one is issued is superseded by it.
   *
   * Two requests for one address can interleave between the invalidation
   * and the insert. Only the newest code may stay pending.
   */
  public function testRequestCodeSupersedesCodeIssuedMeanwhile(): void {
    CompetingCodeOtpService::$issueCompetingCode = TRUE;
    try {
      $result = $this->container->get('markaspot_passwordless.otp')
        ->requestCode('person@example.test', 0);
    }
    finally {
      CompetingCodeOtpService::$issueCompetingCode = FALSE;
    }
    $this->assertTrue($result['success']);

    $verified = $this->verifiedById();
    $this->assertCount(2, $verified);
    $ids = array_keys($verified);
    $this->assertSame(2, $verified[$ids[0]], 'The code issued meanwhile is superseded.');
    $this->assertSame(0, $verified[$ids[1]], 'The newest code stays pending.');
  }

}
