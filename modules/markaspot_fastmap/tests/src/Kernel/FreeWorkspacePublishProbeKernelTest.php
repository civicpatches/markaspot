<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\PublishedLimitReachedException;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
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
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Probes publishing by a tenant admin on a Free workspace under review.
 *
 * The self-service model rests on this: with reviewBeforePublish on, a new
 * report is held, and the tenant admin of the workspace (a group membership
 * with jur-tenant_admin and the global role the membership syncs) publishes
 * it the way the dashboard does, a JSON:API PATCH of status. Automatic
 * publication leaves held reports alone, and the Free limit of published
 * reports applies to that publish: in a workspace without a lifecycle record
 * only to accounts without the staff bypass (today's rule), in a workspace
 * with a record to the tenant admin too.
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class FreeWorkspacePublishProbeKernelTest extends KernelTestBase {

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
   * The Free workspace.
   */
  private GroupInterface $workspace;

  /**
   * Its category.
   */
  private TermInterface $category;

  /**
   * Its initial status.
   */
  private TermInterface $status;

  /**
   * The tenant admin of the workspace.
   */
  private User $tenantAdmin;

  /**
   * A record of an open workspace, as the test helper writes it.
   */
  private const OPEN_RECORD = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":{"opened_at":1800001000,"uid":7,"revision_id":1,"operator":{"organization":"Example Org","address_line1":"Main street 1","postal_code":"12345","locality":"Town","country_code":"DE","email":"operator@example.org"}}}';

  /**
   * Today's sentence of a refused publish at 50, default wording, English.
   */
  private const LIMIT_SENTENCE = 'The publication limit has been reached. reports: 50. Unpublish existing items or choose a plan.';

  /**
   * The open Free workspace with a lifecycle record.
   */
  private GroupInterface $recordWorkspace;

  /**
   * Its category.
   */
  private TermInterface $recordCategory;

  /**
   * Its initial status.
   */
  private TermInterface $recordStatus;

  /**
   * The tenant admin of the record workspace.
   */
  private User $recordTenantAdmin;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Lets a publish lose the race for the last slot (backstop test).
    if ($container->hasDefinition('markaspot_fastmap.tier_config')) {
      $container->getDefinition('markaspot_fastmap.tier_config')->setClass(RacingTierConfig::class);
    }
    // A worktree run discovers the module's services from another checkout;
    // the TierLimit constraint asks the gate for the staff bypass.
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
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'jsonapi']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    // Written to storage directly: only the values this probe needs.
    $this->container->get('config.storage')->write('markaspot_fastmap.settings', [
      'tier_limits' => [
        'free' => ['limit' => 50, 'period' => 'published'],
        // A published-count limit for a paid tier, to compare staff there.
        'starter' => ['limit' => 50, 'period' => 'published'],
      ],
    ]);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();

    // A Free workspace without expiry: permanent, not in demo state.
    $this->workspace = Group::create([
      'type' => 'jur',
      'label' => 'Free map',
      'field_nuxt_config' => json_encode(['features' => []]),
      'field_tier' => 'free',
    ]);
    $this->workspace->save();
    $this->category = $this->createTerm('service_category', 'Pothole', $this->workspace);
    $this->status = $this->createTerm('service_status', 'Open', $this->workspace, 'initial');

    // The global role the membership syncs, with the permission production
    // gives it that matters here.
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])
      ->grantPermission('access content')
      ->grantPermission('bypass mas validation')
      ->save();
    $this->tenantAdmin = User::create(['name' => 'operator', 'mail' => 'operator@example.test', 'status' => 1]);
    $this->tenantAdmin->save();
    $this->workspace->addMember($this->tenantAdmin, ['group_roles' => ['jur-tenant_admin']]);
    $this->tenantAdmin = User::load($this->tenantAdmin->id());

    // The same, under the lifecycle model and opened.
    $this->recordWorkspace = Group::create([
      'type' => 'jur',
      'label' => 'Free map with record',
      'field_nuxt_config' => json_encode(['features' => []]),
      'field_tier' => 'free',
    ]);
    $this->recordWorkspace->save();
    $this->writeOpenRecord($this->recordWorkspace);
    $this->recordCategory = $this->createTerm('service_category', 'Streetlight', $this->recordWorkspace);
    $this->recordStatus = $this->createTerm('service_status', 'Open', $this->recordWorkspace, 'initial');
    $recordAdmin = User::create(['name' => 'record-operator', 'mail' => 'record-operator@example.test', 'status' => 1]);
    $recordAdmin->save();
    $this->recordWorkspace->addMember($recordAdmin, ['group_roles' => ['jur-tenant_admin']]);
    $this->recordTenantAdmin = User::load($recordAdmin->id());

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Held, published by the tenant admin, released; the limit binds.
   */
  public function testTenantAdminPublishesHeldReportOnFreeWorkspace(): void {
    $this->assertTrue($this->tenantAdmin->hasRole('tenant_admin'), 'The membership synced the global role.');
    $this->setFlag($this->workspace, TRUE);

    // Anonymous citizen report through the citizen form route: held.
    $held = $this->createAsAnonymous();
    $this->assertFalse($held->isPublished(), 'The new report is held unpublished.');
    $this->assertTrue($this->review()->isHeld($held), 'The hold is recorded.');

    // Automatic publication leaves it alone.
    $veto = FALSE;
    $this->container->get('module_handler')->alter('markaspot_publisher_hold', $veto, $held);
    $this->assertTrue($veto, 'The publisher hold hook vetoes the held report.');

    // The tenant admin publishes it like the dashboard: JSON:API PATCH.
    $this->setCurrentUser($this->tenantAdmin);
    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $held = $this->reload($held);
    $this->assertTrue($held->isPublished(), 'The tenant admin published the report.');
    $this->assertFalse($this->review()->isHeld($held), 'Publishing released the hold.');
  }

  /**
   * At 50 published reports, the 51st publish by the tenant admin.
   *
   * Pins today's behaviour: the shipped tenant_admin role holds 'bypass mas
   * validation', and _markaspot_fastmap_account_bypasses_tier_limit() lets
   * staff with it pass the limit inside their own workspace. The operator
   * of a Free workspace can therefore publish beyond 50.
   */
  public function testFreeLimitDoesNotBindTenantAdminWithValidationBypass(): void {
    $held = $this->heldReportAtLimit();
    $this->setCurrentUser($this->tenantAdmin);
    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($this->reload($held)->isPublished());
  }

  /**
   * Without the validation bypass the 51st publish is refused with a 422.
   */
  public function testFreeLimitRefusesPublishWithoutValidationBypass(): void {
    Role::load('tenant_admin')->revokePermission('bypass mas validation')->save();
    $held = $this->heldReportAtLimit();
    $this->setCurrentUser(User::load($this->tenantAdmin->id()));
    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringContainsString('50', (string) $response->getContent());
    $this->assertFalse($this->reload($held)->isPublished());
    // Without a record the text stays as it was, byte for byte, no code.
    $this->assertSame(['Entity is not valid: ' . self::LIMIT_SENTENCE], $this->errorDetails($response));
  }

  /**
   * With a record the refusal of the constraint carries the machine code.
   *
   * The exact JSON:API shape the dashboard matches on: an entity-level
   * violation, so the detail reads "Entity is not valid: " followed by the
   * code and the sentence, with the pointer /data.
   */
  public function testLimitRefusalInRecordWorkspaceCarriesTheCode(): void {
    $held = $this->heldReportAtLimit(TRUE);
    $this->setCurrentUser($this->recordTenantAdmin);
    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['Entity is not valid: published_limit_reached: ' . self::LIMIT_SENTENCE], $this->errorDetails($response));
    $errors = json_decode((string) $response->getContent(), TRUE)['errors'];
    $this->assertSame('/data', $errors[0]['source']['pointer']);
    $this->assertSame('422', (string) $errors[0]['status']);
  }

  /**
   * The presave backstop (a publish that loses the race for the last slot).
   *
   * The validation counts 49, the save under the lock counts 50: the
   * TierLimit constraint passes and markaspot_fastmap_node_presave()
   * refuses. With a record: a 422 whose detail starts with the code. Without
   * a record: today's storage exception, unchanged.
   */
  public function testBackstopRefusalShapes(): void {
    $held = $this->heldReportAtLimit(TRUE);
    $this->setCurrentUser($this->recordTenantAdmin);
    RacingTierConfig::$race = TRUE;
    $response = $this->patchStatus($held, TRUE);
    RacingTierConfig::$race = FALSE;
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['published_limit_reached: ' . self::LIMIT_SENTENCE], $this->errorDetails($response));
    $this->assertFalse($this->reload($held)->isPublished());

    // The same refusal on a save without validation: the storage exception
    // carries the 422 exception, whatever the caller.
    $this->setCurrentUser($this->recordTenantAdmin);
    $held = $this->reload($held);
    try {
      $held->setPublished()->save();
      $this->fail('The publish over the limit was stored.');
    }
    catch (EntityStorageException $e) {
      $previous = $e->getPrevious();
      $this->assertInstanceOf(PublishedLimitReachedException::class, $previous);
      $this->assertSame(422, $previous->getStatusCode());
      $this->assertSame('published_limit_reached: ' . self::LIMIT_SENTENCE, $e->getMessage());
    }

    // Without a record (and without the staff bypass, so the limit binds):
    // today's storage exception, unchanged. Through JSON:API that is a 500
    // with this message as detail; a kernel test fails on the logged 500, so
    // the save is checked directly.
    Role::load('tenant_admin')->revokePermission('bypass mas validation')->save();
    $legacy = $this->reload($this->heldReportAtLimit());
    $this->setCurrentUser(User::load($this->tenantAdmin->id()));
    try {
      $legacy->setPublished()->save();
      $this->fail('The publish over the limit was stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertSame(sprintf('Cannot publish service request: jurisdiction %d has reached the published report limit of 50.', $this->workspace->id()), $e->getMessage());
      $this->assertNotInstanceOf(PublishedLimitReachedException::class, $e->getPrevious());
    }
  }

  /**
   * A citizen report at the limit with approval off: saved, never refused.
   *
   * Markaspot_fastmap_node_presave() keeps it unpublished; no refusal text,
   * prefixed or not, reaches the citizen.
   */
  public function testCitizenReportAtLimitIsKeptUnpublishedWithoutRefusal(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    for ($i = 0; $i < 50; $i++) {
      Node::create($this->reportValues('Published ' . $i, TRUE) + ['status' => 1])->save();
    }
    $this->setFlag($this->recordWorkspace, FALSE);
    $node = $this->createAsAnonymous(TRUE);
    $this->assertFalse($node->isPublished());
    $this->assertFalse($this->review()->isHeld($node), 'Approval is off: not held, only kept under the limit.');
  }

  /**
   * The code follows the workspace the report is stored in.
   *
   * A report of the workspace without a record, without a category, is
   * published with the record workspace's ID as request jurisdiction: the
   * resolver takes that group (open finding, out of scope) and the record
   * workspace is at its limit. The refusal is today's text, without the
   * code, because the report belongs to the workspace without a record.
   */
  public function testCodeFollowsTheReportsOwnWorkspace(): void {
    $this->heldReportAtLimit(TRUE);
    $this->setCurrentUser(new AnonymousUserSession());
    $values = $this->reportValues('Legacy report') + ['status' => 0];
    unset($values['field_category']);
    $legacy = Node::create($values);
    $legacy->save();
    $this->assertFalse($this->container->get('markaspot_fastmap.workspace_lifecycle_gate')->isRecordReport($legacy));

    $this->setCurrentUser($this->tenantAdmin);
    $response = $this->patchStatus($legacy, TRUE, (int) $this->recordWorkspace->id());
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['Entity is not valid: ' . self::LIMIT_SENTENCE], $this->errorDetails($response));
    $this->assertFalse($this->reload($legacy)->isPublished());
  }

  /**
   * The detail strings of a JSON:API error response.
   *
   * @return string[]
   *   One detail per error.
   */
  private function errorDetails(Response $response): array {
    $body = json_decode((string) $response->getContent(), TRUE);
    return array_column(is_array($body) ? ($body['errors'] ?? []) : [], 'detail');
  }

  /**
   * With a record, the 51st publish of the tenant admin is refused.
   *
   * The report stays unpublished and held. Unpublishing an older report
   * frees a slot, and the same publish goes through.
   */
  public function testFreeLimitBindsTenantAdminInRecordWorkspace(): void {
    $this->assertTrue($this->recordTenantAdmin->hasPermission('bypass mas validation'));
    $held = $this->heldReportAtLimit(TRUE);
    $this->setCurrentUser($this->recordTenantAdmin);

    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringContainsString('50', (string) $response->getContent());
    $held = $this->reload($held);
    $this->assertFalse($held->isPublished(), 'The report stays unpublished.');
    $this->assertTrue($this->review()->isHeld($held), 'The report stays held.');

    // Unpublish one older report: the publish goes through.
    $older = $this->publishedReportIds(TRUE)[0];
    $this->assertSame(200, $this->patchStatus(Node::load($older), FALSE)->getStatusCode());
    $response = $this->patchStatus($held, TRUE);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $held = $this->reload($held);
    $this->assertTrue($held->isPublished());
    $this->assertFalse($this->review()->isHeld($held), 'Publishing released the hold.');
  }

  /**
   * Paid tier: a record changes nothing for staff at the limit.
   *
   * Starter with and without a record: the tenant admin passes the limit in
   * both, as in any paid workspace today.
   */
  public function testPaidTierWithRecordBehavesAsWithout(): void {
    $results = [];
    foreach ([FALSE => $this->tenantAdmin, TRUE => $this->recordTenantAdmin] as $record => $admin) {
      $workspace = Group::load(($record ? $this->recordWorkspace : $this->workspace)->id());
      $workspace->set('field_tier', 'starter')->save();
      $held = $this->heldReportAtLimit((bool) $record);
      $this->setCurrentUser(User::load($admin->id()));
      $response = $this->patchStatus($held, TRUE);
      $results[$record ? 'record' : 'no record'] = [$response->getStatusCode(), $this->reload($held)->isPublished()];
    }
    $this->assertSame([200, TRUE], $results['no record'], 'Starter without a record: staff pass, as today.');
    $this->assertSame($results['no record'], $results['record'], 'Starter with a record: the same.');
  }

  /**
   * A platform administrator passes the limit with and without a record.
   */
  public function testPlatformAdministratorPassesTheLimit(): void {
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])
      ->grantPermission('access content')
      ->grantPermission('administer nodes')
      ->grantPermission('bypass node access')
      ->save();
    $platformAdmin = User::create([
      'name' => 'platform',
      'mail' => 'platform@example.test',
      'status' => 1,
      'roles' => ['administrator'],
    ]);
    $platformAdmin->save();
    foreach ([FALSE, TRUE] as $record) {
      $held = $this->heldReportAtLimit($record);
      $this->setCurrentUser(User::load($platformAdmin->id()));
      $response = $this->patchStatus($held, TRUE);
      $this->assertSame(200, $response->getStatusCode(), ($record ? 'record: ' : 'no record: ') . $response->getContent());
      $this->assertTrue($this->reload($held)->isPublished());
    }
  }

  /**
   * Fills a workspace to 50 published reports and adds one held report.
   */
  private function heldReportAtLimit(bool $record = FALSE): NodeInterface {
    $this->setCurrentUser(new AnonymousUserSession());
    for ($i = 0; $i < 50; $i++) {
      Node::create($this->reportValues('Published ' . $i, $record) + ['status' => 1])->save();
    }
    $workspace = $record ? $this->recordWorkspace : $this->workspace;
    $this->assertSame(50, $this->container->get('markaspot_fastmap.tier_config')->countRequests((int) $workspace->id(), 'published'));
    $this->setFlag($workspace, TRUE);
    $held = $this->createAsAnonymous($record);
    $this->assertFalse($held->isPublished());
    $this->assertTrue($this->review()->isHeld($held));
    return $held;
  }

  /**
   * The published report ids of a workspace, oldest first.
   *
   * @return int[]
   *   Node ids.
   */
  private function publishedReportIds(bool $record): array {
    $workspace = $record ? $this->recordWorkspace : $this->workspace;
    return array_values(array_map('intval', $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $workspace->id())
      ->sort('nid')
      ->execute()));
  }

  /**
   * Writes the record of an opened workspace (test helper, no product code).
   */
  private function writeOpenRecord(GroupInterface $workspace): void {
    // Marked as syncing: the record rule lets only the lifecycle service and
    // syncing saves change the record of an existing workspace.
    $workspace->setSyncing(TRUE);
    $workspace->set(WorkspaceLifecycle::FIELD, self::OPEN_RECORD)->save();
    $workspace->setSyncing(FALSE);
    $this->assertTrue($this->container->get('markaspot_fastmap.workspace_lifecycle')->isOpen($workspace));
  }

  /**
   * Saves a citizen report as anonymous on the citizen form route.
   */
  private function createAsAnonymous(bool $record = FALSE): NodeInterface {
    $this->setCurrentUser(new AnonymousUserSession());
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $this->container->get('request_stack')->push($request);
    try {
      $node = Node::create($this->reportValues('Citizen report', $record) + ['status' => 1]);
      $node->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    return $this->reload($node);
  }

  /**
   * Values of a report in a workspace.
   */
  private function reportValues(string $title, bool $record = FALSE): array {
    return [
      'type' => 'service_request',
      'title' => $title,
      'uid' => 0,
      'body' => ['value' => 'Something is broken.', 'format' => 'plain_text'],
      'field_category' => ($record ? $this->recordCategory : $this->category)->id(),
      'field_status' => ($record ? $this->recordStatus : $this->status)->id(),
      'field_jurisdiction' => ($record ? $this->recordWorkspace : $this->workspace)->id(),
    ];
  }

  /**
   * Sends the dashboard's status PATCH through JSON:API.
   */
  private function patchStatus(NodeInterface $node, bool $published, ?int $jurisdictionId = NULL): Response {
    $uri = '/jsonapi/node/service_request/' . $node->uuid() . ($jurisdictionId !== NULL ? '?jurisdiction_id=' . $jurisdictionId : '');
    $request = Request::create($uri, 'PATCH', [], [], [], [], json_encode(['data' => [
      'type' => 'node--service_request',
      'id' => $node->uuid(),
      'attributes' => ['status' => $published],
    ]]));
    $request->headers->set('Accept', 'application/vnd.api+json');
    $request->headers->set('Content-Type', 'application/vnd.api+json');
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * The review-before-publish service.
   */
  private function review(): ReviewBeforePublish {
    return $this->container->get('markaspot_nuxt.review_before_publish');
  }

  /**
   * Stores the flag in a jurisdiction's field_nuxt_config.
   */
  private function setFlag(GroupInterface $jurisdiction, bool $value): void {
    $jurisdiction = Group::load($jurisdiction->id());
    $this->assertInstanceOf(GroupInterface::class, $jurisdiction);
    $config = json_decode((string) $jurisdiction->get('field_nuxt_config')->value, TRUE) ?: [];
    $config['features'][ReviewBeforePublish::FLAG] = $value;
    $jurisdiction->set('field_nuxt_config', json_encode($config))->save();
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
      'jur-member' => ['view group'],
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
