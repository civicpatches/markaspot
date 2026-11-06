<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot\Access\SystemWriteContext;
use Drupal\markaspot_dashboard\Controller\StatusNoteController;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\service_request\StatusNoteRequirement;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the per-status "Note required" switch on all status write paths.
 *
 * The test*Validation* method needs the field_status constraint that
 * service_request_entity_bundle_field_info_alter() of this checkout adds.
 * JSON:API (dashboard form PATCH, bulk edit) and GeoReport updates
 * (GeoreportRequestResource::validate()) both run exactly this entity
 * validation before they save. The other methods use the rule class and the
 * dashboard note endpoint directly.
 */
#[Group('service_request')]
#[CoversClass(StatusNoteRequirement::class)]
#[CoversClass(StatusNoteController::class)]
#[RunTestsInSeparateProcesses]
final class StatusNoteRequiredKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'node',
    'taxonomy',
    'entity_reference_revisions',
    'paragraphs',
    'service_request',
  ];

  /**
   * Status that does not ask for a note.
   */
  private TermInterface $open;

  /**
   * Status with "Note required" switched on.
   */
  private TermInterface $rejected;

  /**
   * A report in status "open".
   */
  private NodeInterface $report;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    // No automatic status notes: the test controls every paragraph.
    $this->container->get('config.storage')->write(
      'markaspot_open311.settings',
      ['status_note_auto_create' => FALSE],
    );

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    $this->createField('taxonomy_term', 'service_status', StatusNoteRequirement::FIELD, 'boolean');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->open = $this->createStatus('Open', FALSE);
    $this->rejected = $this->createStatus('Rejected', TRUE);
    $this->report = Node::create([
      'type' => 'service_request',
      'title' => 'Broken bench',
      'field_status' => $this->open->id(),
    ]);
    $this->report->save();
  }

  /**
   * The rule finds a change to a note-required status without a new note.
   */
  public function testRuleRequiresNewNoteWithText(): void {
    $rule = $this->rule();
    $this->assertSame((int) $this->rejected->id(), (int) $rule->requiredTerm($this->rejected->id())?->id());
    $this->assertNull($rule->requiredTerm($this->open->id()));
    $this->assertNull($rule->requiredTerm(NULL));

    $report = $this->reload();
    $report->set('field_status', $this->rejected->id());
    $this->assertSame((int) $this->rejected->id(), (int) $rule->missingNote($report)?->id(), 'No note at all.');

    foreach (['', '   ', '<p> </p>', "&nbsp;\n"] as $empty) {
      $candidate = $this->reload();
      $candidate->set('field_status', $this->rejected->id());
      $this->appendNote($candidate, $empty, $this->rejected);
      $this->assertNotNull($rule->missingNote($candidate), sprintf('Note %s does not count.', json_encode($empty)));
    }

    $wrongStatus = $this->reload();
    $wrongStatus->set('field_status', $this->rejected->id());
    $this->appendNote($wrongStatus, 'Explained, but for another status.', $this->open);
    $this->assertNotNull($rule->missingNote($wrongStatus));

    $explained = $this->reload();
    $explained->set('field_status', $this->rejected->id());
    $this->appendNote($explained, 'Private ground, not ours to fix.', $this->rejected);
    $this->assertNull($rule->missingNote($explained));

    // A saved paragraph that the report did not reference before also counts.
    $saved = $this->reload();
    $saved->set('field_status', $this->rejected->id());
    $this->appendNote($saved, 'Saved before the report.', $this->rejected, TRUE);
    $this->assertNull($rule->missingNote($saved));

    // An earlier note does not explain a new change.
    $explained->save();
    $back = $this->reload();
    $back->set('field_status', $this->open->id());
    $back->save();
    $again = $this->reload();
    $again->set('field_status', $this->rejected->id());
    $this->assertNotNull($rule->missingNote($again), 'The note of the first rejection does not count again.');
  }

  /**
   * Unchanged statuses, other statuses and new reports pass.
   */
  public function testRuleIgnoresUnchangedStatusAndCreation(): void {
    $rule = $this->rule();

    $rejectedWithNote = $this->reload();
    $rejectedWithNote->set('field_status', $this->rejected->id());
    $this->appendNote($rejectedWithNote, 'Rejected for a reason.', $this->rejected);
    $rejectedWithNote->save();

    $edit = $this->reload();
    $edit->setTitle('Only the title changes');
    $this->assertNull($rule->missingNote($edit), 'Staying in a note-required status needs no new note.');

    $toOpen = $this->reload();
    $toOpen->set('field_status', $this->open->id());
    $this->assertNull($rule->missingNote($toOpen));

    $new = Node::create([
      'type' => 'service_request',
      'title' => 'Created in a required status',
      'field_status' => $this->rejected->id(),
    ]);
    $this->assertNull($rule->missingNote($new), 'Creating a report is not a status change.');
  }

  /**
   * Trusted system runs and synchronised content are exempt.
   */
  public function testSystemContextsAreExempt(): void {
    $report = $this->reload();
    $report->set('field_status', $this->rejected->id());

    $this->container->set('markaspot.system_write_context', new SystemWriteContext($this->container->get('kernel'), 'cli'));
    $this->assertNull($this->rule()->missingNote($report), 'drush, cron and updatedb are exempt.');

    $this->container->set('markaspot.system_write_context', new SystemWriteContext($this->container->get('kernel'), 'fpm-fcgi'));
    $this->assertNotNull($this->rule()->missingNote($report), 'Web requests are not exempt.');

    $report->setSyncing(TRUE);
    $this->assertNull($this->rule()->missingNote($report), 'Content synchronisation is exempt.');
  }

  /**
   * Entity validation rejects the change without a note and accepts it with.
   *
   * JSON:API and GeoReport updates validate the report like this before they
   * save. Needs the field_status constraint of this checkout.
   */
  public function testValidationRejectsMissingNote(): void {
    $report = $this->reload();
    $report->set('field_status', $this->rejected->id());
    $violations = $report->validate()->getByField('field_status');
    $this->assertCount(1, $violations);
    $this->assertSame(StatusNoteRequirement::ERROR_CODE, $violations[0]->getCode());
    $this->assertStringContainsString('Rejected', (string) $violations[0]->getMessage());
    $this->assertStringContainsString('requires a status note', (string) $violations[0]->getMessage());

    $this->appendNote($report, 'Private ground.', $this->rejected);
    $this->assertCount(0, $report->validate()->getByField('field_status'));

    $toOpen = $this->reload();
    $toOpen->set('field_status', $this->open->id());
    $this->assertCount(0, $toOpen->validate()->getByField('field_status'));

    $this->container->set('markaspot.system_write_context', new SystemWriteContext($this->container->get('kernel'), 'cli'));
    $system = $this->reload();
    $system->set('field_status', $this->rejected->id());
    $this->assertCount(0, $system->validate()->getByField('field_status'));
  }

  /**
   * The dashboard note endpoint rejects an empty note before writing anything.
   */
  public function testDashboardNoteEndpoint(): void {
    $controller = $this->controller();
    $paragraphs = $this->container->get('entity_type.manager')->getStorage('paragraph');

    $response = $controller->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->rejected->uuid(),
      'note' => '  ',
    ]));
    $this->assertSame(422, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(StatusNoteRequirement::ERROR_CODE, $body['code']);
    $this->assertSame($this->rejected->uuid(), $body['status_term_uuid']);
    $this->assertStringContainsString('Rejected', $body['error']);
    $this->assertSame((int) $this->open->id(), (int) $this->reload()->get('field_status')->target_id, 'The status did not change.');
    $this->assertSame(0, (int) $paragraphs->getQuery()->accessCheck(FALSE)->count()->execute(), 'No paragraph was written.');

    // A status that needs no note keeps today's behaviour.
    $response = $controller->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->open->uuid(),
    ]));
    $this->assertSame(201, $response->getStatusCode());

    $response = $controller->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->rejected->uuid(),
      'note' => 'Private ground, not ours to fix.',
    ]));
    $this->assertSame(201, $response->getStatusCode());
    $report = $this->reload();
    $this->assertSame((int) $this->rejected->id(), (int) $report->get('field_status')->target_id);
    $noteUuid = json_decode((string) $response->getContent(), TRUE)['uuid'];

    // Emptying that note later is refused, rewording it is fine.
    $response = $controller->update($this->request(['note' => '']), $noteUuid);
    $this->assertSame(422, $response->getStatusCode());
    $response = $controller->update($this->request(['note' => 'Private ground of the railway.']), $noteUuid);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * The note endpoint counts a boilerplate only by the text it stores.
   *
   * Review #330 F1: a stale, unpublished or empty boilerplate must not let a
   * note-required status through with the status description as its note.
   * A note of "0" is text and is stored as written.
   */
  public function testDashboardNoteEndpointResolvesTheBoilerplate(): void {
    NodeType::create(['type' => 'boilerplate', 'name' => 'Boilerplate'])->save();
    $this->createField('node', 'boilerplate', 'body', 'text_with_summary');
    $boilerplate = function (string $body, bool $published = TRUE): NodeInterface {
      $node = Node::create([
        'type' => 'boilerplate',
        'title' => 'Boilerplate',
        'status' => $published ? 1 : 0,
        'body' => ['value' => $body, 'format' => 'plain_text'],
      ]);
      $node->save();
      return $node;
    };
    $valid = $boilerplate('<p>Private ground, not ours to fix.</p>');
    $unpublished = $boilerplate('Outdated wording.', FALSE);
    $empty = $boilerplate('');
    $deleted = $boilerplate('Deleted wording.');
    $staleUuid = $deleted->uuid();
    $deleted->delete();
    // With a description the factory's last fallback would produce text.
    $this->rejected->setDescription('Rejected by the city.')->save();
    $closed = $this->createStatus('Closed', TRUE);
    $closed->setDescription('Closed by the city.')->save();

    $controller = $this->controller();
    $paragraphs = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $cases = [
      'stale uuid' => $staleUuid,
      'unpublished boilerplate' => $unpublished->uuid(),
      'boilerplate with empty body' => $empty->uuid(),
    ];
    foreach ($cases as $case => $uuid) {
      $response = $controller->add($this->request([
        'request_uuid' => $this->report->uuid(),
        'status_term_uuid' => $this->rejected->uuid(),
        'note' => '',
        'boilerplate_uuid' => $uuid,
      ]));
      $this->assertSame(422, $response->getStatusCode(), $case);
      $this->assertSame(StatusNoteRequirement::ERROR_CODE, json_decode((string) $response->getContent(), TRUE)['code'], $case);
      $this->assertSame((int) $this->open->id(), (int) $this->reload()->get('field_status')->target_id, $case . ': the status did not change.');
      $this->assertSame(0, (int) $paragraphs->getQuery()->accessCheck(FALSE)->count()->execute(), $case . ': no paragraph was written.');
    }

    // A published boilerplate with text is the note.
    $response = $controller->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $this->rejected->uuid(),
      'note' => '',
      'boilerplate_uuid' => $valid->uuid(),
    ]));
    $this->assertSame(201, $response->getStatusCode());
    $report = $this->reload();
    $this->assertSame((int) $this->rejected->id(), (int) $report->get('field_status')->target_id);
    $this->assertSame('Private ground, not ours to fix.', $this->lastNoteText($report));

    // "0" is the author's note, not a reason to fall back to the description.
    $response = $controller->add($this->request([
      'request_uuid' => $this->report->uuid(),
      'status_term_uuid' => $closed->uuid(),
      'note' => '0',
    ]));
    $this->assertSame(201, $response->getStatusCode());
    $report = $this->reload();
    $this->assertSame((int) $closed->id(), (int) $report->get('field_status')->target_id);
    $this->assertSame('0', $this->lastNoteText($report));
  }

  /**
   * The note text of the last status note of a report.
   */
  private function lastNoteText(NodeInterface $report): ?string {
    $items = $report->get('field_status_notes');
    $last = $items->get($items->count() - 1)?->entity;
    $this->assertInstanceOf(ParagraphInterface::class, $last);
    return $last->get('field_status_note')->value;
  }

  /**
   * The rule under test.
   */
  private function rule(): StatusNoteRequirement {
    return new StatusNoteRequirement($this->container->get('entity_type.manager'), $this->container);
  }

  /**
   * Builds the dashboard note controller with the real paragraph factory.
   *
   * Only the jurisdiction lookup of the processor is stubbed; the factory
   * (note, boilerplate and status description fallback) is the real one.
   */
  private function controller(): StatusNoteController {
    $processor = $this->getMockBuilder(GeoreportProcessorService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['resolveNodeJurisdictionId'])
      ->getMock();
    $processor->method('resolveNodeJurisdictionId')->willReturn(NULL);
    foreach (['entityTypeManager' => 'entity_type.manager', 'languageManager' => 'language_manager'] as $property => $service) {
      (new \ReflectionProperty(GeoreportProcessorService::class, $property))
        ->setValue($processor, $this->container->get($service));
    }
    $scope = $this->createMock(StatusTermScope::class);
    $scope->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => Term::loadMultiple(
        \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
          ->condition('uuid', $properties['uuid'])
          ->execute()
      )
    );
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('hasPermission')->willReturn(TRUE);

    return new StatusNoteController(
      $this->container->get('entity_type.manager'),
      $processor,
      $account,
      $this->createMock(FeatureFlagChecker::class),
      $scope,
      $this->rule(),
    );
  }

  /**
   * A JSON request.
   */
  private function request(array $data): Request {
    return Request::create('/api/dashboard/status-notes', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
  }

  /**
   * Appends a status note paragraph to a report.
   */
  private function appendNote(NodeInterface $report, string $text, TermInterface $status, bool $save = FALSE): ParagraphInterface {
    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_term' => $status->id(),
      'field_status_note' => ['value' => $text, 'format' => 'plain_text'],
    ]);
    if ($save) {
      $paragraph->save();
    }
    $report->get('field_status_notes')->appendItem($paragraph);
    return $paragraph;
  }

  /**
   * Creates a status term.
   */
  private function createStatus(string $name, bool $noteRequired): TermInterface {
    $term = Term::create([
      'vid' => 'service_status',
      'name' => $name,
      StatusNoteRequirement::FIELD => $noteRequired,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Reloads the report without the static cache.
   */
  private function reload(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->report->id()]);
    $report = $storage->load($this->report->id());
    $this->assertInstanceOf(NodeInterface::class, $report);
    return $report;
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => $storageSettings,
    ])->save();
  }

}
