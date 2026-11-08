<?php

/**
 * @file
 * Post update functions for the markaspot_moderation module.
 */

declare(strict_types=1);

/**
 * Converts legacy prose moderation revision logs into revision events.
 *
 * Before profile 11.9.227 a moderation hide wrote only a translated sentence
 * into the revision log. The publication hold decodes civic.revision.v1
 * events and ignores prose, so those reports could be published again by the
 * publisher. This wraps each exact legacy message in an event envelope and
 * keeps the original sentence as the message. Other revision logs are left
 * untouched; running it again converts nothing.
 */
function markaspot_moderation_post_update_legacy_hide_events(array &$sandbox): string {
  $database = \Drupal::database();
  if (!isset($sandbox['last_vid'])) {
    $sandbox['patterns'] = _markaspot_moderation_legacy_hide_patterns();
    $sandbox['last_vid'] = 0;
    $sandbox['converted'] = 0;
  }

  $query = $database->select('node_revision', 'r');
  $query->join('node_field_data', 'n', 'n.nid = r.nid AND n.default_langcode = 1');
  $query->fields('r', ['vid', 'nid', 'revision_log'])
    ->condition('n.type', 'service_request')
    ->condition('r.vid', $sandbox['last_vid'], '>')
    ->orderBy('r.vid')
    ->range(0, 200);
  $matches = $query->orConditionGroup();
  foreach ($sandbox['patterns'] as $pattern) {
    $matches->condition('r.revision_log', $pattern['exact']
      ? $pattern['text']
      : $database->escapeLike($pattern['text']) . '%', $pattern['exact'] ? '=' : 'LIKE');
  }
  $query->condition($matches);
  $rows = $query->execute()->fetchAll();

  $nids = [];
  foreach ($rows as $row) {
    $sandbox['last_vid'] = (int) $row->vid;
    $log = (string) $row->revision_log;
    $event = _markaspot_moderation_legacy_hide_event($log, $sandbox['patterns']);
    if ($event === NULL) {
      continue;
    }
    $database->update('node_revision')
      ->fields(['revision_log' => json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [],
        'events' => [$event],
        'message' => $log,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)])
      ->condition('vid', (int) $row->vid)
      ->execute();
    $sandbox['converted']++;
    $nids[(int) $row->nid] = (int) $row->nid;
  }
  if ($nids) {
    \Drupal::entityTypeManager()->getStorage('node')->resetCache(array_values($nids));
    \Drupal::service('cache_tags.invalidator')->invalidateTags(array_map(
      static fn(int $nid): string => 'node:' . $nid, array_values($nids)));
  }

  $sandbox['#finished'] = count($rows) < 200 ? 1 : 0;
  return (string) t('Converted @count legacy moderation revision logs into hide events.', [
    '@count' => $sandbox['converted'],
  ]);
}

/**
 * Lists the legacy hide messages in English and every stored translation.
 *
 * @return array<int, array{text: string, exact: bool, event: string}>
 *   The manual hide message is matched exactly; the automatic one carries a
 *   count, so only the text before its placeholder is matched as a prefix.
 */
function _markaspot_moderation_legacy_hide_patterns(): array {
  $sources = [
    'Hidden by moderator due to content flags.' => 'moderation_hidden',
    'Automatically hidden: flag threshold (@count) reached.' => 'moderation_auto_hidden',
  ];
  $texts = [];
  foreach ($sources as $source => $event) {
    $texts[] = [$source, $event];
  }
  $database = \Drupal::database();
  if ($database->schema()->tableExists('locales_source') && $database->schema()->tableExists('locales_target')) {
    $query = $database->select('locales_source', 's');
    $query->join('locales_target', 't', 't.lid = s.lid');
    $query->fields('s', ['source'])->fields('t', ['translation'])
      ->condition('s.source', array_keys($sources), 'IN');
    foreach ($query->execute() as $row) {
      $texts[] = [(string) $row->translation, $sources[(string) $row->source]];
    }
  }

  $patterns = [];
  foreach ($texts as [$text, $event]) {
    $placeholder = strpos($text, '@count');
    $prefix = $placeholder === FALSE ? $text : rtrim(substr($text, 0, $placeholder), " (\t");
    // A translation that starts with the placeholder leaves no safe prefix.
    if (trim($prefix) === '') {
      continue;
    }
    $patterns[$event . "\0" . $prefix] = [
      'text' => $prefix,
      'exact' => $placeholder === FALSE,
      'event' => $event,
    ];
  }
  return array_values($patterns);
}

/**
 * Returns the hide event for a legacy message, or NULL for anything else.
 */
function _markaspot_moderation_legacy_hide_event(string $log, array $patterns): ?string {
  foreach ($patterns as $pattern) {
    if ($pattern['exact'] ? $log === $pattern['text'] : str_starts_with($log, $pattern['text'])) {
      return $pattern['event'];
    }
  }
  return NULL;
}
