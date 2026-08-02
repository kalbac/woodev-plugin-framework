<?php
/**
 * Unit tests for the rig's pickup-point fixture data
 * (`tests/_fixtures/woodev-test-shipping-method/fixture-points.php`).
 *
 * This fixture feeds the local rig's `Woodev_Test_Bulk_Point_Source`. Five of the
 * map's presentation surfaces cannot be exercised on a rig that only ever sees 5
 * points of one type at distinct coordinates: the type filter (needs >= 2 types),
 * the co-located-points tab bar (needs identical coordinates), a cluster badge and
 * a scrolling sidebar (both need many points), and a disabled "select" button
 * (needs a point that refuses COD). These tests assert the fixture actually
 * supplies every shape the rework will need, independent of what the map/panel
 * code built on top of it does with them.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev_Test_Bulk_Point_Source
 */
final class TestFixturePointsTest extends TestCase {

	/**
	 * The fixture points array, loaded once per test via `require`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load_points(): array {
		return require dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/fixture-points.php';
	}

	public function test_supplies_at_least_two_distinct_types(): void {
		$codes = array_unique( array_map(
			static fn( array $point ): string => $point['type']['code'],
			$this->load_points()
		) );

		$this->assertGreaterThanOrEqual( 2, count( $codes ), 'The fixture must supply at least two distinct point types.' );
		$this->assertContains( 'PVZ', $codes );
		$this->assertContains( 'POSTAMAT', $codes );
	}

	public function test_supplies_enough_points_to_cluster_and_scroll(): void {
		$this->assertGreaterThanOrEqual(
			40,
			count( $this->load_points() ),
			'The fixture must supply enough points to trigger clustering and a scrolling sidebar.'
		);
	}

	public function test_contains_a_pair_on_identical_coordinates(): void {
		$seen           = [];
		$found_duplicate = false;

		foreach ( $this->load_points() as $point ) {
			$key = $point['lat'] . '|' . $point['lng'];

			if ( isset( $seen[ $key ] ) ) {
				$found_duplicate = true;
				break;
			}

			$seen[ $key ] = true;
		}

		$this->assertTrue( $found_duplicate, 'The fixture must contain at least one pair of points on identical coordinates.' );
	}

	public function test_contains_one_point_that_refuses_cod(): void {
		$refusing = array_filter(
			$this->load_points(),
			static fn( array $point ): bool => false === $point['accepts_cod']
		);

		$this->assertNotEmpty( $refusing, 'The fixture must contain at least one point with accepts_cod === false.' );
	}

	public function test_covers_present_and_absent_optional_sections(): void {
		$points = $this->load_points();

		$with_services    = array_filter( $points, static fn( array $p ): bool => ! empty( $p['services'] ) );
		$without_services = array_filter( $points, static fn( array $p ): bool => empty( $p['services'] ) );
		$without_phone    = array_filter( $points, static fn( array $p ): bool => '' === $p['phone'] );
		$with_max_weight  = array_filter( $points, static fn( array $p ): bool => null !== $p['max_weight'] );

		$this->assertNotEmpty( $with_services, 'At least one point must have a non-empty services list.' );
		$this->assertNotEmpty( $without_services, 'At least one point must have an empty services list.' );
		$this->assertNotEmpty( $without_phone, 'At least one point must have phone === "".' );
		$this->assertNotEmpty( $with_max_weight, 'At least one point must have a non-null max_weight.' );
	}

	public function test_contains_a_long_address_for_ellipsis_testing(): void {
		$long = array_filter(
			$this->load_points(),
			static fn( array $p ): bool => mb_strlen( $p['address'] ) >= 80
		);

		$this->assertNotEmpty( $long, 'The fixture must contain at least one address of 80+ characters.' );
	}
}
