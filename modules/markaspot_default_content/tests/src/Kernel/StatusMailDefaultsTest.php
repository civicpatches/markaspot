<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_default_content\Kernel;

use Drupal\migrate_plus\Entity\Migration;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\FileStorage;
use Drupal\eca\Entity\Eca;
use Drupal\field\Entity\FieldConfig;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\markaspot_mail\Kernel\StatusMailKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the actual optional-config install and CSV migration plugin path.
 *
 * Scripts/import.sh enables the migration modules and invokes these migration
 * IDs after profile install; no hook creates status terms before that import.
 */
#[Group('markaspot_default_content')]
#[RunTestsInSeparateProcesses]
final class StatusMailDefaultsTest extends StatusMailKernelTestBase {

  /**
   * Enables the same migration plugins that scripts/import.sh uses.
   */
  protected function setUp(): void {
    static::$modules = array_merge(static::$modules, [
      'migrate', 'migrate_plus', 'migrate_source_csv', 'markaspot_default_content',
    ]);
    parent::setUp();
  }

  /**
   * Installs shipped ECA config and executes the shipped CSV migration.
   */
  public function testInstalledModels(): void {
    // Remove the command fixture's models and terms. Install only the two
    // shipped optional models through Drupal's actual config installer.
    foreach (Eca::loadMultiple() as $model) {
      $model->delete();
    }
    foreach (Term::loadMultiple() as $term) {
      $term->delete();
    }
    $optional = new FileStorage($this->siteDirectory . '/optional');
    $modulePath = $this->container->get('extension.list.module')->getPath('markaspot_group');
    foreach (['process_tunr6d6', 'process_notify_status_term'] as $id) {
      $optional->write('eca.eca.' . $id, Yaml::decode(file_get_contents($modulePath . '/config/optional/eca.eca.' . $id . '.yml')));
    }
    $this->container->get('config.installer')->installOptionalConfig($optional);
    $this->assertFalse(Eca::load('process_tunr6d6')->status());
    $this->assertTrue(Eca::load('process_notify_status_term')->status());
  }

  /**
   * The CSV migration supplies keys during the real default-content import.
   */
  public function testImportedStatusKeys(): void {
    foreach (Term::loadMultiple() as $term) {
      $term->delete();
    }
    $path = $this->container->get('extension.list.module')->getPath('markaspot_default_content');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex_opacity', 'decimal');
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $manager = $this->container->get('plugin.manager.migration');
    foreach (['group_jurisdiction', 'service_status'] as $part) {
      $definition = Yaml::decode(file_get_contents($path . '/migrations/migrate_plus.migration.markaspot_migrate_default_content_' . $part . '.yml'));
      Migration::create($definition + ['migration_tags' => []])->save();
    }
    // Execute the real jurisdiction migration first, as scripts/import.sh does.
    $manager->clearCachedDefinitions();
    foreach (['group_jurisdiction', 'service_status'] as $part) {
      $migration = $manager->createInstance('markaspot_migrate_default_content_' . $part);
      $executable = new MigrateExecutable($migration, new MigrateMessage());
      $this->assertSame(1, $executable->import(), 'The shipped migration completes.');
    }
    $this->assertSame('status_open', Term::load(4)->get('field_notification_key')->value);
    $this->assertSame('status_closed', Term::load(5)->get('field_notification_key')->value);
    $this->assertTrue(Term::load(3)->get('field_notification_key')->isEmpty());
    $this->assertTrue(Term::load(6)->get('field_notification_key')->isEmpty());
    $this->assertNull(Term::load(30));
    $this->assertCount(4, Term::loadMultiple());
    $field = FieldConfig::loadByName('taxonomy_term', 'service_status', 'field_notification_key');
    $this->assertNotNull($field);
  }

}
