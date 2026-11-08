<?php

declare(strict_types=1);

namespace Drupal\markaspot_archive_search_test\Plugin\search_api\tracker;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiTracker;
use Drupal\search_api\Plugin\search_api\tracker\Basic;

/**
 * The default tracker, whose updates fail while a state flag is set.
 *
 * The failure is raised inside the contrib tracker's own write, so
 * TrackerPluginBase::trackItemsUpdated() catches and only logs it, as it
 * does with a real database error: the caller is not told.
 */
#[SearchApiTracker(
  id: 'markaspot_archive_silently_failing',
  label: new TranslatableMarkup('Silently failing'),
  description: new TranslatableMarkup('Test tracker whose updates fail silently.'),
)]
class SilentlyFailingTracker extends Basic {

  /**
   * State flag that makes tracker updates fail.
   */
  public const FAIL_STATE = 'markaspot_archive_search_test.fail_updates';

  /**
   * {@inheritdoc}
   */
  protected function createUpdateStatement() {
    if (\Drupal::state()->get(self::FAIL_STATE)) {
      throw new \RuntimeException('Synthetic tracker write failure');
    }
    return parent::createUpdateStatement();
  }

}
