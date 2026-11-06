<?php

/**
 * @file
 * Content-only post updates for Vision image lifecycle changes.
 */

/**
 * Unpublish request images without a current report and retire orphan marks.
 *
 * Runs while the site is in maintenance mode, so it must stay short on
 * tenants with 100,000 images: one SQL pre-filter per window of IDs selects
 * the few unreferenced images, and only those go through the locked entity
 * check. A deadlock or lock wait against a parallel cron save is retried.
 */
function markaspot_vision_post_update_unpublish_orphan_request_images(array &$sandbox): void {
  /** @var \Drupal\markaspot_vision\Service\OrphanUploadPurger $purger */
  $purger = \Drupal::service('markaspot_vision.orphan_upload_purger');
  if (!isset($sandbox['last_mid'])) {
    $storage = \Drupal::entityTypeManager()->getStorage('media');
    $last = $storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'request_image')
      ->sort('mid', 'DESC')->range(0, 1)->execute();
    $sandbox['max_mid'] = $last ? (int) reset($last) : 0;
    $sandbox['last_mid'] = 0;
    $sandbox['processed'] = 0;
    $sandbox['skipped_busy'] = 0;
  }
  $sandbox['window'] = (int) ($sandbox['window'] ?? 5000);
  $upto = min($sandbox['max_mid'], $sandbox['last_mid'] + max(1, $sandbox['window']));
  $candidates = $upto > $sandbox['last_mid'] ? $purger->unreferencedRequestImageIds($sandbox['last_mid'], $upto) : [];
  foreach (array_chunk($candidates, 50) as $chunk) {
    for ($attempt = 1;; $attempt++) {
      try {
        $sandbox['skipped_busy'] += $purger->unpublishBatch($chunk);
        break;
      }
      catch (\Throwable $e) {
        // unpublishBatch() is idempotent: already unpublished images are not
        // saved again and the mark deletions repeat harmlessly.
        if ($attempt >= 3 || !$purger::isRetryableLockError($e)) {
          throw $e;
        }
        usleep(250000 * $attempt);
      }
    }
    $sandbox['processed'] += count($chunk);
  }
  // Busy images are deliberately passed over, including the last candidate.
  // Regular lifecycle/collector runs can revisit them after the update.
  $sandbox['last_mid'] = $upto;
  $sandbox['#finished'] = $sandbox['last_mid'] >= $sandbox['max_mid']
    ? 1 : $sandbox['last_mid'] / max(1, $sandbox['max_mid']);
}
