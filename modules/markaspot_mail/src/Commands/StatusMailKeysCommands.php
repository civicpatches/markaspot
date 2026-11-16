<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\markaspot_mail\Service\StatusMailKeyMigration;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Inspects and switches the two status-mail automations explicitly.
 */
final class StatusMailKeysCommands extends DrushCommands {

  public function __construct(protected StatusMailKeyMigration $migration) {
    parent::__construct();
  }

  /**
   * Moves understood status-mail branches onto status term keys.
   *
   * Stop report writes, imports and queue workers before applying. Keep the
   * site in maintenance mode until the post-check passes. Maintenance mode
   * does not itself stop privileged requests or external workers. The command
   * checks existing cron/queue locks and database queue leases, and reserves
   * the cron lock; the operator must keep external workers stopped throughout.
   */
  #[CLI\Command(name: 'markaspot:status-mail-keys')]
  #[CLI\Option(name: 'apply', description: 'Apply in maintenance mode after stopping report writers and queue workers.')]
  #[CLI\Option(name: 'restore', description: 'Restore the before state from the named backup file, in maintenance mode.')]
  #[CLI\FieldLabels(labels: [
    'tid' => 'Term ID',
    'name' => 'Name',
    'jurisdiction' => 'Jurisdiction',
    'current' => 'Key today',
    'planned' => 'Key planned',
    'verdict' => 'Path verdict',
  ])]
  public function statusMailKeys(array $options = ['apply' => FALSE, 'restore' => '']): RowsOfFields {
    if (!empty($options['apply']) && !empty($options['restore'])) {
      throw new \InvalidArgumentException('Use either --apply or --restore.');
    }
    $result = !empty($options['restore'])
      ? $this->migration->restore($options['restore'])
      : (!empty($options['apply']) ? $this->migration->apply() : $this->migration->analyze());
    $this->io()->writeln($result['completed'] ?? $result['result']);
    if (isset($result['backup'])) {
      $this->io()->writeln('Before state: ' . $result['backup']);
    }
    return new RowsOfFields($result['rows']);
  }

}
