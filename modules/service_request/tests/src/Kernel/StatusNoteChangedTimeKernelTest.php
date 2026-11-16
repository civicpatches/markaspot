<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_publisher\PublisherService;
use Drupal\markaspot_resubmission\ReminderManager;
use Drupal\markaspot_resubmission\ResubmissionService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests status-note additions through regular node saves and timer readers.
 */
#[Group('service_request')]
#[RunTestsInSeparateProcesses]
final class StatusNoteChangedTimeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'file', 'taxonomy',
    'language', 'entity_reference_revisions', 'paragraphs', 'service_request',
  ];

  /**
   * Reader configs are used without enabling their unrelated module services.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = [
    'markaspot_open311.settings',
    'markaspot_publisher.settings',
    'markaspot_resubmission.settings',
  ];

  /**
   * Controlled request time.
   */
  private int $now;

  /**
   * Initial status.
   */
  private Term $status;

  /**
   * Report category.
   */
  private Term $category;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'file', 'taxonomy_term', 'paragraph'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $user = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $this->now = time();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $this->container->set('datetime.time', $time);

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    foreach (['service_status', 'service_category'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
    $this->status = Term::create(['vid' => 'service_status', 'name' => 'Open']);
    $this->status->save();
    $this->category = Term::create(['vid' => 'service_category', 'name' => 'Road']);
    $this->category->save();
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->field('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('paragraph', 'status', 'field_status_note', 'text_long');
    $this->field('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A direct append updates changed and preserves both revision snapshots.
   */
  public function testDirectAppendPreservesRevisionSnapshots(): void {
    $node = $this->report();
    $oldRevision = (int) $node->getRevisionId();
    $oldNotes = array_column($node->get('field_status_notes')->getValue(), 'target_id');
    $this->now += 60;
    $this->append($node);
    $saved = $this->reload($node);
    $this->assertSame($this->now, (int) $saved->getChangedTime());
    $this->assertGreaterThan($oldRevision, (int) $saved->getRevisionId());
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame($oldNotes, array_column($storage->loadRevision($oldRevision)->get('field_status_notes')->getValue(), 'target_id'));
    $this->assertCount(2, $storage->loadRevision($saved->getRevisionId())->get('field_status_notes'));
  }

  /**
   * A no-op save keeps changed while the existing policy writes a revision.
   */
  public function testNoOpSaveKeepsChangedTime(): void {
    $node = $this->report();
    $before = (int) $node->getChangedTime();
    $revision = (int) $node->getRevisionId();
    $this->now += 60;
    $node->save();
    $node = $this->reload($node);
    $this->assertSame($before, (int) $node->getChangedTime());
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
  }

  /**
   * Explicit syncing skips the extra timestamp rule.
   */
  public function testSyncingAdditionKeepsChangedTime(): void {
    $node = $this->report();
    $before = (int) $node->getChangedTime();
    $this->now += 60;
    $node->setSyncing(TRUE);
    $this->append($node);
    $this->assertSame($before, (int) $this->reload($node)->getChangedTime());
  }

  /**
   * Removing a note retains core's changed-time update for a shorter list.
   */
  public function testRemovalRetainsCoreChangedTimeBehaviour(): void {
    $node = $this->report();
    $this->now += 60;
    $node->get('field_status_notes')->removeItem(0);
    $node->save();
    $saved = $this->reload($node);
    $this->assertSame($this->now, (int) $saved->getChangedTime());
    $this->assertCount(0, $saved->get('field_status_notes'));
  }

  /**
   * An unsaved form paragraph counts as an addition.
   */
  public function testUnsavedParagraphAddition(): void {
    $node = $this->report();
    $this->now += 60;
    $paragraph = $this->note(FALSE);
    $this->assertNull($paragraph->id());
    $node->get('field_status_notes')->appendItem(['entity' => $paragraph]);
    $node->save();
    $saved = $this->reload($node);
    $this->assertSame($this->now, (int) $saved->getChangedTime());
    $this->assertCount(2, $saved->get('field_status_notes'));
    $this->assertNotEmpty($saved->get('field_status_notes')->last()->target_id);
  }

  /**
   * The automatic note is detected after the status-update handler runs.
   */
  public function testAutomaticStatusNoteOverridesExplicitOldChangedTime(): void {
    $node = $this->report();
    $next = Term::create(['vid' => 'service_status', 'name' => 'In progress', 'description' => 'Work started.']);
    $next->save();
    $processor = $this->getMockBuilder(GeoreportProcessorService::class)
      ->disableOriginalConstructor()->onlyMethods(['resolveNodeJurisdictionId'])->getMock();
    foreach (['entityTypeManager' => 'entity_type.manager', 'languageManager' => 'language_manager'] as $property => $service) {
      (new \ReflectionProperty(GeoreportProcessorService::class, $property))->setValue($processor, $this->container->get($service));
    }
    $this->container->set('markaspot_open311.processor', $processor);
    $this->container->get('config.factory')->getEditable('markaspot_open311.settings')->set('status_note_auto_create', TRUE)->save();
    $node->set('field_status', $next->id());
    // Core honors an explicit timestamp, so only the added-note rule moves it.
    $node->setChangedTime($this->now - 200);
    $this->now += 60;
    $node->save();
    $saved = $this->reload($node);
    $this->assertSame($this->now, (int) $saved->getChangedTime());
    $this->assertCount(2, $saved->get('field_status_notes'));
    $this->assertSame((string) $next->id(), (string) $saved->get('field_status_notes')->last()->entity->get('field_status_term')->target_id);
  }

  /**
   * First notes update existing reports, but creation keeps caller timestamps.
   */
  public function testFirstNoteAndNewReport(): void {
    $node = $this->report(FALSE);
    $this->now += 60;
    $this->append($node);
    $this->assertSame($this->now, (int) $this->reload($node)->getChangedTime());
    $new = Node::create([
      'type' => 'service_request', 'title' => 'New report',
      'changed' => $this->now - 500, 'field_status' => $this->status->id(),
      'field_status_notes' => [['entity' => $this->note(FALSE)]],
    ]);
    $new->save();
    $this->assertSame($this->now - 500, (int) $this->reload($new)->getChangedTime());
  }

  /**
   * A new revision of the same paragraph is not an added paragraph ID.
   */
  public function testExistingParagraphRevisionIsNotAnAddition(): void {
    $node = $this->report();
    $before = (int) $node->getChangedTime();
    $note = $node->get('field_status_notes')->first()->entity;
    $note->setNewRevision(TRUE);
    $note->save();
    $node->get('field_status_notes')->setValue([
      ['target_id' => $note->id(), 'target_revision_id' => $note->getRevisionId()],
    ]);
    $this->now += 60;
    $node->save();
    $this->assertSame($before, (int) $this->reload($node)->getChangedTime());
  }

  /**
   * A shared note added on a non-default translation updates all languages.
   */
  public function testNonDefaultTranslationUpdatesAllChangedTimes(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $node = $this->report();
    $note = $node->get('field_status_notes')->first()->entity;
    $note->addTranslation('de', $note->toArray());
    $note->save();
    $node->addTranslation('de', ['title' => 'Translated report', 'changed' => $this->now - 200]);
    $node->save();
    $node = $this->reload($node);
    $this->now += 60;
    $this->append($node->getTranslation('de'));
    $saved = $this->reload($node);
    foreach (['en', 'de'] as $langcode) {
      $this->assertSame($this->now, (int) $saved->getTranslation($langcode)->getChangedTime(), $langcode);
    }
  }

  /**
   * Request-id repair keeps its existing early return.
   */
  public function testRenumberingSkipsAddedNoteRule(): void {
    $node = $this->report();
    $before = (int) $node->getChangedTime();
    $node->_markaspot_request_id_renumbering = 1;
    $this->now += 60;
    $this->append($node);
    $this->assertSame($before, (int) $this->reload($node)->getChangedTime());
  }

  /**
   * A minimal service-request bundle without notes remains saveable.
   */
  public function testMissingNoteFieldIsSkipped(): void {
    FieldConfig::loadByName('node', 'service_request', 'field_status_notes')->delete();
    $node = $this->report(FALSE);
    $before = (int) $node->getChangedTime();
    $this->now += 60;
    $node->save();
    $this->assertFalse($node->hasField('field_status_notes'));
    $this->assertSame($before, (int) $this->reload($node)->getChangedTime());
  }

  /**
   * A recent note removes an unpublished report from publisher candidates.
   */
  public function testNotePostponesPublisherCandidateSelection(): void {
    $node = $this->oldReport();
    $config = $this->container->get('config.factory');
    $config->getEditable('markaspot_publisher.settings')
      ->set('default_days', 30)->set('manual_unpublish_threshold', 6)
      ->set('status_publishable', [$this->status->id()])
      ->set('days', [$this->category->id() => 30])->save();
    $publisher = new PublisherService($this->container->get('entity_type.manager'), $config);
    $this->assertArrayHasKey($node->id(), $publisher->load());
    $this->now += 60;
    $this->append($node);
    $this->assertArrayNotHasKey($node->id(), $publisher->load());
    $this->assertFalse($this->reload($node)->isPublished());
  }

  /**
   * A recent note removes an inactive report from resubmission selection.
   */
  public function testNotePostponesResubmissionSelection(): void {
    $node = $this->oldReport();
    $config = $this->container->get('config.factory');
    $config->getEditable('markaspot_resubmission.settings')
      ->set('default_resubmission_days', 42)
      ->set('status_resubmissive', [$this->status->id()])->save();
    $reminders = $this->createMock(ReminderManager::class);
    $reminders->method('shouldSendReminder')->willReturn(TRUE);
    $resubmission = new ResubmissionService($this->container->get('entity_type.manager'), $config, $reminders);
    $this->assertSame([$node->id()], array_map(static fn(NodeInterface $candidate) => $candidate->id(), $resubmission->load()));
    $this->now += 60;
    $this->append($node);
    $this->assertSame([], $resubmission->load());
  }

  /**
   * Creates and reloads a report with a translated, unchanged first note.
   */
  private function report(bool $withNote = TRUE, array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'service_request', 'title' => 'Broken bench', 'langcode' => 'en',
      'status' => 0, 'created' => $this->now - 100, 'changed' => $this->now - 100,
      'field_status' => $this->status->id(), 'field_category' => $this->category->id(),
    ]);
    if ($withNote) {
      $node->get('field_status_notes')->appendItem(['entity' => $this->note(FALSE)]);
    }
    $node->save();
    return $this->reload($node);
  }

  /**
   * A report old enough for both timer selectors, but with one existing note.
   */
  private function oldReport(): NodeInterface {
    return $this->report(TRUE, ['created' => time() - 60 * 86400, 'changed' => time() - 60 * 86400]);
  }

  /**
   * Creates a same-language note, saved if requested.
   */
  private function note(bool $save = TRUE): Paragraph {
    $note = Paragraph::create([
      'type' => 'status', 'langcode' => 'en', 'created' => $this->now - 100,
      'field_status_term' => $this->status->id(), 'field_status_note' => 'Site visited.',
    ]);
    if ($save) {
      $note->save();
    }
    return $note;
  }

  /**
   * Appends a saved note through the ordinary node-save path.
   */
  private function append(NodeInterface $node): void {
    $note = $this->note();
    $node->get('field_status_notes')->appendItem([
      'target_id' => $note->id(),
      'target_revision_id' => $note->getRevisionId(),
    ]);
    $node->save();
  }

  /**
   * Reloads a report without the entity memory cache.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Adds a configurable field to the fixture.
   */
  private function field(string $entityType, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'entity_type' => $entityType, 'field_name' => $name, 'type' => $type,
      'settings' => $settings, 'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entityType, 'bundle' => $bundle, 'field_name' => $name,
    ])->save();
  }

}
