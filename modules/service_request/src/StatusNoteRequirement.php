<?php

declare(strict_types=1);

namespace Drupal\service_request;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rule behind the per-status "Note required" switch.
 *
 * A service_status term with field_note_required = TRUE may only be set on a
 * report together with a status note written in the same operation. The rule
 * lives here once; it is enforced at two entry points:
 *
 * - The ServiceRequestStatusNoteRequired constraint on field_status, for
 *   every writer that validates the report: JSON:API (dashboard form PATCH,
 *   bulk edit), GeoReport updates and the Drupal node form.
 * - StatusNoteController::add() checks the raw note input before it creates
 *   the paragraph, because the paragraph factory fills an empty note with the
 *   status description, which must not count as the author's note.
 *
 * Writers that save without validation (citizen feedback, contractor
 * responses, duplicate closing, ECA) and trusted system runs (drush, cron,
 * updatedb, imports) are not blocked. A report's creation is not a status
 * change and is never blocked either.
 */
final class StatusNoteRequirement {

  use StringTranslationTrait;

  /**
   * The boolean field on service_status terms.
   */
  public const FIELD = 'field_note_required';

  /**
   * Machine-readable error code for API clients.
   */
  public const ERROR_CODE = 'status_note_required';

  /**
   * Constructs the status note requirement.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container. The system write context of the install profile
   *   is optional and looked up when needed.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Returns the status term when it requires a note.
   */
  public function requiredTerm(int|string|null $term_id): ?TermInterface {
    if ($term_id === NULL || (int) $term_id <= 0
      || !$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $term_id);
    if (!$term instanceof TermInterface
      || $term->bundle() !== 'service_status'
      || !$term->hasField(self::FIELD)
      || $term->get(self::FIELD)->isEmpty()) {
      return NULL;
    }
    return (bool) $term->get(self::FIELD)->value ? $term : NULL;
  }

  /**
   * Whether a note text counts as a written note.
   *
   * Markup and whitespace alone do not count.
   */
  public function isNoteText(mixed $text): bool {
    if (!is_string($text)) {
      return FALSE;
    }
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $plain) ?? '') !== '';
  }

  /**
   * Finds a status change that lacks its required note.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A service request about to be saved.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The new status when it requires a note and the report does not add one
   *   in this operation; NULL when the save may proceed.
   */
  public function missingNote(NodeInterface $node): ?TermInterface {
    if ($node->bundle() !== 'service_request'
      || $node->isNew()
      || !$node->hasField('field_status')
      || $node->get('field_status')->isEmpty()
      || $this->isSystemContext($node)) {
      return NULL;
    }

    $new_status = (int) $node->get('field_status')->target_id;
    $term = $this->requiredTerm($new_status);
    if ($term === NULL) {
      return NULL;
    }

    $original = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if (!$original instanceof NodeInterface) {
      return NULL;
    }
    $old_status = $original->hasField('field_status') && !$original->get('field_status')->isEmpty()
      ? (int) $original->get('field_status')->target_id
      : 0;
    if ($old_status === $new_status) {
      return NULL;
    }

    return $this->addsNote($node, $original, $new_status) ? NULL : $term;
  }

  /**
   * The message shown when a required note is missing.
   */
  public function message(TermInterface $term): TranslatableMarkup {
    // Same text as ServiceRequestStatusNoteRequiredConstraint::$message.
    return $this->t('The status @status requires a status note. Add a note that explains the change.', [
      '@status' => $term->label(),
    ]);
  }

  /**
   * Whether the report adds a status note with text in this operation.
   *
   * A note counts when its paragraph is new or was not referenced before,
   * it belongs to the new status (or names no status) and its text is not
   * empty.
   */
  private function addsNote(NodeInterface $node, NodeInterface $original, int $new_status): bool {
    if (!$node->hasField('field_status_notes')) {
      return FALSE;
    }
    $known = [];
    if ($original->hasField('field_status_notes')) {
      foreach ($original->get('field_status_notes')->getValue() as $item) {
        $known[(int) ($item['target_id'] ?? 0)] = TRUE;
      }
    }

    foreach ($node->get('field_status_notes') as $item) {
      $paragraph = $item->entity ?? NULL;
      if (!$paragraph instanceof FieldableEntityInterface) {
        continue;
      }
      if (!$paragraph->isNew() && isset($known[(int) $paragraph->id()])) {
        continue;
      }
      if ($paragraph->hasField('field_status_term') && !$paragraph->get('field_status_term')->isEmpty()
        && (int) $paragraph->get('field_status_term')->target_id !== $new_status) {
        continue;
      }
      if ($paragraph->hasField('field_status_note')
        && $this->isNoteText($paragraph->get('field_status_note')->value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the save belongs to a trusted system run or a content sync.
   */
  private function isSystemContext(NodeInterface $node): bool {
    if ($node->isSyncing()) {
      return TRUE;
    }
    if (!$this->container->has('markaspot.system_write_context')) {
      return FALSE;
    }
    $context = $this->container->get('markaspot.system_write_context');
    return is_object($context) && method_exists($context, 'isTrusted') && $context->isTrusted();
  }

}
