<?php
/**
 * Regression tests for #950: Woodev_WC_Packer_Dispatcher converts the store's weight and
 * dimension units to the packer's kg / cm at the boundary, in BOTH converters
 * (from_cart_items() and from_order_items()).
 *
 * `wc_get_weight()` / `wc_get_dimension()` are replaced with a faithful fake of WooCommerce's
 * conversion (through the store unit, as WC does), so the test exercises the real contract:
 * the dispatcher must ask for kg / cm and pass the converted value on.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class WcPackerDispatcherUnitsTest_WC_Product_Stub {}
		class_alias( WcPackerDispatcherUnitsTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	class WcPackerDispatcherUnitsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// the effective values read the store's default dimensions (#955), a settings handler: none stored here
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/** Units → kg. */
		private const WEIGHT_TO_KG = [
			'kg'  => 1.0,
			'g'   => 0.001,
			'lbs' => 0.45359237,
			'oz'  => 0.028349523125,
		];

		/** Units → cm. */
		private const DIMENSION_TO_CM = [
			'cm' => 1.0,
			'mm' => 0.1,
			'm'  => 100.0,
			'in' => 2.54,
			'yd' => 91.44,
		];

		/**
		 * Fakes wc_get_weight() / wc_get_dimension() for a store configured with the given units.
		 */
		private function store_uses( string $weight_unit, string $dimension_unit ): void {
			Functions\when( 'wc_get_weight' )->alias(
				static function ( $weight, $to_unit, $from_unit = '' ) use ( $weight_unit ) {
					return (float) $weight * self::WEIGHT_TO_KG[ '' === $from_unit ? $weight_unit : $from_unit ] / self::WEIGHT_TO_KG[ $to_unit ];
				}
			);
			Functions\when( 'wc_get_dimension' )->alias(
				static function ( $dimension, $to_unit, $from_unit = '' ) use ( $dimension_unit ) {
					return (float) $dimension * self::DIMENSION_TO_CM[ '' === $from_unit ? $dimension_unit : $from_unit ] / self::DIMENSION_TO_CM[ $to_unit ];
				}
			);
		}

		/**
		 * @param string|float $length Raw product value, as WC returns it ('' when unset).
		 */
		private function product( $length, $width, $height, $weight, bool $virtual = false ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'is_virtual' )->andReturn( $virtual );
			$product->shouldReceive( 'get_length' )->andReturn( $length );
			$product->shouldReceive( 'get_width' )->andReturn( $width );
			$product->shouldReceive( 'get_height' )->andReturn( $height );
			$product->shouldReceive( 'get_weight' )->andReturn( $weight );

			return $product;
		}

		private function order_with( \WC_Product $product, int $quantity ): \WC_Order {
			$item = Mockery::mock( '\WC_Order_Item_Product' );
			$item->shouldReceive( 'get_product' )->andReturn( $product );
			$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_items' )->andReturn( [ $item ] );

			return $order;
		}

		/**
		 * The same physical product (2.5 kg, 30 x 20 x 10 cm), expressed in each store's units.
		 *
		 * @return array<string, array{0:string,1:string,2:float,3:float,4:float,5:float}>
		 */
		public static function store_units_provider(): array {
			return [
				'grams + millimetres' => [ 'g', 'mm', 300.0, 200.0, 100.0, 2500.0 ],
				'pounds + inches'     => [ 'lbs', 'in', 30 / 2.54, 20 / 2.54, 10 / 2.54, 2.5 / 0.45359237 ],
				'ounces + metres'     => [ 'oz', 'm', 0.3, 0.2, 0.1, 2.5 / 0.028349523125 ],
				'kg + cm (default)'   => [ 'kg', 'cm', 30.0, 20.0, 10.0, 2.5 ],
			];
		}

		/**
		 * @dataProvider store_units_provider
		 */
		public function test_from_cart_items_converts_store_units_to_cm_and_kg( string $weight_unit, string $dimension_unit, float $l, float $w, float $h, float $weight ): void {
			$this->store_uses( $weight_unit, $dimension_unit );

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[ [ 'data' => $this->product( (string) $l, (string) $w, (string) $h, (string) $weight ), 'quantity' => 3 ] ]
			);

			$this->assertCount( 1, $items );
			$this->assertEqualsWithDelta( 30.0, $items[0]->get_length(), 0.0001 );
			$this->assertEqualsWithDelta( 20.0, $items[0]->get_width(), 0.0001 );
			$this->assertEqualsWithDelta( 10.0, $items[0]->get_height(), 0.0001 );
			$this->assertEqualsWithDelta( 2.5, $items[0]->get_weight(), 0.0001 );
			$this->assertSame( 3, $items[0]->get_quantity() );
		}

		/**
		 * @dataProvider store_units_provider
		 */
		public function test_from_order_items_converts_store_units_to_cm_and_kg( string $weight_unit, string $dimension_unit, float $l, float $w, float $h, float $weight ): void {
			$this->store_uses( $weight_unit, $dimension_unit );

			$items = \Woodev_WC_Packer_Dispatcher::from_order_items(
				$this->order_with( $this->product( (string) $l, (string) $w, (string) $h, (string) $weight ), 2 )
			);

			$this->assertCount( 1, $items );
			$this->assertEqualsWithDelta( 30.0, $items[0]->get_length(), 0.0001 );
			$this->assertEqualsWithDelta( 20.0, $items[0]->get_width(), 0.0001 );
			$this->assertEqualsWithDelta( 10.0, $items[0]->get_height(), 0.0001 );
			$this->assertEqualsWithDelta( 2.5, $items[0]->get_weight(), 0.0001 );
			$this->assertSame( 2, $items[0]->get_quantity() );
		}

		/**
		 * A product with nothing of its own is packed with the built-in default (10 cm, 100 g — here a
		 * kg / cm store, so 0.1 kg) through both converters — see WcPackerDispatcherDefaultsTest for the
		 * fallback itself (#955).
		 */
		public function test_missing_dimensions_and_weight_get_the_builtin_default_in_both_converters(): void {
			$this->store_uses( 'kg', 'cm' );

			$cart  = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[ [ 'data' => $this->product( '', '', '', '' ), 'quantity' => 1 ] ]
			);
			$order = \Woodev_WC_Packer_Dispatcher::from_order_items(
				$this->order_with( $this->product( '', '', '', '' ), 1 )
			);

			foreach ( [ $cart[0], $order[0] ] as $item ) {
				$this->assertEqualsWithDelta( 10.0, $item->get_length(), 0.0001 );
				$this->assertEqualsWithDelta( 10.0, $item->get_width(), 0.0001 );
				$this->assertEqualsWithDelta( 10.0, $item->get_height(), 0.0001 );
				$this->assertEqualsWithDelta( 0.1, $item->get_weight(), 0.0001 );
			}
		}

		/**
		 * The boundary must ask WooCommerce for exactly kg and cm — the packer's contract.
		 */
		public function test_boundary_requests_kg_and_cm_from_woocommerce(): void {
			// the default-dimensions handler converts its built-in defaults (g / cm -> store units) once, on construction
			Functions\expect( 'wc_get_dimension' )->times( 3 )->with( 10.0, 'cm', 'cm' )->andReturn( 10.0 );
			Functions\expect( 'wc_get_weight' )->once()->with( 100.0, 'kg', 'g' )->andReturn( 0.1 );

			Functions\expect( 'wc_get_dimension' )->times( 3 )->with( 5.0, 'cm' )->andReturn( 50.0 );
			Functions\expect( 'wc_get_weight' )->once()->with( 7.0, 'kg' )->andReturn( 0.007 );

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[ [ 'data' => $this->product( '5', '5', '5', '7' ), 'quantity' => 1 ] ]
			);

			$this->assertSame( 50.0, $items[0]->get_length() );
			$this->assertSame( 0.007, $items[0]->get_weight() );
		}
	}
}
