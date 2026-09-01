<?php
/**
 * Unit tests for issue #162: the rig's `woodev-test-shipping-method` fixture must
 * actually filter `Woodev_Test_Bulk_Point_Source::fetch_points()` by the requested
 * `locality`, so the checkout's `emptyLocality` state (spec V-5) is reachable on the
 * rig, not just in tests — previously it returned every fixture point regardless of
 * what locality was requested.
 *
 * Issue #270 extends the fixture to more than one locality (Москва, Санкт-Петербург,
 * Краснодар) and moves the filter from an all-or-nothing gate (any matching locality
 * returned every point) to a genuine per-point filter against each point's own
 * `locality` field — the tests below pin BOTH the #162 "unknown locality → empty"
 * guarantee AND the #270 "each locality returns only its own points" guarantee, so
 * neither can regress into "everything matches everything".
 *
 * `Woodev_Test_Bulk_Point_Source` lives in its own file,
 * `tests/_fixtures/woodev-test-shipping-method/class-test-bulk-point-source.php` (split
 * out of `woodev-test-shipping-method.php` for exactly this reason — see that file's
 * docblock), so it can be `require_once`d directly here, after the interfaces/value
 * objects it depends on, the same pattern {@see \Woodev\Tests\Unit\Shipping\Rest_Api\PickupControllerTest}
 * and {@see \Woodev\Tests\Unit\Shipping\Pickup\PickupHandlerTest} already use for their
 * own `Point_Source` test doubles — WITHOUT going through the fixture plugin's full
 * Platform v2 load path. That path runs through `Woodev_Plugin_Bootstrap`'s singleton
 * resolver, which is process-wide and load-once (`Framework_Resolver::$loaded`); once
 * any other test in the same PHPUnit run has loaded it (e.g. `BootstrapTest`), a second
 * registration+load from this fixture would silently no-op.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-bulk-point-source.php';

/**
 * @covers \Woodev_Test_Bulk_Point_Source
 */
final class TestShippingMethodFixtureLocalityTest extends TestCase {

	/**
	 * A locality matching the fixture's own city ("Москва") must return points —
	 * the fixture's baseline behaviour, unchanged by this fix.
	 */
	public function test_matching_locality_returns_points(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Москва' ] );

		$this->assertNotEmpty( $source->fetch_points( $query ) );
	}

	/**
	 * A locality that is NOT the fixture's own city ("Новосибирск") must return zero
	 * points — this is what makes the checkout's `emptyLocality` state (spec V-5)
	 * reachable on the rig (issue #162), where it was previously unreachable: every
	 * locality returned all 49 fixture points.
	 */
	public function test_foreign_locality_returns_no_points(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Новосибирск' ] );

		$this->assertSame( [], $source->fetch_points( $query ) );
	}

	/**
	 * Matching is forgiving of case and surrounding whitespace — a browser city input
	 * or a REST client is not guaranteed to send back the fixture's own exact casing —
	 * but that forgiveness must not itself cause a false negative on the fixture's own
	 * city.
	 */
	public function test_locality_matching_ignores_case_and_surrounding_whitespace(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => '  МОСКВА  ' ] );

		$this->assertNotEmpty( $source->fetch_points( $query ) );
	}

	/**
	 * A locality that merely CONTAINS the fixture's city as a substring must still be
	 * rejected — matching is exact (after case-folding/trimming), never partial or
	 * fuzzy, per the fix's own explicit decision.
	 */
	public function test_locality_matching_rejects_partial_match(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Москва область' ] );

		$this->assertSame( [], $source->fetch_points( $query ) );
	}

	/**
	 * A request for Санкт-Петербург must return only points whose own `locality` is
	 * "Санкт-Петербург" — none of them may be a Moscow point (issue #270).
	 */
	public function test_spb_locality_returns_only_spb_points(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query  = Point_Query::from_request( [ 'locality' => 'Санкт-Петербург' ] );
		$points = $source->fetch_points( $query );

		$this->assertNotEmpty( $points, 'St Petersburg must have at least one fixture point.' );

		foreach ( $points as $point ) {
			$this->assertSame( 'Санкт-Петербург', $point->get_locality(), 'Every returned point must carry the SPb locality.' );
		}
	}

	/**
	 * A request for Москва must NOT include any of the Санкт-Петербург (or
	 * Краснодар) points — the localities' point sets must not bleed into each other
	 * in either direction (issue #270; this is the actual defect #176's
	 * pickup-point persistence work needs ruled out — two points in two localities
	 * coexisting without one overwriting or leaking into the other).
	 */
	public function test_moscow_locality_excludes_other_localities_points(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query      = Point_Query::from_request( [ 'locality' => 'Москва' ] );
		$localities = array_map(
			static fn( $point ): string => $point->get_locality(),
			$source->fetch_points( $query )
		);

		$this->assertNotEmpty( $localities );
		$this->assertSame( [ 'Москва' ], array_unique( $localities ) );
	}

	/**
	 * Краснодар is the fixture's deliberately single-point locality — card #150
	 * ("a lone point on the map breaks tile zoom") needs exactly this shape to be
	 * reproducible on the rig at all (issue #270).
	 */
	public function test_krasnodar_locality_returns_exactly_one_point(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Краснодар' ] );

		$this->assertCount( 1, $source->fetch_points( $query ) );
	}

	/**
	 * The English alias for Санкт-Петербург must resolve the same way "Moscow" does
	 * for Москва (issue #270; alias spelling itself is unverified against a live
	 * DaData response — see the fixture class' own docblock).
	 */
	public function test_spb_english_alias_resolves(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Saint Petersburg' ] );

		$this->assertNotEmpty( $source->fetch_points( $query ) );
	}
}
