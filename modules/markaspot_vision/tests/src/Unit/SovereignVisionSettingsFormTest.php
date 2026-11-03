<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\markaspot_ai\Utility\SovereignPolicy;
use Drupal\markaspot_vision\Form\MarkaspotVisionSettingsForm;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sovereign display and forged submissions preserve dormant Vision settings.
 */
#[Group('markaspot_vision')]
class SovereignVisionSettingsFormTest extends UnitTestCase {

  /**
   * Each connection field is disabled, displays policy, and retains config.
   */
  public function testConnectionFieldsAreLockedAndPreserved(): void {
    $previous = [];
    foreach (['MARKASPOT_AI_SOVEREIGN' => 'ionos', 'MARKASPOT_VISION_API_KEY' => '', 'OPENAI_API_KEY' => ''] as $key => $value) {
      $previous[$key] = getenv($key);
      putenv("$key=$value");
    }
    try {
      $dormant = [
        'api_url' => 'https://legacy.example/v1',
        'auth_type' => 'api_key',
        'api_key' => 'synthetic-legacy-key',
        'ai_model' => 'legacy-model',
      ];
      $factory = $this->getConfigFactoryStub([
        'markaspot_vision.settings' => $dormant,
        'markaspot_ai.sovereign' => ['vision_model' => 'ionos-vision'],
      ]);
      $saved = [];
      $config = $factory->getEditable('markaspot_vision.settings');
      $config->method('set')->willReturnCallback(static function ($key, $value) use (&$saved, $config) {
        $saved[$key] = $value;
        return $config;
      });
      $config->method('clear')->willReturnSelf();
      $config->expects($this->once())->method('save')->willReturnSelf();
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->createMock(MessengerInterface::class));
      \Drupal::setContainer($container);
      $form = new MarkaspotVisionSettingsForm($factory, $this->createMock(TypedConfigManagerInterface::class));
      $images = $this->createMock(ImageProcessingService::class);
      $images->method('getBlurMode')->willReturn('off');
      (new \ReflectionProperty($form, 'imageProcessing'))->setValue($form, $images);
      $state = new FormState();
      $built = $form->buildForm([], $state);
      foreach (array_keys($dormant) as $key) {
        $this->assertTrue($built['service'][$key]['#disabled'], $key);
        $state->setValue($key, 'forged');
      }
      $this->assertSame(SovereignPolicy::API_URL . '/chat/completions', $built['service']['api_url']['#default_value']);
      $this->assertSame('bearer', $built['service']['auth_type']['#default_value']);
      $this->assertSame('ionos-vision', $built['service']['ai_model']['#default_value']);
      // Disabled dormant credentials must not trigger legacy validation.
      $state->setValue('api_key', '');
      $form->validateForm($built, $state);
      $this->assertSame([], $state->getErrors());
      $state->setValue('api_key', 'forged-key');
      $form->submitForm($built, $state);
      foreach ($dormant as $key => $value) {
        $this->assertSame($value, $saved[$key], $key);
      }
    }
    finally {
      foreach ($previous as $key => $value) {
        putenv($value === FALSE ? $key : "$key=$value");
      }
    }
  }

}
