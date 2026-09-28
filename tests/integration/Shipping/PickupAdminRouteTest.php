<?php
/**
 * Integration: the ADMIN pickup-points routes (#959, spec `2026-09-27-710-create-edit-order-design.md` D3)
 * — `woodev/v1/shipping/orders/pickup/{plugin}/points` and `…/points/{id}`.
 *
 * Proves through the REAL REST server what the unit tests cannot: the routes are registered by the
 * fixture plugin's own `Pickup_Handler::register_rest()`, WordPress itself answers 401 / 403 before
 * the callback runs, and a shop manager gets the explicit-context verdict while the public route
 * stays open to guests.
 *
 * Written, NOT run by the worker that authored it (two concurrent integration runs share one test
 * DB) — the coordinator runs the suite.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use ReflectionProperty;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class PickupAdminRouteTest extends TestCase {

	private const LIST_ROUTE = '/woodev/v1/shipping/orders/pickup/woodev-test-shipping-method/points';

	/**
	 * The ambient fixture plugin's `Pickup_Handler::$source` as found at the start of THIS test,
	 * restored in {@see self::tearDown()} — the same network-free pinning `PickupRouteTest` does.
	 *
	 * @var Point_Source
	 */
	private $original_ambient_point_source;

	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — the shipping fixture plugin must be active in wp-env.'
		);

		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );

		$this->original_ambient_point_source = $property->getValue( $handler );
		$this->force_ambient_point_source( new \Woodev_Test_Bulk_Point_Source() );
	}

	protected function tearDown(): void {
		$this->force_ambient_point_source( $this->original_ambient_point_source );

		parent::tearDown();
	}

	private function force_ambient_point_source( Point_Source $source ): void {
		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );
		$property->setValue( $handler, $source );

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @param string               $route  route path.
	 * @param array<string, mixed> $params request params.
	 */
	private function get( string $route, array $params = [] ) {
		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_the_admin_routes_are_registered_by_the_fixture_plugin(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( self::LIST_ROUTE, $routes );
		$this->assertArrayHasKey( self::LIST_ROUTE . '/(?P<id>[^/]+)', $routes );
	}

	public function test_a_guest_is_refused_with_401(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( self::LIST_ROUTE, [ 'locality' => 'Москва' ] )->get_status() );
		$this->assertSame( 401, $this->get( self::LIST_ROUTE . '/FIX-BULK-1' )->get_status() );
	}

	public function test_a_user_without_edit_shop_orders_is_refused_with_403(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->get( self::LIST_ROUTE, [ 'locality' => 'Москва' ] )->get_status() );
		$this->assertSame( 403, $this->get( self::LIST_ROUTE . '/FIX-BULK-1' )->get_status() );
	}

	public function test_a_shop_manager_reads_the_list(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->get( self::LIST_ROUTE, [ 'locality' => 'Москва' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['points'] );
		$this->assertArrayHasKey( 'selectable', $response->get_data()['points'][0] );
	}

	public function test_the_explicit_payment_method_gates_cod_on_list_and_detail_alike(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cod_id = \Woodev_Test_Bulk_Point_Source::COD_REFUSING_POINT_ID;

		$cod_list   = $this->get( self::LIST_ROUTE, [ 'locality' => 'Москва', 'payment_method' => 'cod' ] )->get_data();
		$cod_detail = $this->get( self::LIST_ROUTE . '/' . $cod_id, [ 'payment_method' => 'cod' ] )->get_data();
		$bacs       = $this->get( self::LIST_ROUTE . '/' . $cod_id, [ 'payment_method' => 'bacs' ] )->get_data();
		$unknown    = $this->get( self::LIST_ROUTE . '/' . $cod_id )->get_data();

		$listed = array_values(
			array_filter( $cod_list['points'], static fn( array $point ): bool => $point['id'] === $cod_id )
		)[0];

		$this->assertFalse( $cod_detail['selectable']['allowed'] );
		$this->assertSame( $listed['selectable'], $cod_detail['selectable'], 'list and detail must agree' );
		$this->assertTrue( $bacs['selectable']['allowed'] );
		$this->assertTrue( $unknown['selectable']['allowed'], 'no payment method chosen yet must not refuse a point' );
	}

	public function test_the_explicit_weight_gates_the_weight_limited_point(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$weight_id = \Woodev_Test_Bulk_Point_Source::WEIGHT_LIMITED_POINT_ID;

		$heavy = $this->get( self::LIST_ROUTE . '/' . $weight_id, [ 'weight' => 1500 ] )->get_data();
		$light = $this->get( self::LIST_ROUTE . '/' . $weight_id, [ 'weight' => 500 ] )->get_data();

		$this->assertFalse( $heavy['selectable']['allowed'] );
		$this->assertTrue( $light['selectable']['allowed'] );
	}

	public function test_an_unknown_point_is_a_404(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( 404, $this->get( self::LIST_ROUTE . '/NO-SUCH-POINT' )->get_status() );
	}

	public function test_an_invalid_location_is_a_400(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->get( self::LIST_ROUTE, [ 'locality' => 'Москва', 'location' => [ 'key' => 'nope' ] ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_the_public_route_stays_open_to_guests(): void {
		wp_set_current_user( 0 );

		$response = $this->get( '/woodev/v1/shipping/pickup/woodev-test-shipping-method/points', [ 'locality' => 'Москва' ] );

		$this->assertSame( 200, $response->get_status() );
	}
}
