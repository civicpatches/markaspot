<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\LocaleConfigBatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests enabling Monolog without replacing site configuration translations.
 */
#[Group('markaspot_nuxt')]
#[RunTestsInSeparateProcesses]
final class MonologUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'language', 'locale', 'locale_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.install';
    $this->installEntitySchema('user');
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['system', 'user', 'language', 'locale', 'locale_test']);
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Both import settings must preserve customized German configuration.
   */
  #[DataProvider('importSettings')]
  public function testTranslationsArePreserved(bool $import_enabled): void {
    $this->config('locale.settings')->set('translation.import_enabled', $import_enabled)->save();
    // Write the override independently of locale's string translation storage,
    // as when importing a tenant's customized configuration collection.
    $storage = $this->container->get('language_manager')->getLanguageConfigOverrideStorage('de');
    $storage->write('locale_test.translation', ['test' => 'Meldungen verwalten']);
    $source = $this->container->get('locale.storage')->createString([
      'source' => 'English test',
      'context' => '',
    ])->save();
    $this->container->get('locale.storage')->createTranslation([
      'lid' => $source->lid,
      'language' => 'de',
      'translation' => 'Meldungen',
    ])->save();
    $this->assertFalse(InstallerKernel::installationAttempted());
    $had_install_state = array_key_exists('install_state', $GLOBALS);
    $install_state = $GLOBALS['install_state'] ?? NULL;
    $batch = &batch_get();
    $batch = [];

    markaspot_nuxt_update_11921();
    $this->container = \Drupal::getContainer();
    $storage = $this->container->get('language_manager')->getLanguageConfigOverrideStorage('de');

    $this->assertTrue($this->container->get('module_handler')->moduleExists('monolog'));
    $this->assertSame('Meldungen verwalten', $storage->read('locale_test.translation')['test']);
    $this->assertSame($import_enabled, $this->config('locale.settings')->get('translation.import_enabled'));
    $this->assertSame([], batch_get(), 'Neither download nor config translation batches were scheduled.');
    $this->assertSame($had_install_state, array_key_exists('install_state', $GLOBALS));
    $this->assertSame($install_state, $GLOBALS['install_state'] ?? NULL);

    // Prove that the fixture would be overwritten by the real config batch,
    // even with translation imports disabled.
    $this->config('locale.settings')->set('translation.import_enabled', FALSE)->save();
    locale_system_update(['module' => ['monolog']]);
    $this->assertNotEmpty(batch_get()['sets']);
    $context = [];
    $this->container->get(LocaleConfigBatch::class)->batchUpdateConfigTranslations(['locale_test.translation'], ['de'], $context);
    $this->assertSame('Meldungen', $storage->read('locale_test.translation')['test']);
  }

  /**
   * Provides enabled and disabled translation import settings.
   */
  public static function importSettings(): array {
    return [[TRUE], [FALSE]];
  }

  /**
   * A second invocation changes neither configuration nor installer state.
   */
  public function testAlreadyEnabledDoesNothing(): void {
    markaspot_nuxt_update_11921();
    $this->container = \Drupal::getContainer();
    $config = $this->container->get('config.storage');
    $before = $config->readMultiple($config->listAll());
    ksort($before);
    $batch = &batch_get();
    $batch = [];
    $this->assertSame('Monolog is already enabled.', markaspot_nuxt_update_11921());
    $after = $config->readMultiple($config->listAll());
    ksort($after);
    $this->assertSame($before, $after);
    $this->assertSame([], batch_get());
  }

  /**
   * Installer state is restored even when installing the module fails.
   */
  public function testInstallerFailureRestoresState(): void {
    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->once())->method('install')->with(['monolog'])
      ->willReturnCallback(function (): never {
        $this->assertTrue(InstallerKernel::installationAttempted());
        throw new \RuntimeException('Installation failed.');
      });
    $this->container->set('module_installer', $installer);
    $had_install_state = array_key_exists('install_state', $GLOBALS);
    $install_state = $GLOBALS['install_state'] ?? NULL;
    $original = ['installation_finished' => TRUE, 'parameters' => ['profile' => 'testing']];
    $GLOBALS['install_state'] = $original;
    try {
      try {
        markaspot_nuxt_update_11921();
        $this->fail('The installation failure must propagate.');
      }
      catch (\RuntimeException $exception) {
        $this->assertSame('Installation failed.', $exception->getMessage());
      }
      $this->assertSame($original, $GLOBALS['install_state']);
    }
    finally {
      if ($had_install_state) {
        $GLOBALS['install_state'] = $install_state;
      }
      else {
        unset($GLOBALS['install_state']);
      }
    }
  }

}
