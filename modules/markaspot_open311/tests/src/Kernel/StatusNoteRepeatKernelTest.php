<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\RemoteImageFetcher;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a GeoReport update does not repeat the last status note.
 */
#[Group('markaspot_open311')]
#[RunTestsInSeparateProcesses]
final class StatusNoteRepeatKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'node', 'taxonomy',
    'entity_reference_revisions', 'paragraphs',
  ];

  /**
   * Status term "in progress".
   */
  private int $inProgress;

  /**
   * Status term "done".
   */
  private int $done;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'paragraph'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);

    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $termReference = [
      'target_type' => 'taxonomy_term',
    ];
    $this->field('node', 'service_request', 'field_status', 'entity_reference', $termReference);
    $this->field('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->field('paragraph', 'status', 'field_status_term', 'entity_reference', $termReference);
    $this->field('paragraph', 'status', 'field_status_note', 'text_long');

    $this->inProgress = $this->term('In progress');
    $this->done = $this->term('Done');
  }

  /**
   * The same note sent twice is stored once.
   */
  public function testRepeatedNoteIsStoredOnce(): void {
    $node = $this->createReport($this->inProgress);

    $node = $this->update($node, ['field_status_notes' => 'Collection has been scheduled.']);
    $node = $this->update($node, ['field_status_notes' => 'Collection has been scheduled.']);
    $this->assertNoteCount(1, $node);

    // Whitespace differences and a resubmitted unchanged status do not make
    // the note new either.
    $node = $this->update($node, [
      'field_status' => $this->inProgress,
      'field_status_notes' => "  Collection has been\n scheduled. ",
    ]);
    $this->assertNoteCount(1, $node);
  }

  /**
   * Markup and HTML entities of the stored note are not compared.
   */
  public function testStoredMarkupIsIgnored(): void {
    $node = $this->createReport($this->inProgress, [
      $this->note($this->inProgress, '<p>Bins &amp; bags&nbsp;collected.</p>', 'basic_html'),
    ]);

    $node = $this->update($node, ['field_status_notes' => 'Bins & bags collected.']);
    $this->assertNoteCount(1, $node);

    // The escaped form an Open311 note is stored in matches as well.
    $node = $this->update($node, ['field_status_notes' => 'Bins &amp; bags collected.']);
    $this->assertNoteCount(1, $node);
  }

  /**
   * Escaped markup matches the same markup stored by the dashboard.
   */
  public function testEscapedMarkupMatchesStoredMarkup(): void {
    $node = $this->createReport($this->inProgress, [
      $this->note($this->inProgress, '<p>Termin <b>morgen</b></p>', 'basic_html'),
    ]);

    $node = $this->update($node, ['field_status_notes' => 'Termin &lt;b&gt;morgen&lt;/b&gt;']);
    $this->assertNoteCount(1, $node);
  }

  /**
   * A "<" in plain text is text, not the start of a tag.
   */
  public function testLessThanSignKeepsNotesApart(): void {
    $node = $this->createReport($this->inProgress);

    $node = $this->update($node, ['field_status_notes' => 'Menge <120 l']);
    $node = $this->update($node, ['field_status_notes' => 'Menge <240 l']);
    $this->assertNoteCount(2, $node);
    $this->assertSame('Menge <240 l', $this->lastNote($node)->get('field_status_note')->value);

    // The escaped form of the same text is still the same note.
    $node = $this->update($node, ['field_status_notes' => 'Menge &lt;240 l']);
    $this->assertNoteCount(2, $node);
  }

  /**
   * A changed note text gives a second note.
   */
  public function testChangedTextAppendsNote(): void {
    $node = $this->createReport($this->inProgress);

    $node = $this->update($node, ['field_status_notes' => 'Collection has been scheduled.']);
    $node = $this->update($node, ['field_status_notes' => 'Collection is delayed.']);

    $this->assertNoteCount(2, $node);
    $this->assertSame('Collection is delayed.', $this->lastNote($node)->get('field_status_note')->value);
  }

  /**
   * The same text with another status gives a second note.
   */
  public function testSameTextWithOtherStatusAppendsNote(): void {
    $node = $this->createReport($this->inProgress);

    $node = $this->update($node, ['field_status_notes' => 'Thank you for your report.']);
    $node = $this->update($node, [
      'field_status' => $this->done,
      'field_status_notes' => 'Thank you for your report.',
    ]);

    $this->assertNoteCount(2, $node);
    $this->assertSame($this->done, (int) $node->get('field_status')->target_id);
    $this->assertSame($this->done, (int) $this->lastNote($node)->get('field_status_term')->target_id);
  }

  /**
   * A status change gets its note even when the last note looks the same.
   */
  public function testStatusChangeAlwaysAppendsNote(): void {
    $node = $this->createReport($this->inProgress, [
      $this->note($this->done, 'Thank you for your report.'),
    ]);

    $node = $this->update($node, [
      'field_status' => $this->done,
      'field_status_notes' => 'Thank you for your report.',
    ]);

    $this->assertNoteCount(2, $node);
    $this->assertSame($this->done, (int) $node->get('field_status')->target_id);
  }

  /**
   * Applies GeoReport update values the way the update endpoint does.
   */
  private function update(NodeInterface $node, array $values): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $node = $storage->load($node->id());

    $method = new \ReflectionMethod(GeoreportRequestResource::class, 'processUpdateFields');
    $method->invoke($this->resource(), $node, $values);
    $node->save();

    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Builds the update resource with the real status note factory.
   */
  private function resource(): GeoreportRequestResource {
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')->willReturn(TRUE);

    $reflection = new \ReflectionClass(GeoreportRequestResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('currentUser')->setValue($resource, $currentUser);
    $reflection->getProperty('georeportProcessor')->setValue($resource, $this->processor());
    return $resource;
  }

  /**
   * Builds the processor from the kernel container.
   */
  private function processor(): GeoreportProcessorService {
    $container = $this->container;
    return new GeoreportProcessorService(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('module_handler'),
      $container->get('entity_field.manager'),
      $container->get('stream_wrapper_manager'),
      $container->get('token'),
      $container->get('language_manager'),
      $container->get('database'),
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('messenger'),
      $container->get('account_switcher'),
      new StatusClassifier($container->get('config.factory'), $container->get('entity_type.manager'), $container->get('cache.default')),
      NULL,
      NULL,
      new KeyValueMemoryFactory(),
      new RemoteImageFetcher(new Client()),
    );
  }

  /**
   * Creates a report with the given status and notes.
   */
  private function createReport(int $status, array $notes = []): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Synthetic report',
      'field_status' => $status,
      'field_status_notes' => array_map(static fn(Paragraph $paragraph): array => [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ], $notes),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a stored status note.
   */
  private function note(int $status, string $text, string $format = 'plain_text'): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_term' => $status,
      'field_status_note' => ['value' => $text, 'format' => $format],
    ]);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Creates a status term.
   */
  private function term(string $name): int {
    $term = Term::create(['vid' => 'service_status', 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Returns the last status note of a report.
   */
  private function lastNote(NodeInterface $node): Paragraph {
    $paragraph = $node->get('field_status_notes')->last()?->entity;
    $this->assertInstanceOf(Paragraph::class, $paragraph);
    return $paragraph;
  }

  /**
   * Asserts the number of status notes on a report.
   */
  private function assertNoteCount(int $expected, NodeInterface $node): void {
    $this->assertCount($expected, $node->get('field_status_notes'));
  }

  /**
   * Adds a fixture field.
   */
  private function field(string $entity_type, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'entity_type' => $entity_type,
      'field_name' => $name,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $name,
    ])->save();
  }

}
