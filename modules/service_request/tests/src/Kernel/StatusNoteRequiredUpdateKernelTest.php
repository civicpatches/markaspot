<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers service_request_update_11024(), which adds field_note_required.
 */
#[Group('service_request')]
#[RunTestsInSeparateProcesses]
final class StatusNoteRequiredUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['system', 'field', 'filter']);
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => 'service_status',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('name', ['type' => 'string_textfield'])->save();
    require_once dirname(__DIR__, 3) . '/service_request.install';
  }

  /**
   * The update creates the field and widget once; existing terms stay off.
   */
  public function testUpdateCreatesFieldAndIsIdempotent(): void {
    $existing = Term::create(['vid' => 'service_status', 'name' => 'Rejected']);
    $existing->save();

    $message = service_request_update_11024();
    $this->assertStringContainsString('Added field_note_required', $message);

    $storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_note_required');
    $this->assertNotNull($storage);
    $this->assertSame('boolean', $storage->getType());
    $field = FieldConfig::loadByName('taxonomy_term', 'service_status', 'field_note_required');
    $this->assertNotNull($field);
    $this->assertSame('Note required', $field->getLabel());
    $this->assertFalse($field->isRequired());
    $this->assertSame([['value' => 0]], $field->getDefaultValueLiteral());
    // Same UUIDs on every site, so tenant config/sync exports match.
    $this->assertSame('d11af134-0b61-4236-a8ca-73bec858bccc', $storage->uuid());
    $this->assertSame('4171a918-e0e1-4f8b-9add-bdc4410ab9ef', $field->uuid());

    $display = EntityFormDisplay::load('taxonomy_term.service_status.default');
    $this->assertSame('boolean_checkbox', $display->getComponent('field_note_required')['type'] ?? NULL);

    $existing = Term::load($existing->id());
    $this->assertFalse((bool) $existing->get('field_note_required')->value, 'Existing statuses start without the requirement.');
    $new = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $this->assertFalse((bool) $new->get('field_note_required')->value, 'New statuses default to off.');

    $this->assertStringContainsString('already exists', service_request_update_11024());
  }

  /**
   * The shipped config files match what the update creates.
   */
  public function testShippedConfigMatchesUpdate(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/install');
    $storage = $source->read('field.storage.taxonomy_term.field_note_required');
    $field = $source->read('field.field.taxonomy_term.service_status.field_note_required');
    $this->assertIsArray($storage);
    $this->assertIsArray($field);

    service_request_update_11024();
    $created_storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_note_required');
    $created_field = FieldConfig::loadByName('taxonomy_term', 'service_status', 'field_note_required');
    $this->assertSame($storage['type'], $created_storage->getType());
    $this->assertSame((bool) $storage['translatable'], $created_storage->isTranslatable());
    $this->assertSame($field['label'], $created_field->getLabel());
    $this->assertSame($field['description'], $created_field->getDescription());
    $this->assertSame($field['default_value'], $created_field->getDefaultValueLiteral());
    $this->assertSame($field['settings'], $created_field->getSettings());
  }

}
