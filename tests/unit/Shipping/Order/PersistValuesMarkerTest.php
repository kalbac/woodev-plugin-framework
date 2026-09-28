<?php
/**
 * Unit: the persistence core marks the order (#967, #710 I1b).
 *
 * `Checkout_Handler::persist_values()` is the one write path the classic checkout, the Store
 * API checkout and (I3) the admin order editor share; the carrier marker must be written from
 * there, for the carrier whose method is on the order, BEFORE any field, and by every path.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
	use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
	use Woodev\Framework\Shipping\Checkout\Field;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/order-persistence-fixtures.php';
	require_once __DIR__ . '/order-marker-fakes.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::persist_values
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::save
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_store_api_order_processed
	 */
	class PersistValuesMarkerTest extends TestCase {

		private const KEY = '_test_marker';

		/**
		 * The ONE ordered log: marker and field writes, so their order is assertable.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		private array $events = [];

		protected function setUp(): void {
			parent::setUp();

			$this->events = [];

			Functions\when( 'wc_clean' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'add_action' )->justReturn( true );
			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'remove_action' )->justReturn( true );
			Functions\when( 'remove_filter' )->justReturn( true );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'sanitize_hex_color' )->returnArg();
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'update_post_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->events[] = [ 'field', $key, $value ];

					return true;
				}
			);
			Functions\when( 'get_post_meta' )->alias( [ Order_Marker_Fakes::class, 'get_post_meta' ] );

			Order_Marker_Fakes::reset();
			Location_Provider_Registry::instance()->reset_for_tests();
			Shipping_Settings_Tab::reset_for_tests();
			Orders_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			$_POST = [];
			Checkout_Handler::reset_native_field_registry();
			Shipping_Settings_Tab::reset_for_tests();
			Orders_Registry::instance()->reset_for_tests();
			Order_Marker_Fakes::reset();
			parent::tearDown();
		}

		private function handler(): Checkout_Handler {
			return new Checkout_Handler(
				Checkout_Fields::from_array( [ Field::create( 'carrier_comment' )->to_array() ] ),
				'carrier'
			);
		}

		private function register_provider(): void {
			Orders_Registry::instance()->register_provider(
				Orders_Provider::create(
					'carrier',
					'Перевозчик',
					self::KEY,
					[ 'carrier_courier' ],
					[
						'marker_writer' => function ( \WC_Order $order, array $context ): void {
							$this->events[] = [ 'marker', $context['method_id'], $context['fields'] ];
							$order->update_meta_data( self::KEY, '1' );
						},
					]
				)
			);
		}

		public function test_persist_values_marks_a_carrier_order_before_writing_any_field(): void {
			$this->register_provider();
			$order   = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );
			$handler = $this->handler();

			$saved = $handler->persist_values( $order, [ 'carrier_comment' => 'позвонить' ], 'carrier_courier' );

			$this->assertSame( [ 'carrier_comment' => 'позвонить' ], $saved );
			$this->assertSame(
				[
					[ 'marker', 'carrier_courier', [ 'carrier_comment' => 'позвонить' ] ],
					[ 'field', 'carrier_comment', 'позвонить' ],
				],
				$this->events
			);
			$this->assertSame( '1', Order_Marker_Fakes::$db[42][ self::KEY ] );
		}

		public function test_persist_values_does_not_mark_another_carriers_order(): void {
			$this->register_provider();
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'free_shipping', 1 ) ] );

			$this->handler()->persist_values( $order, [ 'carrier_comment' => 'x' ], 'free_shipping' );

			$this->assertSame( [ [ 'field', 'carrier_comment', 'x' ] ], $this->events );
			$this->assertSame( [], Order_Marker_Fakes::$db );
		}

		public function test_persist_values_hands_the_admin_editors_context_to_the_writer(): void {
			Orders_Registry::instance()->register_provider(
				Orders_Provider::create(
					'carrier',
					'Перевозчик',
					self::KEY,
					[ 'carrier_courier' ],
					[
						'marker_writer' => function ( \WC_Order $order, array $context ): void {
							$this->events[] = [ 'context', $context['pickup_point'], $context['carrier_fields'] ];
							$order->update_meta_data( self::KEY, '1' );
						},
					]
				)
			);
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );

			$this->handler()->persist_values(
				$order,
				[],
				'carrier_courier',
				null,
				[
					'pickup_point'   => [ 'id' => 'P1' ],
					'carrier_fields' => [ 'declared_value' => 100 ],
				]
			);

			$this->assertSame( [ [ 'context', [ 'id' => 'P1' ], [ 'declared_value' => 100 ] ] ], $this->events );
		}

		public function test_save_reaches_the_marker_through_the_core(): void {
			$this->register_provider();
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );

			$this->handler()->save( $order, [ 'carrier_comment' => 'a' ], 'carrier_courier' );

			$this->assertSame( '1', Order_Marker_Fakes::$db[42][ self::KEY ] );
		}

		public function test_the_store_api_path_marks_the_order_too(): void {
			$this->register_provider();
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );

			$this->handler()->handle_store_api_order_processed( $order );

			$this->assertSame( '1', Order_Marker_Fakes::$db[42][ self::KEY ] );
		}
	}
}
