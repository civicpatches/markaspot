<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

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
 * hand. This test only enables the module and its dependency chain, so hook
 * discovery, the attribute plugin discovery and the service definitions of
 * markaspot_vision are what bind the upload.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class FileUploadBindingWiringKernelTest extends KernelTestBase {

  use FileUploadBindingTestTrait;
  use MediaTypeCreationTrait;

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
    'media',
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
    'markaspot_vision',
  ];

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
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media']);

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

    $source = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('media', 'request_image')['field_media_image'];
    $this->assertArrayHasKey('FileUploadBinding', $source->getConstraints());
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
