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

/**
 * Shared setup for the staff link kernel tests.
 *
 * Two jurisdictions (Bonn, Dorsten), so dashboard links carry the /<slug>
 * path prefix, and request nodes in Bonn built without saving: an explicit
 * node ID stands in for a saved node, so the tests need none of the service
 * request save hooks.
 */
abstract class StaffLinkKernelTestBase extends KernelTestBase {

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
  protected GroupInterface $bonn;

  /**
   * The second jurisdiction.
   */
  protected GroupInterface $dorsten;

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

    $this->bonn = Group::create(['type' => 'jur', 'label' => 'Bonn', 'field_slug' => 'bonn']);
    $this->bonn->save();
    $this->dorsten = Group::create(['type' => 'jur', 'label' => 'Dorsten', 'field_slug' => 'dorsten']);
    $this->dorsten->save();
  }

  /**
   * Writes markaspot_mail.settings.
   *
   * @param array<string, string> $platform
   *   The platform part.
   * @param string $staffLinks
   *   The staff_links mode.
   */
  protected function mailSettings(array $platform, string $staffLinks = 'dashboard'): void {
    $this->config('markaspot_mail.settings')
      ->set('platform', $platform)
      ->set('staff_links', $staffLinks)
      ->save();
  }

  /**
   * Builds a request node in Bonn without saving it.
   */
  protected function node(?int $nid, string $bundle = 'service_request', string $title = 'Broken lamp'): NodeInterface {
    $values = [
      'type' => $bundle,
      'title' => $title,
      'field_jurisdiction' => $this->bonn->id(),
    ];
    if ($nid !== NULL) {
      $values['nid'] = $nid;
    }
    return Node::create($values);
  }

  /**
   * Lets Twig load the mail templates when the module lives outside web/.
   *
   * The isolated profile gate registers a worktree's modules by a path
   * that leaves the web root (../.wt/...), and Twig refuses template names
   * that do. The module's Twig namespace resolves the same files, so point
   * the theme hooks at it. No-op in a regular checkout and in CI.
   */
  protected function loadMailTemplatesThroughModuleNamespace(): void {
    $registry = $this->container->get('theme.registry')->getRuntime();
    foreach (['markaspot_mail_layout', 'markaspot_mail_card_transactional', 'markaspot_mail_hero_code'] as $hook) {
      $info = $registry->get($hook);
      if (is_array($info) && str_starts_with((string) ($info['path'] ?? ''), '../')) {
        $info['path'] = '@markaspot_mail';
        $registry->set($hook, $info);
      }
    }
  }

  /**
   * Creates a field storage and field instance.
   */
  protected function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = []): void {
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
