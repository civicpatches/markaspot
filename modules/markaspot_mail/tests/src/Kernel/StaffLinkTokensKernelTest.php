<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\group\Entity\Group;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the staff link tokens through Drupal's token service.
 *
 * Covers discovery of the token hooks in markaspot_mail.module and the
 * real MailBrandingService resolution, guard included, for the tokens ECA
 * and other mail texts use.
 */
#[TestGroup('markaspot_mail')]
#[RunTestsInSeparateProcesses]
final class StaffLinkTokensKernelTest extends StaffLinkKernelTestBase {

  /**
   * Tests both tokens are declared for nodes.
   */
  public function testTokensAreDeclared(): void {
    $info = $this->container->get('token')->getInfo();

    $this->assertArrayHasKey('markaspot_dashboard_url', $info['tokens']['node']);
    $this->assertArrayHasKey('markaspot_backend_edit_url', $info['tokens']['node']);
  }

  /**
   * Tests the tokens use the staff backend base and ignore staff_links.
   */
  public function testTokensResolveWithTheStaffBackendBase(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ]);

    $text = $this->replace('[node:markaspot_dashboard_url] | [node:markaspot_backend_edit_url]', $this->node(4711));

    $this->assertSame('https://melder.example.org/bonn/dashboard/requests/4711 | https://management.example.org/node/4711/edit', $text);
  }

  /**
   * Tests the backend token is empty when the asset base is the frontend.
   */
  public function testBackendTokenIsEmptyWhenTheBaseIsTheFrontendHost(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => '',
    ]);

    $text = $this->replace('[[node:markaspot_backend_edit_url]]', $this->node(4711));

    $this->assertSame('[]', $text);
  }

  /**
   * Tests the backend token falls back to the asset origin of the backend.
   */
  public function testBackendTokenFallsBackToTheBackendBaseUrl(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://management.example.org',
    ]);

    $text = $this->replace('[node:markaspot_backend_edit_url]', $this->node(4711));

    $this->assertSame('https://management.example.org/node/4711/edit', $text);
  }

  /**
   * Tests other bundles and unsaved nodes get empty values.
   */
  public function testOtherBundlesAndUnsavedNodesAreEmpty(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ]);

    $this->assertSame('[]', $this->replace('[[node:markaspot_backend_edit_url]]', $this->node(4711, 'page')));
    $this->assertSame('[][]', $this->replace('[[node:markaspot_backend_edit_url]][[node:markaspot_dashboard_url]]', $this->node(NULL)));
  }

  /**
   * Tests cached token output is invalidated when jurisdictions change.
   *
   * The /<slug> prefix depends on the number of jurisdictions, so an entry
   * cached with the bubbled tags must not outlive a one-to-two transition
   * in either direction.
   */
  public function testCachedTokenOutputFollowsTheJurisdictionCount(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ]);
    $cache = $this->container->get('cache.default');
    $node = $this->node(4711);

    // Two jurisdictions: the dashboard link carries the slug.
    $metadata = new BubbleableMetadata();
    $text = $this->replace('[node:markaspot_dashboard_url]', $node, $metadata);
    $this->assertSame('https://melder.example.org/bonn/dashboard/requests/4711', $text);
    $this->assertContains('group_list:jur', $metadata->getCacheTags());
    $this->assertContains('config:markaspot_open311.settings', $metadata->getCacheTags());
    $this->assertContains('config:markaspot_mail.settings', $metadata->getCacheTags());
    $this->assertContains('group:' . $this->bonn->id(), $metadata->getCacheTags());
    $cache->set('staff_link', $text, CacheBackendInterface::CACHE_PERMANENT, $metadata->getCacheTags());

    // Two to one: the cached URL is stale, a fresh one drops the slug.
    $this->dorsten->delete();
    $this->assertFalse($cache->get('staff_link'));
    $this->resetBrandingServices();
    $metadata = new BubbleableMetadata();
    $text = $this->replace('[node:markaspot_dashboard_url]', $node, $metadata);
    $this->assertSame('https://melder.example.org/dashboard/requests/4711', $text);
    $cache->set('staff_link', $text, CacheBackendInterface::CACHE_PERMANENT, $metadata->getCacheTags());

    // One to two: stale again, the slug is back.
    Group::create(['type' => 'jur', 'label' => 'Haltern', 'field_slug' => 'haltern'])->save();
    $this->assertFalse($cache->get('staff_link'));
    $this->resetBrandingServices();
    $this->assertSame(
      'https://melder.example.org/bonn/dashboard/requests/4711',
      $this->replace('[node:markaspot_dashboard_url]', $node),
    );
  }

  /**
   * Replaces tokens for a node with the token service.
   */
  private function replace(string $text, NodeInterface $node, ?BubbleableMetadata $metadata = NULL): string {
    return (string) $this->container->get('token')->replace($text, ['node' => $node], [
      'langcode' => 'en',
      'clear' => TRUE,
    ], $metadata);
  }

  /**
   * Drops the request-scoped branding memo, as a new request would.
   */
  private function resetBrandingServices(): void {
    $this->container->set('markaspot_mail.staff_link_tokens', NULL);
    $this->container->set('markaspot_mail.branding', NULL);
  }

}
