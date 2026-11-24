<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_mail\Service\MailStringTranslator;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_fastmap:workspace_welcome mails.
 *
 * Sent to a workspace creator right after email verification unlocks the
 * new workspace on civicspot.io. The legacy markaspot_fastmap_mail() hook
 * renders a plain-text body via @-placeholder strtr(); this builder
 * preserves the admin-editable copy in the config but wraps the result
 * in a branded card_transactional with an "Open your workspace" CTA.
 *
 * Required params:
 *   - workspace_name (string)
 *   - workspace_url (string, absolute URL to the provisioned workspace)
 *
 * Optional params:
 *   - site_name (string, defaults to "CivicSpot")
 *   - wording_preset (string, one of report, suggestion, entry, contribution)
 *   - wording_singular (string, used only with suggestion, entry, or
 *     contribution presets)
 *   - wording_plural (string, used only with suggestion, entry, or
 *     contribution presets)
 *   - lifecycle (bool): the workspace carries a lifecycle record. The mail
 *     says the workspace is ready instead of calling it active.
 *
 * Always platform mode: the recipient is a workspace owner on the
 * CivicSpot SaaS, not a citizen of any single jur group.
 *
 * Admin-facing subject + body template live in markaspot_fastmap.mail
 * .workspace_welcome config with per-locale overrides. When the config is
 * missing we fall back to hardcoded t() copy so fresh test environments
 * still produce sensible mail.
 */
final class WorkspaceWelcomeBuilder implements MailBuilderInterface {

  use MailStringTranslationTrait;

  public function __construct(
    private readonly MailTextResolver $textResolver,
    private readonly LoggerInterface $logger,
    private readonly ?MailStringTranslator $mailStrings = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::FASTMAP_WORKSPACE_WELCOME;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_fastmap' && $key === 'workspace_welcome';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $workspaceName = trim((string) ($ctx->params['workspace_name'] ?? ''));
    $workspaceUrl = trim((string) ($ctx->params['workspace_url'] ?? ''));
    $workspaceScheme = strtolower((string) parse_url($workspaceUrl, PHP_URL_SCHEME));
    if ($workspaceName === ''
      || $workspaceUrl === ''
      || !UrlHelper::isValid($workspaceUrl, TRUE)
      || !in_array($workspaceScheme, ['http', 'https'], TRUE)
      || UrlHelper::stripDangerousProtocols($workspaceUrl) !== $workspaceUrl) {
      $this->logger->warning('workspace_welcome: missing or invalid workspace_name/workspace_url, skipping branded render.');
      return NULL;
    }

    $siteName = trim((string) ($ctx->params['site_name'] ?? ''));
    if ($siteName === '') {
      $siteName = 'CivicSpot';
    }
    $wordingPreset = $ctx->params['wording_preset'] ?? NULL;
    $wordingPreset = is_string($wordingPreset) ? trim($wordingPreset) : '';
    $wordingSingular = $ctx->params['wording_singular'] ?? NULL;
    $wordingSingular = is_string($wordingSingular) ? trim($wordingSingular) : '';
    $wordingPlural = $ctx->params['wording_plural'] ?? NULL;
    $wordingPlural = is_string($wordingPlural) ? trim($wordingPlural) : '';
    $langcode = $ctx->langcode;
    $baseUrl = rtrim($workspaceUrl, '/');
    $dashboardUrl = $baseUrl . '/dashboard';
    $loginUrl = $baseUrl . '/auth/login';

    // Preserve the established source strings where no custom term is given.
    // Existing locale translations are keyed to these strings.
    $customWordingPresets = [
      'suggestion' => ['singular' => 'suggestion', 'plural' => 'suggestions'],
      'entry' => ['singular' => 'entry', 'plural' => 'entries'],
      'contribution' => ['singular' => 'contribution', 'plural' => 'contributions'],
    ];
    $customWording = $customWordingPresets[$wordingPreset] ?? NULL;
    if ($customWording !== NULL) {
      $wordingSingular = $wordingSingular !== '' ? $wordingSingular : $customWording['singular'];
      $wordingPlural = $wordingPlural !== '' ? $wordingPlural : $customWording['plural'];
    }

    $testReportBlock = $customWording === NULL
      ? $this->translateMail('Try it out: create your first test report directly on the map.', [], $langcode)
      : $this->translateMail('Try it out: create your first test @wording_singular directly on the map.', [
        '@wording_singular' => $wordingSingular,
      ], $langcode);
    $demoReportsBlock = $customWording === NULL
      ? $this->translateMail('A few demo reports are already in place. Edit or delete them anytime.', [], $langcode)
      : $this->translateMail('A few demo @wording_plural are already in place. Edit or delete them anytime.', [
        '@wording_plural' => $wordingPlural,
      ], $langcode);
    $dashboardReportsLabel = $customWording === NULL
      ? $this->translateMail('Manage incoming reports in your dashboard:', [], $langcode)
      : $this->translateMail('Manage incoming @wording_plural in your dashboard:', [
        '@wording_plural' => $wordingPlural,
      ], $langcode);
    $dashboardReportsBlock = $dashboardReportsLabel . ' ' . $this->visibleLink($dashboardUrl);
    $loginBlock = $this->translateMail('Log in anytime:', [], $langcode);
    $loginBlock .= ' ' . $this->visibleLink($loginUrl);

    $replacements = [
      '@site_name' => $siteName,
      '@workspace_name' => $workspaceName,
      '@workspace_url' => $workspaceUrl,
    ];

    $subject = $this->resolveFromConfig('subject', $replacements, $langcode)
      ?: $this->translateMail('Your @site workspace "@name" is ready', [
        '@site' => $siteName,
        '@name' => $workspaceName,
      ], $langcode);

    $lifecycle = ($ctx->params['lifecycle'] ?? FALSE) === TRUE;
    if ($lifecycle) {
      $preheader = $this->translateMail('Your @name workspace is ready', [
        '@name' => $workspaceName,
      ], $langcode);
      $intro = $this->translateMail('Workspace "@name" is ready.', [
        '@name' => $workspaceName,
      ], $langcode);
    }
    else {
      $preheader = $this->translateMail('Your @name workspace is now active', [
        '@name' => $workspaceName,
      ], $langcode);
      $intro = $this->translateMail('Workspace "@name" is now active.', [
        '@name' => $workspaceName,
      ], $langcode);
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => $preheader,
        'headline' => $this->translateMail('Your workspace is ready', [], $langcode),
        'intro' => $intro,
        'body_blocks' => [
          $testReportBlock,
          $demoReportsBlock,
          // Anchor text deliberately repeats the URL: body_blocks render
          // |raw in the HTML card (clickable link), while the derived
          // text/plain part strip_tags()es the markup and must keep the
          // URL visible.
          $dashboardReportsBlock,
          $loginBlock,
        ],
        'cta_label' => $this->translateMail('Open your workspace', [], $langcode),
        'cta_url' => $workspaceUrl,
      ],
      mode: 'platform',
    );
  }

  /**
   * Builds a clickable link whose URL remains visible in the plain-text part.
   */
  private function visibleLink(string $url): string {
    $escapedUrl = Html::escape($url);
    return '<a href="' . $escapedUrl . '" style="color:#2563eb; text-decoration:underline;">' . $escapedUrl . '</a>';
  }

  /**
   * Reads a config template for subject/body and substitutes @placeholders.
   *
   * Tries language-override first (per-locale editable subject), then the
   * default config, then returns an empty string so the caller can apply
   * its t()-based fallback.
   */
  private function resolveFromConfig(string $key, array $replacements, string $langcode): string {
    $template = $this->textResolver->resolveField('markaspot_fastmap.mail', 'workspace_welcome', $key, $langcode);
    if ($template === '') {
      return '';
    }
    return (string) strtr($template, $replacements);
  }

}
