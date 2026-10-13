<?php
/**
 * Unit: an order keeps the data of the carrier it was placed with, and only that one (#1260,
 * Y15 F3 + F6).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
	use Woodev\Framework\Shipping\Order\Delivery_Status_Events;
	use Woodev\Framework\Shipping\Order\Unchosen_Carrier_Cleaner;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/order-persistence-fixtures.php';
	require_once __DIR__ . '/order-marker-fakes.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Unchosen_Carrier_Cleaner
	 * @covers \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::meta_keys
	 */
	class UnchosenCarrierCleanerTest extends TestCase {

		private const CDEK_MARKER = '_wc_edostavka_shipping';
		private const YA_MARKER   = '_yandex_delivery_shipping';

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'add_action' )->justReturn( true );
			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'did_action' )->justReturn( 0 );
			Functions\when( 'wc_clean' )->returnArg();
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'get_post_meta' )->alias( [ Order_Marker_Fakes::class, 'get_post_meta' ] );
			Functions\when( 'delete_post_meta' )->alias(
				static function ( $id, $key ) {
					unset( Order_Marker_Fakes::$db[ (int) $id ][ $key ] );

					return true;
				}
			);

			Order_Marker_Fakes::reset();
			Shipping_Settings_Tab::reset_for_tests();
			Orders_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Shipping_Settings_Tab::reset_for_tests();
			Orders_Registry::instance()->reset_for_tests();
			Order_Marker_Fakes::reset();
			parent::tearDown();
		}

		/**
		 * Registers a CDEK-like and a Yandex-like carrier, each with its own plugin handlers.
		 *
		 * @param array<string, mixed> $yandex_args extra `Orders_Provider::create()` args for Yandex.
		 */
		private function register_carriers( array $yandex_args = [] ): void {
			$cdek = Orders_Provider::create(
				'edostavka',
				'СДЭК',
				self::CDEK_MARKER,
				[ 'cdek_pickup', 'cdek_courier' ],
				[ 'pickup_point_meta_key' => 'cdek_pickup_point' ]
			);

			$yandex = Orders_Provider::create(
				'yandex_delivery',
				'Яндекс Доставка',
				self::YA_MARKER,
				[ 'yandex_delivery_pickup', 'yandex_delivery_courier' ],
				array_merge( [ 'pickup_point_meta_key' => '_yandex_delivery_destination_station_id' ], $yandex_args )
			);

			Orders_Registry::instance()->register_provider(
				$cdek,
				$this->plugin( [ 'cdek_pickup_point' ], [ 'cdek_pickup_point', '_wc_edostavka_pickup_point_data' ] )
			);
			Orders_Registry::instance()->register_provider(
				$yandex,
				$this->plugin(
					[ '_yandex_delivery_destination_station_id' ],
					[
						'_yandex_delivery_destination_station_id',
						'_yandex_delivery_destination_station_address',
						'_yandex_delivery_pickup_point_data',
					]
				)
			);
		}

		/**
		 * A carrier plugin double with a checkout handler and a pickup handler.
		 *
		 * @param string[] $slots        the checkout handler's pickup slot ids.
		 * @param string[] $pickup_keys  the pickup handler's order-meta keys.
		 */
		private function plugin( array $slots, array $pickup_keys ): Shipping_Plugin {
			$checkout = Mockery::mock( Checkout_Handler::class );
			$checkout->shouldReceive( 'pickup_field_ids' )->andReturn( $slots );

			$pickup = Mockery::mock( Pickup_Handler::class );
			$pickup->shouldReceive( 'order_meta_keys' )->andReturn( $pickup_keys );

			$plugin = Mockery::mock( Shipping_Plugin::class );
			$plugin->shouldReceive( 'get_checkout_handler' )->andReturn( $checkout );
			$plugin->shouldReceive( 'get_pickup_handler' )->andReturn( $pickup );

			return $plugin;
		}

		/**
		 * The state of an order a buyer left Yandex for CDEK on: both carriers' keys are present.
		 *
		 * @return array<string, mixed>
		 */
		private function both_carriers_keys(): array {
			return [
				'cdek_pickup_point'                            => 'MSK2629',
				'_wc_edostavka_pickup_point_data'              => [ 'id' => 'MSK2629' ],
				self::CDEK_MARKER                              => '1',
				Delivery_Status_Events::BASELINE_META_PREFIX . 'edostavka' => 'pending',
				'_yandex_delivery_destination_station_id'      => 'ya-1',
				'_yandex_delivery_destination_station_address' => 'Москва, Волынская 10',
				'_yandex_delivery_pickup_point_data'           => [ 'id' => 'ya-1' ],
				self::YA_MARKER                                => '1',
				Delivery_Status_Events::BASELINE_META_PREFIX . 'yandex_delivery' => 'pending',
			];
		}

		/**
		 * @param array<string, mixed> $meta the order's stored meta.
		 */
		private function order_with( string $method_id, array $meta, int $instance_id = 4 ) {
			Order_Marker_Fakes::$db[42] = array_merge( [ '_billing_phone' => '+7 900', 'customer_note' => 'позвонить' ], $meta );

			return Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( $method_id, $instance_id ) ] );
		}

		private function stored_keys(): array {
			$keys = array_keys( Order_Marker_Fakes::$db[42] ?? [] );
			sort( $keys );

			return $keys;
		}

		private function chosen_carrier_keys( string $carrier ): array {
			$keys = 'cdek' === $carrier
				? [ 'cdek_pickup_point', '_wc_edostavka_pickup_point_data', self::CDEK_MARKER, Delivery_Status_Events::BASELINE_META_PREFIX . 'edostavka' ]
				: [
					'_yandex_delivery_destination_station_id',
					'_yandex_delivery_destination_station_address',
					'_yandex_delivery_pickup_point_data',
					self::YA_MARKER,
					Delivery_Status_Events::BASELINE_META_PREFIX . 'yandex_delivery',
				];
			$keys = array_merge( $keys, [ '_billing_phone', 'customer_note' ] );
			sort( $keys );

			return $keys;
		}

		public function test_a_cdek_order_loses_every_yandex_key_and_keeps_its_own(): void {
			$this->register_carriers();
			$order = $this->order_with( 'cdek_pickup', $this->both_carriers_keys() );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( $this->chosen_carrier_keys( 'cdek' ), $this->stored_keys() );
			$this->assertSame( 'MSK2629', Order_Marker_Fakes::$db[42]['cdek_pickup_point'] );
			$this->assertSame( 'позвонить', Order_Marker_Fakes::$db[42]['customer_note'], 'a non-carrier key is never touched' );
		}

		public function test_a_yandex_order_loses_every_cdek_key_and_keeps_its_own(): void {
			$this->register_carriers();
			$order = $this->order_with( 'yandex_delivery_pickup', $this->both_carriers_keys() );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( $this->chosen_carrier_keys( 'yandex' ), $this->stored_keys() );
		}

		public function test_the_left_carriers_pending_baseline_and_marker_go_too(): void {
			$this->register_carriers();
			$order = $this->order_with( 'cdek_courier', $this->both_carriers_keys() );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertArrayNotHasKey( self::YA_MARKER, Order_Marker_Fakes::$db[42] );
			$this->assertArrayNotHasKey( Delivery_Status_Events::BASELINE_META_PREFIX . 'yandex_delivery', Order_Marker_Fakes::$db[42] );
		}

		public function test_the_block_checkout_hook_cleans_the_same_way(): void {
			$this->register_carriers();
			$order = $this->order_with( 'cdek_pickup', $this->both_carriers_keys() );

			Unchosen_Carrier_Cleaner::handle_store_api_order_processed( $order );

			$this->assertSame( $this->chosen_carrier_keys( 'cdek' ), $this->stored_keys() );
		}

		public function test_an_order_of_neither_carrier_keeps_no_carrier_key(): void {
			$this->register_carriers();
			$order = $this->order_with( 'free_shipping', $this->both_carriers_keys(), 1 );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( [ '_billing_phone', 'customer_note' ], $this->stored_keys() );
		}

		public function test_nothing_is_written_when_the_order_carries_no_other_carriers_key(): void {
			$this->register_carriers();
			$order = $this->order_with( 'cdek_pickup', [ 'cdek_pickup_point' => 'MSK2629', self::CDEK_MARKER => '1' ] );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( 0, Order_Marker_Fakes::$saves[42] ?? 0 );
			$expected = [ '_billing_phone', 'cdek_pickup_point', 'customer_note', self::CDEK_MARKER ];
			sort( $expected );

			$this->assertSame( $expected, $this->stored_keys() );
		}

		public function test_a_key_shared_with_the_chosen_carrier_stays(): void {
			Orders_Registry::instance()->register_provider(
				Orders_Provider::create( 'edostavka', 'СДЭК', self::CDEK_MARKER, [ 'cdek_pickup' ], [ 'pickup_point_meta_key' => 'shared_point' ] )
			);
			Orders_Registry::instance()->register_provider(
				Orders_Provider::create( 'yandex_delivery', 'Яндекс', self::YA_MARKER, [ 'yandex_delivery_pickup' ], [ 'pickup_point_meta_key' => 'shared_point' ] )
			);
			$order = $this->order_with( 'cdek_pickup', [ 'shared_point' => 'P1', self::YA_MARKER => '1' ] );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( 'P1', Order_Marker_Fakes::$db[42]['shared_point'] );
			$this->assertArrayNotHasKey( self::YA_MARKER, Order_Marker_Fakes::$db[42] );
		}

		public function test_the_unchosen_carriers_export_fields_go_and_the_chosen_ones_stay(): void {
			$this->register_carriers(
				[
					'order_fields' => static fn(): array => [
						'declared_value' => [
							'meta_key' => '_yandex_declared_value',
							'name'     => 'Стоимость',
							'type'     => 'float',
						],
					],
				]
			);
			$order = $this->order_with(
				'cdek_pickup',
				[
					'_yandex_declared_value' => '500',
					'cdek_pickup_point'      => 'MSK2629',
				]
			);

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertArrayNotHasKey( '_yandex_declared_value', Order_Marker_Fakes::$db[42] );
			$this->assertSame( 'MSK2629', Order_Marker_Fakes::$db[42]['cdek_pickup_point'] );
		}

		public function test_a_throwing_field_declaration_never_breaks_the_order(): void {
			$this->register_carriers(
				[
					'order_fields' => static function (): array {
						throw new \RuntimeException( 'carrier bug' );
					},
				]
			);
			$order = $this->order_with( 'cdek_pickup', $this->both_carriers_keys() );

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );

			$this->assertSame( $this->chosen_carrier_keys( 'cdek' ), $this->stored_keys() );
		}

		public function test_it_does_nothing_without_providers_or_without_an_order(): void {
			$order = $this->order_with( 'cdek_pickup', $this->both_carriers_keys() );
			$before = $this->stored_keys();

			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], $order );
			Unchosen_Carrier_Cleaner::handle_checkout_order_processed( 42, [], null );
			Unchosen_Carrier_Cleaner::handle_store_api_order_processed( null );

			$this->assertSame( $before, $this->stored_keys() );
		}

		public function test_forget_unchosen_reports_the_carriers_it_cleaned(): void {
			$this->register_carriers();
			$order = $this->order_with( 'cdek_pickup', [] );

			$this->assertSame( [ 'yandex_delivery' ], ( new Unchosen_Carrier_Cleaner() )->forget_unchosen( $order ) );
		}
	}
}
