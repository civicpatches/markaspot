<?php

/**
 * @file
 * Content-only post updates for Vision image lifecycle changes.
 */

/**
 * Unpublish request images without a current report and retire orphan marks.
 */
function markaspot_vision_post_update_unpublish_orphan_request_images(array &$sandbox): void {
  $storage = \Drupal::entityTypeManager()->getStorage('media');
  if (!isset($sandbox['last_mid'])) {
    $last = $storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'request_image')
      ->sort('mid', 'DESC')->range(0, 1)->execute();
    $sandbox['max_mid'] = $last ? (int) reset($last) : 0;
    $sandbox['last_mid'] = 0;
    $sandbox['processed'] = 0;
    $sandbox['total'] = (int) $storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'request_image')
      ->condition('mid', $sandbox['max_mid'], '<=')->count()->execute();
  }
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'request_image')
    ->condition('mid', $sandbox['last_mid'], '>')->condition('mid', $sandbox['max_mid'], '<=')
    ->sort('mid')->range(0, 50)->execute();
  foreach ($ids as $id) {
    \Drupal::service('markaspot_vision.orphan_upload_purger')->unpublishIfUnreferenced((int) $id);
    $sandbox['last_mid'] = (int) $id;
    $sandbox['processed']++;
  }
  $sandbox['#finished'] = count($ids) < 50 || $sandbox['last_mid'] >= $sandbox['max_mid']
    ? 1 : min(0.99, $sandbox['processed'] / max(1, $sandbox['total']));
}
