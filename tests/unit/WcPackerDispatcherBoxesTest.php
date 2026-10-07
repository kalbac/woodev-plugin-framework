<?php
/**
 * #1138: the store's list of boxes («Доставка» → «Коробки») and the WC dispatcher's `boxes` algorithm.
 *
 * - Boxes_Settings parses the textarea (one box per line, decimal commas, optional weights) and refuses a list
 *   with a broken line on save.
 * - Woodev_WC_Packer_Dispatcher reads the list in the STORE's units, hands it to the packer in cm / kg, and
 *   reports which cart / order lines went into which box.
 *
 * `wc_get_weight()` / `wc_get_dimension()` are faithful fakes of WooCommerce's conversion (see
 * WcPackerDispatcherDefaultsTest); the stored list is served through a `get_option` fake keyed by the real option
 * name (`woodev_boxes_boxes`).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class WcPackerDispatcherBoxesTest_WC_Product_Stub {}
		class_alias( WcPackerDispatcherBoxesTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-boxes.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-package-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Settings\Boxes_Settings;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;

	/**
	 * @covers \Woodev_WC_Packer_Dispatcher
	 * @covers \Woodev\Framework\Shipping\Settings\Boxes_Settings
	 */
	class WcPackerDispatcherBoxesTest extends TestCase {

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

			Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
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
		 * A store on the given units, with the given list of boxes saved (in those units).
		 */
		private function store( string $weight_unit, string $dimension_unit, string $boxes = '' ): void {
			$this->options['woocommerce_weight_unit']    = $weight_unit;
			$this->options['woocommerce_dimension_unit'] = $dimension_unit;
			$this->options['woodev_boxes_boxes']         = $boxes;

			Shipping_Settings_Tab::reset_for_tests();

			Functions\when( 'wc_get_weight' )->alias(
				static fn( $weight, $to, $from = '' ) => (float) $weight * self::WEIGHT_TO_KG[ '' === $from ? $weight_unit : $from ] / self::WEIGHT_TO_KG[ $to ]
			);
			Functions\when( 'wc_get_dimension' )->alias(
				static fn( $dimension, $to, $from = '' ) => (float) $dimension * self::DIMENSION_TO_CM[ '' === $from ? $dimension_unit : $from ] / self::DIMENSION_TO_CM[ $to ]
			);
		}

		private function product( string $length, string $width, string $height, string $weight ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'is_virtual' )->andReturn( false );
			$product->shouldReceive( 'needs_shipping' )->andReturn( true );
			$product->shouldReceive( 'get_length' )->andReturn( $length );
			$product->shouldReceive( 'get_width' )->andReturn( $width );
			$product->shouldReceive( 'get_height' )->andReturn( $height );
			$product->shouldReceive( 'get_weight' )->andReturn( $weight );

			return $product;
		}

		// -----------------------------------------------------------------
		// Boxes_Settings: the list text
		// -----------------------------------------------------------------

		public function test_a_line_is_name_three_sizes_and_two_optional_weights(): void {
			$this->assertSame(
				[
					'name'       => 'Small',
					'length'     => 20.0,
					'width'      => 15.0,
					'height'     => 10.0,
					'max_weight' => 2.0,
					'box_weight' => 0.1,
					'cost' => '',
					'enabled' => true,
				],
				Boxes_Settings::parse_line( 'Small; 20; 15; 10; 2; 0.1' )
			);
		}

		public function test_the_weights_are_optional_and_mean_no_limit_and_no_tare(): void {
			$only_size = Boxes_Settings::parse_line( 'Small; 20; 15; 10' );
			$empty     = Boxes_Settings::parse_line( 'Small; 20; 15; 10; ; ' );

			$this->assertSame( [ 0.0, 0.0 ], [ $only_size['max_weight'], $only_size['box_weight'] ] );
			$this->assertSame( [ 0.0, 0.0 ], [ $empty['max_weight'], $empty['box_weight'] ] );
		}

		public function test_decimal_commas_and_stray_spaces_are_accepted(): void {
			$box = Boxes_Settings::parse_line( '  Коробка «М» ;  20,5 ;15 ;  10,25; 1,5 ;0,05 ' );

			$this->assertSame( 'Коробка «М»', $box['name'] );
			$this->assertSame( [ 20.5, 15.0, 10.25, 1.5, 0.05 ], [ $box['length'], $box['width'], $box['height'], $box['max_weight'], $box['box_weight'] ] );
		}

		/**
		 * @return array<string, array{0: string}>
		 */
		public static function broken_lines_provider(): array {
			return [
				'no fields'        => [ 'Small' ],
				'three fields'     => [ 'Small; 20; 15' ],
				'nine fields'     => [ 'Small; 20; 15; 10; 2; 0.1; 9; yes; extra' ],
				'no name'          => [ ' ; 20; 15; 10' ],
				'a tag for a name' => [ '<b></b>; 20; 15; 10' ],
				'zero length'      => [ 'Small; 0; 15; 10' ],
				'negative width'   => [ 'Small; 20; -15; 10' ],
				'text height'      => [ 'Small; 20; 15; tall' ],
				'text max weight'  => [ 'Small; 20; 15; 10; heavy' ],
				'negative tare'    => [ 'Small; 20; 15; 10; 2; -0.1' ],
			];
		}

		/**
		 * @dataProvider broken_lines_provider
		 */
		public function test_a_broken_line_is_no_box_and_refuses_the_list( string $line ): void {
			$this->assertNull( Boxes_Settings::parse_line( $line ) );
			$this->assertFalse( Boxes_Settings::is_valid_list( "Good; 10; 10; 10\n" . $line ) );
			$this->assertSame( 1, count( Boxes_Settings::parse( "Good; 10; 10; 10\n" . $line ) ), 'read around the control, the broken line is skipped' );
		}

		public function test_the_list_skips_blank_lines_keeps_order_and_numbers_the_boxes(): void {
			$boxes = Boxes_Settings::parse( "Small; 20; 15; 10\r\n\r\n  \nBig; 40; 30; 20; 5\n" );

			$this->assertSame( [ 'box-1', 'box-2' ], array_column( $boxes, 'id' ) );
			$this->assertSame( [ 'Small', 'Big' ], array_column( $boxes, 'name' ) );
			$this->assertSame( 5.0, $boxes[1]['max_weight'] );
		}

		public function test_an_empty_list_is_valid_and_has_no_boxes(): void {
			$this->assertTrue( Boxes_Settings::is_valid_list( '' ) );
			$this->assertTrue( Boxes_Settings::is_valid_list( "\n  \n" ) );
			$this->assertSame( [], Boxes_Settings::parse( '' ) );
			$this->assertFalse( Boxes_Settings::is_valid_list( [ 'not text' ] ) );
		}

		public function test_the_handler_owns_one_optional_setting_that_refuses_a_broken_list(): void {
			$this->store( 'kg', 'cm' );

			$handler = Boxes_Settings::current();
			$setting = $handler->get_setting( 'boxes' );

			$this->assertSame( [ 'boxes' ], $handler->get_owned_setting_ids() );
			$this->assertSame( $handler, Boxes_Settings::current(), 'one instance, the one the admin screen edits' );
			$this->assertNull( $setting->get_validation_error( '' ), 'optional: no boxes is a valid state' );
			$this->assertNull( $setting->get_validation_error( "Small; 20; 15; 10; 2; 0.1\nBig; 40; 30; 20" ) );
			$this->assertNotNull( $setting->get_validation_error( "Small; 20; 15; 10\nBroken" ) );
		}

		public function test_the_control_names_the_store_units(): void {
			$this->store( 'lbs', 'in' );

			$tooltip = Boxes_Settings::current()->get_setting( 'boxes' )->get_control()->get_tooltip();

			$this->assertStringContainsString( 'сантиметрах', $tooltip );
			$this->assertStringContainsString( 'килограммах', $tooltip );
		}

		public function test_table_control_carries_conversion_factors_and_keeps_legacy_storage(): void {
			$this->store( 'g', 'mm', 'Small; 200; 150; 100; 2000; 100' );
			$schema = \Woodev\Framework\Settings\Field_Schema::from_handler( Boxes_Settings::current() )['boxes'];
			$this->assertSame( 'boxes-table', $schema['controlType'] );
			$this->assertSame( 0.1, $schema['dimension_factor'] );
			$this->assertSame( 0.001, $schema['weight_factor'] );
			$this->assertSame( 'Small; 200; 150; 100; 2000; 100', $schema['value'] );
		}

		public function test_saving_sanitizes_names_and_normalizes_decimals_without_changing_shape(): void {
			$this->store( 'kg', 'cm' );
			Functions\when( 'update_option' )->alias( function ( $key, $value ) { $this->options[ $key ] = $value; return true; } );
			Boxes_Settings::current()->update_value( 'boxes', " <b>Small</b>; 20,5; 15; 10; 2; 0,1\n\nBig; 40; 30; 20" );
			$this->assertSame( "Small; 20.5; 15; 10; 2; 0.1; ; yes\nBig; 40; 30; 20; 0; 0; ; yes", $this->options['woodev_boxes_boxes'] );
			$this->assertSame( 'Small', Boxes_Settings::current()->get_boxes()[0]['name'] );
		}

		public function test_invalid_table_rows_are_rejected_without_writing_anything(): void {
			$this->store( 'kg', 'cm' );
			Functions\expect( 'update_option' )->never();
			$this->expectException( \Woodev_Plugin_Exception::class );
			Boxes_Settings::current()->update_value( 'boxes', "Good; 20; 15; 10\nBroken; 0; 1; 1" );
		}

		public function test_overflow_dimensions_are_not_valid_boxes(): void {
			$this->assertFalse( Boxes_Settings::is_valid_list( 'Box; 1e999; 2; 3' ) );
		}

		public function test_the_saved_list_is_what_get_boxes_returns(): void {
			$this->store( 'kg', 'cm', "Small; 20; 15; 10; 2; 0.1\nBig; 40; 30; 20" );

			$boxes = Boxes_Settings::current()->get_boxes();

			$this->assertSame( [ 'Small', 'Big' ], array_column( $boxes, 'name' ) );
		}

		// -----------------------------------------------------------------
		// The WC dispatcher: the store's boxes, in store units
		// -----------------------------------------------------------------

		public function test_the_store_boxes_are_converted_to_cm_and_kg(): void {
			// 200 x 150 x 100 mm, holds 2000 g, weighs 100 g
			$this->store( 'g', 'mm', 'Small; 200; 150; 100; 2000; 100' );

			$boxes = \Woodev_WC_Packer_Dispatcher::get_store_boxes();

			$this->assertCount( 1, $boxes );
			$this->assertInstanceOf( \Woodev_Box_Packer_Box::class, $boxes[0] );
			$this->assertEqualsWithDelta( [ 20.0, 15.0, 10.0 ], [ $boxes[0]->get_length(), $boxes[0]->get_width(), $boxes[0]->get_height() ], 0.0001 );
			$this->assertEqualsWithDelta( 2.0, $boxes[0]->get_max_weight(), 0.0001 );
			$this->assertEqualsWithDelta( 0.1, $boxes[0]->get_weight(), 0.0001 );
			$this->assertSame( 'box-1', $boxes[0]->get_unique_id() );
			$this->assertSame( 'Small', $boxes[0]->get_name() );
		}

		public function test_a_box_without_a_max_weight_has_no_limit(): void {
			$this->store( 'kg', 'cm', 'Small; 20; 15; 10' );

			$this->assertNull( \Woodev_WC_Packer_Dispatcher::get_store_boxes()[0]->get_max_weight() );
		}

		public function test_no_saved_list_means_no_boxes(): void {
			$this->store( 'kg', 'cm' );

			$this->assertSame( [], \Woodev_WC_Packer_Dispatcher::get_store_boxes() );
		}

		public function test_the_boxes_algorithm_packs_into_the_store_list_without_being_given_it(): void {
			$this->store( 'g', 'mm', "Small; 200; 150; 100; 2000; 100\nBig; 400; 300; 200" );

			$items  = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[
					'hash-a' => [ 'key' => 'hash-a', 'product_id' => 7, 'variation_id' => 0, 'quantity' => 2, 'data' => $this->product( '10', '8', '5', '0.3' ) ],
				]
			);
			$result = \Woodev_WC_Packer_Dispatcher::pack( 'boxes', $items );

			$this->assertSame( 'boxes', $result->get_algorithm() );
			$this->assertSame( 1, $result->get_package_count() );

			$package = $result->get_packages()[0];

			// the product is 10 x 8 x 5 MILLIMETRES (store units): the smallest box holds both units, and is 20 x 15 x 10 cm
			$this->assertSame( 'box-1', $package->get_box_id() );
			$this->assertEqualsWithDelta( [ 20.0, 15.0, 10.0 ], [ $package->get_length(), $package->get_width(), $package->get_height() ], 0.0001 );
		}

		public function test_boxes_given_explicitly_win_over_the_store_list(): void {
			$this->store( 'kg', 'cm', 'Small; 20; 15; 10' );

			$items = [ new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1, 'a', 7 ) ];
			$mine  = new \Woodev_Packer_Box_Implementation( 50, 40, 30, 0.5, null, 'mine', 'Mine' );

			$result = \Woodev_WC_Packer_Dispatcher::pack( 'boxes', $items, [ $mine ] );

			$this->assertSame( 'mine', $result->get_packages()[0]->get_box_id() );
		}

		public function test_an_empty_store_list_packs_every_unit_separately_rather_than_dropping_it(): void {
			$this->store( 'kg', 'cm' );

			$items  = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[
					'hash-a' => [ 'key' => 'hash-a', 'product_id' => 7, 'variation_id' => 0, 'quantity' => 3, 'data' => $this->product( '10', '8', '5', '0.3' ) ],
				]
			);
			$result = \Woodev_WC_Packer_Dispatcher::pack( 'boxes', $items );

			$this->assertSame( 3, $result->get_package_count() );
			$this->assertSame( 3, array_sum( array_map( static fn( $p ) => $p->get_items()[0]['quantity'], $result->get_packages() ) ) );
		}

		public function test_the_other_algorithms_do_not_read_the_store_list(): void {
			$this->store( 'kg', 'cm', 'Small; 20; 15; 10' );

			$items  = [ new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1, 'a', 7 ) ];
			$result = \Woodev_WC_Packer_Dispatcher::pack( 'virtual', $items );

			$this->assertSame( '', $result->get_packages()[0]->get_box_id() );
		}

		// -----------------------------------------------------------------
		// Which cart / order line went into which box
		// -----------------------------------------------------------------

		public function test_cart_lines_carry_their_key_and_the_variation_through_to_the_allocation(): void {
			$this->store( 'kg', 'cm', 'Small; 20; 15; 10' );

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[
					'hash-simple'    => [ 'key' => 'hash-simple', 'product_id' => 7, 'variation_id' => 0, 'quantity' => 1, 'data' => $this->product( '10', '8', '5', '0.3' ) ],
					'hash-variation' => [ 'key' => 'hash-variation', 'product_id' => 8, 'variation_id' => 81, 'quantity' => 2, 'data' => $this->product( '5', '5', '5', '0.1' ) ],
				]
			);

			$this->assertSame( 'hash-simple', $items[0]->get_key() );
			$this->assertSame( 7, $items[0]->get_product_id() );
			$this->assertSame( 81, $items[1]->get_product_id(), 'the variation, when there is one' );

			$result = \Woodev_WC_Packer_Dispatcher::pack( 'boxes', $items );

			$this->assertSame(
				[
					[ 'key' => 'hash-simple', 'product_id' => 7, 'quantity' => 1 ],
					[ 'key' => 'hash-variation', 'product_id' => 81, 'quantity' => 2 ],
				],
				$result->get_packages()[0]->get_items()
			);
		}

		public function test_a_cart_line_without_its_own_key_is_keyed_by_its_array_key(): void {
			$this->store( 'kg', 'cm' );

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items(
				[ 'array-key' => [ 'product_id' => 7, 'quantity' => 1, 'data' => $this->product( '10', '8', '5', '0.3' ) ] ]
			);

			$this->assertSame( 'array-key', $items[0]->get_key() );
		}

		public function test_an_order_is_reported_by_its_order_item_ids(): void {
			$this->store( 'kg', 'cm' );

			$order_item = Mockery::mock( '\WC_Order_Item_Product' );
			$order_item->shouldReceive( 'get_product' )->andReturn( $this->product( '10', '8', '5', '0.3' ) );
			$order_item->shouldReceive( 'get_quantity' )->andReturn( 2 );
			$order_item->shouldReceive( 'get_id' )->andReturn( 123 );
			$order_item->shouldReceive( 'get_product_id' )->andReturn( 7 );
			$order_item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_items' )->andReturn( [ $order_item ] );
			$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturn( 0 );

			$result = \Woodev_WC_Packer_Dispatcher::pack( 'separately', \Woodev_WC_Packer_Dispatcher::from_order_items( $order ) );

			$this->assertSame( 2, $result->get_package_count() );
			$this->assertSame( [ [ 'key' => '123', 'product_id' => 7, 'quantity' => 1 ] ], $result->get_packages()[1]->get_items() );
		}
	}
}
