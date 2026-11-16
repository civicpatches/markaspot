<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\eca\Entity\Eca;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_mail\Service\StatusMailKeyAnalyzer;
use Drupal\markaspot_mail\Service\StatusMailKeyMigration;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\markaspot_mail\Unit\Service\StatusMailKeyAnalyzerTest;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Exercises command safety and actual ECA mail dispatch on saved reports.
 */
#[TestGroup('markaspot_mail')]
abstract class StatusMailKernelTestBase extends KernelTestBase {

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
    'path_alias',
    'token',
    'modeler_api',
    'eca',
    'eca_content',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'service_request',
    'markaspot_mail',
  ];

  /**
   * {@inheritdoc}
   *
   * The shipped legacy model carries an "object" key on core's e-mail action
   * that has no config schema. The models are imported as shipped.
   */
  protected static $configSchemaCheckerExclusions = [
    'eca.eca.process_tunr6d6',
  ];


  /**
   * Neutral jurisdictions.
   */
  protected array $districts = [];

  /**
   * Test-owned backup files removed at teardown.
   */
  protected array $backups = [];

  /**
   * Reused report for comparing identical mail bodies before and after.
   */
  protected ?Node $parityReport = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entityTypes = [
      'user',
      'node',
      'taxonomy_term',
      'paragraph',
      'path_alias',
      'group',
      'group_relationship',
      'group_config_wrapper',
    ];
    foreach ($entityTypes as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $lock = new DatabaseLockBackend($this->container->get('database'));
    $this->container->set('lock', $lock);
    $lock->acquire('fixture_setup');
    $lock->release('fixture_setup');
    $this->container->set('flood', new DatabaseBackend($this->container->get('database'), $this->container->get('request_stack'), $this->container->get('datetime.time')));
    $this->container->get('flood')->register('fixture_setup');
    $this->container->get('flood')->clear('fixture_setup');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'eca']);
    // No automatic status notes: the endpoint writes the only paragraph.
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    // markaspot_open311 is not enabled; the group presave asks it for an
    // initial status of new requests.
    $this->container->set('markaspot_open311.processor', new class {

      /**
       * Leaves the initial status unset.
       */
      public function getInitialStatusTid(int $jurisdictionId): ?int {
        return NULL;
      }

    });

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1, 'mail' => 'admin@example.test']);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    // One root jurisdiction; the group hooks assign new reports to it.
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_slug', 'string');
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    $this->createField('taxonomy_term', 'service_status', 'field_notification_key', 'string');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request', 'new_revision' => TRUE])->save();
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$types->load('jur-group_node-service_request')) {
      $types->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    }
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->createField('taxonomy_term', 'service_status', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    foreach ([1, 2] as $id) {
      $district = Group::create([
        'id' => $id,
        'type' => 'jur',
        'label' => "District $id",
        'field_slug' => "district-$id",
      ]);
      $district->save();
      $this->districts[$id] = $district;
    }
    foreach ([
      3 => 'New',
      4 => 'Open',
      5 => 'Closed',
      6 => 'Archived',
      30 => 'Not responsible',
      37 => 'Resolved separately',
      44 => 'Other open',
    ] as $id => $name) {
      Term::create([
        'tid' => $id,
        'vid' => 'service_status',
        'name' => $name,
        'field_jurisdiction' => $id === 44 ? 2 : 1,
      ])->save();
    }
    [$old, $term] = StatusMailKeyAnalyzerTest::models();
    // A fourth branch may share a key, but never a term.
    $old['conditions']['status37'] = $old['conditions']['Flow_0kbs1sz'];
    $old['conditions']['status37']['configuration']['expected_value'] = '37';
    $old['gateways']['Gateway_0nyfe5b']['successors'][] = ['id' => 'extra_closed', 'condition' => 'status37'];
    $old['actions']['extra_closed'] = $old['actions']['Activity_1u8e4au'];
    Eca::create($old)->save();
    Eca::create($term)->save();
    $this->config('system.site')->set('uuid', 'neutral-test-site')->save();
    $this->installConfig(['markaspot_mail']);
  }

  /**
   * Cleans backup artifacts from the isolated test filesystem.
   */
  protected function tearDown(): void {
    foreach ($this->backups as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Traverses all statuses including a re-entry and a second jurisdiction.
   */
  protected function transitions(): array {
    $node = $this->parityReport ??= $this->report(3);
    $node->set('field_status', '3')->save();
    $results = [];
    foreach ([4, 5, 30, 37, 6, 3, 44] as $id) {
      $this->resetMail();
      $node->set('field_status', (string) $id)->save();
      $results[] = $this->mails();
    }
    // Re-entry behaves like the first transition; same ID saves do not.
    $this->resetMail();
    $node->set('field_status', '4')->save();
    $this->assertSame($results[0], $this->mails());
    $this->resetMail();
    $node->set('field_status', '4')->save();
    $this->assertSame([], $this->mails());
    $other = $this->report(44, 2);
    $this->resetMail();
    $other->setTitle('Changed note')->save();
    $this->assertSame([], $this->mails());
    return $results;
  }

  /**
   * Creates a real report, with the same neutral title for body comparison.
   */
  protected function report(int $status, int $district = 1): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken bench',
      'field_status' => (string) $status,
      'field_notification' => TRUE,
      'field_e_mail' => 'reporter@example.test',
      'field_jurisdiction' => $district,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Returns mail evidence, excluding object identity and generated headers.
   */
  protected function mails(): array {
    return array_map(static fn(array $mail): array => array_intersect_key($mail, array_flip([
      'id', 'to', 'langcode', 'subject', 'body',
    ])), $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Starts each observation with a free flood budget and empty mail collector.
   */
  protected function resetMail(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
    $this->container->get('database')->delete('flood')->execute();
  }

  /**
   * Ensures a rejected apply never mutates terms or model state.
   */
  protected function assertRejected(string $message): void {
    try {
      $this->migration()->apply();
      $this->fail('Expected a rejected migration.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }
    $this->assertTrue(Eca::load(StatusMailKeyAnalyzer::OLD_MODEL)->status());
    $this->assertTrue(Term::load(4)->get('field_notification_key')->isEmpty());
  }

  /**
   * Creates the production service with real test services.
   */
  protected function migration(): StatusMailKeyMigration {
    return new StatusMailKeyMigration(...$this->arguments());
  }

  /**
   * The production constructor's dependencies.
   */
  protected function arguments(): array {
    return array_merge(array_map(fn(string $id) => $this->container->get($id), [
      'entity_type.manager',
      'entity_field.manager',
      'state',
      'lock',
      'database',
      'file_system',
      'datetime.time',
    ]), [new StatusMailKeyAnalyzer(), $this->container->get('config.factory')]);
  }

  /**
   * Creates fields for the isolated report/status/jurisdiction schema.
   */
  protected function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'settings' => $storageSettings,
        'cardinality' => $cardinality,
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
