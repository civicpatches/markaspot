<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_tenant_import\Service\TenantConfigValidator;
use Drupal\markaspot_tenant_import\Service\TenantImportFieldMapper;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the optional note_required status key of tenant documents.
 */
#[Group('markaspot_tenant_import')]
#[CoversClass(TenantConfigValidator::class)]
#[CoversClass(TenantImportFieldMapper::class)]
final class TenantStatusNoteRequiredTest extends UnitTestCase {

  /**
   * The key is optional and must be a boolean when present.
   */
  public function testValidatorAcceptsOnlyBooleans(): void {
    $email = $this->createMock(EmailValidatorInterface::class);
    $email->method('isValid')->willReturn(TRUE);
    $validator = new TenantConfigValidator($email);
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/tenant-config.example.json'), TRUE);
    $error = 'statuses[1].note_required must be a boolean.';

    $this->assertNotContains($error, $validator->validate($document));
    foreach ([TRUE, FALSE] as $value) {
      $document['statuses'][1]['note_required'] = $value;
      $this->assertNotContains($error, $validator->validate($document));
    }
    foreach (['yes', 1, NULL] as $value) {
      $document['statuses'][1]['note_required'] = $value;
      $this->assertContains($error, $validator->validate($document), var_export($value, TRUE));
    }
  }

  /**
   * New statuses start off, re-imports without the key keep the stored value.
   */
  public function testMapperSetsTheSwitchOnlyWhenGivenOrNew(): void {
    $mapper = new TenantImportFieldMapper();
    $status = [
      'kind' => 'closed',
      'open311' => 'closed',
      'notify_citizen' => FALSE,
      'description' => '',
      'weight' => 3,
      'hex' => '#D55E00',
      'icon' => 'i-lucide-x-circle',
    ];

    $new = $mapper->statusFieldValues($this->term(TRUE), $status, 7);
    $this->assertFalse($new['field_note_required']);

    $existing = $mapper->statusFieldValues($this->term(FALSE), $status, 7);
    $this->assertArrayNotHasKey('field_note_required', $existing);

    $existing = $mapper->statusFieldValues($this->term(FALSE), ['note_required' => TRUE] + $status, 7);
    $this->assertTrue($existing['field_note_required']);

    $withoutField = $mapper->statusFieldValues($this->term(TRUE, FALSE), ['note_required' => TRUE] + $status, 7);
    $this->assertArrayNotHasKey('field_note_required', $withoutField);
  }

  /**
   * A status term with empty plain fields.
   */
  private function term(bool $isNew, bool $hasNoteField = TRUE): TermInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getType')->willReturn('string');
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn(TRUE);
    $items->method('getFieldDefinition')->willReturn($definition);
    $term = $this->createMock(TermInterface::class);
    $term->method('isNew')->willReturn($isNew);
    $term->method('get')->willReturn($items);
    $term->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field !== 'field_note_required' || $hasNoteField
    );
    return $term;
  }

}
