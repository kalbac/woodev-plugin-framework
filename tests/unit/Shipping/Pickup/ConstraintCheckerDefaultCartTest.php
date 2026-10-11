<?php
/**
 * Constraint_Checker's storage-cell check through its DEFAULT supplier — the live WooCommerce cart (issue #1215).
 *
 * The shipping framework fills a product's missing size with the store's default for rate calculation; for the
 * locker check a size nobody entered is unknown, so it must never refuse a locker. These tests go through the
 * real packer dispatcher and the real default-dimensions settings class, which an injected supplier cannot.
 *
 * `WC()` cannot be undefined again once Patchwork has defined it (gotcha `brain-monkey-function-pollution`), so
 * every test runs in a separate process.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class ConstraintCheckerDefaultCartTest_WC_Product_Stub {}
		class_alias( ConstraintCheckerDefaultCartTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-packer-free-space.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit\Shipping\Pickup {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Pickup\Constraint_Checker
	 */
	final class ConstraintCheckerDefaultCartTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// nothing saved: the store's built-in default (10 cm, 100 g) is what the dispatcher would substitute
			Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => 'woocommerce_dimension_unit' === $name ? 'cm' : ( 'woocommerce_weight_unit' === $name ? 'kg' : $default ) );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'number_format_i18n' )->alias( static fn( $number, $decimals = 0 ) => number_format( (float) $number, $decimals ) );
			Functions\when( 'wc_get_dimension' )->alias( static fn( $dimension, $to, $from = '' ) => (float) $dimension );
			Functions\when( 'wc_get_weight' )->alias( static fn( $weight, $to, $from = '' ) => (float) $weight );

			Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
			Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/** @param string|float $length raw product values, '' when unset. */
		private function product( $length, $width, $height, bool $virtual = false ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'is_virtual' )->andReturn( $virtual );
			$product->shouldReceive( 'get_length' )->andReturn( $length );
			$product->shouldReceive( 'get_width' )->andReturn( $width );
			$product->shouldReceive( 'get_height' )->andReturn( $height );
			$product->shouldReceive( 'get_weight' )->andReturn( '0.5' );

			return $product;
		}

		/** @param array<int, array{0: \WC_Product, 1: int}> $lines product, quantity. */
		private function cart( array $lines ): void {
			$contents = [];

			foreach ( $lines as $index => [ $product, $quantity ] ) {
				$contents[ 'line' . $index ] = [ 'data' => $product, 'quantity' => $quantity ];
			}

			$cart = new class( $contents ) {
				private array $contents;

				public function __construct( array $contents ) {
					$this->contents = $contents;
				}

				public function get_cart(): array {
					return $this->contents;
				}
			};

			Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );
		}

		private function verdict( int $cell ): array {
			$point = Pickup_Point::from_array(
				[
					'id'      => 'L1',
					'name'    => 'Постамат',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Москва',
					'type'    => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ],
					'cells'   => [ [ 'length' => $cell, 'width' => $cell, 'height' => $cell ] ],
				]
			);

			return ( new Constraint_Checker() )->check( $point, 'bacs', 0 );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_a_product_without_dimensions_never_refuses_a_locker(): void {
			// the store default 10 x 10 x 10 twice would not fit a 10 cm cube; the real products are of unknown size
			$this->cart( [ [ $this->product( '', '', '' ), 2 ] ] );

			$verdict = $this->verdict( 10 );

			$this->assertTrue( $verdict['allowed'] );
			$this->assertNull( $verdict['reason'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_one_missing_side_is_enough_to_make_the_size_unknown(): void {
			foreach ( [ [ '', '8', '8' ], [ '8', '0', '8' ], [ '8', '8', 'n/a' ] ] as $sides ) {
				$this->cart( [ [ $this->product( ...$sides ), 3 ] ] );

				$this->assertTrue( $this->verdict( 10 )['allowed'], implode( ' x ', $sides ) );
			}
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_one_unsized_line_makes_the_whole_order_unknown(): void {
			$this->cart( [ [ $this->product( '30', '30', '30' ), 1 ], [ $this->product( '', '', '' ), 1 ] ] );

			$this->assertTrue( $this->verdict( 10 )['allowed'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_a_virtual_product_without_dimensions_does_not_hide_a_proven_refusal(): void {
			$this->cart( [ [ $this->product( '30', '30', '30' ), 1 ], [ $this->product( '', '', '', true ), 1 ] ] );

			$verdict = $this->verdict( 10 );

			$this->assertFalse( $verdict['allowed'] );
			$this->assertSame( 'The order does not fit the cells of this parcel locker', $verdict['reason'] );
		}

		/**
		 * The control: through the same default supplier, products that DO have their sizes are still judged.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_sized_products_are_still_judged_through_the_cart(): void {
			$this->cart( [ [ $this->product( '6', '6', '6' ), 2 ] ] );
			$this->assertFalse( $this->verdict( 10 )['allowed'], 'two 6 cm cubes do not fit a 10 cm cell' );

			$this->cart( [ [ $this->product( '6', '6', '6' ), 1 ] ] );
			$this->assertTrue( $this->verdict( 10 )['allowed'] );
		}
	}
}
