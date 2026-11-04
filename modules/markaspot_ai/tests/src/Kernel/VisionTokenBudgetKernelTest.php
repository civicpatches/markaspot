<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Controller\UsageController;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves budget separation and reporting using real usage rows and SQL sums.
 */
#[Group('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class VisionTokenBudgetKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The optional vision budget; NULL models a legacy missing config key.
   */
  private ?int $visionLimit = NULL;

  /**
   * Tracking keeps its existing opt-out semantics for both budgets.
   */
  private bool $trackingEnabled = TRUE;

  /**
   * Real tracker with a small, independent text budget.
   */
  private TokenTrackingService $tracking;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $database = $this->container->get('database');
    $database->schema()->createTable('markaspot_ai_token_usage', markaspot_ai_schema()['markaspot_ai_token_usage']);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => match ($key) {
      'token_tracking.enabled' => $this->trackingEnabled,
      'token_tracking.daily_limit' => 100,
      'token_tracking.vision_daily_limit' => $this->visionLimit,
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $this->container->set('config.factory', $factory);
    $this->tracking = new TokenTrackingService($database, $factory, $this->container->get('logger.factory'));
  }

  /**
   * Missing/zero vision limits allow usage even with an exhausted text budget.
   */
  #[DataProvider('unlimitedBudgets')]
  public function testUnlimitedVision(?int $limit): void {
    $this->visionLimit = $limit;
    $this->tracking->logUsage('azure', 'text', 'pii_name_detection', 100, 0);
    $this->tracking->logUsage('azure', 'vision', 'vision', 100000, 5000);
    $this->tracking->logUsage('azure', 'vision', 'vision_screening', 50000, 5000);
    self::assertFalse($this->tracking->checkLimit());
    self::assertTrue($this->tracking->checkVisionLimit());
    self::assertSame(160000, $this->tracking->getDailyUsage(NULL, TRUE)['total_tokens']);
    self::assertSame(160100, $this->tracking->getDailyUsage()['total_tokens']);
  }

  /**
   * Legacy absent key and explicit unlimited config.
   */
  public static function unlimitedBudgets(): array {
    return ['missing' => [NULL], 'zero' => [0]];
  }

  /**
   * Both vision operations count, other operations and yesterday do not.
   */
  public function testIndependentBudgetsAndBreakdowns(): void {
    $this->visionLimit = 1000;
    $this->tracking->logUsage('azure', 'shared-model', 'chat', 20, 10);
    $this->tracking->logUsage('ionos', 'embedding', 'embed', 20, 0);
    $this->tracking->logUsage('azure', 'shared-model', 'vision', 700, 100);
    $this->tracking->logUsage('ionos', 'screening', 'vision_screening', 100, 99);
    $this->container->get('database')->insert('markaspot_ai_token_usage')->fields([
      'provider' => 'azure', 'model' => 'old', 'operation' => 'vision',
      'input_tokens' => 99999, 'output_tokens' => 0,
      'created' => strtotime('yesterday noon'),
    ])->execute();
    self::assertTrue($this->tracking->checkVisionLimit());
    self::assertTrue($this->tracking->checkLimit());
    self::assertSame(50, $this->tracking->getRemainingTokens());
    $text = $this->tracking->getDailyUsage(NULL, FALSE);
    self::assertSame(50, $text['total_tokens']);
    self::assertSame(2, $text['request_count']);
    self::assertEqualsCanonicalizing(['chat', 'embed'], array_keys($text['by_operation']));
    self::assertSame(20, (int) $text['by_model']['shared-model']['input_tokens']);
    $vision = $this->tracking->getDailyUsage(NULL, TRUE);
    self::assertSame(999, $vision['total_tokens']);
    self::assertSame(2, $vision['request_count']);
    self::assertEqualsCanonicalizing(TokenTrackingService::VISION_OPERATIONS, array_keys($vision['by_operation']));
    self::assertSame(700, (int) $vision['by_model']['shared-model']['input_tokens']);
    self::assertSame(30, $this->tracking->getDailyUsage('azure', FALSE)['total_tokens']);
    self::assertSame(199, $this->tracking->getDailyUsage('ionos', TRUE)['total_tokens']);
    self::assertSame(1049, $this->tracking->getDailyUsage()['total_tokens']);
    $this->tracking->logUsage('azure', 'screening', 'vision_screening', 1, 0);
    self::assertFalse($this->tracking->checkVisionLimit(), 'At the limit blocks.');
    $this->tracking->logUsage('azure', 'vision', 'vision', 1, 0);
    self::assertFalse($this->tracking->checkVisionLimit(), 'Above the limit blocks.');
    self::assertTrue($this->tracking->checkLimit(), 'Vision does not exhaust text.');
    $this->tracking->logUsage('azure', 'text', 'pii_name_detection', 50, 0);
    self::assertFalse($this->tracking->checkLimit(), 'All non-vision operations count.');
    self::assertSame(0, $this->tracking->getRemainingTokens());
    $this->trackingEnabled = FALSE;
    self::assertTrue($this->tracking->checkVisionLimit());
    self::assertTrue($this->tracking->checkLimit());
    self::assertSame(-1, $this->tracking->getRemainingTokens());
  }

  /**
   * API budget counters exclude vision while cost totals still include it.
   */
  public function testUsageReporting(): void {
    $this->tracking->logUsage('azure', 'text', 'chat', 20, 10);
    $this->tracking->logUsage('azure', 'image', 'vision', 400, 100);
    $this->tracking->logUsage('ionos', 'image', 'vision_screening', 200, 0);
    $controller = new UsageController($this->tracking);
    $usage = json_decode($controller->getUsage(Request::create('/usage'))->getContent(), TRUE);
    self::assertSame('text', $usage['daily']['scope']);
    self::assertSame(30, $usage['daily']['total_tokens']);
    self::assertSame(70, $usage['daily']['remaining']);
    self::assertEquals(30, $usage['daily']['usage_percent']);
    self::assertSame(['chat'], array_keys($usage['daily']['by_operation']));
    self::assertSame('all', $usage['period']['scope']);
    self::assertSame(730, $usage['period']['total_tokens']);
    self::assertSame(700, $usage['vision_daily']['total_tokens']);
    self::assertNull($usage['vision_daily']['limit']);
    self::assertNull($usage['vision_daily']['remaining']);
    self::assertNull($usage['vision_daily']['usage_percent']);
    $summary = json_decode($controller->getSummary()->getContent(), TRUE);
    self::assertSame('ok', $summary['status']);
    self::assertSame(30, $summary['today']['tokens_used']);
    self::assertSame(700, $summary['vision_today']['total_tokens']);
    $this->visionLimit = 1000;
    $filtered = json_decode($controller->getUsage(Request::create('/usage?provider=azure'))->getContent(), TRUE);
    self::assertSame(700, $filtered['vision_daily']['total_tokens']);
    self::assertNull($filtered['vision_daily']['provider_filter']);
    self::assertSame(1000, $filtered['vision_daily']['limit']);
    self::assertSame(300, $filtered['vision_daily']['remaining']);
    self::assertEquals(70, $filtered['vision_daily']['usage_percent']);
    self::assertEqualsCanonicalizing(TokenTrackingService::VISION_OPERATIONS, array_keys($filtered['vision_daily']['by_operation']));
  }

}
