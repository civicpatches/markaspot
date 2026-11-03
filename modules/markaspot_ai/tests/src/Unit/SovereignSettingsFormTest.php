<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_ai\Form\MarkaspotAiSettingsForm;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Administrator controls cannot override the operator's provider selection.
 */
#[Group('markaspot_ai')]
class SovereignSettingsFormTest extends UnitTestCase {

  /**
   * Both rendering and forged submission enforce IONOS.
   */
  public function testProviderSelectionIsLocked(): void {
    $previous = getenv('MARKASPOT_AI_SOVEREIGN');
    putenv('MARKASPOT_AI_SOVEREIGN=ionos');
    new Settings([]);
    try {
      $factory = $this->getConfigFactoryStub([
        'markaspot_ai.settings' => ['default_provider' => 'azure', 'pii_redaction' => ['provider' => 'local_nlp']],
        'markaspot_vision.settings' => [],
      ]);
      $saved = [];
      $config = $factory->getEditable('markaspot_ai.settings');
      $config->method('set')->willReturnCallback(function ($key, $value) use (&$saved, $config) {
        $saved[$key] = $value;
        return $config;
      });
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->createMock(MessengerInterface::class));
      \Drupal::setContainer($container);
      $form = new MarkaspotAiSettingsForm($factory, $this->createMock(TypedConfigManagerInterface::class),
        $this->createMock(NlpClientService::class), $this->createMock(TokenTrackingService::class),
        $this->createMock(CacheTagsInvalidatorInterface::class), new NullLogger(), $this->createMock(ModuleHandlerInterface::class));
      $state = new FormState();
      $built = $form->buildForm([], $state);
      $this->assertTrue($built['provider']['default_provider']['#disabled']);
      $this->assertSame('ionos', $built['provider']['default_provider']['#default_value']);
      $this->assertTrue($built['pii_redaction']['pii_provider']['#disabled']);
      $state->setValue('default_provider', 'anthropic');
      $state->setValue('pii_provider', 'local_nlp');
      $form->submitForm($built, $state);
      $this->assertSame('azure', $saved['default_provider']);
      $this->assertSame('local_nlp', $saved['pii_redaction.provider']);
    }
    finally {
      putenv($previous === FALSE ? 'MARKASPOT_AI_SOVEREIGN' : "MARKASPOT_AI_SOVEREIGN=$previous");
    }
  }

}
