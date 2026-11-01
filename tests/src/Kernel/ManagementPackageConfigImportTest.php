<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Kernel;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests management config convergence through the import transformer.
 */
#[Group('markaspot')]
#[RunTestsInSeparateProcesses]
final class ManagementPackageConfigImportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'language', 'views', 'search_api'];

  /**
   * The package references plugins from modules not needed for config imports.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Profile-owned config covered by the guard.
   */
  private const CONFIG_NAMES = ['views.view.management', 'search_api.index.service_requests'];

  /**
   * Profile fixture containing the real package without unrelated dependencies.
   */
  private string $profilePath;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->getDefinition('config.storage.schema')->setClass(ManagementPackageSchemaStorage::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language']);
    $this->installEntitySchema('search_api_task');
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Import real View/Index entities without installing the entire profile.
    // Only dependency declarations are omitted; all handler/processor settings
    // and translations remain identical to the shipped definitions.
    $this->profilePath = $this->siteDirectory . '/management-profile';
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $fixture = new FileStorage($this->profilePath . '/config/optional');
    $translations = new FileStorage(dirname(__DIR__, 3) . '/config/optional/language/de');
    $fixtureTranslations = new FileStorage($this->profilePath . '/config/optional/language/de');
    foreach (self::CONFIG_NAMES as $name) {
      $data = $source->read($name);
      $data['dependencies'] = [];
      $fixture->write($name, $data);
      $fixtureTranslations->write($name, $translations->read($name));
      $this->assertSame($data, $fixture->read($name));
    }

    $profiles = $this->createMock(ProfileExtensionList::class);
    $profiles->method('getPath')->with('markaspot')->willReturn($this->profilePath);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->container->get('extension.list.module'),
      $this->container->get('config.storage'),
      'markaspot',
      $logger,
      $profiles,
    );
    $this->container->get('event_dispatcher')->addSubscriber($subscriber);
  }

  /**
   * Tests stale sync, localized bases, and a second import without writes.
   */
  #[DataProvider('siteLanguages')]
  public function testImportConverges(string $langcode): void {
    $this->config('system.site')->set('default_langcode', $langcode)->save();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $source = new FileStorage($this->profilePath . '/config/optional');
    foreach (self::CONFIG_NAMES as $name) {
      $data = $source->read($name);
      $data['uuid'] = $this->container->get('uuid')->generate();
      $data['langcode'] = $langcode;
      $active->write($name, $data);
    }
    // Start with a legacy German base but outdated shared field definitions.
    $view = $active->read('views.view.management');
    $view['label'] = $langcode === 'de' ? 'Verwaltung' : 'Management';
    $view['display']['default']['display_options']['fields']['request_id']['label'] = $langcode === 'de' ? 'Meldungsnummer' : 'Request ID';
    $view['display']['default']['display_options']['fields']['request_id']['field'] = 'obsolete_request_id';
    unset($view['display']['default']['display_options']['filters']['status']);
    $active->write('views.view.management', $view);
    $index = $active->read('search_api.index.service_requests');
    $index['field_settings']['request_id']['property_path'] = 'obsolete_request_id';
    $active->write('search_api.index.service_requests', $index);
    $this->copyConfig($active, $sync);

    $first = $this->comparer();
    foreach (self::CONFIG_NAMES as $name) {
      $this->assertContains($name, $first->getChangelist('update'));
    }
    $importer = $this->container->get(ConfigImporterFactory::class)->get($first);
    $importer->import();
    $this->assertSame([], $importer->getErrors());

    $view = $active->read('views.view.management');
    $index = $active->read('search_api.index.service_requests');
    $this->assertSame($langcode, $view['langcode']);
    $this->assertSame($langcode, $index['langcode']);
    $this->assertSame($langcode === 'de' ? 'Verwaltung' : 'Management', $view['label']);
    $this->assertSame($langcode === 'de' ? 'Meldungsnummer' : 'Request ID', $index['field_settings']['request_id']['label']);
    $this->assertSame($langcode === 'de' ? 'Meldungsnummer' : 'Request ID', $view['display']['default']['display_options']['fields']['request_id']['label']);
    $this->assertSame('request_id', $view['display']['default']['display_options']['fields']['request_id']['field']);
    $this->assertSame('request_id', $index['field_settings']['request_id']['property_path']);
    $this->assertSame('search_api_boolean', $view['display']['default']['display_options']['filters']['status']['plugin_id']);
    $this->assertSame('Verwaltung', $active->createCollection('language.de')->read('views.view.management')['label']);

    // Compare the full imported objects, not just their translated labels.
    foreach (self::CONFIG_NAMES as $name) {
      $expected = $source->read($name);
      if ($langcode === 'de') {
        $translation = (new FileStorage($this->profilePath . '/config/optional/language/de'))->read($name);
        $expected = array_replace_recursive($expected, $translation);
      }
      $data = $active->read($name);
      unset($data['uuid']);
      $this->assertEquals($expected, $data);
    }

    // Re-read the same stale sync, not an export of the first import result.
    $second = $this->comparer();
    foreach ($second->getAllCollectionNames() as $collection) {
      foreach (['create', 'update', 'delete', 'rename'] as $operation) {
        $this->assertSame([], array_intersect(self::CONFIG_NAMES, $second->getChangelist($operation, $collection)));
      }
    }
    $writes = [];
    $this->container->get('event_dispatcher')->addListener(ConfigEvents::SAVE, static function (ConfigCrudEvent $event) use (&$writes): void {
      if (in_array($event->getConfig()->getName(), self::CONFIG_NAMES, TRUE)) {
        $writes[] = $event->getConfig()->getName();
      }
    });
    $importer = $this->container->get(ConfigImporterFactory::class)->get($second);
    $importer->import();
    $this->assertSame([], $importer->getErrors());
    $this->assertSame([], $writes);
    $this->assertSame('obsolete_request_id', $sync->read('search_api.index.service_requests')['field_settings']['request_id']['property_path']);
  }

  /**
   * Tests the existing tenant opt-out still bypasses language normalization.
   */
  public function testOptOut(): void {
    new Settings(['markaspot_manage_management_view' => FALSE] + Settings::getAll());
    $this->config('system.site')->set('default_langcode', 'de')->save();
    $active = $this->container->get('config.storage');
    $source = new FileStorage($this->profilePath . '/config/optional');
    foreach (self::CONFIG_NAMES as $name) {
      $active->write($name, $source->read($name));
    }
    $this->copyConfig($active, $this->container->get('config.storage.sync'));
    $comparer = $this->comparer();
    foreach ($comparer->getAllCollectionNames() as $collection) {
      foreach (['create', 'update', 'delete', 'rename'] as $operation) {
        $this->assertSame([], array_intersect(self::CONFIG_NAMES, $comparer->getChangelist($operation, $collection)));
      }
    }
    foreach (self::CONFIG_NAMES as $name) {
      $this->assertSame($source->read($name), $comparer->getSourceStorage()->read($name));
    }
  }

  /**
   * Returns supported default-language cases.
   */
  public static function siteLanguages(): array {
    return ['German' => ['de'], 'English' => ['en']];
  }

  /**
   * Compares freshly transformed sync with raw active storage.
   */
  private function comparer(): StorageComparer {
    $transformed = $this->container->get('config.import_transformer')->transform($this->container->get('config.storage.sync'));
    return (new StorageComparer($transformed, $this->container->get('config.storage')))->createChangelist();
  }

}
