<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Validation;

use Drupal\markaspot_nuxt\Service\BoundaryGeoJsonValidator;

/**
 * Requires an explicit reporting area before accepting a workspace signup.
 */
final class WorkspaceBoundary {

  public const MAX_BYTES = 512 * 1024;

  /**
   * Validates the area without inventing a substitute for missing geocoding.
   */
  public static function validate(mixed $boundary): array {
    if (!is_array($boundary) || !in_array($boundary['type'] ?? NULL, ['Polygon', 'MultiPolygon'], TRUE)) {
      throw new WorkspaceBoundaryException('Choose and confirm a reporting area before creating the workspace. A city centre alone is not an area.');
    }
    $geometry = array_intersect_key($boundary, ['type' => TRUE, 'coordinates' => TRUE]);
    $encoded = json_encode($geometry);
    if ($encoded === FALSE || strlen($encoded) > self::MAX_BYTES) {
      throw new WorkspaceBoundaryException('The reporting area is too large. Simplify it and confirm the area again.');
    }
    $result = BoundaryGeoJsonValidator::validate($geometry);
    if (!$result['valid'] || $result['normalized'] === NULL) {
      throw new WorkspaceBoundaryException($result['error'] ?? 'Choose and confirm a reporting area before creating the workspace.');
    }
    return $result['normalized']['features'][0]['geometry'];
  }

}
