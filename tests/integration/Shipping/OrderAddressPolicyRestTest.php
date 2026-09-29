<?php
/**
 * Integration: the checkout's address-field policy in the admin order wizard (#985) — the route
 * that hands it to step ② and the save-time refusal that enforces it, against the real REST
 * server, the real WooCommerce field set and the real fixture carriers.
 *
 * The policy is the checkout's OWN object ({@see Checkout_Field_Policy}); this file pins that the
 * route answers from it, that a payload missing a field it requires is refused with a 422 on the
 * field's own path, and that a field the merchant removes never reaches the order. The rule's
 * pure core is unit-tested ({@see \Woodev\Tests\Unit\Shipping\Checkout\CheckoutFieldPolicyTest}).
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class OrderAddressPolicyRestTest extends TestCase {

	private const ROUTE    = '/woodev/v1/shipping/orders/address-policy';
	private const ORDERS   = '/woodev/v1/shipping/orders';
	private const COURIER  = 'woodev_realistic_shipping';
	private const PICKUP   = 'woodev_realistic_pickup_shipping';
	private const POINT_ID = 'REAL-MSK-1';

	/** @var int the tests' product. */
	private $product_id = 0;

	/** @var Checkout_Field_Settings|null the settings handler the policy held before the test. */
	private $policy_settings_before = null;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Woodev_Realistic_Shipping_Plugin' ) ) {
			$this->markTestSkipped( 'The shipping fixture plugins are not loaded.' );
		}

		$reflection = new \ReflectionProperty( Checkout_Field_Policy::class, 'settings' );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$this->policy_settings_before = $reflection->getValue( Checkout_Field_Policy::instance() );

		Orders_Registry::instance()->reset_for_tests();

		$plugin  = \Woodev_Realistic_Shipping_Plugin::instance();
		$init    = new \ReflectionMethod( $plugin, 'init_realistic_orders_page' );

		if ( PHP_VERSION_ID < 80100 ) {
			$init->setAccessible( true );
		}

		$init->invoke( $plugin );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Товар политики адреса' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100' );
		$this->product_id = $product->save();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		// A fresh REST server, so `rest_api_init` fires with the re-added hook.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );

		if ( null !== $this->policy_settings_before ) {
			Checkout_Field_Policy::instance()->register( $this->policy_settings_before );
		}

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Makes the checkout's policy read the given «Поля» values — through the same `register()` the
	 * settings tab calls, so the policy object under test is the production one.
	 *
	 * @param array<string, string> $values effective value by setting id; absent ids are `show`.
	 * @return void
	 */
	private function policy_says( array $values ): void {
		$settings = new class( $values ) extends Checkout_Field_Settings {

			/** @var array<string, string> */
			private $values;

			/** @param array<string, string> $values effective values. */
			public function __construct( array $values ) {
				parent::__construct();

				$this->values = $values;
			}

			/**
			 * @param string $id setting id.
			 * @return bool|string
			 */
			public function effective( string $id ) {
				if ( in_array( $id, [ 'field_order_preset', 'block_place_order' ], true ) ) {
					return false;
				}

				return $this->values[ $id ] ?? ( 'phone_field_format' === $id ? 'off' : 'show' );
			}
		};

		Checkout_Field_Policy::instance()->register( $settings );
	}

	/**
	 * @param string              $method HTTP method.
	 * @param string              $route  route below the site root.
	 * @param array<string,mixed> $params query params (GET) or JSON body.
	 * @return \WP_REST_Response
	 */
	private function send( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( [] !== $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A wizard payload with a full Russian address, to be bent one field at a time.
	 *
	 * @param array<string,mixed> $address  address keys to replace (a key set to null is dropped).
	 * @param string              $method   the tariff's method id.
	 * @param array<string,mixed> $override top-level payload keys to replace.
	 * @return array<string,mixed>
	 */
	private function payload( array $address = [], string $method = self::COURIER, array $override = [] ): array {
		$billing = array_filter(
			array_replace(
				[
					'first_name' => 'Иван',
					'country'    => 'RU',
					'state'      => 'Москва',
					'city'       => 'Москва',
					'address_1'  => 'ул. Тверская, 1',
					'postcode'   => '125009',
					'email'      => 'ivan-985@example.test',
				],
				$address
			),
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		$payload = [
			'customer'       => [ 'id' => 0 ],
			'billing'        => $billing,
			'items'          => [
				[
					'product_id' => $this->product_id,
					'quantity'   => 1,
					'price'      => '150',
				],
			],
			'shipping_line'  => [
				'method_id'   => $method,
				'instance_id' => 6,
				'label'       => 'Тариф',
				'cost'        => '350',
			],
			'payment_method' => 'cod',
			'status'         => 'pending',
		];

		if ( self::PICKUP === $method ) {
			$payload['pickup_point'] = [ 'id' => self::POINT_ID ];
		}

		return array_replace( $payload, $override );
	}

	/**
	 * @param \WP_REST_Response $response a 422.
	 * @return string[] `field:code` of every problem.
	 */
	private function problems( \WP_REST_Response $response ): array {
		return array_map(
			static function ( array $error ): string {
				return $error['field'] . ':' . $error['code'];
			},
			$response->get_data()['data']['errors']
		);
	}

	// -------------------------------------------------------------------------
	// the route
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_the_route_is_registered_and_gated_like_the_wizards_others(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes( 'woodev/v1' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->send( 'GET', self::ROUTE, [ 'country' => 'RU' ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->send( 'GET', self::ROUTE, [ 'country' => 'RU' ] )->get_status() );
	}

	/**
	 * @return void
	 */
	public function test_the_route_needs_a_two_letter_country(): void {
		$this->assertSame( 400, $this->send( 'GET', self::ROUTE )->get_status() );
		$this->assertSame( 400, $this->send( 'GET', self::ROUTE, [ 'country' => 'RUS' ] )->get_status() );
	}

	/**
	 * The route answers from the policy object the checkout uses — not from a copy of its rule.
	 *
	 * @return void
	 */
	public function test_the_route_answers_exactly_what_the_checkouts_policy_says(): void {
		$this->policy_says( [ 'region_field' => 'remove' ] );

		$response = $this->send( 'GET', self::ROUTE, [ 'country' => 'ru' ] );
		$data     = $this->wire( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'RU', $data['country'] );
		$this->assertFalse( $data['pickup'] );
		$this->assertSame( [ 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ], array_keys( $data['fields'] ) );
		$expected                         = Checkout_Field_Policy::instance()->address_rules( 'RU', false );
		$expected['postcode']['required'] = false; // #999: the wizard never requires the postcode.
		$this->assertSame( $expected, $data['fields'] );

		$this->assertSame( [ 'required' => false, 'hidden' => true, 'removed' => true ], $data['fields']['state'], 'the merchant removed the region' );
		$this->assertTrue( $data['fields']['city']['required'], 'the settlement is always required' );
		$this->assertTrue( $data['fields']['address_1']['required'] );
		$this->assertFalse( $data['fields']['address_2']['required'] );
	}

	/**
	 * @return void
	 */
	public function test_the_route_reads_the_pickup_hiding_from_the_pickup_flag(): void {
		$this->policy_says( [ 'address_field' => 'hide_for_pickup', 'postcode_field' => 'hide_for_pickup' ] );

		$pickup  = $this->wire( $this->send( 'GET', self::ROUTE, [ 'country' => 'RU', 'pickup' => 'true' ] ) );
		$courier = $this->wire( $this->send( 'GET', self::ROUTE, [ 'country' => 'RU', 'pickup' => 'false' ] ) );

		$this->assertTrue( $pickup['pickup'] );
		$this->assertSame( [ 'required' => false, 'hidden' => true, 'removed' => false ], $pickup['fields']['address_1'] );
		$this->assertSame( [ 'required' => false, 'hidden' => true, 'removed' => false ], $pickup['fields']['postcode'] );
		$this->assertTrue( $courier['fields']['address_1']['required'] );
		$this->assertFalse( $courier['fields']['postcode']['required'], 'the wizard never requires the postcode (#999)' );
	}

	/**
	 * The response as the browser receives it — the route casts `fields` to an object so an empty
	 * set still serialises as `{}`, so read it back through JSON rather than as the PHP value.
	 *
	 * @param \WP_REST_Response $response The route's response.
	 * @return array<string, mixed>
	 */
	private function wire( $response ): array {
		return json_decode( wp_json_encode( $response->get_data() ), true );
	}

	// -------------------------------------------------------------------------
	// the save-time refusal
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_an_order_missing_a_required_field_is_refused_on_that_field_and_nothing_is_written(): void {
		$this->policy_says( [] );

		$before = count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );

		$response = $this->send( 'POST', self::ORDERS, $this->payload( [ 'postcode' => null, 'address_1' => null ] ) );

		$this->assertSame( 422, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertContains( 'billing.address_1:field_required', $this->problems( $response ) );
		$this->assertNotContains( 'billing.postcode:field_required', $this->problems( $response ), 'the postcode is never required in the wizard (#999)' );
		$this->assertSame( $before, count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ) );
	}

	/**
	 * @return void
	 */
	public function test_a_complete_address_is_accepted(): void {
		$this->policy_says( [] );

		$response = $this->send( 'POST', self::ORDERS, $this->payload() );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A field the merchant removes is not demanded, and a value sent for it does not reach the order.
	 *
	 * @return void
	 */
	public function test_a_removed_field_is_neither_required_nor_stored(): void {
		$this->policy_says( [ 'postcode_field' => 'remove' ] );

		$without = $this->send( 'POST', self::ORDERS, $this->payload( [ 'postcode' => null ] ) );

		$this->assertSame( 201, $without->get_status(), wp_json_encode( $without->get_data() ) );

		$with = $this->send( 'POST', self::ORDERS, $this->payload( [ 'postcode' => '125009' ] ) );

		$this->assertSame( 201, $with->get_status(), wp_json_encode( $with->get_data() ) );
		$this->assertSame( '', wc_get_order( $with->get_data()['id'] )->get_billing_postcode() );
		$this->assertSame( '', wc_get_order( $with->get_data()['id'] )->get_shipping_postcode() );
	}

	/**
	 * A saved order can hold a legacy region that is not a WooCommerce state code. Once the merchant
	 * removes the region, the wizard neither draws nor keeps that field, so its stale value cannot
	 * block the save.
	 *
	 * @return void
	 */
	public function test_a_removed_region_with_a_stored_non_woocommerce_code_is_accepted_and_not_stored(): void {
		$this->policy_says( [ 'region_field' => 'remove' ] );

		$response = $this->send( 'POST', self::ORDERS, $this->payload( [ 'state' => 'not-a-woocommerce-code' ] ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '', wc_get_order( $response->get_data()['id'] )->get_billing_state() );
		$this->assertSame( '', wc_get_order( $response->get_data()['id'] )->get_shipping_state() );
	}

	/**
	 * `hide_for_pickup` relaxes the address only for a pickup tariff — a courier tariff still asks.
	 *
	 * @return void
	 */
	public function test_the_address_is_optional_for_a_pickup_tariff_only_when_the_merchant_hides_it_there(): void {
		$this->policy_says( [ 'address_field' => 'hide_for_pickup' ] );

		$pickup = $this->send( 'POST', self::ORDERS, $this->payload( [ 'address_1' => null ], self::PICKUP ) );

		$this->assertSame( 201, $pickup->get_status(), wp_json_encode( $pickup->get_data() ) );

		$courier = $this->send( 'POST', self::ORDERS, $this->payload( [ 'address_1' => null ], self::COURIER ) );

		$this->assertSame( 422, $courier->get_status() );
		$this->assertContains( 'billing.address_1:field_required', $this->problems( $courier ) );
	}

	/**
	 * A delivery address the manager typed separately is judged as a delivery address: the problem is
	 * on `shipping.*`, not `billing.*`.
	 *
	 * @return void
	 */
	public function test_a_separate_delivery_address_is_refused_on_its_own_path(): void {
		$this->policy_says( [] );

		$response = $this->send(
			'POST',
			self::ORDERS,
			$this->payload(
				[],
				self::COURIER,
				[
					'shipping' => [
						'country' => 'RU',
						'state'   => 'Татарстан',
						'city'    => 'Казань',
					],
				]
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertContains( 'shipping.address_1:field_required', $this->problems( $response ) );
		$this->assertNotContains( 'shipping.postcode:field_required', $this->problems( $response ), 'the postcode is never required in the wizard (#999)' );
	}

	/**
	 * #999: whatever the locale says (WC 11.1 RU requires the postcode), a wizard order without one is saved,
	 * and the route tells the step ② the same — while the checkout's own rule is untouched.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_postcode_is_accepted_and_the_checkout_rule_is_untouched(): void {
		$this->policy_says( [] );

		$this->assertTrue( Checkout_Field_Policy::instance()->address_rules( 'RU', false )['postcode']['required'], 'the checkout still requires it' );

		$response = $this->send( 'POST', self::ORDERS, $this->payload( [ 'postcode' => null ] ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $this->wire( $this->send( 'GET', self::ROUTE, [ 'country' => 'RU' ] ) )['fields']['postcode']['required'] );
	}
}
