<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests plain API-key reads under real group grants.
 *
 * Secures that the GeoReport base query returns only published requests for
 * a plain key read while the reading account's group grants also cover
 * unpublished ones: its member role carries the draft permission, group
 * relationships and markaspot_group node grants are real, and the reading
 * account does not bypass node access (uid 1 only sets up and authors the
 * requests).
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class GeoreportKeyReadGroupGrantsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file',
    'entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group',
  ];

  /**
   * The account the key maps to.
   */
  private UserInterface $keyAccount;

  /**
   * Published request in the jurisdiction.
   */
  private Node $published;

  /**
   * Unpublished request in the jurisdiction, authored by someone else.
   */
  private Node $draft;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'group']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
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
    foreach (['field_jurisdiction', 'field_organisation'] as $field) {
      FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'group'],
      ])->save();
      FieldConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $field,
      ])->save();
    }
    Role::load('authenticated')->grantPermission('access content')->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    $this->keyAccount = User::create(['name' => 'api_user', 'status' => 1]);
    $this->keyAccount->save();
    $jurisdiction = Group::create(['type' => 'jur', 'label' => 'Jurisdiction']);
    $jurisdiction->save();
    $jurisdiction->addMember($this->keyAccount, ['group_roles' => ['jur-member']]);
    $this->published = $this->createRequest($jurisdiction, TRUE);
    $this->draft = $this->createRequest($jurisdiction, FALSE);

    $this->container->get('current_user')->setAccount($this->keyAccount);
  }

  /**
   * Positive control: the group grants apply to the reading account.
   *
   * Without it the key read assertions below could pass vacuously.
   */
  public function testMisconfiguredRoleGrantsDraftWithoutBypass(): void {
    $this->assertFalse($this->keyAccount->hasPermission('bypass node access'));
    $this->assertFalse($this->keyAccount->hasPermission('access open311 advanced properties'));
    $this->assertTrue($this->container->get('module_handler')->hasImplementations('node_grants', 'markaspot_group'));
    $this->assertArrayHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->keyAccount, 'view'));
    $this->assertTrue($this->draft->access('view', $this->keyAccount));

    $this->assertSame($this->ids($this->published, $this->draft), $this->readNids(FALSE));
  }

  /**
   * A plain key read lists only published requests.
   */
  public function testKeyReadListReturnsPublishedOnly(): void {
    $this->assertSame($this->ids($this->published), $this->readNids(TRUE));
  }

  /**
   * A plain key read cannot fetch the draft as a single request either.
   */
  public function testKeyReadSingleRequestReturnsPublishedOnly(): void {
    $this->assertSame([], $this->readNids(TRUE, (int) $this->draft->id()));
    $this->assertSame($this->ids($this->published), $this->readNids(TRUE, (int) $this->published->id()));
  }

  /**
   * Creates a service request in a jurisdiction, authored by uid 1.
   */
  private function createRequest(GroupInterface $jurisdiction, bool $published): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $published ? 'Published request' : 'Draft request',
      'uid' => 1,
      'status' => (int) $published,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $node->save();
    if ($jurisdiction->getRelationshipsByEntity($node, 'group_node:service_request') === []) {
      $jurisdiction->addRelationship($node, 'group_node:service_request');
    }
    return $node;
  }

  /**
   * Returns sorted node IDs.
   *
   * @return int[]
   *   Node IDs in ascending order.
   */
  private function ids(Node ...$nodes): array {
    $ids = array_map(static fn(Node $node): int => (int) $node->id(), $nodes);
    sort($ids);
    return $ids;
  }

  /**
   * Runs the processor's base query as the key account.
   *
   * The single-request resource adds its ID condition to the same base
   * query, so a node ID condition stands in for it here.
   *
   * @return int[]
   *   Matching node IDs in ascending order.
   */
  private function readNids(bool $apiKeyRead, ?int $nid = NULL): array {
    $query = $this->createProcessor()->createNodeQuery([], $this->keyAccount, $apiKeyRead);
    if ($nid !== NULL) {
      $query->condition('nid', $nid);
    }
    $nids = array_map('intval', array_values($query->execute()));
    sort($nids);
    return $nids;
  }

  /**
   * Builds the processor from the container services.
   */
  private function createProcessor(): GeoreportProcessorService {
    return new GeoreportProcessorService(
      $this->container->get('config.factory'),
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
      $this->container->get('request_stack'),
      $this->container->get('entity_type.manager'),
      $this->container->get('file_url_generator'),
      $this->container->get('module_handler'),
      $this->container->get('entity_field.manager'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('token'),
      $this->container->get('language_manager'),
      $this->container->get('database'),
      $this->container->get('file_system'),
      $this->container->get('http_client'),
      $this->container->get('messenger'),
      $this->container->get('account_switcher'),
      new StatusClassifier(
        $this->container->get('config.factory'),
        $this->container->get('entity_type.manager'),
        $this->container->get('cache.default'),
      ),
      NULL,
      $this->container->get('logger.factory')->get('markaspot_open311'),
    );
  }

}
