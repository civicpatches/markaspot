<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;

/**
 * Builds the request links of staff mails from resolved mail branding.
 *
 * Shared by the organisation and assignee notification builders and the
 * [node:markaspot_dashboard_url] / [node:markaspot_backend_edit_url] tokens,
 * so mail buttons and ECA texts link to the same place. The inputs are the
 * branding keys MailBrandingService::getBranding() returns
 * (frontend_base_url, jurisdiction_slug, frontend_uses_jurisdiction_path,
 * staff_links, staff_backend_base); nothing here reads configuration.
 */
trait StaffRequestLinksTrait {

  /**
   * Builds the dashboard URL of a request.
   *
   * @param array $branding
   *   Resolved mail branding.
   * @param string $requestId
   *   The human-facing request ID.
   * @param bool $edit
   *   Whether to link the dashboard edit page instead of the request view.
   *
   * @return string
   *   The URL, or an empty string without frontend base or request ID.
   */
  protected function buildDashboardRequestUrl(array $branding, string $requestId, bool $edit): string {
    $frontendBase = rtrim((string) ($branding['frontend_base_url'] ?? ''), '/');
    if ($frontendBase === '' || $requestId === '') {
      return '';
    }
    $slug = trim((string) ($branding['jurisdiction_slug'] ?? ''));
    $useJurisdictionPath = (bool) ($branding['frontend_uses_jurisdiction_path'] ?? ($slug !== ''));
    $prefix = $useJurisdictionPath && $slug !== '' ? '/' . rawurlencode($slug) : '';
    return $frontendBase . $prefix . '/dashboard/requests/' . rawurlencode($requestId) . ($edit ? '/edit' : '');
  }

  /**
   * Builds the Drupal backend URL of a saved request node.
   *
   * @param string|null $backendBase
   *   The staff backend base URL, already guarded by MailBrandingService.
   * @param \Drupal\node\NodeInterface $node
   *   The request node.
   * @param bool $edit
   *   Whether to link the edit form (/node/<nid>/edit) or the node view.
   *
   * @return string
   *   The URL, or an empty string without base or for an unsaved node.
   */
  protected function buildBackendRequestUrl(?string $backendBase, NodeInterface $node, bool $edit): string {
    $base = rtrim((string) $backendBase, '/');
    $nid = (string) ($node->id() ?? '');
    if ($base === '' || preg_match('/^[1-9][0-9]*$/', $nid) !== 1) {
      return '';
    }
    return $base . '/node/' . $nid . ($edit ? '/edit' : '');
  }

  /**
   * Resolves the staff links of a request in display order.
   *
   * - dashboard: the dashboard link.
   * - backend: the backend link, or the dashboard link when the node has no
   *   backend URL yet.
   * - both: the dashboard link first, then the backend link.
   *
   * @param array $branding
   *   Resolved mail branding.
   * @param \Drupal\node\NodeInterface $node
   *   The request node.
   * @param string $requestId
   *   The human-facing request ID.
   * @param bool $edit
   *   Whether the links open the edit pages.
   *
   * @return list<array{kind: string, url: string}>
   *   Zero, one or two links; kind is "dashboard" or "backend".
   */
  protected function resolveStaffRequestLinks(array $branding, NodeInterface $node, string $requestId, bool $edit): array {
    $mode = (string) ($branding['staff_links'] ?? MailBrandingService::STAFF_LINKS_DASHBOARD);
    $dashboard = [
      'kind' => 'dashboard',
      'url' => $this->buildDashboardRequestUrl($branding, $requestId, $edit),
    ];
    $backend = [
      'kind' => 'backend',
      'url' => in_array($mode, [MailBrandingService::STAFF_LINKS_BACKEND, MailBrandingService::STAFF_LINKS_BOTH], TRUE)
        ? $this->buildBackendRequestUrl($branding['staff_backend_base'] ?? NULL, $node, $edit)
        : '',
    ];
    $ordered = match ($mode) {
      MailBrandingService::STAFF_LINKS_BACKEND => $backend['url'] !== '' ? [$backend] : [$dashboard],
      MailBrandingService::STAFF_LINKS_BOTH => [$dashboard, $backend],
      default => [$dashboard],
    };
    return array_values(array_filter(
      $ordered,
      static fn(array $link): bool => $link['url'] !== '',
    ));
  }

  /**
   * Maps staff links onto the card_transactional CTA content keys.
   *
   * The first link is the button (cta_label, cta_url), a second one the
   * secondary link (secondary_cta_label, secondary_cta_url). The backend
   * label is used for backend links only when the mail carries both kinds
   * (staff_links both); with staff_links backend the single button keeps the
   * regular button label.
   *
   * @param list<array{kind: string, url: string}> $links
   *   Links from resolveStaffRequestLinks().
   * @param array $branding
   *   Resolved mail branding.
   * @param string $ctaLabel
   *   The regular button label.
   * @param string $backendCtaLabel
   *   The label of the Drupal backend link.
   *
   * @return array<string, string>
   *   cta_label and cta_url always; the secondary keys only with a second
   *   link.
   */
  protected function buildStaffCtaContent(array $links, array $branding, string $ctaLabel, string $backendCtaLabel): array {
    $both = ($branding['staff_links'] ?? NULL) === MailBrandingService::STAFF_LINKS_BOTH;
    $labelFor = static fn(array $link): string => $both && $link['kind'] === 'backend'
      ? $backendCtaLabel
      : $ctaLabel;

    $primary = $links[0] ?? NULL;
    $content = [
      'cta_label' => $primary !== NULL ? $labelFor($primary) : '',
      'cta_url' => $primary['url'] ?? '',
    ];
    $secondary = $links[1] ?? NULL;
    if ($secondary !== NULL) {
      $content['secondary_cta_label'] = $labelFor($secondary);
      $content['secondary_cta_url'] = $secondary['url'];
    }
    return $content;
  }

}
