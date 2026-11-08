<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\RequestFieldValueTrait;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\StaffRequestLinksTrait;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_group:assignee_notification mails.
 */
final class AssigneeNotificationBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;
  use RequestFieldValueTrait;
  use StaffRequestLinksTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly MailBrandingService $branding,
    private readonly MailTextResolver $textResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_ASSIGNEE_NOTIFICATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_group' && $key === 'assignee_notification';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $node = $ctx->params['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('assignee_notification: missing node param, skipping branded render.');
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);
    $branding = $this->branding->getBranding($jurisdictionId, $mode, $ctx->langcode);
    $requestId = $this->resolveRequestId($node);
    $category = $this->resolveCategoryLabel($node);
    $address = $this->resolveAddressText($node);
    $description = $this->resolveBodyText($node);
    // The assignee gets the request view; staff_links decides whether it
    // opens in the dashboard, the Drupal backend or both.
    $links = $this->resolveStaffRequestLinks($branding, $node, $requestId, FALSE);
    $assignee = $ctx->params['assignee'] ?? NULL;
    $assigneeName = $assignee instanceof UserInterface
      ? trim($assignee->getDisplayName())
      : '';

    $defaultSubject = (string) $this->t('Request #@request_id assigned to you', [
      '@request_id' => $requestId,
    ], ['langcode' => $ctx->langcode]);
    $defaultPreheader = (string) $this->t('Request #@request_id was assigned to you', [
      '@request_id' => $requestId,
    ], ['langcode' => $ctx->langcode]);
    $defaultIntro = (string) $this->t('A citizen request is ready for review.', [], ['langcode' => $ctx->langcode]);
    $defaultLead = (string) $this->t(
      'This request has been assigned to you for processing.',
      [],
      ['langcode' => $ctx->langcode],
    );
    $defaultCtaLabel = (string) $this->t('Open request', [], ['langcode' => $ctx->langcode]);
    $defaultBackendCtaLabel = (string) $this->t('Open in the backend', [], ['langcode' => $ctx->langcode]);

    $slots = $this->resolveTextSlots('assignee_notification', $node, $ctx->langcode);
    $slots = $this->replaceContextPlaceholders($slots, [
      '{{ assignee }}' => $assigneeName,
      '{{ request_id }}' => $requestId,
    ]);

    $subject = $slots['subject'] !== '' ? $slots['subject'] : $defaultSubject;
    $headline = $slots['headline'] !== '' ? $slots['headline'] : $defaultSubject;
    $intro = $slots['intro'] !== '' ? $slots['intro'] : $defaultIntro;
    $preheader = $slots['preheader'] !== '' ? $slots['preheader'] : $defaultPreheader;
    $ctaLabel = $slots['cta_label'] !== '' ? $slots['cta_label'] : $defaultCtaLabel;
    $backendCtaLabel = $slots['backend_cta_label'] !== '' ? $slots['backend_cta_label'] : $defaultBackendCtaLabel;

    $bodyBlocks = $slots['body_blocks'] !== [] ? $slots['body_blocks'] : [$defaultLead];
    if ($description !== '') {
      $bodyBlocks[] = (string) $this->t('Description: @description', [
        '@description' => $description,
      ], ['langcode' => $ctx->langcode]);
    }

    $features = [
      [(string) $this->t('Request', [], ['langcode' => $ctx->langcode]) => '#' . $requestId],
    ];
    if ($category !== '') {
      $features[] = [(string) $this->t('Category', [], ['langcode' => $ctx->langcode]) => $category];
    }
    if ($address !== '') {
      $features[] = [(string) $this->t('Location', [], ['langcode' => $ctx->langcode]) => $address];
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => $preheader,
        'headline' => $headline,
        'intro' => $intro,
        'body_blocks' => $bodyBlocks,
        'features_block' => $features,
      ] + $this->buildStaffCtaContent($links, $branding, $ctaLabel, $backendCtaLabel),
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Resolves and token-replaces the editable text slots.
   */
  private function resolveTextSlots(string $key, NodeInterface $node, string $langcode): array {
    $resolved = $this->textResolver->resolve('markaspot_mail.texts', $key, $langcode);
    $slots = [
      'subject' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'subject', $langcode),
      'headline' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'headline', $langcode),
      'intro' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'intro', $langcode),
      'body_blocks' => $resolved['body_blocks'],
      'cta_label' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'cta_label', $langcode),
      'preheader' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'preheader', $langcode),
    ];
    $slots = $this->textResolver->replaceTokens($slots, ['node' => $node], $langcode);
    $slots['backend_cta_label'] = $this->textResolver->replaceLabelTokens(
      $this->textResolver->resolveField('markaspot_mail.texts', $key, 'backend_cta_label', $langcode),
      ['node' => $node],
      $langcode,
    );
    return $slots;
  }

  /**
   * Replaces assignment-only placeholders after generic token replacement.
   */
  private function replaceContextPlaceholders(array $slots, array $replacements): array {
    foreach (['subject', 'headline', 'cta_label', 'backend_cta_label', 'preheader'] as $slot) {
      $slots[$slot] = strtr((string) ($slots[$slot] ?? ''), $replacements);
    }
    $htmlReplacements = array_map(
      static fn(string $replacement): string => Html::escape($replacement),
      $replacements,
    );
    $slots['intro'] = Xss::filter(
      strtr($slots['intro'], $htmlReplacements),
      MailTextResolver::MAIL_ALLOWED_TAGS,
    );
    $slots['body_blocks'] = array_map(
      static fn(string $block): string => Xss::filter(
        strtr($block, $htmlReplacements),
        MailTextResolver::MAIL_ALLOWED_TAGS,
      ),
      $slots['body_blocks'],
    );
    return $slots;
  }

  /**
   * Resolves the human-facing request identifier.
   */
  private function resolveRequestId(NodeInterface $node): string {
    $requestId = $this->resolveFieldString($node, 'request_id');
    return $requestId !== '' ? $requestId : (string) $node->id();
  }

  /**
   * Resolves a taxonomy-like category label.
   */
  private function resolveCategoryLabel(NodeInterface $node): string {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return '';
    }
    $field = $node->get('field_category');
    if (!$field instanceof EntityReferenceFieldItemListInterface) {
      return '';
    }
    $category = $field->referencedEntities()[0] ?? NULL;
    return is_object($category) && method_exists($category, 'label')
      ? trim((string) $category->label())
      : '';
  }

  /**
   * Resolves a scalar node field value.
   */
  private function resolveFieldString(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim($node->get($fieldName)->getString());
  }

}
