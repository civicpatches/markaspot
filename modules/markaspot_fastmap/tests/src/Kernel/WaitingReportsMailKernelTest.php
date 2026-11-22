<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_fastmap\Hook\CitizenMailHooks;
use Drupal\markaspot_fastmap\Service\WaitingReportsNotifier;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the operator mail about reports waiting for approval.
 *
 * Real storage, the module's real hooks (node_insert after markaspot_nuxt,
 * group_delete), the mail manager with the test collector and, for the
 * pipeline test, markaspot_mail's alter hooks. In a worktree run the module
 * comes from the worktree only when the test bootstrap redirects it there;
 * testRunsThisCheckout() fails otherwise.
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class WaitingReportsMailKernelTest extends KernelTestBase {

  use AssertMailTrait;
  use UserCreationTrait;

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
    'serialization',
    'jsonapi',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_publisher',
    'markaspot_tenant_admin',
    'markaspot_mail',
    'markaspot_fastmap',
    'service_request',
  ];

  /**
   * A record of an open map.
   */
  private const OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":{"opened_at":1800001000,"uid":7,"revision_id":1,"operator":{"organization":"Example Org","address_line1":"Main street 1","postal_code":"12345","locality":"Town","country_code":"DE","email":"operator@example.org"}}}';

  /**
   * A record of a map that is not open.
   */
  private const NOT_OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":0},"opening":null}';

  /**
   * A day and a second.
   */
  private const DAY = 86400;

  /**
   * Workspaces by key: open, closed (record, not open), legacy (no record).
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  private array $workspaces = [];

  /**
   * Categories by workspace key.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $categories = [];

  /**
   * Initial statuses by workspace key.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>
   */
  private array $statuses = [];

  /**
   * Accounts by label.
   *
   * @var array<string, \Drupal\user\Entity\User>
   */
  private array $accounts = [];

  /**
   * Log records of the markaspot_fastmap channel, as "level: message".
   *
   * @var string[]
   */
  private array $log = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('markaspot_operating_mode', 'saas');

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_fastmap', ['markaspot_ai_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group', 'jsonapi', 'language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->container->get('config.storage')->write('markaspot_fastmap.settings', [
      'tier_limits' => ['free' => ['limit' => 50, 'period' => 'published']],
      'workspace_base_url' => 'https://maps.example.org',
      'lifecycle_enabled' => TRUE,
    ]);

    $collector = new class($this->log) implements LoggerInterface {

      use RfcLoggerTrait;

      public function __construct(private array &$records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? '') === 'markaspot_fastmap') {
          $names = [RfcLogLevel::ERROR => 'error', RfcLogLevel::WARNING => 'warning', RfcLogLevel::NOTICE => 'notice'];
          $this->records[] = ($names[$level] ?? (string) $level) . ': ' . strtr((string) $message, array_map('strval', array_filter($context, 'is_scalar')));
        }
      }

    };
    $this->container->get('logger.factory')->addLogger($collector);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'mail' => 'root@example.test', 'status' => 1])->save();
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])
      ->grantPermission('access content')
      ->grantPermission('bypass mas validation')
      ->save();
    Role::create(['id' => 'api_user', 'label' => 'API user'])->grantPermission('access content')->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])
      ->grantPermission('access content')
      ->grantPermission('administer nodes')
      ->grantPermission('bypass node access')
      ->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();

    $this->addWorkspace('open', self::OPEN);
    $this->addWorkspace('closed', self::NOT_OPEN);
    $this->addWorkspace('legacy', NULL);

    $this->accounts['operator'] = $this->member('open', 'operator', 'jur-tenant_admin', 'de');
    $this->accounts['colleague'] = $this->member('open', 'colleague', 'jur-tenant_admin', 'en');
    $this->accounts['blocked admin'] = $this->member('open', 'blocked', 'jur-tenant_admin', 'en', FALSE);
    $this->accounts['helper'] = $this->member('open', 'helper', 'jur-member', 'en');
    // The API key owner is a member of every workspace; here even with the
    // tenant admin role, which it must never receive mail for.
    $this->accounts['api_user'] = $this->member('open', 'api_user', 'jur-tenant_admin', 'en', TRUE, ['api_user']);
    $this->accounts['closed operator'] = $this->member('closed', 'closed-operator', 'jur-tenant_admin', 'en');
    $this->accounts['legacy operator'] = $this->member('legacy', 'legacy-operator', 'jur-tenant_admin', 'en');
    $this->accounts['platform admin'] = User::create([
      'name' => 'staff',
      'mail' => 'staff@example.test',
      'status' => 1,
      'roles' => ['administrator'],
    ]);
    $this->accounts['platform admin']->save();

    $this->container->get('router.builder')->rebuild();
    $this->clearMails();
  }

  /**
   * The module code under test is this checkout's.
   */
  public function testRunsThisCheckout(): void {
    $path = $this->container->get('extension.list.module')->getPath('markaspot_fastmap');
    $this->assertSame(realpath(dirname(__DIR__, 3)), realpath(DRUPAL_ROOT . '/' . $path), 'The enabled module is this checkout.');
    $this->assertTrue($this->container->has('markaspot_fastmap.waiting_reports_notifier'));
    $moduleHandler = $this->container->get('module_handler');
    $this->assertTrue($moduleHandler->hasImplementations('node_insert', 'markaspot_fastmap'));
    $this->assertTrue($moduleHandler->hasImplementations('group_delete', 'markaspot_fastmap'));
    $this->assertContains('markaspot_fastmap.waiting_reports_notifier', $this->container->getParameter('kernel.destructable_services'), 'Sent at the end of the request.');
  }

  /**
   * Which new reports note the workspace, with the module's real hooks.
   */
  public function testTriggerMatrix(): void {
    $open = (int) $this->workspaces['open']->id();

    // A citizen's held report in an open record workspace.
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertTrue($this->review()->isHeld($report), 'held');
    $this->assertSame([$open], $this->pendingIds(), 'citizen: noted');
    $this->assertSame([$open => WaitingReportsNotifier::SENT], $this->notifier()->flush(1900000000));
    $this->assertCount(2, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
    $this->reset();

    // A citizen through the public key: GeoReport makes them api_user.
    $this->createAs($this->accounts['api_user'], 'open');
    $this->assertSame([$open], $this->pendingIds(), 'public key: noted');
    $this->reset();

    // The operator's own report, through the own login session.
    $report = $this->createAs($this->accounts['operator'], 'open', TRUE);
    $this->assertTrue($this->review()->isHeld($report));
    $this->assertSame([], $this->pendingIds(), 'own report: not noted');
    // A member without the admin role is the team too.
    $this->createAs($this->accounts['helper'], 'open', TRUE);
    $this->assertSame([], $this->pendingIds(), 'team member: not noted');
    // The same operator without the own session (another authentication, a
    // background process) is still the team: not noted.
    $this->createAs($this->accounts['operator'], 'open');
    $this->assertSame([], $this->pendingIds(), 'operator without own session: not noted');
    // Anonymous, as a command-line or cron save runs: noted.
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open], $this->pendingIds(), 'anonymous save: noted');
    $this->reset();

    // A category of another open map marks only the report's own map.
    $this->addWorkspace('second', self::OPEN);
    $shared = $this->createTerm('service_category', 'Shared', $this->workspaces['second']);
    $this->setCurrentUser(new AnonymousUserSession());
    $this->pushRequest(NULL);
    try {
      Node::create(['field_category' => $shared->id()] + $this->reportValues('open', 'Shared category') + ['status' => 1])->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    $this->assertSame([$open], $this->pendingIds(), 'foreign category: only the own workspace');
    $this->reset();

    // Approval off: not held, not noted.
    $this->setFlag('open', FALSE);
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertFalse($this->review()->isHeld($report));
    $this->assertSame([], $this->pendingIds(), 'review off: not noted');
    $this->setFlag('open', TRUE);

    // Not open: only the team and platform administrators may report there.
    $report = $this->createAs($this->accounts['platform admin'], 'closed', TRUE);
    $this->assertTrue($this->review()->isHeld($report));
    $this->assertSame([], $this->pendingIds(), 'not open: not noted');

    // Blocked: markaspot_group refuses the save outright; the note checks the
    // block as well, for a held report noted against a blocked map.
    $held = $this->createAs(new AnonymousUserSession(), 'open');
    $this->reset();
    $this->setVisibility('open', 'blocked');
    try {
      $this->createAs(new AnonymousUserSession(), 'open');
      $this->fail('A report was stored in a blocked map.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('Workspace is blocked.', $e->getMessage());
    }
    $this->notifier()->noteHeldReport($held, new AnonymousUserSession());
    $this->assertSame([], $this->pendingIds(), 'blocked: not noted');
    $this->setVisibility('open', 'public');

    // No record.
    $report = $this->createAs(new AnonymousUserSession(), 'legacy');
    $this->assertTrue($this->review()->isHeld($report));
    $this->assertSame([], $this->pendingIds(), 'no record: not noted');

    // Not self-service, the module installed.
    $this->setSetting('markaspot_operating_mode', 'self_hosted');
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertTrue($this->review()->isHeld($report));
    $this->assertSame([], $this->pendingIds(), 'self-hosted: not noted');
    $this->assertSame([], $this->notifier()->sendDue(1900000000), 'self-hosted: cron reads nothing');
    $this->setSetting('markaspot_operating_mode', 'saas');

    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]), 'none of these sent a mail');
  }

  /**
   * The note runs after the hold is recorded, on both hold paths.
   *
   * Markaspot_nuxt records the hold in its insert hook: recordHold() for a
   * report held in presave, holdAfterInsert() for one whose tenant became
   * known only on insert (which re-saves it there). Both happen inside
   * markaspot_nuxt's node_insert, so a listener ordered after markaspot_nuxt
   * sees either hold. This reads the order of the real listeners; a report
   * of a child jurisdiction is noted under its workspace root.
   */
  public function testNoteRunsAfterTheHoldAndUnderTheRoot(): void {
    $order = [];
    $this->container->get('module_handler')->invokeAllWith('node_insert', static function (callable $hook, string $module) use (&$order): void {
      $order[] = is_array($hook) && is_object($hook[0]) ? $module . '::' . $hook[1] : $module;
    });
    $note = array_search('markaspot_fastmap::noteWaitingReport', $order, TRUE);
    $this->assertIsInt($note, implode(', ', $order));
    $this->assertGreaterThan(array_search('markaspot_nuxt', $order, TRUE), $note, implode(', ', $order));
    $this->assertGreaterThan(array_search('markaspot_group', $order, TRUE), $note, implode(', ', $order));

    // A child of the open map: noted under the workspace root, counted with
    // the descendants.
    $child = Group::create([
      'type' => 'jur',
      'label' => 'District',
      'field_parent_jurisdiction' => $this->workspaces['open']->id(),
    ]);
    $child->save();
    $this->setCurrentUser(new AnonymousUserSession());
    $this->pushRequest(NULL);
    try {
      $node = Node::create(['field_jurisdiction' => $child->id()] + $this->reportValues('open', 'District report') + ['status' => 1]);
      $node->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    $this->assertTrue($this->review()->isHeld($this->reload($node)), 'held');
    $open = (int) $this->workspaces['open']->id();
    $this->assertSame([$open], $this->pendingIds(), 'noted under the workspace root');
    $this->assertSame(1, $this->notifier()->countWaiting($open), 'counted with the descendants');
    $this->reset();

    // A report without field_jurisdiction: markaspot_group derives it on
    // insert (single root, a category without jurisdiction), the note reads
    // it after that.
    foreach (['closed', 'legacy'] as $key) {
      $this->categories[$key]->delete();
      // A status mapping must remain: the statuses move to the open map.
      $this->statuses[$key]->set('field_jurisdiction', $this->workspaces['open']->id())->save();
      $this->workspaces[$key]->delete();
    }
    // The child is no root; the open map is the only one now.
    $global = $this->createTerm('service_category', 'Litter');
    $this->setCurrentUser(new AnonymousUserSession());
    $this->pushRequest(NULL);
    try {
      $values = ['field_category' => $global->id()] + $this->reportValues('open', 'Unscoped report') + ['status' => 1];
      unset($values['field_jurisdiction']);
      $node = Node::create($values);
      $node->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    $node = $this->reload($node);
    $this->assertSame($open, (int) $node->get('field_jurisdiction')->target_id, 'markaspot_group assigned the single root on insert');
    $this->assertTrue($this->review()->isHeld($node), 'held');
    $this->assertSame([$open], $this->pendingIds(), 'noted from the field derived on insert');
  }

  /**
   * Only when something new arrived: first at once, then at most daily.
   */
  public function testRate(): void {
    $open = (int) $this->workspaces['open']->id();
    $t0 = 1900000000;

    // Two reports together: one mail per recipient.
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open => WaitingReportsNotifier::SENT], $this->notifier()->flush($t0));
    $mails = $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]);
    $this->assertCount(2, $mails, 'one mail each for two recipients');
    $this->assertStringContainsString(': 2', $this->body($mails[0]));
    $this->clearMails();

    // A new report within 24 hours: nothing at once, the note stays.
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open => WaitingReportsNotifier::TOO_SOON], $this->notifier()->flush($t0 + 3600));
    $this->assertSame([$open => WaitingReportsNotifier::TOO_SOON], $this->notifier()->sendDue($t0 + self::DAY - 1));
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));

    // Cron after 24 hours: one mail, with all three waiting.
    $this->assertSame([$open => WaitingReportsNotifier::SENT], $this->notifier()->sendDue($t0 + self::DAY));
    $mails = $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]);
    $this->assertCount(2, $mails);
    $this->assertStringContainsString(': 3', $this->body($mails[0]));
    $this->clearMails();

    // Nothing new: no mail ever, however many still wait.
    $this->assertSame([], $this->notifier()->sendDue($t0 + 3 * self::DAY));
    $this->assertSame([], $this->notifier()->sendDue($t0 + 30 * self::DAY));
    $this->assertSame(3, $this->notifier()->countWaiting($open));
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));

    // Long after: the next new report is mailed at once.
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open => WaitingReportsNotifier::SENT], $this->notifier()->flush($t0 + 31 * self::DAY));
    $this->assertCount(2, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));

    // A second decision on the same note sends nothing (the stamp holds).
    $this->assertSame(WaitingReportsNotifier::NOTHING_NEW, $this->notifier()->sendIfDue($open, $t0 + 31 * self::DAY));
  }

  /**
   * Nothing waits any more at cron time: the note goes, no mail.
   */
  public function testNothingWaitingAtCronClearsTheNote(): void {
    $open = (int) $this->workspaces['open']->id();
    $t0 = 1900000000;
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->notifier()->flush($t0);
    $this->clearMails();

    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open => WaitingReportsNotifier::TOO_SOON], $this->notifier()->flush($t0 + 60));
    // The operator publishes both waiting reports.
    $this->setCurrentUser($this->accounts['platform admin']);
    foreach ($this->heldReports('open') as $held) {
      $held->setPublished()->save();
    }
    $this->assertFalse($this->review()->isHeld($this->reload($report)));
    $this->assertSame(0, $this->notifier()->countWaiting($open));

    $this->assertSame([$open => WaitingReportsNotifier::NOTHING_WAITING], $this->notifier()->sendDue($t0 + self::DAY + 1));
    $this->assertSame([], $this->pendingIds(), 'note cleared');
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
  }

  /**
   * Recipients, language, text and link.
   */
  public function testRecipientsAndText(): void {
    $recipients = $this->notifier()->recipients($this->workspaces['open']);
    $this->assertEqualsCanonicalizing(
      [(int) $this->accounts['operator']->id(), (int) $this->accounts['colleague']->id()],
      array_keys($recipients),
      'Active tenant admins only: not the blocked admin, not the member, not api_user, not the platform account.',
    );

    // A German override installed before the key existed: the shipped German
    // text is found without an update hook.
    $this->container->get('language_manager')
      ->getLanguageConfigOverride('de', 'markaspot_fastmap.mail')
      ->set('workspace_welcome', ['subject' => 'Willkommen', 'body' => 'Hallo'])
      ->save();
    $this->assertNull($this->config('markaspot_fastmap.mail')->get(WaitingReportsNotifier::MAIL_KEY), 'The key is not in the active config.');

    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->notifier()->flush(1900000000);
    $mails = $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]);
    $byTo = array_column($mails, NULL, 'to');
    $this->assertEqualsCanonicalizing(['operator@example.test', 'colleague@example.test'], array_keys($byTo));

    $german = $byTo['operator@example.test'];
    $this->assertSame('de', $german['langcode']);
    $this->assertSame('Meldungen warten auf Ihre Freigabe: Open map', $german['subject']);
    $this->assertStringContainsString('Warten auf Ihre Freigabe: 1', $this->body($german));
    $this->assertStringContainsString('https://maps.example.org/open-map/dashboard/requests', $this->body($german));
    $this->assertStringContainsString('Einstellungen > Funktionen', $this->body($german));

    $english = $byTo['colleague@example.test'];
    $this->assertSame('Reports are waiting for your approval: Open map', $english['subject']);
    $this->assertStringContainsString('Waiting for your approval: 1', $this->body($english));
    $this->assertStringContainsString('No further e-mail follows until new reports arrive.', $this->body($english));

    // Nothing from the report, no CC, no BCC.
    foreach ($mails as $mail) {
      $this->assertStringNotContainsString((string) $report->label(), $this->body($mail));
      $this->assertStringNotContainsString('Something is broken', $this->body($mail));
      foreach (array_keys((array) ($mail['headers'] ?? [])) as $header) {
        $this->assertNotContains(strtolower((string) $header), ['cc', 'bcc']);
      }
    }
  }

  /**
   * No recipient: decided once for the marker, logged once, not hourly.
   *
   * No link: nothing sent, the workspace stays due; cron logs an empty base
   * URL once per run, not per workspace.
   */
  public function testNoRecipientIsDecidedOnce(): void {
    $open = (int) $this->workspaces['open']->id();
    foreach (['operator', 'colleague'] as $label) {
      $this->accounts[$label]->block()->save();
    }
    $this->createAs(new AnonymousUserSession(), 'open');
    $counted = 0;
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), NULL, NULL, function () use (&$counted): void {
      $counted++;
    });
    $this->assertSame(WaitingReportsNotifier::NO_RECIPIENT, $notifier->sendIfDue($open, 1900000000));
    $this->assertSame(0, $counted, 'recipients before the count');
    $this->assertSame([], $this->pendingIds(), 'decided for this marker');
    $this->assertSame([], $this->notifier()->sendDue(1900000000 + 3600), 'not again an hour later');
    $warning = "warning: No operator mail about waiting reports for workspace $open: no active tenant admin with an e-mail address.";
    $this->assertCount(1, array_keys($this->log, $warning, TRUE));

    // A new report tries again, now with a recipient but without a link.
    $this->accounts['colleague']->activate()->save();
    $this->config('markaspot_fastmap.settings')->set('workspace_base_url', '')->save();
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame(WaitingReportsNotifier::NO_LINK, $this->notifier()->sendIfDue($open, 1900000000));
    $this->assertSame([$open], $this->pendingIds(), 'stays due without a link');

    // Cron with two due workspaces and no base URL: one log line, no visit.
    $this->addWorkspace('second', self::OPEN);
    $this->kv(WaitingReportsNotifier::PENDING_COLLECTION)->set((string) $this->workspaces['second']->id(), 'marker');
    $this->log = [];
    $this->assertSame([], $this->notifier()->sendDue(1900000000));
    $this->assertSame(['error: No operator mails about waiting reports: markaspot_fastmap.settings:workspace_base_url is empty; 2 workspaces stay due.'], $this->log);
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));

    $this->config('markaspot_fastmap.settings')->set('workspace_base_url', 'https://maps.example.org')->save();
    $this->assertSame(WaitingReportsNotifier::SENT, $this->notifier()->sendIfDue($open, 1900000000));
    $this->assertCount(1, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
  }

  /**
   * A send an alter hook cancelled is decided, not retried.
   */
  public function testCancelledSendIsHandled(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->createAs(new AnonymousUserSession(), 'open');
    $cancelling = $this->createMock(MailManagerInterface::class);
    $cancelling->method('mail')->willReturn(['result' => NULL]);
    $this->assertSame(WaitingReportsNotifier::CANCELLED, $this->notifierWith($cancelling)->sendIfDue($open, 1900000000));
    $this->assertSame([], $this->pendingIds(), 'handled');
    $this->assertSame(1900000000, (int) $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open), 'the stamp stays');
  }

  /**
   * One cancelled and one failed send: nobody got it, so it is retried.
   */
  public function testCancelledAndFailedSendIsRetried(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->createAs(new AnonymousUserSession(), 'open');
    $calls = 0;
    $mixed = $this->createMock(MailManagerInterface::class);
    $mixed->method('mail')->willReturnCallback(static function () use (&$calls): array {
      return ['result' => $calls++ === 0 ? NULL : FALSE];
    });
    $this->assertSame(WaitingReportsNotifier::FAILED, $this->notifierWith($mixed)->sendIfDue($open, 1900000000));
    $this->assertSame(2, $calls);
    $this->assertSame([$open], $this->pendingIds(), 'still due');
    $this->assertNull($this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open), 'the stamp is taken back');
  }

  /**
   * The citizen's confirmation of a held report names the approval.
   *
   * Only for a report held in an open workspace with a record, only in the
   * confirmation and only in a language with a sentence.
   */
  public function testConfirmationNamesTheApproval(): void {
    $alter = function (NodeInterface $node, string $langcode, string $key = 'report_confirmation'): array {
      $slots = [
        'subject' => 'S',
        'headline' => 'H',
        'intro' => 'I',
        'body_blocks' => ['Category: X'],
        'cta_label' => '',
        'preheader' => 'P',
      ];
      $context = ['notification_key' => $key, 'node' => $node, 'langcode' => $langcode];
      $this->container->get('module_handler')->alter('markaspot_mail_notification_slots', $slots, $context);
      return $slots['body_blocks'];
    };

    $held = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertTrue($this->review()->isHeld($held));
    $this->assertSame(['Category: X', CitizenMailHooks::APPROVAL_NOTICE['de']], $alter($held, 'de'));
    $this->assertSame(['Category: X', CitizenMailHooks::APPROVAL_NOTICE['en']], $alter($held, 'en'));
    $this->assertSame(['Category: X', CitizenMailHooks::APPROVAL_NOTICE['de-ls']], $alter($held, 'de-ls'));
    $this->assertSame(['Category: X'], $alter($held, 'fr'), 'no English sentence in a French mail');
    $this->assertSame(['Category: X'], $alter($held, 'de', 'status_open'), 'only the confirmation');

    // A workspace without a record (old model) with approval on: unchanged.
    $legacy = $this->createAs(new AnonymousUserSession(), 'legacy');
    $this->assertTrue($this->review()->isHeld($legacy));
    $this->assertSame(['Category: X'], $alter($legacy, 'de'));

    // The team's test report in a map that is not open: never published.
    $test = $this->createAs($this->accounts['closed operator'], 'closed', TRUE);
    $this->assertFalse($test->isPublished());
    $this->assertSame(['Category: X'], $alter($test, 'de'));

    // Approval off: published at once, nothing to add.
    $this->setFlag('open', FALSE);
    $published = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertTrue($published->isPublished());
    $this->assertSame(['Category: X'], $alter($published, 'de'));
  }

  /**
   * The count reads a fresh hold list, not one cached by an earlier count.
   */
  public function testCountReadsFreshHoldList(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->assertSame(0, $this->notifier()->countWaiting($open));
    // A hold another process wrote after the list above was read.
    $this->setFlag('open', FALSE);
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $report->setUnpublished()->save();
    $this->review()->heldIds();
    $this->container->get('keyvalue')->get(ReviewBeforePublish::COLLECTION)->set((string) $report->id(), ['created' => 1900000000]);
    $this->assertSame(1, $this->notifier()->countWaiting($open));
  }

  /**
   * The nested save of holdAfterInsert() and the note: exactly one marker.
   *
   * Drives the insert listeners in their real order on a report saved
   * without a hold (approval off at save time): markaspot_nuxt's
   * holdAfterInsert() unpublishes and saves it again inside the hook (an
   * update, not a second insert), then the note runs.
   */
  public function testHoldAfterInsertWritesOneMarker(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->setFlag('open', FALSE);
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertTrue($report->isPublished());
    $this->assertSame([], $this->pendingIds());
    $this->setFlag('open', TRUE);

    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertTrue($this->review()->holdAfterInsert($report), 'held after insert, with the nested save');
    $this->notifier()->noteHeldReport($report, new AnonymousUserSession());
    $this->assertFalse($this->reload($report)->isPublished());
    $this->assertSame([$open], $this->pendingIds());
    $this->assertCount(1, $this->kv(WaitingReportsNotifier::PENDING_COLLECTION)->getAll(), 'exactly one marker');
  }

  /**
   * A save rolled back after the note leaves no marker.
   *
   * Kernel tests keep key/value in memory, which no transaction rolls back;
   * this case uses the database store production uses. The note is written
   * inside the save's transaction, as the insert hook does, and the save
   * fails afterwards.
   */
  public function testRolledBackSaveLeavesNoMarker(): void {
    $open = (int) $this->workspaces['open']->id();
    $database = $this->container->get('database');
    $store = new KeyValueDatabaseFactory($this->container->get('serialization.phpserialize'), $database);
    // The table is created on first use; DDL would end the transaction.
    $store->get('warm_up')->set('table', TRUE);
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), $store);

    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $transaction = $database->startTransaction();
    $notifier->noteHeldReport($report, new AnonymousUserSession());
    $this->assertNotNull($store->get(WaitingReportsNotifier::PENDING_COLLECTION)->get((string) $open), 'noted inside the transaction');
    $transaction->rollBack();
    unset($transaction);

    $this->assertNull($store->get(WaitingReportsNotifier::PENDING_COLLECTION)->get((string) $open), 'rolled back with the save');
    $this->clearMails();
    $this->assertSame([$open => WaitingReportsNotifier::NOTHING_NEW], $notifier->flush(1900000000));
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
  }

  /**
   * The end of the request sends the mail (kernel terminate, destruct()).
   */
  public function testKernelTerminateSends(): void {
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]), 'nothing inside the save');
    $this->container->get('kernel')->terminate(Request::create('/'), new Response());
    $this->assertCount(2, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
    $this->assertSame([], $this->pendingIds());
  }

  /**
   * Closed again, blocked or without a record by cron time: dropped, no mail.
   */
  public function testIneligibleAtSendTimeDropsTheNote(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->setVisibility('open', 'blocked');
    $this->assertSame([$open => WaitingReportsNotifier::NOT_APPLICABLE], $this->notifier()->flush(1900000000));
    $this->assertSame([], $this->pendingIds());
    $this->setVisibility('open', 'public');

    $this->createAs(new AnonymousUserSession(), 'open');
    $this->container->get('markaspot_fastmap.workspace_lifecycle')->removeOpening($this->workspaces['open']);
    $this->workspaces['open']->save();
    $this->assertFalse($this->container->get('markaspot_fastmap.workspace_lifecycle')->isOpen(Group::load($open)));
    $this->assertSame([$open => WaitingReportsNotifier::NOT_APPLICABLE], $this->notifier()->sendDue(1900000000));
    $this->assertSame([], $this->pendingIds());
    $this->assertSame([], $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
  }

  /**
   * The platform switch decides nothing for workspaces that carry a record.
   */
  public function testSwitchOffKeepsTheMail(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->config('markaspot_fastmap.settings')->set('lifecycle_enabled', FALSE)->save();
    $this->assertFalse($this->container->get('markaspot_fastmap.workspace_lifecycle')->isEnabled());
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open => WaitingReportsNotifier::SENT], $this->notifier()->flush(1900000000));
    $this->assertCount(2, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]));
  }

  /**
   * A failing mail manager never fails or delays the citizen's save.
   *
   * The save only writes the note; sending happens later and swallows the
   * failure (stamped, logged).
   */
  public function testFailingMailNeverFailsTheSave(): void {
    $open = (int) $this->workspaces['open']->id();
    $report = $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertNotNull($report->id(), 'the citizen report is stored');
    $this->assertSame([], $this->getMails(), 'nothing is sent inside the save');

    $failing = $this->createMock(MailManagerInterface::class);
    $failing->method('mail')->willThrowException(new \RuntimeException('SMTP down'));
    $notifier = $this->notifierWith($failing);
    $this->assertSame(WaitingReportsNotifier::FAILED, $notifier->sendIfDue($open, 1900000000));
    $this->assertSame([$open], $this->pendingIds(), 'nothing delivered: still due');
    $this->assertNull($this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open), 'the stamp is taken back');
    $this->assertNotEmpty(array_filter($this->log, static fn(string $line): bool => str_contains($line, 'SMTP down')));

    // Even the note itself cannot break a save: a failing store is logged.
    $brokenStore = $this->createMock(KeyValueFactoryInterface::class);
    $brokenStore->method('get')->willThrowException(new \RuntimeException('store down'));
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), $brokenStore);
    $notifier->noteHeldReport($report, new AnonymousUserSession());
    $this->assertNotEmpty(array_filter($this->log, static fn(string $line): bool => str_contains($line, 'store down')));
  }

  /**
   * A report marked while a decision runs stays due.
   *
   * The marker is overwritten during the count (after the decision read
   * it): the mail goes out for the old marker, the new one waits for its
   * turn after 24 hours.
   */
  public function testMarkerWrittenDuringTheDecisionStaysDue(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->createAs(new AnonymousUserSession(), 'open');
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), NULL, NULL, function (): void {
      $this->kv(WaitingReportsNotifier::PENDING_COLLECTION)->set((string) $this->workspaces['open']->id(), 'later');
    });
    $this->assertSame(WaitingReportsNotifier::SENT, $notifier->sendIfDue($open, 1900000000));
    $this->assertSame([$open], $this->pendingIds(), 'the newer marker stays due');
    $this->assertSame(WaitingReportsNotifier::TOO_SOON, $this->notifier()->sendIfDue($open, 1900000000 + 60));
    $this->assertSame(WaitingReportsNotifier::SENT, $this->notifier()->sendIfDue($open, 1900000000 + self::DAY));
    $this->assertSame([], $this->pendingIds());

    // Nothing waiting: the marker read is handled, a newer one is not.
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->setCurrentUser($this->accounts['platform admin']);
    foreach ($this->heldReports('open') as $held) {
      $held->setPublished()->save();
    }
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), NULL, NULL, function (): void {
      $this->kv(WaitingReportsNotifier::PENDING_COLLECTION)->set((string) $this->workspaces['open']->id(), 'later still');
    });
    $this->assertSame(WaitingReportsNotifier::NOTHING_WAITING, $notifier->sendIfDue($open, 1900000000 + 3 * self::DAY));
    $this->assertSame([$open], $this->pendingIds(), 'the newer marker stays due');
  }

  /**
   * Too soon decides without counting.
   */
  public function testTooSoonDoesNotCount(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->set((string) $open, 1900000000);
    $this->createAs(new AnonymousUserSession(), 'open');
    $counted = 0;
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), NULL, NULL, function () use (&$counted): void {
      $counted++;
    });
    $this->assertSame(WaitingReportsNotifier::TOO_SOON, $notifier->sendIfDue($open, 1900000000 + 3600));
    $this->assertSame(0, $counted);
    $this->assertSame([$open], $this->pendingIds());
  }

  /**
   * The stamp and the 24-hour rule use the current time, not the request's.
   */
  public function testStampUsesTheCurrentTime(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->createAs(new AnonymousUserSession(), 'open');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $time->method('getCurrentTime')->willReturn(1900000000);
    $time->method('getCurrentMicroTime')->willReturn(1900000000.5);
    $notifier = $this->notifierWith($this->container->get('plugin.manager.mail'), NULL, $time);
    $this->assertSame(WaitingReportsNotifier::SENT, $notifier->sendIfDue($open));
    $this->assertSame(1900000000, (int) $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open));
  }

  /**
   * Nobody got it: retried. Somebody got it: sent.
   */
  public function testDeliveryFailures(): void {
    $open = (int) $this->workspaces['open']->id();
    $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->set((string) $open, 1800000000);
    $this->createAs(new AnonymousUserSession(), 'open');

    $none = $this->createMock(MailManagerInterface::class);
    $none->method('mail')->willReturn(['result' => FALSE]);
    $this->assertSame(WaitingReportsNotifier::FAILED, $this->notifierWith($none)->sendIfDue($open, 1900000000));
    $this->assertSame([$open], $this->pendingIds(), 'still due');
    $this->assertSame(1800000000, (int) $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open), 'the previous stamp is back');

    $calls = 0;
    $partly = $this->createMock(MailManagerInterface::class);
    $partly->method('mail')->willReturnCallback(static function () use (&$calls): array {
      return ['result' => $calls++ > 0];
    });
    $this->assertSame(WaitingReportsNotifier::SENT, $this->notifierWith($partly)->sendIfDue($open, 1900000000));
    $this->assertSame(2, $calls);
    $this->assertSame([], $this->pendingIds(), 'one delivered: done');
    $this->assertSame(1900000000, (int) $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open));
  }

  /**
   * The whole send pipeline in non-production mail mode.
   *
   * Markaspot_mail's alter hooks run (branding, then the recipient fence):
   * the real recipient and the reply address are replaced by the override,
   * the subject names the real recipient, and no Cc or Bcc leaves.
   */
  public function testPipelineInNonProductionMailMode(): void {
    $this->setSetting('markaspot_mail_recipient_override', 'dev-inbox@example.test');
    $this->config('markaspot_fastmap.settings')->set('mail_from', 'noreply@example.org')->save();
    $this->accounts['operator']->block()->save();

    $this->createAs(new AnonymousUserSession(), 'open');
    $this->notifier()->flush(1900000000);
    $mails = $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]);
    $this->assertCount(1, $mails);
    $mail = $mails[0];
    $this->assertSame('dev-inbox@example.test', $mail['to']);
    $this->assertSame('[DEV→colleague@example.test] Reports are waiting for your approval: Open map', $mail['subject']);
    $headers = array_change_key_case((array) $mail['headers'], CASE_LOWER);
    $this->assertSame('dev-inbox@example.test', $headers['reply-to'] ?? NULL);
    $this->assertArrayNotHasKey('cc', $headers);
    $this->assertArrayNotHasKey('bcc', $headers);
    $this->assertStringContainsString('https://maps.example.org/open-map/dashboard/requests', $this->body($mail));
  }

  /**
   * The hourly cron sends what is due, the state goes with the workspace.
   */
  public function testCronAndDeletion(): void {
    $open = (int) $this->workspaces['open']->id();
    $now = (int) $this->container->get(TimeInterface::class)->getRequestTime();
    // A report noted a day after the last mail; this request does not send
    // it (the note is dropped from the request's own list), only cron does.
    $this->container->get('keyvalue')->get(WaitingReportsNotifier::MAILED_COLLECTION)->set((string) $open, $now - self::DAY - 1);
    $this->createAs(new AnonymousUserSession(), 'open');
    $this->assertSame([$open], $this->pendingIds());
    $this->container->get('state')->set('markaspot_fastmap.last_reports_waiting_run', 0);

    markaspot_fastmap_cron();
    $this->assertCount(2, $this->getMails(['key' => WaitingReportsNotifier::MAIL_KEY]), 'cron sent the due mail');
    $stamp = (int) $this->kv(WaitingReportsNotifier::MAILED_COLLECTION)->get((string) $open);
    $this->assertGreaterThanOrEqual($now, $stamp);
    $this->assertLessThanOrEqual(time(), $stamp);

    // A deleted workspace takes its state along.
    $empty = Group::create(['type' => 'jur', 'label' => 'Empty', 'field_slug' => 'empty']);
    $empty->save();
    $gid = (string) $empty->id();
    $collections = [
      WaitingReportsNotifier::PENDING_COLLECTION,
      WaitingReportsNotifier::HANDLED_COLLECTION,
      WaitingReportsNotifier::MAILED_COLLECTION,
    ];
    foreach ($collections as $collection) {
      $this->kv($collection)->set($gid, $now);
    }
    // Also outside self-service mode.
    $this->setSetting('markaspot_operating_mode', 'self_hosted');
    $empty->delete();
    foreach ($collections as $collection) {
      $this->assertNull($this->kv($collection)->get($gid), $collection);
    }
  }

  /**
   * Creates a report in a workspace as an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The creating account.
   * @param string $key
   *   The workspace key.
   * @param bool $ownSession
   *   Whether the request runs on the account's own login session.
   */
  private function createAs(AccountInterface $account, string $key, bool $ownSession = FALSE): NodeInterface {
    $this->setCurrentUser($account);
    $this->pushRequest($ownSession ? (int) $account->id() : NULL);
    try {
      $node = Node::create($this->reportValues($key, 'Citizen report') + ['status' => 1]);
      $node->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    return $this->reload($node);
  }

  /**
   * Pushes a JSON:API create request, optionally on a login session.
   */
  private function pushRequest(int|false|null $sessionUid): void {
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    if (is_int($sessionUid)) {
      $session = new Session(new MockArraySessionStorage());
      $session->set('uid', $sessionUid);
      $request->setSession($session);
    }
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Drops notes, mail times and collected mails.
   */
  private function reset(): void {
    foreach ($this->workspaces as $workspace) {
      $this->notifier()->forget((int) $workspace->id());
    }
    $this->clearMails();
  }

  /**
   * Empties the mail collector.
   */
  private function clearMails(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

  /**
   * The workspace IDs with a pending note.
   *
   * @return int[]
   *   Workspace IDs.
   */
  private function pendingIds(): array {
    return $this->notifier()->dueIds();
  }

  /**
   * The body of a collected mail as one string.
   */
  private function body(array $mail): string {
    return is_array($mail['body']) ? implode("\n", array_map('strval', $mail['body'])) : (string) $mail['body'];
  }

  /**
   * The held, unpublished reports of a workspace.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Reports.
   */
  private function heldReports(string $key): array {
    $nids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', $this->workspaces[$key]->id())
      ->condition('status', 0)
      ->execute();
    return array_filter(Node::loadMultiple($nids), fn(NodeInterface $node): bool => $this->review()->isHeld($node));
  }

  /**
   * The notifier of the container.
   */
  private function notifier(): WaitingReportsNotifier {
    return $this->container->get('markaspot_fastmap.waiting_reports_notifier');
  }

  /**
   * A notifier with other services, optionally running code in the count.
   */
  private function notifierWith(MailManagerInterface $mailManager, mixed $keyValue = NULL, ?TimeInterface $time = NULL, ?\Closure $onCount = NULL): WaitingReportsNotifier {
    $arguments = [
      $this->container->get('entity_type.manager'),
      $this->container->get('markaspot_fastmap.workspace_lifecycle'),
      $this->container->get('markaspot_fastmap.workspace_lifecycle_gate'),
      $this->container->get('markaspot_nuxt.review_before_publish'),
      $this->container->get('markaspot_group.hierarchy_resolver'),
      $this->container->get('module_handler'),
      $keyValue ?? $this->container->get('keyvalue'),
      $this->container->get('lock'),
      $mailManager,
      $this->container->get('config.factory'),
      $time ?? $this->container->get(TimeInterface::class),
      $this->container->get('logger.channel.markaspot_fastmap'),
    ];
    if ($onCount === NULL) {
      return new WaitingReportsNotifier(...$arguments);
    }
    return new class($onCount, ...$arguments) extends WaitingReportsNotifier {

      public function __construct(private readonly \Closure $onCount, mixed ...$arguments) {
        parent::__construct(...$arguments);
      }

      /**
       * {@inheritdoc}
       */
      public function countWaiting(int $gid): int {
        ($this->onCount)();
        return parent::countWaiting($gid);
      }

    };
  }

  /**
   * A key/value collection.
   */
  private function kv(string $collection): KeyValueStoreInterface {
    return $this->container->get('keyvalue')->get($collection);
  }

  /**
   * The review-before-publish service.
   */
  private function review(): ReviewBeforePublish {
    return $this->container->get('markaspot_nuxt.review_before_publish');
  }

  /**
   * Adds a workspace with approval on, a category and an initial status.
   */
  private function addWorkspace(string $key, ?string $record): void {
    $workspace = Group::create([
      'type' => 'jur',
      'label' => ucfirst($key) . ' map',
      'field_slug' => $key . '-map',
      'field_tier' => 'free',
      'field_visibility' => 'public',
      'field_nuxt_config' => json_encode(['features' => [ReviewBeforePublish::FLAG => TRUE]]),
    ]);
    $workspace->save();
    if ($record !== NULL) {
      // Marked as syncing: only the lifecycle service and syncing saves may
      // change the record of an existing workspace.
      $workspace->setSyncing(TRUE);
      $workspace->set(WorkspaceLifecycle::FIELD, $record)->save();
      $workspace->setSyncing(FALSE);
    }
    $this->workspaces[$key] = $workspace;
    $this->categories[$key] = $this->createTerm('service_category', ucfirst($key) . ' pothole', $workspace);
    $this->statuses[$key] = $this->createTerm('service_status', 'Open', $workspace, 'initial');
  }

  /**
   * Adds an account with a group role in a workspace.
   */
  private function member(string $key, string $name, string $groupRole, string $langcode, bool $active = TRUE, array $roles = []): User {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => $active ? 1 : 0,
      'preferred_langcode' => $langcode,
      'roles' => $roles,
    ]);
    $account->save();
    $this->workspaces[$key]->addMember($account, ['group_roles' => [$groupRole]]);
    // The membership syncs the global role onto the stored account; a stale
    // copy saved later would drop it again, and with it the group role.
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache([$account->id()]);
    return User::load($account->id());
  }

  /**
   * Stores the approval flag of a workspace.
   */
  private function setFlag(string $key, bool $value): void {
    $workspace = Group::load($this->workspaces[$key]->id());
    $config = json_decode((string) $workspace->get('field_nuxt_config')->value, TRUE) ?: [];
    $config['features'][ReviewBeforePublish::FLAG] = $value;
    $workspace->set('field_nuxt_config', json_encode($config))->save();
    $this->workspaces[$key] = $workspace;
  }

  /**
   * Stores the visibility of a workspace.
   */
  private function setVisibility(string $key, string $visibility): void {
    $workspace = Group::load($this->workspaces[$key]->id());
    $workspace->set('field_visibility', $visibility)->save();
    $this->workspaces[$key] = $workspace;
  }

  /**
   * Values of a report in a workspace.
   */
  private function reportValues(string $key, string $title): array {
    return [
      'type' => 'service_request',
      'title' => $title,
      'uid' => 0,
      'body' => ['value' => 'Something is broken.', 'format' => 'plain_text'],
      'field_category' => $this->categories[$key]->id(),
      'field_status' => $this->statuses[$key]->id(),
      'field_jurisdiction' => $this->workspaces[$key]->id(),
    ];
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

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $relationshipStorage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }
    $roles = [
      'jur-tenant_admin' => [
        'view group',
        'view group_node:service_request entity',
        'view any unpublished group_node:service_request entity',
        'update any group_node:service_request entity',
      ],
      'jur-member' => ['view group'],
    ];
    foreach ($roles as $id => $permissions) {
      GroupRole::create([
        'id' => $id,
        'label' => $id,
        'group_type' => 'jur',
        'scope' => 'individual',
        'permissions' => $permissions,
      ])->save();
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_visibility', 'string');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'jur', 'field_tier', 'list_string', [
      'allowed_values' => ['free' => 'Free', 'starter' => 'Starter', 'pro' => 'Pro', 'heart' => 'Heart'],
    ]);
    $this->createField('group', 'jur', 'field_expiry_date', 'timestamp');
    $this->createField('group', 'jur', 'field_stripe_subscription_id', 'string');
    $this->createField('group', 'jur', WorkspaceLifecycle::FIELD, 'string_long');
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
