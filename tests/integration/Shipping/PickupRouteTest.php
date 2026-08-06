<?php
/**
 * Integration: pickup-points REST routes (woodev/v1/shipping/pickup/{plugin}).
 *
 * Authored here per the SP-5 plan's warning header: Task 7 sketched these cases but
 * could not implement them without a fixture Point_Source, so it deferred the file to
 * this task. Exercises the REAL wiring the fixture plugin now does in its constructor
 * (`Woodev_Test_Shipping_Method_Plugin::__construct()` → `Pickup_Handler::register()` →
 * `Pickup_Controller::register_routes()` on `rest_api_init`) for route registration,
 * guest access and the two "unusable query still 200s" cases, then drops to
 * constructing `Pickup_Controller` directly — same pattern as
 * `PickupControllerTest` (unit) — for the cases that need control over the
 * payment-method/cart-weight callables or a specific Point_Source strategy that the
 * ambient fixture plugin (default `WOODEV_TEST_PICKUP_STRATEGY = 'bulk'`, see the
 * fixture file) is not currently running.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class PickupRouteTest extends TestCase {

	/**
	 * Bounding box covering FIX-VIEW-1 (55.7601, 37.6367) and FIX-VIEW-2
	 * (55.7887, 37.6789) from Woodev_Test_Viewport_Point_Source, but NOT
	 * FIX-VIEW-3 (55.5450, 37.5270).
	 *
	 * @var string
	 */
	private const VIEWPORT_BBOX = '55.70,37.60,55.85,37.70';

	/**
	 * Set up — resolve the fixture plugin and assert it's loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — the shipping fixture plugin must be active in wp-env.'
		);
	}

	/**
	 * Removes any $_POST leftovers from a payment-method test so it cannot leak
	 * into a later test in the same process.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST['payment_method'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. The route is registered by the fixture plugin's real wiring
	// -------------------------------------------------------------------------

	/**
	 * The points route must be registered under the fixture plugin's own id —
	 * proof that `Woodev_Test_Shipping_Method_Plugin`'s constructor really wired
	 * `Pickup_Handler::register()`, not just that `Pickup_Controller` works in
	 * isolation (already covered by the unit test).
	 *
	 * @return void
	 */
	public function test_points_route_is_registered_for_the_fixture_plugin(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey(
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points',
			$routes
		);
	}

	// -------------------------------------------------------------------------
	// 2. Guest access
	// -------------------------------------------------------------------------

	/**
	 * A GUEST (unauthenticated) request must be able to read points — checkout
	 * guests need this, so the route's `permission_callback` is intentionally
	 * `__return_true` (spec §5).
	 *
	 * @return void
	 */
	public function test_a_guest_can_read_points(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points'
		);
		$request->set_param( 'locality', 'Москва' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty(
			$response->get_data()['points'],
			'The bulk fixture source must return points for a locality query.'
		);
	}

	// -------------------------------------------------------------------------
	// 3. Unusable query still 200s
	// -------------------------------------------------------------------------

	/**
	 * A whole-planet bbox must yield an empty point list with HTTP 200 — NOT an
	 * error. The controller deliberately treats an unusable query as "nothing to
	 * show yet", not a failure, so a client that has not resolved a locality does
	 * not see an error state (spec §5, Point_Query::from_request()).
	 *
	 * @return void
	 */
	public function test_an_oversized_bbox_yields_no_points_but_status_200(): void {
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points'
		);
		$request->set_param( 'bbox', '-90,-180,90,180' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['points'] );
	}

	/**
	 * REGRESSION GUARD (spec §10.2): the bbox cap is PER-SIDE (10° each), not
	 * per-area. A box that is thin in latitude but spans the full longitude range
	 * — a strip around the entire circumference of the planet — has an area
	 * (0.27° × 360° ≈ 97 sq-deg) UNDER the old 100 sq-deg area cap, so an
	 * area-only cap would wrongly ACCEPT it; the actual abuse this cap exists to
	 * prevent. Registers its OWN route on a viewport-strategy source (the ambient
	 * fixture defaults to bulk, which would reject any bbox purely on strategy
	 * mismatch and prove nothing about the cap itself) so the per-side check is
	 * what's actually exercised, not a false pass by a different mechanism.
	 *
	 * If this test ever starts failing after someone "simplifies" the cap back to
	 * an area check, that is this test doing its job — do not weaken it.
	 *
	 * @return void
	 */
	public function test_bbox_cap_is_per_side_not_per_area_refuses_a_planet_spanning_strip(): void {
		$controller = new Pickup_Controller(
			'pickup-cap-guard',
			new \Woodev_Test_Viewport_Point_Source(),
			static fn(): int => 0,
			static fn(): string => 'bacs',
			static fn(): string => 'carrier_pickup'
		);

		add_action(
			'rest_api_init',
			static function () use ( $controller ) {
				$controller->register_routes();
			}
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// 0.27° of latitude by the full 360° of longitude — a strip around the
		// planet, not a viewport.
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/pickup-cap-guard/points'
		);
		$request->set_param( 'bbox', '55.70,-180,55.97,180' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[],
			$response->get_data()['points'],
			'A planet-spanning strip must be refused by the PER-SIDE cap.'
		);
	}

	// -------------------------------------------------------------------------
	// 4. Point-details route
	// -------------------------------------------------------------------------

	/**
	 * The detail route must return the known point and 404 for an unknown one.
	 *
	 * @return void
	 */
	public function test_point_details_route_returns_a_known_point_and_404s_for_an_unknown_one(): void {
		$known = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points/FIX-BULK-1'
		);
		$known_response = rest_get_server()->dispatch( $known );

		$this->assertSame( 200, $known_response->get_status() );
		$this->assertSame( 'FIX-BULK-1', $known_response->get_data()['id'] );

		$unknown = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points/NOPE-DOES-NOT-EXIST'
		);
		$unknown_response = rest_get_server()->dispatch( $unknown );

		$this->assertSame( 404, $unknown_response->get_status() );
	}

	// -------------------------------------------------------------------------
	// 5. Every point carries a selectable verdict
	// -------------------------------------------------------------------------

	/**
	 * Every point in a points-list response must carry `selectable.allowed` (bool)
	 * and `selectable.reason` (present, string|null) — the client renders this
	 * verdict, it never recomputes it (spec §4.5).
	 *
	 * @return void
	 */
	public function test_each_returned_point_carries_a_selectable_verdict(): void {
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points'
		);
		$request->set_param( 'locality', 'Москва' );

		$points = rest_get_server()->dispatch( $request )->get_data()['points'];

		$this->assertNotEmpty( $points );

		foreach ( $points as $point ) {
			$this->assertArrayHasKey( 'selectable', $point );
			$this->assertIsBool( $point['selectable']['allowed'] );
			$this->assertArrayHasKey( 'reason', $point['selectable'] );
		}
	}

	// -------------------------------------------------------------------------
	// 6. COD gating (bulk source, direct construction — controls payment method)
	// -------------------------------------------------------------------------

	/**
	 * `Woodev_Test_Bulk_Point_Source::COD_REFUSING_POINT_ID` must be reported
	 * unselectable when the chosen payment method is COD, and selectable for any
	 * other method — proving the payment-method callable actually gates the
	 * verdict, not just that the point has `accepts_cod: false` set.
	 *
	 * @return void
	 */
	public function test_cod_refusing_point_is_unselectable_only_for_cod_payment(): void {
		$source = new \Woodev_Test_Bulk_Point_Source();
		$cod_id = \Woodev_Test_Bulk_Point_Source::COD_REFUSING_POINT_ID;

		$weight_free = static fn(): int => 0;
		$cod         = static fn(): string => 'cod';
		$bacs        = static fn(): string => 'bacs';
		$method      = static fn(): string => 'carrier_pickup';

		$cod_controller  = new Pickup_Controller( 'pickup-cod-guard', $source, $weight_free, $cod, $method );
		$bacs_controller = new Pickup_Controller( 'pickup-cod-guard', $source, $weight_free, $bacs, $method );

		$blocked = $cod_controller->get_point_data( $cod_id );
		$allowed = $bacs_controller->get_point_data( $cod_id );

		$this->assertFalse( $blocked['selectable']['allowed'], 'COD payment must block the COD-refusing point.' );
		$this->assertNotNull( $blocked['selectable']['reason'] );
		$this->assertTrue(
			$allowed['selectable']['allowed'],
			'A non-COD method must not be blocked by accepts_cod=false.'
		);
	}

	// -------------------------------------------------------------------------
	// 7. Weight gating (bulk source, direct construction — controls cart weight)
	// -------------------------------------------------------------------------

	/**
	 * `Woodev_Test_Bulk_Point_Source::WEIGHT_LIMITED_POINT_ID` (max_weight 1000 g)
	 * must be reported unselectable when the cart exceeds the limit, and
	 * selectable when it does not.
	 *
	 * @return void
	 */
	public function test_weight_limited_point_is_unselectable_over_its_limit(): void {
		$source    = new \Woodev_Test_Bulk_Point_Source();
		$weight_id = \Woodev_Test_Bulk_Point_Source::WEIGHT_LIMITED_POINT_ID;
		$bacs      = static fn(): string => 'bacs';
		$method    = static fn(): string => 'carrier_pickup';

		$heavy_controller = new Pickup_Controller(
			'pickup-weight-guard',
			$source,
			static fn(): int => 1500,
			$bacs,
			$method
		);
		$light_controller = new Pickup_Controller(
			'pickup-weight-guard',
			$source,
			static fn(): int => 500,
			$bacs,
			$method
		);

		$blocked = $heavy_controller->get_point_data( $weight_id );
		$allowed = $light_controller->get_point_data( $weight_id );

		$this->assertFalse( $blocked['selectable']['allowed'], 'A cart over the 1000g limit must be blocked.' );
		$this->assertNotNull( $blocked['selectable']['reason'] );
		$this->assertTrue( $allowed['selectable']['allowed'], 'A cart under the 1000g limit must be selectable.' );
	}

	// -------------------------------------------------------------------------
	// 8. Viewport strategy — sparse list, full details, verdict recomputation
	// -------------------------------------------------------------------------

	/**
	 * `Woodev_Test_Viewport_Point_Source::fetch_points()` must return only points
	 * inside the requested bbox, and every returned point must be SPARSE — no
	 * `accepts_cod`, no `max_weight` — mirroring a carrier whose bbox listing
	 * omits both (spec §4.5).
	 *
	 * @return void
	 */
	public function test_viewport_fetch_points_returns_only_points_in_bounds_and_sparse(): void {
		$source = new \Woodev_Test_Viewport_Point_Source();
		$query  = Point_Query::from_request( [ 'bbox' => self::VIEWPORT_BBOX ] );

		$this->assertNotNull( $query );

		$points = $source->fetch_points( $query );
		$ids    = array_map( static fn( $p ) => $p->get_id(), $points );

		sort( $ids );
		$this->assertSame( [ 'FIX-VIEW-1', 'FIX-VIEW-2' ], $ids, 'Only the in-bounds points must be returned.' );

		foreach ( $points as $point ) {
			$this->assertNull( $point->get_accepts_cod(), 'A sparse viewport point must have unknown accepts_cod.' );
			$this->assertNull( $point->get_max_weight(), 'A sparse viewport point must have unknown max_weight.' );
		}
	}

	/**
	 * The verdict must be RECOMPUTED between the sparse list response and the
	 * detail response: `Woodev_Test_Viewport_Point_Source::COD_REFUSING_POINT_ID`
	 * is emitted as selectable in the sparse list (unknown accepts_cod is
	 * permissive) even under COD, but the detail call — which returns the full
	 * record with `accepts_cod: false` — must recompute it as blocked. This is
	 * the exact contract spec §4.5 calls out under "Timing under the viewport
	 * strategy": the verdict is authoritative only after `fetchDetails`.
	 *
	 * @return void
	 */
	public function test_viewport_verdict_is_recomputed_on_fetch_details(): void {
		$source = new \Woodev_Test_Viewport_Point_Source();
		$cod_id = \Woodev_Test_Viewport_Point_Source::COD_REFUSING_POINT_ID;

		$controller = new Pickup_Controller(
			'pickup-viewport-guard',
			$source,
			static fn(): int => 0,
			static fn(): string => 'cod',
			static fn(): string => 'carrier_pickup'
		);

		$list_data = $controller->get_points_data( [ 'bbox' => self::VIEWPORT_BBOX ] );
		$list_point = null;

		foreach ( $list_data['points'] as $point ) {
			if ( $cod_id === $point['id'] ) {
				$list_point = $point;
			}
		}

		$this->assertNotNull( $list_point, 'The COD-refusing point must appear in the sparse list.' );
		$this->assertTrue(
			$list_point['selectable']['allowed'],
			'Sparse list entry has no accepts_cod yet — unknown is permissive, even under COD.'
		);

		$detail = $controller->get_point_data( $cod_id );

		$this->assertFalse(
			$detail['selectable']['allowed'],
			'Once the full record is known (accepts_cod=false) the verdict must recompute to blocked under COD.'
		);
		$this->assertNotNull( $detail['selectable']['reason'] );
	}

	/**
	 * The viewport source's detail lookup must return null for an id it does not
	 * know — distinct from the sparse-vs-full distinction above.
	 *
	 * @return void
	 */
	public function test_viewport_fetch_details_returns_null_for_an_unknown_point(): void {
		$source = new \Woodev_Test_Viewport_Point_Source();

		$this->assertNull( $source->fetch_details( 'NOPE-DOES-NOT-EXIST' ) );
	}
}
