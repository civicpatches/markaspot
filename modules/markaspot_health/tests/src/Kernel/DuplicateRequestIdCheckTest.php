<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

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
 * Tests duplicate detection against real node and group storage tables.
 */
#[TestGroup('markaspot_health')]
final class DuplicateRequestIdCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language',
    'entity', 'flexible_permissions', 'group',
    'markaspot_request_id', 'markaspot_health',
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
    foreach (['user', 'node', 'group', 'group_relationship'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'field', 'group']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createReferenceField('node', 'service_request', 'field_jurisdiction');
    $this->createReferenceField('node', 'page', 'field_jurisdiction');
    $this->createReferenceField('group', 'jur', 'field_parent_jurisdiction');
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
   * Independent counters may legitimately assign the same request ID.
   */
  public function testSameIdInDifferentJurisdictionsPasses(): void {
    $this->createRequest($this->jurisdictions[0]);
    $this->createRequest($this->jurisdictions[1]);

    $result = $this->plugin()->run();
    $this->assertTrue($result->passed);
    $this->assertSame(0, $result->count);
    $this->assertSame([], $result->details);
  }

  /**
   * An ambiguous pair reports the jurisdiction, request ID and both node IDs.
   */
  public function testDuplicateWithinJurisdictionFails(): void {
    $first = $this->createRequest($this->jurisdictions[0]);
    $second = $this->createRequest($this->jurisdictions[0]);
    $this->createRequest($this->jurisdictions[1]);

    $result = $this->plugin()->run();
    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertSame(1, $result->count);
    $this->assertSame([[
      'jurisdiction_id' => (int) $this->jurisdictions[0]->id(),
      'request_id' => '42-2026',
      'count' => 2,
      'nids' => [(int) $first->id(), (int) $second->id()],
    ]], $result->details);
    $this->assertStringContainsString(sprintf('%d: 42-2026 x2 (nids %d, %d)',
      $this->jurisdictions[0]->id(), $first->id(), $second->id()), $result->message);
    $this->assertStringNotContainsString('Fictional', $result->message);
  }

  /**
   * Roots, children and deeper descendants share the root's counter.
   */
  public function testHierarchyDuplicatesFail(): void {
    $child = Group::create([
      'type' => 'jur',
      'label' => 'Fictional child',
      'field_parent_jurisdiction' => $this->jurisdictions[0]->id(),
    ]);
    $child->save();
    $root_request = $this->createRequest($this->jurisdictions[0]);
    $child_request = $this->createRequest($child);
    $this->createRequest($this->jurisdictions[1]);

    $result = $this->plugin()->run();
    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertSame((int) $this->jurisdictions[0]->id(), $result->details[0]['jurisdiction_id']);
    $this->assertSame([(int) $root_request->id(), (int) $child_request->id()], $result->details[0]['nids']);

    $grandchild = Group::create([
      'type' => 'jur',
      'label' => 'Fictional grandchild',
      'field_parent_jurisdiction' => $child->id(),
    ]);
    $grandchild->save();
    $grandchild_request = $this->createRequest($grandchild);
    $result = $this->plugin()->run();
    $this->assertSame(3, $result->details[0]['count']);
    $this->assertSame([(int) $root_request->id(), (int) $child_request->id(), (int) $grandchild_request->id()], $result->details[0]['nids']);
  }

  /**
   * Translations, revisions and extra field values do not inflate counts.
   */
  public function testOnlyCurrentDefaultTranslationAndFirstDeltaCount(): void {
    $first = $this->createRequest($this->jurisdictions[0]);
    $first->addTranslation('de', [
      'title' => 'Fictional translated report title',
      'request_id' => '42-2026',
    ]);
    $first->setNewRevision(TRUE);
    $first->save();
    $second = $this->createRequest($this->jurisdictions[1]);
    $second->get('field_jurisdiction')->appendItem($this->jurisdictions[0]->id());
    $second->save();
    $this->createRequest($this->jurisdictions[0], '42-2026', 'page');
    $this->assertTrue($this->plugin()->run()->passed);

    // Unpublished requests still reserve their IDs and must be checked.
    $second->set('field_jurisdiction', $this->jurisdictions[0]->id());
    $second->setUnpublished();
    $second->save();
    $result = $this->plugin()->run();
    $this->assertFalse($result->passed);
    $this->assertSame(2, $result->details[0]['count']);
  }

  /**
   * The total is not capped when only ten example groups are shown.
   */
  public function testExamplesAreLimited(): void {
    for ($i = 1; $i <= 12; $i++) {
      $this->createRequest($this->jurisdictions[0], "$i-2026");
      $this->createRequest($this->jurisdictions[0], "$i-2026");
    }
    $result = $this->plugin()->run();
    $this->assertSame(12, $result->count);
    $this->assertCount(10, $result->details);
    $this->assertSame(2, $result->detailsTruncatedCount);
    $this->assertSame(10, substr_count($result->message, '(nids '));
  }

  /**
   * Discovers the plugin through the same manager used by mas:health.
   */
  private function plugin(): HealthCheckInterface {
    // Support isolated worktrees as well as a standard installed module path.
    $this->container->get('container.namespaces')->offsetSet('Drupal\\markaspot_health', dirname(__DIR__, 3) . '/src');
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
