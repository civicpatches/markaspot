<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\services_api_key_auth\Entity\ApiKey;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests plain API-key GeoReport reads over HTTP under real group grants.
 *
 * Secures that list and single reads with a real API key return only
 * published requests while the reading account's group grants also cover
 * unpublished ones: its member role carries the draft permission, group
 * relationships and markaspot_group node grants are real, and it holds
 * neither the Open311 advanced permission nor bypass node access.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class GeoreportKeyReadHttpKernelTest extends KernelTestBase {

  /**
   * The API key value sent in the request header.
   */
  private const KEY = 'kernel-test-key-0001';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'field_permissions', 'file', 'filter', 'text',
    'node', 'taxonomy', 'serialization', 'rest', 'token',
    'entity', 'flexible_permissions', 'group', 'gnode',
    'services_api_key_auth', 'markaspot_validation', 'markaspot_request_id',
    'markaspot_group', 'markaspot_nuxt', 'markaspot_open311',
  ];

  /**
   * The account the key maps to.
   */
  private UserInterface $keyAccount;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'services_api_key_auth']);
    $settings = Yaml::decode((string) file_get_contents(
      $this->container->get('extension.list.module')->getPath('markaspot_open311') . '/config/install/markaspot_open311.settings.yml'
    ));
    unset($settings['_core']);
    $this->config('markaspot_open311.settings')->setData($settings)->save();
    foreach (['georeport_request_index_resource', 'georeport_request_resource'] as $resource) {
      RestResourceConfig::create([
        'id' => $resource,
        'plugin_id' => $resource,
        'granularity' => 'method',
        'configuration' => [
          'GET' => [
            'supported_formats' => ['json'],
            'supported_auth' => ['api_key_auth', 'cookie'],
          ],
        ],
      ])->save();
    }

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    $this->createRequestFields();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $plugin) {
      $type = $relationshipTypes->createFromPlugin(GroupType::load('jur'), $plugin);
      if (!$relationshipTypes->load($type->id())) {
        $type->save();
      }
    }
    // A simple member role that wrongly carries the draft permission.
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => [
        'view group',
        'view group_node:service_request entity',
        'view own unpublished group_node:service_request entity',
        'view unpublished group_node:service_request entity',
      ],
    ])->save();
    Role::load('authenticated')
      ->grantPermission('access content')
      ->grantPermission('restful get georeport_request_index_resource')
      ->grantPermission('restful get georeport_request_resource')
      ->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    $this->keyAccount = User::create(['name' => 'api_user', 'status' => 1]);
    $this->keyAccount->save();
    ApiKey::create([
      'id' => 'frontend',
      'label' => 'Frontend',
      'key' => self::KEY,
      'user_uuid' => $this->keyAccount->uuid(),
    ])->save();

    $jurisdiction = Group::create(['type' => 'jur', 'label' => 'Jurisdiction']);
    $jurisdiction->save();
    $jurisdiction->addMember($this->keyAccount, ['group_roles' => ['jur-member']]);
    // No category: organisation routing on save is not under test here.
    $this->createRequest($jurisdiction, TRUE, 'REQ-PUB-1');
    $this->createRequest($jurisdiction, FALSE, 'REQ-DRAFT-1');

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Positive control: the group grants apply to the reading account.
   *
   * Without it the key read assertions below could pass vacuously.
   */
  public function testSessionReadSeesDraft(): void {
    $this->assertFalse($this->keyAccount->hasPermission('bypass node access'));
    $this->assertFalse($this->keyAccount->hasPermission('access open311 advanced properties'));
    $this->assertArrayHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->keyAccount, 'view'));
    $this->container->get('current_user')->setAccount($this->keyAccount);

    $this->assertSame(['REQ-DRAFT-1', 'REQ-PUB-1'], $this->requestIds($this->get('/georeport/v2/requests.json')));
  }

  /**
   * A key read of the list returns only the published request.
   */
  public function testKeyReadListReturnsPublishedOnly(): void {
    $response = $this->get('/georeport/v2/requests.json', self::KEY);

    $this->assertSame(['REQ-PUB-1'], $this->requestIds($response));
  }

  /**
   * A key read of a single draft answers like an unknown request ID.
   */
  public function testKeyReadSingleRequestReturnsPublishedOnly(): void {
    $draft = $this->get('/georeport/v2/requests/REQ-DRAFT-1.json', self::KEY);
    $missing = $this->get('/georeport/v2/requests/REQ-NONE-1.json', self::KEY);

    // Current behaviour for an unknown ID: 200 with an empty list. The draft
    // must not be distinguishable from it.
    $this->assertSame(200, $missing->getStatusCode(), (string) $missing->getContent());
    $this->assertSame([], json_decode((string) $missing->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertSame(200, $draft->getStatusCode(), (string) $draft->getContent());
    $this->assertSame([], json_decode((string) $draft->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertSame((string) $missing->getContent(), (string) $draft->getContent());
    $this->assertSame($missing->headers->get('Content-Type'), $draft->headers->get('Content-Type'));

    $published = $this->get('/georeport/v2/requests/REQ-PUB-1.json', self::KEY);
    $this->assertSame(['REQ-PUB-1'], $this->requestIds($published));
  }

  /**
   * Sends a GET request through the HTTP kernel.
   */
  private function get(string $path, ?string $key = NULL): Response {
    if ($key !== NULL) {
      // The key alone must authenticate; no session account carries over.
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    }
    $request = Request::create($path);
    $request->headers->set('Accept', 'application/json');
    if ($key !== NULL) {
      $request->headers->set('api_key', $key);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Extracts the service request IDs of a GeoReport JSON response.
   *
   * @return string[]
   *   Sorted service request IDs.
   */
  private function requestIds(Response $response, bool $expectOk = TRUE): array {
    if ($expectOk) {
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $ids = [];
    foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [] as $item) {
      if (is_array($item) && isset($item['service_request_id'])) {
        $ids[] = (string) $item['service_request_id'];
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * Creates the fields the request mapper and the group grants read.
   */
  private function createRequestFields(): void {
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Category'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    $fields = [
      'field_category' => ['taxonomy_term', 'service_category'],
      'field_status' => ['taxonomy_term', 'service_status'],
      'field_jurisdiction' => ['group', NULL],
      'field_organisation' => ['group', NULL],
    ];
    foreach ($fields as $name => [$target, $bundle]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $name,
        'settings' => $bundle === NULL ? [] : [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => ['target_bundles' => [$bundle => $bundle]],
        ],
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'All groups member',
    ])->save();
  }

  /**
   * Creates a service request in a jurisdiction, authored by uid 1.
   */
  private function createRequest(GroupInterface $jurisdiction, bool $published, string $requestId): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $published ? 'Published request' : 'Draft request',
      'uid' => 1,
      'status' => (int) $published,
      'request_id' => $requestId,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $node->save();
    if ($jurisdiction->getRelationshipsByEntity($node, 'group_node:service_request') === []) {
      $jurisdiction->addRelationship($node, 'group_node:service_request');
    }
  }

}
