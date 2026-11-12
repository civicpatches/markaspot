<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueDatabaseExpirableFactory;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exercises the verification link window with real tables and a fixed clock.
 *
 * The pending and verified tables, the expirable binding and login token
 * stores and the lock backend are real. The clock is shared by the
 * controller and the expirable stores, so every boundary is exact.
 */
#[Group('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class VerificationWindowKernelTest extends KernelTestBase {

  /**
   * Binding nonce of the synthetic signup browser.
   */
  private const NONCE = '5ad1c0de5ad1c0de5ad1c0de5ad1c0de5ad1c0de5ad1c0de5ad1c0de5ad1c0de';

  /**
   * Binding nonce of a foreign opener, such as a mail link checker.
   */
  private const FOREIGN = '0000000000000000000000000000000000000000000000000000000000000000';

  /**
   * One day in seconds.
   */
  private const DAY = 86400;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Current time of the shared clock.
   */
  private int $now = 1800000000;

  /**
   * Seconds the request time lags behind the current time.
   *
   * Zero models an instantaneous request; a positive value models a request
   * that started earlier than the moment the controller decides.
   */
  private int $requestLag = 0;

  /**
   * The cleanup_days setting the controller reads.
   */
  private mixed $cleanupDays = 7;

  /**
   * Verify tokens taken from the verification mails, keyed by slug.
   *
   * @var array<string, string>
   */
  private array $tokens = [];

  /**
   * Provisioned workspaces: slug => group ID.
   *
   * @var array<string, int>
   */
  private array $groups = [];

  /**
   * The controller under test.
   */
  private FastMapWorkspaceController $controller;

  /**
   * The request stack the controller reads verify requests from.
   */
  private RequestStack $requestStack;

  /**
   * The real expirable key-value factory on the shared clock.
   */
  private KeyValueDatabaseExpirableFactory $expirable;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.install';
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';

    $database = $this->container->get('database');
    $schema = markaspot_fastmap_schema();
    foreach (['markaspot_fastmap_pending', 'markaspot_fastmap_verified'] as $table) {
      $database->schema()->createTable($table, $schema[$table]);
    }

    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getRequestTime')->willReturnCallback(fn(): int => $this->now - $this->requestLag);
    $this->expirable = new KeyValueDatabaseExpirableFactory(new PhpSerialize(), $database, $clock);
    $this->requestStack = new RequestStack();

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(fn(string $key) => match ($key) {
      'service_key' => 'synthetic-service-key',
      'verify_base_url' => 'https://verify.example.test',
      'cleanup_days' => $this->cleanupDays,
      default => NULL,
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      fn(string $name) => $name === 'markaspot_fastmap.settings' ? $settings : $this->createMock(ImmutableConfig::class)
    );

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->method('mail')->willReturnCallback(function (string $module, string $key, string $to, string $langcode, array $params): array {
      if ($key === 'workspace_verification') {
        $this->tokens[$params['workspace_slug']] = substr($params['verify_url'], -64);
      }
      return ['result' => TRUE];
    });

    $provisioning = $this->createMock(WorkspaceProvisioningServiceInterface::class);
    $provisioning->method('provisionWorkspace')->willReturnCallback(function (array $data): array {
      $this->groups[$data['slug']] = 100 + count($this->groups);
      return [
        'group_id' => $this->groups[$data['slug']],
        'slug' => $data['slug'],
        'name' => $data['name'],
        'url' => '/' . $data['slug'],
        'categories' => 1,
        'user_id' => 42,
      ];
    });

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $controller = new FastMapWorkspaceController();
    $services = [
      'database' => $database,
      'provisioning' => $provisioning,
      'mailManager' => $mailManager,
      'fastmapLogger' => new NullLogger(),
      'keyValueExpirable' => $this->expirable,
      'flood' => $flood,
      'lock' => new DatabaseLockBackend($database),
      'requestStack' => $this->requestStack,
      'time' => $clock,
      'configFactory' => $configFactory,
      'entityTypeManager' => $this->entityTypeManager(),
    ];
    foreach ($services as $name => $value) {
      (new \ReflectionProperty($controller, $name))->setValue($controller, $value);
    }
    $this->controller = $controller;
  }

  /**
   * A link verifies at exactly the TTL and expires one second later.
   */
  public function testLinkValidAtTtlAndExpiredOneSecondLater(): void {
    $this->signup('edge-in');
    $this->signup('edge-out');
    $this->now += 7 * self::DAY;

    $inside = $this->verify($this->tokens['edge-in']);
    $this->assertSame(201, $inside['status']);
    $this->assertArrayHasKey('login_token', $inside['body']);

    $this->now += 1;
    $outside = $this->verify($this->tokens['edge-out']);
    $this->assertSame(410, $outside['status']);
    $this->assertSame('Verification token has expired', $outside['body']['error']);
    $this->assertFalse($this->pendingExists('edge-out'));
  }

  /**
   * With cleanup_days = 3, day 2 is valid and day 4 is expired.
   */
  public function testShorterSettingShortensTheLink(): void {
    $this->cleanupDays = 3;
    $this->signup('short-a');
    $this->signup('short-b');

    $this->now += 2 * self::DAY;
    $this->assertSame(201, $this->verify($this->tokens['short-a'])['status']);

    $this->now += 2 * self::DAY;
    $this->assertSame(410, $this->verify($this->tokens['short-b'])['status']);
  }

  /**
   * Cleanup keeps a row at exactly the TTL and removes it one second later.
   */
  public function testCleanupAgreesWithVerification(): void {
    $this->signup('cleanup-edge');
    $token = $this->tokens['cleanup-edge'];

    $this->now += 7 * self::DAY;
    $this->cleanupExpired();
    $this->assertTrue($this->pendingExists('cleanup-edge'));

    $this->now += 1;
    $this->cleanupExpired();
    $this->assertFalse($this->pendingExists('cleanup-edge'));
    // A link whose row was cleaned up first is unknown, not expired.
    $this->assertSame(404, $this->verify($token)['status']);
  }

  /**
   * An expired signup no longer blocks its slug; a valid one still does.
   */
  public function testExpiredSignupReleasesItsSlug(): void {
    $this->signup('reserved');
    $this->now += 7 * self::DAY;
    $this->assertSame(409, $this->signupStatus('reserved'));

    $this->now += 1;
    $this->assertSame(202, $this->signupStatus('reserved'));
    $this->assertSame(1, $this->countPending('reserved'));
  }

  /**
   * The binding lives for the link validity plus the recovery window.
   */
  public function testBindingStoredForValidityPlusRecovery(): void {
    $this->signup('binding-ttl');
    $key = hash('sha256', $this->tokens['binding-ttl']);
    $expire = $this->container->get('database')->select('key_value_expire', 'k')
      ->fields('k', ['expire'])
      ->condition('collection', 'markaspot_fastmap_signup_binding')
      ->condition('name', $key)
      ->execute()
      ->fetchField();

    $this->assertSame($this->now + 7 * self::DAY + self::DAY, (int) $expire);
  }

  /**
   * An expired binding from before the update verifies without a login token.
   */
  public function testExpiredLegacyBindingVerifiesWithoutLoginToken(): void {
    $this->signup('legacy-binding');
    $token = $this->tokens['legacy-binding'];
    // Replace the binding with one stored by the earlier 72 hour rule.
    $this->expirable->get('markaspot_fastmap_signup_binding')
      ->setWithExpire(hash('sha256', $token), hash('sha256', self::NONCE), 3 * self::DAY);

    $this->now += 4 * self::DAY;
    $result = $this->verify($token);

    $this->assertSame(201, $result['status']);
    $this->assertSame('provisioned', $result['body']['status']);
    $this->assertArrayNotHasKey('login_token', $result['body']);
  }

  /**
   * Day 6: a foreign open provisions, then the signup browser recovers.
   */
  public function testForeignOpenOnDaySixLeavesRecoveryToSignupBrowser(): void {
    $this->signup('day-six');
    $token = $this->tokens['day-six'];
    $this->now += 6 * self::DAY;

    $foreign = $this->verify($token, self::FOREIGN);
    $this->assertSame(201, $foreign['status']);
    $this->assertArrayNotHasKey('login_token', $foreign['body']);

    $this->now += 3600;
    $owner = $this->verify($token);
    $this->assertSame(200, $owner['status']);
    $loginToken = $owner['body']['login_token'] ?? NULL;
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $loginToken);

    // A second open before the claim returns the same token.
    $this->now += 60;
    $this->assertSame($loginToken, $this->verify($token)['body']['login_token'] ?? NULL);

    // The signup browser claims it through the controller. The fixture has
    // no user account, so the claim stops with 403 after consuming the token.
    $this->assertSame(403, $this->claim((string) $loginToken)->getStatusCode());
    $this->assertNull($this->expirable->get('markaspot_fastmap_login_tokens')->get((string) $loginToken));
    $this->assertSame(401, $this->claim((string) $loginToken)->getStatusCode());

    // After the claim, a further open hands out no token.
    $reopened = $this->verify($token);
    $this->assertSame(200, $reopened['status']);
    $this->assertArrayNotHasKey('login_token', $reopened['body']);
  }

  /**
   * Recovery works until the last second of its window and not after.
   */
  public function testRecoveryWindowBoundaries(): void {
    $this->signup('recover-in');
    $this->signup('recover-out');
    $this->now += 6 * self::DAY;
    $this->verify($this->tokens['recover-in'], self::FOREIGN);
    $this->verify($this->tokens['recover-out'], self::FOREIGN);

    // VERIFIED_TOKEN_RECOVERY_TTL minus LOGIN_TOKEN_TTL after provisioning.
    $this->now += self::DAY - 300;
    $this->assertArrayHasKey('login_token', $this->verify($this->tokens['recover-in'])['body']);

    $this->now += 1;
    $outside = $this->verify($this->tokens['recover-out']);
    $this->assertSame(200, $outside['status']);
    $this->assertArrayNotHasKey('login_token', $outside['body']);
  }

  /**
   * The binding lifetime follows a shorter setting and is never extended.
   */
  public function testBindingFollowsSettingAndIsNotExtended(): void {
    $this->cleanupDays = 3;
    $this->signup('short-binding');
    $token = $this->tokens['short-binding'];
    $expire = $this->container->get('database')->select('key_value_expire', 'k')
      ->fields('k', ['expire'])
      ->condition('collection', 'markaspot_fastmap_signup_binding')
      ->condition('name', hash('sha256', $token))
      ->execute()
      ->fetchField();
    $this->assertSame($this->now + 4 * self::DAY, (int) $expire);

    // Raising the setting revives the link but not the expired binding.
    $this->cleanupDays = 7;
    $this->now += 5 * self::DAY;
    $result = $this->verify($token);
    $this->assertSame(201, $result['status']);
    $this->assertArrayNotHasKey('login_token', $result['body']);
  }

  /**
   * Expiry is decided by the current time, not by the request start time.
   */
  public function testExpiryUsesCurrentTimeNotRequestTime(): void {
    $this->signup('slow-request');
    // The request started 10 s before the deadline; the controller decides
    // 10 s after it.
    $this->now += 7 * self::DAY + 10;
    $this->requestLag = 20;

    $result = $this->verify($this->tokens['slow-request']);

    $this->assertSame(410, $result['status']);
    $this->assertFalse($this->pendingExists('slow-request'));
  }

  /**
   * Without a claim, the recovered token is gone after its 300 s lifetime.
   */
  public function testRecoveredTokenEndsAfterItsLifetime(): void {
    $this->signup('lifetime');
    $token = $this->tokens['lifetime'];
    $this->now += 6 * self::DAY;
    $this->verify($token, self::FOREIGN);

    $loginToken = $this->verify($token)['body']['login_token'] ?? NULL;
    $this->assertNotNull($loginToken);

    $this->now += 301;
    $this->assertArrayNotHasKey('login_token', $this->verify($token)['body']);
  }

  /**
   * Lowering the setting expires a pending link; raising keeps it valid.
   */
  public function testConfigurationChangeAppliesToPendingRows(): void {
    $this->cleanupDays = 3;
    $this->signup('raised');
    $this->cleanupDays = 7;
    $this->signup('lowered');
    $this->now += 4 * self::DAY;

    $this->cleanupDays = 3;
    $this->assertSame(410, $this->verify($this->tokens['lowered'])['status']);

    $this->cleanupDays = 6;
    $this->assertSame(201, $this->verify($this->tokens['raised'])['status']);
  }

  /**
   * Two signups in one browser share the nonce and both sign in.
   */
  public function testTwoSignupsInOneBrowserBothSignIn(): void {
    $this->signup('first-ws');
    $this->now += self::DAY;
    $this->signup('second-ws');
    $this->now += 5 * self::DAY;

    $first = $this->verify($this->tokens['first-ws']);
    $second = $this->verify($this->tokens['second-ws']);

    $this->assertSame(201, $first['status']);
    $this->assertSame(201, $second['status']);
    $this->assertArrayHasKey('login_token', $first['body']);
    $this->assertArrayHasKey('login_token', $second['body']);
    $this->assertNotSame($first['body']['login_token'], $second['body']['login_token']);
  }

  /**
   * Signs up a workspace from the signup browser and expects a pending row.
   */
  private function signup(string $slug): void {
    $this->assertSame(202, $this->signupStatus($slug));
  }

  /**
   * Posts a signup for $slug from the signup browser.
   */
  private function signupStatus(string $slug): int {
    $body = [
      'service_key' => 'synthetic-service-key',
      'name' => 'Synthetic ' . $slug,
      'slug' => $slug,
      'email' => $slug . '@example.test',
      'categories' => ['Road Damage', 'Flood'],
      'lat' => 50.9,
      'lng' => 6.9,
      'boundary' => ['type' => 'Polygon', 'coordinates' => [[[6.8, 50.8], [7.0, 50.8], [7.0, 51.0], [6.8, 50.8]]]],
    ];
    $request = Request::create('/api/fastmap/create-workspace', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '192.0.2.1',
      'HTTP_X_FASTMAP_SIGNUP_BINDING' => self::NONCE,
    ], json_encode($body, JSON_THROW_ON_ERROR));
    return $this->controller->createWorkspace($request)->getStatusCode();
  }

  /**
   * Opens a verify link as JSON from the browser with the given nonce.
   *
   * @return array{status: int, body: array}
   *   The status code and the decoded body.
   */
  private function verify(string $token, string $nonce = self::NONCE): array {
    $this->requestStack->push(Request::create('/api/fastmap/verify/' . $token, 'GET', [], [], [], [
      'HTTP_X_FASTMAP_RESPONSE_MODE' => 'json',
      'HTTP_X_FASTMAP_SIGNUP_BINDING' => $nonce,
    ]));
    try {
      $response = $this->controller->verifyWorkspace($token);
    }
    finally {
      $this->requestStack->pop();
    }
    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode((string) $response->getContent(), TRUE) ?? [],
    ];
  }

  /**
   * Claims a login token through the controller from the signup browser.
   */
  private function claim(string $loginToken): JsonResponse {
    $request = Request::create('/api/fastmap/claim-login-token', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '192.0.2.1',
      'HTTP_X_FASTMAP_SIGNUP_BINDING' => self::NONCE,
    ], json_encode(['token' => $loginToken], JSON_THROW_ON_ERROR));
    return $this->controller->claimLoginToken($request);
  }

  /**
   * Runs the controller's pending cleanup.
   */
  private function cleanupExpired(): void {
    (new \ReflectionMethod($this->controller, 'cleanupExpired'))->invoke($this->controller);
  }

  /**
   * Counts pending rows for a slug.
   */
  private function countPending(string $slug): int {
    return (int) $this->container->get('database')->select('markaspot_fastmap_pending', 'p')
      ->where("JSON_UNQUOTE(JSON_EXTRACT(p.workspace_data, '$.slug')) = :slug", [':slug' => $slug])
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Whether a pending row exists for a slug.
   */
  private function pendingExists(string $slug): bool {
    return $this->countPending($slug) > 0;
  }

  /**
   * Group and membership storage resolving provisioned workspaces.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')->willReturnCallback(function (array $values): array {
      $gid = $this->groups[$values['field_slug'] ?? ''] ?? NULL;
      if ($gid === NULL) {
        return [];
      }
      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn($gid);
      return [$group];
    });

    $membership = new class {

      /**
       * Checks whether the relationship exposes a field.
       */
      public function hasField(string $fieldName): bool {
        return in_array($fieldName, ['group_roles', 'entity_id'], TRUE);
      }

      /**
       * Returns a minimal field value object.
       */
      public function get(string $fieldName): object {
        return match ($fieldName) {
          'group_roles' => new class {

            /**
             * Returns the relationship role values.
             */
            public function getValue(): array {
              return [['target_id' => 'jur-tenant_admin']];
            }

          },
          'entity_id' => new class {

            /**
             * Referenced user ID in Drupal field-item form.
             */
            // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
            public int $target_id = 42;

          },
        };
      }

    };
    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')->willReturn([$membership]);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(fn(string $type) => match ($type) {
      'group' => $groupStorage,
      'group_relationship' => $relationshipStorage,
      default => $this->createMock(EntityStorageInterface::class),
    });
    return $manager;
  }

}
