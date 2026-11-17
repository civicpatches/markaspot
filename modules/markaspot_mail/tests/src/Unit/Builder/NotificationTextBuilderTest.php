<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests config-driven notification-mail rendering.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder::class)]
#[Group('markaspot_mail')]
final class NotificationTextBuilderTest extends UnitTestCase {

  /**
   * Reports the config-driven notification mail type.
   */
  public function testGetTypeReturnsNotificationConfig(): void {
    $this->assertSame(MailType::NOTIFICATION_CONFIG, $this->buildBuilder()->getType());
  }

  /**
   * Claims only the notification mail-key namespace.
   */
  public function testSupportsOnlyNotificationPrefixedKeys(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_report_confirmation'));
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_status_open'));
    $this->assertFalse($builder->supports('markaspot_mail', 'other'));
    $this->assertFalse($builder->supports('other', 'notification_report_confirmation'));
  }

  /**
   * Rejects mail contexts without a service-request node.
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Rejects templates without a usable subject.
   */
  public function testBuildReturnsNullWhenResolvedSubjectIsEmpty(): void {
    $node = $this->buildPlainNode();

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn([
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ]);
    $textResolver->method('replaceTokens')->willReturnArgument(0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $builder = $this->buildBuilder(textResolver: $textResolver, logger: $logger);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Produces a platform mail when no jurisdiction is referenced.
   */
  public function testBuildProducesPlatformMailWithoutJurisdiction(): void {
    $node = $this->buildPlainNode();

    $slots = [
      'subject' => 'Your report #42 has been received',
      'headline' => 'We received your report',
      'intro' => 'Your report #42 has been received.',
      'body_blocks' => ['Category: Pothole'],
      'cta_label' => 'View your report',
      'preheader' => 'We received your report.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('Your report #42 has been received', $msg->subject);
    $this->assertSame('We received your report', $msg->content['headline']);
    $this->assertSame(['Category: Pothole'], $msg->content['body_blocks']);
    $this->assertSame('View your report', $msg->content['cta_label']);
    $this->assertSame('https://tenant.example.org/sample/requests/42', $msg->content['cta_url']);
  }

  /**
   * Unpublished reports keep the notification wording without a report link.
   */
  #[DataProvider('notificationKeys')]
  public function testUnpublishedReportOmitsLinkWithoutChangingText(string $notificationKey): void {
    $slots = [
      'subject' => 'Update on your report #42',
      'headline' => 'Your report was updated',
      'intro' => 'We are processing your report.',
      'body_blocks' => ['Category: Pothole', 'Thank you for your report.'],
      'cta_label' => 'View your report',
      'preheader' => 'An update on your report.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->with('markaspot_mail.texts', $notificationKey, 'en')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturnArgument(0);
    $builder = $this->buildBuilder(textResolver: $textResolver);
    $context = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_' . $notificationKey,
      langcode: 'en',
      params: ['node' => $this->buildPlainNode(FALSE), 'notification_key' => $notificationKey],
      to: 'citizen@example.com',
    );

    $message = $builder->build($context);

    $this->assertNotNull($message);
    $this->assertSame($slots['subject'], $message->subject);
    $this->assertSame('card_transactional', $message->variant);
    $this->assertSame('platform', $message->mode);
    $this->assertNull($message->jurisdictionId);
    $this->assertSame([
      'preheader' => $slots['preheader'],
      'headline' => $slots['headline'],
      'intro' => $slots['intro'],
      'body_blocks' => $slots['body_blocks'],
    ], $message->content);
    $this->assertStringNotContainsString('/requests/', (string) json_encode($message->content));
  }

  /**
   * Provides every shipped citizen notification key and a custom key.
   */
  public static function notificationKeys(): array {
    return [
      'confirmation' => ['report_confirmation'],
      'open' => ['status_open'],
      'closed' => ['status_closed'],
      'not responsible' => ['status_not_responsible'],
      'custom' => ['custom_notification'],
    ];
  }

  /**
   * A later publication affects new mails, not the already built message.
   */
  public function testReportLinkUsesPublicationStateAtBuildTime(): void {
    $node = $this->buildPlainNode(NULL);
    $node->method('isPublished')->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $slots = [
      'subject' => 'Update on your report #42',
      'headline' => 'Your report was updated',
      'intro' => 'We are processing your report.',
      'body_blocks' => ['Thank you for your report.'],
      'cta_label' => 'View your report',
      'preheader' => 'An update on your report.',
    ];
    $resolver = $this->createMock(MailTextResolver::class);
    $resolver->method('resolve')->willReturn($slots);
    $resolver->method('replaceTokens')->willReturnArgument(0);
    $builder = $this->buildBuilder(textResolver: $resolver);
    $context = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_status_open',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );

    $unpublished = $builder->build($context);
    $published = $builder->build($context);

    $this->assertNotNull($unpublished);
    $this->assertNotNull($published);
    $this->assertArrayNotHasKey('cta_label', $unpublished->content);
    $this->assertArrayNotHasKey('cta_url', $unpublished->content);
    $this->assertSame('View your report', $published->content['cta_label']);
    $this->assertSame('https://tenant.example.org/sample/requests/42', $published->content['cta_url']);
    $contentWithoutLink = $published->content;
    unset($contentWithoutLink['cta_label'], $contentWithoutLink['cta_url']);
    $this->assertSame($unpublished->content, $contentWithoutLink);
    $this->assertSame($unpublished->subject, $published->subject);
  }

  /**
   * Duplicate CTAs point only at a published remaining report.
   */
  public function testDuplicateLinkUsesPublishedKeptReport(): void {
    $closed = $this->buildPlainNode();
    foreach ([TRUE, FALSE] as $published) {
      $kept = $this->buildPlainNode(requestId: 'KEPT-99');
      $kept->method('isPublished')->willReturn($published);
      $resolver = $this->createMock(MailTextResolver::class);
      $slots = [
        'subject' => 'Duplicate report',
        'headline' => '',
        'intro' => 'This matter is already being handled.',
        'body_blocks' => [],
        'cta_label' => 'View remaining report',
        'preheader' => '',
      ];
      $resolver->method('resolve')->willReturn($slots);
      $resolver->method('replaceTokens')->willReturnArgument(0);
      $branding = $this->createMock(MailBrandingService::class);
      $branding->method('getBranding')->willReturn([
        'frontend_base_url' => 'https://reports.example.test',
        'jurisdiction_slug' => 'district',
      ]);
      $builder = $this->buildBuilder(textResolver: $resolver, branding: $branding);
      $message = $builder->build(new MailContext(
        module: 'markaspot_mail',
        key: 'notification_status_duplicate',
        langcode: 'en',
        params: ['node' => $closed, 'duplicate_kept' => $kept],
        to: 'citizen@example.test',
      ));
      $this->assertNotNull($message);
      if ($published) {
        $this->assertSame('https://reports.example.test/district/requests/KEPT-99', $message->content['cta_url']);
      }
      else {
        $this->assertArrayNotHasKey('cta_url', $message->content);
        $this->assertArrayNotHasKey('cta_label', $message->content);
      }
    }
  }

  /**
   * The CTA uses the kept child's branding while the mail keeps its sender.
   */
  public function testDuplicateLinkUsesKeptChildJurisdiction(): void {
    $parent = $this->createMock(GroupInterface::class);
    $parent->method('id')->willReturn(7);
    $parent->method('getEntityTypeId')->willReturn('group');
    $parent->method('bundle')->willReturn('jur');
    $parentField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $parentField->method('isEmpty')->willReturn(FALSE);
    $parentField->method('referencedEntities')->willReturn([$parent]);
    $child = $this->createMock(GroupInterface::class);
    $child->method('id')->willReturn(9);
    $child->method('getEntityTypeId')->willReturn('group');
    $child->method('bundle')->willReturn('jur');
    $child->method('hasField')->willReturnMap([['field_parent_jurisdiction', TRUE]]);
    $child->method('get')->with('field_parent_jurisdiction')->willReturn($parentField);
    $kept = $this->buildPlainNode(requestId: 'KEPT-99', jurisdiction: $child);
    $kept->method('isPublished')->willReturn(TRUE);
    $closed = $this->buildPlainNode(requestId: 'CLOSED-100', jurisdiction: $parent);
    $resolver = $this->createMock(MailTextResolver::class);
    $resolver->method('resolve')->willReturn([
      'subject' => 'Duplicate report', 'headline' => '', 'intro' => '',
      'body_blocks' => [], 'cta_label' => 'View existing entry', 'preheader' => '',
    ]);
    $resolver->method('replaceTokens')->willReturnArgument(0);
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())->method('getBranding')
      ->with(9, 'jurisdiction', 'en')->willReturn([
        'frontend_base_url' => 'https://child.example.test',
        'jurisdiction_slug' => 'child-district',
      ]);
    $message = $this->buildBuilder(textResolver: $resolver, branding: $branding)->build(new MailContext(
      module: 'markaspot_mail',
      key: 'notification_status_duplicate',
      langcode: 'en',
      params: ['node' => $closed, 'duplicate_kept' => $kept],
      to: 'citizen@example.test',
    ));
    $this->assertNotNull($message);
    $this->assertSame('https://child.example.test/child-district/requests/KEPT-99', $message->content['cta_url']);
    $this->assertSame('jurisdiction', $message->mode);
    $this->assertSame(7, $message->jurisdictionId);
  }

  /**
   * Selects jurisdiction mode from the referenced group.
   */
  public function testBuildResolvesJurisdictionModeFromNode(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
    ]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    $slots = [
      'subject' => 'Update on your report #42',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_status_open',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'status_open'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
    $this->assertArrayNotHasKey('cta_label', $msg->content);
    $this->assertArrayNotHasKey('cta_url', $msg->content);
  }

  /**
   * Shipped templates resolve their opt-in runtime wording before node tokens.
   */
  public function testBuildReplacesRuntimeWordingPlaceholdersForJurisdiction(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $requestIdField = $this->createMock(FieldItemListInterface::class);
    $requestIdField->method('isEmpty')->willReturn(FALSE);
    $requestIdField->method('getString')->willReturn('42');

    $node = $this->createMock(NodeInterface::class);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $requestIdField],
    ]);

    $slots = [
      'subject' => 'Your {{ citizen_term_singular }} #[node:request_id] has been received',
      'headline' => 'We received your {{ citizen_term_singular }}',
      'intro' => 'Your {{ citizen_term_singular }} is ready.',
      'body_blocks' => ['View {{ citizen_term_plural }}.'],
      'cta_label' => 'View your {{ citizen_term_singular }}',
      'preheader' => '{{ citizen_term_singular_title }} ready.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturnArgument(0);

    $msg = $this->buildBuilder(
      textResolver: $textResolver,
      citizenWordingResolver: new CitizenWordingResolver(),
    )->build(new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Your entry #[node:request_id] has been received', $msg->subject);
    $this->assertSame('We received your entry', $msg->content['headline']);
    $this->assertSame(['View entries.'], $msg->content['body_blocks']);
    $this->assertSame('Entry ready.', $msg->content['preheader']);
    $this->assertStringNotContainsString('{{ citizen_term_', $msg->subject);
  }

  /**
   * Builds a mock node with an optional jurisdiction and request ID.
   *
   * No toUrl() stub on purpose: the builder must derive the CTA from the
   * branding package, never from the node's canonical (backend) URL.
   */
  private function buildPlainNode(?bool $published = TRUE, string $requestId = '42', ?GroupInterface $jurisdiction = NULL): NodeInterface&MockObject {
    $requestIdField = $this->createMock(FieldItemListInterface::class);
    $requestIdField->method('isEmpty')->willReturn(FALSE);
    $requestIdField->method('getString')->willReturn($requestId);
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn($jurisdiction === NULL);
    $jurisdictionField->method('referencedEntities')->willReturn($jurisdiction ? [$jurisdiction] : []);

    $node = $this->createMock(NodeInterface::class);
    if ($published !== NULL) {
      $node->method('isPublished')->willReturn($published);
    }
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', $jurisdiction !== NULL],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['request_id', $requestIdField],
      ['field_jurisdiction', $jurisdictionField],
    ]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    return $node;
  }

  /**
   * Builds a valid jurisdiction with a selected i18n wording preset.
   */
  private function buildWordingJurisdiction(string $preset): GroupInterface&MockObject {
    $configField = new class ((string) json_encode(['i18n' => ['wording' => $preset]])) {

      /**
       * Raw JSON config value.
       */
      public string $value;

      /**
       * Stores the raw config value.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Reports the mock field as populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_nuxt_config',
    );
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);
    $group->method('id')->willReturn(7);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    return $group;
  }

  /**
   * Builds the builder with default or injected mocks.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?LoggerInterface $logger = NULL,
    ?MailBrandingService $branding = NULL,
    ?CitizenWordingResolver $citizenWordingResolver = NULL,
  ): NotificationTextBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolve')->willReturn([
        'subject' => '',
        'headline' => '',
        'intro' => '',
        'body_blocks' => [],
        'cta_label' => '',
        'preheader' => '',
      ]);
      $textResolver->method('replaceTokens')->willReturnArgument(0);
    }
    if ($branding === NULL) {
      $branding = $this->createMock(MailBrandingService::class);
      $branding->method('getBranding')->willReturn([
        'frontend_base_url' => 'https://tenant.example.org',
        'jurisdiction_slug' => 'sample',
      ]);
    }
    return new NotificationTextBuilder(
      $textResolver,
      $branding,
      $citizenWordingResolver ?? new CitizenWordingResolver(),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
