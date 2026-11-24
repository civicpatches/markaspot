<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_fastmap\Commands\WorkspaceCommands;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\OperatorDetails;
use Drupal\markaspot_fastmap\Service\OperatorScrub;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests "Open map" with real storage, routes, hooks and the mail collector.
 *
 * Runs the module's own hooks and services. In a worktree run they come from
 * the worktree only when the test bootstrap redirects the module there;
 * testRunsThisCheckout() fails otherwise, so a green run here is never a run
 * of another checkout's code.
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class WorkspaceOpeningKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'address',
    'color_field',
    'geolocation',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'serialization',
    'basic_auth',
    'jsonapi',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_publisher',
    'markaspot_tenant_admin',
    'markaspot_fastmap',
    'service_request',
  ];

  /**
   * A record of a map that is not open.
   */
  private const NOT_OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":null}';

  /**
   * A person as operator.
   */
  private const PERSON = [
    'country_code' => 'NL',
    'organization' => '',
    'given_name' => 'Jane',
    'family_name' => 'Doe',
    'address_line1' => 'Main street 1',
    'postal_code' => '1011 AB',
    'locality' => 'Amsterdam',
  ];

  /**
   * Workspaces by key.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  private array $workspaces = [];

  /**
   * Categories by workspace key.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $categories = [];

  /**
   * Accounts by label.
   *
   * @var array<string, \Drupal\user\Entity\User>
   */
  private array $accounts = [];

  /**
   * Log records of the markaspot_fastmap channel, as "level: message".
   *
   * @var string[]
   */
  private array $log = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Exposes the response's cacheability max-age as a header.
    $container->setParameter('http.response.debug_cacheability_headers', TRUE);
    $container->getDefinition('flood')->setClass(DatabaseBackend::class)
      ->setArguments([new Reference('database'), new Reference('request_stack'), new Reference('datetime.time')]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('markaspot_operating_mode', 'saas');

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_fastmap', ['markaspot_ai_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'jsonapi']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->container->get('config.storage')->write('markaspot_fastmap.settings', [
      'tier_limits' => ['free' => ['limit' => 50, 'period' => 'published']],
      'support_email' => 'support@example.org',
      'workspace_base_url' => 'https://maps.example.org',
    ]);
    $this->container->get('config.storage')->write('markaspot_nuxt.settings', [
      'zoom_initial' => 13,
    ]);

    $collector = new class($this->log) implements LoggerInterface {

      use RfcLoggerTrait;

      public function __construct(private array &$records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? '') === 'markaspot_fastmap') {
          $names = [RfcLogLevel::ERROR => 'error', RfcLogLevel::WARNING => 'warning', RfcLogLevel::NOTICE => 'notice'];
          $this->records[] = ($names[$level] ?? (string) $level) . ': ' . strtr((string) $message, array_map('strval', array_filter($context, 'is_scalar')));
        }
      }

    };
    $this->container->get('logger.factory')->addLogger($collector);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])
      ->grantPermission('access content')
      ->grantPermission('bypass mas validation')
      ->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $roleId) {
      Role::load($roleId)
        ->grantPermission('access content')
        ->grantPermission('create service_request content')
        ->save();
    }

    $review = json_encode(['features' => ['reviewBeforePublish' => TRUE]]);
    $this->addWorkspace('mine', [WorkspaceLifecycle::FIELD => self::NOT_OPEN, 'field_nuxt_config' => $review], self::PERSON);
    $this->addWorkspace('incomplete', [WorkspaceLifecycle::FIELD => self::NOT_OPEN], ['postal_code' => ''] + self::PERSON);
    $this->addWorkspace('other', [WorkspaceLifecycle::FIELD => self::NOT_OPEN], ['organization' => 'Other Org'] + self::PERSON);
    $this->addWorkspace('legacy', [], ['organization' => 'Legacy Org'] + self::PERSON);

    $this->accounts['owner'] = $this->member(['mine', 'incomplete'], 'owner', 'jur-tenant_admin', ['tenant_admin']);
    $this->accounts['helper'] = $this->member(['mine'], 'helper', 'jur-member', ['tenant_admin']);
    $this->accounts['foreign admin'] = $this->member(['other'], 'foreign', 'jur-tenant_admin', ['tenant_admin']);
    $this->accounts['platform admin'] = User::create([
      'name' => 'staff',
      'mail' => 'staff@example.test',
      'status' => 1,
      'roles' => ['administrator'],
    ]);
    $this->accounts['platform admin']->save();

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The module code under test is this checkout's.
   */
  public function testRunsThisCheckout(): void {
    $path = $this->container->get('extension.list.module')->getPath('markaspot_fastmap');
    $this->assertSame(realpath(dirname(__DIR__, 3)), realpath(DRUPAL_ROOT . '/' . $path), 'The enabled module is this checkout.');
    $this->assertTrue($this->container->has('markaspot_fastmap.workspace_opening'));
    $this->assertTrue($this->container->get('module_handler')->hasImplementations('group_presave', 'markaspot_fastmap'));
  }

  /**
   * Opening as tenant admin: record, revision, summary, mail, open intake.
   */
  public function testOpenAsTenantAdmin(): void {
    $owner = $this->accounts['owner'];
    $before = $this->workspace('mine');
    $revisionBefore = (int) $before->getRevisionId();

    // Before opening, an anonymous report is refused.
    $this->assertSame(403, $this->postReport('mine')->getStatusCode());
    // The details are a draft: nothing of the operator is public yet.
    $settings = $this->publicSettings('mine');
    $this->assertFalse($settings['body']['jurisdiction']['open']);
    $this->assertArrayNotHasKey('operator', $settings['body']);
    $this->assertStringNotContainsString('Main street', (string) json_encode($settings['body']));
    $this->assertStringNotContainsString('mine@example.org', (string) json_encode($settings['body']));

    $response = $this->open('mine', $owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('opened', $body['status']);
    $this->assertTrue($body['lifecycle']['open']);
    $this->assertSame([], $body['lifecycle']['operator_missing']);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame($now, $body['lifecycle']['opened_at']);
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

    $group = $this->workspace('mine');
    $record = WorkspaceLifecycle::parse($group->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertSame([
      'opened_at' => $now,
      'uid' => (int) $owner->id(),
      'revision_id' => $revisionBefore,
      'operator' => [
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'address_line1' => 'Main street 1',
        'postal_code' => '1011 AB',
        'locality' => 'Amsterdam',
        'country_code' => 'NL',
        'email' => 'mine@example.org',
      ],
    ], $record['opening']);
    $this->assertSame(json_decode(self::NOT_OPEN, TRUE)['acceptance'], $record['acceptance']);
    $this->assertGreaterThan($revisionBefore, (int) $group->getRevisionId(), 'A new revision.');
    $this->assertSame(WorkspaceOpening::REVISION_LOG, $group->getRevisionLogMessage());
    $this->assertSame((int) $owner->id(), (int) $group->getRevisionUserId());
    $this->assertSame('public', $group->hasField('field_visibility') ? ($group->get('field_visibility')->value ?? 'public') : 'public', 'Visibility untouched.');

    // One mail, to the support address, with the operator and the account.
    $mails = $this->openingMails();
    $this->assertCount(1, $mails);
    $mail = $mails[0];
    $this->assertSame('support@example.org', $mail['to']);
    $this->assertSame('Map opened: Map mine (mine)', $mail['subject']);
    $body = is_array($mail['body']) ? implode("\n", array_map('strval', $mail['body'])) : (string) $mail['body'];
    $lines = [
      'Map: Map mine',
      'Link: https://maps.example.org/mine',
      'Name: Jane Doe',
      'Address: Main street 1, 1011 AB Amsterdam, NL',
      'Contact e-mail: mine@example.org',
      'Opened by (account e-mail): owner@example.test',
      'UTC',
    ];
    foreach ($lines as $line) {
      $this->assertStringContainsString($line, $body);
    }

    // The intake is open: an anonymous report is accepted and held.
    $response = $this->postReport('mine');
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $nid = json_decode((string) $response->getContent(), TRUE)['data']['attributes']['drupal_internal__nid'];
    $this->assertFalse($this->reloadNode((int) $nid)->isPublished(), 'Held by review before publish.');

    // Public settings: open, operator named by the address, never the map.
    $settings = $this->publicSettings('mine');
    $this->assertTrue($settings['body']['jurisdiction']['open']);
    $this->assertSame('Jane Doe', $settings['body']['operator']['name']);
    $this->assertNotSame('Map mine', $settings['body']['operator']['name'] ?? NULL);

    // Second call: the existing opening, no second mail, no new revision.
    $revision = (int) $group->getRevisionId();
    $response = $this->open('mine', $owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('already_open', json_decode((string) $response->getContent(), TRUE)['status']);
    $this->assertSame($now, json_decode((string) $response->getContent(), TRUE)['lifecycle']['opened_at']);
    $this->assertCount(1, $this->openingMails());
    $this->assertSame($revision, (int) $this->workspace('mine')->getRevisionId());
  }

  /**
   * Refusals: unconfirmed, wrong account, no record.
   */
  public function testOpenRefusals(): void {
    $owner = $this->accounts['owner'];
    $cases = [
      'no confirmation' => ['mine', $owner, [], 422, 'confirmation_required'],
      'confirmation not TRUE' => ['mine', $owner, ['confirmed' => 'yes'], 422, 'confirmation_required'],
      'member without the admin role' => ['mine', $this->accounts['helper'], ['confirmed' => TRUE], 403, NULL],
      'tenant admin of another workspace' => [
        'mine', $this->accounts['foreign admin'], ['confirmed' => TRUE], 403, NULL,
      ],
      'anonymous' => ['mine', NULL, ['confirmed' => TRUE], 403, NULL],
      'workspace without a record' => [
        'legacy', $this->accounts['platform admin'], ['confirmed' => TRUE], 404, 'not_applicable',
      ],
    ];
    foreach ($cases as $label => [$key, $account, $payload, $status, $code]) {
      $response = $this->open($key, $account, $payload);
      $this->assertSame($status, $response->getStatusCode(), "$label: " . $response->getContent());
      if ($code !== NULL) {
        $this->assertSame($code, json_decode((string) $response->getContent(), TRUE)['code'], $label);
      }
      $this->assertFalse($this->gate()->openState($this->workspace($key)) === TRUE, "$label: not open");
    }
    $this->assertSame([], $this->openingMails(), 'No mail.');

    // A platform administrator may open any map with a record.
    $response = $this->open('other', $this->accounts['platform admin'], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $record = WorkspaceLifecycle::parse($this->workspace('other')->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertSame((int) $this->accounts['platform admin']->id(), $record['opening']['uid']);
    $this->assertSame('Other Org', $record['opening']['operator']['organization']);
  }

  /**
   * Only a valid, bound owner session opens the workspace automatically.
   */
  public function testSignupClaimOpensExactlyOnce(): void {
    $owner = $this->accounts['owner'];
    $this->markPending('mine', $owner);
    $this->markPending('incomplete', $owner);
    $opening = $this->container->get('markaspot_fastmap.workspace_opening');
    $opening->openAfterLogin((int) $this->workspaces['mine']->id(), $this->accounts['helper']);
    $opening->openAfterLogin((int) $this->workspaces['mine']->id(), new AnonymousUserSession());
    $this->assertSame(403, $this->postReport('mine')->getStatusCode());
    $this->assertSame([], $this->openingMails());

    $token = str_repeat('a', 64);
    $binding = str_repeat('b', 64);
    $this->container->get('keyvalue.expirable')->get('markaspot_fastmap_login_tokens')->setWithExpire($token, [
      'uid' => (int) $owner->id(),
      'slug' => 'mine',
      'binding' => hash('sha256', $binding),
    ], 300);
    $session = new Session(new MockArraySessionStorage());
    $this->container->set('session', $session);
    $controller = FastMapWorkspaceController::create($this->container);
    $request = Request::create('/api/fastmap/claim-login-token', 'POST', [], [], [], [
      'HTTP_X_FASTMAP_SIGNUP_BINDING' => str_repeat('c', 64),
    ], json_encode(['token' => $token]));

    $this->assertSame(401, $controller->claimLoginToken($request)->getStatusCode());
    $this->assertFalse($this->gate()->openState($this->workspace('mine')));
    $this->assertSame([], $this->openingMails());

    $request->headers->set('X-Fastmap-Signup-Binding', $binding);
    $this->assertSame(200, $controller->claimLoginToken($request)->getStatusCode());
    $this->assertSame($owner->id(), $session->get('uid'));
    $this->assertTrue($this->gate()->openState($this->workspace('mine')));
    $this->assertFalse($this->gate()->openState($this->workspace('incomplete')));
    $this->assertSame(401, $controller->claimLoginToken($request)->getStatusCode());
    $opening->openAfterLogin((int) $this->workspaces['mine']->id(), $owner);
    $this->assertCount(1, $this->openingMails());

    $this->assertSame('reset', $opening->resetOperator($this->workspace('incomplete'), TRUE)['status']);
    $opening->openAfterLogin((int) $this->workspaces['incomplete']->id(), $owner);
    $this->assertFalse($this->gate()->openState($this->workspace('incomplete')));
    $this->assertCount(1, $this->openingMails());
  }

  /**
   * The automatic limit applies across workspaces and allows a manual open.
   */
  public function testAutomaticOpeningLimitIsPlatformWide(): void {
    $owner = $this->accounts['owner'];
    $other = $this->accounts['foreign admin'];
    $this->markPending('mine', $owner);
    $this->markPending('other', $other);
    $this->config('markaspot_fastmap.settings')->set('auto_open_daily_limit', 1)->save();
    $opening = $this->container->get('markaspot_fastmap.workspace_opening');
    $opening->openAfterLogin((int) $this->workspaces['mine']->id(), $owner);
    $opening->openAfterLogin((int) $this->workspaces['other']->id(), $other);

    $this->assertTrue($this->gate()->openState($this->workspace('mine')));
    $this->assertFalse($this->gate()->openState($this->workspace('other')));
    $this->assertSame(403, $this->postReport('other')->getStatusCode());
    $this->assertArrayNotHasKey('auto_open_pending', WorkspaceLifecycle::parse($this->workspace('other')->get(WorkspaceLifecycle::FIELD)->value));
    $this->container->get('flood')->clear('markaspot_fastmap.auto_open', 'platform');
    $opening->openAfterLogin((int) $this->workspaces['other']->id(), $other);
    $this->assertFalse($this->gate()->openState($this->workspace('other')), 'The quota decision survives later logins.');
    $this->assertCount(1, $this->openingMails());
    $notices = $this->openingMails(WorkspaceOpening::LIMIT_MAIL_KEY);
    $this->assertCount(1, $notices);
    $this->assertSame('support@example.org', $notices[0]['to']);
    $this->assertStringContainsString('https://maps.example.org/other', $notices[0]['body']);
    $this->assertStringContainsString('manually before the public', $notices[0]['body']);
    $this->assertSame(200, $this->open('other', $other, ['confirmed' => TRUE])->getStatusCode());
    $this->assertCount(2, $this->openingMails());
  }

  /**
   * The route takes the CSRF header token on a cookie session.
   */
  public function testCsrfOnCookieSession(): void {
    $this->setCurrentUser($this->accounts['owner']);
    $request = Request::create('/api/fastmap/workspace/' . $this->workspaces['mine']->id() . '/open', 'POST');
    $name = $this->container->get('session_configuration')->getOptions($request)['name'];
    $request->cookies->set($name, 'kernel-session');
    try {
      $this->match($request);
      $this->fail('No CSRF token was accepted.');
    }
    catch (AccessDeniedHttpException $e) {
      $this->assertSame('X-CSRF-Token request header is missing', $e->getMessage());
    }
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $this->assertSame('markaspot_fastmap.workspace_open', $this->match($request));

    $route = $this->container->get('router.route_provider')->getRouteByName('markaspot_fastmap.workspace_open');
    $this->assertSame(['cookie'], $route->getOption('_auth'));
    $this->assertSame('\Drupal\markaspot_nuxt\Controller\TenantSettingsController::accessCheck', $route->getRequirement('_custom_access'));
  }

  /**
   * After opening, a settings save keeps the opening and the operator.
   */
  public function testSettingsSaveAfterOpening(): void {
    $owner = $this->accounts['owner'];
    // A stale copy of the workspace from before the opening.
    $stale = $this->workspace('mine');
    $this->assertSame(200, $this->open('mine', $owner, ['confirmed' => TRUE])->getStatusCode());
    $opened = $this->workspace('mine')->get(WorkspaceLifecycle::FIELD)->value;

    // A PATCH of other fields keeps the opening.
    $response = $this->patchGeneral('mine', $owner, ['field_platform_name' => 'Trees of Amsterdam']);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($data['lifecycle']['open']);
    $this->assertSame('Jane', $data['field_jurisdiction_address']['given_name']);
    $this->assertSame('Doe', $data['field_jurisdiction_address']['family_name']);
    $this->assertSame($opened, $this->workspace('mine')->get(WorkspaceLifecycle::FIELD)->value);

    // Changing the operator to a complete organisation is fine.
    $response = $this->patchGeneral('mine', $owner, ['field_jurisdiction_address' => ['organization' => 'Tree Friends'] + self::PERSON]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('Tree Friends', $this->publicSettings('mine')['body']['operator']['name']);

    // Removing an operator part makes the provider notice public again.
    $response = $this->patchGeneral('mine', $owner, ['field_jurisdiction_address' => ['address_line1' => ''] + self::PERSON]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(200, $this->patchGeneral('mine', $owner, ['field_jurisdiction_e_mail' => ''])->getStatusCode());
    $this->assertSame('platform', $this->publicSettings('mine')['body']['legalNotice']['source']);
    $this->assertCount(1, $this->openingMails());
    // Name halves are validated like the other address parts.
    $this->assertSame(422, $this->patchGeneral('mine', $owner, ['field_jurisdiction_address' => ['given_name' => '<b>Jane</b>'] + self::PERSON])->getStatusCode());

    // Any other writer with a stale entity cannot take the opening back.
    $stale->set('field_platform_name', 'Stale save');
    $stale->save();
    $this->assertSame($opened, $this->workspace('mine')->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertTrue($this->gate()->openState($this->workspace('mine')));

    // Before opening, emptying the street is allowed (the map is not open).
    $response = $this->patchGeneral('incomplete', $owner, ['field_jurisdiction_address' => ['address_line1' => ''] + self::PERSON]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * The provider notice is served until an open map has an operator.
   */
  public function testProviderNoticeOnOpenMapWithoutOperator(): void {
    $owner = $this->accounts['owner'];
    $response = $this->open('incomplete', $owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $public = $this->publicSettings('incomplete')['body'];
    $this->assertTrue($public['jurisdiction']['open']);
    $this->assertFalse($public['hasOperator']);
    $this->assertArrayNotHasKey('operator', $public);
    $this->assertSame('platform', $public['legalNotice']['source']);
    $this->assertSame('https://www.civicpatches.de/impressum', $public['legalNotice']['url']);
    $generator = $this->container->get('markaspot_fastmap.legal_notice_generator');
    $this->assertSame('', $generator->generateForGroup($this->workspace('incomplete'), 'en'));
    $this->assertTrue($this->workspace('incomplete')->get('field_legal_notice')->isEmpty());

    $response = $this->patchGeneral('incomplete', $owner, [
      'field_jurisdiction_address' => self::PERSON,
      'field_jurisdiction_e_mail' => 'operator@example.test',
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $dashboard = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($dashboard['hasOperator']);
    $this->assertTrue($dashboard['lifecycle']['open']);
    $public = $this->publicSettings('incomplete')['body'];
    $this->assertTrue($public['hasOperator']);
    $this->assertSame('operator', $public['legalNotice']['source']);
    $this->assertStringContainsString('Jane Doe', $public['legalNotice']['html']);
    $this->assertCount(1, $this->openingMails());
    $mails = $this->openingMails(WorkspaceOpening::OPERATOR_MAIL_KEY);
    $this->assertCount(1, $mails);
    $this->assertSame('support@example.org', $mails[0]['to']);
    $text = $mails[0]['body'];
    foreach ([
      'Map incomplete',
      'https://maps.example.org/incomplete',
      'Jane Doe',
      'Main street 1, 1011 AB Amsterdam, NL',
      'operator@example.test',
    ] as $value) {
      $this->assertStringContainsString($value, $text);
    }

    $this->assertSame(200, $this->patchGeneral('incomplete', $owner, [
      'field_jurisdiction_address' => ['address_line2' => 'Second floor'] + self::PERSON,
    ])->getStatusCode());
    $this->assertCount(1, $this->openingMails(WorkspaceOpening::OPERATOR_MAIL_KEY));
    $this->container->get('database')->update('flood')
      ->fields(['timestamp' => 1, 'expiration' => 1])
      ->condition('event', 'markaspot_fastmap.operator_changed_support')
      ->execute();
    $this->assertSame(200, $this->patchGeneral('incomplete', $owner, [
      'field_jurisdiction_address' => ['address_line2' => 'Third floor'] + self::PERSON,
    ])->getStatusCode());
    $mails = $this->openingMails(WorkspaceOpening::OPERATOR_MAIL_KEY);
    $this->assertCount(2, $mails);
    $this->assertStringContainsString('Third floor', $mails[1]['body']);

    $this->assertSame(200, $this->patchGeneral('incomplete', $owner, ['field_jurisdiction_e_mail' => ''])->getStatusCode());
    $this->assertTrue($this->workspace('incomplete')->get('field_legal_notice')->isEmpty());
    $this->assertSame('platform', $this->publicSettings('incomplete')['body']['legalNotice']['source']);
    $this->assertCount(2, $this->openingMails(WorkspaceOpening::OPERATOR_MAIL_KEY));
  }

  /**
   * Public settings: a short max-age for lifecycle workspaces.
   */
  public function testPublicSettings(): void {
    $this->config('system.performance')->set('cache.page.max_age', 3600)->save();
    $notOpen = $this->publicSettings('mine');
    $this->assertSame((string) WorkspaceLifecycleGate::LIFECYCLE_SETTINGS_MAX_AGE, $notOpen['max_age']);
    $this->assertFalse($notOpen['body']['jurisdiction']['open']);
    $this->assertArrayNotHasKey('operator', $notOpen['body'], 'Complete details, not open: no operator block.');
    // The opening's group save invalidates the response through this tag.
    $this->assertContains('group:' . $this->workspaces['mine']->id(), $notOpen['tags']);

    $legacy = $this->publicSettings('legacy');
    $this->assertSame('3600', $legacy['max_age']);
    $this->assertArrayNotHasKey('open', $legacy['body']['jurisdiction']);
    $this->assertSame('Map legacy', $legacy['body']['operator']['name'], 'Without a record the map name stays, as today.');

    $this->assertSame('legacy@example.org', $legacy['body']['operator']['email']);

    // The effective approval flag the citizen confirmation reads: top-level
    // features.reviewBeforePublish, resolved from the workspace root, also
    // for a child jurisdiction.
    $this->assertTrue($notOpen['body']['features']['reviewBeforePublish']);
    $this->assertFalse($legacy['body']['features']['reviewBeforePublish']);
    $child = Group::create([
      'type' => 'jur',
      'label' => 'District',
      'field_parent_jurisdiction' => $this->workspaces['mine']->id(),
    ]);
    $child->save();
    $this->workspaces['child'] = $child;
    $this->assertTrue($this->publicSettings('child')['body']['features']['reviewBeforePublish'], 'Child: the root flag.');
    unset($this->workspaces['child']);

    $this->open('mine', $this->accounts['owner'], ['confirmed' => TRUE]);
    $open = $this->publicSettings('mine');
    $this->assertSame('60', $open['max_age']);
    // Core overrides HTTP caching under CLI. Check the response before the
    // request policy is applied.
    $request = Request::create('/api/mark-a-spot-settings', 'GET', ['jurisdiction' => $this->workspaces['mine']->id()]);
    $response = MarkASpotSettingsController::create($this->container)->getMarkASpotSettings($request);
    $this->assertSame(60, $response->getMaxAge());
    $this->assertTrue($open['body']['jurisdiction']['open']);
    $this->assertSame('Jane Doe', $open['body']['operator']['name']);
    $this->assertSame('mine@example.org', $open['body']['operator']['email']);
    $this->assertSame('Main street 1', $open['body']['operator']['address']['address_line1']);
  }

  /**
   * Without a valid support address nothing is sent and an error is logged.
   */
  public function testNoSupportAddress(): void {
    foreach (['', 'not-an-address'] as $value) {
      $this->config('markaspot_fastmap.settings')->set('support_email', $value)->save();
      $key = $value === '' ? 'mine' : 'other';
      $account = $value === '' ? $this->accounts['owner'] : $this->accounts['foreign admin'];
      $this->log = [];
      $response = $this->open($key, $account, ['confirmed' => TRUE]);
      $this->assertSame(200, $response->getStatusCode(), 'The opening does not depend on the mail.');
      $this->assertTrue($this->gate()->openState($this->workspace($key)));
      $this->assertSame([], $this->allMails(), "No mail at all for '$value'.");
      $errors = array_filter($this->log, static fn(string $line): bool => str_starts_with($line, 'error: No support mail'));
      $this->assertCount(1, $errors, implode("\n", $this->log));
    }
  }

  /**
   * A blocked or unpublished map is not opened, not even by support.
   */
  public function testBlockedMapIsNotOpened(): void {
    $group = $this->workspace('other');
    $group->set('field_visibility', 'blocked')->save();
    $response = $this->open('other', $this->accounts['platform admin'], ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('workspace_blocked', json_decode((string) $response->getContent(), TRUE)['code']);

    // An unpublished workspace is not found by the route's access check
    // already (403); the service's own 409 for it is a unit test.
    $group = $this->workspace('mine');
    $group->setUnpublished()->save();
    $response = $this->open('mine', $this->accounts['owner'], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(WorkspaceOpening::BLOCKED, $this->container->get('markaspot_fastmap.workspace_opening')->open($group, $this->accounts['owner'], TRUE)['status']);
    foreach (['other', 'mine'] as $key) {
      $this->assertFalse($this->gate()->openState($this->workspace($key)), $key);
    }
    $this->assertSame([], $this->openingMails());
  }

  /**
   * Support removes false operator data; the map closes and can reopen.
   */
  public function testResetOperator(): void {
    $owner = $this->accounts['owner'];
    $this->assertSame(200, $this->open('mine', $owner, ['confirmed' => TRUE])->getStatusCode());
    $this->assertSame(201, $this->postReport('mine')->getStatusCode());
    /** @var \Drupal\markaspot_fastmap\Service\WorkspaceOpening $opening */
    $opening = $this->container->get('markaspot_fastmap.workspace_opening');
    $revisionBefore = (int) $this->workspace('mine')->getRevisionId();

    // The drush command: a dry run by default, names parts, no values.
    $commands = new WorkspaceCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('markaspot_fastmap.workspace_deletion'),
      $opening,
    );
    $output = new BufferedOutput();
    $commands->setOutput($output);
    $commands->resetOperator('mine', ['apply' => FALSE]);
    $dryRun = $output->fetch();
    $this->assertStringContainsString('Dry run.', $dryRun);
    $this->assertStringContainsString('would clear given_name, family_name, address_line1, postal_code, locality, email; the map is open and will be closed', $dryRun);
    $this->assertStringContainsString('Older revisions keep their values', $dryRun);
    foreach (['Jane', 'Doe', 'Main street', 'mine@example.org'] as $needle) {
      $this->assertStringNotContainsString($needle, $dryRun, 'No personal data in the output.');
    }
    $this->assertTrue($this->gate()->openState($this->workspace('mine')), 'The dry run wrote nothing.');
    $this->assertSame($revisionBefore, (int) $this->workspace('mine')->getRevisionId());

    $commands->resetOperator('mine', ['apply' => TRUE]);
    $this->assertStringContainsString('the map is closed and opening is suspended', $output->fetch());

    $group = $this->workspace('mine');
    $this->assertFalse($this->gate()->openState($group), 'Not open any more.');
    $record = WorkspaceLifecycle::parse($group->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertNull($record['opening']);
    $this->assertTrue($record['closed_by_support']);
    $this->assertSame(json_decode(self::NOT_OPEN, TRUE)['acceptance'], $record['acceptance']);
    $address = $group->get('field_jurisdiction_address')->first()?->toArray() ?? [];
    $this->assertSame('NL', $address['country_code']);
    foreach (['organization', 'given_name', 'family_name', 'address_line1', 'postal_code', 'locality'] as $property) {
      $this->assertEmpty($address[$property] ?? NULL, $property);
    }
    $this->assertTrue($group->get('field_jurisdiction_e_mail')->isEmpty());
    $this->assertGreaterThan($revisionBefore, (int) $group->getRevisionId());
    $this->assertNull(OperatorDetails::name($group));
    $this->assertArrayNotHasKey('operator', $this->publicSettings('mine')['body']);
    // An older revision keeps the values, as the command says.
    $old = $this->container->get('entity_type.manager')->getStorage('group')->loadRevision($revisionBefore);
    $this->assertSame('Jane', $old->get('field_jurisdiction_address')->first()->get('given_name')->getValue());

    // Intake closed again.
    $this->assertSame(403, $this->postReport('mine')->getStatusCode());
    // A second run finds nothing to do.
    $this->assertSame('nothing_to_reset', $opening->resetOperator($group, TRUE)['status']);

    // Correct data alone cannot lift the support hold.
    $response = $this->patchGeneral('mine', $owner, [
      'field_jurisdiction_address' => ['organization' => 'Tree Friends'] + self::PERSON,
      'field_jurisdiction_e_mail' => 'friends@example.org',
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $denied = $this->open('mine', $owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $denied->getStatusCode());
    $this->assertSame('workspace_blocked', json_decode((string) $denied->getContent(), TRUE)['code']);
    $this->assertCount(1, $this->openingMails());
    $commands->clearOpeningHold('mine');
    $this->assertStringContainsString('support hold cleared', $output->fetch());
    $this->assertFalse($this->gate()->openState($this->workspace('mine')));
    $this->assertSame(200, $this->open('mine', $owner, ['confirmed' => TRUE])->getStatusCode());
    $this->assertTrue($this->gate()->openState($this->workspace('mine')));
    $mails = $this->openingMails();
    $this->assertCount(2, $mails);
    $this->assertStringContainsString('Name: Tree Friends', implode("\n", array_map('strval', (array) $mails[1]['body'])));

    // A workspace without a record is not touched.
    $this->assertSame('not_applicable', $opening->resetOperator($this->workspace('legacy'), TRUE)['status']);
    $this->assertSame('Legacy Org', OperatorDetails::name($this->workspace('legacy')));
  }

  /**
   * The reset takes the legal notice off, hand-edited ones in every language.
   */
  public function testResetClearsLegalNotice(): void {
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    /** @var \Drupal\markaspot_fastmap\Service\WorkspaceOpening $opening */
    $opening = $this->container->get('markaspot_fastmap.workspace_opening');

    // A generated notice (English only).
    $group = $this->workspace('mine');
    $generated = $this->container->get('markaspot_fastmap.legal_notice_generator')->generateForGroup($group, 'en');
    $this->assertStringContainsString('Jane Doe', $generated);
    $group->set('field_legal_notice', ['value' => $generated, 'format' => 'plain_text'])->save();
    $this->assertSame(200, $this->open('mine', $this->accounts['owner'], ['confirmed' => TRUE])->getStatusCode());
    $preview = $opening->resetOperator($this->workspace('mine'), FALSE);
    $this->assertContains('legal_notice', $preview['parts']);
    $this->assertSame('reset', $opening->resetOperator($this->workspace('mine'), TRUE)['status']);
    $this->assertTrue($this->workspace('mine')->get('field_legal_notice')->isEmpty(), 'Not regenerated in the reset.');
    $this->assertSame('nothing_to_reset', $opening->resetOperator($this->workspace('mine'), FALSE)['status']);

    // A hand-edited notice in two languages, which regeneration would skip.
    $manual = '<!-- manual-override --><p>Jane Doe, Home street 1</p>';
    $group = $this->workspace('other');
    $group->set('field_legal_notice', ['value' => $manual, 'format' => 'plain_text']);
    $group->addTranslation('de', [
      'label' => 'Karte',
      'field_legal_notice' => [
        'value' => '<!-- manual-override --><p>Jane Doe, Heimweg 1</p>',
        'format' => 'plain_text',
      ],
    ]);
    $group->save();
    $this->assertSame(200, $this->open('other', $this->accounts['foreign admin'], ['confirmed' => TRUE])->getStatusCode());
    $this->assertSame(
      ['organization', 'given_name', 'family_name', 'address_line1', 'postal_code', 'locality', 'email', 'legal_notice'],
      $opening->resetOperator($this->workspace('other'), FALSE)['parts'],
    );
    $this->assertSame('reset', $opening->resetOperator($this->workspace('other'), TRUE)['status']);
    $group = $this->workspace('other');
    foreach (['en', 'de'] as $langcode) {
      $this->assertTrue($group->getTranslation($langcode)->get('field_legal_notice')->isEmpty(), $langcode);
    }
    $this->assertStringNotContainsString('Jane', (string) json_encode($this->publicSettings('other')['body']));

    // New details: the update hook generates the notice again, as before.
    $response = $this->patchGeneral('other', $this->accounts['foreign admin'], [
      'field_jurisdiction_address' => ['organization' => 'Real Org'] + self::PERSON,
      'field_jurisdiction_e_mail' => 'operator@example.test',
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringContainsString('Real Org', (string) $this->workspace('other')->get('field_legal_notice')->value);
  }

  /**
   * A save built before a support reset cannot reopen the map.
   */
  public function testStaleSaveCannotUndoReset(): void {
    $owner = $this->accounts['owner'];
    $this->assertSame(200, $this->open('mine', $owner, ['confirmed' => TRUE])->getStatusCode());
    // Loaded while open, saved after the reset (a concurrent settings save).
    $stale = $this->workspace('mine');
    $this->assertTrue($this->gate()->openState($stale));
    $this->assertSame('reset', $this->container->get('markaspot_fastmap.workspace_opening')->resetOperator($this->workspace('mine'), TRUE)['status']);

    $stale->set('field_platform_name', 'Stale save');
    $stale->save();
    $group = $this->workspace('mine');
    $this->assertFalse($this->gate()->openState($group), 'The map stays closed.');
    $this->assertNull(WorkspaceLifecycle::parse($group->get(WorkspaceLifecycle::FIELD)->value)['opening']);
    $this->assertSame('Stale save', $group->get('field_platform_name')->value, 'The rest of the save went through.');
    $this->assertSame(403, $this->postReport('mine')->getStatusCode());

    $this->assertSame(409, $this->open('mine', $owner, ['confirmed' => TRUE])->getStatusCode());
  }

  /**
   * The scrub takes the operator data before an undecodable record.
   *
   * In one apply run, a workspace whose record does not decode loses its
   * operator data before the record sweep removes that record.
   */
  public function testScrubApplyOrderForUndecodableRecord(): void {
    $group = $this->workspace('other');
    $group->set('field_legal_notice', ['value' => '<p>Other Org, Main street 1</p>', 'format' => 'plain_text']);
    // A record that does not decode, written as a fixture (syncing).
    $group->setSyncing(TRUE);
    $group->set(WorkspaceLifecycle::FIELD, 'uid 5, not json')->save();
    $group->setSyncing(FALSE);

    putenv('IS_DDEV_PROJECT=true');
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    $extra = ['--apply'];
    ob_start();
    include dirname(__DIR__, 5) . '/scripts/scrub-pii.php';
    $output = (string) ob_get_clean();

    $this->assertStringContainsString('Operator group__field_jurisdiction_address:', $output);
    $operatorAt = strpos($output, 'Operator group__field_jurisdiction_address');
    $recordAt = strpos($output, 'Lifecycle group__field_lifecycle_record');
    $this->assertNotFalse($recordAt, $output);
    $this->assertLessThan($recordAt, $operatorAt, 'Operator data first, then the records.');

    $this->container->get('cache.entity')->deleteAll();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $group = $this->workspace('other');
    $this->assertTrue($group->get(WorkspaceLifecycle::FIELD)->isEmpty(), 'The undecodable record is removed.');
    $this->assertSame('Scrubbed operator', OperatorDetails::name($group));
    $this->assertSame(WorkspaceLifecycle::SCRUBBED_OPERATOR['email'], $group->get('field_jurisdiction_e_mail')->value);
    $this->assertSame(OperatorScrub::LEGAL_NOTICE, $group->get('field_legal_notice')->value);
    // A workspace without a record keeps its operator.
    $this->assertSame('Legacy Org', OperatorDetails::name($this->workspace('legacy')));
  }

  /**
   * The dump scrub replaces the operator data of record workspaces only.
   */
  public function testScrubOperatorData(): void {
    $this->assertSame(200, $this->open('mine', $this->accounts['owner'], ['confirmed' => TRUE])->getStatusCode());
    // A second revision with the person's data, the legal notice included.
    $group = $this->workspace('mine');
    $group->setNewRevision(TRUE);
    $group->set('field_platform_name', 'Renamed');
    $group->set('field_legal_notice', ['value' => '<p>Jane Doe, Main street 1</p>', 'format' => 'plain_text'])->save();
    $legacy = $this->workspace('legacy');
    $legacy->set('field_legal_notice', ['value' => '<p>Legacy Org, Main street 1</p>', 'format' => 'plain_text'])->save();

    putenv('IS_DDEV_PROJECT=true');
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    $extra = [];
    ob_start();
    include dirname(__DIR__, 5) . '/scripts/scrub-pii.php';
    $output = (string) ob_get_clean();

    $tables = scrub_operator_tables();
    $this->assertSame([
      'group__field_jurisdiction_address',
      'group_revision__field_jurisdiction_address',
      'group__field_jurisdiction_e_mail',
      'group_revision__field_jurisdiction_e_mail',
      'group__field_legal_notice',
      'group_revision__field_legal_notice',
    ], array_keys($tables));
    $this->assertStringContainsString('Operator group__field_legal_notice: 1 rows would be rewritten', $output);
    // Three record workspaces hold data; the dry run wrote nothing.
    $this->assertStringContainsString('Operator group__field_jurisdiction_address: 3 rows would be rewritten', $output);
    $this->assertSame('Jane', $this->workspace('mine')->get('field_jurisdiction_address')->first()->get('given_name')->getValue());

    foreach ($tables as $table => [$field, $kind]) {
      $rows = scrub_operator_unscrubbed_rows($table, $field, $kind);
      $this->assertNotEmpty($rows, $table);
      scrub_operator_sweep($table, $field, $kind, $rows);
      $this->assertSame([], scrub_operator_unscrubbed_rows($table, $field, $kind), "$table: verify passes");
    }
    $this->container->get('cache.entity')->deleteAll();
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();

    // The scrubbed copy is complete and stays open; the person shape stays.
    $group = $this->workspace('mine');
    $this->assertSame([], OperatorDetails::missing($group));
    $this->assertTrue($this->gate()->openState($group));
    $this->assertSame('Scrubbed Operator', OperatorDetails::name($group));
    $this->assertSame('NL', $group->get('field_jurisdiction_address')->first()->get('country_code')->getValue());
    $this->assertSame(WorkspaceLifecycle::SCRUBBED_OPERATOR['email'], $group->get('field_jurisdiction_e_mail')->value);
    // Every revision.
    $revisionIds = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $group->id())->execute());
    $this->assertGreaterThan(2, count($revisionIds));
    foreach ($revisionIds as $revisionId) {
      $revision = $storage->loadRevision($revisionId);
      $this->assertNotSame('Jane', $revision->get('field_jurisdiction_address')->first()?->get('given_name')->getValue(), "revision $revisionId");
      $this->assertNotSame('mine@example.org', $revision->get('field_jurisdiction_e_mail')->value, "revision $revisionId");
      $this->assertStringNotContainsString('Jane', (string) $revision->get('field_legal_notice')->value, "revision $revisionId");
    }
    $this->assertSame(OperatorScrub::LEGAL_NOTICE, $group->get('field_legal_notice')->value);
    $this->assertSame('plain_text', $group->get('field_legal_notice')->format, 'The text format stays.');
    $this->assertStringContainsString('Legacy Org', (string) $this->workspace('legacy')->get('field_legal_notice')->value);
    // A workspace without a record keeps its data.
    $this->assertSame('Legacy Org', OperatorDetails::name($this->workspace('legacy')));
    $this->assertSame('legacy@example.org', $this->workspace('legacy')->get('field_jurisdiction_e_mail')->value);
  }

  /**
   * The lifecycle gate of this checkout.
   */
  private function gate(): WorkspaceLifecycleGate {
    return $this->container->get('markaspot_fastmap.workspace_lifecycle_gate');
  }

  /**
   * Mails of the opening key in the collector.
   */
  private function openingMails(string $key = WorkspaceOpening::MAIL_KEY): array {
    return array_values(array_filter($this->allMails(), static fn(array $mail): bool => ($mail['key'] ?? '') === $key));
  }

  /**
   * Marks a workspace as freshly provisioned for the given owner.
   */
  private function markPending(string $key, AccountInterface $owner): void {
    $group = $this->workspace($key);
    $this->container->get('markaspot_fastmap.workspace_lifecycle')->writeAcceptance(
      $group,
      WorkspaceLifecycle::parse(self::NOT_OPEN)['acceptance'],
      (int) $owner->id(),
      autoOpenPending: TRUE,
    );
    $group->save();
  }

  /**
   * Every collected mail.
   */
  private function allMails(): array {
    return $this->container->get('state')->get('system.test_mail_collector', []);
  }

  /**
   * Posts "Open map" as an account on its own session.
   */
  private function open(string $key, ?AccountInterface $account, array $payload): Response {
    $request = Request::create('/api/fastmap/workspace/' . $this->workspaces[$key]->id() . '/open', 'POST', [], [], [], [], json_encode($payload));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');
    return $this->send($request, $account);
  }

  /**
   * Sends a general settings PATCH.
   */
  private function patchGeneral(string $key, AccountInterface $account, array $payload): Response {
    $request = Request::create('/api/tenant-settings/' . $this->workspaces[$key]->id() . '/general', 'PATCH', [], [], [], [], json_encode($payload));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');
    return $this->send($request, $account);
  }

  /**
   * Reads the public settings as an anonymous visitor.
   *
   * @return array{body: array, max_age: ?string, tags: string[], cache_control: ?string}
   *   The decoded body and the cache headers.
   */
  private function publicSettings(string $key): array {
    $request = Request::create('/api/mark-a-spot-settings', 'GET', ['jurisdiction' => $this->workspaces[$key]->id()]);
    $response = $this->send($request, NULL);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return [
      'body' => json_decode((string) $response->getContent(), TRUE),
      'max_age' => $response->headers->get('X-Drupal-Cache-Max-Age') === NULL ? NULL : explode(' ', (string) $response->headers->get('X-Drupal-Cache-Max-Age'))[0],
      'tags' => explode(' ', (string) $response->headers->get('X-Drupal-Cache-Tags')),
      'cache_control' => $response->headers->get('Cache-Control'),
    ];
  }

  /**
   * Posts an anonymous citizen report through JSON:API.
   */
  private function postReport(string $key): Response {
    $request = Request::create('/jsonapi/node/service_request', 'POST', [], [], [], [], json_encode(['data' => [
      'type' => 'node--service_request',
      'attributes' => ['title' => 'Report in ' . $key],
      'relationships' => [
        'field_category' => [
          'data' => ['type' => 'taxonomy_term--service_category', 'id' => $this->categories[$key]->uuid()],
        ],
      ],
    ]]));
    $request->headers->set('Accept', 'application/vnd.api+json');
    $request->headers->set('Content-Type', 'application/vnd.api+json');
    return $this->send($request, NULL);
  }

  /**
   * Handles a request as an account with its own session, or anonymously.
   */
  private function send(Request $request, ?AccountInterface $account): Response {
    $this->setCurrentUser($account ?? new AnonymousUserSession());
    if ($account !== NULL) {
      $session = new Session(new MockArraySessionStorage());
      $session->set('uid', (int) $account->id());
      $request->setSession($session);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Matches a request through the access-aware router.
   */
  private function match(Request $request): string {
    $this->container->get('request_stack')->push($request);
    $this->container->get('router.request_context')->fromRequest($request);
    try {
      return (string) $this->container->get('router')->matchRequest($request)['_route'];
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Reloads a workspace without the static cache.
   */
  private function workspace(string $key): GroupInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([$this->workspaces[$key]->id()]);
    return $storage->load($this->workspaces[$key]->id());
  }

  /**
   * Reloads a report.
   */
  private function reloadNode(int $nid): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * Adds a workspace with operator data, a category and an initial status.
   */
  private function addWorkspace(string $key, array $values, array $address): void {
    $group = Group::create($values + [
      'type' => 'jur',
      'label' => 'Map ' . $key,
      'field_slug' => $key,
      'field_platform_name' => 'Map ' . $key,
      'field_tier' => 'free',
      'field_nuxt_config' => json_encode(['features' => []]),
      'field_jurisdiction_address' => $address,
      'field_jurisdiction_e_mail' => $key . '@example.org',
    ]);
    $group->save();
    $this->workspaces[$key] = $group;
    $this->categories[$key] = $this->createTerm('service_category', 'Pothole ' . $key, $group);
    $this->createTerm('service_status', 'Open ' . $key, $group, 'initial');
  }

  /**
   * Creates an account with a membership role in workspaces.
   */
  private function member(array $keys, string $name, string $groupRole, array $roles): User {
    $account = User::create(['name' => $name, 'mail' => $name . '@example.test', 'status' => 1, 'roles' => $roles]);
    $account->save();
    foreach ($keys as $key) {
      $this->workspaces[$key]->addMember($account, ['group_roles' => [$groupRole]]);
    }
    return User::load($account->id());
  }

  /**
   * Creates a category or status term of a jurisdiction.
   */
  private function createTerm(string $vid, string $name, GroupInterface $jurisdiction, ?string $mapping = NULL): TermInterface {
    $values = ['vid' => $vid, 'name' => $name, 'field_jurisdiction' => $jurisdiction->id()];
    if ($mapping !== NULL) {
      $values['field_open311_mapping'] = $mapping;
    }
    $term = Term::create($values);
    $term->save();
    return $term;
  }

  /**
   * Stubs the Open311 processor the report hooks consult.
   */
  private function stubProcessor(): void {
    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->container->set('markaspot_open311.processor', new class($termStorage) {

      public function __construct(
        private readonly EntityStorageInterface $termStorage,
      ) {}

      /**
       * Returns the initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->range(0, 1)
          ->execute();
        return $tids ? (int) reset($tids) : NULL;
      }

      /**
       * No boilerplates in this test.
       */
      public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int {
        return NULL;
      }

      /**
       * Creates a status note like the real processor.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create(['type' => 'status']);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        $paragraph->save();
        return $paragraph;
      }

    });
  }

  /**
   * Creates the node, taxonomy and paragraph model of a report.
   */
  private function createContentModel(): void {
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    if (!FilterFormat::load('plain_text')) {
      FilterFormat::create(['format' => 'plain_text', 'name' => 'Plain text'])->save();
    }
    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    $this->createField('node', 'service_request', 'body', 'text_with_summary');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_address', 'string');
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
  }

  /**
   * Creates the jurisdiction group type, roles and fields.
   */
  private function createJurisdictionModel(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'new_revision' => TRUE])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $relationshipStorage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }
    $roles = [
      'jur-tenant_admin' => [
        'view group',
        'view group_node:service_request entity',
        'view any unpublished group_node:service_request entity',
        'update any group_node:service_request entity',
      ],
      'jur-member' => ['view group', 'view group_node:service_request entity'],
    ];
    foreach ($roles as $id => $permissions) {
      GroupRole::create([
        'id' => $id,
        'label' => $id,
        'group_type' => 'jur',
        'scope' => 'individual',
        'permissions' => $permissions,
      ])->save();
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'jur', 'field_tier', 'list_string', [
      'allowed_values' => ['free' => 'Free', 'starter' => 'Starter', 'pro' => 'Pro', 'heart' => 'Heart'],
    ]);
    $this->createField('group', 'jur', 'field_expiry_date', 'timestamp');
    $this->createField('group', 'jur', 'field_stripe_subscription_id', 'string');
    $this->createField('group', 'jur', 'field_stripe_customer_id', 'string');
    $this->createField('group', 'jur', 'field_jurisdiction_address', 'address');
    $this->createField('group', 'jur', 'field_jurisdiction_e_mail', 'email');
    $this->createField('group', 'jur', 'field_visibility', 'string');
    $this->createField('group', 'jur', 'field_legal_notice', 'text_long');
    $this->createField('group', 'jur', WorkspaceLifecycle::FIELD, 'string_long');
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => $storageSettings,
    ])->save();
  }

}
