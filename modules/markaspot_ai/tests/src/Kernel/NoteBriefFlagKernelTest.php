<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The note brief switch resolves from stored jurisdictions, root first.
 *
 * Real jurisdiction groups, the real feature scope resolver and hierarchy
 * resolver; markaspot_ai itself is not installed (its dependency chain is out
 * of scope), its procedural gate is loaded from the module file.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class NoteBriefFlagKernelTest extends KernelTestBase {

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
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'file', 'taxonomy_term', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    require_once dirname(__DIR__, 3) . '/markaspot_ai.module';
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
  }

  /**
   * An absent key is off, for the root and for a child inheriting from it.
   */
  public function testAbsentKeyResolvesToFalseIncludingChild(): void {
    $root = $this->jurisdiction('Root', ['aiProcessing' => TRUE]);
    $child = $this->jurisdiction('Child', ['aiProcessing' => TRUE], $root);
    $resolver = $this->container->get('markaspot_nuxt.feature_scope_resolver');

    foreach (['root' => $root, 'child' => $child] as $label => $jurisdiction) {
      $this->assertFalse($resolver->resolveEffectiveFeatures($jurisdiction)['aiNoteBrief'], $label);
      $this->assertFalse($resolver->isEnabledEffective('aiNoteBrief', $jurisdiction, FALSE), $label);
      $this->assertTrue($resolver->isEnabledEffective('aiProcessing', $jurisdiction, FALSE), $label);
      $this->assertFalse($this->service()->isNoteBriefEnabled($this->report($jurisdiction)), $label);
    }
  }

  /**
   * The root decides for the child; a child value alone never counts.
   */
  public function testRootDecidesForChild(): void {
    $root = $this->jurisdiction('Root', ['aiProcessing' => TRUE, 'aiNoteBrief' => TRUE]);
    $child = $this->jurisdiction('Child', ['aiNoteBrief' => FALSE], $root);
    $this->assertTrue($this->service()->isNoteBriefEnabled($this->report($child)));
    $this->assertTrue($this->container->get('markaspot_nuxt.feature_scope_resolver')->resolveEffectiveFeatures($child)['aiNoteBrief']);

    $otherRoot = $this->jurisdiction('Other root', ['aiProcessing' => TRUE]);
    $otherChild = $this->jurisdiction('Other child', ['aiProcessing' => TRUE, 'aiNoteBrief' => TRUE], $otherRoot);
    $this->assertFalse($this->service()->isNoteBriefEnabled($this->report($otherChild)));

    // Without AI text processing at the root the switch has no effect.
    $offRoot = $this->jurisdiction('Off root', ['aiProcessing' => FALSE, 'aiNoteBrief' => TRUE]);
    $this->assertFalse($this->service()->isNoteBriefEnabled($this->report($offRoot)));
  }

  /**
   * The form assistant; only the procedural gate is exercised here.
   */
  private function service(): AttributeFillingService {
    return $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();
  }

  /**
   * An unsaved report of a jurisdiction.
   */
  private function report(GroupInterface $jurisdiction): NodeInterface {
    return Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
  }

  /**
   * Creates a jurisdiction with stored features, optionally below a parent.
   */
  private function jurisdiction(string $label, array $features, ?GroupInterface $parent = NULL): GroupInterface {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_nuxt_config' => json_encode(['features' => $features]),
      'field_parent_jurisdiction' => $parent?->id(),
    ]);
    $group->save();
    $this->container->get('markaspot_group.hierarchy_resolver')->resetCache();
    return $group;
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = []): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
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
