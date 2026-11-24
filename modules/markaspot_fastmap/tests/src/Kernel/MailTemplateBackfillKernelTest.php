<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests FastMap mail backfills and shipped template fallbacks.
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class MailTemplateBackfillKernelTest extends KernelTestBase {

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
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'serialization',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_fastmap',
  ];

  /**
   * The two keys the update adds.
   */
  private const KEYS = ['workspace_opened_support', 'workspace_reports_waiting'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('module_handler')->loadInclude('markaspot_fastmap', 'install');

    // An older site: the shipped templates without the two keys, one of the
    // older keys edited by an admin.
    $shipped = $this->shipped('markaspot_fastmap.mail.yml');
    foreach (self::KEYS as $key) {
      unset($shipped[$key]);
    }
    $shipped['workspace_welcome']['subject'] = 'Edited welcome';
    $this->container->get('config.storage')->write('markaspot_fastmap.mail', $shipped);
    // A German override from that time, with an edited key of its own; a
    // French one that already carries the waiting-reports mail.
    // Adding a language installs the shipped overrides as they are today;
    // the older override did not have the new keys.
    $this->container->get('language_manager')->getLanguageConfigOverride('de', 'markaspot_fastmap.mail')
      ->clear('workspace_reports_waiting')
      ->clear('workspace_opened_support')
      ->set('workspace_welcome', ['subject' => 'Bearbeitet', 'body' => 'Hallo'])
      ->save();
    $this->container->get('language_manager')->getLanguageConfigOverride('fr', 'markaspot_fastmap.mail')
      ->set('workspace_reports_waiting', ['subject' => 'Édité', 'body' => 'Corps'])
      ->save();
  }

  /**
   * Both keys added, edited keys untouched, a second run adds nothing.
   */
  public function testBackfill(): void {
    $this->assertSame(realpath(dirname(__DIR__, 3)), realpath(DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('markaspot_fastmap')), 'The enabled module is this checkout.');
    $message = markaspot_fastmap_update_11932();
    $this->assertStringContainsString('workspace_opened_support, workspace_reports_waiting', $message);
    $this->assertStringContainsString('Backfilled lifecycle mail translations: de.', $message);

    $shipped = $this->shipped('markaspot_fastmap.mail.yml');
    $active = $this->config('markaspot_fastmap.mail');
    foreach (self::KEYS as $key) {
      $this->assertSame($shipped[$key], $active->get($key), $key);
      $this->assertNotSame('', trim((string) $active->get($key . '.subject')));
      $this->assertNotSame('', trim((string) $active->get($key . '.body')));
    }
    $this->assertSame('Edited welcome', $active->get('workspace_welcome.subject'), 'An edited key stays.');

    $languageManager = $this->container->get('language_manager');
    $german = $languageManager->getLanguageConfigOverride('de', 'markaspot_fastmap.mail');
    $this->assertSame($this->shipped('language/de/markaspot_fastmap.mail.yml')['workspace_reports_waiting'], $german->get('workspace_reports_waiting'));
    $this->assertNull($german->get('workspace_opened_support'), 'The support mail is English only.');
    $this->assertSame('Bearbeitet', $german->get('workspace_welcome.subject'));
    $this->assertSame('Édité', $languageManager->getLanguageConfigOverride('fr', 'markaspot_fastmap.mail')->get('workspace_reports_waiting.subject'), 'An existing override stays.');

    $again = markaspot_fastmap_update_11932();
    $this->assertStringContainsString('nothing to backfill', $again);
    $this->assertStringContainsString('No lifecycle mail translations to backfill.', $again);
  }

  /**
   * Repairs active fields and adds missing keys to existing overrides only.
   */
  public function testBackfillAllShippedMailTemplates(): void {
    $name = 'markaspot_fastmap.mail';
    $operator = 'workspace_operator_changed_support';
    $limited = 'workspace_auto_open_limited_support';
    $paid = 'workspace_deletion_scheduled_paid';
    $active = $this->config($name);
    $active->clear($operator)
      ->set($limited, ['subject' => " \n", 'body' => 'Custom support body'])
      ->set('workspace_verification.body', '')
      ->clear($paid)
      ->save();

    $language_manager = $this->container->get('language_manager');
    $german = $language_manager->getLanguageConfigOverride('de', $name);
    $german->clear($operator)
      ->set($limited, ['subject' => 'Eigener Betreff'])
      ->save();
    $french = $language_manager->getLanguageConfigOverride('fr', $name);
    $french->delete();
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $dutch = $language_manager->getLanguageConfigOverrideStorage('nl');
    $dutch->write($name, []);

    markaspot_fastmap_update_11933();

    $storage = $this->container->get('config.storage');
    $expected = $this->shipped($name . '.yml');
    $expected['workspace_welcome']['subject'] = 'Edited welcome';
    $expected[$limited]['body'] = 'Custom support body';
    $this->assertEquals($expected, $storage->read($name));
    $german = $language_manager->getLanguageConfigOverride('de', $name);
    $this->assertSame($this->shipped('language/de/' . $name . '.yml')[$operator], $german->get($operator));
    $this->assertSame(['subject' => 'Eigener Betreff'], $german->get($limited));
    $this->assertSame(['subject' => 'Bearbeitet', 'body' => 'Hallo'], $german->get('workspace_welcome'));
    $this->assertNull($german->get('workspace_opened_support'));
    $french_storage = $language_manager->getLanguageConfigOverrideStorage('fr');
    $this->assertFalse($french_storage->exists($name));
    $expected_dutch = $this->shipped('language/nl/' . $name . '.yml');
    unset($expected_dutch['langcode']);
    $this->assertSame($expected_dutch, $dutch->read($name));

    $translations = $german->getRawData();
    $this->assertSame('No FastMap mail templates to backfill.', markaspot_fastmap_update_11933());
    $this->assertEquals($expected, $storage->read($name));
    $this->assertSame($translations, $language_manager->getLanguageConfigOverrideStorage('de')->read($name));
    $this->assertFalse($french_storage->exists($name));
    $this->assertSame($expected_dutch, $dutch->read($name));
  }

  /**
   * Welcome templates fall back per field before using English config.
   */
  public function testWelcomeTemplatePrecedence(): void {
    $de = $this->container->get('language_manager')->getLanguageConfigOverride('de', 'markaspot_fastmap.mail');
    $de->set('workspace_welcome', ['subject' => 'Eigener Betreff'])->save();
    $shipped = $this->shipped('language/de/markaspot_fastmap.mail.yml')['workspace_welcome'];
    $this->assertSame(['subject' => 'Eigener Betreff', 'body' => $shipped['body']], _markaspot_fastmap_mail_template('workspace_welcome', 'de'));
    $de->clear('workspace_welcome')->save();
    $this->assertSame($shipped, _markaspot_fastmap_mail_template('workspace_welcome', 'de'));
    $this->assertSame('Edited welcome', _markaspot_fastmap_mail_template('workspace_welcome', 'en')['subject']);
    $this->assertSame('Edited welcome', _markaspot_fastmap_mail_template('workspace_welcome', 'zu')['subject']);
    $this->config('markaspot_fastmap.mail')->clear('workspace_welcome')->save();
    $this->assertSame($this->shipped('markaspot_fastmap.mail.yml')['workspace_welcome'], _markaspot_fastmap_mail_template('workspace_welcome', 'en'));
  }

  /**
   * Every key of the shipped file has a schema entry.
   */
  public function testSchemaCoversShippedKeys(): void {
    markaspot_fastmap_update_11932();
    $definition = $this->container->get('config.typed')->getDefinition('markaspot_fastmap.mail');
    foreach (array_keys($this->shipped('markaspot_fastmap.mail.yml')) as $key) {
      $this->assertArrayHasKey($key, $definition['mapping'], $key);
    }
  }

  /**
   * A shipped file of the module, decoded.
   */
  private function shipped(string $relative): array {
    $path = $this->container->get('extension.list.module')->getPath('markaspot_fastmap') . '/config/install/' . $relative;
    return Yaml::decode((string) file_get_contents($path));
  }

}
