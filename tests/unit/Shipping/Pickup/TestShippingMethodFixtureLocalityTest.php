<?php
/**
 * Unit tests for issue #162: the rig's `woodev-test-shipping-method` fixture must
 * actually filter `Woodev_Test_Bulk_Point_Source::fetch_points()` by the requested
 * `locality`, so the checkout's `emptyLocality` state (spec V-5) is reachable on the
 * rig, not just in tests — previously it returned every fixture point regardless of
 * what locality was requested.
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
}
