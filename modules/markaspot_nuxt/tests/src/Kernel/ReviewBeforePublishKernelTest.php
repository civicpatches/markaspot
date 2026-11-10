<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Privacy\NameScreeningResult;
use Drupal\markaspot_ai\Privacy\PiiScreeningHold;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\markaspot_publisher\Plugin\QueueWorker\PublisherQueueWorker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the root-only review-before-publish hold for new reports.
 *
 * The test*Hook* methods save reports through the regular entity hooks, the
 * way the citizen form (JSON:API) and GeoReport POST do, so they need the
 * hook implementations of this checkout. The other methods call the service
 * directly. The service always comes from markaspot_nuxt.services.yml.
 * Process isolation is required for kernel tests since Drupal 11.3.
 */
#[TestGroup('markaspot_nuxt')]
#[CoversClass(ReviewBeforePublish::class)]
#[RunTestsInSeparateProcesses]
final class ReviewBeforePublishKernelTest extends KernelTestBase {

  use ContentModerationTestTrait;

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
    // markaspot_nuxt's node load hook reads image styles on JSON:API routes.
    'image',
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
    'markaspot_publisher',
    // After markaspot_group, like the alphabetical order in production.
    'service_request',
  ];

  /**
   * Root jurisdiction of tenant A.
   */
  private GroupInterface $tenantA;

  /**
   * Sub-jurisdiction below tenant A.
   */
  private GroupInterface $districtA;

  /**
   * Root jurisdiction of tenant B.
   */
  private GroupInterface $tenantB;

  /**
   * Categories keyed by tenantA, districtA and tenantB.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $categories = [];

  /**
   * Initial status terms keyed like the categories.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $statuses = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();

    $this->tenantA = $this->createJurisdiction('Tenant A');
    $this->districtA = $this->createJurisdiction('District A', $this->tenantA);
    $this->tenantB = $this->createJurisdiction('Tenant B');
    foreach (['tenantA', 'districtA', 'tenantB'] as $key) {
      $jurisdiction = $this->{$key};
      $this->categories[$key] = $this->createTerm('service_category', 'Category ' . $key, $jurisdiction);
      $this->statuses[$key] = $this->createTerm('service_status', 'Open ' . $key, $jurisdiction, 'initial');
    }

    // Written to storage directly: the publisher settings have no schema.
    $statusIds = array_map(static fn(TermInterface $term): int => (int) $term->id(), $this->statuses);
    $categoryIds = array_map(static fn(TermInterface $term): int => (int) $term->id(), $this->categories);
    $this->container->get('config.storage')->write('markaspot_publisher.settings', [
      'status_publishable' => array_combine($statusIds, $statusIds),
      'days' => array_fill_keys($categoryIds, 30),
      'default_days' => 30,
      'manual_unpublish_threshold' => 6,
    ]);
  }

  /**
   * Without the flag a new report keeps the content type's publish default.
   */
  public function testFlagOffChangesNothing(): void {
    $node = $this->newReport('tenantA');
    $this->assertFalse($this->service()->holdNewReport($node));
    $this->assertTrue($node->isPublished());

    // An unpublished default stays unpublished, but is not held.
    $node = $this->newReport('tenantA', FALSE);
    $this->assertFalse($this->service()->holdNewReport($node));
    $this->assertFalse($node->isPublished());
  }

  /**
   * The root flag holds every new report of the tenant, and only that tenant.
   */
  public function testRootFlagHoldsWholeTenantOnly(): void {
    $this->setFlag($this->tenantA, TRUE);

    foreach (['tenantA', 'districtA'] as $key) {
      $node = $this->newReport($key);
      $this->assertTrue($this->service()->holdNewReport($node), $key);
      $this->assertFalse($node->isPublished(), $key);
    }

    $foreign = $this->newReport('tenantB');
    $this->assertFalse($this->service()->holdNewReport($foreign));
    $this->assertTrue($foreign->isPublished(), 'The flag of tenant A does not reach tenant B.');

    // A report that only names the root in field_jurisdiction is held too.
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'No category',
      'status' => 1,
      'field_jurisdiction' => [$this->tenantA->id()],
    ]);
    $this->assertTrue($this->service()->holdNewReport($node));
  }

  /**
   * The flag is tenant-scoped: a value stored on a child does not count.
   */
  public function testChildValueIsIgnored(): void {
    $this->setFlag($this->districtA, TRUE);
    $node = $this->newReport('districtA');
    $this->assertFalse($this->service()->holdNewReport($node));
    $this->assertTrue($node->isPublished());
  }

  /**
   * Existing reports and synchronised content are never held.
   */
  public function testExistingAndSyncingReportsAreNotHeld(): void {
    $existing = $this->newReport('tenantA');
    $existing->save();
    $this->setFlag($this->tenantA, TRUE);
    $existing = $this->reload($existing);
    $this->assertFalse($this->service()->holdNewReport($existing));

    $syncing = $this->newReport('tenantA');
    $syncing->setSyncing(TRUE);
    $this->assertFalse($this->service()->holdNewReport($syncing));
    $this->assertTrue($syncing->isPublished());
  }

  /**
   * The hold is recorded on insert, read by the publisher query, released.
   */
  public function testHoldLifecycleAndPublisherQuery(): void {
    $service = $this->service();
    $this->setFlag($this->tenantA, TRUE);
    $held = $this->newReport('tenantA');
    $service->holdNewReport($held);
    $held->save();
    $service->recordHold($held);
    $other = $this->newReport('tenantB', FALSE);
    $other->save();

    $this->assertTrue($service->isHeld($held));
    $this->assertFalse($service->isHeld($other));
    $this->assertSame([(int) $held->id()], $service->heldIds());

    $query = $this->container->get('database')->select('node', 'base_table')
      ->fields('base_table', ['nid']);
    $service->excludeHeldReports($query);
    $this->assertSame([(int) $other->id()], array_map('intval', $query->execute()->fetchCol()));

    // A held report that stays unpublished keeps its hold.
    $held = $this->reload($held);
    $held->setTitle('Edited')->save();
    $service->releaseOnPublish($held);
    $this->assertTrue($service->isHeld($held));

    $held = $this->reload($held);
    $held->setPublished()->save();
    $service->releaseOnPublish($held);
    $this->assertFalse($service->isHeld($held));
    $this->assertSame([], $service->heldIds());

    $query = $this->container->get('database')->select('node', 'base_table')
      ->fields('base_table', ['nid']);
    $service->excludeHeldReports($query);
    $this->assertCount(2, $query->execute()->fetchCol());
  }

  /**
   * Citizen form and GeoReport creates are held with the flag on, not off.
   *
   * Needs the hook implementations of this checkout.
   */
  #[DataProvider('createPathProvider')]
  public function testHookCreatePathsHoldOnlyWithFlag(string $route): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $request = Request::create('/create', 'POST');
    $request->attributes->set('_route', $route);
    $this->container->get('request_stack')->push($request);

    try {
      $open = $this->newReport('tenantA');
      $open->save();
      $open = $this->reload($open);
      $this->assertTrue($open->isPublished(), 'Flag off: the content type default applies.');
      $this->assertFalse($this->service()->isHeld($open));

      $this->setFlag($this->tenantA, TRUE);
      foreach (['tenantA', 'districtA'] as $key) {
        $held = $this->newReport($key);
        $held->save();
        $held = $this->reload($held);
        $this->assertFalse($held->isPublished(), $key . ': saved unpublished.');
        $this->assertTrue($this->service()->isHeld($held), $key . ': hold recorded.');
      }

      $foreign = $this->newReport('tenantB');
      $foreign->save();
      $foreign = $this->reload($foreign);
      $this->assertTrue($foreign->isPublished(), 'Tenant B is not affected by tenant A.');
      $this->assertFalse($this->service()->isHeld($foreign));
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Routes of the citizen form create and the GeoReport POST.
   */
  public static function createPathProvider(): array {
    return [
      'JSON:API citizen form' => ['jsonapi.node--service_request.collection.post'],
      'GeoReport POST' => ['rest.georeport_request_index_resource.POST'],
    ];
  }

  /**
   * The publisher skips held reports and publishes the others.
   *
   * Needs the hook implementations of this checkout.
   */
  public function testHookPublisherSkipsHeldReports(): void {
    $past = $this->container->get('datetime.time')->getRequestTime() - 40 * 86400;

    $this->setFlag($this->tenantA, TRUE);
    $held = $this->newReport('tenantA', TRUE, $past);
    $held->save();
    $this->setFlag($this->tenantA, FALSE);
    $waiting = $this->newReport('tenantB', FALSE, $past);
    $waiting->save();
    // Post-insert normalisation may save the reports again and move their
    // changed time; the publisher only takes reports untouched since intake.
    $this->container->get('database')->update('node_field_data')
      ->fields(['created' => $past, 'changed' => $past])
      ->condition('nid', [$held->id(), $waiting->id()], 'IN')
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();

    $this->assertTrue($this->service()->isHeld($this->reload($held)));
    $candidates = array_map('intval', array_keys(
      $this->container->get('markaspot_publisher.publisher')->load()
    ));
    $this->assertSame([(int) $waiting->id()], $candidates, 'Only the report without a hold is a publication candidate.');

    // Switching the flag off later does not release reports held earlier.
    $this->assertTrue($this->service()->isHeld($this->reload($held)));
  }

  /**
   * A hold written after enqueueing is read despite the cached candidate list.
   */
  public function testWorkerRechecksReviewHoldAfterEnqueue(): void {
    $node = $this->newReport('tenantA', FALSE);
    $node->save();
    $node = $this->backdatePublisherReport($node);

    markaspot_publisher_cron();
    $this->assertSame([], $this->service()->heldIds(), 'The candidates query cached an empty hold list.');
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame((int) $node->id(), (int) $item->data['nid']);

    // Another process writes the hold without invalidating this service's memo.
    $this->container->get('keyvalue')->get(ReviewBeforePublish::COLLECTION)->set((string) $node->id(), [
      'created' => $this->container->get('datetime.time')->getRequestTime(),
    ]);
    $this->assertSame([], $this->service()->heldIds());
    $this->assertWorkerHonoursReviewHold($node, $item->data);
    $queue->deleteItem($item);
  }

  /**
   * A production insert hold survives a stale item until explicit release.
   */
  public function testWorkerPreservesReviewHoldFromInsert(): void {
    $this->setFlag($this->tenantA, TRUE);
    $node = $this->newReport('tenantA');
    $node->save();
    $node = $this->backdatePublisherReport($node);
    $this->assertFalse($node->isPublished());
    $this->assertTrue($this->service()->isHeld($node));

    $this->assertWorkerHonoursReviewHold($node, ['nid' => $node->id()]);
  }

  /**
   * The review hook preserves earlier vetoes and accepts unheld bundles.
   */
  public function testReviewAlterPreservesEarlierVeto(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    foreach (['service_request', 'page'] as $bundle) {
      $node = Node::create(['type' => $bundle, 'title' => 'Unheld node']);
      $this->assertPublisherHold(FALSE, $node);
      $held = TRUE;
      $this->container->get('module_handler')->alter('markaspot_publisher_hold', $held, $node);
      $this->assertTrue($held);
    }
  }

  /**
   * Both hold implementations must release before the same item can publish.
   */
  #[DataProvider('holdReleaseOrderProvider')]
  public function testWorkerComposesScreeningAndReviewHolds(bool $releaseScreeningFirst): void {
    $this->enableModules(['markaspot_ai']);
    // Enabling a module rebuilds the container and drops the processor stub.
    $this->stubProcessor();
    $this->setFlag($this->tenantA, TRUE);
    $node = $this->newReport('tenantA');
    $node->save();
    $node = $this->backdatePublisherReport($node);
    PiiScreeningHold::place($node, NameScreeningResult::REASON_UNAVAILABLE);
    $this->assertTrue(PiiScreeningHold::isHeld($node));
    $this->assertTrue($this->service()->isHeld($node));
    $revision = $node->getRevisionId();
    $item = ['nid' => $node->id()];
    $worker = $this->publisherWorker();

    $this->assertPublisherHold(TRUE, $node);
    $worker->processItem($item);
    $node = $this->reload($node);
    $this->assertFalse($node->isPublished());
    $this->assertSame($revision, $node->getRevisionId());
    $this->assertTrue(PiiScreeningHold::isHeld($node));
    $this->assertTrue($this->service()->isHeld($node));

    if ($releaseScreeningFirst) {
      PiiScreeningHold::release($node);
    }
    else {
      $this->service()->release($node);
    }
    $this->assertPublisherHold(TRUE, $node);
    $worker->processItem($item);
    $node = $this->reload($node);
    $this->assertFalse($node->isPublished(), 'One remaining veto is sufficient.');
    $this->assertSame($revision, $node->getRevisionId());
    $this->assertSame(!$releaseScreeningFirst, PiiScreeningHold::isHeld($node));
    $this->assertSame($releaseScreeningFirst, $this->service()->isHeld($node));

    PiiScreeningHold::release($node);
    $this->service()->release($node);
    $this->assertPublisherHold(FALSE, $node);
    $worker->processItem($item);
    $node = $this->reload($node);
    $this->assertTrue($node->isPublished(), 'Only release of every veto permits publication.');
    $this->assertFalse(PiiScreeningHold::isHeld($node));
    $this->assertFalse($this->service()->isHeld($node));
  }

  /**
   * Exercises either hold as the last remaining veto.
   */
  public static function holdReleaseOrderProvider(): array {
    return [
      'screening released first' => [TRUE],
      'review released first' => [FALSE],
    ];
  }

  /**
   * Backdates a report after insert hooks have finished their saves.
   */
  private function backdatePublisherReport(NodeInterface $node): NodeInterface {
    $past = $this->container->get('datetime.time')->getRequestTime() - 40 * 86400;
    $this->container->get('database')->update('node_field_data')
      ->fields(['created' => $past, 'changed' => $past])
      ->condition('nid', $node->id())
      ->execute();
    return $this->reload($node);
  }

  /**
   * Asserts the alter result directly, without the worker's exception handler.
   */
  private function assertPublisherHold(bool $expected, NodeInterface $node): void {
    $held = FALSE;
    $this->container->get('module_handler')->alter('markaspot_publisher_hold', $held, $node);
    $this->assertSame($expected, $held);
  }

  /**
   * Builds the real worker with assertions against swallowed exceptions.
   */
  private function publisherWorker(): PublisherQueueWorker {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('error');
    $logger->expects($this->never())->method('critical');
    return new PublisherQueueWorker(
      [], 'markaspot_publisher_queue_worker', [],
      $this->container->get('markaspot_publisher.publisher'),
      $logger,
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
    );
  }

  /**
   * Checks a review veto and publication of the same item after release.
   */
  private function assertWorkerHonoursReviewHold(NodeInterface $node, array $item): void {
    $this->assertPublisherHold(TRUE, $node);
    $revision = $node->getRevisionId();
    $worker = $this->publisherWorker();
    $worker->processItem($item);
    $node = $this->reload($node);
    $this->assertFalse($node->isPublished());
    $this->assertTrue($this->service()->isHeld($node));
    $this->assertSame($revision, $node->getRevisionId());

    $this->service()->release($node);
    $this->assertPublisherHold(FALSE, $node);
    $worker->processItem($item);
    $node = $this->reload($node);
    $this->assertTrue($node->isPublished(), 'The same item publishes after release.');
    $this->assertFalse($this->service()->isHeld($node));
  }

  /**
   * Publishing by hand releases the hold, deleting removes it.
   *
   * Needs the hook implementations of this checkout.
   */
  public function testHookManualPublishAndDeleteReleaseTheHold(): void {
    $this->setFlag($this->tenantA, TRUE);
    $published = $this->newReport('tenantA');
    $published->save();
    $deleted = $this->newReport('tenantA');
    $deleted->save();
    $this->assertCount(2, $this->service()->heldIds());

    $published = $this->reload($published);
    $published->setTitle('Reviewed')->save();
    $this->assertTrue($this->service()->isHeld($this->reload($published)), 'An edit without publishing keeps the hold.');

    $published = $this->reload($published);
    $published->setPublished()->save();
    $this->assertTrue($this->reload($published)->isPublished());
    $this->assertFalse($this->service()->isHeld($published));

    $deletedId = (int) $deleted->id();
    $this->reload($deleted)->delete();
    $this->assertNotContains($deletedId, $this->service()->heldIds());
    $this->assertSame([], $this->service()->heldIds());
  }

  /**
   * A single-root site holds reports that name no jurisdiction in presave.
   *
   * With several roots presave cannot tell the tenant; the insert-time check
   * covers that (see testHoldAfterInsertHoldsLateResolvedReport()).
   */
  public function testSingleRootFallbackResolvesTheOnlyRoot(): void {
    $this->setFlag($this->tenantA, TRUE);
    $orphan = $this->createTerm('service_category', 'Created without jurisdiction');

    $multiRoot = $this->newReport('tenantA');
    $multiRoot->set('field_category', $orphan->id());
    $this->assertFalse($this->service()->holdNewReport($multiRoot), 'Two roots: presave does not guess the tenant.');

    $this->makeSingleRoot();
    $withOrphanCategory = $this->newReport('tenantA');
    $withOrphanCategory->set('field_category', $orphan->id());
    $this->assertTrue($this->service()->holdNewReport($withOrphanCategory), 'Category without jurisdiction on a single-root site.');
    $this->assertFalse($withOrphanCategory->isPublished());

    $withoutCategory = $this->newReport('tenantA');
    $withoutCategory->set('field_category', NULL);
    $this->assertTrue($this->service()->holdNewReport($withoutCategory), 'Report without category on a single-root site.');
    $this->assertFalse($withoutCategory->isPublished());

    $this->setFlag($this->tenantA, FALSE);
    $open = $this->newReport('tenantA');
    $open->set('field_category', NULL);
    $this->assertFalse($this->service()->holdNewReport($open), 'The fallback only finds the tenant, the flag decides.');
  }

  /**
   * Single root: reports without a tenant in presave are saved held.
   *
   * Security review F1: a category without field_jurisdiction and a report
   * without category, created through the citizen form route. Needs the hook
   * implementations of this checkout.
   */
  public function testHookSingleRootReportsWithoutTenantAreHeld(): void {
    $this->makeSingleRoot();
    $this->setFlag($this->tenantA, TRUE);
    $orphan = $this->createTerm('service_category', 'Created without jurisdiction');
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $request = Request::create('/create', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $this->container->get('request_stack')->push($request);

    try {
      $withOrphanCategory = $this->newReport('tenantA');
      $withOrphanCategory->set('field_category', $orphan->id());
      $withOrphanCategory->save();
      $withoutCategory = $this->newReport('tenantA');
      $withoutCategory->set('field_category', NULL);
      $withoutCategory->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }

    foreach (['orphan category' => $withOrphanCategory, 'no category' => $withoutCategory] as $case => $node) {
      $node = $this->reload($node);
      $this->assertFalse($node->isPublished(), $case . ': saved unpublished.');
      $this->assertTrue($this->service()->isHeld($node), $case . ': hold recorded.');
      $this->assertSame((int) $this->tenantA->id(), (int) $node->get('field_jurisdiction')->target_id, $case . ': markaspot_group assigned the single root.');
    }
  }

  /**
   * A report whose tenant is only known after insert is held there.
   *
   * The insert hook calls holdAfterInsert() for reports presave did not
   * hold. Here it is called on a report that was saved published while its
   * tenant was unknown and then got field_jurisdiction, which is the state
   * markaspot_group leaves on insert. The hook path itself is covered by
   * testHookSingleRootReportsWithoutTenantAreHeld(): with the presave
   * fallback removed, that test still passes through this check alone.
   */
  public function testHoldAfterInsertHoldsLateResolvedReport(): void {
    $this->setFlag($this->tenantA, TRUE);
    $orphan = $this->createTerm('service_category', 'Created without jurisdiction');
    $node = $this->newReport('tenantA');
    $node->set('field_category', $orphan->id());
    $node->save();
    $node = $this->reload($node);
    $this->assertTrue($node->isPublished(), 'Two roots and no jurisdiction: not held in presave.');
    $this->assertFalse($this->service()->holdAfterInsert($node), 'Still no tenant: nothing to hold.');

    $node->set('field_jurisdiction', $this->districtA->id());
    $this->assertTrue($this->service()->holdAfterInsert($node));
    $this->assertFalse($node->isPublished());
    $stored = $this->reload($node);
    $this->assertFalse($stored->isPublished(), 'The unpublished state is saved.');
    $this->assertTrue($this->service()->isHeld($stored));
    $this->assertContains((int) $stored->id(), $this->service()->heldIds());
    $this->assertFalse($this->service()->holdAfterInsert($stored), 'A held report is not held twice.');

    // Tenant B does not review: a late-resolved report there stays published.
    $foreign = $this->newReport('tenantB');
    $foreign->set('field_category', $orphan->id());
    $foreign->save();
    $foreign = $this->reload($foreign);
    $foreign->set('field_jurisdiction', $this->tenantB->id());
    $this->assertFalse($this->service()->holdAfterInsert($foreign));
    $this->assertTrue($this->reload($foreign)->isPublished());
  }

  /**
   * Content Moderation: a held report gets the workflow's initial state.
   *
   * Domain review F3: the lightest unpublished state (archived here) must not
   * win over the initial state for new content (draft).
   */
  public function testModeratedReportGetsInitialUnpublishedState(): void {
    $this->enableModules(['workflows', 'content_moderation']);
    // enableModules() rebuilds the container and drops the setUp() stub.
    $this->stubProcessor();
    $this->installEntitySchema('content_moderation_state');
    $workflow = $this->createEditorialWorkflow();
    $configuration = $workflow->getTypePlugin()->getConfiguration();
    $configuration['states']['archived']['weight'] = -10;
    $configuration['default_moderation_state'] = 'draft';
    $workflow->getTypePlugin()->setConfiguration($configuration);
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'service_request');
    $workflow->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->setFlag($this->tenantA, TRUE);

    $service = $this->service();
    $node = $this->newReport('tenantA');
    $node->set('moderation_state', 'published');
    $this->assertTrue($service->holdNewReport($node));
    $this->assertFalse($node->isPublished());
    $this->assertSame('draft', $node->get('moderation_state')->value, 'The initial state, not the lightest unpublished one.');

    // An unpublished state chosen by the writer stays.
    $archived = $this->newReport('tenantA');
    $archived->set('moderation_state', 'archived');
    $this->assertTrue($service->holdNewReport($archived));
    $this->assertSame('archived', $archived->get('moderation_state')->value);
  }

  /**
   * Content Moderation: the late re-save stays the unpublished default revision.
   *
   * Re-review R1: the default revision is the published insert row, so
   * Content Moderation would otherwise save the hold as a forward revision.
   */
  public function testModeratedLateHoldKeepsUnpublishedDefaultRevision(): void {
    $this->enableModules(['workflows', 'content_moderation']);
    // enableModules() rebuilds the container and drops the setUp() stub.
    $this->stubProcessor();
    $this->installEntitySchema('content_moderation_state');
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'service_request');
    $workflow->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->setFlag($this->tenantA, TRUE);
    $orphan = $this->createTerm('service_category', 'Moderated without jurisdiction');
    $node = $this->newReport('tenantA');
    $node->set('field_category', $orphan->id());
    $node->set('moderation_state', 'published');
    $node->save();
    $node = $this->reload($node);
    $this->assertTrue($node->isPublished(), 'Two roots and no jurisdiction: not held in presave.');

    $node->set('field_jurisdiction', $this->districtA->id());
    $this->assertTrue($this->service()->holdAfterInsert($node));
    $stored = $this->reload($node);
    $this->assertFalse($stored->isPublished(), 'The default revision is unpublished.');
    $this->assertTrue($stored->isDefaultRevision());
    $this->assertTrue($this->service()->isHeld($stored));
  }

  /**
   * The pending count reads holds of still unpublished reports per tenant.
   */
  public function testCountHeldCountsUnpublishedHoldsPerTenant(): void {
    $service = $this->service();
    $tenantTree = [(int) $this->tenantA->id(), (int) $this->districtA->id()];
    $this->assertSame(0, $service->countHeld($tenantTree));

    $this->setFlag($this->tenantA, TRUE);
    $this->setFlag($this->tenantB, TRUE);
    $held = [];
    foreach (['tenantA', 'districtA', 'tenantA', 'tenantB'] as $i => $key) {
      $node = $this->newReport($key);
      $service->holdNewReport($node);
      $node->set('field_jurisdiction', $this->{$key}->id());
      $node->save();
      $service->recordHold($node);
      $held[$i] = $node;
    }
    $this->assertSame(3, $service->countHeld($tenantTree));
    $this->assertSame(1, $service->countHeld([(int) $this->tenantB->id()]), 'Tenants are counted apart.');
    $this->assertSame(0, $service->countHeld([]));

    // Publishing by hand ends the hold.
    $published = $this->reload($held[0]);
    $published->setPublished()->save();
    $service->releaseOnPublish($published);
    $this->assertSame(2, $service->countHeld($tenantTree));

    // A hold whose report was published past the hooks does not count.
    $this->container->get('database')->update('node_field_data')
      ->fields(['status' => 1])
      ->condition('nid', $held[2]->id())
      ->execute();
    $this->assertTrue($service->isHeld($held[2]));
    $this->assertSame(1, $service->countHeld($tenantTree));
  }

  /**
   * The service under test, as markaspot_nuxt.services.yml defines it.
   */
  private function service(): ReviewBeforePublish {
    $service = $this->container->get('markaspot_nuxt.review_before_publish');
    $this->assertInstanceOf(ReviewBeforePublish::class, $service);
    return $service;
  }

  /**
   * Builds an unsaved report in the category of a jurisdiction.
   */
  private function newReport(string $key, bool $published = TRUE, ?int $created = NULL): NodeInterface {
    $values = [
      'type' => 'service_request',
      'title' => 'Report ' . $key,
      'uid' => 0,
      'status' => $published ? 1 : 0,
      'body' => ['value' => 'Something is broken.', 'format' => 'plain_text'],
      'field_category' => $this->categories[$key]->id(),
      'field_status' => $this->statuses[$key]->id(),
    ];
    if ($created !== NULL) {
      $values['created'] = $created;
      $values['changed'] = $created;
    }
    return Node::create($values);
  }

  /**
   * Stores the flag in a jurisdiction's field_nuxt_config.
   */
  private function setFlag(GroupInterface $jurisdiction, bool $value): void {
    $jurisdiction = Group::load($jurisdiction->id());
    $this->assertInstanceOf(GroupInterface::class, $jurisdiction);
    $config = json_decode((string) $jurisdiction->get('field_nuxt_config')->value, TRUE) ?: [];
    $config['features'][ReviewBeforePublish::FLAG] = $value;
    $jurisdiction->set('field_nuxt_config', json_encode($config))->save();
  }

  /**
   * Reloads a report without the static cache.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Creates a jurisdiction, optionally below a parent.
   */
  private function createJurisdiction(string $label, ?GroupInterface $parent = NULL): GroupInterface {
    $jurisdiction = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_nuxt_config' => json_encode(['features' => []]),
      'field_parent_jurisdiction' => $parent?->id(),
    ]);
    $jurisdiction->save();
    return $jurisdiction;
  }

  /**
   * Leaves tenant A as the only root jurisdiction.
   */
  private function makeSingleRoot(): void {
    // markaspot_group refuses to delete a jurisdiction its terms still use.
    $this->categories['tenantB']->delete();
    $this->statuses['tenantB']->delete();
    unset($this->categories['tenantB'], $this->statuses['tenantB']);
    Group::load($this->tenantB->id())?->delete();
    $this->container->get('markaspot_group.hierarchy_resolver')->resetCache();
  }

  /**
   * Creates a category or status term, optionally of a jurisdiction.
   */
  private function createTerm(string $vid, string $name, ?GroupInterface $jurisdiction = NULL, ?string $mapping = NULL): TermInterface {
    $values = [
      'vid' => $vid,
      'name' => $name,
      'field_jurisdiction' => $jurisdiction?->id(),
    ];
    if ($mapping !== NULL) {
      $values['field_open311_mapping'] = $mapping;
    }
    $term = Term::create($values);
    $term->save();
    return $term;
  }

  /**
   * Stubs the Open311 processor the report hooks consult.
   */
  private function stubProcessor(): void {
    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->container->set('markaspot_open311.processor', new class($termStorage) {

      public function __construct(
        private readonly EntityStorageInterface $termStorage,
      ) {}

      /**
       * Returns the lowest-weight initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->sort('weight')
          ->range(0, 1)
          ->execute();
        return $tids ? (int) reset($tids) : NULL;
      }

      /**
       * No boilerplates in this test.
       */
      public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int {
        return NULL;
      }

      /**
       * Creates a status note like the real processor, without boilerplates.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create(['type' => 'status']);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        $paragraph->save();
        return $paragraph;
      }

    });
  }

  /**
   * Creates the node, taxonomy and paragraph model of a report.
   */
  private function createContentModel(): void {
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    if (!FilterFormat::load('plain_text')) {
      FilterFormat::create(['format' => 'plain_text', 'name' => 'Plain text'])->save();
    }

    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    $this->createField('node', 'service_request', 'body', 'text_with_summary');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_address', 'string');
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
  }

  /**
   * Creates the jurisdiction group type, roles and fields.
   */
  private function createJurisdictionModel(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    foreach (['tenant_admin' => 'Tenant Admin', 'member' => 'Member'] as $role => $label) {
      GroupRole::create([
        'id' => 'jur-' . $role,
        'label' => $label,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }

    $relationshipStorage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
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
