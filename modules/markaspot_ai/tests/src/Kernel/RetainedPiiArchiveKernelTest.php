<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Privacy\RetainedPiiOriginal;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Archiving a report erases the original text retained by PII redaction.
 *
 * Runs the real PII presave redaction (regex only) and the real archive
 * transition with markaspot_ai and markaspot_archive enabled; the test module
 * stands in for the markaspot_nuxt feature resolver.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class RetainedPiiArchiveKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file', 'options',
    'entity', 'flexible_permissions', 'group', 'taxonomy',
    'entity_reference_revisions', 'paragraphs',
    'markaspot_ai', 'markaspot_ai_pii_test', 'markaspot_archive',
  ];

  /**
   * Synthetic personal data the redaction removes from the public text.
   */
  private const EMAIL = 'private@example.test';
  private const PHONE = '+49 170 1234567';

  /**
   * Jurisdiction with PII redaction enabled.
   */
  private GroupInterface $jurisdiction;

  /**
   * Status term ids keyed by name.
   *
   * @var array<string, int>
   */
  private array $status = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'group', 'group_relationship', 'group_config_wrapper', 'paragraph'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_ai', [
      'markaspot_ai_embeddings',
      'markaspot_ai_duplicate_matches',
      'markaspot_ai_sentiment',
    ]);
    $this->installConfig(['system', 'user', 'field', 'node', 'filter', 'group']);

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service Request',
      'new_revision' => TRUE,
    ])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Service status'])->save();
    foreach (['open', 'archived'] as $name) {
      $term = Term::create(['vid' => 'service_status', 'name' => $name]);
      $term->save();
      $this->status[$name] = (int) $term->id();
    }
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();

    $this->addField('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    $this->addField('node', 'service_request', 'body', 'text_with_summary');
    $this->addField('node', 'service_request', 'field_e_mail', 'email');
    $this->addField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->addField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->addField('node', 'service_request', 'field_internal_remark', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Tenant']);
    $this->jurisdiction->save();

    $this->config('markaspot_archive.settings')
      ->set('status_archivable', [$this->status['open'] => (string) $this->status['open']])
      ->set('status_archived', (string) $this->status['archived'])
      ->set('unpublish', 1)
      ->set('anonymize', 1)
      ->set('anonymize_fields', ['field_e_mail' => 'field_e_mail'])
      ->save();

    // Global switch on, provider name detection off: regex redaction only.
    // markaspot_ai_pii_test opts every tenant into piiRedaction.
    $GLOBALS['config']['markaspot_ai.settings']['pii_redaction'] = [
      'enabled' => TRUE,
      'detect_names' => FALSE,
    ];
  }

  /**
   * A direct archive erases the original in every revision, nothing else.
   */
  public function testDirectArchiveErasesRetainedOriginalInAllRevisions(): void {
    $node = $this->submitWithPii();
    $original = $this->retainedOriginal($node);
    $this->assertNotNull($original, 'Redaction retained the original as an internal remark.');
    $this->assertStringContainsString(self::PHONE, (string) $original->get('field_internal_remark_text')->value);

    // Staff add a remark and edit the original: new revisions of both.
    $plain = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => ['value' => 'Crew scheduled for Tuesday.', 'format' => 'plain_text'],
    ]);
    $node->get('field_internal_remark')->appendItem($plain);
    $this->retainedOriginal($node)->set('field_internal_remark_text', [
      'value' => 'Reporter at ' . self::PHONE . ' called again.',
      'format' => 'plain_text',
    ]);
    $node->setNewRevision(TRUE);
    $node->save();
    $this->assertGreaterThan(1, count($this->remarkRevisionTexts((int) $original->id())));
    $this->assertStringContainsString(self::EMAIL, implode(' ', $this->remarkRevisionTexts((int) $original->id())));

    // A status change outside the queue worker archives the report.
    $node->set('field_status', $this->status['archived']);
    $node->setNewRevision(TRUE);
    $node->save();

    $this->assertRemarkErased((int) $original->id());
    $erased = Paragraph::load($original->id());
    $this->assertTrue(RetainedPiiOriginal::isMarked($erased), 'The erased remark keeps its provenance mark.');
    $this->assertTrue(RetainedPiiOriginal::isExcludedFromAi($erased));
    $this->assertSame(['Crew scheduled for Tuesday.'], array_values(array_unique($this->remarkRevisionTexts((int) $plain->id()))));
    $archived = Node::load($node->id());
    $this->assertFalse($archived->isPublished());
    $this->assertStringEndsWith('@anonymized.off', (string) $archived->get('field_e_mail')->value);

    // Editing and saving the same report object again creates new remark
    // revisions from the loaded remarks; they must not bring the text back.
    $node->setTitle('Pothole, archived');
    $node->setNewRevision(TRUE);
    $node->save();
    $this->assertRemarkErased((int) $original->id());
  }

  /**
   * An original only previous report revisions reference is erased too.
   */
  public function testArchiveErasesOriginalRemovedFromCurrentRevision(): void {
    $node = $this->submitWithPii();
    $original_id = (int) $this->retainedOriginal($node)->id();

    // Staff remove the remark; earlier revisions still reference it.
    $node->set('field_internal_remark', []);
    $node->setNewRevision(TRUE);
    $node->save();
    $this->assertNotNull(Paragraph::load($original_id));

    $node->set('field_status', $this->status['archived']);
    $node->setNewRevision(TRUE);
    $node->save();

    $this->assertRemarkErased($original_id);
  }

  /**
   * Submits a report whose text holds personal data.
   */
  private function submitWithPii(): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Pothole',
      'body' => [
        'value' => 'Deep pothole outside number 4. Call me at ' . self::PHONE . ' or write to ' . self::EMAIL . '.',
        'format' => 'plain_text',
      ],
      'field_e_mail' => self::EMAIL,
      'field_status' => $this->status['open'],
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $node->save();
    $this->assertStringNotContainsString(self::EMAIL, (string) $node->get('body')->value);
    return $node;
  }

  /**
   * Finds the remark that holds the retained original.
   */
  private function retainedOriginal(NodeInterface $node): ?ParagraphInterface {
    foreach ($node->get('field_internal_remark') as $item) {
      if ($item->entity instanceof ParagraphInterface && RetainedPiiOriginal::isPiiOriginal($item->entity)) {
        return $item->entity;
      }
    }
    return NULL;
  }

  /**
   * Asserts that no stored text of a remark holds personal data.
   */
  private function assertRemarkErased(int $paragraph_id): void {
    $texts = [
      ...$this->remarkRevisionTexts($paragraph_id),
      ...\Drupal::database()->select('paragraph__field_internal_remark_text', 't')
        ->fields('t', ['field_internal_remark_text_value'])
        ->condition('entity_id', $paragraph_id)
        ->execute()
        ->fetchCol(),
    ];
    $this->assertNotSame([], $texts);
    foreach ($texts as $text) {
      $this->assertSame(RetainedPiiOriginal::erasedText(), $text);
    }
  }

  /**
   * Returns the remark text of every revision of a paragraph.
   *
   * @return string[]
   *   Texts in revision order.
   */
  private function remarkRevisionTexts(int $paragraph_id): array {
    return \Drupal::database()->select('paragraph_revision__field_internal_remark_text', 't')
      ->fields('t', ['field_internal_remark_text_value'])
      ->condition('entity_id', $paragraph_id)
      ->orderBy('revision_id')
      ->execute()
      ->fetchCol();
  }

  /**
   * Adds a configurable field to a bundle.
   */
  private function addField(string $entity_type, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    $field_settings = [];
    if ($type === 'entity_reference_revisions') {
      $field_settings = [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['internal_remark' => 'internal_remark']],
      ];
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => $field_settings,
    ])->save();
  }

}
