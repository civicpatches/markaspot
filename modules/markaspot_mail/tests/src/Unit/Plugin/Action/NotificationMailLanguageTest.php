<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Plugin\Action;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Plugin\Action\NotificationMailActionBase;
use Drupal\markaspot_mail\Plugin\Action\SendNotificationMail;
use Drupal\markaspot_mail\Plugin\Action\SendTermNotificationMail;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests language resolution and text building for both notification actions.
 */
#[CoversClass(NotificationMailActionBase::class)]
#[Group('markaspot_mail')]
final class NotificationMailLanguageTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 6) . '/markaspot_group/markaspot_group.module';
  }

  /**
   * Resolves subjects without changing or saving the report's language.
   */
  #[DataProvider('languageCases')]
  public function testNotificationLanguage(bool $statusAction, string $reportLanguage, ?string $workspaceConfig, string $expectedLanguage, string $bundle = 'jur', bool $missingConfigField = FALSE): void {
    $notification_key = $statusAction ? 'status_closed' : 'report_confirmation';
    $subjects = [
      'en' => [
        'report_confirmation' => 'Your report has been received',
        'status_closed' => 'Your report has been resolved',
      ],
      'de' => [
        'report_confirmation' => 'Ihre Meldung ist eingegangen',
        'status_closed' => 'Ihre Meldung wurde abgeschlossen',
      ],
    ];
    $config_factory = $this->getConfigFactoryStub([
      'markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur'],
      'markaspot_mail.settings' => ['recipient_flood' => ['limit' => 5, 'window' => 3600]],
      'markaspot_mail.texts' => [$notification_key => ['subject' => $subjects['en'][$notification_key]]],
    ]);
    $language_manager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $language_manager->method('getLanguages')->willReturn([
      'en' => new Language(['id' => 'en']),
      'de' => new Language(['id' => 'de']),
    ]);
    $language_manager->method('getDefaultLanguage')->willReturn(new Language(['id' => 'en']));
    $language_manager->method('getLanguageConfigOverride')->willReturnCallback(
      function (string $langcode, string $configName) use ($subjects, $notification_key): LanguageConfigOverride {
        $this->assertSame('markaspot_mail.texts', $configName);
        $override = $this->createMock(LanguageConfigOverride::class);
        $override->method('get')->with($notification_key)->willReturn(['subject' => $subjects[$langcode][$notification_key]]);
        return $override;
      },
    );

    $fields = [];
    if ($workspaceConfig !== NULL) {
      $nuxt_config = $this->createMock(FieldItemListInterface::class);
      $nuxt_config->method('isEmpty')->willReturn($workspaceConfig === '');
      $nuxt_config->method('__get')->with('value')->willReturn($workspaceConfig);
      $jurisdiction = $this->createMock(GroupInterface::class);
      $jurisdiction->method('id')->willReturn(7);
      $jurisdiction->method('getEntityTypeId')->willReturn('group');
      $jurisdiction->method('bundle')->willReturn($bundle);
      $jurisdiction->method('hasField')->with('field_nuxt_config')->willReturn(!$missingConfigField);
      $jurisdiction->method('get')->with('field_nuxt_config')->willReturn($nuxt_config);
      $fields['field_jurisdiction'] = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $fields['field_jurisdiction']->method('isEmpty')->willReturn(FALSE);
      $fields['field_jurisdiction']->method('referencedEntities')->willReturn([$jurisdiction]);
    }
    if ($statusAction) {
      $key_field = $this->createMock(FieldItemListInterface::class);
      $key_field->method('isEmpty')->willReturn(FALSE);
      $key_field->method('getValue')->willReturn([['value' => $notification_key]]);
      $term = $this->createMock(TermInterface::class);
      $term->method('hasField')->with('field_notification_key')->willReturn(TRUE);
      $term->method('get')->with('field_notification_key')->willReturn($key_field);
      $fields['field_status'] = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $fields['field_status']->method('isEmpty')->willReturn(FALSE);
      $fields['field_status']->method('referencedEntities')->willReturn([$term]);
    }
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('language')->willReturn(new Language(['id' => $reportLanguage]));
    $node->method('hasField')->willReturnCallback(static fn (string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn (string $name) => $fields[$name]);
    $node->expects($this->never())->method('set');
    $node->expects($this->never())->method('save');

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(static fn (string $text): string => $text);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('markaspot_mail')->willReturn($logger);
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $builder = new NotificationTextBuilder(
      new MailTextResolver($config_factory, $language_manager, $token),
      $this->createMock(MailBrandingService::class),
      new CitizenWordingResolver(),
      $logger,
    );
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())->method('mail')->willReturnCallback(
      function (string $module, string $key, string $to, string $langcode, array $params) use ($builder, $expectedLanguage, $subjects, $notification_key, $node): array {
        $this->assertSame($expectedLanguage, $langcode);
        $this->assertSame('notification_' . $notification_key, $key);
        $this->assertSame($node, $params['node']);
        $message = $builder->build(new MailContext($module, $key, $langcode, $params, $to));
        $this->assertNotNull($message);
        $this->assertSame($subjects[$expectedLanguage][$notification_key], $message->subject);
        return ['result' => TRUE];
      },
    );
    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('language_manager', $language_manager);
    $container->set('token', $token);
    $container->set('logger.factory', $logger_factory);
    $container->set('flood', $flood);
    $container->set('plugin.manager.mail', $mail_manager);
    \Drupal::setContainer($container);

    $class = $statusAction ? SendTermNotificationMail::class : SendNotificationMail::class;
    $plugin_id = $statusAction ? 'markaspot_mail_send_term_notification' : 'markaspot_mail_send_notification';
    $action = $class::create($container, [
      'notification_key' => $notification_key,
      'recipient' => 'citizen@example.com',
    ], $plugin_id, ['id' => $plugin_id]);
    $action->execute($node);
    $this->assertSame($reportLanguage, $node->language()->getId());
  }

  /**
   * Provides the same fallback matrix for confirmation and status actions.
   */
  public static function languageCases(): iterable {
    $german = '{"languages":{"default":"de"}}';
    $cases = [
      'unspecified report uses workspace' => ['und', $german, 'de'],
      'not applicable report uses workspace' => ['zxx', $german, 'de'],
      'explicit report wins' => ['en', $german, 'en'],
      'explicit German report wins' => ['de', '{"languages":{"default":"en"}}', 'de'],
      'uninstalled report uses workspace' => ['fr', $german, 'de'],
      'uninstalled workspace uses site' => ['und', '{"languages":{"default":"fr"}}', 'en'],
      'unknown workspace uses site' => ['und', '{"languages":{"default":"unknown"}}', 'en'],
      'missing workspace uses site' => ['und', NULL, 'en'],
      'missing default uses site' => ['und', '{}', 'en'],
      'empty config uses site' => ['und', '', 'en'],
      'invalid JSON uses site' => ['und', '{', 'en'],
      'non-string default uses site' => ['und', '{"languages":{"default":["de"]}}', 'en'],
      'unspecified workspace uses site' => ['und', '{"languages":{"default":"und"}}', 'en'],
      'not applicable workspace uses site' => ['und', '{"languages":{"default":"zxx"}}', 'en'],
      'locale normalized by existing helper' => ['und', '{"languages":{"default":" DE-de "}}', 'de'],
      'organisation is not a workspace' => ['und', $german, 'en', 'org'],
      'missing config field uses site' => ['und', $german, 'en', 'jur', TRUE],
    ];
    foreach ([FALSE, TRUE] as $status_action) {
      foreach ($cases as $name => $arguments) {
        yield ($status_action ? 'status: ' : 'confirmation: ') . $name => [$status_action, ...$arguments];
      }
    }
  }

}
