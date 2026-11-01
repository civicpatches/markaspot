<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_id\Kernel;

use Drupal\eca\Entity\Eca;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\markaspot_request_id\Service\RequestIdGeneratorInterface;
use Drupal\eca\EventSubscriber\DynamicSubscriber;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_health\HealthCheckInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Tests silent duplicate repairs against real node and group storage tables.
 */
#[TestGroup('markaspot_request_id')]
final class DuplicateRequestIdUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language',
    'entity', 'flexible_permissions', 'group', 'gnode',
    'options', 'taxonomy', 'file', 'entity_reference_revisions', 'paragraphs',
    'field_permissions', 'markaspot_validation',
    'markaspot_request_id', 'markaspot_health',
    'eca', 'eca_content', 'modeler_api', 'request_id_repair_test',
  ];

  /**
   * Two independent root jurisdictions.
   *
   * @var \Drupal\group\Entity\Group[]
   */
  private array $jurisdictions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper', 'paragraph', 'taxonomy_term'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'field', 'group', 'eca']);
    $this->container->get('module_handler')->loadInclude('markaspot_request_id', 'install');
    $this->installSchema('markaspot_request_id', ['markaspot_request_id']);
    $this->container->get('queue')->get('request_id_repair_test_notifications')->createQueue();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')
      ->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    $this->createReferenceField('node', 'service_request', 'field_jurisdiction');
    $this->createReferenceField('node', 'page', 'field_jurisdiction');
    $this->createReferenceField('group', 'jur', 'field_parent_jurisdiction');
    foreach (['body' => 'text_long', 'field_source' => 'string'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'service_request'])->save();
    }
    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->jurisdictions = [
      Group::create(['type' => 'jur', 'label' => 'Fictional root A']),
      Group::create(['type' => 'jur', 'label' => 'Fictional root B']),
    ];
    foreach ($this->jurisdictions as $jurisdiction) {
      $jurisdiction->save();
    }
  }

  /**
   * Repairs only later members, retaining publication, fields and history.
   */
  public function testRepairAndNotifications(): void {
    $newer = $this->createRequest($this->jurisdictions[0]);
    $older = $this->createRequest($this->jurisdictions[0]);
    $older->setCreatedTime(100);
    $older->save();
    $newer->setCreatedTime(200);
    $newer->setUnpublished();
    $newer->save();
    $other = $this->createRequest($this->jurisdictions[1]);
    $child = Group::create([
      'type' => 'jur', 'label' => 'Fictional child',
      'field_parent_jurisdiction' => $this->jurisdictions[0]->id(),
    ]);
    $child->save();
    $child_request = $this->createRequest($child);
    // Keep even pre-existing relationship drift: this repair is not a sync.
    $this->jurisdictions[1]->addRelationship($newer, 'group_node:service_request');
    $this->jurisdictions[1]->addRelationship($other, 'group_node:service_request');
    $relations = $this->container->get('database')->select('group_relationship_field_data')->fields('group_relationship_field_data')->orderBy('id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $newer = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($newer->id());
    $revision = $newer->getRevisionId();
    $older_revision = $older->getRevisionId();
    $this->enableNotifications();

    // Positive control: an ordinary no-status-change save sends both ECA
    // presave and update notifications, including asynchronous queue entries.
    $other->save();
    $this->assertCount(2, $this->container->get('state')->get('system.test_mail_collector', []));
    $queue = $this->container->get('queue')->get('request_id_repair_test_notifications');
    $this->assertSame(2, $queue->numberOfItems());
    $queue->deleteQueue();
    $this->container->get('state')->set('system.test_mail_collector', []);
    $other_revision = $other->getRevisionId();

    // Exercise the actual assignment notification and revision hooks.
    $this->enableModules(['markaspot_group', 'service_request']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    $before = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($newer->id())->toArray();
    $config_storage = $this->container->get('config.storage');
    $config_before = $config_storage->readMultiple($config_storage->listAll());
    $sandbox = [];
    $message = $this->runUpdate($sandbox);
    $config_after = $config_storage->readMultiple($config_storage->listAll());
    ksort($config_before);
    ksort($config_after);
    $this->assertSame($config_before, $config_after);
    $this->assertSame('Renumbered 2 node(s) in 1 duplicate request ID group(s).', $message);
    $this->assertTrue(DynamicSubscriber::isActive());
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
    $this->assertSame(0, (int) $this->container->get('database')->select('queue')->countQuery()->execute()->fetchField());
    $scopes = $this->container->get('database')->select('markaspot_request_id', 'r')->fields('r', ['jurisdiction_id'])->distinct()->execute()->fetchCol();
    $this->assertSame([(int) $this->jurisdictions[0]->id()], array_map('intval', $scopes));

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $fresh = $storage->loadUnchanged($newer->id());
    $this->assertNotSame('42-2026', $fresh->get('request_id')->value);
    $this->assertSame('#' . $fresh->get('request_id')->value . ' ', $fresh->label());
    $this->assertFalse($fresh->isPublished());
    $this->assertNotSame($revision, $fresh->getRevisionId());
    $this->assertSame(sprintf('Renumbered: request ID 42-2026 was also used by node %d in the same jurisdiction.', $older->id()), $fresh->getRevisionLogMessage());
    $this->assertSame('42-2026', $storage->loadRevision($revision)->get('request_id')->value);
    $after = $fresh->toArray();
    foreach ([
      'request_id', 'title', 'vid', 'revision_timestamp', 'revision_uid',
      'revision_log', 'changed', 'revision_translation_affected',
    ] as $field) {
      unset($before[$field], $after[$field]);
    }
    $this->assertSame($before, $after);
    $this->assertSame($older_revision, $storage->loadUnchanged($older->id())->getRevisionId());
    $this->assertSame($other_revision, $storage->loadUnchanged($other->id())->getRevisionId());
    $this->assertNotSame('42-2026', $storage->loadUnchanged($child_request->id())->get('request_id')->value);
    $this->assertTrue($this->plugin()->run()->passed);
    $this->assertSame($relations, $this->container->get('database')->select('group_relationship_field_data')->fields('group_relationship_field_data')->orderBy('id')->execute()->fetchAll(\PDO::FETCH_ASSOC));

    $revision_count = $this->container->get('database')->select('node_revision')->countQuery()->execute()->fetchField();
    $again = [];
    $this->assertSame('Renumbered 0 node(s) in 0 duplicate request ID group(s).', $this->runUpdate($again));
    $this->assertSame($revision_count, $this->container->get('database')->select('node_revision')->countQuery()->execute()->fetchField());
    // The notification suppression must not leak to subsequent ordinary saves.
    $other->save();
    $this->assertCount(2, $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * A group larger than one batch retains the oldest (nid tie-break) member.
   */
  public function testBatchingAndIndependentRoots(): void {
    $keeper = $this->createRequest($this->jurisdictions[0]);
    for ($i = 0; $i < 51; $i++) {
      $this->createRequest($this->jurisdictions[0]);
    }
    $other = $this->createRequest($this->jurisdictions[1]);
    $this->createRequest($this->jurisdictions[1], '77-2020');
    $this->createRequest($this->jurisdictions[1], '77-2020');
    $sandbox = [];
    markaspot_request_id_update_11902($sandbox);
    $this->assertSame(50, $sandbox['renumbered']);
    $this->assertLessThan(1, $sandbox['#finished']);
    $this->assertSame('Renumbered 52 node(s) in 2 duplicate request ID group(s).', $this->runUpdate($sandbox));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('42-2026', $storage->loadUnchanged($keeper->id())->get('request_id')->value);
    $this->assertSame('42-2026', $storage->loadUnchanged($other->id())->get('request_id')->value);
    $this->assertTrue($this->plugin()->run()->passed);
  }

  /**
   * Save failures restore ECA and leave the duplicate eligible for retry.
   */
  public function testFailureAndPreviouslyDisabledEca(): void {
    $this->createRequest($this->jurisdictions[0]);
    $newer = $this->createRequest($this->jurisdictions[0]);
    $generator = $this->container->get('markaspot_request_id.generator');
    $failing = $this->createMock(RequestIdGeneratorInterface::class);
    $failing->method('generateRequestId')->willThrowException(new \RuntimeException('Synthetic failure'));
    $this->container->set('markaspot_request_id.generator', $failing);
    $sandbox = [];
    try {
      markaspot_request_id_update_11902($sandbox);
      $this->fail('A failed generator must abort the update.');
    }
    catch (EntityStorageException) {
      $this->assertTrue(DynamicSubscriber::isActive());
      $this->assertSame(0, $sandbox['renumbered']);
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $fresh = $storage->loadUnchanged($newer->id());
    $this->assertSame($newer->getRevisionId(), $fresh->getRevisionId());
    $this->assertEmpty($fresh->_markaspot_request_id_renumbering);
    $this->container->set('markaspot_request_id.generator', $generator);
    DynamicSubscriber::setActive(FALSE);
    try {
      $this->runUpdate($sandbox);
      $this->assertFalse(DynamicSubscriber::isActive());
    }
    finally {
      DynamicSubscriber::setActive(TRUE);
    }
    $this->assertTrue($this->plugin()->run()->passed);
  }

  /**
   * Runs all sandbox passes with a finite guard against progress regressions.
   */
  private function runUpdate(array &$sandbox): string {
    for ($i = 0; $i < 10; $i++) {
      $message = markaspot_request_id_update_11902($sandbox);
      if ($sandbox['#finished'] == 1) {
        return $message;
      }
    }
    $this->fail('The update did not finish.');
  }

  /**
   * Enables unconditional ECA notifications on both save lifecycle events.
   */
  private function enableNotifications(): void {
    $events = [];
    foreach (['presave', 'update'] as $event) {
      $events[$event] = [
        'plugin' => 'content_entity:' . $event,
        'configuration' => ['type' => 'node service_request'],
        'successors' => [['id' => 'notify', 'condition' => '']],
      ];
    }
    Eca::create([
      'id' => 'request_id_repair_notifications',
      'label' => 'Synthetic notifications',
      'status' => TRUE,
      'events' => $events,
      'conditions' => [],
      'gateways' => [],
      'actions' => [
        'notify' => [
          'plugin' => 'request_id_repair_test_notify',
          'configuration' => [],
          'successors' => [],
        ],
      ],
    ])->save();

  }

  /**
   * Discovers the plugin through the same manager used by mas:health.
   */
  private function plugin(): HealthCheckInterface {
    // Support isolated worktrees as well as a standard installed module path.
    $this->container->get('container.namespaces')->offsetSet('Drupal\\markaspot_health', dirname(__DIR__, 4) . '/markaspot_health/src');
    $manager = $this->container->get('plugin.manager.markaspot_health_check');
    $this->assertTrue($manager->hasDefinition('duplicate_request_id'));
    return $manager->createInstance('duplicate_request_id');
  }

  /**
   * Creates a request with an explicit legacy ID, bypassing ID generation.
   */
  private function createRequest(Group $jurisdiction, string $requestId = '42-2026', string $bundle = 'service_request'): Node {
    $node = Node::create([
      'type' => $bundle,
      'created' => 1000,
      'body' => 'Fictional report content that must be preserved.',
      'field_source' => 'staff',
      'title' => 'Fictional private report title',
      'request_id' => $requestId,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a jurisdiction reference with multiple deltas for filter coverage.
   */
  private function createReferenceField(string $entityType, string $bundle, string $fieldName): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'settings' => ['target_type' => 'group'],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

}
