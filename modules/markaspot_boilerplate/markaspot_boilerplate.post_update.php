<?php

/**
 * @file
 * Post update functions for the markaspot_boilerplate module.
 */

declare(strict_types=1);

use Drupal\markaspot_boilerplate\Access\BoilerplateAccess;

/**
 * Removes duplicate jurisdiction relations of text templates.
 */
function markaspot_boilerplate_post_update_remove_duplicate_template_relationships(): string {
  $result = BoilerplateAccess::removeDuplicateRelationships();
  return sprintf(
    'Duplicate template relations: %d templates repaired, %d relations removed, %d templates left for review.',
    $result['templates'],
    $result['deleted'],
    $result['failed'],
  );
}
