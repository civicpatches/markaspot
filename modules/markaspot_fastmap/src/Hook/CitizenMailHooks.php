<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Hook;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycle;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\node\NodeInterface;

/**
 * Lifecycle wording in the citizen's notification mails.
 */
final class CitizenMailHooks {

  /**
   * The approval sentence per mail language.
   *
   * Static, not t(): works without the locale module, like the rest of the
   * confirmation, which comes from the translated markaspot_mail.texts
   * config. A language without an entry gets no
   * sentence rather than an English one in a mail of another language. The
   * placeholder is replaced with the workspace's citizen wording.
   */
  public const APPROVAL_NOTICE = [
    'en' => 'The operator of this map approves new {{ citizen_term_plural }} before they appear on the map.',
    'de' => 'Der Betreiber dieser Karte gibt neue {{ citizen_term_plural }} frei, bevor sie auf der Karte erscheinen.',
    'de-ls' => 'Der Betreiber von dieser Karte prüft neue {{ citizen_term_plural }} zuerst. Danach erscheinen sie auf der Karte.',
  ];

  public function __construct(
    private readonly WorkspaceLifecycleGate $gate,
    private readonly WorkspaceLifecycle $lifecycle,
    private readonly ReviewBeforePublish $reviewBeforePublish,
  ) {}

  /**
   * Tells the citizen that a held report appears after the approval.
   *
   * Only in the confirmation of a report held for the operator's approval
   * in an open workspace with a lifecycle record. Old-model workspaces and
   * tenants with approval outside the lifecycle model keep their text.
   */
  #[Hook('markaspot_mail_notification_slots_alter')]
  public function notificationSlotsAlter(array &$slots, array $context): void {
    $node = $context['node'] ?? NULL;
    if (($context['notification_key'] ?? NULL) !== 'report_confirmation'
      || !$node instanceof NodeInterface
      || $node->isPublished()
      || !$this->reviewBeforePublish->isHeld($node)
      || !$this->inOpenRecordWorkspace($node)) {
      return;
    }
    $sentence = self::approvalNotice((string) ($context['langcode'] ?? ''));
    if ($sentence !== NULL && is_array($slots['body_blocks'] ?? NULL)) {
      $slots['body_blocks'][] = $sentence;
    }
  }

  /**
   * The approval sentence for a mail language, or NULL without one.
   */
  public static function approvalNotice(string $langcode): ?string {
    $langcode = strtolower(trim($langcode));
    if (isset(self::APPROVAL_NOTICE[$langcode])) {
      return self::APPROVAL_NOTICE[$langcode];
    }
    $base = explode('-', $langcode, 2)[0];
    return self::APPROVAL_NOTICE[$base] ?? NULL;
  }

  /**
   * Whether the report belongs to an open workspace with a record.
   */
  private function inOpenRecordWorkspace(NodeInterface $node): bool {
    $field = $node->hasField('field_jurisdiction') ? $node->get('field_jurisdiction') : NULL;
    if (!$field instanceof EntityReferenceFieldItemListInterface) {
      return FALSE;
    }
    foreach ($field->referencedEntities() as $group) {
      $workspace = $group instanceof GroupInterface ? $this->gate->recordWorkspace($group) : NULL;
      if ($workspace !== NULL && $this->lifecycle->isOpen($workspace)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
