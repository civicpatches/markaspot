<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the staff link tokens through Drupal's token service.
 *
 * Covers discovery of the token hooks in markaspot_mail.module and the
 * real MailBrandingService resolution, guard included, for the tokens ECA
 * and other mail texts use.
 */
#[TestGroup('markaspot_mail')]
#[RunTestsInSeparateProcesses]
final class StaffLinkTokensKernelTest extends KernelTestBase {

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
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'service_request',
    'markaspot_mail',
  ];

  /**
   * The first jurisdiction.
   */
  private GroupInterface $bonn;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // markaspot_group's group hooks read this user field.
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createField('group', 'jur', 'field_slug', 'string');
    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }

    // Two jurisdictions: links carry the /<slug> path prefix.
    $this->bonn = Group::create(['type' => 'jur', 'label' => 'Bonn', 'field_slug' => 'bonn']);
    $this->bonn->save();
    Group::create(['type' => 'jur', 'label' => 'Dorsten', 'field_slug' => 'dorsten'])->save();
  }

  /**
   * Tests both tokens are declared for nodes.
   */
  public function testTokensAreDeclared(): void {
    $info = $this->container->get('token')->getInfo();

    $this->assertArrayHasKey('markaspot_dashboard_url', $info['tokens']['node']);
    $this->assertArrayHasKey('markaspot_backend_edit_url', $info['tokens']['node']);
  }

  /**
   * Tests the tokens use the staff backend base and ignore staff_links.
   */
  public function testTokensResolveWithTheStaffBackendBase(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ]);

    $text = $this->replace('[node:markaspot_dashboard_url] | [node:markaspot_backend_edit_url]', $this->node(4711));

    $this->assertSame('https://melder.example.org/bonn/dashboard/requests/4711 | https://management.example.org/node/4711/edit', $text);
  }

  /**
   * Tests the backend token is empty when the asset base is the frontend.
   */
  public function testBackendTokenIsEmptyWhenTheBaseIsTheFrontendHost(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => '',
    ]);

    $text = $this->replace('[[node:markaspot_backend_edit_url]]', $this->node(4711));

    $this->assertSame('[]', $text);
  }

  /**
   * Tests the backend token falls back to the asset origin of the backend.
   */
  public function testBackendTokenFallsBackToTheBackendBaseUrl(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://management.example.org',
    ]);

    $text = $this->replace('[node:markaspot_backend_edit_url]', $this->node(4711));

    $this->assertSame('https://management.example.org/node/4711/edit', $text);
  }

  /**
   * Tests other bundles and unsaved nodes get empty values.
   */
  public function testOtherBundlesAndUnsavedNodesAreEmpty(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ]);

    $this->assertSame('[]', $this->replace('[[node:markaspot_backend_edit_url]]', $this->node(4711, 'page')));
    $this->assertSame('[][]', $this->replace('[[node:markaspot_backend_edit_url]][[node:markaspot_dashboard_url]]', $this->node(NULL)));
  }

  /**
   * Writes the platform part of markaspot_mail.settings.
   */
  private function mailSettings(array $platform): void {
    $this->config('markaspot_mail.settings')
      ->set('platform', $platform)
      ->save();
  }

  /**
   * Replaces tokens for a node with the token service.
   */
  private function replace(string $text, NodeInterface $node): string {
    return (string) $this->container->get('token')->replace($text, ['node' => $node], [
      'langcode' => 'en',
      'clear' => TRUE,
    ]);
  }

  /**
   * Builds a request node in Bonn without saving it.
   *
   * An explicit node ID stands in for a saved node, so the test needs none
   * of the service request save hooks.
   */
  private function node(?int $nid, string $bundle = 'service_request'): NodeInterface {
    $values = [
      'type' => $bundle,
      'title' => 'Broken lamp',
      'field_jurisdiction' => $this->bonn->id(),
    ];
    if ($nid !== NULL) {
      $values['nid'] = $nid;
    }
    return Node::create($values);
  }

  /**
   * Creates a field storage and field instance.
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
    ])->save();
  }

}
