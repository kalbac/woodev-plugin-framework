<?php
/**
 * Unit tests for issue #251's F4 rig-finding fix: `Woodev_Test_Embedded_Confirm_Point_Source`
 * (`tests/_fixtures/woodev-test-shipping-method/class-test-embedded-confirm-point-source.php`).
 *
 * On the rig, under `WOODEV_TEST_PICKUP_EMBEDDED`, a customer's selection inside the Почта
 * widget carries a REAL carrier point id (e.g. `"43213"`) that no OTHER fixture `Point_Source`
 * has ever heard of — the confirmation round trip (`Pickup_Controller::handle_select_request()`,
 * provider-agnostic, always calls `Point_Source::fetch_details( $point_id )`) 404'd on every
 * real selection as a result. These tests pin the fix directly against the stub class: it must
 * accept ANY non-empty id and fabricate an always-permissive `Pickup_Point` for it, never `null`
 * for a real-looking id, and `fetch_points()` must stay a harmless empty list (never actually
 * reached on the rig — the carrier's own embed renders its own list).
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-embedded-confirm-point-source.php';

/**
 * @covers \Woodev_Test_Embedded_Confirm_Point_Source
 */
final class TestEmbeddedConfirmPointSourceTest extends TestCase {

	public function test_declares_the_viewport_strategy(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$this->assertSame( Point_Source::STRATEGY_VIEWPORT, $source->get_strategy() );
	}

	public function test_fetch_points_always_returns_an_empty_list(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$query = Point_Query::from_request( [ 'locality' => 'Москва' ] );

		$this->assertSame( [], $source->fetch_points( $query ) );
	}

	/**
	 * The core of the fix: a REAL Почта carrier id, measured on the rig
	 * (docs-internal/specs/2026-08-10-embedded-map-provider-adapter-seam.md §1 M7), must
	 * resolve to a point — never `null` — so `Pickup_Controller::handle_select_request()`
	 * never answers 404 for it.
	 */
	public function test_fetch_details_resolves_a_real_measured_pochta_id(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$point = $source->fetch_details( '43213' );

		$this->assertNotNull( $point );
		$this->assertSame( '43213', $point->get_id() );
	}

	/**
	 * @dataProvider provide_arbitrary_ids
	 */
	public function test_fetch_details_resolves_any_non_empty_id( string $id ): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$this->assertNotNull( $source->fetch_details( $id ), "fetch_details() must not return null for id '{$id}'" );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function provide_arbitrary_ids(): array {
		return [
			'numeric carrier id'    => [ '43213' ],
			'alphanumeric id'       => [ 'ABC-123' ],
			'a single character'    => [ '0' ],
			'an id with whitespace' => [ '  918872  ' ],
		];
	}

	public function test_fetch_details_returns_null_for_an_empty_or_whitespace_only_id(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$this->assertNull( $source->fetch_details( '' ) );
		$this->assertNull( $source->fetch_details( '   ' ) );
	}

	public function test_fetch_details_returns_the_id_verbatim_as_the_points_own_id(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$point = $source->fetch_details( 'FIXTURE-ID-42' );

		$this->assertSame( 'FIXTURE-ID-42', $point->get_id() );
	}

	/**
	 * `accepts_cod: true` / `max_weight: null` — chosen so `Constraint_Checker::check()`
	 * always answers `allowed: true` regardless of the cart's payment method or weight,
	 * since this stub has no domain basis for gating either. Asserted via `to_array()`
	 * (both fields have no public getter on `Pickup_Point` except `get_accepts_cod()`/
	 * `get_max_weight()`, used here for the same reason `Woodev_Test_Bulk_Point_Source`'s
	 * own sibling tests do).
	 */
	public function test_fetch_details_is_always_permissive_accepts_cod_and_unlimited_weight(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$point = $source->fetch_details( '43213' );

		$this->assertTrue( $point->get_accepts_cod() );
		$this->assertNull( $point->get_max_weight() );
	}

	public function test_fetch_details_returns_a_valid_in_range_coordinate(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$point = $source->fetch_details( '43213' );

		$this->assertGreaterThanOrEqual( -90.0, $point->get_lat() );
		$this->assertLessThanOrEqual( 90.0, $point->get_lat() );
		$this->assertGreaterThanOrEqual( -180.0, $point->get_lng() );
		$this->assertLessThanOrEqual( 180.0, $point->get_lng() );
	}

	public function test_fetch_details_returns_a_non_empty_name_and_address(): void {
		$source = new \Woodev_Test_Embedded_Confirm_Point_Source();

		$point = $source->fetch_details( '43213' )->to_array();

		$this->assertNotSame( '', $point['name'] );
		$this->assertNotSame( '', $point['address'] );
		$this->assertSame( 'PVZ', $point['type']['code'] );
	}
}
