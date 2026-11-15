<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\Storage\GroupRoleStorageInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the closed intake and the publication gate of unopened workspaces.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate
 * @group markaspot_fastmap
 */
class WorkspaceLifecycleGateTest extends UnitTestCase {

  private const NOT_OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":7},"opening":null}';

  private const OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":7},"opening":{"at":1800001000}}';

  /**
   * The request stack the gate reads the session from.
   */
  private RequestStack $requestStack;

  /**
   * Groups by id for the group storage.
   *
   * @var array<int, \Drupal\group\Entity\GroupInterface>
   */
  private array $groups = [];

  /**
   * Root ids by group id for the hierarchy resolver.
   *
   * @var array<int, int>
   */
  private array $roots = [];

  /**
   * Translation doubles other than the active one, by language code.
   *
   * @var array<string, \Drupal\node\NodeInterface&\PHPUnit\Framework\MockObject\MockObject>
   */
  private array $translationDoubles = [];

  /**
   * Members of the record workspace: individual roles by account id.
   *
   * @var array<int, string[]>
   */
  private array $members = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['markaspot_operating_mode' => 'saas']);
    $this->requestStack = new RequestStack();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Builds the gate.
   */
  private function gate(): WorkspaceLifecycleGate {
    $config = $this->createMock(ImmutableConfig::class);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $lifecycle = new WorkspaceLifecycle($factory, $this->createMock(TimeInterface::class));

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturnCallback(fn($id) => $this->groups[(int) $id] ?? NULL);
    $groupStorage->method('loadMultiple')->willReturnCallback(fn(?array $ids = NULL) => array_intersect_key($this->groups, array_flip(array_map('intval', $ids ?? []))));
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    // Individual roles of the members, only in the record workspace (id 1).
    $roleStorage = $this->createMock(GroupRoleStorageInterface::class);
    $roleStorage->method('loadByUserAndGroup')->willReturnCallback(
      fn(AccountInterface $account, GroupInterface $group, bool $synchronized = TRUE): array => (int) $group->id() === 1 && !$synchronized
        ? array_fill_keys($this->members[(int) $account->id()] ?? [], TRUE)
        : [],
    );
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(fn(string $type) => match ($type) {
      'group' => $groupStorage,
      'group_role' => $roleStorage,
      default => $nodeStorage,
    });

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static fn(string $module): bool => $module === 'markaspot_fastmap');

    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->willReturnCallback(fn(int $id): ?int => $this->roots[$id] ?? $id);

    return new WorkspaceLifecycleGate($lifecycle, $entityTypeManager, $modules, $this->requestStack, $hierarchy);
  }

  /**
   * Builds a jurisdiction group carrying a record value (or none).
   */
  private function group(int $id, ?string $record, string $tier = ''): GroupInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn($record === NULL);
    $items->method('__get')->with('value')->willReturn($record);
    $tierItems = $this->createMock(FieldItemListInterface::class);
    $tierItems->method('isEmpty')->willReturn($tier === '');
    $tierItems->method('__get')->with('value')->willReturn($tier === '' ? NULL : $tier);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === WorkspaceLifecycle::FIELD || ($name === 'field_tier' && $tier !== ''));
    $group->method('get')->willReturnCallback(static fn(string $name) => $name === 'field_tier' ? $tierItems : $items);
    $this->groups[$id] = $group;
    return $group;
  }

  /**
   * Builds an account.
   */
  private function account(int $uid, array $roles = [], array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAnonymous')->willReturn($uid === 0);
    $account->method('getRoles')->willReturn($uid === 0 ? ['anonymous'] : array_merge(['authenticated'], $roles));
    $account->method('hasPermission')->willReturnCallback(static fn(string $p): bool => in_array($p, $permissions, TRUE));
    return $account;
  }

  /**
   * Starts a request whose login session belongs to an account (or none).
   */
  private function request(?int $sessionUid): void {
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    if ($sessionUid !== NULL) {
      $session = new Session(new MockArraySessionStorage());
      $session->set('uid', $sessionUid);
      $request->setSession($session);
    }
    $this->requestStack->push($request);
  }

  /**
   * Builds a report.
   *
   * @param bool $new
   *   Whether the report is new.
   * @param bool $published
   *   Whether the active translation is published in this save.
   * @param bool|null $wasPublished
   *   Whether the stored active translation was published (NULL: no original).
   * @param bool $syncing
   *   Whether the save is marked as syncing.
   * @param string $bundle
   *   The bundle.
   * @param \Drupal\group\Entity\GroupInterface[] $named
   *   The values of field_jurisdiction in this save.
   * @param \Drupal\group\Entity\GroupInterface[]|null $wasNamed
   *   The stored values (NULL: the same as in this save).
   * @param array<string, array{0: bool, 1: bool}> $translations
   *   Further translations: [published now, published before] by langcode.
   */
  private function report(bool $new = TRUE, bool $published = TRUE, ?bool $wasPublished = NULL, bool $syncing = FALSE, string $bundle = 'service_request', array $named = [], ?array $wasNamed = NULL, array $translations = []): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isNew')->willReturn($new);
    $node->method('isPublished')->willReturn($published);
    $node->method('isSyncing')->willReturn($syncing);
    $this->nameJurisdiction($node, $named);

    $this->translationDoubles = [];
    foreach ($translations as $langcode => [$now]) {
      $translation = $this->createMock(NodeInterface::class);
      $translation->method('isPublished')->willReturn($now);
      $this->translationDoubles[$langcode] = $translation;
    }
    $this->translatable($node, ['en' => $node] + $this->translationDoubles);

    if ($wasPublished !== NULL) {
      $original = $this->createMock(NodeInterface::class);
      $original->method('isPublished')->willReturn($wasPublished);
      $this->nameJurisdiction($original, $wasNamed ?? $named);
      $before = ['en' => $original];
      foreach ($translations as $langcode => [, $then]) {
        $translation = $this->createMock(NodeInterface::class);
        $translation->method('isPublished')->willReturn($then);
        $before[$langcode] = $translation;
      }
      $this->translatable($original, $before);
      $node->method('getOriginal')->willReturn($original);
    }
    return $node;
  }

  /**
   * Gives a report double its translations.
   *
   * @param \Drupal\node\NodeInterface&\PHPUnit\Framework\MockObject\MockObject $node
   *   The report double.
   * @param array<string, \Drupal\node\NodeInterface> $translations
   *   The translations by language code, the active one first.
   */
  private function translatable(NodeInterface $node, array $translations): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);
    $node->method('getTranslationLanguages')->willReturn(array_map(fn() => $this->createMock(LanguageInterface::class), $translations));
    $node->method('hasTranslation')->willReturnCallback(static fn(string $langcode): bool => isset($translations[$langcode]));
    $node->method('getTranslation')->willReturnCallback(static fn(string $langcode) => $translations[$langcode]);
  }

  /**
   * Gives a report double its field_jurisdiction values (or none).
   *
   * @param \Drupal\node\NodeInterface&\PHPUnit\Framework\MockObject\MockObject $node
   *   The report double.
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The values.
   */
  private function nameJurisdiction(NodeInterface $node, array $groups): void {
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction' && $groups !== []);
    $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $items->method('isEmpty')->willReturn($groups === []);
    $items->method('referencedEntities')->willReturn(array_values($groups));
    $node->method('get')->with('field_jurisdiction')->willReturn($items);
  }

  /**
   * Only a root with a valid record on the self-service platform counts.
   *
   * @covers ::recordWorkspace
   * @covers ::isClosed
   * @covers ::openState
   */
  public function testScopeAndState(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $old = $this->group(3, NULL);
    $invalid = $this->group(4, '{"v":1,"acceptance":{},"opening":null}');
    $child = $this->group(5, NULL);
    $this->roots[5] = 1;
    $gate = $this->gate();

    $this->assertSame($notOpen, $gate->recordWorkspace($notOpen));
    $this->assertSame($notOpen, $gate->recordWorkspace($child), 'A child uses the record of its root.');
    $this->assertNull($gate->recordWorkspace($old));
    $this->assertNull($gate->recordWorkspace($invalid));
    $this->assertNull($gate->recordWorkspace(NULL));

    $this->assertTrue($gate->isClosed($notOpen));
    $this->assertTrue($gate->isClosed($child));
    $this->assertFalse($gate->isClosed($open));
    $this->assertFalse($gate->isClosed($old));

    $this->assertFalse($gate->openState($notOpen));
    $this->assertTrue($gate->openState($open));
    $this->assertNull($gate->openState($old), 'No open key for a workspace without a record.');

    // Self-hosted: nothing applies, record or not.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertNull($gate->recordWorkspace($notOpen));
    $this->assertFalse($gate->isClosed($notOpen));
    $this->assertNull($gate->openState($notOpen));
  }

  /**
   * Who may create a report in a workspace that is not open.
   *
   * @covers ::refusesNewReport
   * @covers ::mayCreateInClosedWorkspace
   */
  public function testIntakeMatrix(): void {
    $workspace = $this->group(1, self::NOT_OPEN);
    $this->members = [
      10 => ['jur-tenant_admin'],
      11 => ['jur-member'],
      12 => [],
      30 => ['jur-member'],
    ];
    $gate = $this->gate();
    $platformAdmin = $this->account(40, ['administrator'], ['administer nodes']);
    $cases = [
      'anonymous' => [$this->account(0), NULL, TRUE],
      'tenant admin with own session' => [$this->account(10, ['tenant_admin'], ['bypass mas validation']), 10, FALSE],
      'member with own session' => [$this->account(11), 11, FALSE],
      'membership without a role' => [$this->account(12), 12, TRUE],
      'member, session of another account' => [$this->account(10), 99, TRUE],
      'member without session (API key, CLI)' => [$this->account(10), NULL, TRUE],
      'logged in, no role here' => [$this->account(20), 20, TRUE],
      'API key owner, even with a session' => [$this->account(30, ['api_user']), 30, TRUE],
      'platform administrator with session' => [$platformAdmin, 40, FALSE],
      'platform administrator without session' => [$platformAdmin, NULL, TRUE],
    ];
    foreach ($cases as $label => [$account, $sessionUid, $refused]) {
      $this->request($sessionUid);
      $this->assertSame($refused, $gate->refusesNewReport($this->report(), $workspace, $account), $label);
      $this->assertSame($refused, $gate->refusesNewReport($this->report(published: FALSE), $workspace, $account), $label . ' (arrives unpublished)');
      $this->requestStack->pop();
    }
  }

  /**
   * A report created by mail promotion, which arrives unpublished.
   *
   * The automatic promotion runs in a queue worker on cron: no request, or a
   * request without a login session, under the anonymous or a system
   * account. It is refused, whatever the account. A staff member promoting
   * the mail from the dashboard acts through their own session and is
   * allowed when they hold a role in the workspace.
   *
   * @covers ::refusesNewReport
   * @covers ::enforceOnSave
   */
  public function testMailPromotion(): void {
    $workspace = $this->group(1, self::NOT_OPEN);
    $this->members = [10 => ['jur-tenant_admin']];
    $gate = $this->gate();
    $promoted = fn() => $this->report(published: FALSE);

    // Queue worker on cron: no request on the stack at all.
    $accounts = [
      'anonymous' => $this->account(0),
      'uid 1' => $this->account(1, ['administrator'], ['administer nodes']),
      'tenant admin' => $this->account(10),
    ];
    foreach ($accounts as $label => $account) {
      $this->assertTrue($gate->refusesNewReport($promoted(), $workspace, $account), "queue, $label");
      try {
        $gate->enforceOnSave($promoted(), $account, fn() => $workspace, FALSE);
        $this->fail("The queue promoted a mail into a closed workspace ($label).");
      }
      catch (WorkspaceNotOpenException) {
        // Refused: the queue item fails and is retried on the next run.
      }
    }

    // Staff promoting from the dashboard with their own session.
    $this->request(10);
    $this->assertFalse($gate->refusesNewReport($promoted(), $workspace, $this->account(10)), 'dashboard, tenant admin');
    $this->assertTrue($gate->refusesNewReport($promoted(), $workspace, $this->account(20)), 'dashboard, no role here');
    $this->requestStack->pop();
  }

  /**
   * The workspace the report names counts next to the resolved one.
   *
   * @covers ::enforceOnSave
   */
  public function testNamedJurisdictionAndMovedReports(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $old = $this->group(3, NULL);
    $this->members = [10 => ['jur-tenant_admin']];
    $gate = $this->gate();
    $this->request(NULL);

    // Resolved to an old workspace (a category of it), but naming the closed
    // one in its field: refused.
    try {
      $gate->enforceOnSave($this->report(named: [$notOpen]), $this->account(0), fn() => $old, FALSE);
      $this->fail('A report naming a closed workspace was accepted.');
    }
    catch (WorkspaceNotOpenException) {
      // Refused.
    }
    $this->requestStack->pop();

    // A published report moved into the closed workspace: a publish attempt
    // there. Staying where it was published: left alone.
    $moved = $this->report(new: FALSE, wasPublished: TRUE, named: [$notOpen], wasNamed: [$old]);
    $moved->expects($this->once())->method('setUnpublished');
    $gate->enforceOnSave($moved, $this->account(40, [], ['administer nodes']), fn() => $notOpen, FALSE);
    $stays = $this->report(new: FALSE, wasPublished: TRUE, named: [$notOpen]);
    $stays->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($stays, $this->account(40, [], ['administer nodes']), fn() => $notOpen, FALSE);

    // A member's test report naming the closed workspace: kept unpublished.
    $this->request(10);
    $report = $this->report(named: [$notOpen]);
    $report->expects($this->once())->method('setUnpublished');
    $gate->enforceOnSave($report, $this->account(10), fn() => $old, FALSE);
    $this->requestStack->pop();
  }

  /**
   * The Free limit binds workspace staff in workspaces with a record.
   *
   * @covers ::accountBypassesTierLimit
   */
  public function testTierLimitBypass(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $old = $this->group(3, NULL);
    $gate = $this->gate();
    $staff = $this->account(10, ['tenant_admin'], ['bypass mas validation']);
    $admin = $this->account(40, ['administrator'], ['administer nodes']);
    $citizen = $this->account(20);

    foreach (['not open' => $notOpen, 'open' => $open] as $label => $workspace) {
      $this->assertFalse($gate->accountBypassesTierLimit($staff, $workspace), "staff, record workspace $label");
      $this->assertTrue($gate->accountBypassesTierLimit($admin, $workspace), "platform administrator, record workspace $label");
      $this->assertFalse($gate->accountBypassesTierLimit($citizen, $workspace), "citizen, record workspace $label");
    }
    // Without a record the old rule holds: membership decides for staff. The
    // group module file is not loaded in a unit test, so staff never bypass
    // here; the membership rule is covered by the markaspot_group kernel test
    // ModeratorNewRequestPublishScopeKernelTest.
    if (!function_exists('_markaspot_group_user_is_member_in_jurisdiction_scope')) {
      $this->assertFalse($gate->accountBypassesTierLimit($staff, $old), 'staff, old workspace, no membership check available');
    }
    $this->assertTrue($gate->accountBypassesTierLimit($staff, NULL), 'staff, no jurisdiction: as today');
    $this->assertTrue($gate->accountBypassesTierLimit($admin, $old));
    $this->assertFalse($gate->accountBypassesTierLimit($citizen, $old));

    // Paid tiers keep today's rule, record or not: Starter with a record
    // answers like Starter without one.
    $starterRecord = $this->group(6, self::OPEN, 'starter');
    $starterOld = $this->group(7, NULL, 'starter');
    foreach ([$staff, $admin, $citizen] as $account) {
      $this->assertSame($gate->accountBypassesTierLimit($account, $starterOld), $gate->accountBypassesTierLimit($account, $starterRecord));
    }
    $this->assertTrue($gate->accountBypassesTierLimit($admin, $starterRecord));
    // A record workspace whose tier is Free, set or resolved: staff bound.
    $this->assertFalse($gate->accountBypassesTierLimit($staff, $this->group(8, self::OPEN, 'free')));

    // Self-hosted: the record plays no part; staff with no group pass as today.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertTrue($gate->accountBypassesTierLimit($staff, NULL));
    $this->assertNull($gate->recordWorkspace($notOpen));
  }

  /**
   * Exempt saves and every workspace outside the model are never refused.
   *
   * @covers ::refusesNewReport
   */
  public function testIntakeExemptions(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $old = $this->group(3, NULL);
    $anonymous = $this->account(0);
    $gate = $this->gate();
    $this->request(NULL);

    $this->assertTrue($gate->refusesNewReport($this->report(), $notOpen, $anonymous));
    $this->assertFalse($gate->refusesNewReport($this->report(), $notOpen, $anonymous, TRUE), 'Seeded examples.');
    $this->assertFalse($gate->refusesNewReport($this->report(syncing: TRUE), $notOpen, $anonymous), 'Syncing saves.');
    $this->assertFalse($gate->refusesNewReport($this->report(new: FALSE), $notOpen, $anonymous), 'Existing reports.');
    $this->assertFalse($gate->refusesNewReport($this->report(bundle: 'page'), $notOpen, $anonymous), 'Other bundles.');
    $this->assertFalse($gate->refusesNewReport($this->report(), $open, $anonymous), 'Open workspace.');
    $this->assertFalse($gate->refusesNewReport($this->report(), $old, $anonymous), 'No record.');
    $this->assertFalse($gate->refusesNewReport($this->report(), NULL, $anonymous), 'No jurisdiction.');
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertFalse($gate->refusesNewReport($this->report(), $notOpen, $anonymous), 'Self-hosted platform.');
  }

  /**
   * New reports and publish attempts stay unpublished while not open.
   *
   * @covers ::enforceOnSave
   */
  public function testPublicationGate(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $old = $this->group(3, NULL);
    $this->members = [10 => ['jur-tenant_admin']];
    $gate = $this->gate();
    $admin = $this->account(40, [], ['administer nodes']);
    $this->request(40);
    $cases = [
      // [report, resolved group, seeding, held].
      'new published report' => [$this->report(), $notOpen, FALSE, TRUE],
      'publish attempt' => [$this->report(new: FALSE, wasPublished: FALSE), $notOpen, FALSE, TRUE],
      'already published' => [$this->report(new: FALSE, wasPublished: TRUE), $notOpen, FALSE, FALSE],
      'unpublished save' => [$this->report(published: FALSE), $notOpen, FALSE, FALSE],
      'seeded example' => [$this->report(), $notOpen, TRUE, FALSE],
      'syncing save' => [$this->report(syncing: TRUE), $notOpen, FALSE, FALSE],
      'open workspace' => [$this->report(), $open, FALSE, FALSE],
      'no record' => [$this->report(), $old, FALSE, FALSE],
    ];
    // The labels name the cases; the expectations on each double check them.
    foreach ($cases as [$report, $group, $seeding, $held]) {
      $report->expects($held ? $this->once() : $this->never())->method('setUnpublished');
      $gate->enforceOnSave($report, $admin, fn() => $group, $seeding);
    }
    $this->requestStack->pop();
  }

  /**
   * A publish attempt on any translation is held, not only the active one.
   *
   * @covers ::enforceOnSave
   */
  public function testPublishAttemptOnAnotherTranslation(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $gate = $this->gate();
    $admin = $this->account(40, [], ['administer nodes']);
    $this->request(40);

    // The active translation stays unpublished; German becomes published.
    $report = $this->report(new: FALSE, published: FALSE, wasPublished: FALSE, named: [$notOpen], translations: [
      'de' => [TRUE, FALSE],
    ]);
    $report->expects($this->never())->method('setUnpublished');
    $this->translationDoubles['de']->expects($this->once())->method('setUnpublished');
    $gate->enforceOnSave($report, $admin, fn() => $notOpen, FALSE);

    // A new report with a published translation: every translation held.
    $new = $this->report(named: [$notOpen], translations: ['de' => [TRUE, FALSE]]);
    $new->expects($this->once())->method('setUnpublished');
    $this->translationDoubles['de']->expects($this->once())->method('setUnpublished');
    $gate->enforceOnSave($new, $admin, fn() => $notOpen, FALSE);

    // A German translation that was already published stays published.
    $kept = $this->report(new: FALSE, published: FALSE, wasPublished: FALSE, named: [$notOpen], translations: [
      'de' => [TRUE, TRUE],
    ]);
    $this->translationDoubles['de']->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($kept, $admin, fn() => $notOpen, FALSE);
    $this->requestStack->pop();
  }

  /**
   * The full jurisdiction set counts, whatever the order of the values.
   *
   * @covers ::enforceOnSave
   */
  public function testJoiningClosedWorkspaceWhilePublished(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $gate = $this->gate();
    $admin = $this->account(40, [], ['administer nodes']);
    $this->request(40);
    foreach (['[O, W]' => [$open, $notOpen], '[W, O]' => [$notOpen, $open]] as $label => $now) {
      $report = $this->report(new: FALSE, wasPublished: TRUE, named: $now, wasNamed: [$open]);
      $report->expects($this->once())->method('setUnpublished');
      $gate->enforceOnSave($report, $admin, fn() => $open, FALSE);
    }
    // Leaving a value out or reordering joins nothing: left alone.
    $report = $this->report(new: FALSE, wasPublished: TRUE, named: [$open], wasNamed: [$open, $open]);
    $report->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($report, $admin, function () {
      $this->fail('Resolved for a plain edit.');
    }, FALSE);
    $this->requestStack->pop();
  }

  /**
   * A boundary hit in a foreign workspace tree does not close a report.
   *
   * The resolver result counts only when the report's set is empty or the
   * hit lies in the tree of a member of the set.
   *
   * @covers ::enforceOnSave
   */
  public function testForeignBoundaryHitIsIgnored(): void {
    $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    // A child area of the closed workspace.
    $closedChild = $this->group(9, NULL);
    $this->roots[9] = 1;
    $gate = $this->gate();
    $this->request(NULL);
    $anonymous = $this->account(0);

    // Meant for the open map, located inside the closed map's child area.
    $report = $this->report(named: [$open]);
    $report->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($report, $anonymous, fn() => $closedChild, FALSE);

    // A publish attempt there: not held either.
    $publish = $this->report(new: FALSE, wasPublished: FALSE, named: [$open]);
    $publish->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($publish, $this->account(40, [], ['administer nodes']), fn() => $closedChild, FALSE);

    // An empty set: the boundary hit is all there is, and it closes.
    try {
      $gate->enforceOnSave($this->report(), $anonymous, fn() => $closedChild, FALSE);
      $this->fail('A report without jurisdiction inside a closed area was accepted.');
    }
    catch (WorkspaceNotOpenException) {
      // Refused.
    }
    $this->requestStack->pop();
  }

  /**
   * After insert, a closed workspace in the derived set refuses the report.
   *
   * @covers ::refuseAfterInsert
   */
  public function testRefuseAfterInsert(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $open = $this->group(2, self::OPEN);
    $this->members = [10 => ['jur-tenant_admin']];
    $gate = $this->gate();

    // The derived field names the closed workspace: anonymous is refused.
    $this->request(NULL);
    try {
      $gate->refuseAfterInsert($this->report(new: FALSE, named: [$open, $notOpen]), $this->account(0), FALSE);
      $this->fail('A report landing in a closed workspace on insert was kept.');
    }
    catch (WorkspaceNotOpenException) {
      // Refused.
    }
    // Seeded examples, syncing saves and open workspaces pass.
    $gate->refuseAfterInsert($this->report(new: FALSE, named: [$notOpen]), $this->account(0), TRUE);
    $gate->refuseAfterInsert($this->report(new: FALSE, syncing: TRUE, named: [$notOpen]), $this->account(0), FALSE);
    $gate->refuseAfterInsert($this->report(new: FALSE, named: [$open]), $this->account(0), FALSE);
    $this->requestStack->pop();

    // A member of the closed workspace with own session: allowed.
    $this->request(10);
    $gate->refuseAfterInsert($this->report(new: FALSE, named: [$notOpen]), $this->account(10), FALSE);
    $this->requestStack->pop();
    $this->addToAssertionCount(1);
  }

  /**
   * The save hook refuses, unpublishes, or leaves the report alone.
   *
   * @covers ::enforceOnSave
   */
  public function testEnforceOnSave(): void {
    $notOpen = $this->group(1, self::NOT_OPEN);
    $old = $this->group(3, NULL);
    $this->members = [10 => ['jur-tenant_admin']];
    $gate = $this->gate();

    // Anonymous new report: refused, also when it arrives unpublished.
    $this->request(NULL);
    foreach ([TRUE, FALSE] as $published) {
      try {
        $gate->enforceOnSave($this->report(published: $published), $this->account(0), fn() => $notOpen, FALSE);
        $this->fail('An anonymous report was accepted.');
      }
      catch (WorkspaceNotOpenException $e) {
        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringStartsWith('workspace_not_open', $e->getMessage());
      }
    }
    $this->requestStack->pop();

    // A member's test report is accepted and kept unpublished.
    $this->request(10);
    $report = $this->report();
    $report->expects($this->once())->method('setUnpublished');
    $gate->enforceOnSave($report, $this->account(10), fn() => $notOpen, FALSE);

    // A seeded example is neither refused nor unpublished.
    $example = $this->report();
    $example->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($example, $this->account(0), fn() => $notOpen, TRUE);

    // An edit of an existing unpublished report needs no jurisdiction, nor
    // one of a published report that stays where it is.
    $gate->enforceOnSave($this->report(new: FALSE, published: FALSE), $this->account(10), function () {
      $this->fail('The jurisdiction was resolved for a plain edit.');
    }, FALSE);
    $gate->enforceOnSave($this->report(new: FALSE, wasPublished: TRUE, named: [$notOpen]), $this->account(10), function () {
      $this->fail('The jurisdiction was resolved for an edit of a published report.');
    }, FALSE);
    $this->requestStack->pop();

    // Workspaces without a record: untouched.
    $this->request(NULL);
    $legacy = $this->report();
    $legacy->expects($this->never())->method('setUnpublished');
    $gate->enforceOnSave($legacy, $this->account(0), fn() => $old, FALSE);

    // Self-hosted: not even resolved.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $gate->enforceOnSave($this->report(), $this->account(0), function () {
      $this->fail('The jurisdiction was resolved on a self-hosted platform.');
    }, FALSE);
  }

}
