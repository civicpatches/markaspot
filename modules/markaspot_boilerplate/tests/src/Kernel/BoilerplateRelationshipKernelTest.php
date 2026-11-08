<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_boilerplate\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_boilerplate\Access\BoilerplateAccess;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/markaspot_boilerplate.install';
require_once dirname(__DIR__, 3) . '/markaspot_boilerplate.post_update.php';

/**
 * Covers the Group relations of text templates.
 *
 * The shipped "000 Received" template is the one template with translations.
 * Tenant setup scripts that inserted relation rows from node_field_data gave
 * it one relation per language; the entity API path gives it exactly one.
 * All groups and texts are fictional.
 *
 * @group markaspot_boilerplate
 */
#[RunTestsInSeparateProcesses]
final class BoilerplateRelationshipKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'node',
    'language', 'entity', 'flexible_permissions', 'group', 'gnode',
    'markaspot_boilerplate',
  ];

  /**
   * The tenant's jurisdiction.
   */
  private Group $county;

  /**
   * A second jurisdiction.
   */
  private Group $otherCounty;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'group', 'language']);
    ConfigurableLanguage::createFromLangcode('de')->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    $type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction']);
    $type->save();
    NodeType::create(['type' => 'boilerplate', 'name' => 'Template'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')
      ->createFromPlugin($type, 'group_node:boilerplate')->save();

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'node',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
      'cardinality' => -1, 'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'node',
      'bundle' => 'boilerplate', 'translatable' => FALSE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'boilerplate'])->save();

    $this->county = Group::create(['type' => 'jur', 'label' => 'Fictional county']);
    $this->county->save();
    $this->otherCounty = Group::create(['type' => 'jur', 'label' => 'Other county']);
    $this->otherCounty->save();
  }

  /**
   * The shipped template gets exactly one relation through the entity API.
   *
   * Runs the update that creates "000 Received" with its translations, then
   * assigns the jurisdiction and saves both languages, as the profile and a
   * hook-respecting tenant setup do.
   */
  public function testShippedTemplateGetsExactlyOneRelation(): void {
    markaspot_boilerplate_update_11001();
    $template = $this->shippedTemplate();
    $this->assertTrue($template->hasTranslation('de'));
    $this->assertSame([], $this->relationIds($template));

    $template->set('field_jurisdiction', $this->county->id())->save();
    $this->assertCount(1, $this->relationIds($template));

    $this->reload($template)->getTranslation('de')->save();
    $this->reload($template)->save();
    $relations = $this->relationIds($template);
    $this->assertCount(1, $relations);
    $this->assertSame([(int) $this->county->id()], array_values(array_unique($relations)));

    $this->assertSame(
      ['templates' => 0, 'deleted' => 0, 'failed' => 0],
      BoilerplateAccess::removeDuplicateRelationships(),
    );
  }

  /**
   * The post update removes the duplicates and leaves everything else alone.
   */
  public function testPostUpdateRemovesDuplicateRelations(): void {
    // The tenant setup pattern: field and relation rows written per
    // node_field_data row, without entity hooks.
    markaspot_boilerplate_update_11001();
    $shipped = $this->shippedTemplate();
    $this->backfillJurisdictionRows($shipped, $this->county);
    $this->insertRelationRowsPerTranslation($shipped, $this->county);
    $shipped_ids = $this->relationIds($shipped);
    $this->assertCount(2, $shipped_ids);
    try {
      $this->reload($shipped)->save();
      $this->fail('A template with two relations to its jurisdiction must not save.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('duplicate Group relationships', $exception->getMessage());
    }

    $triple = $this->template('Triple');
    $this->duplicateRelation($triple);
    $this->duplicateRelation($triple);
    $triple_ids = $this->relationIds($triple);
    $this->assertCount(3, $triple_ids);

    // Already exactly one, as after a manual cleanup.
    $single = $this->template('Single');
    $single_ids = $this->relationIds($single);
    $this->assertCount(1, $single_ids);

    // A second group is foreign ownership, not a duplicate.
    $foreign = $this->template('Foreign');
    $this->duplicateRelation($foreign, $this->otherCounty);
    $foreign_ids = $this->relationIds($foreign);
    $this->assertCount(2, $foreign_ids);

    $message = markaspot_boilerplate_post_update_remove_duplicate_template_relationships();

    $this->assertSame('Duplicate template relations: 2 templates repaired, 3 relations removed, 0 templates left for review.', $message);
    $this->assertSame([min(array_keys($shipped_ids))], array_keys($this->relationIds($shipped)));
    $this->assertSame([min(array_keys($triple_ids))], array_keys($this->relationIds($triple)));
    $this->assertSame($single_ids, $this->relationIds($single));
    $this->assertSame($foreign_ids, $this->relationIds($foreign));

    // The repaired template saves again, in both languages.
    $this->reload($shipped)->getTranslation('de')->save();
    $this->reload($shipped)->save();
    $this->assertCount(1, $this->relationIds($shipped));

    $this->assertSame(
      'Duplicate template relations: 0 templates repaired, 0 relations removed, 0 templates left for review.',
      markaspot_boilerplate_post_update_remove_duplicate_template_relationships(),
    );
  }

  /**
   * A deletion that fails leaves the template untouched and does not abort.
   */
  public function testFailedRepairIsReportedNotThrown(): void {
    $orphan = $this->template('Orphan');
    $this->duplicateRelation($orphan);
    $orphan_ids = $this->relationIds($orphan);
    // Without its jurisdiction field the remaining relation counts as foreign,
    // so the re-save after the deletion throws and is rolled back.
    $database = $this->container->get('database');
    foreach (['node__field_jurisdiction', 'node_revision__field_jurisdiction'] as $table) {
      $database->delete($table)->condition('entity_id', $orphan->id())->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $repaired = $this->template('Repaired');
    $this->duplicateRelation($repaired);

    $result = BoilerplateAccess::removeDuplicateRelationships();

    $this->assertSame(['templates' => 1, 'deleted' => 1, 'failed' => 1], $result);
    $this->assertSame($orphan_ids, $this->relationIds($orphan));
    $this->assertCount(1, $this->relationIds($repaired));
  }

  /**
   * Loads the template created by markaspot_boilerplate_update_11001().
   */
  private function shippedTemplate(): NodeInterface {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['type' => 'boilerplate', 'title' => '000 Received']);
    $this->assertCount(1, $nodes);
    $node = reset($nodes);
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Creates a template in the county through the entity API.
   */
  private function template(string $title): NodeInterface {
    $node = Node::create([
      'type' => 'boilerplate',
      'title' => $title,
      'uid' => 1,
      'status' => 1,
      'field_jurisdiction' => $this->county->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Reloads a template from storage.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns the template's relations as relation ID => group ID.
   *
   * @return array<int, int>
   *   Group IDs keyed by relation ID, ordered by relation ID.
   */
  private function relationIds(NodeInterface $node): array {
    $rows = $this->container->get('database')->select('group_relationship_field_data', 'r')
      ->fields('r', ['id', 'gid'])
      ->condition('r.plugin_id', 'group_node:boilerplate')
      ->condition('r.entity_id', $node->id())
      ->orderBy('r.id')
      ->execute()
      ->fetchAllKeyed();
    return array_map('intval', $rows);
  }

  /**
   * Writes field_jurisdiction rows per translation, as the setup scripts do.
   */
  private function backfillJurisdictionRows(NodeInterface $node, Group $group): void {
    $database = $this->container->get('database');
    $rows = $database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'vid', 'langcode'])
      ->condition('n.nid', $node->id())
      ->execute()
      ->fetchAll();
    foreach (['node__field_jurisdiction', 'node_revision__field_jurisdiction'] as $table) {
      foreach ($rows as $row) {
        $database->insert($table)->fields([
          'bundle' => 'boilerplate',
          'deleted' => 0,
          'entity_id' => $row->nid,
          'revision_id' => $row->vid,
          'langcode' => $row->langcode,
          'delta' => 0,
          'field_jurisdiction_target_id' => $group->id(),
        ])->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
  }

  /**
   * Inserts one relation per node_field_data row, as the setup scripts do.
   */
  private function insertRelationRowsPerTranslation(NodeInterface $node, Group $group): void {
    $database = $this->container->get('database');
    $rows = $database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'langcode', 'uid', 'title'])
      ->condition('n.nid', $node->id())
      ->execute()
      ->fetchAll();
    $now = $this->container->get('datetime.time')->getRequestTime();
    foreach ($rows as $row) {
      $id = $database->insert('group_relationship')->fields([
        'type' => 'jur-group_node-boilerplate',
        'uuid' => $this->container->get('uuid')->generate(),
        'langcode' => $row->langcode,
      ])->execute();
      $database->insert('group_relationship_field_data')->fields([
        'id' => $id,
        'type' => 'jur-group_node-boilerplate',
        'langcode' => $row->langcode,
        'uid' => $row->uid,
        'gid' => $group->id(),
        'entity_id' => $row->nid,
        'label' => $row->title,
        'created' => $now,
        'changed' => $now,
        'plugin_id' => 'group_node:boilerplate',
        'group_type' => 'jur',
        'default_langcode' => 1,
      ])->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
  }

  /**
   * Copies the template's first relation without entity hooks.
   */
  private function duplicateRelation(NodeInterface $node, ?Group $group = NULL): void {
    $database = $this->container->get('database');
    $source = (int) array_key_first($this->relationIds($node));
    $row = $database->select('group_relationship', 'r')->fields('r')->condition('id', $source)->execute()->fetchAssoc();
    unset($row['id']);
    $row['uuid'] = $this->container->get('uuid')->generate();
    $id = (int) $database->insert('group_relationship')->fields($row)->execute();
    foreach ($database->select('group_relationship_field_data', 'r')->fields('r')->condition('id', $source)->execute()->fetchAll(FetchAs::Associative) as $data) {
      $data['id'] = $id;
      if ($group !== NULL) {
        $data['gid'] = $group->id();
      }
      $database->insert('group_relationship_field_data')->fields($data)->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
  }

}
