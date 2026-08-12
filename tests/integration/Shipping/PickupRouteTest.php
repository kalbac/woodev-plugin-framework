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

use ReflectionProperty;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
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
	 * The ambient fixture plugin's `Pickup_Handler::$source` as found at the
	 * start of THIS test, captured in {@see self::setUp()} and restored in
	 * {@see self::tearDown()}.
	 *
	 * @var Point_Source
	 */
	private $original_ambient_point_source;

	/**
	 * Set up — resolve the fixture plugin, assert it's loaded, and pin the
	 * ambient `/woodev/v1/shipping/pickup/woodev-test-shipping-method/points`
	 * route onto a known, network-free `Point_Source`.
	 *
	 * The rig's `WOODEV_TEST_PICKUP_LIVE_YANDEX` / `WOODEV_TEST_PICKUP_LIVE_POCHTA` /
	 * `WOODEV_TEST_PICKUP_STRATEGY` wp-config constants (precedence: LIVE_YANDEX >
	 * LIVE_POCHTA > STRATEGY — see woodev-test-shipping-method.php's own
	 * point-source-selection block) pick the fixture's `Point_Source` ONCE, at the
	 * plugin's construction (bootstrap time, long before any test runs) — they are
	 * plain PHP constants and cannot be flipped per test. Every test below that
	 * talks to the ambient route (`test_a_guest_can_read_points()`,
	 * `test_a_foreign_locality_returns_no_points()`,
	 * `test_each_returned_point_carries_a_selectable_verdict()`,
	 * `test_point_details_route_returns_a_known_point_and_404s_for_an_unknown_one()`)
	 * assumes the STOCK bulk-fixture data (locality "Москва", point id
	 * "FIX-BULK-1", …) — an assumption that silently depends on which constants
	 * happen to be defined this run, and breaks (live-API 502s, or a live provider
	 * that has simply never heard of "FIX-BULK-1") the moment the rig is pointed at
	 * a live source. A test must never depend on a live third-party API, so this
	 * establishes its own precondition instead: swap the handler's private
	 * `$source` property (no public setter exists — production code has no reason
	 * to swap it at runtime) to a fresh `Woodev_Test_Bulk_Point_Source`, then
	 * rebuild the REST server so `Pickup_Handler::register_rest()` (hooked on
	 * `rest_api_init`) re-registers `Pickup_Controller` bound to it — the exact
	 * "null $wp_rest_server, then rest_get_server()" idiom
	 * `LocationRouteTest::activate_and_boot_rest()` already uses to force a fresh
	 * `rest_api_init` pass.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — the shipping fixture plugin must be active in wp-env.'
		);

		$this->original_ambient_point_source = $this->ambient_point_source();
		$this->force_ambient_point_source( new \Woodev_Test_Bulk_Point_Source() );
	}

	/**
	 * Removes any $_POST leftovers from a payment-method test so it cannot leak
	 * into a later test in the same process, and restores the ambient fixture
	 * plugin's original `Point_Source` (see {@see self::setUp()}).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST['payment_method'] );

		$this->force_ambient_point_source( $this->original_ambient_point_source );

		parent::tearDown();
	}

	/**
	 * Reads the ambient fixture plugin's current `Pickup_Handler::$source` via
	 * reflection — see {@see self::setUp()} for why no public accessor exists.
	 *
	 * @return Point_Source
	 */
	private function ambient_point_source(): Point_Source {
		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );

		return $property->getValue( $handler );
	}

	/**
	 * Swaps the ambient fixture plugin's `Pickup_Handler::$source` and rebuilds
	 * the REST server so the swap takes effect — see {@see self::setUp()} for the
	 * full reasoning.
	 *
	 * @param Point_Source $source the source the ambient route should serve.
	 *
	 * @return void
	 */
	private function force_ambient_point_source( Point_Source $source ): void {
		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );
		$property->setValue( $handler, $source );

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
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

	/**
	 * A locality OTHER than the fixture's own city ("Москва") must return zero
	 * points, HTTP 200 — issue #162. Before this fix, `Woodev_Test_Bulk_Point_Source`
	 * ignored `locality` entirely and returned every fixture point regardless, so the
	 * checkout's `emptyLocality` state (spec V-5) could never be seen on the rig by
	 * setting the checkout city to e.g. «Новосибирск», only exercised in unit/jest
	 * tests.
	 *
	 * @return void
	 */
	public function test_a_foreign_locality_returns_no_points(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/pickup/woodev-test-shipping-method/points'
		);
		$request->set_param( 'locality', 'Новосибирск' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[],
			$response->get_data()['points'],
			'A locality other than the fixture\'s own city must yield zero points.'
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
