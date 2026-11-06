<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_passwordless\Controller\FormLayoutPreferencesController;
use Drupal\markaspot_passwordless\Controller\WorkspaceFormLayoutController;
use Drupal\markaspot_passwordless\Service\WorkspaceFormLayoutStore;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the workspace default endpoint contract and its storage shape.
 */
#[Group('markaspot_passwordless')]
#[CoversClass(WorkspaceFormLayoutController::class)]
#[CoversClass(WorkspaceFormLayoutStore::class)]
final class WorkspaceFormLayoutTest extends UnitTestCase {

  /**
   * Returns a valid layout.
   */
  private function layout(): array {
    return [
      'version' => 1,
      'left' => ['details', 'location', 'media', 'contact', 'status_history'],
      'right' => ['status_actions', 'context', 'service_provider', 'internal', 'related'],
    ];
  }

  /**
   * Both routes use the tenant settings access rule; writes need CSRF.
   */
  public function testRoutesUseTenantSettingsAccessAndCsrf(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_passwordless.routing.yml');
    $get = $routes['markaspot_passwordless.workspace_form_layout_get'];
    $patch = $routes['markaspot_passwordless.workspace_form_layout_patch'];
    foreach ([$get, $patch] as $definition) {
      $this->assertSame('/api/tenant-settings/{jurisdiction_id}/form-layout', $definition['path']);
      $this->assertSame('\\' . TenantSettingsController::class . '::accessCheck', $definition['requirements']['_custom_access']);
      $this->assertSame('[a-z0-9_-]+', $definition['requirements']['jurisdiction_id']);
      $this->assertSame(['cookie'], $definition['options']['_auth']);
    }
    $this->assertSame(['GET'], $get['methods']);
    $this->assertArrayNotHasKey('_csrf_request_header_token', $get['requirements']);
    $this->assertSame(['PATCH'], $patch['methods']);
    $this->assertSame('TRUE', $patch['requirements']['_csrf_request_header_token']);
  }

  /**
   * The store service definition matches the constructor of this checkout.
   */
  public function testServiceDefinitionMatchesConstructor(): void {
    $services = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_passwordless.services.yml')['services'];
    $definition = $services['markaspot_passwordless.workspace_form_layout'];
    $this->assertSame(WorkspaceFormLayoutStore::class, $definition['class']);
    $constructor = new \ReflectionMethod(WorkspaceFormLayoutStore::class, '__construct');
    $this->assertCount($constructor->getNumberOfParameters(), $definition['arguments']);
  }

  /**
   * Demo sessions never reach the endpoint, whatever the method.
   */
  public function testDemoSessionsAreDeniedThePath(): void {
    foreach (['GET', 'PATCH'] as $method) {
      $this->assertTrue(DemoStaffAccount::isDeniedPath('/api/tenant-settings/demo-x/form-layout', $method));
    }
  }

  /**
   * GET returns the stored default of the resolved jurisdiction.
   */
  public function testGetReturnsStoredDefault(): void {
    $store = $this->createMock(WorkspaceFormLayoutStore::class);
    $store->expects($this->once())->method('get')->with(69)->willReturn($this->layout());
    $response = $this->controller($store)->getLayout(Request::create('/'), '69');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['editLayout' => $this->layout()], json_decode((string) $response->getContent(), TRUE));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * PATCH stores a layout or resets it with NULL.
   */
  public function testPatchStoresAndResets(): void {
    $store = $this->createMock(WorkspaceFormLayoutStore::class);
    $store->method('rootId')->willReturn(69);
    $store->expects($this->exactly(2))->method('set')
      ->willReturnCallback(function (int $jurisdiction, ?array $layout): ?array {
        $this->assertSame(69, $jurisdiction);
        return $layout;
      });
    $controller = $this->controller($store);
    $response = $controller->updateLayout($this->patch(['editLayout' => $this->layout()]), '69');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['editLayout' => $this->layout()], json_decode((string) $response->getContent(), TRUE));
    $response = $controller->updateLayout($this->patch(['editLayout' => NULL]), '69');
    $this->assertSame(['editLayout' => NULL], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Provides bodies that must never reach storage, with their status.
   */
  public static function invalidBodies(): iterable {
    $sections = FormLayoutPreferencesController::SECTIONS;
    yield 'not JSON' => ['{', 400];
    yield 'oversized' => [str_repeat(' ', 2049), 400];
    yield 'JSON scalar' => ['"layout"', 400];
    yield 'empty object' => ['{}', 422];
    yield 'personal endpoint key' => [json_encode(['layout' => NULL]), 422];
    yield 'extra key' => [json_encode(['editLayout' => NULL, 'jurisdiction' => 70]), 422];
    yield 'unknown version' => [json_encode(['editLayout' => [
      'version' => 2, 'left' => $sections, 'right' => [],
    ]]), 422];
    yield 'unknown section' => [json_encode(['editLayout' => [
      'version' => 1, 'left' => array_merge(array_slice($sections, 0, 9), ['invented']), 'right' => [],
    ]]), 422];
    yield 'duplicate section' => [json_encode(['editLayout' => [
      'version' => 1, 'left' => array_merge(array_slice($sections, 0, 9), ['details']), 'right' => [],
    ]]), 422];
    yield 'missing section' => [json_encode(['editLayout' => [
      'version' => 1, 'left' => array_slice($sections, 0, 9), 'right' => [],
    ]]), 422];
  }

  /**
   * Malformed or invalid bodies are rejected before storage.
   */
  #[DataProvider('invalidBodies')]
  public function testInvalidBodiesNeverReachStorage(string $body, int $status): void {
    $store = $this->createMock(WorkspaceFormLayoutStore::class);
    $store->expects($this->never())->method('set');
    $response = $this->controller($store)->updateLayout(Request::create('/', 'PATCH', [], [], [], [], $body), '69');
    $this->assertSame($status, $response->getStatusCode());
  }

  /**
   * A write through a sub-jurisdiction needs tenant settings access to root.
   *
   * Uses the real TenantSettingsController::accessCheck(): a platform
   * administrator passes, an account without admin roles does not.
   */
  public function testSubJurisdictionWriteRequiresRootAccess(): void {
    // Access results validate their cache contexts against the container.
    $cache_contexts = $this->createMock(CacheContextsManager::class);
    $cache_contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts);
    \Drupal::setContainer($container);

    foreach ([[[], 403], [['administrator'], 200]] as [$roles, $status]) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('id')->willReturn(42);
      $account->method('isAuthenticated')->willReturn(TRUE);
      $account->method('getRoles')->willReturn(array_merge(['authenticated'], $roles));
      $store = $this->createMock(WorkspaceFormLayoutStore::class);
      $store->method('rootId')->with(69)->willReturn(1);
      $store->expects($status === 200 ? $this->once() : $this->never())->method('set')->with(69, NULL)->willReturn(NULL);
      $resolver = $this->createMock(ClassResolverInterface::class);
      $resolver->method('getInstanceFromDefinition')->with(TenantSettingsController::class)
        ->willReturn($this->tenantSettings($account));
      $response = $this->controller($store, $resolver, NULL, $account)->updateLayout($this->patch(['editLayout' => NULL]), '69');
      $this->assertSame($status, $response->getStatusCode());
    }
  }

  /**
   * Unknown jurisdictions are reported without touching storage.
   */
  public function testUnknownJurisdiction(): void {
    $store = $this->createMock(WorkspaceFormLayoutStore::class);
    $store->expects($this->never())->method('get');
    $store->expects($this->never())->method('set');
    $controller = $this->controller($store, NULL, NULL, NULL, FALSE);
    $this->assertSame(404, $controller->getLayout(Request::create('/'), '69')->getStatusCode());
    $this->assertSame(404, $controller->updateLayout($this->patch(['editLayout' => NULL]), '69')->getStatusCode());
  }

  /**
   * Shared demo accounts can neither read nor write the workspace default.
   */
  public function testDemoAccountIsForbidden(): void {
    $site_uuid = '11bd3935-672b-41b3-9f90-aefecbc86350';
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn(42);
    $account->method('getAccountName')->willReturn(DemoStaffAccount::accountName('demo-layout'));
    $account->method('uuid')->willReturn(DemoWorkspace::uuid($site_uuid, 'demo-layout', 'user', 'staff'));
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->with(42)->willReturn($account);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($users);
    $demo = new DemoStaffAccount(
      $manager,
      $this->getConfigFactoryStub(['system.site' => ['uuid' => $site_uuid]]),
      $this->createMock(UserSessionRepositoryInterface::class),
      $this->createMock(TimeInterface::class),
    );
    $store = $this->createMock(WorkspaceFormLayoutStore::class);
    $store->expects($this->never())->method('get');
    $store->expects($this->never())->method('set');
    $controller = $this->controller($store, NULL, $demo, $account);
    $this->assertSame(403, $controller->getLayout(Request::create('/'), '69')->getStatusCode());
    $this->assertSame(403, $controller->updateLayout($this->patch(['editLayout' => NULL]), '69')->getStatusCode());
  }

  /**
   * The default lives at staffForms.editLayout; other configuration is kept.
   */
  public function testStoreConfigurationShape(): void {
    $this->assertSame('staffForms', WorkspaceFormLayoutStore::CONFIG_KEY);
    $this->assertSame('editLayout', WorkspaceFormLayoutStore::LAYOUT_KEY);
    $config = [
      'theme' => ['primary' => 'blue'],
      'forms' => ['pickOnMapButton' => TRUE],
      'staffForms' => ['futureSetting' => TRUE],
    ];
    $with = WorkspaceFormLayoutStore::withLayout($config, $this->layout());
    $this->assertSame(['futureSetting' => TRUE, 'editLayout' => $this->layout()], $with['staffForms']);
    // The public "forms" key is left alone.
    $this->assertSame(['pickOnMapButton' => TRUE], $with['forms']);
    $this->assertSame($config['theme'], $with['theme']);
    $this->assertSame($this->layout(), WorkspaceFormLayoutStore::readLayout($with));
    $this->assertSame($config, WorkspaceFormLayoutStore::withLayout($with, NULL));

    // Removing the only key below "staffForms" removes "staffForms" as well.
    $only = WorkspaceFormLayoutStore::withLayout(['theme' => []], $this->layout());
    $this->assertSame(['theme' => [], 'staffForms' => ['editLayout' => $this->layout()]], $only);
    $this->assertSame(['theme' => []], WorkspaceFormLayoutStore::withLayout($only, NULL));
    $this->assertSame(['theme' => []], WorkspaceFormLayoutStore::withLayout(['theme' => []], NULL));

    // The public "forms" key is no storage location, not even for reads.
    $this->assertNull(WorkspaceFormLayoutStore::readLayout(['forms' => ['editLayout' => $this->layout()]]));
    // Stored values that are not valid layouts read as "no default".
    $this->assertNull(WorkspaceFormLayoutStore::readLayout([]));
    $this->assertNull(WorkspaceFormLayoutStore::readLayout(['staffForms' => 'broken']));
    $this->assertNull(WorkspaceFormLayoutStore::readLayout(['staffForms' => ['editLayout' => ['version' => 999]]]));
  }

  /**
   * The public settings response never copies the storage key.
   *
   * MarkASpotSettingsController::getMarkASpotSettings() copies a fixed list
   * of field_nuxt_config keys into the anonymous settings payload. The staff
   * form layout must stay out of it.
   */
  public function testStorageKeyIsNotInPublicSettings(): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/markaspot_nuxt/src/Controller/MarkASpotSettingsController.php');
    $this->assertIsString($source);
    $this->assertSame(1, preg_match('/\$config_keys = \[(.*?)\];/s', $source, $match),
      'The public key list in MarkASpotSettingsController was not found; update this guard.');
    preg_match_all("/'([A-Za-z0-9_]+)'/", $match[1], $keys);
    // The list was parsed: it holds the known public keys.
    $this->assertContains('forms', $keys[1]);
    $this->assertContains('dashboard', $keys[1]);
    $this->assertNotContains(WorkspaceFormLayoutStore::CONFIG_KEY, $keys[1]);
  }

  /**
   * Creates the tenant settings controller with stubbed collaborators.
   */
  private function tenantSettings(AccountInterface $account): TenantSettingsController {
    $root = $this->createMock(GroupInterface::class);
    $root->method('id')->willReturn(1);
    $root->method('bundle')->willReturn('jur');
    $root->method('isPublished')->willReturn(TRUE);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->with(1)->willReturn($root);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($groups);
    return new TenantSettingsController(
      $manager,
      $this->createMock(StreamWrapperManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(FileUsageInterface::class),
      $account,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(FeatureScopeResolver::class),
      $this->createMock(EmailValidator::class),
      NULL,
    );
  }

  /**
   * Builds a PATCH request with a JSON body.
   */
  private function patch(array $body): Request {
    return Request::create('/', 'PATCH', [], [], [], [], json_encode($body));
  }

  /**
   * Creates the controller for jurisdiction 69.
   */
  private function controller(
    WorkspaceFormLayoutStore $store,
    ?ClassResolverInterface $resolver = NULL,
    ?DemoStaffAccount $demo = NULL,
    ?AccountInterface $account = NULL,
    bool $jurisdiction_exists = TRUE,
  ): WorkspaceFormLayoutController {
    if ($account === NULL) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('id')->willReturn(42);
      $account->method('isAuthenticated')->willReturn(TRUE);
    }
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(69);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturn($jurisdiction_exists ? $group : NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($groups);
    $resolver ??= $this->createMock(ClassResolverInterface::class);
    return new WorkspaceFormLayoutController($account, $manager, $store, $resolver, $demo);
  }

}
