<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

/**
 * Persists hashed tickets with atomic consumption outside node transactions.
 */
final class StaffIntakeTicketStore {

  public const TABLE = 'markaspot_staff_intake_ticket';
  public const LIFETIME = 600;

  /**
   * Independent connection, never enlisted in an entity save transaction.
   */
  private ?Connection $connection = NULL;

  /**
   * Constructs the ticket store.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Opens the same database and prefix with an independent transaction stack.
   */
  private function connection(): Connection {
    if ($this->connection === NULL) {
      $options = $this->database->getConnectionOptions();
      // A persistent PDO handle must never share the node transaction.
      $options['pdo'][\PDO::ATTR_PERSISTENT] = FALSE;
      Database::addConnectionInfo('markaspot_staff_intake', 'default', $options);
      $this->connection = Database::getConnection('default', 'markaspot_staff_intake');
    }
    return $this->connection;
  }

  /**
   * Issues a random 256-bit ticket; only its digest is retained.
   */
  public function issue(int $uid, string $sessionHash, int $workspace): string {
    $ticket = bin2hex(random_bytes(32));
    $this->connection()->insert(self::TABLE)->fields([
      'ticket_hash' => hash('sha256', $ticket),
      'session_hash' => $sessionHash,
      'uid' => $uid,
      'workspace_id' => $workspace,
      'created' => $this->time->getCurrentTime(),
      'used' => 0,
    ])->execute();
    return $ticket;
  }

  /**
   * Looks up a candidate without consuming it or exposing its plaintext.
   *
   * @return array<string, mixed>|null
   *   An unused, unexpired record, or NULL.
   */
  public function find(string $ticket, int $uid, string $sessionHash): ?array {
    if (preg_match('/^[0-9a-f]{64}$/D', $ticket) !== 1) {
      return NULL;
    }
    $row = $this->connection()->select(self::TABLE, 't')->fields('t')
      ->condition('ticket_hash', hash('sha256', $ticket))
      ->condition('uid', $uid)->condition('session_hash', $sessionHash)
      ->condition('used', 0)
      ->condition('created', $this->time->getCurrentTime() - self::LIFETIME, '>')
      ->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Atomically commits one redemption before any entity save can roll back.
   */
  public function consume(string $ticket, int $uid, string $sessionHash): bool {
    return $this->connection()->update(self::TABLE)->fields(['used' => 1])
      ->condition('ticket_hash', hash('sha256', $ticket))
      ->condition('uid', $uid)->condition('session_hash', $sessionHash)
      ->condition('used', 0)
      ->condition('created', $this->time->getCurrentTime() - self::LIFETIME, '>')
      ->execute() === 1;
  }

  /**
   * Bounds storage, including expired records already used by failed saves.
   */
  public function purge(): void {
    $this->connection()->delete(self::TABLE)
      ->condition('created', $this->time->getCurrentTime() - self::LIFETIME, '<=')
      ->execute();
  }

}
