<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Dependency;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Plugin\Validation\Constraint\FileUploadBindingConstraint;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\Tests\markaspot_vision\Traits\FileUploadBindingTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the file upload binding through the enabled markaspot_vision module.
 *
 * FileUploadBindingKernelTest registers the services and the constraint by
 * hand. This test only enables the module and the dependency chain its info
 * files declare, so hook discovery, the attribute plugin discovery and the
 * service definitions of markaspot_vision are what bind the upload.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class FileUploadBindingWiringKernelTest extends KernelTestBase {

  use FileUploadBindingTestTrait;
  use MediaTypeCreationTrait;

  /**
   * Core modules enabled first; setUp() resolves the rest from info files.
   *
   * @var list<string>
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'image', 'media'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    // Before the chain: a role save lists every enabled module by name, and
    // after enableModules() it could not resolve markaspot_nuxt in CI.
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media']);
    $this->enableModules($this->withDependencies('markaspot_vision'));

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->createMediaType('image', ['id' => 'request_image', 'label' => 'Request image']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The base class clears the session of the current request.
    $this->enterTeardownRequest();
    parent::tearDown();
  }

  /**
   * Returns a module and every module it requires, as an install enables them.
   *
   * Resolved from the info files, so a new dependency of markaspot_vision or
   * of a module it depends on is enabled here too instead of failing the
   * container build. Sorted by name, like the module order of an install.
   *
   * @return list<string>
   *   The module names.
   */
  private function withDependencies(string $module): array {
    $available = (new ExtensionDiscovery($this->root))->scan('module');
    $resolved = [];
    $pending = [$module];
    while ($pending !== []) {
      $name = array_shift($pending);
      if (isset($resolved[$name])) {
        continue;
      }
      $this->assertArrayHasKey($name, $available, "Required module $name is not in the codebase.");
      $resolved[$name] = TRUE;
      $info = Yaml::decode((string) file_get_contents($available[$name]->getPathname()));
      foreach ($info['dependencies'] ?? [] as $dependency) {
        $pending[] = Dependency::createFromString($dependency)->getName();
      }
    }
    $modules = array_map('strval', array_keys($resolved));
    sort($modules);
    return $modules;
  }

  /**
   * The module's hooks and constraint plugin are discovered.
   */
  public function testHooksAndConstraintAreDiscovered(): void {
    $modules = $this->container->get('module_handler');
    foreach (['file_insert', 'file_delete', 'entity_bundle_field_info_alter', 'entity_base_field_info_alter'] as $hook) {
      $this->assertTrue($modules->hasImplementations($hook, 'markaspot_vision'), "markaspot_vision implements hook_$hook.");
    }

    $constraints = $this->container->get('validation.constraint');
    $this->assertTrue($constraints->hasDefinition('FileUploadBinding'));
    $definition = $constraints->getDefinition('FileUploadBinding');
    $this->assertSame(FileUploadBindingConstraint::class, $definition['class']);
    $this->assertSame('markaspot_vision', $definition['provider']);

    $fields = $this->container->get('entity_field.manager');
    $media = $fields->getFieldDefinitions('media', 'request_image');
    $this->assertArrayHasKey('FileUploadBinding', $media['field_media_image']->getConstraints());
    $this->assertArrayNotHasKey('FileUploadBinding', $media['thumbnail']->getConstraints(), 'The read-only thumbnail stays unbound.');
    // A formatted text base field, bound through the base field hook.
    $term = $fields->getFieldDefinitions('taxonomy_term', 'tags');
    $this->assertArrayHasKey('FileUploadBinding', $term['description']->getConstraints());
  }

  /**
   * The enabled module records the upload and refuses a second session.
   */
  public function testEnabledModuleBindsTheUpload(): void {
    $this->enter($this->request('citizen-token'));
    $file = $this->upload('public');

    $this->enter($this->request('other-token'));
    $this->assertSame(1, $this->bindingViolations($this->media((int) $file->id()), 'field_media_image'), 'A second session must not claim the upload.');

    $this->enter($this->request('citizen-token'));
    $this->assertSame(0, $this->bindingViolations($this->media((int) $file->id()), 'field_media_image'), 'The uploading session attaches its own file.');
  }

  /**
   * Builds an unsaved request_image media for the file.
   */
  private function media(int $fid): MediaInterface {
    return Media::create([
      'bundle' => 'request_image',
      'name' => 'Photo',
      'status' => 0,
      'field_media_image' => ['target_id' => $fid, 'alt' => 'Photo'],
    ]);
  }

}
