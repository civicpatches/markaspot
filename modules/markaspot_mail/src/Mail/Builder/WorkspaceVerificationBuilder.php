<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_mail\Service\MailStringTranslator;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_fastmap:workspace_verification mails.
 *
 * Sent to a workspace creator after sign-up on civicspot.io so they can
 * click through to activate the workspace within the configured cleanup
 * window (default 7 days). The legacy markaspot_fastmap_mail() hook
 * renders a plain-text body via @-placeholder strtr(); this builder
 * preserves the admin-editable copy in the config but wraps the result
 * in a branded card_transactional with a pill-shaped "Verify email" CTA.
 *
 * Required params:
 *   - workspace_name (string)
 *   - verify_url (string, absolute URL with verification token)
 *
 * Optional params:
 *   - site_name (string, defaults to "CivicSpot")
 *   - cleanup_days (int|string, defaults to 7)
 *   - lifecycle (bool): the signup carries an acceptance (lifecycle model).
 *     The mail asks to confirm the address before the workspace is set up.
 *
 * Always platform mode: the workspace doesn't have a jurisdiction yet
 * (that's precisely what the verification step unlocks), so there is no
 * jur group to attach Zone-1 branding to.
 *
 * Admin-facing subject + body template live in markaspot_fastmap.mail
 * .workspace_verification config with per-locale overrides. When the
 * config is missing we fall back to hardcoded t() copy so fresh test
 * environments still produce sensible mail.
 */
final class WorkspaceVerificationBuilder implements MailBuilderInterface {

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
    return MailType::FASTMAP_WORKSPACE_VERIFICATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_fastmap' && $key === 'workspace_verification';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $workspaceName = trim((string) ($ctx->params['workspace_name'] ?? ''));
    $verifyUrl = trim((string) ($ctx->params['verify_url'] ?? ''));
    if ($workspaceName === '' || $verifyUrl === '') {
      $this->logger->warning('workspace_verification: missing workspace_name or verify_url, skipping branded render.');
      return NULL;
    }

    $siteName = trim((string) ($ctx->params['site_name'] ?? ''));
    if ($siteName === '') {
      $siteName = 'CivicSpot';
    }
    $cleanupDays = (string) ($ctx->params['cleanup_days'] ?? '7');
    $langcode = $ctx->langcode;

    $replacements = [
      '@site_name' => $siteName,
      '@workspace_name' => $workspaceName,
      '@verify_url' => $verifyUrl,
      '@cleanup_days' => $cleanupDays,
    ];

    $subject = $this->resolveFromConfig('subject', $replacements, $langcode)
      ?: $this->translateMail('Activate your @site workspace "@name"', [
        '@site' => $siteName,
        '@name' => $workspaceName,
      ], $langcode);

    $lifecycle = ($ctx->params['lifecycle'] ?? FALSE) === TRUE;
    if ($lifecycle) {
      $preheader = $this->translateMail('Confirm your email address for @name', [
        '@name' => $workspaceName,
      ], $langcode);
      $headline = $this->translateMail('Confirm your email address', [], $langcode);
      $intro = $this->translateMail('Welcome to @site! Confirm your email address to set up your workspace "@name".', [
        '@site' => $siteName,
        '@name' => $workspaceName,
      ], $langcode);
      $action = $this->translateMail('Click the button below to confirm your email address.', [], $langcode);
    }
    else {
      $preheader = $this->translateMail('Click to activate your @name workspace', [
        '@name' => $workspaceName,
      ], $langcode);
      $headline = $this->translateMail('Activate your workspace', [], $langcode);
      $intro = $this->translateMail('Welcome to @site! Your workspace "@name" is ready to activate.', [
        '@site' => $siteName,
        '@name' => $workspaceName,
      ], $langcode);
      $action = $this->translateMail('Click the button below to verify your email and unlock the workspace.', [], $langcode);
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => $preheader,
        'headline' => $headline,
        'intro' => $intro,
        'body_blocks' => [
          $action,
          $this->translateMail('Unverified workspaces are automatically removed after @days days.', [
            '@days' => $cleanupDays,
          ], $langcode),
          $this->translateMail('Open the link in the browser you signed up with to be signed in automatically. In any other browser you can sign in with a code that we send to this email address.', [], $langcode),
        ],
        'cta_label' => $this->translateMail('Verify email', [], $langcode),
        'cta_url' => $verifyUrl,
      ],
      mode: 'platform',
    );
  }

  /**
   * Reads a config template for subject/body and substitutes @placeholders.
   *
   * Tries language-override first (per-locale editable subject), then the
   * default config, then returns an empty string so the caller can apply
   * its t()-based fallback.
   */
  private function resolveFromConfig(string $key, array $replacements, string $langcode): string {
    $template = $this->textResolver->resolveField('markaspot_fastmap.mail', 'workspace_verification', $key, $langcode);
    if ($template === '') {
      return '';
    }
    return (string) strtr($template, $replacements);
  }

}
