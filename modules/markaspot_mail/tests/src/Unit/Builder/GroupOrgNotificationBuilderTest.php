<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the organisation assignment notification mail builder.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder::class)]
#[Group('markaspot_mail')]
final class GroupOrgNotificationBuilderTest extends UnitTestCase {

  /**
   * Tests the builder mail type.
   */
  public function testGetTypeReturnsEcaGroupOrgNotification(): void {
    $this->assertSame(MailType::ECA_GROUP_ORG_NOTIFICATION, $this->buildBuilder()->getType());
  }

  /**
   * Tests the builder claims only organisation assignment notifications.
   */
  public function testSupportsOnlyOrgNotification(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_group', 'org_notification'));
    $this->assertFalse($builder->supports('markaspot_group', 'member_invitation'));
    $this->assertFalse($builder->supports('other', 'org_notification'));
  }

  /**
   * Tests missing node or organisation context keeps the mail unbranded.
   */
  public function testBuildReturnsNullOnMissingParams(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);
    $this->assertNull($builder->build($this->buildContext(['subject' => 'x'])));
  }

  /**
   * Tests the builder resolves jurisdiction mode and dashboard links.
   */
  public function testBuildProducesJurisdictionCardFromNodeAndOrganisation(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())
      ->method('getBranding')
      ->with(18, 'jurisdiction', 'de')
      ->willReturn([
        'frontend_base_url' => 'https://bonn-mobility.example',
        'jurisdiction_slug' => 'bonn',
        'frontend_uses_jurisdiction_path' => TRUE,
      ]);
    $builder = $this->buildBuilder(branding: $branding);
    $ctx = $this->buildContext([
      'node' => $this->buildNode(),
      'organisation' => $this->buildOrganisation(),
    ], 'de');
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(18, $msg->jurisdictionId);
    $this->assertSame('Request #7-2026 assigned to Tiefbauamt', $msg->subject);
    $this->assertSame('Request #7-2026 assigned to Tiefbauamt', (string) $msg->content['headline']);
    $this->assertSame('Request #7-2026 was assigned to Tiefbauamt', (string) $msg->content['preheader']);
    $this->assertSame('A citizen request is ready for review.', (string) $msg->content['intro']);
    $this->assertSame('Open request', (string) $msg->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026/edit', $msg->content['cta_url']);
    $this->assertSame([
      'This request has been assigned to your organisation for processing.',
      'Description: Bitte pruefen.',
    ], $msg->content['body_blocks']);
    $this->assertStringNotContainsString('plain_text', implode(' ', $msg->content['body_blocks']));
    $this->assertSame([
      ['Request' => '#7-2026'],
      ['Category' => 'Radbuegel'],
      ['Location' => 'Euskirchener Strasse 49, 53113 Bonn, NRW, DE'],
      ['Organisation' => 'Tiefbauamt'],
    ], $msg->content['features_block']);
  }

  /**
   * Tests all editable slots, Drupal tokens, and assignment placeholders.
   */
  public function testBuildUsesResolvedOverridesAndKeepsGeneratedDetails(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'subject' => 'Custom {{ organisation }} #{{ request_id }} [node:title]',
      'headline' => 'Handle {{ organisation }}',
      'intro' => 'Review [node:title] #{{ request_id }}.',
      'body_blocks' => ['Assigned to {{ organisation }} for [node:title].'],
      'cta_label' => 'Open #{{ request_id }}',
      'preheader' => '{{ organisation }} received [node:title].',
    ], $node);
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://example.test',
      'frontend_uses_jurisdiction_path' => FALSE,
    ]);
    $organisation = $this->buildOrganisation('Tiefbau & Grün');

    $message = $this->buildBuilder(branding: $branding, textResolver: $resolver)->build(
      $this->buildContext([
        'node' => $node,
        'organisation' => $organisation,
      ]),
    );

    $this->assertNotNull($message);
    $this->assertSame('Custom Tiefbau & Grün #7-2026 Broken lamp', $message->subject);
    $this->assertSame('Handle Tiefbau & Grün', (string) $message->content['headline']);
    $this->assertSame('Review Broken lamp #7-2026.', (string) $message->content['intro']);
    $this->assertSame('Tiefbau & Grün received Broken lamp.', (string) $message->content['preheader']);
    $this->assertSame('Open #7-2026', (string) $message->content['cta_label']);
    $this->assertSame([
      'Assigned to Tiefbau &amp; Grün for Broken lamp.',
      'Description: Bitte pruefen.',
    ], $message->content['body_blocks']);
    $this->assertContains(['Request' => '#7-2026'], $message->content['features_block']);
    $this->assertContains(['Organisation' => 'Tiefbau & Grün'], $message->content['features_block']);
  }

  /**
   * Tests final raw-slot filtering after token and context replacement.
   */
  public function testBuildFiltersDynamicValuesInRawHtmlSlots(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'intro' => '<a href="{{ organisation }}">Organisation</a> <a href="[node:title]">Token</a>',
      'body_blocks' => ['<a href="{{ organisation }}">Organisation</a> <a href="[node:title]">Token</a>'],
    ], $node, 'javascript:alert(1)');

    $message = $this->buildBuilder(textResolver: $resolver)->build(
      $this->buildContext([
        'node' => $node,
        'organisation' => $this->buildOrganisation('javascript:alert(2)'),
      ]),
    );

    $this->assertNotNull($message);
    $this->assertStringNotContainsString('javascript:', (string) $message->content['intro']);
    $this->assertStringNotContainsString('javascript:', (string) $message->content['body_blocks'][0]);
  }

  /**
   * Tests a subject-only override leaves every other slot at its old default.
   */
  public function testBuildUsesPartialSubjectOverrideWithOtherSlotsUnchanged(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'subject' => 'Queue #{{ request_id }} for {{ organisation }}',
    ], $node);
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://example.test',
      'frontend_uses_jurisdiction_path' => FALSE,
    ]);

    $message = $this->buildBuilder(branding: $branding, textResolver: $resolver)->build(
      $this->buildContext([
        'node' => $node,
        'organisation' => $this->buildOrganisation(),
      ]),
    );

    $this->assertNotNull($message);
    $this->assertSame('Queue #7-2026 for Tiefbauamt', $message->subject);
    $this->assertSame('Request #7-2026 assigned to Tiefbauamt', (string) $message->content['headline']);
    $this->assertSame('Request #7-2026 was assigned to Tiefbauamt', (string) $message->content['preheader']);
    $this->assertSame('A citizen request is ready for review.', (string) $message->content['intro']);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame([
      'This request has been assigned to your organisation for processing.',
      'Description: Bitte pruefen.',
    ], $message->content['body_blocks']);
    $this->assertSame([
      ['Request' => '#7-2026'],
      ['Category' => 'Radbuegel'],
      ['Location' => 'Euskirchener Strasse 49, 53113 Bonn, NRW, DE'],
      ['Organisation' => 'Tiefbauamt'],
    ], $message->content['features_block']);
  }

  /**
   * Tests tenant frontend bases are not prefixed with the jurisdiction slug.
   */
  public function testBuildOmitsSlugPrefixWhenFrontendBaseIsTenantScoped(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())
      ->method('getBranding')
      ->with(18, 'jurisdiction', 'de')
      ->willReturn([
        'frontend_base_url' => 'https://bonn-mobility.example',
        'jurisdiction_slug' => 'bonn',
        'frontend_uses_jurisdiction_path' => FALSE,
      ]);
    $builder = $this->buildBuilder(branding: $branding);
    $ctx = $this->buildContext([
      'node' => $this->buildNode(),
      'organisation' => $this->buildOrganisation(),
    ], 'de');
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('https://bonn-mobility.example/dashboard/requests/7-2026/edit', $msg->content['cta_url']);
  }

  /**
   * Tests tenants whose staff work in the backend get its edit form.
   */
  public function testBuildLinksToTheBackendEditFormWhenConfigured(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://bonn-mobility.example',
      'jurisdiction_slug' => 'bonn',
      'frontend_uses_jurisdiction_path' => TRUE,
      'staff_links' => 'backend',
      'staff_backend_base' => 'https://management.bonn-mobility.example/',
    ]);
    $builder = $this->buildBuilder(branding: $branding);
    $msg = $builder->build($this->buildContext([
      'node' => $this->buildNode(),
      'organisation' => $this->buildOrganisation(),
    ], 'de'));

    $this->assertNotNull($msg);
    $this->assertSame('https://management.bonn-mobility.example/node/123/edit', $msg->content['cta_url']);
  }

  /**
   * Tests a missing staff_links key keeps the single dashboard link.
   */
  public function testBuildWithoutStaffLinksKeysKeepsTheDashboardLink(): void {
    $node = $this->buildNode();
    $message = $this->buildBuilder(branding: $this->staffBranding([]))->build($this->staffContext($node));

    $this->assertNotNull($message);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026/edit', $message->content['cta_url']);
    $this->assertArrayNotHasKey('secondary_cta_url', $message->content);
    $this->assertArrayNotHasKey('secondary_cta_label', $message->content);
  }

  /**
   * Tests both links: dashboard button first, backend link second.
   */
  public function testBuildWithBothStaffLinksAddsTheBackendLink(): void {
    $node = $this->buildNode();
    $message = $this->buildBuilder(branding: $this->staffBranding([
      'staff_links' => 'both',
      'staff_backend_base' => 'https://management.bonn-mobility.example',
    ]))->build($this->staffContext($node));

    $this->assertNotNull($message);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026/edit', $message->content['cta_url']);
    $this->assertSame('Open in the backend', (string) $message->content['secondary_cta_label']);
    $this->assertSame('https://management.bonn-mobility.example/node/123/edit', $message->content['secondary_cta_url']);
  }

  /**
   * Tests the editable backend label, with context placeholders and tokens.
   */
  public function testBuildWithBothStaffLinksUsesTheEditableBackendLabel(): void {
    $node = $this->buildNode();
    $builder = $this->buildBuilder(
      branding: $this->staffBranding([
        'staff_links' => 'both',
        'staff_backend_base' => 'https://management.bonn-mobility.example',
      ]),
      textResolver: $this->buildTextResolver([
        'backend_cta_label' => '[node:title] in Drupal ({{ organisation }})',
      ]),
    );
    $message = $builder->build($this->staffContext($node));

    $this->assertNotNull($message);
    $this->assertSame('Broken lamp in Drupal (Tiefbauamt)', $message->content['secondary_cta_label']);
  }

  /**
   * Tests both links without a frontend base: the backend link is the button.
   */
  public function testBuildWithBothStaffLinksAndNoDashboardUsesTheBackendAsButton(): void {
    $node = $this->buildNode();
    $message = $this->buildBuilder(branding: $this->staffBranding([
      'frontend_base_url' => '',
      'staff_links' => 'both',
      'staff_backend_base' => 'https://management.bonn-mobility.example',
    ]))->build($this->staffContext($node));

    $this->assertNotNull($message);
    $this->assertSame('Open in the backend', (string) $message->content['cta_label']);
    $this->assertSame('https://management.bonn-mobility.example/node/123/edit', $message->content['cta_url']);
    $this->assertArrayNotHasKey('secondary_cta_url', $message->content);
  }

  /**
   * Tests an unsaved node keeps the dashboard link in every backend mode.
   */
  public function testBuildForAnUnsavedNodeKeepsTheDashboardLink(): void {
    foreach (['backend', 'both'] as $mode) {
      $node = $this->buildNode(NULL);
      $message = $this->buildBuilder(branding: $this->staffBranding([
        'staff_links' => $mode,
        'staff_backend_base' => 'https://management.bonn-mobility.example',
      ]))->build($this->staffContext($node));

      $this->assertNotNull($message);
      $this->assertSame('Open request', (string) $message->content['cta_label'], $mode);
      $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026/edit', $message->content['cta_url'], $mode);
      $this->assertArrayNotHasKey('secondary_cta_url', $message->content, $mode);
    }
  }

  /**
   * Tests a fallen-back mode without backend base keeps the dashboard link.
   */
  public function testBuildWithBackendModeButNoBackendBaseKeepsTheDashboardLink(): void {
    $node = $this->buildNode();
    $message = $this->buildBuilder(branding: $this->staffBranding([
      'staff_links' => 'backend',
      'staff_backend_base' => NULL,
    ]))->build($this->staffContext($node));

    $this->assertNotNull($message);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026/edit', $message->content['cta_url']);
  }

  /**
   * Builds the German mail context of a staff link test.
   */
  private function staffContext(NodeInterface $node): MailContext {
    return $this->buildContext([
      'node' => $node,
      'organisation' => $this->buildOrganisation(),
    ], 'de');
  }

  /**
   * Builds a branding double for staff link tests.
   */
  private function staffBranding(array $overrides): MailBrandingService {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn($overrides + [
      'frontend_base_url' => 'https://bonn-mobility.example',
      'jurisdiction_slug' => 'bonn',
      'frontend_uses_jurisdiction_path' => TRUE,
    ]);
    return $branding;
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params, string $langcode = 'en'): MailContext {
    return new MailContext(
      module: 'markaspot_group',
      key: 'org_notification',
      langcode: $langcode,
      params: $params,
      to: 'head@org.example',
    );
  }

  /**
   * Builds the builder with optional test doubles.
   */
  private function buildBuilder(
    ?LoggerInterface $logger = NULL,
    ?MailBrandingService $branding = NULL,
    ?MailTextResolver $textResolver = NULL,
  ): GroupOrgNotificationBuilder {
    $textResolver ??= $this->buildTextResolver([]);
    $builder = new GroupOrgNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $branding ?? $this->createMock(MailBrandingService::class),
      $textResolver,
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a resolver double for one complete or partial slot set.
   */
  private function buildTextResolver(
    array $overrides,
    ?NodeInterface $expectedNode = NULL,
    string $tokenValue = 'Broken lamp',
  ): MailTextResolver {
    $slots = $overrides + [
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'backend_cta_label' => '',
      'preheader' => '',
    ];
    $resolver = $this->createMock(MailTextResolver::class);
    $resolver->method('resolve')
      ->with('markaspot_mail.texts', 'group_assignment', $this->anything())
      ->willReturn($slots);
    $resolver->method('resolveField')
      ->willReturnCallback(
        static fn(string $configName, string $key, string $field): string => (string) $slots[$field],
      );
    $resolver->method('replaceLabelTokens')
      ->willReturnCallback(
        static fn(string $label): string => str_replace('[node:title]', $tokenValue, $label),
      );
    $replaceTokens = $expectedNode === NULL
      ? $resolver->method('replaceTokens')
      : $resolver->expects($this->once())->method('replaceTokens');
    $replaceTokens
      ->with(
        $this->callback(static fn(array $resolved): bool => $resolved['subject'] === $slots['subject']),
        $this->callback(
          static fn(array $data): bool => $expectedNode === NULL || ($data['node'] ?? NULL) === $expectedNode,
        ),
        $this->anything(),
      )
      ->willReturnCallback(static function (array $resolved) use ($tokenValue): array {
        foreach (['subject', 'headline', 'intro', 'cta_label', 'preheader'] as $slot) {
          $resolved[$slot] = str_replace('[node:title]', $tokenValue, $resolved[$slot]);
        }
        $resolved['body_blocks'] = array_map(
          static fn(string $block): string => str_replace('[node:title]', $tokenValue, $block),
          $resolved['body_blocks'],
        );
        return $resolved;
      });
    return $resolver;
  }

  /**
   * Builds a service request node with jurisdiction and display fields.
   */
  private function buildNode(?int $id = 123): NodeInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('getEntityTypeId')->willReturn('group');
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('id')->willReturn(18);

    $category = new class() {

      /**
       * Returns the category label.
       */
      public function label(): string {
        return 'Radbuegel';
      }

    };

    $fields = [
      'field_jurisdiction' => $this->referenceField([$jurisdiction]),
      'request_id' => $this->field([['value' => '7-2026']], ['value' => '7-2026']),
      'field_category' => $this->referenceField([$category]),
      'field_address' => $this->field(
        [[
          'address_line1' => 'Euskirchener Strasse 49',
          'address_line2' => '',
          'postal_code' => '53113',
          'locality' => 'Bonn',
          'administrative_area' => 'NRW',
          'country_code' => 'DE',
        ]],
        [],
        'address-property-leak',
      ),
      'body' => $this->field(
        [['value' => '<p>Bitte pruefen.</p>', 'format' => 'plain_text']],
        ['value' => '<p>Bitte pruefen.</p>'],
        '<p>Bitte pruefen.</p>, plain_text',
      ),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => array_key_exists($fieldName, $fields));
    $node->method('get')
      ->willReturnCallback(static fn(string $fieldName): FieldItemListInterface => $fields[$fieldName]);

    return $node;
  }

  /**
   * Builds an organisation group.
   */
  private function buildOrganisation(string $label = 'Tiefbauamt'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn($label);
    return $group;
  }

  /**
   * Builds a scalar field item list.
   */
  private function field(
    array $values,
    array $properties = [],
    ?string $stringValue = NULL,
  ): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);
    $field->method('getString')->willReturn($stringValue ?? (string) ($properties['value'] ?? ''));
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $properties[$property] ?? NULL);
    return $field;
  }

  /**
   * Builds an entity reference field item list.
   */
  private function referenceField(array $entities): EntityReferenceFieldItemListInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($entities === []);
    $field->method('referencedEntities')->willReturn($entities);
    return $field;
  }

}
