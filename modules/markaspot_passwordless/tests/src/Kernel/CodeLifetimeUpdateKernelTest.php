<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that extends the sign-in code lifetime.
 */
#[Group('markaspot_passwordless')]
#[RunTestsInSeparateProcesses]
final class CodeLifetimeUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'markaspot_passwordless'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['markaspot_passwordless']);
    $this->container->get('module_handler')->loadInclude('markaspot_passwordless', 'install');
  }

  /**
   * A fresh install ships the 30-minute lifetime.
   */
  public function testInstallDefaultIsThirtyMinutes(): void {
    $this->assertSame(1800, $this->config('markaspot_passwordless.settings')->get('code_lifetime'));
  }

  /**
   * The old default moves to 30 minutes; limits stay untouched.
   */
  public function testOldDefaultIsExtended(): void {
    $this->config('markaspot_passwordless.settings')->set('code_lifetime', 600)->save();
    $before = $this->config('markaspot_passwordless.settings')->get();

    markaspot_passwordless_update_11909();

    $after = $this->config('markaspot_passwordless.settings')->get();
    $this->assertSame(1800, $after['code_lifetime']);
    unset($before['code_lifetime'], $after['code_lifetime']);
    $this->assertSame($before, $after);
  }

  /**
   * A deliberately configured lifetime is kept.
   */
  public function testCustomLifetimeIsKept(): void {
    $this->config('markaspot_passwordless.settings')->set('code_lifetime', 900)->save();

    markaspot_passwordless_update_11909();

    $this->assertSame(900, $this->config('markaspot_passwordless.settings')->get('code_lifetime'));
  }

}
