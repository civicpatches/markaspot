<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\OperatorIncompleteException;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests opening a map, the save rules of an open map and the support mail.
 *
 * The mail tests stop at the mail manager: they prove the recipient, the key,
 * the language and the parameters handed over, and that nothing is handed
 * over without a valid support address. Rendering through hook_mail() and
 * the mail collector run in WorkspaceOpeningKernelTest.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceOpening
 * @group markaspot_fastmap
 */
class WorkspaceOpeningTest extends UnitTestCase {

  use OperatorWorkspaceTrait;

  private const REQUEST_TIME = 1_800_005_000;

  private const NOT_OPEN = '{"v":1,"acceptance":{"terms":"t1","dpa":"d1","declaration":"o1","langcode":"en","accepted_at":1800000000,"uid":7},"opening":null}';

  /**
   * Settings of markaspot_fastmap.settings for the next service.
   *
   * @var array<string, mixed>
   */
  private array $settings = [];

  /**
   * Mails handed to the mail manager.
   *
   * @var array<int, array>
   */
  private array $mails = [];

  /**
   * Log entries, as "level: message".
   *
   * @var string[]
   */
  private array $log = [];

  /**
   * The mail manager's result for the next mail.
   */
  private mixed $mailResult = TRUE;

  /**
   * The lifecycle service of the last service() call.
   */
  private WorkspaceLifecycle $lifecycle;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['markaspot_operating_mode' => 'saas']);
    $this->settings = [
      'support_email' => 'support@example.org',
      'workspace_base_url' => 'https://maps.example.org',
      'mail_from' => 'no-reply@example.org',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Builds the service.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $stored
   *   What the storage loads unchanged.
   * @param bool $lockFree
   *   Whether the lock can be acquired.
   */
  private function service(?GroupInterface $stored = NULL, bool $lockFree = TRUE): WorkspaceOpening {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $this->settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturn($stored);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($lockFree);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->method('mail')->willReturnCallback(function (...$args): array {
      $this->mails[] = $args;
      if ($this->mailResult instanceof \Throwable) {
        throw $this->mailResult;
      }
      return ['result' => $this->mailResult];
    });

    $logger = $this->createMock(LoggerInterface::class);
    foreach (['error', 'warning', 'notice'] as $level) {
      $logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
        $this->log[] = "$level: $message";
      });
    }

    $this->lifecycle = new WorkspaceLifecycle($factory, $time, $modules);
    return new WorkspaceOpening(
      $entityTypeManager,
      $this->lifecycle,
      $modules,
      $lock,
      $mailManager,
      $factory,
      $logger,
    );
  }

  /**
   * A workspace with a record whose field value changes on set().
   *
   * @param string|null $record
   *   The stored record.
   * @param array|null $address
   *   The address properties.
   * @param string|null $email
   *   The contact e-mail.
   * @param int $saves
   *   Counts save() calls.
   * @param string $visibility
   *   The stored field_visibility.
   * @param bool $published
   *   Whether the workspace is published.
   * @param string $label
   *   The map name.
   * @param bool $syncing
   *   Whether the save is marked as syncing.
   */
  private function recordWorkspace(?string $record, ?array $address, ?string $email, int &$saves = 0, string $visibility = 'public', bool $published = TRUE, string $label = 'Tree map', bool $syncing = FALSE): GroupInterface {
    $value = $record;
    $recordItems = $this->createMock(FieldItemListInterface::class);
    $recordItems->method('isEmpty')->willReturnCallback(static function () use (&$value): bool {
      return $value === NULL;
    });
    $recordItems->method('__get')->with('value')->willReturnCallback(static function () use (&$value): ?string {
      return $value;
    });
    $group = $this->operatorWorkspace($address, $email, TRUE, [
      WorkspaceLifecycle::FIELD => $recordItems,
      'field_slug' => 'tree-map',
      'field_visibility' => $visibility,
    ]);
    $group->method('isPublished')->willReturn($published);
    $group->method('isSyncing')->willReturn($syncing);
    $group->method('set')->willReturnCallback(static function (string $field, mixed $new) use (&$value, $group): GroupInterface {
      if ($field === WorkspaceLifecycle::FIELD) {
        $value = $new;
      }
      return $group;
    });
    $group->method('id')->willReturn(3);
    $group->method('label')->willReturn($label);
    $group->method('getRevisionId')->willReturn(17);
    $group->method('isNew')->willReturn(FALSE);
    $group->method('save')->willReturnCallback(static function () use (&$saves): int {
      $saves++;
      return 2;
    });
    return $group;
  }

  /**
   * An account that opens maps.
   */
  private function account(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(9);
    $account->method('getEmail')->willReturn('owner@example.org');
    return $account;
  }

  /**
   * The support address: the configured one, else nothing at all.
   *
   * @covers ::supportRecipient
   */
  public function testSupportRecipient(): void {
    $cases = [
      'valid' => ['support@example.org', 'support@example.org'],
      'trimmed' => [' support@example.org ', 'support@example.org'],
      'empty' => ['', NULL],
      'unset' => [NULL, NULL],
      'not an address' => ['support', NULL],
      'two addresses' => ['a@example.org, b@example.org', NULL],
      'header injection' => ["support@example.org\nBcc: x@example.org", NULL],
      'not a string' => [['support@example.org'], NULL],
    ];
    foreach ($cases as $label => [$value, $expected]) {
      $this->settings['support_email'] = $value;
      $this->assertSame($expected, $this->service()->supportRecipient(), $label);
    }
  }

  /**
   * Without a valid support address nothing is sent and an error is logged.
   *
   * Never the site mail, mail_from or an account: the mail manager is not
   * called at all.
   *
   * @covers ::sendSupportMail
   */
  public function testNoSupportAddressSendsNothing(): void {
    $group = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    foreach (['', NULL, 'support'] as $value) {
      $this->settings['support_email'] = $value;
      $this->log = [];
      $this->assertFalse($this->service()->sendSupportMail($group, $this->openingSection(), $this->account()));
      $this->assertSame([], $this->mails, var_export($value, TRUE));
      $this->assertCount(1, $this->log);
      $this->assertStringStartsWith('error: No support mail', $this->log[0]);
    }
  }

  /**
   * The support mail goes to the support address, in English, once.
   *
   * @covers ::sendSupportMail
   * @covers ::mailParams
   */
  public function testSupportMailRecipientAndParameters(): void {
    $group = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $this->assertTrue($this->service()->sendSupportMail($group, $this->openingSection(), $this->account()));
    $this->assertCount(1, $this->mails);
    [$module, $key, $to, $langcode, $params, $reply] = $this->mails[0];
    $this->assertSame(
      ['markaspot_fastmap', WorkspaceOpening::MAIL_KEY, 'support@example.org', 'en'],
      [$module, $key, $to, $langcode],
    );
    $this->assertSame('no-reply@example.org', $reply, 'Reply address as in the other mails of the module.');
    $this->assertSame([
      'workspace_name' => 'Tree map',
      'workspace_slug' => 'tree-map',
      'workspace_id' => '3',
      'workspace_url' => 'https://maps.example.org/tree-map',
      'operator_name' => 'Example Org',
      'operator_address' => 'Main street 1, 12345 Town, DE',
      'operator_email' => 'op@example.org',
      'opened_by' => 'owner@example.org',
      'opened_at' => '2027-01-15 22:30 UTC',
    ], $params);

    // A person is named by first and last name; a template base URL.
    $this->settings['workspace_base_url'] = 'https://example.org/{slug}/start';
    $person = $this->openingSection(['given_name' => 'Jane', 'family_name' => 'Doe'] + array_diff_key($this->openingSection()['operator'], ['organization' => 1]));
    $params = $this->service()->mailParams($group, $person, $this->account());
    $this->assertSame('Jane Doe', $params['operator_name']);
    $this->assertSame('https://example.org/tree-map/start', $params['workspace_url']);
  }

  /**
   * A failed send is logged and reported, never thrown.
   *
   * @covers ::sendSupportMail
   */
  public function testFailedSendIsLogged(): void {
    $group = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    foreach ([FALSE, new \RuntimeException('SMTP down')] as $result) {
      $this->mailResult = $result;
      $this->log = [];
      $this->assertFalse($this->service()->sendSupportMail($group, $this->openingSection(), $this->account()));
      $this->assertCount(1, $this->log);
      $this->assertStringStartsWith('error: The support mail', $this->log[0]);
    }
  }

  /**
   * Opening: refusals before anything is written.
   *
   * @covers ::open
   */
  public function testOpenRefusals(): void {
    $saves = 0;
    $account = $this->account();

    // No record, or not the self-service platform: not applicable.
    $noRecord = $this->recordWorkspace(NULL, OperatorDetailsTest::ORGANISATION, 'op@example.org', $saves);
    $this->assertSame(['status' => WorkspaceOpening::NOT_APPLICABLE], $this->service($noRecord)->open($noRecord, $account, TRUE));
    $closed = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org', $saves);
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertSame(['status' => WorkspaceOpening::NOT_APPLICABLE], $this->service($closed)->open($closed, $account, TRUE));
    new Settings(['markaspot_operating_mode' => 'saas']);

    // Without the confirmation.
    $this->assertSame(['status' => WorkspaceOpening::CONFIRMATION_REQUIRED], $this->service($closed)->open($closed, $account, FALSE));

    // Incomplete operator data, checked on the stored state.
    $incomplete = $this->recordWorkspace(self::NOT_OPEN, ['postal_code' => ''] + OperatorDetailsTest::ORGANISATION, NULL, $saves);
    $this->assertSame(
      ['status' => WorkspaceOpening::OPERATOR_INCOMPLETE, 'missing' => ['postal_code', 'email']],
      $this->service($incomplete)->open($closed, $account, TRUE),
    );

    // Another request holds the lock.
    $this->assertSame(['status' => WorkspaceOpening::BUSY], $this->service($closed, FALSE)->open($closed, $account, TRUE));

    $this->assertSame(0, $saves, 'Nothing was saved.');
    $this->assertSame([], $this->mails, 'No mail.');
  }

  /**
   * Opening writes the record once, as a new revision, and mails once.
   *
   * @covers ::open
   */
  public function testOpenWritesOnceAndMailsOnce(): void {
    $saves = 0;
    $stored = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org', $saves);
    $stored->expects($this->once())->method('setNewRevision')->with(TRUE);
    $stored->expects($this->once())->method('setRevisionUserId')->with(9);
    $stored->expects($this->once())->method('setRevisionLogMessage')->with(WorkspaceOpening::REVISION_LOG);
    $service = $this->service($stored);

    $result = $service->open($stored, $this->account(), TRUE);
    $this->assertSame(WorkspaceOpening::OPENED, $result['status']);
    $this->assertSame(1, $saves);
    $record = WorkspaceLifecycle::parse($stored->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertSame([
      'opened_at' => self::REQUEST_TIME,
      'uid' => 9,
      'revision_id' => 17,
      'operator' => OperatorDetailsTest::ORGANISATION + ['email' => 'op@example.org'],
    ], $record['opening']);
    $this->assertCount(1, $this->mails);
    // The revision log holds no personal data.
    $this->assertStringNotContainsString('Example', WorkspaceOpening::REVISION_LOG);

    // The second call finds the map open: no save, no mail.
    $again = $service->open($stored, $this->account(), TRUE);
    $this->assertSame(WorkspaceOpening::ALREADY_OPEN, $again['status']);
    $this->assertSame(1, $saves);
    $this->assertCount(1, $this->mails);

    // A failed mail does not undo the opening.
    $this->mailResult = new \RuntimeException('SMTP down');
    $saves = 0;
    $other = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org', $saves);
    $this->assertSame(WorkspaceOpening::OPENED, $this->service($other)->open($other, $this->account(), TRUE)['status']);
    $this->assertSame(1, $saves);
    $this->assertNotNull(WorkspaceLifecycle::parse($other->get(WorkspaceLifecycle::FIELD)->value)['opening']);
  }

  /**
   * An open map keeps its opening and its complete operator data.
   *
   * @covers ::enforceOnSave
   */
  public function testSaveRulesOfAnOpenMap(): void {
    $open = json_encode(['opening' => $this->openingSection()] + json_decode(self::NOT_OPEN, TRUE));
    $original = $this->recordWorkspace($open, OperatorDetailsTest::ORGANISATION, 'op@example.org');

    // Emptying the street: refused, the missing parts named.
    $saving = $this->recordWorkspace($open, ['address_line1' => ''] + OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $saving->method('getOriginal')->willReturn($original);
    try {
      $this->service()->enforceOnSave($saving);
      $this->fail('Emptying the street of an open map was not refused.');
    }
    catch (OperatorIncompleteException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertSame(['address_line1'], $e->missing);
      $this->assertStringStartsWith('operator_incomplete:', $e->getMessage());
    }

    // Switching from the organisation to a complete person: allowed.
    $person = $this->recordWorkspace($open, ['organization' => '', 'given_name' => 'Jane', 'family_name' => 'Doe'] + OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $person->method('getOriginal')->willReturn($original);
    $this->service()->enforceOnSave($person);

    // A stale entity with the closed record: the stored opening is kept.
    $stale = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $stale->method('getOriginal')->willReturn($original);
    $this->service()->enforceOnSave($stale);
    $this->assertSame($open, $stale->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertStringStartsWith('warning:', end($this->log));

    // Already incomplete before (an unrelated save): passes.
    $incompleteOriginal = $this->recordWorkspace($open, ['locality' => ''] + OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $unrelated = $this->recordWorkspace($open, ['locality' => ''] + OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $unrelated->method('getOriginal')->willReturn($incompleteOriginal);
    $this->service()->enforceOnSave($unrelated);

    // A map that is not open, and the self-hosted platform: no rule.
    $closedOriginal = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $emptied = $this->recordWorkspace(self::NOT_OPEN, NULL, NULL);
    $emptied->method('getOriginal')->willReturn($closedOriginal);
    $this->service()->enforceOnSave($emptied);
    $emptiedOpen = $this->recordWorkspace($open, NULL, NULL);
    $emptiedOpen->method('getOriginal')->willReturn($original);
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->service()->enforceOnSave($emptiedOpen);
    new Settings(['markaspot_operating_mode' => 'saas']);
    $this->expectException(OperatorIncompleteException::class);
    $this->service()->enforceOnSave($emptiedOpen);
  }

  /**
   * A blocked or unpublished map is not opened, for anyone.
   *
   * @covers ::open
   * @covers ::isBlocked
   */
  public function testBlockedMapIsNotOpened(): void {
    $cases = [
      'blocked' => ['blocked', TRUE],
      'blocked with stray case' => [' Blocked', TRUE],
      'unpublished' => ['public', FALSE],
    ];
    foreach ($cases as $label => [$visibility, $published]) {
      $saves = 0;
      $stored = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org', $saves, $visibility, $published);
      $this->assertSame(['status' => WorkspaceOpening::BLOCKED], $this->service($stored)->open($stored, $this->account(), TRUE), $label);
      $this->assertSame(0, $saves, $label);
    }
    $this->assertSame([], $this->mails);
    $this->assertFalse(WorkspaceOpening::isBlocked($this->recordWorkspace(self::NOT_OPEN, NULL, NULL)));
  }

  /**
   * Only the lifecycle class changes the record, open map or not.
   *
   * @covers ::enforceOnSave
   */
  public function testRecordIsPinnedOnClosedMap(): void {
    $open = json_encode(['opening' => $this->openingSection()] + json_decode(self::NOT_OPEN, TRUE));
    $closedOriginal = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');

    // A stale entity still carrying the opening a support reset removed.
    $stale = $this->recordWorkspace($open, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $stale->method('getOriginal')->willReturn($closedOriginal);
    $this->service()->enforceOnSave($stale);
    $this->assertSame(self::NOT_OPEN, $stale->get(WorkspaceLifecycle::FIELD)->value, 'The stored record without the opening is kept.');
    $this->assertStringStartsWith('warning: A save of workspace', end($this->log));

    // The lifecycle class's own write passes, once.
    $opening = $this->recordWorkspace(self::NOT_OPEN, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $opening->method('getOriginal')->willReturn($closedOriginal);
    $service = $this->service();
    $this->lifecycle->writeOpening($opening, 9, OperatorDetailsTest::ORGANISATION + ['email' => 'op@example.org'], 17);
    $written = $opening->get(WorkspaceLifecycle::FIELD)->value;
    $service->enforceOnSave($opening);
    $this->assertSame($written, $opening->get(WorkspaceLifecycle::FIELD)->value);
    $this->assertNotNull(WorkspaceLifecycle::parse($written)['opening']);
    // The mark is used up: a second save of the same object is pinned.
    $service->enforceOnSave($opening);
    $this->assertSame(self::NOT_OPEN, $opening->get(WorkspaceLifecycle::FIELD)->value);
  }

  /**
   * A save marked as syncing is the support path: no rule applies.
   *
   * @covers ::enforceOnSave
   */
  public function testSyncingSaveIsExempt(): void {
    $open = json_encode(['opening' => $this->openingSection()] + json_decode(self::NOT_OPEN, TRUE));
    $original = $this->recordWorkspace($open, OperatorDetailsTest::ORGANISATION, 'op@example.org');
    $saves = 0;
    $reset = $this->recordWorkspace(self::NOT_OPEN, NULL, NULL, $saves, 'public', TRUE, 'Tree map', TRUE);
    $reset->method('getOriginal')->willReturn($original);
    $this->service()->enforceOnSave($reset);
    $this->assertSame(self::NOT_OPEN, $reset->get(WorkspaceLifecycle::FIELD)->value, 'The opening is taken back, not restored.');
  }

  /**
   * The mail parameters are one line of visible text each.
   *
   * @covers ::mailParams
   */
  public function testMailParametersAreSingleLine(): void {
    $saves = 0;
    $group = $this->recordWorkspace(self::NOT_OPEN, NULL, NULL, $saves, 'public', TRUE, "Tree\u{2028}map\u{202E}Bcc: x@example.org");
    $params = $this->service()->mailParams($group, $this->openingSection(), $this->account());
    $this->assertSame('Tree map Bcc: x@example.org', $params['workspace_name']);
    foreach ($params as $key => $value) {
      $this->assertDoesNotMatchRegularExpression('/[\p{C}\p{Zl}\p{Zp}]/u', $value, $key);
    }
  }

  /**
   * A valid opening section.
   */
  private function openingSection(?array $operator = NULL): array {
    return [
      'opened_at' => 1_800_052_200,
      'uid' => 9,
      'revision_id' => 17,
      'operator' => $operator ?? OperatorDetailsTest::ORGANISATION + ['email' => 'op@example.org'],
    ];
  }

}
