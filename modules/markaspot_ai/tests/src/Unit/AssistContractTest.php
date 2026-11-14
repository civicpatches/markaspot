<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Controller\AttributeController;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

require_once __DIR__ . '/../../../markaspot_ai.module';
require_once dirname(__DIR__, 4) . '/markaspot_nuxt/src/Service/FeatureScopeResolver.php';

/**
 * Pins the assist endpoint contract end to end through the controller.
 *
 * Runs the real controller, the real form assistant and the real feature
 * scope resolver; only the report context loaders and the provider client are
 * stubbed. Every provider call (messages and options) and the raw response
 * body are compared with fixtures recorded from the code before the
 * per-tenant brief switch existed, so "switch off" provably equals that code.
 *
 * Re-record (only when a deliberate change of the off state is approved):
 * MARKASPOT_AI_RECORD_ASSIST_FIXTURES=1.
 */
#[Group('markaspot_ai')]
final class AssistContractTest extends UnitTestCase {

  /**
   * Report under test.
   */
  private const NID = 321;

  /**
   * Jurisdiction of the report.
   */
  private const JURISDICTION = 42;

  /**
   * Tenant root of the jurisdiction.
   */
  private const ROOT = 1;

  /**
   * Unrelated jurisdiction a request may name.
   */
  private const FOREIGN = 99;

  /**
   * Provider reply for note drafts.
   */
  private const NOTE_REPLY = '{"status_note":"Thank you for your report. We have forwarded it."}';

  /**
   * Provider reply for the field check, including reasons.
   */
  private const FIELD_REPLY = '{"body":"The lamp head hangs loose.","attributes":{"lamp_type":"sodium","pole":"wood"},"priority":true,"reasons":{"body":"<b>Photo 1</b> shows the loose lamp head.","priority":"Citizen writes \"cables hang down\".","attributes.lamp_type":"The photo shows an orange sodium lamp."}}';

  /**
   * Provider reply for the legacy path.
   */
  private const LEGACY_REPLY = '{"status_note":"We are looking into it.","status_term_id":"status-in-progress","priority":true}';

  /**
   * Every provider call: messages and options, in call order.
   *
   * @var array<int, array{messages: array, options: array}>
   */
  private array $calls = [];

  /**
   * Stored field_nuxt_config per group ID.
   *
   * @var array<int, array|null>
   */
  private array $groupConfigs = [];

  /**
   * Whether the jurisdiction hangs below a separate tenant root.
   */
  private bool $childOfRoot = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->calls = [];
    $this->childOfRoot = FALSE;
    $this->groupConfigs = [
      self::JURISDICTION => ['features' => ['aiProcessing' => TRUE]],
      self::ROOT => NULL,
      self::FOREIGN => ['features' => ['aiProcessing' => TRUE, 'aiNoteBrief' => TRUE]],
    ];
  }

  /**
   * With the switch off, every case equals the recorded baseline.
   */
  #[DataProvider('baselineCases')]
  public function testSwitchOffMatchesPinnedBaseline(string $case): void {
    [$body, $features, $reply] = self::cases()[$case];
    $this->groupConfigs[self::JURISDICTION] = $features === NULL ? NULL : ['features' => $features];
    $this->assertMatchesBaseline($case, $this->assist($body, $reply));
  }

  /**
   * All pinned cases.
   */
  public static function baselineCases(): array {
    return array_map(static fn(string $case): array => [$case], array_combine(array_keys(self::cases()), array_keys(self::cases())));
  }

  /**
   * With the switch off, the declared origin is ignored, valid or not.
   */
  #[DataProvider('ignoredOrigins')]
  public function testSwitchOffIgnoresNoteOrigin(mixed $origin): void {
    foreach (['note_text', 'note_receipt', 'note_instruction', 'field_check', 'legacy'] as $case) {
      [$body, $features, $reply] = self::cases()[$case];
      $this->groupConfigs[self::JURISDICTION] = ['features' => $features + ['aiNoteBrief' => FALSE]];
      $this->calls = [];
      $this->assertMatchesBaseline($case, $this->assist(self::withOrigin($body, $origin), $reply));
    }
  }

  /**
   * Origins an off-state request may carry.
   */
  public static function ignoredOrigins(): array {
    return [
      'absent' => [self::ABSENT],
      'manual' => ['manual'],
      'text_block' => ['text_block'],
      'ai' => ['ai'],
      'unknown' => ['typed'],
      'number' => [5],
      'null' => [NULL],
    ];
  }

  /**
   * The legacy path without a draft ignores the switch in both states.
   */
  public function testLegacyPathIgnoresSwitch(): void {
    foreach ([TRUE, FALSE] as $enabled) {
      foreach ([self::ABSENT, 'manual', 'bogus'] as $origin) {
        [$body, $features, $reply] = self::cases()['legacy'];
        $this->groupConfigs[self::JURISDICTION] = ['features' => $features + ['aiNoteBrief' => $enabled]];
        $this->calls = [];
        $this->assertMatchesBaseline('legacy', $this->assist(self::withOrigin($body, $origin), $reply));
      }
    }
  }

  /**
   * Marker for "no note_origin key in the request".
   */
  private const ABSENT = "\0absent";

  /**
   * The pinned request cases.
   *
   * @return array<string, array{0: array, 1: array|null, 2: string}>
   *   Request body, tenant features and provider reply per case.
   */
  private static function cases(): array {
    $note = static fn(string $text, string $instruction = ''): array => [
      'nid' => self::NID,
      'langcode' => 'en',
      'fields' => ['status_note'],
      'draft' => ['status_note' => $text],
      'instruction' => $instruction,
    ];
    $on = ['aiProcessing' => TRUE];
    $fieldCheck = [
      'nid' => self::NID,
      'langcode' => 'en',
      'fields' => ['body', 'attributes', 'priority'],
      'draft' => [
        'body' => 'The lamp in front of number 12 is dark, cables hang down.',
        'priority' => FALSE,
        'attributes' => ['lamp_type' => 'led'],
      ],
    ];
    return [
      'note_text' => [$note('tell them we forwarded it'), $on, self::NOTE_REPLY],
      'note_receipt' => [$note('   '), $on, self::NOTE_REPLY],
      'note_instruction' => [$note('', 'Ask for a photo of the lamp.'), $on, self::NOTE_REPLY],
      'note_combined' => [$note('the crew comes on Tuesday', 'Thank them.'), $on, self::NOTE_REPLY],
      'note_unusable' => [$note('tell them we forwarded it'), $on, '{"status_note":"tell them we forwarded it"}'],
      'note_refusal' => [$note('tell them we forwarded it'), $on, ''],
      'field_check' => [$fieldCheck, $on, self::FIELD_REPLY],
      'field_check_invalid_json' => [$fieldCheck, $on, 'not json'],
      'legacy' => [[
        'nid' => self::NID,
        'langcode' => 'en',
        'fields' => ['status_note', 'priority'],
      ], $on, self::LEGACY_REPLY],
      'error_draft_key' => [
        ['draft' => ['contact_email' => 'private@example.test']] + $note('x'),
        $on,
        self::NOTE_REPLY,
      ],
      'error_instruction' => [['instruction' => 42] + $note('x'), $on, self::NOTE_REPLY],
      'error_ai_disabled' => [
        $note('tell them we forwarded it'),
        ['aiProcessing' => FALSE, 'aiNoteBrief' => TRUE],
        self::NOTE_REPLY,
      ],
      'error_no_config' => [$note('tell them we forwarded it'), NULL, self::NOTE_REPLY],
    ];
  }

  /**
   * Adds or omits a top-level note_origin.
   */
  private static function withOrigin(array $body, mixed $origin): array {
    if ($origin !== self::ABSENT) {
      $body['note_origin'] = $origin;
    }
    return $body;
  }

  /**
   * Runs one request through the controller and records the outcome.
   *
   * @return array{status: int, body: string, calls: array}
   *   Status code, raw response body and every provider call.
   */
  private function assist(array $body, string $reply, ?callable $tweak = NULL): array {
    $node = $this->node();
    $service = $this->service($reply);
    $this->installContainer($node);
    if ($tweak !== NULL) {
      $tweak($node, $service);
    }
    $controller = new AttributeController(
      $this->createMock(Connection::class),
      $this->createMock(QueueFactory::class),
      $service,
      new RequestStack(),
    );
    $request = Request::create('/api/markaspot-ai/assist', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    $response = $controller->assist($request);
    return [
      'status' => $response->getStatusCode(),
      'body' => (string) $response->getContent(),
      'calls' => $this->calls,
    ];
  }

  /**
   * Compares an outcome with its fixture, recording it on request.
   */
  private function assertMatchesBaseline(string $case, array $outcome): void {
    $file = self::fixturePath($case);
    $encoded = self::encode($outcome);
    if (getenv('MARKASPOT_AI_RECORD_ASSIST_FIXTURES') === '1') {
      file_put_contents($file, $encoded);
    }
    $this->assertFileExists($file, "Missing baseline fixture for $case.");
    $this->assertSame(file_get_contents($file), $encoded, "Off-state contract changed for $case.");
  }

  /**
   * Path of a recorded fixture.
   */
  private static function fixturePath(string $case): string {
    return dirname(__DIR__, 2) . '/fixtures/assist/' . $case . '.json';
  }

  /**
   * Stable, readable encoding of an outcome.
   */
  private static function encode(array $outcome): string {
    return json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
  }

  /**
   * Builds the form assistant with stubbed context loaders and provider.
   */
  private function service(string $reply): AttributeFillingService {
    $service = $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'getNodeImages',
        'buildStatusHistory',
        'buildInternalRemarks',
        'getJurisdictionPrompt',
        'buildStatusOptions',
        'loadStatusOptions',
        'parseServiceDefinition',
      ])
      ->getMock();
    $service->method('getNodeImages')->willReturn([
      ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']],
    ]);
    $service->method('buildStatusHistory')->willReturn("[2026-11-10] Received: Thank you, we have received your report.");
    $service->method('buildInternalRemarks')->willReturn('Crew assigned internally.');
    $service->method('getJurisdictionPrompt')->willReturn('Use a friendly, short tone.');
    $service->method('buildStatusOptions')->willReturn('- status-in-progress: In progress');
    $status = $this->createMock(TermInterface::class);
    $status->method('uuid')->willReturn('status-in-progress');
    $service->method('loadStatusOptions')->willReturn([$status]);
    $service->method('parseServiceDefinition')->willReturn([
      [
        'code' => 'lamp_type',
        'datatype' => 'singlevaluelist',
        'description' => 'Lamp type',
        'variable' => TRUE,
        'values' => [['key' => 'led', 'name' => 'LED'], ['key' => 'sodium', 'name' => 'Sodium']],
      ],
      [
        'code' => 'pole',
        'datatype' => 'singlevaluelist',
        'description' => 'Pole material',
        'variable' => TRUE,
        'values' => [['key' => 'steel', 'name' => 'Steel'], ['key' => 'wood', 'name' => 'Wood']],
      ],
    ]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $client = $this->createMock(AiClientService::class);
    $client->method('resolveChatModel')->willReturn('test-model');
    $client->method('chat')->willReturnCallback(function (array $messages, array $options) use ($reply): array {
      $this->calls[] = ['messages' => $messages, 'options' => $options];
      return ['choices' => [['message' => ['content' => $reply]]]];
    });
    foreach ([
      'aiClient' => $client,
      'configFactory' => $factory,
      'logger' => $this->createMock(LoggerInterface::class),
      'hierarchyResolver' => NULL,
    ] as $property => $value) {
      (new \ReflectionProperty(AttributeFillingService::class, $property))->setValue($service, $value);
    }
    return $service;
  }

  /**
   * Installs the services the controller and the procedural gates read.
   */
  private function installContainer(NodeInterface $node): void {
    $nodes = $this->createMock(EntityStorageInterface::class);
    $nodes->method('load')->willReturnCallback(static fn($id): ?NodeInterface => (int) $id === self::NID ? $node : NULL);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturnCallback(fn($id): ?GroupInterface => array_key_exists((int) $id, $this->groupConfigs) ? $this->group((int) $id) : NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(static fn(string $type): EntityStorageInterface => $type === 'node' ? $nodes : $groups);

    $nuxt = $this->createMock(ImmutableConfig::class);
    $nuxt->method('get')->willReturn(NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($nuxt);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->willReturnCallback(
      fn(int $id): int => $id === self::JURISDICTION && $this->childOfRoot ? self::ROOT : $id,
    );

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')->willReturn(TRUE);
    $user->method('getRoles')->willReturn(['authenticated']);
    $user->method('id')->willReturn(7);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('config.factory', $configFactory);
    $container->set('current_user', $user);
    $container->set('logger.factory', $loggers);
    $container->set('markaspot_nuxt.feature_scope_resolver', new FeatureScopeResolver($manager, $configFactory, $hierarchy));
    \Drupal::setContainer($container);
  }

  /**
   * Builds a jurisdiction group with its stored configuration.
   */
  private function group(int $id): GroupInterface {
    $config = $this->groupConfigs[$id];
    $json = $config === NULL ? NULL : json_encode($config, JSON_THROW_ON_ERROR);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_nuxt_config' && $json !== NULL);
    $group->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => $this->field(['value' => $json]));
    return $group;
  }

  /**
   * Builds the persisted report; it must never be saved.
   */
  private function node(): NodeInterface {
    $category = $this->createMock(TermInterface::class);
    $category->method('hasTranslation')->willReturn(FALSE);
    $category->method('label')->willReturn('Streetlight');
    $status = $this->createMock(TermInterface::class);
    $status->method('label')->willReturn('In progress');
    $status->method('uuid')->willReturn('status-in-progress');
    $organisation = $this->createMock(GroupInterface::class);
    $organisation->method('label')->willReturn('Roads department');
    $fields = [
      'body' => $this->field(['value' => 'The lamp in front of number 12 has been dark since Monday.']),
      'field_category' => $this->field(['entity' => $category]),
      'field_request_attributes' => $this->field(['value' => '{"lamp_type":"led"}']),
      'field_status' => $this->field(['entity' => $status]),
      'field_organisation' => $this->field(['entity' => $organisation]),
      'field_status_notes' => $this->field(['references' => []]),
      'field_priority' => $this->field(['value' => 0]),
      'field_internal_remark' => $this->field(['value' => 'Crew assigned internally.']),
      'field_jurisdiction' => $this->field(['target_id' => self::JURISDICTION]),
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(self::NID);
    $node->method('bundle')->willReturn('service_request');
    $node->method('label')->willReturn('Streetlight out at Elm Street');
    $node->method('access')->willReturn(TRUE);
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn(string $name) => $fields[$name]);
    $node->expects($this->never())->method('save');
    return $node;
  }

  /**
   * Builds an accessible field list with scalar, entity or reference values.
   */
  private function field(array $spec): FieldItemListInterface {
    $value = $spec['value'] ?? NULL;
    $entity = $spec['entity'] ?? NULL;
    $targetId = $spec['target_id'] ?? NULL;
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('access')->willReturn(TRUE);
    $field->method('isEmpty')->willReturn($value === NULL && $entity === NULL && $targetId === NULL && !isset($spec['references']));
    $field->method('__get')->willReturnCallback(static fn(string $name): mixed => match ($name) {
      'value' => $value,
      'entity' => $entity,
      'target_id' => $targetId,
      default => NULL,
    });
    $field->method('getValue')->willReturn(match (TRUE) {
      $targetId !== NULL => [['target_id' => $targetId]],
      $value !== NULL => [['value' => $value]],
      default => [],
    });
    $field->method('referencedEntities')->willReturn($spec['references'] ?? []);
    return $field;
  }

}
