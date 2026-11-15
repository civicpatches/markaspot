<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Hook\Attribute\Hook;
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
use Drupal\markaspot_fastmap\Controller\BillingAdminController;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
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
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests workspaces that are not open: closed intake and publication gate.
 *
 * The refusal comes from the save of the report, so every entrance gets it:
 * JSON:API (the citizen form and the dashboard) answers 403 with the code
 * workspace_not_open in the detail; paths that save without an HTTP layer
 * (mail promotion, programmatic saves) get the exception. Workspaces without
 * a record, old permanent Free ones and old demo ones, behave as before.
 *
 * Where the site loads markaspot_fastmap from another checkout (a worktree
 * run), this class stands in for the module's save hook with the worktree's
 * classes; where the module is this checkout (CI), only the module's own
 * hook acts.
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class LifecycleClosedWorkspaceKernelTest extends KernelTestBase {

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
    // Where Basic Auth is enabled, an access denial for an anonymous request
    // becomes a 401 challenge.
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
   * A record in a workspace that is not open.
   */
  private const NOT_OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":null}';

  /**
   * A record in an open workspace.
   */
  private const OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":{"at":1800001000}}';

  /**
   * Workspaces by key, each with a category and an initial status.
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
   * Initial statuses by workspace key.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $statuses = [];

  /**
   * Accounts by label.
   *
   * @var array<string, \Drupal\user\Entity\User>
   */
  private array $accounts = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // A worktree run discovers the module's services from another checkout.
    if (!$container->hasDefinition('markaspot_fastmap.workspace_lifecycle')) {
      $container->register('markaspot_fastmap.workspace_lifecycle', WorkspaceLifecycle::class)
        ->setArguments([new Reference('config.factory'), new Reference('datetime.time')])
        ->setPublic(TRUE);
    }
    if (!$container->hasDefinition('markaspot_fastmap.workspace_lifecycle_gate')) {
      $container->register('markaspot_fastmap.workspace_lifecycle_gate', WorkspaceLifecycleGate::class)
        ->setArguments([
          new Reference('markaspot_fastmap.workspace_lifecycle'),
          new Reference('entity_type.manager'),
          new Reference('module_handler'),
          new Reference('request_stack'),
          new Reference('markaspot_group.hierarchy_resolver'),
          new Reference('markaspot_nuxt.review_before_publish'),
        ])
        ->setPublic(TRUE);
    }
  }

  /**
   * Leaves the presave check out, to test the insert recheck alone.
   *
   * Switches off only the stand-in hook below. See presaveCheckCanBeLeftOut().
   */
  private bool $skipPresaveCheck = FALSE;

  /**
   * Whether this run can leave the presave check out.
   *
   * Only the stand-in hook can be switched off. Where the module's own save
   * hook is loaded (a run in the module's checkout, as in CI), the presave
   * check always runs, so a save there never reaches the insert recheck with
   * a report the presave check would refuse.
   */
  private function presaveCheckCanBeLeftOut(): bool {
    return !$this->moduleIsThisCheckout()
      && !function_exists('_markaspot_fastmap_resolve_gate_group');
  }

  /**
   * Labels the pass that sets skipPresaveCheck.
   */
  private function skipPassLabel(): string {
    return $this->presaveCheckCanBeLeftOut()
      ? 'insert recheck alone'
      : 'save check again (the module hook cannot be left out)';
  }

  /**
   * Stands in for the module's save hook in a worktree run.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($this->moduleIsThisCheckout() || $this->skipPresaveCheck) {
      return;
    }
    $resolve = function_exists('_markaspot_fastmap_resolve_gate_group')
      ? '_markaspot_fastmap_resolve_gate_group'
      : '_markaspot_fastmap_resolve_jurisdiction_group_for_node';
    $this->gate()->enforceOnSave(
      $node,
      $this->container->get('current_user'),
      static fn(): ?GroupInterface => $resolve($node),
      (bool) drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE),
    );
  }

  /**
   * Stands in for the module's insert hook in a worktree run.
   *
   * Test hooks run after every module's implementation, so after
   * markaspot_group has derived field_jurisdiction, like the module's hook
   * ordered after markaspot_group.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if ($this->moduleIsThisCheckout()) {
      return;
    }
    $this->gate()->refuseAfterInsert(
      $node,
      $this->container->get('current_user'),
      (bool) drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE),
    );
  }

  /**
   * Whether the enabled module is the code of this checkout.
   */
  private function moduleIsThisCheckout(): bool {
    $path = $this->container->get('extension.list.module')->getPath('markaspot_fastmap');
    return realpath(DRUPAL_ROOT . '/' . $path) === realpath(dirname(__DIR__, 3));
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
    // The hourly cron prunes AI usage rows of the module.
    $this->installSchema('markaspot_fastmap', ['markaspot_ai_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'jsonapi']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->container->get('config.storage')->write('markaspot_fastmap.settings', [
      'tier_limits' => ['free' => ['limit' => 50, 'period' => 'published']],
      'demo_expiry_days' => 5,
    ]);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])
      ->grantPermission('access content')
      ->grantPermission('bypass mas validation')
      ->save();
    Role::create(['id' => 'api_user', 'label' => 'API user'])->grantPermission('access content')->save();
    Role::create(['id' => 'editorial_board', 'label' => 'Editorial board'])
      ->grantPermission('access content')
      ->grantPermission('administer nodes')
      ->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();
    // Citizens and staff create reports, as on a tenant.
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $roleId) {
      Role::load($roleId)
        ->grantPermission('access content')
        ->grantPermission('create service_request content')
        ->save();
    }

    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->addWorkspace('notOpen', ['field_tier' => 'free', WorkspaceLifecycle::FIELD => self::NOT_OPEN]);
    $this->addWorkspace('open', ['field_tier' => 'free', WorkspaceLifecycle::FIELD => self::OPEN]);
    $this->addWorkspace('oldFree', [
      'field_tier' => 'free',
      'field_stripe_customer_id' => 'cus_old',
      'field_stripe_subscription_id' => 'sub_old',
    ]);
    $this->addWorkspace('oldDemo', ['field_expiry_date' => $now + 3 * 86400]);

    $this->accounts['tenant admin'] = $this->member('notOpen', 'operator', 'jur-tenant_admin');
    $this->accounts['member'] = $this->member('notOpen', 'helper', 'jur-member');
    $this->accounts['outsider'] = User::create(['name' => 'outsider', 'mail' => 'outsider@example.test', 'status' => 1]);
    $this->accounts['outsider']->save();
    $apiUser = $this->member('notOpen', 'api_user', 'jur-member');
    $apiUser->addRole('api_user')->save();
    $this->accounts['api user'] = $apiUser;
    $this->accounts['platform admin'] = User::create([
      'name' => 'staff',
      'mail' => 'staff@example.test',
      'status' => 1,
      'roles' => ['editorial_board'],
    ]);
    $this->accounts['platform admin']->save();

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Who may create a report in a workspace that is not open, via JSON:API.
   */
  public function testIntakeThroughJsonApi(): void {
    $cases = [
      // [account label or NULL, session of that account, expected status].
      'anonymous citizen' => [NULL, FALSE, 403],
      'tenant admin, own session' => ['tenant admin', TRUE, 201],
      'member, own session' => ['member', TRUE, 201],
      'member without a session (API key)' => ['member', FALSE, 403],
      'logged in, no role here' => ['outsider', TRUE, 403],
      'API key owner with a session' => ['api user', TRUE, 403],
      'platform administrator, own session' => ['platform admin', TRUE, 201],
    ];
    // Second pass: the content type defaults to unpublished, as on some
    // tenants, so the reports arrive unpublished.
    foreach ([TRUE, FALSE] as $published) {
      if (!$published) {
        $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'service_request')['status']
          ->getConfig('service_request')->setDefaultValue(FALSE)->save();
        $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
      }
      foreach ($cases as $label => [$accountKey, $withSession, $expected]) {
        $before = $this->countReports('notOpen');
        $response = $this->postReport('notOpen', $accountKey === NULL ? NULL : $this->accounts[$accountKey], $withSession);
        $this->assertSame($expected, $response->getStatusCode(), "$label (published: " . var_export($published, TRUE) . '): ' . $response->getContent());
        if ($expected === 403) {
          $error = json_decode((string) $response->getContent(), TRUE)['errors'][0];
          $this->assertSame('403', (string) $error['status'], $label);
          $this->assertSame(WorkspaceLifecycleGate::MESSAGE, $error['detail'], $label);
          $this->assertFalse($response->headers->has('WWW-Authenticate'), "$label: no authentication challenge");
          $this->assertSame($before, $this->countReports('notOpen'), "$label: nothing stored.");
        }
        else {
          $id = json_decode((string) $response->getContent(), TRUE)['data']['attributes']['drupal_internal__nid'];
          $this->assertFalse($this->reload(Node::load($id))->isPublished(), "$label: a test report stays unpublished.");
        }
      }
    }

    // Open and old workspaces take public reports as before.
    foreach (['open', 'oldFree', 'oldDemo'] as $key) {
      $response = $this->postReport($key, NULL, FALSE);
      $this->assertSame(201, $response->getStatusCode(), "$key: " . $response->getContent());
    }
  }

  /**
   * Attack: the closed workspace's category, coordinates in another tree.
   *
   * No field_jurisdiction, coordinates inside a child jurisdiction of an
   * open workspace: the boundary resolver picks the child, markaspot_group
   * derives the closed workspace from the category. Refused, nothing stored,
   * by the save check and, with that left out, by the insert recheck. The
   * second pass proves the insert recheck only in a run with the stand-in
   * hook; where the module's own hook runs, it repeats the save check.
   */
  public function testCategoryOfClosedWorkspaceWithBoundaryElsewhere(): void {
    $child = Group::create([
      'type' => 'jur',
      'label' => 'District of the open map',
      'field_parent_jurisdiction' => $this->workspaces['open']->id(),
      'field_boundary' => json_encode([
        'type' => 'Polygon',
        'coordinates' => [[[6.0, 50.0], [7.0, 50.0], [7.0, 51.0], [6.0, 51.0], [6.0, 50.0]]],
      ]),
      'field_nuxt_config' => json_encode(['features' => []]),
    ]);
    $child->save();

    foreach ([FALSE => 'save check', TRUE => $this->skipPassLabel()] as $skip => $label) {
      $this->skipPresaveCheck = (bool) $skip;
      $nodes = $this->countAllReports();
      $request = Request::create('/jsonapi/node/service_request', 'POST', [], [], [], [], json_encode(['data' => [
        'type' => 'node--service_request',
        'attributes' => [
          'title' => 'Attack',
          'field_geolocation' => ['lat' => 50.5, 'lng' => 6.5],
        ],
        'relationships' => [
          'field_category' => [
            'data' => ['type' => 'taxonomy_term--service_category', 'id' => $this->categories['notOpen']->uuid()],
          ],
        ],
      ]]));
      $response = $this->send($request, NULL, FALSE);
      // Through JSON:API the category check refuses the mismatch first.
      $this->assertSame(422, $response->getStatusCode(), "$label: " . $response->getContent());
      $this->assertSame($nodes, $this->countAllReports(), "$label: no report stored");

      // A save without validation (a programmatic or mail path) with the
      // same input is refused by the lifecycle checks.
      $this->setCurrentUser(new AnonymousUserSession());
      try {
        Node::create([
          'type' => 'service_request',
          'title' => 'Attack',
          'uid' => 0,
          'field_category' => $this->categories['notOpen']->id(),
          'field_status' => $this->statuses['notOpen']->id(),
          'field_geolocation' => ['lat' => 50.5, 'lng' => 6.5],
        ])->save();
        $this->fail("$label: the report was stored.");
      }
      catch (EntityStorageException $e) {
        $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious(), $label);
      }
      $this->assertSame($nodes, $this->countAllReports(), "$label: no report stored");
    }
    $this->skipPresaveCheck = FALSE;
  }

  /**
   * A closed workspace's child area does not close reports for other maps.
   *
   * The closed workspace has a child jurisdiction whose boundary contains the
   * point. Reports for the open map located there are accepted, land in the
   * open map and can be published by its operator. A report that names no
   * jurisdiction at all is refused at presave: the boundary hit is all the
   * gate has, and it lies in the closed workspace.
   */
  public function testForeignChildAreaDoesNotCloseOtherMaps(): void {
    Group::create([
      'type' => 'jur',
      'label' => 'District of the closed map',
      'field_parent_jurisdiction' => $this->workspaces['notOpen']->id(),
      'field_boundary' => json_encode([
        'type' => 'Polygon',
        'coordinates' => [[[8.0, 52.0], [9.0, 52.0], [9.0, 53.0], [8.0, 53.0], [8.0, 52.0]]],
      ]),
      'field_nuxt_config' => json_encode(['features' => []]),
    ])->save();
    $point = ['lat' => 52.5, 'lng' => 8.5];
    $open = (int) $this->workspaces['open']->id();

    // With and without field_jurisdiction of the open map.
    foreach (['field and category' => TRUE, 'category only' => FALSE] as $label => $withField) {
      $this->setCurrentUser(new AnonymousUserSession());
      $values = $this->reportValues('open', 'Inside the closed district') + [
        'status' => 1,
        'field_geolocation' => $point,
      ];
      if (!$withField) {
        unset($values['field_jurisdiction']);
      }
      $report = Node::create($values);
      $report->save();
      $report = $this->reload($report);
      $this->assertTrue($report->isPublished(), "$label: not held by the closed map");
      $this->assertContains($open, array_map('intval', array_column($report->get('field_jurisdiction')->getValue(), 'target_id')), "$label: lands in the open map");
    }

    // The operator of the open map publishes a report located there.
    $admin = $this->member('open', 'open-operator-2', 'jur-tenant_admin');
    $this->setCurrentUser(new AnonymousUserSession());
    $waiting = Node::create($this->reportValues('open', 'Waiting inside') + [
      'status' => 0,
      'field_geolocation' => $point,
    ]);
    $waiting->save();
    $response = $this->patchStatus($this->reload($waiting), TRUE, $admin);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($this->reload($waiting)->isPublished(), 'Published by the operator of the open map.');

    // No category, no field: refused at presave through the boundary hit.
    $before = $this->countAllReports();
    $this->setCurrentUser(new AnonymousUserSession());
    try {
      Node::create(['type' => 'service_request', 'title' => 'Nowhere', 'uid' => 0, 'field_geolocation' => $point])->save();
      $this->fail('A report without jurisdiction inside the closed district was stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious());
    }
    $this->assertSame($before, $this->countAllReports());

    // The insert recheck alone does not see it: with several roots,
    // markaspot_group assigns no jurisdiction to such a report. Only a run
    // with the stand-in hook can show this; where the module's own hook runs,
    // the save check cannot be left out and refuses the report as above.
    $this->skipPresaveCheck = TRUE;
    $orphan = Node::create([
      'type' => 'service_request',
      'title' => 'Nowhere',
      'uid' => 0,
      'field_geolocation' => $point,
    ]);
    if ($this->presaveCheckCanBeLeftOut()) {
      $orphan->save();
      $this->skipPresaveCheck = FALSE;
      $this->assertTrue($this->reload($orphan)->get('field_jurisdiction')->isEmpty(), 'Stored without jurisdiction when only the insert recheck runs.');
      return;
    }
    try {
      $orphan->save();
      $this->fail('The module save check let a report without jurisdiction into the closed district.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious());
    }
    finally {
      $this->skipPresaveCheck = FALSE;
    }
    $this->assertSame($before, $this->countAllReports());
  }

  /**
   * Single root: the jurisdiction is assigned only on insert.
   *
   * A category without a jurisdiction on a site with one root: the save
   * check sees the root through the review set, the insert recheck through
   * the field markaspot_group sets. Either refuses. The second pass proves the
   * insert recheck only in a run with the stand-in hook; where the module's
   * own hook runs, it repeats the save check.
   */
  public function testSingleRootAssignsOnlyOnInsert(): void {
    foreach (['open', 'oldFree', 'oldDemo'] as $key) {
      // Free the group of its terms: categories go, a status mapping must
      // remain, so the statuses move to the closed workspace.
      $this->categories[$key]->delete();
      $this->statuses[$key]->set('field_jurisdiction', $this->workspaces['notOpen']->id())->save();
      $this->workspace($key)->delete();
    }
    $this->assertSame([(int) $this->workspaces['notOpen']->id()], array_map('intval', array_values($this->container->get('markaspot_group.hierarchy_resolver')->getAllRootJurisdictionIds())));
    $global = $this->createTerm('service_category', 'Litter');

    foreach ([FALSE => 'save check', TRUE => $this->skipPassLabel()] as $skip => $label) {
      $this->skipPresaveCheck = (bool) $skip;
      $this->setCurrentUser(new AnonymousUserSession());
      $values = ['field_category' => $global->id()] + $this->reportValues('notOpen', 'Single root') + ['status' => 0];
      unset($values['field_jurisdiction']);
      try {
        Node::create($values)->save();
        $this->fail("$label: a report was stored in the closed single root.");
      }
      catch (EntityStorageException $e) {
        $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious(), $label);
      }
      $this->assertSame(0, $this->countAllReports(), "$label: nothing stored");
    }
    $this->skipPresaveCheck = FALSE;
  }

  /**
   * A published report that joins a closed workspace is held.
   *
   * The field field_jurisdiction takes several values; the order does not
   * matter.
   */
  public function testPublishedReportJoiningClosedWorkspace(): void {
    $open = (int) $this->workspaces['open']->id();
    $closed = (int) $this->workspaces['notOpen']->id();
    foreach (['[O, W]' => [$open, $closed], '[W, O]' => [$closed, $open]] as $label => $ids) {
      $report = $this->publishedReports('open', 1)[0];
      $report = $this->reload($report);
      $this->assertTrue($report->isPublished());
      // Moving a report between jurisdictions is a platform administrator's
      // act.
      $this->setCurrentUser(User::load(1));
      $report->set('field_jurisdiction', $ids)->save();
      $report = $this->reload($report);
      $this->assertEqualsCanonicalizing($ids, array_map('intval', array_column($report->get('field_jurisdiction')->getValue(), 'target_id')), $label);
      $this->assertFalse($report->isPublished(), "$label: held");
    }
  }

  /**
   * A publish attempt on a second language is held too.
   */
  public function testPublishAttemptOnTranslation(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $response = $this->postReport('notOpen', $this->accounts['member'], TRUE);
    $this->assertSame(201, $response->getStatusCode());
    $report = $this->reload(Node::load((int) json_decode((string) $response->getContent(), TRUE)['data']['attributes']['drupal_internal__nid']));
    $report->addTranslation('de', ['title' => 'Meldung', 'status' => 0])->save();

    // Publish only the German translation.
    $report = $this->reload($report);
    $report->getTranslation('de')->setPublished()->save();
    $report = $this->reload($report);
    $this->assertFalse($report->getTranslation('de')->isPublished(), 'The German publish attempt is held.');
    $this->assertFalse($report->isPublished());

    // In an open record workspace the same works.
    $open = $this->unpublishedReport('open');
    $open->addTranslation('de', ['title' => 'Meldung', 'status' => 0])->save();
    $open = $this->reload($open);
    $open->getTranslation('de')->setPublished()->save();
    $this->assertTrue($this->reload($open)->getTranslation('de')->isPublished());
  }

  /**
   * A hand-built request naming the closed workspace with a foreign category.
   *
   * The category resolves to an old workspace; the report names the closed
   * one in its field. It must not end up stored in the closed workspace.
   */
  public function testHandBuiltRequestNamingTheClosedWorkspace(): void {
    $request = Request::create('/jsonapi/node/service_request', 'POST', [], [], [], [], json_encode(['data' => [
      'type' => 'node--service_request',
      'attributes' => ['title' => 'Crafted'],
      'relationships' => [
        'field_category' => [
          'data' => ['type' => 'taxonomy_term--service_category', 'id' => $this->categories['oldFree']->uuid()],
        ],
        'field_jurisdiction' => ['data' => [['type' => 'group--jur', 'id' => $this->workspaces['notOpen']->uuid()]]],
      ],
    ]]));
    $response = $this->send($request, NULL, FALSE);
    $this->assertSame(0, $this->countReports('notOpen'), 'Nothing stored in the closed workspace: ' . $response->getContent());
    // Validation refuses the mismatch before the save.
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());

    // A save that skips validation (a programmatic or mail path) with the
    // same mismatch is refused by the save itself.
    $this->setCurrentUser(new AnonymousUserSession());
    try {
      Node::create(['field_category' => $this->categories['oldFree']->id()] + $this->reportValues('notOpen', 'Crafted') + ['status' => 0])->save();
      $this->fail('A report naming the closed workspace was stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious());
    }
    $this->assertSame(0, $this->countReports('notOpen'));
  }

  /**
   * The Free limit binds the operator of a record workspace; the hourly run.
   *
   * An open record workspace at 50 published reports: the tenant admin's
   * next publish is refused with the limit message, and the hourly limit run
   * finds nothing to take down. After a downgrade from an unlimited tier the
   * hourly run takes the excess offline, oldest first, as for any Free
   * workspace.
   */
  public function testFreeLimitAndHourlyRunInRecordWorkspace(): void {
    $admin = $this->member('open', 'open-operator', 'jur-tenant_admin');
    $this->assertTrue($admin->hasPermission('bypass mas validation'));
    $first = $this->publishedReports('open', 50);
    $held = $this->unpublishedReport('open');

    $response = $this->patchStatus($held, TRUE, $admin);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringContainsString('50', (string) $response->getContent());
    $this->assertFalse($this->reload($held)->isPublished());

    $this->runHourlyLimit();
    $this->assertSame(50, $this->countPublished('open'), 'At the limit: the hourly run takes nothing down.');

    // Downgrade: published above the Free limit under a larger tier.
    $this->config('markaspot_fastmap.settings')->set('tier_limits.heart', ['limit' => 1000, 'period' => 'published'])->save();
    $this->workspace('open')->set('field_tier', 'heart')->save();
    $this->setCurrentUser(new AnonymousUserSession());
    $this->publishedReports('open', 2, 100);
    $this->assertSame(52, $this->countPublished('open'));
    $this->workspace('open')->set('field_tier', 'free')->save();
    $this->runHourlyLimit();
    $this->assertSame(50, $this->countPublished('open'));
    $this->assertFalse($this->reload($first[0])->isPublished(), 'The oldest report went offline first.');
    $this->assertFalse($this->reload($first[1])->isPublished());
    $this->assertTrue($this->reload($first[2])->isPublished());
    $this->assertSame(self::OPEN, $this->workspace('open')->get(WorkspaceLifecycle::FIELD)->value, 'Record and opening unchanged.');
  }

  /**
   * Old permanent Free workspace: staff pass the limit, the hourly run trims.
   *
   * Pins today's behaviour for a workspace without a record: the tenant admin
   * publishes the 51st report, and the hourly limit run then takes the oldest
   * published report offline, without telling anyone.
   */
  public function testOldFreeWorkspaceStaffPassAndHourlyRun(): void {
    $admin = $this->member('oldFree', 'old-operator', 'jur-tenant_admin');
    $first = $this->publishedReports('oldFree', 50);
    $held = $this->unpublishedReport('oldFree');

    $response = $this->patchStatus($held, TRUE, $admin);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($this->reload($held)->isPublished(), 'Staff pass the limit in a workspace without a record.');

    $mails = count($this->container->get('state')->get('system.test_mail_collector', []));
    $this->runHourlyLimit();
    $this->assertSame(50, $this->countPublished('oldFree'));
    $this->assertFalse($this->reload($first[0])->isPublished(), 'The oldest report went offline, not the one just published.');
    $this->assertTrue($this->reload($held)->isPublished());
    $this->assertSame($mails, count($this->container->get('state')->get('system.test_mail_collector', [])), 'Nobody is told.');
  }

  /**
   * A platform administrator passes the limit in both kinds of workspace.
   */
  public function testPlatformAdministratorPassesTheLimit(): void {
    Role::load('editorial_board')->grantPermission('bypass node access')->save();
    $platformAdmin = User::load($this->accounts['platform admin']->id());
    foreach (['open', 'oldFree'] as $key) {
      $this->publishedReports($key, 50);
      $held = $this->unpublishedReport($key);
      $response = $this->patchStatus($held, TRUE, $platformAdmin);
      $this->assertSame(200, $response->getStatusCode(), "$key: " . $response->getContent());
      $this->assertTrue($this->reload($held)->isPublished(), $key);
    }
  }

  /**
   * Saves that skip validation hit the backstop; exempt saves pass.
   */
  public function testSaveBackstopAndExemptions(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    try {
      Node::create($this->reportValues('notOpen', 'Mail report') + ['status' => 0])->save();
      $this->fail('A report without validation was stored in a workspace that is not open.');
    }
    catch (EntityStorageException $e) {
      $previous = $e->getPrevious();
      $this->assertInstanceOf(WorkspaceNotOpenException::class, $previous);
      $this->assertSame(403, $previous->getStatusCode());
      $this->assertSame(WorkspaceLifecycleGate::MESSAGE, $previous->getMessage());
    }
    $this->assertSame(0, $this->countReports('notOpen'));

    // The examples provisioning seeds: stored and published.
    $seeding = &drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE);
    $seeding = TRUE;
    try {
      $example = Node::create($this->reportValues('notOpen', 'Example') + ['status' => 1]);
      $example->save();
    }
    finally {
      $seeding = FALSE;
    }
    $this->assertTrue($this->reload($example)->isPublished(), 'Seeded examples are published.');

    // Content synchronisation keeps what it brings.
    $synced = Node::create($this->reportValues('notOpen', 'Synced') + ['status' => 1]);
    $synced->setSyncing(TRUE);
    $synced->save();
    $this->assertTrue($this->reload($synced)->isPublished(), 'Syncing saves are exempt.');
  }

  /**
   * Nothing but the examples becomes public while the map is not open.
   */
  public function testPublicationGate(): void {
    $response = $this->postReport('notOpen', $this->accounts['member'], TRUE);
    $this->assertSame(201, $response->getStatusCode());
    $id = (int) json_decode((string) $response->getContent(), TRUE)['data']['attributes']['drupal_internal__nid'];
    $report = $this->reload(Node::load($id));
    $this->assertFalse($report->isPublished());

    // The tenant admin publishes it: accepted, and still not public.
    $patch = $this->patchStatus($report, TRUE, $this->accounts['tenant admin']);
    $this->assertSame(200, $patch->getStatusCode(), (string) $patch->getContent());
    $this->assertFalse($this->reload($report)->isPublished(), 'A publish attempt is undone while not open.');

    // A seeded example the operator unpublished stays unpublished.
    $seeding = &drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE);
    $seeding = TRUE;
    $example = Node::create($this->reportValues('notOpen', 'Example') + ['status' => 1]);
    $example->save();
    $seeding = FALSE;
    $example = $this->reload($example);
    $example->setUnpublished()->save();
    $example = $this->reload($example);
    $example->setPublished()->save();
    $this->assertFalse($this->reload($example)->isPublished(), 'An unpublished example stays unpublished until the map opens.');

    // In an open record workspace the same publish works.
    $openReport = Node::create($this->reportValues('open', 'Open map report') + ['status' => 0]);
    $openReport->save();
    $openReport = $this->reload($openReport);
    $openReport->setPublished()->save();
    $this->assertTrue($this->reload($openReport)->isPublished());
  }

  /**
   * Old workspaces behave as before; the record workspace follows the model.
   *
   * Old permanent Free (tier free, subscription, no expiry, no record), old
   * demo (expiry, no subscription, no record) and a record workspace that is
   * not open, also after a cancellation.
   */
  public function testComparisonWithOldWorkspaces(): void {
    $gate = $this->gate();
    $lifecycle = $this->container->get('markaspot_fastmap.workspace_lifecycle');

    // Publication gate and intake for an anonymous programmatic report.
    $this->setCurrentUser(new AnonymousUserSession());
    $oldFree = Node::create($this->reportValues('oldFree', 'Old free') + ['status' => 1]);
    $oldFree->save();
    $this->assertTrue($this->reload($oldFree)->isPublished(), 'Old permanent Free: published as before.');
    $oldDemo = Node::create($this->reportValues('oldDemo', 'Old demo') + ['status' => 1]);
    $oldDemo->save();
    $this->assertFalse($this->reload($oldDemo)->isPublished(), 'Old demo: the demo gate holds it as before.');
    try {
      Node::create($this->reportValues('notOpen', 'Refused') + ['status' => 1])->save();
      $this->fail('The record workspace took a public report.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(WorkspaceNotOpenException::class, $e->getPrevious());
    }

    // Settings response values.
    $expect = [
      'oldFree' => [NULL, TRUE],
      'oldDemo' => [NULL, FALSE],
      'notOpen' => [FALSE, TRUE],
      'open' => [TRUE, TRUE],
    ];
    foreach ($expect as $key => [$open, $permanent]) {
      $group = $this->workspace($key);
      $this->assertSame($open, $gate->openState($group), "$key: open");
      $this->assertSame($permanent, !_markaspot_fastmap_workspace_in_demo_state($group), "$key: is_permanent");
      if ($this->moduleIsThisCheckout()) {
        $settings = ['jurisdiction' => []];
        markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $group);
        $this->assertSame($permanent, $settings['jurisdiction']['is_permanent'], "$key: is_permanent in the response");
        if ($open === NULL) {
          $this->assertArrayNotHasKey('open', $settings['jurisdiction'], "$key: no open key");
        }
        else {
          $this->assertSame($open, $settings['jurisdiction']['open'], "$key: open in the response");
          $this->assertArrayNotHasKey('expiry_date', $settings['jurisdiction'], "$key: no expiry");
        }
      }
    }

    // Billing state as the billing controllers compute it.
    $states = [
      'oldFree' => 'free_permanent',
      'oldDemo' => 'demo',
      'notOpen' => 'free_permanent',
    ];
    foreach ($states as $key => $state) {
      $this->assertSame($state, $this->billingState($this->workspace($key)), "$key: billing state");
    }

    // A cancellation of a paid plan: customer kept, subscription removed,
    // tier free. Free, active; record and state unchanged.
    $notOpen = $this->workspace('notOpen');
    $notOpen->set('field_stripe_customer_id', 'cus_new')->set('field_stripe_subscription_id', NULL)->set('field_tier', 'free')->save();
    $notOpen = $this->workspace('notOpen');
    $this->assertSame('free_permanent', $this->billingState($notOpen));
    $this->assertSame(self::NOT_OPEN, $notOpen->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertTrue($lifecycle->isNotOpen($notOpen));
    $this->assertTrue($gate->isClosed($notOpen));

    // Reminder and teardown: the expired old demo goes, the record stays.
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->workspace('oldDemo')->set('field_expiry_date', $now + 3600)->save();
    $this->addWorkspace('expiredDemo', ['field_expiry_date' => $now - 60]);
    $this->container->get('state')->set('markaspot_fastmap.last_demo_cleanup', 0);
    markaspot_fastmap_cron();
    $state = $this->container->get('state');
    $this->assertTrue((bool) $state->get('markaspot_fastmap.demo_reminder_sent.' . $this->workspaces['oldDemo']->id()), 'Old demo: reminder as before.');
    $this->assertNull($state->get('markaspot_fastmap.demo_reminder_sent.' . $this->workspaces['notOpen']->id()), 'Record workspace: no reminder.');
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $this->assertNull(Group::load($this->workspaces['expiredDemo']->id()), 'Expired old demo: torn down as before.');
    $this->assertNotNull(Group::load($this->workspaces['notOpen']->id()), 'Record workspace: kept.');
  }

  /**
   * The billing admin listing reads the record workspace as Free, active.
   *
   * A record workspace and an old one with the same billing fields (tier
   * free, a customer, no subscription: an aborted checkout or a
   * cancellation) are told apart by the record scope alone.
   */
  public function testBillingAdminListing(): void {
    foreach (['notOpen', 'oldFree'] as $key) {
      $this->workspace($key)->set('field_stripe_customer_id', 'cus_' . $key)->set('field_stripe_subscription_id', NULL)->save();
    }
    $controller = BillingAdminController::create($this->container);
    $ids = function (string $state) use ($controller): array {
      $build = $controller->listing(Request::create('/admin/billing', 'GET', ['state' => $state]));
      $found = [];
      $walk = static function (array $element) use (&$walk, &$found): void {
        if (isset($element['#rows']) && is_array($element['#rows'])) {
          foreach ($element['#rows'] as $row) {
            $found[] = (int) $row[0]['data'];
          }
        }
        foreach ($element as $key => $child) {
          if (is_array($child) && !str_starts_with((string) $key, '#')) {
            $walk($child);
          }
        }
      };
      $walk($build);
      return $found;
    };
    $this->assertContains((int) $this->workspaces['notOpen']->id(), $ids('free_permanent'), 'Record workspace: Free, active.');
    $this->assertNotContains((int) $this->workspaces['oldFree']->id(), $ids('free_permanent'));
    $this->assertContains((int) $this->workspaces['oldFree']->id(), $ids('canceled'), 'Old workspace with the same fields: as today.');
    $this->assertNotContains((int) $this->workspaces['notOpen']->id(), $ids('canceled'));
  }

  /**
   * Deletion, restore and the tier limit read a record workspace as Free.
   */
  public function testDeletionRestoreAndTierLimitForRecordWorkspace(): void {
    $deletion = $this->container->get('markaspot_fastmap.workspace_deletion');
    $notOpen = $this->workspace('notOpen');
    $this->assertNull($deletion->ineligibilityReason($notOpen), 'Self-service deletion is open to a Free record workspace.');

    // A restore after a scheduled deletion writes no expiry.
    $this->container->get('keyvalue')->get('markaspot_fastmap_workspace_deletions')->set((string) $notOpen->id(), [
      'prior_status' => 1,
      'prior_expiry' => 0,
      'prior_visibility' => '',
    ]);
    $this->assertTrue($deletion->restore($notOpen));
    $this->assertTrue($this->workspace('notOpen')->get('field_expiry_date')->isEmpty());

    // The Free limit applies to an open record workspace as to any Free one.
    for ($i = 0; $i < 50; $i++) {
      Node::create($this->reportValues('open', 'Published ' . $i) + ['status' => 1])->save();
    }
    $held = Node::create($this->reportValues('open', 'Over the limit') + ['status' => 0]);
    $held->save();
    $violations = $this->reload($held)->setPublished()->validate();
    $messages = array_map(static fn($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    $this->assertNotEmpty(array_filter($messages, static fn(string $message): bool => str_contains($message, '50')), implode(' | ', $messages));
  }

  /**
   * The gate of this checkout.
   */
  private function gate(): WorkspaceLifecycleGate {
    return $this->container->get('markaspot_fastmap.workspace_lifecycle_gate');
  }

  /**
   * Computes the billing state like the billing controllers do.
   */
  private function billingState(GroupInterface $group): string {
    $value = static fn(string $field): ?string => $group->get($field)->isEmpty() ? NULL : (string) $group->get($field)->value;
    $expiry = $value('field_expiry_date');
    return (new BillingStateResolver())->resolve(
      $value('field_tier'),
      $value('field_stripe_customer_id'),
      $value('field_stripe_subscription_id'),
      $expiry === NULL ? NULL : (int) $expiry,
      $this->gate()->recordWorkspace($group) !== NULL,
    );
  }

  /**
   * Adds a workspace with its category and initial status.
   */
  private function addWorkspace(string $key, array $values): void {
    $group = Group::create([
      'type' => 'jur',
      'label' => 'Map ' . $key,
      'field_nuxt_config' => json_encode(['features' => []]),
    ] + $values);
    $group->save();
    $this->workspaces[$key] = $group;
    $this->categories[$key] = $this->createTerm('service_category', 'Pothole ' . $key, $group);
    $this->statuses[$key] = $this->createTerm('service_status', 'Open ' . $key, $group, 'initial');
  }

  /**
   * Reloads a workspace.
   */
  private function workspace(string $key): GroupInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([$this->workspaces[$key]->id()]);
    return $storage->load($this->workspaces[$key]->id());
  }

  /**
   * Creates an account with a membership role in a workspace.
   */
  private function member(string $key, string $name, string $role): User {
    $account = User::create(['name' => $name, 'mail' => $name . '@example.test', 'status' => 1]);
    $account->save();
    $this->workspaces[$key]->addMember($account, ['group_roles' => [$role]]);
    return User::load($account->id());
  }

  /**
   * Values of a report in a workspace.
   */
  private function reportValues(string $key, string $title): array {
    return [
      'type' => 'service_request',
      'title' => $title,
      'uid' => 0,
      'body' => ['value' => 'Something is broken.', 'format' => 'plain_text'],
      'field_category' => $this->categories[$key]->id(),
      'field_status' => $this->statuses[$key]->id(),
      'field_jurisdiction' => $this->workspaces[$key]->id(),
    ];
  }

  /**
   * Creates published reports with increasing creation times.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The reports, oldest first.
   */
  private function publishedReports(string $key, int $count, int $offset = 0): array {
    $this->setCurrentUser(new AnonymousUserSession());
    $base = $this->container->get('datetime.time')->getRequestTime() - 100000;
    $nodes = [];
    for ($i = 0; $i < $count; $i++) {
      $node = Node::create($this->reportValues($key, 'Published ' . ($offset + $i)) + [
        'status' => 1,
        'created' => $base + ($offset + $i) * 60,
      ]);
      $node->save();
      $this->assertTrue($this->reload($node)->isPublished(), "$key: report " . ($offset + $i) . ' published');
      $nodes[] = $node;
    }
    return $nodes;
  }

  /**
   * Creates one unpublished report.
   */
  private function unpublishedReport(string $key): NodeInterface {
    $this->setCurrentUser(new AnonymousUserSession());
    $node = Node::create($this->reportValues($key, 'Waiting') + ['status' => 0]);
    $node->save();
    return $this->reload($node);
  }

  /**
   * Counts the published reports of a workspace.
   */
  private function countPublished(string $key): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $this->workspaces[$key]->id())
      ->count()
      ->execute();
  }

  /**
   * Runs the module's cron with only the hourly limit run due.
   */
  private function runHourlyLimit(): void {
    $state = $this->container->get('state');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $state->set('markaspot_fastmap.last_limit_check', 0);
    $state->set('markaspot_fastmap.last_demo_cleanup', $now);
    $state->set('markaspot_fastmap.last_workspace_deletion_run', $now);
    $state->set('markaspot_fastmap.last_ai_usage_cleanup', $now);
    $this->setCurrentUser(new AnonymousUserSession());
    markaspot_fastmap_cron();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
  }

  /**
   * Counts every report.
   */
  private function countAllReports(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->count()
      ->execute();
  }

  /**
   * Counts the reports of a workspace.
   */
  private function countReports(string $key): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_jurisdiction', $this->workspaces[$key]->id())
      ->count()
      ->execute();
  }

  /**
   * Posts a report through JSON:API like the citizen form or the dashboard.
   */
  private function postReport(string $key, ?AccountInterface $account, bool $withSession): Response {
    // The citizen form sends no status: the content type default applies.
    $request = Request::create('/jsonapi/node/service_request', 'POST', [], [], [], [], json_encode(['data' => [
      'type' => 'node--service_request',
      'attributes' => [
        'title' => 'Report in ' . $key,
      ],
      'relationships' => [
        'field_category' => [
          'data' => ['type' => 'taxonomy_term--service_category', 'id' => $this->categories[$key]->uuid()],
        ],
      ],
    ]]));
    return $this->send($request, $account, $withSession);
  }

  /**
   * Sends the dashboard's status PATCH.
   */
  private function patchStatus(NodeInterface $node, bool $published, AccountInterface $account): Response {
    $request = Request::create('/jsonapi/node/service_request/' . $node->uuid(), 'PATCH', [], [], [], [], json_encode(['data' => [
      'type' => 'node--service_request',
      'id' => $node->uuid(),
      'attributes' => ['status' => $published],
    ]]));
    return $this->send($request, $account, TRUE);
  }

  /**
   * Handles a JSON:API request as an account, with or without its session.
   */
  private function send(Request $request, ?AccountInterface $account, bool $withSession): Response {
    $request->headers->set('Accept', 'application/vnd.api+json');
    $request->headers->set('Content-Type', 'application/vnd.api+json');
    $this->setCurrentUser($account ?? new AnonymousUserSession());
    if ($account !== NULL && $withSession) {
      $session = new Session(new MockArraySessionStorage());
      $session->set('uid', (int) $account->id());
      $request->setSession($session);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Reloads a report without the static cache.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Creates a category or status term, optionally of a jurisdiction.
   */
  private function createTerm(string $vid, string $name, ?GroupInterface $jurisdiction = NULL, ?string $mapping = NULL): TermInterface {
    $values = [
      'vid' => $vid,
      'name' => $name,
      'field_jurisdiction' => $jurisdiction?->id(),
    ];
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
       * Returns the lowest-weight initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->sort('weight')
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
       * Creates a status note like the real processor, without boilerplates.
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

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    // Group permissions consult organisations of a jurisdiction.
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
