<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The token tracking update fills only a missing token_tracking mapping.
 */
#[Group('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class TokenTrackingDefaultsUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Enabling markaspot_ai for its schema would pull in group and nuxt.
   *
   * The assertions check the exact PHP types the schema declares instead.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
  }

  /**
   * Settings without token_tracking get the shipped defaults.
   */
  public function testMissingTokenTrackingGetsShippedDefaults(): void {
    $this->saveSettings([
      'default_provider' => 'openai',
      'sentiment_analysis' => ['enabled' => FALSE],
    ]);

    markaspot_ai_update_11905();

    $settings = $this->config('markaspot_ai.settings');
    self::assertTrue($settings->get('token_tracking.enabled'));
    self::assertSame(100000, $settings->get('token_tracking.daily_limit'));
    self::assertSame(0.9, $settings->get('token_tracking.alert_threshold'));
    self::assertNull($settings->get('token_tracking.vision_daily_limit'));
    self::assertSame('openai', $settings->get('default_provider'));
    self::assertFalse($settings->get('sentiment_analysis.enabled'));
  }

  /**
   * Existing token tracking values, including a disabled tracker, stay.
   */
  public function testExistingTokenTrackingIsUnchanged(): void {
    $custom = [
      'enabled' => FALSE,
      'daily_limit' => 5000,
      'alert_threshold' => 0.5,
      'vision_daily_limit' => 777,
    ];
    $this->saveSettings(['token_tracking' => $custom]);

    markaspot_ai_update_11905();

    self::assertSame($custom, $this->config('markaspot_ai.settings')->get('token_tracking'));
  }

  /**
   * A partial mapping counts as present and gets no further keys.
   */
  public function testPartialTokenTrackingIsUnchanged(): void {
    $this->saveSettings(['token_tracking' => ['enabled' => FALSE]]);

    markaspot_ai_update_11905();

    self::assertSame(['enabled' => FALSE], $this->config('markaspot_ai.settings')->get('token_tracking'));
  }

  /**
   * Missing settings are not created by the update.
   */
  public function testMissingSettingsAreNotCreated(): void {
    markaspot_ai_update_11905();

    self::assertTrue($this->config('markaspot_ai.settings')->isNew());
  }

  /**
   * Replaces the markaspot_ai settings with the given data.
   */
  private function saveSettings(array $data): void {
    $this->container->get('config.factory')
      ->getEditable('markaspot_ai.settings')
      ->setData($data)
      ->save();
  }

}
