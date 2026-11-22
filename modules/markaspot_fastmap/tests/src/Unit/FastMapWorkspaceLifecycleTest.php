<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\AcceptanceRequiredException;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the lifecycle switch at signup, verification and the switch route.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController
 * @group markaspot_fastmap
 */
class FastMapWorkspaceLifecycleTest extends UnitTestCase {

  private const REQUEST_TIME = 1_800_000_000;

  private const TOKEN = 'abababababababababababababababababababababababababababababababab';

  /**
   * The switch value the config mock returns.
   */
  private mixed $switch = NULL;

  /**
   * Fields passed to the pending-row insert, if any.
   */
  private ?array $inserted = NULL;

  /**
   * Conditions passed to delete queries, per table.
   *
   * @var array<string, array<int, array>>
   */
  private array $deleted = [];

  /**
   * Keys deleted from the signup binding store.
   *
   * @var string[]
   */
  private array $bindingDeletes = [];

  /**
   * Expirable key/value contents, per collection.
   *
   * @var array<string, array<string, array{value: mixed, ttl: int, at: int}>>
   */
  private array $keyValue = [];

  /**
   * The clock of the controller's time service.
   */
  private int $now = self::REQUEST_TIME;

  /**
   * Order of the release writes.
   *
   * @var string[]
   */
  private array $events = [];

  /**
   * When set, writing the restart marker fails.
   */
  private bool $markerWriteFails = FALSE;

  /**
   * The pending row the verify select returns.
   */
  private array|false $pendingRow = FALSE;

  /**
   * Mails sent: [module, key, to, langcode, params] per mail.
   *
   * @var array<int, array>
   */
  private array $mails = [];

  /**
   * The provisioning mock.
   */
  private WorkspaceProvisioningServiceInterface&MockObject $provisioning;

  /**
   * The flood mock.
   */
  private FloodInterface&MockObject $flood;

  /**
   * Builds the controller around the current test state.
   */
  private function controller(): FastMapWorkspaceController {
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')->willReturnCallback(fn(string $key) => match ($key) {
      'service_key' => 'test-service-key-123',
      'verify_base_url' => 'https://example.com',
      'cleanup_days' => 7,
      'workspace_base_url' => 'https://maps.example.com',
      'lifecycle_enabled' => $this->switch,
      default => NULL,
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(fn(string $name) => $name === 'markaspot_fastmap.settings'
      ? $fastmapConfig
      : $this->createMock(ImmutableConfig::class));

    $statement = $this->createMock(StatementInterface::class);
    // The slug check sees the pending row while it exists.
    $statement->method('fetchField')->willReturnCallback(fn() => $this->pendingRow ? $this->pendingRow['id'] : FALSE);
    $statement->method('fetchAssoc')->willReturnCallback(fn() => $this->pendingRow);
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'where', 'range', 'condition', 'forUpdate'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(function (array $fields) use ($insert) {
      $this->inserted = $fields;
      return $insert;
    });
    $insert->method('execute')->willReturn('1');

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturnCallback(function (string $table) {
      $delete = $this->createMock(Delete::class);
      $delete->method('condition')->willReturnCallback(function (string $field, $value) use ($delete, $table) {
        $this->deleted[$table][] = [$field => $value];
        if ($table === 'markaspot_fastmap_pending' && $this->pendingRow && $field === 'id' && $value === $this->pendingRow['id']) {
          $this->pendingRow = FALSE;
          $this->events[] = 'row released';
        }
        return $delete;
      });
      $delete->method('execute')->willReturn(1);
      return $delete;
    });
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $database->method('merge')->willReturn($merge);
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);
    $database->method('update')->willReturn($update);
    $database->method('startTransaction')->willReturn(new class {

      /**
       * No-op rollback.
       */
      public function rollBack(): void {}

    });

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')->willReturn([]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(fn(string $type) => $type === 'group'
      ? $groupStorage
      : $this->createMock(EntityStorageInterface::class));

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->method('mail')->willReturnCallback(function (string $module, string $key, string $to, string $langcode, array $params): array {
      $this->mails[] = [$module, $key, $to, $langcode, $params];
      return ['result' => TRUE];
    });

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $keyValue = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyValue->method('get')->willReturnCallback(function (string $collection) {
      $store = $this->createMock(KeyValueStoreExpirableInterface::class);
      // Expires like the real store: gone once its lifetime has passed.
      $store->method('get')->willReturnCallback(function (string $key) use ($collection) {
        $entry = $this->keyValue[$collection][$key] ?? NULL;
        return $entry !== NULL && $this->now < $entry['at'] + $entry['ttl'] ? $entry['value'] : NULL;
      });
      $store->method('setWithExpire')->willReturnCallback(function (string $key, $value, int $ttl) use ($collection): void {
        if ($collection === 'markaspot_fastmap_signup_restart') {
          if ($this->markerWriteFails) {
            throw new \RuntimeException('Key/value store unavailable.');
          }
          $this->events[] = 'marker';
        }
        $this->keyValue[$collection][$key] = ['value' => $value, 'ttl' => $ttl, 'at' => $this->now];
      });
      $store->method('delete')->willReturnCallback(function (string $key) use ($collection): void {
        if ($collection === 'markaspot_fastmap_signup_binding') {
          $this->bindingDeletes[] = $key;
        }
        unset($this->keyValue[$collection][$key]);
      });
      return $store;
    });

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);

    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/api/fastmap/verify/' . self::TOKEN, 'GET', [], [], [], ['HTTP_X_FASTMAP_RESPONSE_MODE' => 'json']));

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('database', $database);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('markaspot_fastmap.workspace_provisioning', $this->provisioning);
    $container->set('markaspot_fastmap.workspace_lifecycle', new WorkspaceLifecycle($configFactory, $time));
    $container->set('plugin.manager.mail', $mailManager);
    $container->set('logger.channel.markaspot_fastmap', $this->createMock(LoggerInterface::class));
    $container->set('keyvalue.expirable', $keyValue);
    $container->set('request_stack', $requestStack);
    $container->set('flood', $this->flood);
    $container->set('lock', $lock);
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);

    return FastMapWorkspaceController::create($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->provisioning = $this->createMock(WorkspaceProvisioningServiceInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(TRUE);
  }

  /**
   * Sends a signup with the given extra payload.
   */
  private function signup(array $extra = []): array {
    $payload = [
      'service_key' => 'test-service-key-123',
      'name' => 'Test Workspace',
      'slug' => 'test-workspace',
      'email' => 'user@example.com',
      'categories' => ['Road Damage'],
      'boundary' => [
        'type' => 'Polygon',
        'coordinates' => [[[6.8, 50.8], [7.0, 50.8], [7.0, 51.0], [6.8, 50.8]]],
      ],
    ] + $extra;
    $request = Request::create('/api/fastmap/create-workspace', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    $response = $this->controller()->createWorkspace($request);
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)];
  }

  /**
   * A complete acceptance as the Nuxt server sends it.
   */
  private function acceptance(array $override = []): array {
    return $override + [
      'terms' => '2026-11-15',
      'dpa' => '2026-11-15',
      'declaration' => '1.0',
      'langcode' => 'de',
    ];
  }

  /**
   * Switch off: the acceptance is ignored and nothing of it is stored.
   *
   * @covers ::createWorkspace
   */
  public function testSwitchOffIgnoresAcceptance(): void {
    foreach ([NULL, FALSE] as $switch) {
      $this->switch = $switch;
      $this->inserted = NULL;
      [$status] = $this->signup(['acceptance' => $this->acceptance(['accepted_at' => 5])]);
      $this->assertSame(202, $status);
      $data = json_decode($this->inserted['workspace_data'], TRUE);
      $this->assertArrayNotHasKey('acceptance', $data);
      $this->assertArrayNotHasKey('lifecycle', $this->lastMail('workspace_verification')[4], 'the old verification texts');

      // And a signup without it is accepted exactly as before.
      $this->inserted = NULL;
      [$status] = $this->signup();
      $this->assertSame(202, $status);
      $this->assertArrayNotHasKey('acceptance', json_decode($this->inserted['workspace_data'], TRUE));
    }
  }

  /**
   * Switch on, no acceptance: 422 acceptance_required, nothing stored.
   *
   * @covers ::createWorkspace
   */
  public function testSwitchOnRequiresAcceptance(): void {
    $this->switch = TRUE;
    [$status, $body] = $this->signup();
    $this->assertSame(422, $status);
    $this->assertSame('acceptance_required', $body['code']);
    $this->assertNull($this->inserted);
  }

  /**
   * Switch on, malformed versions or language: refused the same way.
   *
   * @covers ::createWorkspace
   */
  public function testSwitchOnRefusesInvalidAcceptance(): void {
    $this->switch = TRUE;
    $invalid = [
      'missing dpa' => array_diff_key($this->acceptance(), ['dpa' => TRUE]),
      'empty version' => $this->acceptance(['terms' => '']),
      'too long' => $this->acceptance(['terms' => str_repeat('a', 33)]),
      'space' => $this->acceptance(['dpa' => '1 0']),
      'markup' => $this->acceptance(['declaration' => '<b>1</b>']),
      'trailing newline' => $this->acceptance(['terms' => "1.0\n"]),
      'not a string' => $this->acceptance(['terms' => 1]),
      'no language' => array_diff_key($this->acceptance(), ['langcode' => TRUE]),
      'bad language' => $this->acceptance(['langcode' => 'deutsch!']),
      'not an object' => 'yes',
    ];
    foreach ($invalid as $label => $acceptance) {
      $this->inserted = NULL;
      [$status, $body] = $this->signup(['acceptance' => $acceptance]);
      $this->assertSame(422, $status, $label);
      $this->assertSame('acceptance_required', $body['code'], $label);
      $this->assertNull($this->inserted, $label);
    }
  }

  /**
   * Switch on: the acceptance is stored with the server's request time.
   *
   * @covers ::createWorkspace
   */
  public function testSwitchOnStoresAcceptanceWithRequestTime(): void {
    $this->switch = TRUE;
    [$status] = $this->signup(['acceptance' => $this->acceptance(['accepted_at' => 5, 'uid' => 1, 'langcode' => 'DE-LS'])]);
    $this->assertSame(202, $status);
    $data = json_decode($this->inserted['workspace_data'], TRUE);
    $this->assertSame([
      'terms' => '2026-11-15',
      'dpa' => '2026-11-15',
      'declaration' => '1.0',
      'langcode' => 'de-ls',
      'accepted_at' => self::REQUEST_TIME,
    ], $data['acceptance']);
    $this->assertTrue($this->lastMail('workspace_verification')[4]['lifecycle'], 'the verification mail asks to confirm the address');
  }

  /**
   * Verification hands the stored acceptance to provisioning unchanged.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyCarriesAcceptanceToProvisioning(): void {
    // The welcome mail builds its link with the module's URL helper.
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';
    $this->switch = TRUE;
    $acceptance = $this->acceptance() + ['accepted_at' => self::REQUEST_TIME];
    $this->pendingRow = $this->pendingRow(['acceptance' => $acceptance]);
    $this->provisioning->expects($this->once())->method('provisionWorkspace')
      ->with($this->callback(fn(array $data) => ($data['acceptance'] ?? NULL) === $acceptance))
      ->willReturn([
        'group_id' => 42,
        'slug' => 'test-ws',
        'name' => 'Test Workspace',
        'url' => '/test-ws',
        'categories' => 1,
        'user_id' => 7,
        'lifecycle' => TRUE,
      ]);

    $response = $this->controller()->verifyWorkspace(self::TOKEN);

    $this->assertSame(201, $response->getStatusCode());
    $this->assertTrue($this->lastMail('workspace_welcome')[4]['lifecycle'], 'the welcome mail names the next step');
  }

  /**
   * The last mail sent with a key.
   */
  private function lastMail(string $key): array {
    $mails = array_values(array_filter($this->mails, static fn(array $mail): bool => $mail[1] === $key));
    $this->assertNotEmpty($mails, "A $key mail was sent.");
    return end($mails);
  }

  /**
   * A pending row from before activation is released, never provisioned.
   *
   * Provisioning refuses it before writing anything; the controller frees
   * the row (and with it the map name) and asks for a new signup.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyReleasesPendingRowWithoutAcceptance(): void {
    $this->switch = TRUE;
    $this->pendingRow = $this->pendingRow();
    $this->provisioning->expects($this->once())->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));
    $this->provisioning->expects($this->never())->method('teardownWorkspace');

    $response = $this->controller()->verifyWorkspace(self::TOKEN);

    $this->assertSame(410, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('signup_restart_required', $body['code']);
    $this->assertSame([['id' => 5]], $this->deleted['markaspot_fastmap_pending'] ?? NULL);
    $this->assertArrayNotHasKey('markaspot_fastmap_verified', $this->deleted);
    $this->assertSame([hash('sha256', self::TOKEN)], $this->bindingDeletes);
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * Every later opening of a released link gets the same answer.
   *
   * A mail scanner may open the link first; the person must still see the
   * restart message, not "invalid token".
   *
   * @covers ::verifyWorkspace
   */
  public function testRepeatedVerifyOfReleasedSignupKeepsRestartAnswer(): void {
    $this->switch = TRUE;
    $this->pendingRow = $this->pendingRow();
    $this->provisioning->expects($this->once())->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));

    $first = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame(410, $first->getStatusCode());
    $this->assertFalse($this->pendingRow, 'The row is released.');
    // The marker exists before the row is gone.
    $this->assertSame(['marker', 'row released'], $this->events);
    $marker = $this->keyValue['markaspot_fastmap_signup_restart'][hash('sha256', self::TOKEN)] ?? NULL;
    $this->assertTrue($marker['value'] ?? NULL);
    // Lives until the link would have expired: seven days (cleanup_days),
    // created a minute ago.
    $this->assertSame(7 * 86400 - 60, $marker['ttl']);

    foreach ([2, 3] as $attempt) {
      $again = $this->controller()->verifyWorkspace(self::TOKEN);
      $this->assertSame(410, $again->getStatusCode(), "attempt $attempt");
      $this->assertSame('signup_restart_required', json_decode((string) $again->getContent(), TRUE)['code']);
    }

    // Another token without a marker still gets the plain 404.
    $other = $this->controller()->verifyWorkspace(str_repeat('cd', 32));
    $this->assertSame(404, $other->getStatusCode());
  }

  /**
   * A failed marker write still releases the row and frees the name.
   *
   * @covers ::verifyWorkspace
   */
  public function testReleaseSurvivesFailedMarkerWrite(): void {
    $this->switch = TRUE;
    $this->markerWriteFails = TRUE;
    $this->pendingRow = $this->pendingRow();
    $this->provisioning->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));

    $response = $this->controller()->verifyWorkspace(self::TOKEN);

    $this->assertSame(410, $response->getStatusCode());
    $this->assertSame('signup_restart_required', json_decode((string) $response->getContent(), TRUE)['code']);
    $this->assertFalse($this->pendingRow, 'The row is released although the marker failed.');
    $this->assertSame([hash('sha256', self::TOKEN)], $this->bindingDeletes);
    $this->assertArrayNotHasKey('markaspot_fastmap_signup_restart', $this->keyValue);
  }

  /**
   * The released map name takes a new signup at once.
   *
   * @covers ::createWorkspace
   * @covers ::verifyWorkspace
   */
  public function testSameMapNameIsFreeAfterRelease(): void {
    $this->switch = TRUE;
    $this->pendingRow = $this->pendingRow(['slug' => 'test-workspace']);
    [$status] = $this->signup(['acceptance' => $this->acceptance()]);
    $this->assertSame(409, $status, 'The old pending row reserves the name.');

    $this->provisioning->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));
    $this->assertSame(410, $this->controller()->verifyWorkspace(self::TOKEN)->getStatusCode());

    $this->inserted = NULL;
    [$status] = $this->signup(['acceptance' => $this->acceptance()]);
    $this->assertSame(202, $status);
    $this->assertSame('test-workspace', json_decode($this->inserted['workspace_data'], TRUE)['slug']);
  }

  /**
   * The switch route answers the Nuxt server and nothing else.
   *
   * @covers ::signupConfig
   */
  public function testSignupConfigReportsSwitch(): void {
    $this->flood->expects($this->never())->method('register');
    foreach ([[TRUE, TRUE], [FALSE, FALSE], [NULL, FALSE], ['1', FALSE], [1, FALSE]] as [$stored, $expected]) {
      $this->switch = $stored;
      $request = Request::create('/api/fastmap/signup-config', 'GET', [], [], [], ['HTTP_X_SERVICE_KEY' => 'test-service-key-123']);
      $response = $this->controller()->signupConfig($request);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame(['lifecycle_enabled' => $expected], json_decode((string) $response->getContent(), TRUE));
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
  }

  /**
   * A valid key is answered even when the shared flood window is full.
   *
   * Signups from the Nuxt server fill the same window; the switch read must
   * not fail because of them.
   *
   * @covers ::signupConfig
   */
  public function testSignupConfigIgnoresFloodForValidKey(): void {
    $this->switch = TRUE;
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(FALSE);
    $this->flood->expects($this->never())->method('register');

    $valid = Request::create('/api/fastmap/signup-config', 'GET', [], [], [], ['HTTP_X_SERVICE_KEY' => 'test-service-key-123']);
    $response = $this->controller()->signupConfig($valid);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['lifecycle_enabled' => TRUE], json_decode((string) $response->getContent(), TRUE));

    $wrong = Request::create('/api/fastmap/signup-config', 'GET', [], [], [], ['HTTP_X_SERVICE_KEY' => 'wrong']);
    $this->assertSame(429, $this->controller()->signupConfig($wrong)->getStatusCode());
  }

  /**
   * A wrong or missing key is refused and counts toward the flood window.
   *
   * @covers ::signupConfig
   */
  public function testSignupConfigRefusesWrongKey(): void {
    $this->switch = TRUE;
    $this->flood->expects($this->exactly(3))->method('register');
    $requests = [
      Request::create('/api/fastmap/signup-config', 'GET', [], [], [], ['HTTP_X_SERVICE_KEY' => 'wrong']),
      Request::create('/api/fastmap/signup-config', 'GET'),
      // The key is accepted from the header only.
      Request::create('/api/fastmap/signup-config', 'GET', ['service_key' => 'test-service-key-123']),
    ];
    foreach ($requests as $request) {
      $response = $this->controller()->signupConfig($request);
      $this->assertSame(403, $response->getStatusCode());
      $this->assertStringNotContainsString('lifecycle_enabled', (string) $response->getContent());
    }
  }

  /**
   * Builds a pending row as createWorkspace() stores it.
   */
  private function pendingRow(array $extra = []): array {
    return [
      'id' => 5,
      'token' => self::TOKEN,
      'email' => 'user@example.com',
      'workspace_data' => json_encode($extra + [
        'name' => 'Test Workspace',
        'slug' => 'test-ws',
        'email' => 'user@example.com',
        'categories' => ['en' => ['Road Damage']],
        'language' => 'en',
      ]),
      'created' => self::REQUEST_TIME - 60,
    ];
  }

  /**
   * The release's verification window and the restart path, at their edges.
   *
   * An expired link answers "expired" (410 without a code) and is never
   * released; a link at exactly its lifetime is still valid, so a signup
   * without acceptance is released then. A released signup answers
   * signup_restart_required for the rest of the original link lifetime and
   * "invalid" after it.
   *
   * @covers ::verifyWorkspace
   */
  public function testRestartFollowsTheVerificationWindow(): void {
    $this->switch = TRUE;
    $ttl = 7 * 86400;

    // Older than the lifetime: expired, not released, no marker.
    $this->pendingRow = ['created' => self::REQUEST_TIME - $ttl - 1] + $this->pendingRow();
    $this->provisioning->expects($this->never())->method('provisionWorkspace');
    $expired = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame(410, $expired->getStatusCode());
    $this->assertArrayNotHasKey('code', json_decode((string) $expired->getContent(), TRUE));
    $this->assertArrayNotHasKey(hash('sha256', self::TOKEN), $this->keyValue['markaspot_fastmap_signup_restart'] ?? []);
  }

  /**
   * At exactly the lifetime the link is valid and the signup is released.
   *
   * @covers ::verifyWorkspace
   */
  public function testReleaseAtTheEdgeAndAfterTheLifetime(): void {
    $this->switch = TRUE;
    $ttl = 7 * 86400;
    $created = self::REQUEST_TIME - 3600;
    $this->pendingRow = ['created' => $created] + $this->pendingRow();
    $this->provisioning->expects($this->once())->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));

    // Released an hour after the signup: the marker lives the rest.
    $released = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame('signup_restart_required', json_decode((string) $released->getContent(), TRUE)['code']);
    $this->assertSame($ttl - 3600, $this->keyValue['markaspot_fastmap_signup_restart'][hash('sha256', self::TOKEN)]['ttl']);

    // Within the original lifetime: still the restart answer.
    $this->now = $created + $ttl - 1;
    $late = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame(410, $late->getStatusCode());
    $this->assertSame('signup_restart_required', json_decode((string) $late->getContent(), TRUE)['code']);

    // After it: an invalid link like any other.
    $this->now = $created + $ttl;
    $after = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame(404, $after->getStatusCode());
  }

  /**
   * A link exactly at its lifetime still reaches the restart path.
   *
   * @covers ::verifyWorkspace
   */
  public function testLinkAtExactlyItsLifetimeIsReleased(): void {
    $this->switch = TRUE;
    $this->pendingRow = ['created' => self::REQUEST_TIME - 7 * 86400] + $this->pendingRow();
    $this->provisioning->expects($this->once())->method('provisionWorkspace')
      ->willThrowException(new AcceptanceRequiredException('A valid acceptance is required to create a workspace.'));
    $response = $this->controller()->verifyWorkspace(self::TOKEN);
    $this->assertSame('signup_restart_required', json_decode((string) $response->getContent(), TRUE)['code']);
    // max(1, ...): the marker never gets a zero or negative lifetime.
    $this->assertSame(1, $this->keyValue['markaspot_fastmap_signup_restart'][hash('sha256', self::TOKEN)]['ttl']);
  }

}
