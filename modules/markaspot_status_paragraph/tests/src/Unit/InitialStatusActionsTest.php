<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_status_paragraph\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/markaspot_status_paragraph.module';

/**
 * Tests initial status action removal for both Paragraphs widgets.
 */
#[Group('markaspot_status_paragraph')]
final class InitialStatusActionsTest extends UnitTestCase {

  /**
   * The classic widget loses its complete dropbutton, not later note actions.
   */
  public function testClassicWidgetRemovesInitialDropbutton(): void {
    $item = [
      'top' => [
        'paragraph_type_title' => ['info' => ['#markup' => 'Status']],
        'links' => [
          '#theme_wrappers' => ['dropbutton_wrapper', 'paragraphs_dropbutton_wrapper'],
          'prefix' => ['#markup' => '<ul class="dropbutton">'],
          'edit_button' => ['#type' => 'submit', '#value' => 'Edit'],
          'remove_button' => ['#type' => 'submit', '#value' => 'Remove'],
          'suffix' => ['#markup' => '</ul>'],
        ],
      ],
      'subform' => ['status_note' => ['#required' => TRUE]],
    ];
    $form = ['field_status_notes' => ['widget' => [
      0 => $item,
      1 => $item,
      'add_more' => ['#type' => 'submit', '#value' => 'Add status'],
    ]]];

    _markaspot_status_paragraph_lock_initial_status($form);

    $widget = $form['field_status_notes']['widget'];
    $this->assertArrayNotHasKey('links', $widget[0]['top']);
    $this->assertSame($item['top']['paragraph_type_title'], $widget[0]['top']['paragraph_type_title']);
    $this->assertSame($item['subform'], $widget[0]['subform']);
    $this->assertSame($item, $widget[1]);
    $this->assertSame('Add status', $widget['add_more']['#value']);
  }

  /**
   * AJAX rebuilds and repeated alters keep later status actions intact.
   */
  public function testClassicWidgetSurvivesAjaxRebuild(): void {
    $actions = [
      '#theme_wrappers' => ['paragraphs_dropbutton_wrapper'],
      'edit_button' => ['#type' => 'submit'],
      'remove_button' => ['#type' => 'submit'],
    ];
    $form = ['field_status_notes' => ['widget' => [
      '#id' => 'field-status-notes-values',
      0 => ['top' => ['links' => $actions]],
    ]]];
    _markaspot_status_paragraph_lock_initial_status($form);

    // Paragraphs rebuilds the field with a new wrapper and another note.
    $form['field_status_notes']['widget'] = [
      '#id' => 'field-status-notes-values--ajax-rebuild',
      0 => ['top' => ['links' => $actions]],
      1 => ['top' => ['links' => $actions]],
    ];
    _markaspot_status_paragraph_lock_initial_status($form);

    $widget = $form['field_status_notes']['widget'];
    $this->assertArrayNotHasKey('links', $widget[0]['top']);
    $this->assertSame($actions, $widget[1]['top']['links']);

    $altered = $form;
    _markaspot_status_paragraph_lock_initial_status($form);
    $this->assertSame($altered, $form);
  }

  /**
   * The modern widget still removes initial actions and global edit toggles.
   */
  public function testModernWidgetKeepsLaterActions(): void {
    $item = ['top' => ['actions' => [
      'actions' => ['edit_button' => ['#type' => 'submit']],
      'dropdown_actions' => [
        'remove_button' => ['#type' => 'submit'],
        'duplicate_button' => ['#type' => 'submit'],
        'convert_button' => ['#type' => 'submit'],
      ],
    ]]];
    $form = ['field_status_notes' => ['widget' => [
      0 => $item,
      1 => $item,
      'header_actions' => [
        'actions' => ['edit_all' => [], 'collapse_all' => [], 'add_more' => []],
        'dropdown_actions' => ['edit_all' => [], 'collapse_all' => []],
      ],
    ]]];

    _markaspot_status_paragraph_lock_initial_status($form);

    $widget = $form['field_status_notes']['widget'];
    $this->assertArrayNotHasKey('edit_button', $widget[0]['top']['actions']['actions']);
    $this->assertArrayNotHasKey('dropdown_actions', $widget[0]['top']['actions']);
    $this->assertSame($item, $widget[1]);
    $this->assertSame(['add_more' => []], $widget['header_actions']['actions']);
    $this->assertSame([], $widget['header_actions']['dropdown_actions']);
  }

  /**
   * Forms without status notes are unchanged.
   */
  public function testUnrelatedFormIsUnchanged(): void {
    $form = ['title' => ['#type' => 'textfield']];
    $original = $form;
    _markaspot_status_paragraph_lock_initial_status($form);
    $this->assertSame($original, $form);
  }

}
