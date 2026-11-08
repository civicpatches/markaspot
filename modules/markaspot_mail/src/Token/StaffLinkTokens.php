<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Token;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\StaffRequestLinksTrait;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;

/**
 * Node tokens for the staff links of a service request.
 *
 * - [node:markaspot_dashboard_url]: the request view in the markaspot-ui
 *   dashboard, e.g. https://example.org/<slug>/dashboard/requests/7-2026.
 * - [node:markaspot_backend_edit_url]: the Drupal edit form, e.g.
 *   https://management.example.org/node/123/edit.
 *
 * Both resolve exactly like the buttons of the organisation and assignee
 * mails (MailBrandingService plus StaffRequestLinksTrait), including the
 * guard that drops a backend base pointing at the frontend host. They do
 * not depend on markaspot_mail.settings:staff_links: placing a token in a
 * text is the choice. Empty values mean "no safe link": no frontend base,
 * no request ID, no usable backend base, or an unsaved node.
 */
final class StaffLinkTokens {

  use ResolveJurisdictionFromNodeTrait;
  use StaffRequestLinksTrait;

  /**
   * Token name of the dashboard request URL.
   */
  public const DASHBOARD_URL = 'markaspot_dashboard_url';

  /**
   * Token name of the Drupal backend edit URL.
   */
  public const BACKEND_EDIT_URL = 'markaspot_backend_edit_url';

  public function __construct(
    private readonly MailBrandingService $branding,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Replaces the staff link tokens of a node for hook_tokens().
   *
   * @param array<string, string> $tokens
   *   Token names mapped to their original text.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $langcode
   *   The language of the text.
   * @param \Drupal\Core\Render\BubbleableMetadata $metadata
   *   Cacheability of the replacements.
   *
   * @return array<string, string>
   *   Replacements keyed by original token text.
   */
  public function replace(array $tokens, NodeInterface $node, string $langcode, BubbleableMetadata $metadata): array {
    $ownTokens = array_intersect_key($tokens, [
      self::DASHBOARD_URL => TRUE,
      self::BACKEND_EDIT_URL => TRUE,
    ]);
    if ($ownTokens === []) {
      return [];
    }
    $this->addCacheableDependencies($node, $metadata);

    $replacements = [];
    foreach ($ownTokens as $name => $original) {
      $replacements[$original] = $name === self::DASHBOARD_URL
        ? $this->dashboardUrl($node, $langcode)
        : $this->backendEditUrl($node, $langcode);
    }
    return $replacements;
  }

  /**
   * Adds everything the staff link URLs depend on.
   *
   * Besides the node, its jurisdiction and the mail settings, the URLs
   * depend on the jurisdiction bundle (markaspot_open311.settings) and on
   * the list of jurisdictions: their number decides whether dashboard links
   * carry the /<slug> prefix and whether a request without a jurisdiction
   * is branded by the only one. The bundle list tag is invalidated when a
   * jurisdiction is created, deleted or saved, so it also covers a change
   * to that implicit fallback jurisdiction.
   */
  private function addCacheableDependencies(NodeInterface $node, BubbleableMetadata $metadata): void {
    $metadata->addCacheableDependency($node);
    $metadata->addCacheableDependency($this->configFactory->get('markaspot_mail.settings'));
    $metadata->addCacheableDependency($this->configFactory->get('markaspot_open311.settings'));
    $metadata->addCacheTags(['group_list:' . $this->resolvedJurisdictionGroupType()]);
    $jurisdiction = $this->resolveJurisdictionGroupFromNode($node);
    if ($jurisdiction !== NULL) {
      $metadata->addCacheableDependency($jurisdiction);
    }
  }

  /**
   * Returns the dashboard URL of a request, or '' without a safe link.
   */
  public function dashboardUrl(NodeInterface $node, string $langcode): string {
    if ($node->bundle() !== 'service_request') {
      return '';
    }
    return $this->buildDashboardRequestUrl($this->brandingFor($node, $langcode), $this->requestId($node), FALSE);
  }

  /**
   * Returns the Drupal backend edit URL of a request, or '' without one.
   */
  public function backendEditUrl(NodeInterface $node, string $langcode): string {
    if ($node->bundle() !== 'service_request' || $node->id() === NULL) {
      return '';
    }
    $branding = $this->brandingFor($node, $langcode);
    $backendBase = $this->branding->resolveStaffBackendBase((string) ($branding['frontend_base_url'] ?? ''));
    return $this->buildBackendRequestUrl($backendBase, $node, TRUE);
  }

  /**
   * Resolves the mail branding of the node's jurisdiction.
   */
  private function brandingFor(NodeInterface $node, string $langcode): array {
    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);
    return $this->branding->getBranding($jurisdictionId, $mode, $langcode);
  }

  /**
   * Resolves the human-facing request ID, falling back to the node ID.
   */
  private function requestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      $requestId = trim($node->get('request_id')->getString());
      if ($requestId !== '') {
        return $requestId;
      }
    }
    return (string) ($node->id() ?? '');
  }

}
