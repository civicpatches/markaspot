<?php

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\markaspot_passwordless\Service\BreakGlassOtpServiceInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\markaspot_vision\Service\StaffAnalysisAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests the status capability with the real optional vision budget resolver.
 */
#[CoversClass(PasswordlessAuthController::class)]
#[Group('markaspot_passwordless')]
class VisionStaffBudgetStatusTest extends UnitTestCase {

  /**
   * Status and vision must agree for each account and session context.
   */
  #[DataProvider('budgetProvider')]
  public function testStatusBudget(bool $authenticated, ?string $permission, ?int $sessionUid, string $sessionId, bool $groupStaff, bool $visionInstalled, bool $expected): void {
    $container = $this->buildContainer($authenticated, $permission, $groupStaff);
    if ($visionInstalled) {
      $container->set('markaspot_vision.staff_analysis_access', new StaffAnalysisAccess(
        $container->get('current_user'),
        $container->get('config.factory'),
        $container->get('entity_type.manager'),
      ));
    }
    $request = Request::create('/api/auth/status');
    if ($sessionUid !== NULL) {
      $session = $this->createMock(SessionInterface::class);
      $session->method('getId')->willReturn($sessionId);
      $session->method('get')->with('uid', 0)->willReturn($sessionUid);
      $request->setSession($session);
    }
    new Settings(['hash_salt' => 'status-budget-test-salt']);

    $controller = PasswordlessAuthController::create($container);
    $response = $controller->status($request);
    $this->assertStatus($response, $authenticated, $expected);
    if ($sessionId !== '') {
      $this->assertStringNotContainsString($sessionId, $response->getContent());
    }
    $this->assertStringNotContainsString('sid:', $response->getContent());
  }

  /**
   * Trusted staff, citizens, missing vision and unbound sessions.
   */
  public static function budgetProvider(): iterable {
    yield 'anonymous' => [FALSE, NULL, NULL, 'private-session', FALSE, TRUE, FALSE];
    yield 'anonymous with session' => [FALSE, NULL, 42, 'private-session', FALSE, TRUE, FALSE];
    foreach (['access dashboard notifications', 'administer nodes', 'edit any service_request content'] as $permission) {
      yield $permission => [TRUE, $permission, 42, 'private-session', FALSE, TRUE, TRUE];
    }
    yield 'jurisdiction staff' => [TRUE, NULL, 42, 'private-session', TRUE, TRUE, TRUE];
    yield 'non-staff' => [TRUE, NULL, 42, 'private-session', FALSE, TRUE, FALSE];
    yield 'no session' => [TRUE, 'administer nodes', NULL, 'private-session', FALSE, TRUE, FALSE];
    yield 'unbound session' => [TRUE, 'administer nodes', 0, 'private-session', FALSE, TRUE, FALSE];
    yield 'other uid' => [TRUE, 'administer nodes', 99, 'private-session', FALSE, TRUE, FALSE];
    yield 'empty session id' => [TRUE, 'administer nodes', 42, '', FALSE, TRUE, FALSE];
    yield 'no vision' => [TRUE, 'administer nodes', 42, 'private-session', FALSE, FALSE, FALSE];
    yield 'anonymous without vision' => [FALSE, NULL, NULL, 'private-session', FALSE, FALSE, FALSE];
  }

  /**
   * Optional resolver failures expose no error or private session information.
   */
  #[DataProvider('failureProvider')]
  public function testResolverFailure(bool $constructionFailure, \Throwable $error): void {
    $container = $this->buildContainer(TRUE, 'administer nodes');
    if ($constructionFailure) {
      $failing_container = $this->createMock(ContainerInterface::class);
      $failing_container->method('has')->willReturnCallback(
        fn ($id) => $id === 'markaspot_vision.staff_analysis_access' || $container->has($id));
      $failing_container->method('get')->willReturnCallback(
        function ($id) use ($container, $error) {
          if ($id === 'markaspot_vision.staff_analysis_access') {
            throw $error;
          }
          return $container->get($id);
        });
      $controller = PasswordlessAuthController::create($failing_container);
    }
    else {
      $access = $this->createMock(StaffAnalysisAccess::class);
      $request = Request::create('/api/auth/status');
      $access->expects($this->once())->method('getRateLimitIdentifier')
        ->with($this->identicalTo($request))->willThrowException($error);
      $container->set('markaspot_vision.staff_analysis_access', $access);
      $controller = PasswordlessAuthController::create($container);
    }
    $response = $controller->status($request ?? Request::create('/api/auth/status'));
    $this->assertStatus($response, TRUE, FALSE);
    $this->assertStringNotContainsString($error->getMessage(), $response->getContent());
  }

  /**
   * Both construction and invocation failures must fail closed.
   */
  public static function failureProvider(): iterable {
    yield 'service exception' => [FALSE, new \RuntimeException('private-session-data')];
    yield 'service error' => [FALSE, new \Error('private-session-data')];
    yield 'construction exception' => [TRUE, new \RuntimeException('private-session-data')];
  }

  /**
   * Builds all factory dependencies without requiring vision to be installed.
   */
  private function buildContainer(bool $authenticated, ?string $permission, bool $groupStaff = FALSE): ContainerBuilder {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('id')->willReturn($authenticated ? 42 : 0);
    $account->method('hasPermission')->willReturnCallback(fn ($candidate) => $candidate === $permission);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('group_relationship')->willReturn(TRUE);
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $memberships = [];
    if ($groupStaff) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn('jur');
      $group->method('hasPermission')
        ->with('view unpublished group_node:service_request entity', $account)->willReturn(TRUE);
      $membership = $this->createMock(GroupRelationshipInterface::class);
      $membership->method('getGroup')->willReturn($group);
      $memberships[] = $membership;
    }
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadByProperties')
      ->with(['entity_id' => 42, 'plugin_id' => 'group_membership'])->willReturn($memberships);
    $entities->method('getStorage')->willReturnMap([
      ['user', $user_storage],
      ['group_relationship', $group_storage],
    ]);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('entity_type.manager', $entities);
    $container->set('config.factory', $this->getConfigFactoryStub([
      'markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur'],
    ]));
    foreach ([
      'markaspot_passwordless.otp' => OtpService::class,
      'flood' => FloodInterface::class,
      'session_configuration' => SessionConfigurationInterface::class,
      'keyvalue.expirable' => KeyValueExpirableFactoryInterface::class,
      'markaspot_nuxt.feature_scope_resolver' => FeatureScopeResolver::class,
      'state' => StateInterface::class,
      'markaspot_passwordless.break_glass_otp' => BreakGlassOtpServiceInterface::class,
      'entity.repository' => EntityRepositoryInterface::class,
      'markaspot_nuxt.frontend_url' => FrontendUrlService::class,
      'cache.default' => CacheBackendInterface::class,
      'cache_tags.invalidator.checksum' => CacheTagsChecksumInterface::class,
    ] as $id => $class) {
      $container->set($id, $this->createMock($class));
    }
    \Drupal::setContainer($container);
    return $container;
  }

  /**
   * Checks the shared response contract and its existing cache protections.
   */
  private function assertStatus(CacheableJsonResponse $response, bool $authenticated, bool $expected): void {
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame($authenticated, $data['authenticated']);
    $this->assertSame([
      'view_field_priority' => FALSE,
      'vision_staff_budget' => $expected,
    ], $data['capabilities']);
    $this->assertFalse($data['maintenance_access']);
    $this->assertSame($authenticated, array_key_exists('user', $data));
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $this->assertSame(['user', 'user.permissions'], $response->getCacheableMetadata()->getCacheContexts());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

}
