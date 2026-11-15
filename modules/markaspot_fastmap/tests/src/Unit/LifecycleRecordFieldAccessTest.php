<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the deny of the lifecycle record in the module's access hooks.
 *
 * Runs the hook functions of this checkout's module file. The deny sits in
 * the module's only implementation of hook_entity_field_access(): core
 * merges field access results per module, so a second implementation would
 * overwrite one of the two results.
 *
 * @group markaspot_fastmap
 */
class LifecycleRecordFieldAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';
  }

  /**
   * Builds a field definition double.
   */
  private function definition(string $entityType, string $name): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getTargetEntityTypeId')->willReturn($entityType);
    $definition->method('getName')->willReturn($name);
    return $definition;
  }

  /**
   * Builds an account double.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturn($uid === 1);
    return $account;
  }

  /**
   * The function under test comes from this checkout.
   */
  public function testHookFunctionsComeFromThisCheckout(): void {
    $module = realpath(dirname(__DIR__, 3) . '/markaspot_fastmap.module');
    foreach (['markaspot_fastmap_entity_field_access', 'markaspot_fastmap_jsonapi_entity_field_filter_access'] as $function) {
      $this->assertSame($module, (new \ReflectionFunction($function))->getFileName(), $function);
    }
  }

  /**
   * View and edit of the record are forbidden for every account.
   */
  public function testRecordIsForbidden(): void {
    $record = $this->definition('group', WorkspaceLifecycle::FIELD);
    foreach (['plain account' => $this->account(42), 'uid 1' => $this->account(1)] as $label => $account) {
      foreach (['view', 'edit'] as $operation) {
        $this->assertTrue(markaspot_fastmap_entity_field_access($operation, $record, $account)->isForbidden(), "$label: $operation");
      }
      $this->assertTrue(markaspot_fastmap_jsonapi_entity_field_filter_access($record, $account)->isForbidden(), "$label: filter");
    }
  }

  /**
   * The cases the function handled before keep their results.
   */
  public function testOtherFieldsAreUnchanged(): void {
    $account = $this->account(42);
    $slug = $this->definition('group', 'field_slug');
    foreach (['view', 'edit'] as $operation) {
      $this->assertTrue(markaspot_fastmap_entity_field_access($operation, $slug, $account)->isNeutral(), "field_slug: $operation");
    }
    $this->assertTrue(markaspot_fastmap_jsonapi_entity_field_filter_access($slug, $account)->isNeutral());

    // Status of a report: viewing is not gated, editing without field items
    // stays neutral (the existing early returns).
    $status = $this->definition('node', 'status');
    $this->assertTrue(markaspot_fastmap_entity_field_access('view', $status, $account)->isNeutral());
    $this->assertTrue(markaspot_fastmap_entity_field_access('edit', $status, $account)->isNeutral());

    // Same name on another entity type is not the record.
    $nodeField = $this->definition('node', WorkspaceLifecycle::FIELD);
    $this->assertTrue(markaspot_fastmap_entity_field_access('view', $nodeField, $account)->isNeutral());
    $this->assertTrue(markaspot_fastmap_jsonapi_entity_field_filter_access($nodeField, $account)->isNeutral());
  }

}
