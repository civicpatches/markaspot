<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Validation\WorkspaceBoundary;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * No unresolved or rejected reporting area becomes an invented radius.
 */
#[Group('markaspot_fastmap')]
final class WorkspaceBoundaryTest extends UnitTestCase {

  /**
   * Invalid inputs fail with a recoverable validation exception.
   */
  #[DataProvider('invalidBoundaries')]
  public function testRejectsUnresolvedArea(mixed $boundary): void {
    $this->expectException(\InvalidArgumentException::class);
    WorkspaceBoundary::validate($boundary);
  }

  /**
   * Geocoding failures, point results and malformed/oversized geometries.
   */
  public static function invalidBoundaries(): array {
    return [
      [NULL], [[]], [['type' => 'Point', 'coordinates' => [12, 50]]],
      [['type' => 'Polygon', 'coordinates' => []]],
      [['type' => 'Polygon', 'coordinates' => [[[181, 50], [12, 50], [12, 51], [181, 50]]]]],
      [['type' => 'Polygon', 'coordinates' => [array_fill(0, 60000, [12.12345678, 50.12345678])]]],
    ];
  }

  /**
   * Large city outlines above the old UI cap retain their exact shape.
   */
  public function testPreservesLargeMunicipalMultipolygonAndHoles(): void {
    $ring = [];
    for ($i = 0; $i < 6000; $i++) {
      $angle = 2 * M_PI * $i / 6000;
      $ring[] = [12.0 + cos($angle), 50.0 + sin($angle)];
    }
    $ring[] = $ring[0];
    $boundary = [
      'type' => 'MultiPolygon',
      'coordinates' => [[$ring, [[12.0, 50.0], [12.1, 50.0], [12.1, 50.1], [12.0, 50.0]]]],
    ];
    $this->assertGreaterThan(100000, strlen(json_encode($boundary)));
    $this->assertSame($boundary, WorkspaceBoundary::validate($boundary));
  }

}
