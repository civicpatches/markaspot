<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceDeletionService;
use Drupal\markaspot_fastmap\Service\WorkspaceOpening;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for FastMap workspace operations.
 */
class WorkspaceCommands extends DrushCommands {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly WorkspaceDeletionService $deletion,
    protected readonly WorkspaceOpening $opening,
  ) {
    parent::__construct();
  }

  /**
   * Blocks a workspace immediately.
   */
  #[CLI\Command(name: 'markaspot:workspace-block', aliases: ['mas:workspace-block'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Option(name: 'reason', description: 'Reason logged for operators.')]
  #[CLI\Usage(name: 'markaspot:workspace-block amsterdam --reason=spam', description: 'Block a workspace by slug.')]
  public function block(string $workspace, array $options = ['reason' => 'spam suspicion']): void {
    $group = $this->loadWorkspace($workspace);
    $this->setVisibility($group, 'blocked');

    $reason = trim((string) ($options['reason'] ?? 'spam suspicion'));
    $this->logger()->warning('Workspace @id (@label) blocked via Drush. Reason: @reason', [
      '@id' => $group->id(),
      '@label' => $group->label(),
      '@reason' => $reason !== '' ? $reason : 'not specified',
    ]);

    $this->output()->writeln(sprintf(
      'Workspace %s (%s) is now blocked.',
      $group->id(),
      $group->label(),
    ));
  }

  /**
   * Unblocks a workspace by restoring a regular visibility mode.
   */
  #[CLI\Command(name: 'markaspot:workspace-unblock', aliases: ['mas:workspace-unblock'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Option(name: 'visibility', description: 'Visibility to restore: public, submission_only, form_only, or authenticated.')]
  #[CLI\Usage(name: 'markaspot:workspace-unblock amsterdam --visibility=public', description: 'Unblock a workspace by slug.')]
  public function unblock(string $workspace, array $options = ['visibility' => 'public']): void {
    $visibility = (string) ($options['visibility'] ?? 'public');
    if (!in_array($visibility, ['public', 'submission_only', 'form_only', 'authenticated'], TRUE)) {
      throw new \InvalidArgumentException('--visibility must be one of: public, submission_only, form_only, authenticated.');
    }

    $group = $this->loadWorkspace($workspace);
    $this->setVisibility($group, $visibility);

    $this->logger()->notice('Workspace @id (@label) unblocked via Drush and set to @visibility.', [
      '@id' => $group->id(),
      '@label' => $group->label(),
      '@visibility' => $visibility,
    ]);

    $this->output()->writeln(sprintf(
      'Workspace %s (%s) visibility restored to %s.',
      $group->id(),
      $group->label(),
      $visibility,
    ));
  }

  /**
   * Lists workspaces whose self-service deletion is scheduled.
   */
  #[CLI\Command(name: 'markaspot:workspace-deletions', aliases: ['mas:workspace-deletions'])]
  #[CLI\Usage(name: 'markaspot:workspace-deletions', description: 'Show scheduled workspace deletions.')]
  public function deletions(): void {
    $schedules = $this->deletion->getSchedules();
    if ($schedules === []) {
      $this->output()->writeln('No workspace deletion is scheduled.');
      return;
    }
    foreach ($schedules as $gid => $schedule) {
      $this->output()->writeln(sprintf(
        '%s  %s (%s)  confirmed %s  teardown %s',
        $gid,
        $schedule['label'] ?? '',
        $schedule['slug'] ?? '',
        date('Y-m-d H:i', (int) ($schedule['confirmed_at'] ?? 0)),
        date('Y-m-d H:i', (int) ($schedule['scheduled_for'] ?? 0)),
      ));
    }
  }

  /**
   * Cancels a scheduled self-service deletion and brings the workspace back.
   */
  #[CLI\Command(name: 'markaspot:workspace-restore', aliases: ['mas:workspace-restore'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Usage(name: 'markaspot:workspace-restore 42', description: 'Restore workspace 42 before its teardown.')]
  public function restore(string $workspace): void {
    $group = $this->loadWorkspace($workspace);
    if (!$this->deletion->restore($group)) {
      throw new \RuntimeException(sprintf('No deletion is scheduled for workspace %s.', $group->id()));
    }
    $this->output()->writeln(sprintf(
      'Workspace %s (%s) is online again. A cancelled Stripe subscription stays cancelled; the workspace continues on the free tier.',
      $group->id(),
      $group->label(),
    ));
  }

  /**
   * Removes false operator data from an opened map and closes it again.
   *
   * For a workspace with a lifecycle record: clears organisation, person
   * name, street, postal code, city, contact e-mail (the country stays) and
   * the legal notice in every language, takes the opening back and saves one
   * new revision. The operator can
   * enter correct data and open the map again. Older revisions keep their
   * values. Dry run unless --apply is given.
   */
  #[CLI\Command(name: 'markaspot:workspace-reset-operator', aliases: ['mas:workspace-reset-operator'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Option(name: 'apply', description: 'Write the change. Without it the command only reports what it would change.')]
  #[CLI\Usage(name: 'markaspot:workspace-reset-operator tree-map', description: 'Show what would be removed.')]
  #[CLI\Usage(name: 'markaspot:workspace-reset-operator tree-map --apply', description: 'Remove the operator data and close the map.')]
  public function resetOperator(string $workspace, array $options = ['apply' => FALSE]): void {
    $group = $this->loadWorkspace($workspace);
    $apply = (bool) ($options['apply'] ?? FALSE);
    $result = $this->opening->resetOperator($group, $apply);
    $label = sprintf('Workspace %s (%s)', $group->id(), $group->label());
    $parts = implode(', ', $result['parts'] ?? []) ?: 'none';
    $closing = !empty($result['was_open']) ? 'the map is open and will be closed' : 'the map is not open';

    match ($result['status']) {
      WorkspaceOpening::NOT_APPLICABLE => throw new \RuntimeException("$label has no lifecycle record on the self-service platform; nothing to reset."),
      WorkspaceOpening::BUSY => throw new \RuntimeException("$label is being opened right now. Try again."),
      WorkspaceOpening::NOTHING_TO_RESET => $this->output()->writeln("$label: no operator data and not open; nothing to reset."),
      WorkspaceOpening::WOULD_RESET => $this->output()->writeln("Dry run. $label: would clear $parts; $closing. Older revisions keep their values. Run again with --apply to write."),
      default => $this->output()->writeln("$label: cleared $parts; " . (!empty($result['was_open']) ? 'the map is closed again' : 'the map was not open') . '. A new revision was saved; older revisions keep their values.'),
    };
  }

  /**
   * Loads a jurisdiction workspace by numeric ID or slug.
   */
  private function loadWorkspace(string $workspace): GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');

    if (ctype_digit($workspace)) {
      $group = $storage->load((int) $workspace);
    }
    else {
      if (!preg_match('/^[a-z0-9_-]{1,64}$/', $workspace)) {
        throw new \InvalidArgumentException('Workspace must be a numeric group ID or a valid slug.');
      }
      $matches = $storage->loadByProperties([
        'type' => 'jur',
        'field_slug' => $workspace,
      ]);
      $group = reset($matches) ?: NULL;
    }

    if (!$group instanceof GroupInterface || $group->bundle() !== 'jur') {
      throw new \RuntimeException(sprintf('Workspace "%s" was not found.', $workspace));
    }

    if (!$group->hasField('field_visibility')) {
      throw new \RuntimeException(sprintf('Workspace "%s" has no field_visibility field.', $workspace));
    }

    return $group;
  }

  /**
   * Updates the workspace visibility field.
   */
  private function setVisibility(GroupInterface $group, string $visibility): void {
    $group->set('field_visibility', $visibility);
    $group->save();
  }

}
