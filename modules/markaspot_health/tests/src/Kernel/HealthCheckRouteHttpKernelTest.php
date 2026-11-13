<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the health report route through the HTTP kernel.
 *
 * @group markaspot_health
 */
#[RunTestsInSeparateProcesses]
final class HealthCheckRouteHttpKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter',
    'entity', 'flexible_permissions', 'group', 'gnode',
    'services_api_key_auth', 'markaspot_health',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'node', 'group', 'services_api_key_auth']);
    // The controller reads the profile version; a kernel test has no profile
    // unless one is set, and Core warns on the empty profile list.
    $this->setInstallProfile('minimal');
    // Keep uid 1 out of the way; it bypasses every permission.
    $this->createUser();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Anonymous visitors are refused.
   */
  public function testAnonymousIsRefused(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $response = $this->get('/api/admin/health-check?_format=json');

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringNotContainsString('key-account-unpublished-read', (string) $response->getContent());
  }

  /**
   * An account without the permission is refused.
   */
  public function testAccountWithoutPermissionIsRefused(): void {
    $this->setCurrentUser($this->createUser(['access content']));

    $response = $this->get('/api/admin/health-check?_format=json');

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * An account with the permission gets the report including the new check.
   */
  public function testPermittedAccountGetsReport(): void {
    $this->setCurrentUser($this->createUser(['view health checks']));

    $response = $this->get('/api/admin/health-check?_format=json');

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $report = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $checks = array_column($report['checks'], NULL, 'id');
    $this->assertArrayHasKey('key-account-unpublished-read', $checks);
    $this->assertSame('warning', $checks['key-account-unpublished-read']['severity']);
    $this->assertTrue($checks['key-account-unpublished-read']['passed'], $checks['key-account-unpublished-read']['message']);
  }

  /**
   * Sends a GET request through the HTTP kernel.
   */
  private function get(string $path): Response {
    $request = Request::create($path);
    $request->headers->set('Accept', 'application/json');
    return $this->container->get('http_kernel')->handle($request);
  }

}
