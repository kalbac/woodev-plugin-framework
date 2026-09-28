<?php
/**
 * Integration: `POST woodev/v1/shipping/orders/rates` — the admin order wizard's rate calculator
 * (#965, spec `2026-09-27-710-create-edit-order-design.md` D2).
 *
 * Proves through the REAL REST server what the unit tests cannot: inside a REST request (where
 * `REST_REQUEST` is defined and `WC()->session`, `WC()->cart`, `WC()->customer` are null) the
 * calculator still gets a rate out of a real zone-instance method — the whole point of the
 * `Shipping_Method::get_admin_rates_for_package()` seam (Mine 1) — that WordPress itself answers
 * 401 / 403 before the callback, and that a zone restricted by STATE is matched from the label a
 * location record carries (Mine 1b), not only from a code.
 *
 * Written, NOT run by the worker that authored it (two concurrent integration runs share one
 * test DB) — the coordinator runs the suite.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class AdminRatesRouteTest extends TestCase {

	private const ROUTE = '/woodev/v1/shipping/orders/rates';

	/** @var int a shippable product id. */
	private $product_id;

	/** @var \WC_Shipping_Zone the zone the fixture method lives in. */
	private $zone;

	/** @var int the fixture method's instance id in that zone. */
	private $instance_id;

	/**
	 * A real zone (RU, Москва) with the fixture method, one registered provider that claims it, one
	 * shippable product, and a fresh REST server so the route registers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — the shipping fixture plugin must be active in wp-env.'
		);

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider( Orders_Provider::create( 'rates_test', 'Rates test carrier', '_rates_test_marker', [ 'woodev_test_shipping' ] ) );

		$this->zone = new \WC_Shipping_Zone();
		$this->zone->set_zone_name( '#965 admin rates zone' );
		$this->zone->add_location( 'RU:МОСКВА', 'state' );
		$this->zone->save();

		$this->instance_id = $this->zone->add_shipping_method( 'woodev_test_shipping' );

		// Populate the plugin's method registry the way WooCommerce does.
		apply_filters( 'woocommerce_shipping_methods', [] );

		$product = new \WC_Product_Simple();
		$product->set_name( '#965 shippable product' );
		$product->set_regular_price( '100' );
		$product->set_virtual( false );
		$product->set_weight( '1.5' );
		$this->product_id = $product->save();

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->zone->delete();
		wp_delete_post( $this->product_id, true );
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $body request body.
	 * @return \WP_REST_Response
	 */
	private function post( array $body ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string, mixed> $overrides body fields to override.
	 * @return array<string, mixed>
	 */
	private function body( array $overrides = [] ): array {
		return array_merge(
			[
				'items'       => [
					[
						'product_id' => $this->product_id,
						'quantity'   => 2,
						'price'      => 150,
					],
				],
				'destination' => [
					'country' => 'RU',
					'state'   => 'Москва',
					'city'    => 'Москва',
				],
			],
			$overrides
		);
	}

	/**
	 * @param \WP_REST_Response $response the route's response.
	 * @return array<string, mixed> the group of the registered provider.
	 */
	private function group( $response ): array {
		foreach ( $response->get_data()['providers'] as $group ) {
			if ( 'rates_test' === $group['id'] ) {
				return $group;
			}
		}

		$this->fail( 'the registered provider is missing from the response' );
	}

	public function test_the_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes( 'woodev/v1' ) );
	}

	public function test_a_guest_is_refused_with_401(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->post( $this->body() )->get_status() );
	}

	public function test_a_user_without_edit_shop_orders_is_refused_with_403(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->post( $this->body() )->get_status() );
	}

	/**
	 * Mine 1 end to end: a rate comes out of a REST request, from the method instance of the zone
	 * the destination falls into.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_gets_the_carriers_rate_inside_a_rest_request(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->post( $this->body() );

		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$group = $this->group( $response );

		$this->assertTrue( $data['needs_shipping'] );
		$this->assertSame( $this->zone->get_id(), $data['zone']['id'] );
		$this->assertSame( 'Rates test carrier', $group['label'] );
		$this->assertCount( 1, $group['rates'] );
		$this->assertSame( 'woodev_test_shipping:' . $this->instance_id, $group['rates'][0]['id'] );
		$this->assertSame( 'woodev_test_shipping', $group['rates'][0]['method_id'] );
		$this->assertSame( $this->instance_id, $group['rates'][0]['instance_id'] );
		// The fixture method declares itself a pickup method (`get_delivery_type() === 'pickup'`, #709),
		// so the flag the calculator derives from `is_pickup_shipping()` is true — it is read off the
		// method, not defaulted.
		$this->assertTrue( $group['rates'][0]['is_pickup'] );
	}

	/**
	 * Only OUR carriers' methods (O12): `free_shipping` in the same zone is not in any group.
	 *
	 * @return void
	 */
	public function test_a_foreign_method_in_the_zone_is_not_offered(): void {
		$this->zone->add_shipping_method( 'free_shipping' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->post( $this->body() );

		foreach ( $response->get_data()['providers'] as $group ) {
			foreach ( $group['rates'] as $rate ) {
				$this->assertNotSame( 'free_shipping', $rate['method_id'] );
			}
		}
	}

	/**
	 * Mine 1b: the zone is restricted by the state CODE (`RU:МОСКВА`); the wizard sends the label
	 * (or a record's region name). A different region falls outside the zone, so the carrier
	 * offers nothing there — the provider still appears, with no rates.
	 *
	 * @return void
	 */
	public function test_the_state_label_is_mapped_to_the_code_the_zone_is_restricted_by(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$inside  = $this->post( $this->body() );
		$outside = $this->post(
			$this->body(
				[
					'destination' => [
						'country' => 'RU',
						'state'   => 'Санкт-Петербург',
						'city'    => 'Санкт-Петербург',
					],
				]
			)
		);

		$this->assertCount( 1, $this->group( $inside )['rates'] );
		$this->assertSame( [], $this->group( $outside )['rates'] );
	}

	public function test_an_unknown_product_is_a_422(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->post( $this->body( [ 'items' => [ [ 'product_id' => 999999, 'quantity' => 1 ] ] ] ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woodev_rates_unknown_product', $response->get_data()['code'] );
	}

	public function test_a_destination_without_a_country_is_a_422(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->post( $this->body( [ 'destination' => [ 'city' => 'Москва' ] ] ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woodev_rates_no_country', $response->get_data()['code'] );
	}

	public function test_a_request_without_items_is_refused_by_the_schema(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->post( [ 'destination' => [ 'country' => 'RU' ] ] );

		$this->assertSame( 400, $response->get_status() );
	}
}
