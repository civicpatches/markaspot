<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Token;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Token\StaffLinkTokens;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the staff link node tokens.
 */
#[CoversClass(StaffLinkTokens::class)]
#[Group('markaspot_mail')]
final class StaffLinkTokensTest extends UnitTestCase {

  /**
   * Branding of a path-prefixed jurisdiction.
   */
  private const BONN_BRANDING = [
    'frontend_base_url' => 'https://bonn.example.org',
    'jurisdiction_slug' => 'bonn',
    'frontend_uses_jurisdiction_path' => TRUE,
  ];

  /**
   * Tests both tokens resolve from the jurisdiction branding.
   */
  public function testReplaceResolvesDashboardAndBackendUrls(): void {
    $branding = $this->brandingService(
      self::BONN_BRANDING,
      'https://management.bonn.example.org',
      expectedFrontend: 'https://bonn.example.org',
    );
    $metadata = new BubbleableMetadata();

    $replacements = $this->tokens($branding)->replace([
      'markaspot_dashboard_url' => '[node:markaspot_dashboard_url]',
      'markaspot_backend_edit_url' => '[node:markaspot_backend_edit_url]',
      'title' => '[node:title]',
    ], $this->node(), 'de', $metadata);

    $this->assertSame([
      '[node:markaspot_dashboard_url]' => 'https://bonn.example.org/bonn/dashboard/requests/7-2026',
      '[node:markaspot_backend_edit_url]' => 'https://management.bonn.example.org/node/123/edit',
    ], $replacements);
    $this->assertContains('config:markaspot_mail.settings', $metadata->getCacheTags());
    $this->assertContains('group:18', $metadata->getCacheTags());
    $this->assertContains('node:123', $metadata->getCacheTags());
    // The jurisdiction bundle and the number of jurisdictions (/<slug>
    // prefix, implicit fallback jurisdiction) shape the URLs as well.
    $this->assertContains('config:markaspot_open311.settings', $metadata->getCacheTags());
    $this->assertContains('group_list:jur', $metadata->getCacheTags());
  }

  /**
   * Tests other tokens add no cacheability.
   */
  public function testReplaceIgnoresOtherTokens(): void {
    $metadata = new BubbleableMetadata();

    $replacements = $this->tokens($this->brandingService(self::BONN_BRANDING, NULL))
      ->replace(['title' => '[node:title]'], $this->node(), 'de', $metadata);

    $this->assertSame([], $replacements);
    $this->assertSame([], $metadata->getCacheTags());
  }

  /**
   * Tests the backend token is empty when the guard rejects the base.
   */
  public function testBackendTokenIsEmptyWithoutUsableBackendBase(): void {
    $branding = $this->brandingService(
      self::BONN_BRANDING,
      NULL,
    );

    $tokens = $this->tokens($branding);

    $this->assertSame('', $tokens->backendEditUrl($this->node(), 'de'));
    $this->assertSame('https://bonn.example.org/bonn/dashboard/requests/7-2026', $tokens->dashboardUrl($this->node(), 'de'));
  }

  /**
   * Tests the dashboard token is empty without a frontend base.
   */
  public function testDashboardTokenIsEmptyWithoutFrontendBase(): void {
    $branding = $this->brandingService(['frontend_base_url' => ''], 'https://management.bonn.example.org');

    $this->assertSame('', $this->tokens($branding)->dashboardUrl($this->node(), 'de'));
  }

  /**
   * Tests an unsaved node has no backend URL and no request ID fallback.
   */
  public function testUnsavedNodeYieldsEmptyTokens(): void {
    $branding = $this->brandingService(['frontend_base_url' => 'https://bonn.example.org'], 'https://management.bonn.example.org');
    $node = $this->node(id: NULL, requestId: '');

    $tokens = $this->tokens($branding);

    $this->assertSame('', $tokens->backendEditUrl($node, 'de'));
    $this->assertSame('', $tokens->dashboardUrl($node, 'de'));
  }

  /**
   * Tests the dashboard token falls back to the node ID without request ID.
   */
  public function testDashboardTokenFallsBackToTheNodeId(): void {
    $branding = $this->brandingService(['frontend_base_url' => 'https://bonn.example.org/'], NULL);

    $this->assertSame(
      'https://bonn.example.org/dashboard/requests/123',
      $this->tokens($branding)->dashboardUrl($this->node(requestId: ''), 'de'),
    );
  }

  /**
   * Tests nodes other than service requests get no staff links.
   */
  public function testOtherBundlesYieldEmptyTokens(): void {
    $branding = $this->brandingService(['frontend_base_url' => 'https://bonn.example.org'], 'https://management.bonn.example.org');
    $node = $this->node(bundle: 'page');

    $tokens = $this->tokens($branding);

    $this->assertSame('', $tokens->dashboardUrl($node, 'de'));
    $this->assertSame('', $tokens->backendEditUrl($node, 'de'));
  }

  /**
   * Builds the token service.
   */
  private function tokens(MailBrandingService $branding): StaffLinkTokens {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getCacheTags')->willReturn(['config:markaspot_mail.settings']);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(-1);
    $open311 = $this->createMock(ImmutableConfig::class);
    $open311->method('get')->willReturn('jur');
    $open311->method('getCacheTags')->willReturn(['config:markaspot_open311.settings']);
    $open311->method('getCacheContexts')->willReturn([]);
    $open311->method('getCacheMaxAge')->willReturn(-1);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['markaspot_mail.settings', $config],
      ['markaspot_open311.settings', $open311],
    ]);
    return new StaffLinkTokens($branding, $configFactory);
  }

  /**
   * Builds a branding double for one jurisdiction.
   */
  private function brandingService(array $branding, ?string $backendBase, ?string $expectedFrontend = NULL): MailBrandingService {
    $service = $this->createMock(MailBrandingService::class);
    $service->method('getBranding')->willReturn($branding);
    $resolver = $service->method('resolveStaffBackendBase');
    if ($expectedFrontend !== NULL) {
      $resolver->with($expectedFrontend);
    }
    $resolver->willReturn($backendBase);
    return $service;
  }

  /**
   * Builds a service request node in jurisdiction 18.
   */
  private function node(?int $id = 123, string $requestId = '7-2026', string $bundle = 'service_request'): NodeInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('getEntityTypeId')->willReturn('group');
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('id')->willReturn(18);
    $jurisdiction->method('getCacheTags')->willReturn(['group:18']);
    $jurisdiction->method('getCacheContexts')->willReturn([]);
    $jurisdiction->method('getCacheMaxAge')->willReturn(-1);

    $reference = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $reference->method('isEmpty')->willReturn(FALSE);
    $reference->method('referencedEntities')->willReturn([$jurisdiction]);
    $requestIdField = $this->createMock(FieldItemListInterface::class);
    $requestIdField->method('isEmpty')->willReturn($requestId === '');
    $requestIdField->method('getString')->willReturn($requestId);
    $fields = [
      'field_jurisdiction' => $reference,
      'request_id' => $requestIdField,
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('bundle')->willReturn($bundle);
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    $node->method('getCacheTags')->willReturn($id !== NULL ? ['node:' . $id] : []);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    return $node;
  }

}
