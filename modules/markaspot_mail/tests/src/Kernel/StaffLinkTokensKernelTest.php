<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

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
   * Replaces tokens for a node with the token service.
   */
  private function replace(string $text, NodeInterface $node): string {
    return (string) $this->container->get('token')->replace($text, ['node' => $node], [
      'langcode' => 'en',
      'clear' => TRUE,
    ]);
  }

}
