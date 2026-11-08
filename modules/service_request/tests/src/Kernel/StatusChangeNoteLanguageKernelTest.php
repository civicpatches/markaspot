<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers the language of the generated note on a status change.
 *
 * A German tenant with English and Leichte Sprache enabled published the
 * English "Status changed." when the note field was untranslatable: every
 * language wrote its fallback into the one shared value and the last one won.
 *
 * @group service_request
 */
final class StatusChangeNoteLanguageKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'taxonomy',
    'language',
    'locale',
    'paragraphs',
    'entity_reference_revisions',
  ];

  /**
   * Status term the request starts in.
   */
  private int $openTid;

  /**
   * Status term the request moves to.
   */
  private int $doneTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('locale', [
      'locales_location',
      'locales_source',
      'locales_target',
    ]);
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language']);

    // The language set of the affected tenant, in its weight order.
    ConfigurableLanguage::createFromLangcode('de')->set('weight', -10)->save();
    ConfigurableLanguage::load('en')->set('weight', -9)->save();
    ConfigurableLanguage::create([
      'id' => 'de-ls',
      'label' => 'Leichte Sprache',
      'weight' => 1,
    ])->save();
    $this->config('system.site')->set('default_langcode', 'de')->save();
    $this->container->get('language_manager')->reset();
    $this->addGermanStatusChangedTranslation();

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();
    // Status terms without description, so the t() fallback is used.
    $open = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $open->save();
    $done = Term::create(['vid' => 'service_status', 'name' => 'Done']);
    $done->save();
    $this->openTid = (int) $open->id();
    $this->doneTid = (int) $done->id();

    ParagraphsType::create([
      'id' => 'status',
      'label' => 'Status',
    ])->save();

    $this->container->set('markaspot_open311.processor', new class {

      /**
       * Creates an empty status note paragraph.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create([
          'type' => 'status',
          'langcode' => $langcode,
        ]);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        return $paragraph;
      }

    });

    require_once dirname(__DIR__, 3) . '/service_request.module';
  }

  /**
   * An untranslatable note keeps the site-language text in every language.
   */
  public function testUntranslatableNoteKeepsTheSiteLanguageText(): void {
    $this->createStatusFields(FALSE);

    $paragraph = $this->changeStatus();

    $this->assertSame('de', $paragraph->language()->getId());
    foreach (['de', 'en', 'de-ls'] as $langcode) {
      $this->assertTrue($paragraph->hasTranslation($langcode), "Translation $langcode exists.");
      $this->assertSame(
        'Status wurde geändert.',
        $paragraph->getTranslation($langcode)->get('field_status_note')->value,
        "Note text of $langcode."
      );
    }
  }

  /**
   * A translatable note still carries a text per language.
   */
  public function testTranslatableNoteCarriesTextPerLanguage(): void {
    $this->createStatusFields(TRUE);

    $paragraph = $this->changeStatus();

    $this->assertSame('Status wurde geändert.', $paragraph->getTranslation('de')->get('field_status_note')->value);
    $this->assertSame('Status changed.', $paragraph->getTranslation('en')->get('field_status_note')->value);
    // No Leichte Sprache translation exists for the fallback string.
    $this->assertSame('Status changed.', $paragraph->getTranslation('de-ls')->get('field_status_note')->value);
  }

  /**
   * Moves a stored request from open to done and returns the new note.
   */
  private function changeStatus(): Paragraph {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'langcode' => 'de',
      'field_status' => $this->openTid,
    ]);
    $node->save();

    $node->original = clone $node;
    $node->set('field_status', $this->doneTid);
    _service_request_handle_update($node, $this->createMock(ImmutableConfig::class), TRUE);

    $this->assertCount(1, $node->get('field_status_notes'));
    $stored = Paragraph::load($node->get('field_status_notes')->target_id);
    $this->assertInstanceOf(Paragraph::class, $stored);
    return $stored;
  }

  /**
   * Adds the German locale string used by the fallback.
   */
  private function addGermanStatusChangedTranslation(): void {
    $storage = $this->container->get('locale.storage');
    $source = $storage->createString([
      'source' => 'Status changed.',
      'context' => '',
    ])->save();
    $storage->createTranslation([
      'lid' => $source->lid,
      'language' => 'de',
      'translation' => 'Status wurde geändert.',
    ])->save();

    if (function_exists('_locale_refresh_translations')) {
      _locale_refresh_translations(['de'], [$source->lid]);
    }
  }

  /**
   * Creates the entity fields read by the update handler.
   */
  private function createStatusFields(bool $noteTranslatable): void {
    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['service_status' => 'service_status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'paragraph'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status notes',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['status' => 'status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_term',
      'entity_type' => 'paragraph',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_term',
      'entity_type' => 'paragraph',
      'bundle' => 'status',
      'label' => 'Status',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['service_status' => 'service_status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_note',
      'entity_type' => 'paragraph',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_note',
      'entity_type' => 'paragraph',
      'bundle' => 'status',
      'label' => 'Step note',
      'translatable' => $noteTranslatable,
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
