<?php
/**
 * #955: Woodev_WC_Packer_Dispatcher fills a product's MISSING length / width / height / weight from the
 * store's «Вес и габариты» settings — per value, in the store's units, and only when the product
 * has no value of its own. The settings are required and pre-filled (100 g, 10 cm) in the store's units.
 *
 * `wc_get_weight()` / `wc_get_dimension()` are faithful fakes of WooCommerce's conversion (see
 * WcPackerDispatcherUnitsTest); the stored settings are served through a `get_option` fake keyed by the
 * real option names (`woodev_default_dimensions_{id}`).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class WcPackerDispatcherDefaultsTest_WC_Product_Stub {}
		class_alias( WcPackerDispatcherDefaultsTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Settings\Default_Dimensions_Settings;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;

	/**
	 * @covers \Woodev_WC_Packer_Dispatcher
	 * @covers \Woodev\Framework\Shipping\Settings\Default_Dimensions_Settings
	 */
	class WcPackerDispatcherDefaultsTest extends TestCase {

		private const WEIGHT_TO_KG = [
			'kg'  => 1.0,
			'g'   => 0.001,
			'lbs' => 0.45359237,
		];

		private const DIMENSION_TO_CM = [
			'cm' => 1.0,
			'mm' => 0.1,
			'in' => 2.54,
			'm'  => 100.0,
		];

		/** @var array<string, mixed> stored options by name */
		private array $options = [];

		protected function setUp(): void {
			parent::setUp();

			$this->options = [];

			Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);

			Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
			Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/**
		 * A store on the given units, with the given stored defaults (in those units).
		 *
		 * @param array<string, mixed> $defaults `length`/`width`/`height`/`weight` => stored value.
		 */
		private function store( string $weight_unit, string $dimension_unit, array $defaults = [] ): void {
			$this->options['woocommerce_weight_unit']    = $weight_unit;
			$this->options['woocommerce_dimension_unit'] = $dimension_unit;

			foreach ( $defaults as $key => $value ) {
				$this->options[ 'woodev_default_dimensions_default_' . $key ] = $value;
			}

			Shipping_Settings_Tab::reset_for_tests();

			Functions\when( 'wc_get_weight' )->alias(
				static fn( $weight, $to, $from = '' ) => (float) $weight * self::WEIGHT_TO_KG[ '' === $from ? $weight_unit : $from ] / self::WEIGHT_TO_KG[ $to ]
			);
			Functions\when( 'wc_get_dimension' )->alias(
				static fn( $dimension, $to, $from = '' ) => (float) $dimension * self::DIMENSION_TO_CM[ '' === $from ? $dimension_unit : $from ] / self::DIMENSION_TO_CM[ $to ]
			);
		}

		/** @param string|float $length raw product value, '' when unset. */
		private function product( $length, $width, $height, $weight ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'is_virtual' )->andReturn( false );
			$product->shouldReceive( 'get_length' )->andReturn( $length );
			$product->shouldReceive( 'get_width' )->andReturn( $width );
			$product->shouldReceive( 'get_height' )->andReturn( $height );
			$product->shouldReceive( 'get_weight' )->andReturn( $weight );

			return $product;
		}

		/** @return \Woodev_Packer_Input_Item */
		private function pack_one( \WC_Product $product ): \Woodev_Packer_Input_Item {
			return \Woodev_WC_Packer_Dispatcher::from_cart_items( [ [ 'data' => $product, 'quantity' => 1 ] ] )[0];
		}

		/**
		 * A merchant who never opens the section: the registered default (10 cm, 100 g) fills a bare product.
		 */
		public function test_the_builtin_default_fills_a_bare_product_when_nothing_was_saved(): void {
			$this->store( 'kg', 'cm' );

			$item = $this->pack_one( $this->product( '', '', '', '' ) );

			$this->assertEqualsWithDelta( [ 10.0, 10.0, 10.0, 0.1 ], [ $item->get_length(), $item->get_width(), $item->get_height(), $item->get_weight() ], 0.0001 );
		}

		/**
		 * The registered default is the built-in one CONVERTED to the store's units, and rounded.
		 *
		 * @return array<string, array{0: string, 1: string, 2: float, 3: float}>
		 */
		public static function builtin_default_units_provider(): array {
			// weight unit, dimension unit => expected weight default, expected size default
			return [
				'kg / cm'  => [ 'kg', 'cm', 0.1, 10.0 ],
				'g / mm'   => [ 'g', 'mm', 100.0, 100.0 ],
				'lbs / in' => [ 'lbs', 'in', 0.22, 3.94 ],
				'kg / m'   => [ 'kg', 'm', 0.1, 0.1 ],
			];
		}

		/**
		 * @dataProvider builtin_default_units_provider
		 */
		public function test_the_registered_default_is_the_builtin_one_in_store_units( string $weight_unit, string $dimension_unit, float $weight, float $size ): void {
			$this->store( $weight_unit, $dimension_unit );

			$handler = Default_Dimensions_Settings::current();

			$this->assertSame( $weight, $handler->get_setting( 'default_weight' )->get_default(), 'weight, rounded: no 0.2204622622' );
			foreach ( [ 'default_length', 'default_width', 'default_height' ] as $id ) {
				$this->assertSame( $size, $handler->get_setting( $id )->get_default() );
			}
			$this->assertSame( $weight, $handler->get_fallback( 'weight' ), 'nothing saved: the default is the effective value' );
		}

		public function test_a_product_with_all_its_own_values_ignores_the_defaults(): void {
			$this->store( 'kg', 'cm', [ 'length' => '99', 'width' => '99', 'height' => '99', 'weight' => '99' ] );

			$item = $this->pack_one( $this->product( '30', '20', '10', '2.5' ) );

			$this->assertEqualsWithDelta( 30.0, $item->get_length(), 0.0001 );
			$this->assertEqualsWithDelta( 20.0, $item->get_width(), 0.0001 );
			$this->assertEqualsWithDelta( 10.0, $item->get_height(), 0.0001 );
			$this->assertEqualsWithDelta( 2.5, $item->get_weight(), 0.0001 );
		}

		/**
		 * Each value is decided on its own: the default fills ONLY the missing ones.
		 *
		 * @return array<string, array{0: array<int, string>, 1: array<int, float>}>
		 */
		public static function partial_products_provider(): array {
			// own [l, w, h, kg]  =>  expected [l, w, h, kg] with defaults 11 / 12 / 13 / 4
			return [
				'only length missing' => [ [ '', '20', '10', '2' ], [ 11.0, 20.0, 10.0, 2.0 ] ],
				'only width missing'  => [ [ '30', '', '10', '2' ], [ 30.0, 12.0, 10.0, 2.0 ] ],
				'only height missing' => [ [ '30', '20', '', '2' ], [ 30.0, 20.0, 13.0, 2.0 ] ],
				'only weight missing' => [ [ '30', '20', '10', '' ], [ 30.0, 20.0, 10.0, 4.0 ] ],
				'size missing, has weight' => [ [ '', '', '', '2' ], [ 11.0, 12.0, 13.0, 2.0 ] ],
				'everything missing'  => [ [ '', '', '', '' ], [ 11.0, 12.0, 13.0, 4.0 ] ],
			];
		}

		/**
		 * @dataProvider partial_products_provider
		 *
		 * @param array<int, string> $own      the product's own values.
		 * @param array<int, float>  $expected the packed values.
		 */
		public function test_the_default_fills_only_the_missing_values( array $own, array $expected ): void {
			$this->store( 'kg', 'cm', [ 'length' => '11', 'width' => '12', 'height' => '13', 'weight' => '4' ] );

			$item = $this->pack_one( $this->product( ...$own ) );

			$this->assertEqualsWithDelta( $expected, [ $item->get_length(), $item->get_width(), $item->get_height(), $item->get_weight() ], 0.0001 );
		}

		/**
		 * A saved value beats the built-in default, per value.
		 */
		public function test_a_saved_value_replaces_only_its_own_builtin_default(): void {
			$this->store( 'kg', 'cm', [ 'weight' => '4' ] );

			$item = $this->pack_one( $this->product( '', '', '', '' ) );

			$this->assertSame( [ 10.0, 10.0, 10.0 ], [ $item->get_length(), $item->get_width(), $item->get_height() ] );
			$this->assertEqualsWithDelta( 4.0, $item->get_weight(), 0.0001 );
		}

		/**
		 * «Missing» includes a stored zero: such a product ships as the same zero-size parcel.
		 */
		public function test_a_zero_product_value_counts_as_missing(): void {
			$this->store( 'kg', 'cm', [ 'length' => '11', 'weight' => '4' ] );

			$item = $this->pack_one( $this->product( '0', '5', '5', '0' ) );

			$this->assertEqualsWithDelta( 11.0, $item->get_length(), 0.0001 );
			$this->assertEqualsWithDelta( 5.0, $item->get_width(), 0.0001 );
			$this->assertEqualsWithDelta( 4.0, $item->get_weight(), 0.0001 );
		}

		/**
		 * A stored value that is not positive (written past the validation) never reaches the packer as a
		 * zero parcel: the effective value falls back to the registered default.
		 */
		public function test_a_non_positive_or_non_numeric_stored_value_falls_back_to_the_registered_default(): void {
			$this->store( 'kg', 'cm', [ 'length' => '0', 'width' => '-3', 'height' => 'abc', 'weight' => '' ] );

			$item = $this->pack_one( $this->product( '', '', '', '' ) );

			$this->assertEqualsWithDelta( [ 10.0, 10.0, 10.0, 0.1 ], [ $item->get_length(), $item->get_width(), $item->get_height(), $item->get_weight() ], 0.0001 );
		}

		/**
		 * The default is typed in the STORE's units and converted exactly like a product's own value.
		 */
		public function test_the_default_is_in_store_units_and_converted_to_cm_and_kg(): void {
			// 300 mm x 200 mm x 100 mm, 2500 g  ==  30 x 20 x 10 cm, 2.5 kg
			$this->store( 'g', 'mm', [ 'length' => '300', 'width' => '200', 'height' => '100', 'weight' => '2500' ] );

			$item = $this->pack_one( $this->product( '', '', '', '' ) );

			$this->assertEqualsWithDelta( 30.0, $item->get_length(), 0.0001 );
			$this->assertEqualsWithDelta( 20.0, $item->get_width(), 0.0001 );
			$this->assertEqualsWithDelta( 10.0, $item->get_height(), 0.0001 );
			$this->assertEqualsWithDelta( 2.5, $item->get_weight(), 0.0001 );
		}

		public function test_the_fallback_reaches_the_order_converter_too(): void {
			$this->store( 'kg', 'cm', [ 'length' => '11', 'width' => '12', 'height' => '13', 'weight' => '4' ] );

			$order_item = Mockery::mock( '\WC_Order_Item_Product' );
			$order_item->shouldReceive( 'get_product' )->andReturn( $this->product( '', '', '', '' ) );
			$order_item->shouldReceive( 'get_quantity' )->andReturn( 1 );
			$order_item->shouldReceive( 'get_id' )->andReturn( 1 );
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_items' )->andReturn( [ $order_item ] );
			$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturn( 0 );

			$item = \Woodev_WC_Packer_Dispatcher::from_order_items( $order )[0];

			$this->assertEqualsWithDelta( [ 11.0, 12.0, 13.0, 4.0 ], [ $item->get_length(), $item->get_width(), $item->get_height(), $item->get_weight() ], 0.0001 );
		}

		public function test_the_effective_values_are_in_store_units(): void {
			$this->store( 'g', 'mm', [ 'length' => '300', 'weight' => '2500' ] );

			$this->assertSame(
				[ 'length' => 300.0, 'width' => 7.0, 'height' => 100.0, 'weight' => 2500.0 ],
				\Woodev_WC_Packer_Dispatcher::get_effective_values( $this->product( '', '7', '', '' ) )
			);
		}

		// -----------------------------------------------------------------
		// The settings handler itself
		// -----------------------------------------------------------------

		public function test_the_handler_owns_four_settings_stored_under_their_own_namespace(): void {
			$this->store( 'kg', 'cm', [ 'length' => '11' ] );

			$handler = Default_Dimensions_Settings::current();

			$this->assertSame( [ 'default_length', 'default_width', 'default_height', 'default_weight' ], $handler->get_owned_setting_ids() );
			$this->assertSame( 11.0, $handler->get_fallback( 'length' ) );
			$this->assertSame( 10.0, $handler->get_fallback( 'width' ), 'nothing saved: the registered default' );
			$this->assertSame( 0.0, $handler->get_fallback( 'nonsense' ) );
			$this->assertSame( $handler, Default_Dimensions_Settings::current(), 'one instance, the one the admin screen edits' );
		}

		/**
		 * The label names the STORE's unit, read from the WooCommerce options — not hard-coded g / cm.
		 */
		public function test_labels_show_the_store_units(): void {
			$this->store( 'lbs', 'in' );

			$handler = Default_Dimensions_Settings::current();

			$this->assertStringContainsString( ', in', $handler->get_setting( 'default_length' )->get_name() );
			$this->assertStringContainsString( ', in', $handler->get_setting( 'default_height' )->get_name() );
			$this->assertStringContainsString( ', lbs', $handler->get_setting( 'default_weight' )->get_name() );
			$this->assertStringNotContainsString( 'см', $handler->get_setting( 'default_length' )->get_name() );
		}

		/**
		 * The four fields are required, and strictly positive: empty, zero, negative and non-numeric are refused.
		 *
		 * @return array<string, array{0: string}>
		 */
		public static function dimension_setting_ids_provider(): array {
			return [
				'length' => [ 'default_length' ],
				'width'  => [ 'default_width' ],
				'height' => [ 'default_height' ],
				'weight' => [ 'default_weight' ],
			];
		}

		/**
		 * @dataProvider dimension_setting_ids_provider
		 */
		public function test_each_setting_is_required_and_must_be_positive( string $id ): void {
			$this->store( 'kg', 'cm' );

			$setting = Default_Dimensions_Settings::current()->get_setting( $id );

			$this->assertTrue( $setting->is_required() );
			$this->assertNotNull( $setting->get_validation_error( '' ), 'empty is refused' );
			$this->assertNotNull( $setting->get_validation_error( '0' ), 'zero is refused' );
			$this->assertNotNull( $setting->get_validation_error( 0 ), 'numeric zero is refused' );
			$this->assertNotNull( $setting->get_validation_error( '-1' ), 'a negative size is refused' );
			$this->assertNotNull( $setting->get_validation_error( 'abc' ), 'non-numeric is refused' );
			$this->assertNull( $setting->get_validation_error( '12.5' ) );
			$this->assertNull( $setting->get_validation_error( '0.05' ), 'a small positive value is fine' );
		}

		/**
		 * The four fields — and only they — ask the browser to enforce the minimum: the schema carries
		 * `native_bounds` together with min / step, so the DOM `<input type=number>` refuses 0 and -1.
		 */
		public function test_the_schema_of_the_four_fields_opts_in_to_browser_side_bounds(): void {
			$this->store( 'kg', 'cm' );
			Functions\when( 'is_wp_error' )->justReturn( false );

			$handler = Default_Dimensions_Settings::current();
			$schema  = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler, $handler->get_owned_setting_ids() );

			$this->assertCount( 4, $schema );
			foreach ( $schema as $entry ) {
				$this->assertSame( 'number', $entry['controlType'] );
				$this->assertTrue( $entry['native_bounds'] );
				$this->assertSame( 0.01, $entry['min'] );
				$this->assertSame( 0.01, $entry['step'] );
				$this->assertTrue( $entry['required'] );
			}
		}
	}
}
