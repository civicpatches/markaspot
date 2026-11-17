<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Component\Serialization\Yaml;
use Drupal\node\NodeStorage;
use Drupal\services_api_key_auth\Entity\ApiKey;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Exercises intake through HTTP routing, real authentication and entity saves.
 */
#[TestGroup('markaspot_dashboard')]
#[RunTestsInSeparateProcesses]
final class StaffIntakeHttpKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'file', 'image',
    'taxonomy', 'options', 'datetime', 'language', 'color_field', 'geolocation',
    'entity', 'entity_reference_revisions', 'paragraphs', 'flexible_permissions',
    'group', 'gnode', 'field_permissions', 'serialization', 'jsonapi',
    'jsonapi_extras', 'markaspot_validation', 'markaspot_group', 'markaspot_nuxt',
    'markaspot_open311', 'markaspot_mail', 'markaspot_dashboard',
    'services_api_key_auth', 'rest', 'markaspot_passwordless',
  ];

  /**
   * The authorized root workspace.
   */
  private Group $workspaceRoot;
  /**
   * A different root workspace.
   */
  private Group $foreign;
  /**
   * A child of the authorized workspace.
   */
  private Group $child;
  /**
   * The staff account.
   */
  private User $staff;
  /**
   * The real cookie provider reads this server-side session.
   */
  private Session $session;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // This module-only kernel has no installed or uninstalled profile.
    // Keep its enabled profile modules discoverable for permission providers.
    $container->setParameter('install_profile', NULL);
    $container->register('test.jsonapi_resource_cache', NullBackend::class)->setArguments(['jsonapi_resource_types']);
    $container->getDefinition('jsonapi.resource_type.repository')->replaceArgument(3, new Reference('test.jsonapi_resource_cache'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user',
      'node',
      'file',
      'taxonomy_term',
      'paragraph',
      'group',
      'group_relationship',
      'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('markaspot_dashboard', ['markaspot_staff_intake_ticket']);
    $this->installConfig(['system',
      'user',
      'field',
      'filter',
      'node',
      'group',
      'jsonapi',
      'jsonapi_extras',
      'services_api_key_auth']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->config('jsonapi_extras.settings')->set('default_disabled', FALSE)->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->field('user', 'user', 'field_all_groups_member', 'boolean');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $this->field('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->field('node', 'service_request', 'field_gdpr', 'boolean');
    GroupType::create(['id' => 'jur', 'label' => 'Workspace', 'creator_membership' => FALSE])->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationships->load('jur-group_membership')) {
      $relationships->createFromPlugin(GroupType::load('jur'), 'group_membership')->save();
    }
    if (!$relationships->load('jur-group_node-service_request')) {
      $relationships->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    }
    if (!GroupRole::load('jur-member')) {
      GroupRole::create(['id' => 'jur-member',
        'label' => 'Member',
        'group_type' => 'jur',
        'scope' => 'individual'])->save();
    }
    GroupRole::load('jur-member')->grantPermission('view group')->save();
    $this->field('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->field('group', 'jur', 'field_nuxt_config', 'string_long');
    $this->workspaceRoot = $this->workspace('Workspace A');
    $this->foreign = $this->workspace('Workspace B');
    $this->child = $this->workspace('Child A', $this->workspaceRoot);
    Role::load('authenticated')->grantPermission('create service_request content')->grantPermission('access content')->save();
    Role::create(['id' => 'staff', 'label' => 'Staff', 'permissions' => ['administer nodes']])->save();
    $this->staff = $this->account('staff', ['staff']);
    $this->workspaceRoot->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $this->session = $this->newSession($this->staff);
    $this->container->get('router.builder')->rebuild();
    $this->container->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
      if ($event->getThrowable() instanceof EntityStorageException && $event->getThrowable()->getMessage() === 'Test rollback after insert.') {
        $event->setResponse(new JsonResponse(['error' => 'Test rollback'], 500));
      }
    }, 200);
  }

  /**
   * Staff Create And Reuse.
   */
  public function testStaffCreateAndReuse(): void {
    $ticket = $this->ticket();
    $this->assertTrue($this->workspaceRoot->access('view', $this->staff));
    $response = $this->create($ticket);
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE)['data'];
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $data['id']);
    $this->assertFalse((bool) $node->get('field_gdpr')->value);
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
  }

  /**
   * Refused Issuance And Csrf.
   */
  public function testRefusedIssuanceAndCsrf(): void {
    $path = '/api/dashboard/staff-intake-ticket';
    $this->assertSame(403, $this->http($path, ['workspace_id' => (int) $this->workspaceRoot->id()], csrf: FALSE)->getStatusCode());
    $wrong = $this->http($path, ['workspace_id' => (int) $this->workspaceRoot->id()], headers: ['X-CSRF-Token' => 'wrong']);
    $this->assertSame(403, $wrong->getStatusCode(), $wrong->getContent());
    foreach ([$this->foreign, $this->child] as $group) {
      $this->assertSame(403, $this->http($path, ['workspace_id' => (int) $group->id()])->getStatusCode());
    }
    $this->workspaceRoot->removeMember($this->staff);
    $this->assertSame(403, $this->http($path, ['workspace_id' => (int) $this->workspaceRoot->id()])->getStatusCode());
    $this->staff = $this->account('citizen');
    $this->session = $this->newSession($this->staff);
    $this->workspaceRoot->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $this->assertSame(403, $this->http($path, ['workspace_id' => (int) $this->workspaceRoot->id()])->getStatusCode());
    $this->session = $this->newSession(User::load(0));
    $this->assertSame(403, $this->http($path, ['workspace_id' => (int) $this->workspaceRoot->id()])->getStatusCode());
  }

  /**
   * Targets Expiration Session And Revocation.
   */
  public function testTargetsExpirationSessionAndRevocation(): void {
    $ticket = $this->ticket();
    foreach ([[$this->workspaceRoot, $this->foreign], [$this->foreign]] as $targets) {
      $this->assertSame(422, $this->create($ticket, $targets)->getStatusCode());
    }
    $oldSession = $this->session;
    $this->session = $this->newSession($this->staff);
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
    $this->session = $oldSession;
    $this->workspaceRoot->removeMember($this->staff);
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
    $this->workspaceRoot->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $this->container->get('database')->update('markaspot_staff_intake_ticket')->fields(['created' => time() - 601])->execute();
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
  }

  /**
   * Empty Targets Contract.
   */
  public function testEmptyTargetsContract(): void {
    $this->useObservingStorage();
    $ticket = $this->ticket();
    $response = $this->create($ticket, []);
    $this->assertSame(0, ObservingIntakeNodeStorage::$emptyTargetContextEntries, 'Empty targets have no intake context before presave.');
    $used = $this->container->get('database')->select('markaspot_staff_intake_ticket', 't')->fields('t', ['used'])->execute()->fetchField();
    $this->assertSame('0', (string) $used, 'Empty targets never consume the ticket.');
    $withoutTicket = $this->create('', []);
    $this->assertSame($withoutTicket->getStatusCode(), $response->getStatusCode(), 'Empty targets preserve the status of the same request without a ticket.');
  }

  /**
   * Validation Failure Does Not Consume.
   */
  public function testValidationFailureDoesNotConsume(): void {
    $ticket = $this->ticket();
    $this->assertSame(422, $this->create($ticket, title: '')->getStatusCode());
    $this->assertSame(201, $this->create($ticket)->getStatusCode());
  }

  /**
   * Api Key With Session Never Exempts.
   */
  public function testApiKeyWithSessionNeverExempts(): void {
    $ticket = $this->ticket();
    ApiKey::create(['id' => 'intake-test',
      'label' => 'Intake test',
      'key' => 'test-only-key',
      'user_uuid' => $this->staff->uuid()])->save();
    $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()], ['api_key' => 'test-only-key']);
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->create($ticket, extraHeaders: ['api_key' => 'test-only-key']);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame(201, $this->create($ticket)->getStatusCode(), 'Mixed authentication did not consume.');
  }

  /**
   * Foreign Uid And Revoked Capability.
   */
  public function testForeignUidAndRevokedCapability(): void {
    $ticket = $this->ticket();
    $original = $this->session;
    $other = $this->account('other-staff', ['staff']);
    $this->workspaceRoot->addMember($other, ['group_roles' => ['jur-member']]);
    $this->session = $this->newSession($other);
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
    $this->session = $original;
    $this->staff->removeRole('staff')->save();
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
  }

  /**
   * Global And Root Elevated Issuance.
   */
  public function testGlobalAndRootElevatedIssuance(): void {
    $this->staff->removeRole('staff')->save();
    GroupRole::create(['id' => 'jur-elevated-test',
      'label' => 'Elevated',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view unpublished group_node:service_request entity']])->save();
    $this->workspaceRoot->getMember($this->staff)->getGroupRelationship()->set('group_roles', ['jur-elevated-test'])->save();
    $this->assertNotSame('', $this->ticket());
    foreach (['administrator', 'editorial_board'] as $rid) {
      if (!Role::load($rid)) {
        Role::create(['id' => $rid, 'label' => $rid])->save();
      }
      $user = $this->account($rid, [$rid]);
      $this->session = $this->newSession($user);
      $this->assertNotSame('', $this->ticket());
    }
    $this->session = $this->newSession(User::load(1));
    $this->assertNotSame('', $this->ticket());
    Role::create(['id' => 'bypass-test', 'label' => 'Bypass', 'permissions' => ['bypass node access']])->save();
    $this->session = $this->newSession($this->account('bypass', ['bypass-test']));
    $this->assertNotSame('', $this->ticket());
  }

  /**
   * Child Membership Does Not Authorize Root.
   */
  public function testChildMembershipDoesNotAuthorizeRoot(): void {
    $this->workspaceRoot->removeMember($this->staff);
    $this->child->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()]);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Membership of an organisation alone does not authorize workspace intake.
   */
  public function testOrganisationOnlyMembershipDoesNotAuthorize(): void {
    $this->useModerator();
    $this->workspaceRoot->removeMember($this->staff);
    $this->joinOrganisation();
    $membership = GroupMembership::loadSingle($this->workspaceRoot, $this->staff);
    $this->assertNotFalse($membership, 'The real organisation hook creates a root membership.');
    $this->assertSame(['jur-org_member'], array_keys($membership->getRoles(FALSE)));
    $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()]);
    $this->assertSame(403, $response->getStatusCode());
    foreach (['', str_repeat('a', 64)] as $ticket) {
      $this->assertSame(422, $this->create($ticket)->getStatusCode());
    }
  }

  /**
   * A global moderator with an explicit root membership can create.
   */
  public function testModeratorWithExplicitRootMembershipCanCreate(): void {
    $this->useModerator();
    $membership = GroupMembership::loadSingle($this->workspaceRoot, $this->staff);
    $this->assertSame(['jur-member'], array_keys($membership->getRoles(FALSE)));
    $response = $this->create($this->ticket());
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE)['data'];
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $data['id']);
    $this->assertFalse((bool) $node->get('field_gdpr')->value);
  }

  /**
   * Adding an organisation preserves explicit root membership authority.
   */
  public function testExplicitRootAndOrganisationMembershipCanCreate(): void {
    $this->useModerator();
    $this->joinOrganisation();
    $roles = array_keys(GroupMembership::loadSingle($this->workspaceRoot, $this->staff)->getRoles(FALSE));
    $this->assertEqualsCanonicalizing(['jur-member', 'jur-org_member'], $roles);
    $response = $this->create($this->ticket());
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
  }

  /**
   * Shipped insider and outsider mappings do not authorize a non-member.
   */
  public function testShippedGroupRolesRefuseNonMemberModerator(): void {
    $this->useModerator();
    $this->installOrganisationType();
    $storage = $this->container->get('entity_type.manager')->getStorage('group_role');
    $modulePath = $this->container->get('extension.list.module')->getPath('markaspot_group');
    foreach (glob($modulePath . '/config/install/group.role.*.yml') as $path) {
      $data = Yaml::decode(file_get_contents($path));
      $role = $storage->load($data['id']);
      if ($role) {
        foreach ($data as $key => $value) {
          $role->set($key, $value);
        }
      }
      else {
        $role = $storage->create($data);
      }
      $role->save();
    }
    $this->assertSame('outsider', GroupRole::load('jur-outsider')->getScope());
    $this->assertSame('insider', GroupRole::load('org-insider')->getScope());
    $this->assertSame('moderator', GroupRole::load('org-insider')->get('global_role'));
    $this->workspaceRoot->removeMember($this->staff);
    $this->assertFalse($this->workspaceRoot->getMember($this->staff));
    $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()]);
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
  }

  /**
   * Store errors preserve consent enforcement without exposing the ticket.
   */
  #[DataProvider('storeFailureOperations')]
  public function testStoreFailureKeepsConsentRequired(string $operation): void {
    $ticket = $this->ticket();
    $database = $this->container->get('database');
    $connection = $this->createMock(Connection::class);
    if ($operation === 'select') {
      $connection->expects($this->once())->method('select')->willThrowException(new \Error($ticket . "\nStore unavailable"));
    }
    else {
      $connection->method('select')->willReturnCallback($database->select(...));
      $connection->expects($this->once())->method('update')->willThrowException(new \RuntimeException($ticket . "\nStore unavailable"));
    }
    $store = $this->container->get('markaspot_dashboard.staff_intake_store');
    (new \ReflectionProperty($store, 'connection'))->setValue($store, $connection);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with(
      RfcLogLevel::WARNING,
      'Staff intake ticket storage failed; consent remains required.',
      $this->callback(static fn(array $context): bool => $context['channel'] === 'markaspot_dashboard'
        && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), $ticket)),
    );
    $this->container->get('logger.channel.markaspot_dashboard')->addLogger($logger);
    $response = $this->create($ticket);
    $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    $used = $database->select('markaspot_staff_intake_ticket', 't')->fields('t', ['used'])->execute()->fetchField();
    $this->assertSame('0', (string) $used);
  }

  /**
   * Lists the database operations used to find and consume a ticket.
   */
  public static function storeFailureOperations(): array {
    return ['find' => ['select'], 'consume' => ['update']];
  }

  /**
   * Invalid Reference Does Not Consume.
   */
  public function testInvalidReferenceDoesNotConsume(): void {
    $ticket = $this->ticket();
    $invalid = Group::create(['type' => 'jur', 'uuid' => '12345678-1234-1234-1234-123456789012']);
    $response = $this->create($ticket, [$invalid]);
    $this->assertSame(404, $response->getStatusCode());
    $used = $this->container->get('database')->select('markaspot_staff_intake_ticket', 't')->fields('t', ['used'])->execute()->fetchField();
    $this->assertSame('0', (string) $used, 'An unresolved reference never consumes the ticket.');
    $this->assertSame(201, $this->create($ticket)->getStatusCode());
  }

  /**
   * Save Rollback Cannot Revive Consumed Ticket.
   */
  public function testSaveRollbackCannotReviveConsumedTicket(): void {
    $this->useObservingStorage();
    $ticket = $this->ticket();
    $this->assertSame(500, $this->create($ticket, title: 'rollback')->getStatusCode());
    $this->assertSame(422, $this->create($ticket)->getStatusCode());
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());
    $context = $this->container->get('markaspot_dashboard.staff_intake_context');
    $this->assertFalse($context->take(ObservingIntakeNodeStorage::$failedRequest, ObservingIntakeNodeStorage::$failedNode));
    ObservingIntakeNodeStorage::$failedNode->set('nid', NULL)->set('vid', NULL)->enforceIsNew();
    $request = ObservingIntakeNodeStorage::$failedRequest;
    $this->container->get('request_stack')->push($request);
    try {
      ObservingIntakeNodeStorage::$failedNode->setTitle('second attempt')->save();
      $this->fail('A second initial save needs a new ticket.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('Privacy notice consent', $exception->getMessage());
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Side Object Cannot Take Primary Exemption.
   */
  public function testSideObjectCannotTakePrimaryExemption(): void {
    $this->useObservingStorage();
    $response = $this->create($this->ticket(), title: 'side first');
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $this->assertTrue(ObservingIntakeNodeStorage::$sideRejected);
  }

  /**
   * Overlapping foreign boundaries cannot move intake outside its root.
   */
  public function testMostSpecificStaysInsideTicketRoot(): void {
    $this->field('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->field('group', 'jur', 'field_boundary', 'string_long');
    $foreignChild = $this->workspace('Foreign child', $this->foreign);
    $boundary = json_encode([
      'type' => 'Polygon',
      'coordinates' => [[[0, 0], [2, 0], [2, 2], [0, 2], [0, 0]]],
    ]);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $this->child = Group::load($this->child->id());
    $foreignChild = Group::load($foreignChild->id());
    $this->child->set('field_boundary', $boundary)->save();
    $foreignChild->set('field_boundary', $boundary)->save();
    $response = $this->create($this->ticket(), extraAttributes: ['field_geolocation' => ['lat' => 1, 'lng' => 1]]);
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE)['data'];
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $data['id']);
    $this->assertSame((int) $this->child->id(), (int) $node->get('field_jurisdiction')->target_id);
  }

  /**
   * GeoReport never redeems the header even with valid staff credentials.
   */
  public function testGeoreportHeaderCannotExempt(): void {
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Category'])->save();
    $term = Term::create(['vid' => 'service_category', 'name' => 'Category']);
    $term->save();
    $this->field('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->field('paragraph', 'status', 'field_status_note', 'text_long');
    $this->field('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $processor = $this->createMock(GeoreportProcessorService::class);
    $processor->method('resolveJurisdictionId')->willReturn((int) $this->workspaceRoot->id());
    $processor->method('prepareNodeProperties')->willReturn([
      'type' => 'service_request', 'title' => 'GeoReport intake',
      'field_jurisdiction' => $this->workspaceRoot->id(),
      'field_gdpr' => FALSE,
    ]);
    $processor->method('createStatusNoteParagraph')->willReturnCallback(static function (): Paragraph {
      $paragraph = Paragraph::create([
        'type' => 'status',
        'field_status_note' => ['value' => 'Created', 'format' => 'plain_text'],
      ]);
      $paragraph->save();
      return $paragraph;
    });
    $this->container->set('markaspot_open311.processor', $processor);
    RestResourceConfig::create([
      'id' => 'georeport_request_index_resource',
      'plugin_id' => 'georeport_request_index_resource',
      'granularity' => 'method',
      'configuration' => [
        'POST' => [
          'supported_formats' => ['json'],
          'supported_auth' => ['api_key_auth'],
        ],
      ],
    ])->save();
    Role::load('staff')->grantPermission('restful post georeport_request_index_resource')->save();
    ApiKey::create([
      'id' => 'georeport-test',
      'label' => 'GeoReport test',
      'key' => 'georeport-test-key',
      'user_uuid' => $this->staff->uuid(),
    ])->save();
    $this->container->get('router.builder')->rebuild();
    $ticket = $this->ticket();
    $response = $this->http('/georeport/v2/requests.json', [
      'jurisdiction_id' => (int) $this->workspaceRoot->id(),
      'service_code' => (string) $term->id(),
    ], ['api_key' => 'georeport-test-key', 'X-Markaspot-Staff-Intake' => $ticket]);
    $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    $this->assertSame(201, $this->create($ticket)->getStatusCode(), 'GeoReport did not consume the ticket.');
  }

  /**
   * A citizen's successful code login grants no staff intake authority.
   */
  public function testCodeLoginCitizenIsRefused(): void {
    $this->installSchema('markaspot_passwordless', ['markaspot_passwordless_codes']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['markaspot_passwordless']);
    $this->config('markaspot_nuxt.settings')->set('platform_features.passwordless', TRUE)->save();
    $citizen = $this->account('code-citizen');
    $citizen->setEmail('code-citizen@example.test')->save();
    $this->session = $this->newSession(User::load(0));
    $this->container->get('database')->insert('markaspot_passwordless_codes')->fields([
      'email' => $citizen->getEmail(), 'code' => password_hash('123456', PASSWORD_DEFAULT),
      'created' => time(), 'expires' => time() + 600, 'jurisdiction_id' => (int) $this->workspaceRoot->id(),
    ])->execute();
    $response = $this->http('/api/auth/verify-code', [
      'email' => $citizen->getEmail(), 'code' => '123456', 'jurisdiction_id' => (int) $this->workspaceRoot->id(),
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $payload = json_decode($response->getContent(), TRUE);
    $this->assertTrue($payload['success']);
    $this->assertSame((int) $citizen->id(), (int) $payload['user']['uid']);
    $this->session = $this->container->get('session');
    $this->assertSame((int) $citizen->id(), (int) $this->session->get('uid'), 'The ticket request uses the session established by actual code login.');
    $this->assertSame(403, $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()])->getStatusCode());
  }

  /**
   * Invalid workspace bodies never issue a ticket.
   */
  public function testInvalidWorkspaceBodies(): void {
    foreach ([0, -1, '1', NULL, [], 999999] as $workspace) {
      $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => $workspace]);
      $this->assertContains($response->getStatusCode(), [400, 403]);
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('markaspot_staff_intake_ticket', 't')->countQuery()->execute()->fetchField());
  }

  /**
   * Installing without Vision works; schema update and expiry cleanup repeat.
   */
  public function testStorageLifecycleWithoutVision(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('markaspot_vision'));
    $this->container->get('module_handler')->loadInclude('markaspot_dashboard', 'install');
    markaspot_dashboard_update_11903();
    markaspot_dashboard_update_11903();
    $this->ticket();
    $db = $this->container->get('database');
    $db->update('markaspot_staff_intake_ticket')->fields(['created' => time() - 601])->execute();
    markaspot_dashboard_cron();
    markaspot_dashboard_cron();
    $this->assertSame(0, (int) $db->select('markaspot_staff_intake_ticket', 't')->countQuery()->execute()->fetchField());
  }

  /**
   * Exactly one parallel authenticated HTTP create can redeem a ticket.
   */
  public function testParallelRedemption(): void {
    $this->assertTrue(function_exists('pcntl_fork'), 'Parallel HTTP proof requires pcntl.');
    $ticket = $this->ticket();
    $directory = sys_get_temp_dir() . '/intake-race-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $children = [];
    for ($i = 0; $i < 2; $i++) {
      $pid = pcntl_fork();
      if ($pid === 0) {
        // Never share an inherited database socket between forked workers.
        $db = $this->container->get('database');
        $class = get_class($db);
        $options = $db->getConnectionOptions();
        $connection = new $class($class::open($options), $options);
        $socket = new \ReflectionProperty(Connection::class, 'connection');
        $socket->setValue($db, $socket->getValue($connection));
        $store = $this->container->get('markaspot_dashboard.staff_intake_store');
        $storedConnection = new \ReflectionProperty($store, 'connection');
        $ticketDb = $storedConnection->getValue($store);
        if ($ticketDb !== NULL) {
          $ticketOptions = $ticketDb->getConnectionOptions();
          $ticketConnection = new $class($class::open($ticketOptions), $ticketOptions);
          $socket->setValue($ticketDb, $socket->getValue($ticketConnection));
        }
        file_put_contents($directory . '/' . $i . '.ready', 'ready');
        while (!file_exists($directory . '/go')) {
          usleep(1000);
        }
        try {
          $status = $this->create($ticket)->getStatusCode();
          file_put_contents($directory . '/' . $i . '.result', (string) $status);
        }
        catch (\Throwable $exception) {
          file_put_contents($directory . '/' . $i . '.result', get_class($exception));
        }
        // Avoid inherited PHPUnit shutdown handlers dropping fixture tables.
        posix_kill(getmypid(), SIGKILL);
      }
      $this->assertGreaterThan(0, $pid);
      $children[] = $pid;
    }
    $deadline = microtime(TRUE) + 20;
    while ((!file_exists($directory . '/0.ready') || !file_exists($directory . '/1.ready')) && microtime(TRUE) < $deadline) {
      usleep(1000);
    }
    file_put_contents($directory . '/go', 'go');
    foreach ($children as $pid) {
      pcntl_waitpid($pid, $status);
    }
    // A fork closes copies of the parent's socket. Reconnect for teardown.
    $db = $this->container->get('database');
    $class = get_class($db);
    $options = $db->getConnectionOptions();
    $connection = new $class($class::open($options), $options);
    $socket = new \ReflectionProperty(Connection::class, 'connection');
    $socket->setValue($db, $socket->getValue($connection));
    $statuses = [(int) file_get_contents($directory . '/0.result'), (int) file_get_contents($directory . '/1.result')];
    sort($statuses);
    foreach (glob($directory . '/*') as $file) {
      unlink($file);
    }
    rmdir($directory);
    $this->assertSame([201, 422], $statuses);
  }

  /**
   * Installs a test-only storage handler without changing runtime code.
   */
  private function useObservingStorage(): void {
    $manager = $this->container->get('entity_type.manager');
    $definition = $manager->getDefinition('node');
    $storage = $manager->createHandlerInstance(ObservingIntakeNodeStorage::class, $definition);
    $property = new \ReflectionProperty($manager, 'handlers');
    $handlers = $property->getValue($manager);
    $handlers['storage']['node'] = $storage;
    $property->setValue($manager, $handlers);
  }

  /**
   * Uses the shipped global moderator permissions, without the test role.
   */
  private function useModerator(): void {
    $data = Yaml::decode(file_get_contents(dirname(__DIR__, 5) . '/config/optional/user.role.moderator.yml'));
    Role::create($data)->save();
    $this->workspaceRoot->removeMember($this->staff);
    $this->staff = $this->account('moderator', ['moderator']);
    $this->workspaceRoot->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $this->session = $this->newSession($this->staff);
    $this->assertTrue($this->staff->hasPermission('edit any service_request content'));
  }

  /**
   * Installs the organisation bundle and its real membership plugin.
   */
  private function installOrganisationType(): void {
    GroupType::create(['id' => 'org', 'label' => 'Organisation', 'creator_membership' => FALSE])->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationships->load('org-group_membership')) {
      $relationships->createFromPlugin(GroupType::load('org'), 'group_membership')->save();
    }
    $this->field('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
  }

  /**
   * Joins an organisation whose jurisdiction triggers the real root sync.
   */
  private function joinOrganisation(): void {
    $this->installOrganisationType();
    $modulePath = $this->container->get('extension.list.module')->getPath('markaspot_group');
    $data = Yaml::decode(file_get_contents($modulePath . '/config/install/group.role.jur-org_member.yml'));
    GroupRole::create($data)->save();
    $organisation = Group::create(['type' => 'org', 'label' => 'Department', 'status' => 1,
      'field_jurisdiction' => $this->workspaceRoot->id(),
    ]);
    $organisation->save();
    $organisation->addMember($this->staff);
  }

  /**
   * Issues a fresh ticket through the protected route.
   */
  private function ticket(): string {
    $response = $this->http('/api/dashboard/staff-intake-ticket', ['workspace_id' => (int) $this->workspaceRoot->id()]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $ticket = json_decode($response->getContent(), TRUE)['staff_intake_ticket'];
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket);
    return $ticket;
  }

  /**
   * Submits the primary JSON:API resource.
   */
  private function create(string $ticket, ?array $targets = NULL, string $title = 'Staff intake', array $extraHeaders = [], array $extraAttributes = []): Response {
    $relationships = array_map(static fn(Group $group): array => ['type' => 'group--jur',
      'id' => $group->uuid()], $targets ?? [$this->workspaceRoot]);
    return $this->http('/jsonapi/node/service_request', ['data' => [
      'type' => 'node--service_request', 'attributes' => ['title' => $title, 'field_gdpr' => FALSE] + $extraAttributes,
      'relationships' => ['field_jurisdiction' => ['data' => $relationships]],
    ]], [
      'Content-Type' => 'application/vnd.api+json',
      'Accept' => 'application/vnd.api+json',
    ] + ($ticket !== '' ? ['X-Markaspot-Staff-Intake' => $ticket] : []) + $extraHeaders);
  }

  /**
   * Runs a full HTTP-kernel request with cookie authentication.
   */
  private function http(string $path, array $body, array $headers = [], bool $csrf = TRUE): Response {
    $request = Request::create('http://localhost' . $path, 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    $this->container->get('router.request_context')->fromRequest($request);
    $this->container->get('current_route_match')->resetRouteMatch();
    $request->setRequestFormat(str_starts_with($path, '/jsonapi/') ? 'api_json' : 'json');
    $request->setSession($this->session);
    $name = $this->container->get('session_configuration')->getOptions($request)['name'];
    $request->cookies->set($name, $this->session->getId());
    $this->container->get('session_manager.metadata_bag')->setCsrfTokenSeed('test-seed');
    $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    if ($csrf) {
      $headers += ['X-CSRF-Token' => $this->container->get('csrf_token')->get('X-CSRF-Token request header')];
    }
    $request->headers->add($headers);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Creates a separate server-side session.
   */
  private function newSession(User $user): Session {
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $session->set('uid', (int) $user->id());
    return $session;
  }

  /**
   * Creates a workspace with consent required.
   */
  private function workspace(string $label, ?Group $parent = NULL): Group {
    $group = Group::create(['type' => 'jur', 'label' => $label, 'uid' => 1, 'status' => 1,
      'field_parent_jurisdiction' => $parent?->id(),
      'field_nuxt_config' => json_encode(['fields' => ['field_gdpr' => ['required' => TRUE]]]),
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates an active account.
   */
  private function account(string $name, array $roles = []): User {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Installs one fixture field.
   */
  private function field(string $entity, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entity, $name)) {
      FieldStorageConfig::create(['entity_type' => $entity,
        'field_name' => $name,
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality])->save();
    }
    if (!FieldConfig::loadByName($entity, $bundle, $name)) {
      FieldConfig::create(['entity_type' => $entity,
        'bundle' => $bundle,
        'field_name' => $name,
        'label' => $name])->save();
    }
  }

}

/**
 * Runs controlled failures inside real node transactions for the HTTP tests.
 */
final class ObservingIntakeNodeStorage extends NodeStorage {

  /**
   * The number of intake bindings before an empty-target node is saved.
   */
  public static ?int $emptyTargetContextEntries = NULL;

  /**
   * Whether the side object was rejected.
   */
  public static bool $sideRejected = FALSE;
  /**
   * The primary object from the failed save.
   */
  public static ?EntityInterface $failedNode = NULL;
  /**
   * The request whose save failed.
   */
  public static ?Request $failedRequest = NULL;

  /**
   * Runs a side save before the primary save.
   */
  public function save(EntityInterface $entity) {
    if ($entity->get('field_jurisdiction')->isEmpty()) {
      $context = \Drupal::service('markaspot_dashboard.staff_intake_context');
      $primaries = new \ReflectionProperty($context, 'primaries');
      self::$emptyTargetContextEntries = count($primaries->getValue($context));
    }
    if ($entity->label() === 'side first') {
      $side = Node::create(['type' => 'service_request',
        'title' => 'side',
        'field_jurisdiction' => $entity->get('field_jurisdiction')->getValue(),
        'field_gdpr' => FALSE]);
      try {
        $side->save();
      }
      catch (EntityStorageException $exception) {
        self::$sideRejected = str_contains($exception->getMessage(), 'Privacy notice consent');
      }
    }
    return parent::save($entity);
  }

  /**
   * Fails after inserting the primary row to exercise rollback.
   */
  protected function doSave($id, EntityInterface $entity) {
    $result = parent::doSave($id, $entity);
    if ($entity->label() === 'rollback') {
      self::$failedNode = $entity;
      self::$failedRequest = \Drupal::request();
      throw new \RuntimeException('Test rollback after insert.');
    }
    return $result;
  }

}
