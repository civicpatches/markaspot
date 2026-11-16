<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

/**
 * Proves equivalence of the two status-mail graphs before any mutation.
 *
 * Unlike the text migrator's keyword and immediate-predecessor heuristics,
 * this traverses every reachable path and accepts only the known semantics.
 */
final class StatusMailKeyAnalyzer {

  public const OLD_MODEL = 'process_tunr6d6';
  public const TERM_MODEL = 'process_notify_status_term';
  public const KEYS = ['status_open', 'status_closed', 'status_not_responsible'];
  private const RECIPIENT = '[node:field_e_mail:value]';

  /**
   * Analyzes active and inactive models, with status terms indexed by ID.
   *
   * @return array{rows: array, keys: array, result: string}
   *   The complete plan; an exception means no writes are safe.
   */
  public function analyze(array $old, array $term, array $terms): array {
    if (!$term['status']) {
      throw new \RuntimeException('The term model must be enabled.');
    }
    $termPaths = $this->paths($term);
    if (count($termPaths) !== 1) {
      throw new \RuntimeException('The term model must have exactly one sending path.');
    }
    $this->validatePath($termPaths[0], TRUE);
    $rows = $keys = [];
    foreach ($this->paths($old) as $path) {
      // Exclude only provably dead positive comparisons, never negations.
      $dead = NULL;
      foreach ($path['conditions'] as $index => $condition) {
        if ($this->conditionKind($condition) === 'status') {
          $id = (int) $condition['configuration']['expected_value'];
          if (!isset($terms[$id])) {
            if (array_filter($path['action_gates'], static fn(int $gates): bool => $gates <= $index)) {
              throw new \RuntimeException('A dead status branch has an earlier reachable action.');
            }
            $dead = $id;
          }
        }
      }
      if ($dead !== NULL) {
        $rows[] = [
          'tid' => $dead,
          'name' => '(missing)',
          'jurisdiction' => '',
          'current' => '',
          'planned' => '',
          'verdict' => 'dead branch excluded',
        ];
        continue;
      }
      [$id, $key] = $this->validatePath($path, FALSE);
      if (isset($terms[$id]['compatible']) && !$terms[$id]['compatible']) {
        throw new \RuntimeException("Status term $id is not a compatible service status.");
      }
      if (isset($keys[$id])) {
        throw new \RuntimeException("Two branches map status term $id; migration is ambiguous.");
      }
      if (($terms[$id]['key'] ?? '') !== '' && $terms[$id]['key'] !== $key) {
        throw new \RuntimeException("Status term $id already carries another key.");
      }
      if ($old['status'] && ($terms[$id]['key'] ?? '') !== '') {
        throw new \RuntimeException("Status term $id already sends through both models; cannot prove mail-count parity.");
      }
      $keys[$id] = $key;
      $rows[] = [
        'tid' => $id,
        'name' => $terms[$id]['name'],
        'jurisdiction' => $terms[$id]['jurisdiction'],
        'current' => $terms[$id]['key'],
        'planned' => $key,
        'verdict' => 'equivalent',
      ];
    }
    $result = 'nothing to migrate';
    if ($keys) {
      $missing = array_filter($keys, static fn(string $key, int $id): bool => ($terms[$id]['key'] ?? '') !== $key, ARRAY_FILTER_USE_BOTH);
      if (!$old['status'] && $missing) {
        throw new \RuntimeException('Old model is off but keys are missing: incomplete, not migrated.');
      }
      $result = $old['status'] ? 'ready to migrate' : 'already migrated';
    }
    return ['rows' => $rows, 'keys' => $keys, 'result' => $result];
  }

  /**
   * Enumerates complete event paths, detecting cycles and broken edges.
   */
  private function paths(array $model): array {
    $events = $model['events'] ?? [];
    if (count($events) !== 1 || !empty($model['template'])) {
      throw new \RuntimeException('Expected one non-template update event.');
    }
    $event = reset($events);
    if (!empty($event['applied_templates'])) {
      throw new \RuntimeException('Applied event templates are not understood.');
    }
    if (($event['plugin'] ?? '') !== 'content_entity:update' || ($event['configuration'] ?? []) !== ['type' => 'node service_request']) {
      throw new \RuntimeException('Expected the service request update event.');
    }
    $paths = [];
    $walk = function (array $node, array $path, array $visited) use (&$walk, &$paths, $model): void {
      if (count($paths) > 1000 || count($visited) > 100) {
        throw new \RuntimeException('Graph exceeds the safe analysis limit.');
      }
      if (empty($node['successors'])) {
        $paths[] = $path;
        return;
      }
      foreach ($node['successors'] as $edge) {
        $next = $path;
        $id = $edge['id'] ?? '';
        if (isset($visited[$id])) {
          throw new \RuntimeException('Cycle in status mail graph.');
        }
        $conditionId = $edge['condition'] ?? '';
        if ($conditionId !== '') {
          if (!isset($model['conditions'][$conditionId])) {
            throw new \RuntimeException('Missing graph condition.');
          }
          $next['conditions'][] = $model['conditions'][$conditionId];
        }
        if (isset($model['actions'][$id])) {
          $child = $model['actions'][$id];
          $next['actions'][] = $child;
          $next['action_gates'][] = count($next['conditions']);
        }
        elseif (isset($model['gateways'][$id])) {
          $child = $model['gateways'][$id];
          $next['gateways'][] = $child['type'] ?? NULL;
        }
        else {
          throw new \RuntimeException('Missing graph target.');
        }
        $walk($child, $next, $visited + [$id => TRUE]);
      }
    };
    $walk($event, ['conditions' => [], 'actions' => [], 'gateways' => [], 'action_gates' => []], []);
    return $paths;
  }

  /**
   * Returns known positive predicate semantics, otherwise unknown.
   */
  private function conditionKind(array $condition): string {
    $config = $condition['configuration'] ?? [];
    if (!array_key_exists('negate', $config) || $config['negate'] !== FALSE || ($config['entity'] ?? NULL) !== '') {
      return 'unknown';
    }
    $field = $config['field_name'] ?? '';
    if (($condition['plugin'] ?? '') === 'eca_entity_field_value_changed' && $field === 'field_status.target_id' && !array_diff(array_keys($config), [
      'field_name', 'negate', 'entity',
    ])) {
      return 'changed';
    }
    if (($condition['plugin'] ?? '') !== 'eca_entity_field_value' || ($config['operator'] ?? '') !== 'equal' || ($config['type'] ?? '') !== 'value' || ($config['case'] ?? NULL) !== TRUE || array_diff(array_keys($config), [
      'field_name', 'expected_value', 'operator', 'type', 'case', 'negate', 'entity',
    ])) {
      return 'unknown';
    }
    $value = (string) ($config['expected_value'] ?? '');
    if ($field === 'field_notification.value' && $value === '1') {
      return 'opt-in';
    }
    if ($field === 'field_status.target_id' && ctype_digit($value) && (int) $value > 0) {
      return 'status';
    }
    return 'unknown';
  }

  /**
   * Checks every gate and action, returning the old path's term and key.
   */
  private function validatePath(array $path, bool $term): array {
    $kinds = array_map($this->conditionKind(...), $path['conditions']);
    sort($kinds);
    $expected = $term ? ['changed', 'opt-in'] : ['changed', 'opt-in', 'status'];
    if ($kinds !== $expected || array_filter($path['gateways'], static fn($type): bool => $type !== 0) || count($path['actions']) !== 1) {
      throw new \RuntimeException('Unknown or ambiguous path: expected status changed, opt-in and one mail action (plus one positive status comparison in the old model).');
    }
    if ($path['action_gates'] !== [count($path['conditions'])]) {
      throw new \RuntimeException('A mail action runs before its required conditions.');
    }
    $action = $path['actions'][0];
    $plugin = $term ? 'markaspot_mail_send_term_notification' : 'markaspot_mail_send_notification';
    if (($action['plugin'] ?? '') !== $plugin) {
      throw new \RuntimeException('Reachable raw or unknown action; requires a separate text migration.');
    }
    $config = $action['configuration'] ?? [];
    $allowed = $term ? ['object', 'recipient'] : ['object', 'recipient', 'notification_key'];
    if (($config['object'] ?? '') !== 'entity' || ($config['recipient'] ?? '') !== self::RECIPIENT || array_diff(array_keys($config), $allowed)) {
      throw new \RuntimeException('Mail action has a wrong recipient, missing entity binding or unknown configuration.');
    }
    $key = $config['notification_key'] ?? '';
    if (!$term && !in_array($key, self::KEYS, TRUE)) {
      throw new \RuntimeException('Unknown status notification key.');
    }
    $id = 0;
    foreach ($path['conditions'] as $condition) {
      if ($this->conditionKind($condition) === 'status') {
        $id = (int) $condition['configuration']['expected_value'];
      }
    }
    return [$id, $key];
  }

}
