<?php

/**
 * @file
 * Hooks provided by the Mark-a-Spot Mail module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the text slots of a notification mail before rendering.
 *
 * Runs in NotificationTextBuilder after the slots were read from
 * markaspot_mail.texts in the mail language and before the citizen wording
 * placeholders ({{ citizen_term_singular }} and the like) and the [node:*]
 * tokens are replaced, so added text may use both. Keep the slot shape:
 * strings for subject, headline, intro, cta_label and preheader, a list of
 * strings for body_blocks. Added text must be in the mail language
 * ($context['langcode']); add nothing rather than text in another language.
 *
 * Body blocks are rendered raw (mail-card-transactional.html.twig:
 * {{ block|raw }}). Pass escaped or otherwise safe markup only: escape every
 * dynamic value, for example with Html::escape() or a t() placeholder.
 *
 * @param array $slots
 *   The slots: subject, headline, intro, body_blocks, cta_label, preheader.
 * @param array $context
 *   Context of the mail:
 *   - notification_key: the markaspot_mail.texts key, e.g.
 *     report_confirmation.
 *   - node: the report the mail is about.
 *   - langcode: the mail language.
 */
function hook_markaspot_mail_notification_slots_alter(array &$slots, array $context): void {
  if ($context['notification_key'] === 'report_confirmation' && !$context['node']->isPublished()) {
    $slots['body_blocks'][] = 'Your {{ citizen_term_singular }} is reviewed before it is published.';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
