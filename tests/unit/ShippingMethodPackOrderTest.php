<?php
/**
 * Tests for Shipping_Method::pack_order() (#948): an ORDER is packed at export time with the same
 * packer step the rate used at checkout, so the parcels of the rate and of the order cannot diverge.
 *
 * The method instance is built with newInstanceWithoutConstructor() (the real constructor needs WC
 * plugin wiring); products, order lines and the order are Mockery doubles.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/** Empty WC_Shipping_Method double: the tested code never reaches WooCommerce's own methods. */
		class ShippingMethodPackOrderTest_WC_Shipping_Method_Stub {}
		class_alias( ShippingMethodPackOrderTest_WC_Shipping_Method_Stub::class, 'WC_Shipping_Method' );
	}

	if ( ! class_exists( '\WC_Product', false ) ) {
		class ShippingMethodPackOrderTest_WC_Product_Stub {}
		class_alias( ShippingMethodPackOrderTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-free-space.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-package-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-method.php';

	/**
	 * Concrete method: options and the box-packing capability are plain public fields.
	 */
	class ShippingMethodPackOrderTest_Method extends \Woodev\Framework\Shipping\Shipping_Method {

		/** @var array<string,mixed> */
		public array $stored_options = [];

		/** @var bool */
		public bool $box_packing = true;

		public static function get_method_id(): string {
			return 'pack_order_test_method';
		}

		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		protected function get_method_form_fields(): array {
			return [];
		}

		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \LogicException( 'not needed for the pack_order tests' );
		}

		public function get_option( $key, $empty_value = null ) {
			return $this->stored_options[ $key ] ?? $empty_value;
		}

		public function supports_box_packing(): bool {
			return $this->box_packing;
		}

		/** Exposes the rate-time entry (protected on the base class). */
		public function pack_cart_package( array $package ): ?\Woodev_Packer_Result {
			return $this->pack_package( $package );
		}
	}

	/**
	 * A carrier that customizes parcels by overriding the pack_package() seam: it records the package
	 * it was handed and swaps the result for a fixed one.
	 */
	class ShippingMethodPackOrderTest_OverridingMethod extends ShippingMethodPackOrderTest_Method {

		/** @var array<string,mixed>|null */
		public ?array $seen_package = null;

		/** @var \Woodev_Packer_Result|null what the override returns */
		public ?\Woodev_Packer_Result $override_result = null;

		protected function pack_package( array $package ): ?\Woodev_Packer_Result {
			$this->seen_package = $package;

			return $this->override_result;
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	class ShippingMethodPackOrderTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// a kg / cm store: the identity conversion (units are #950's tests, not these)
			Functions\when( 'wc_get_dimension' )->returnArg( 1 );
			Functions\when( 'wc_get_weight' )->returnArg( 1 );

			// the default dimensions (#955) are a settings handler: nothing stored, so the built-in 10 cm / 100 g
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

		private function method( string $algorithm = \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY, string $class = \ShippingMethodPackOrderTest_Method::class ): \ShippingMethodPackOrderTest_Method {
			$method                 = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			$method->stored_options = [ 'packing_algorithm' => $algorithm ];

			return $method;
		}

		/**
		 * A result as its parcels — the sizes, weights and counts, WITHOUT the item allocation: a rate's cart
		 * keys and an order's item ids name the same lines differently (#1138), the parcels must still agree.
		 *
		 * @return array<string, mixed>
		 */
		private function parcels( \Woodev_Packer_Result $result ): array {
			$data = $result->to_array();

			foreach ( $data['packages'] as $index => $package ) {
				unset( $data['packages'][ $index ]['items'] );
			}

			return $data;
		}

		/**
		 * A product double. Numbers (0 = «not set») rather than '' — the dispatcher treats both as missing.
		 */
		private function product( float $length, float $width, float $height, float $weight, bool $virtual = false, ?bool $needs_shipping = null ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'is_virtual' )->andReturn( $virtual );
			// WC: a virtual product needs no shipping; a filter can also switch a physical one off
			$product->shouldReceive( 'needs_shipping' )->andReturn( $needs_shipping ?? ! $virtual );
			$product->shouldReceive( 'get_length' )->andReturn( $length );
			$product->shouldReceive( 'get_width' )->andReturn( $width );
			$product->shouldReceive( 'get_height' )->andReturn( $height );
			$product->shouldReceive( 'get_weight' )->andReturn( $weight );

			return $product;
		}

		/**
		 * An order line.
		 *
		 * @param \WC_Product|false $product  false = the product was deleted
		 * @param int               $refunded what WooCommerce reports as refunded (its sign differs between versions)
		 * @return array{item: \WC_Order_Item_Product, refunded: int}
		 */
		private function line( $product, int $quantity, int $refunded = 0 ): array {
			static $next_id = 100;

			$item = Mockery::mock( '\WC_Order_Item_Product' );
			$item->shouldReceive( 'get_product' )->andReturn( $product );
			$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
			$item->shouldReceive( 'get_id' )->andReturn( ++$next_id );
			$item->shouldReceive( 'get_product_id' )->andReturn( 7 );
			$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );

			return [
				'item'     => $item,
				'refunded' => $refunded,
			];
		}

		/**
		 * @param array<int, array{item: \WC_Order_Item_Product, refunded: int}> $lines
		 */
		private function order( array $lines ): \WC_Order {
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_items' )->andReturn( array_column( $lines, 'item' ) );
			$order->shouldReceive( 'get_shipping_country' )->andReturn( 'RU' );
			$order->shouldReceive( 'get_shipping_state' )->andReturn( '' );
			$order->shouldReceive( 'get_shipping_postcode' )->andReturn( '101000' );
			$order->shouldReceive( 'get_shipping_city' )->andReturn( 'Moscow' );
			$order->shouldReceive( 'get_shipping_address_1' )->andReturn( 'Tverskaya 1' );
			$order->shouldReceive( 'get_shipping_address_2' )->andReturn( '' );
			$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturnUsing(
				static function ( $item_id ) use ( $lines ) {
					foreach ( $lines as $line ) {
						if ( $line['item']->get_id() === $item_id ) {
							return $line['refunded'];
						}
					}

					return 0;
				}
			);

			return $order;
		}

		/**
		 * @return array<string, array{0:string}>
		 */
		public static function algorithm_provider(): array {
			return [
				'separately' => [ \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY ],
				'virtual'    => [ \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL ],
			];
		}

		/**
		 * The pin: an order with 2 lines (quantity 2 and 1) packs to exactly what the rate path packs
		 * for the equivalent cart package — same parcels, same weights.
		 *
		 * @dataProvider algorithm_provider
		 */
		public function test_order_packs_to_the_same_result_as_the_equivalent_cart_package( string $algorithm ): void {
			$first  = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$second = $this->product( 20.0, 10.0, 8.0, 2.0 );
			$method = $this->method( $algorithm );

			$from_order = $method->pack_order( $this->order( [ $this->line( $first, 2 ), $this->line( $second, 1 ) ] ) );
			$from_rate  = $method->pack_cart_package(
				[
					'contents' => [
						'a' => [ 'data' => $first, 'quantity' => 2 ],
						'b' => [ 'data' => $second, 'quantity' => 1 ],
					],
				]
			);

			$this->assertInstanceOf( \Woodev_Packer_Result::class, $from_order );
			$this->assertSame( 5.0, $from_order->get_total_weight() );
			$this->assertEquals( $this->parcels( $from_rate ), $this->parcels( $from_order ) );
		}

		public function test_a_virtual_line_is_skipped(): void {
			$physical = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$virtual  = $this->product( 0.0, 0.0, 0.0, 0.0, true );
			$method   = $this->method();

			$packed = $method->pack_order( $this->order( [ $this->line( $virtual, 4 ), $this->line( $physical, 1 ) ] ) );

			$this->assertSame( 1, $packed->get_package_count() );
			$this->assertSame( 1.5, $packed->get_total_weight() );
		}

		public function test_an_order_with_nothing_physical_packs_to_null(): void {
			$method = $this->method();

			$this->assertNull( $method->pack_order( $this->order( [] ) ) );
			$this->assertNull( $method->pack_order( $this->order( [ $this->line( $this->product( 0.0, 0.0, 0.0, 0.0, true ), 1 ) ] ) ) );
		}

		/**
		 * @return array<string, array{0:int}>
		 */
		public static function refund_sign_provider(): array {
			return [
				'refunded reported negative' => [ -1 ],
				'refunded reported positive' => [ 1 ],
			];
		}

		/**
		 * @dataProvider refund_sign_provider
		 */
		public function test_a_refunded_quantity_is_not_packed( int $refunded ): void {
			$product = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$method  = $this->method( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL );

			$packed = $method->pack_order( $this->order( [ $this->line( $product, 3, $refunded ) ] ) );

			// 3 ordered, 1 refunded: two units of 1.5 kg ship
			$this->assertSame( 3.0, $packed->get_total_weight() );
		}

		public function test_a_fully_refunded_line_is_skipped(): void {
			$kept     = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$refunded = $this->product( 20.0, 10.0, 8.0, 2.0 );
			$method   = $this->method();

			$packed = $method->pack_order( $this->order( [ $this->line( $kept, 1 ), $this->line( $refunded, 2, -2 ) ] ) );

			$this->assertSame( 1, $packed->get_package_count() );
			$this->assertSame( 1.5, $packed->get_total_weight() );

			$this->assertNull( $method->pack_order( $this->order( [ $this->line( $refunded, 2, -2 ) ] ) ) );
		}

		public function test_a_deleted_product_is_skipped_without_a_fatal(): void {
			$product = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$method  = $this->method();

			$packed = $method->pack_order( $this->order( [ $this->line( false, 2 ), $this->line( $product, 1 ) ] ) );

			$this->assertSame( 1.5, $packed->get_total_weight() );
			$this->assertNull( $method->pack_order( $this->order( [ $this->line( false, 2 ) ] ) ) );
		}

		/**
		 * The default dimensions (#955) apply to an order exactly as they do at rate time: a product with
		 * no size and no weight is packed with the built-in 10 cm / 100 g, and both paths agree on it.
		 */
		public function test_default_dimensions_are_applied_as_at_rate_time(): void {
			// the built-in default weight is 100 g and is converted to kg through the store unit it is typed in
			Functions\when( 'wc_get_weight' )->alias(
				static function ( $weight, $to_unit, $from_unit = '' ) {
					return 'g' === $from_unit ? (float) $weight / 1000 : (float) $weight;
				}
			);

			$bare   = $this->product( 0.0, 0.0, 0.0, 0.0 );
			$method = $this->method( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL );

			$from_order = $method->pack_order( $this->order( [ $this->line( $bare, 2 ) ] ) );
			$from_rate  = $method->pack_cart_package( [ 'contents' => [ 'a' => [ 'data' => $bare, 'quantity' => 2 ] ] ] );

			$this->assertEqualsWithDelta( 0.2, $from_order->get_total_weight(), 0.0001 );
			$this->assertEquals( $this->parcels( $from_rate ), $this->parcels( $from_order ) );
		}

		/**
		 * A non-virtual product that needs no shipping (woocommerce_product_needs_shipping, a product
		 * subclass) is left out of a cart's package — and so out of the export, not exported as a parcel.
		 */
		public function test_a_product_that_needs_no_shipping_is_excluded_at_export_as_at_rate_time(): void {
			$shipped = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$service = $this->product( 20.0, 10.0, 8.0, 4.0, false, false );
			$method  = $this->method();

			$from_order = $method->pack_order( $this->order( [ $this->line( $service, 1 ), $this->line( $shipped, 1 ) ] ) );

			$this->assertSame( 1, $from_order->get_package_count() );
			$this->assertSame( 1.5, $from_order->get_total_weight() );

			// only the non-shippable line: nothing to pack, like the rate of an all-service cart
			$this->assertNull( $method->pack_order( $this->order( [ $this->line( $service, 2 ) ] ) ) );
		}

		/**
		 * Equality against the actual rate package: the cart package WC builds (contents filtered with
		 * needs_shipping(), as WC_Cart::filter_items_needing_shipping() does) packs to exactly what the
		 * order does.
		 */
		public function test_export_equals_the_filtered_rate_package(): void {
			$first   = $this->product( 10.0, 5.0, 3.0, 1.5 );
			$service = $this->product( 20.0, 10.0, 8.0, 4.0, false, false );
			$virtual = $this->product( 0.0, 0.0, 0.0, 0.0, true );
			$second  = $this->product( 20.0, 10.0, 8.0, 2.0 );
			$method  = $this->method();

			$cart = [
				'a' => [ 'data' => $first, 'quantity' => 2 ],
				'b' => [ 'data' => $service, 'quantity' => 1 ],
				'c' => [ 'data' => $virtual, 'quantity' => 3 ],
				'd' => [ 'data' => $second, 'quantity' => 1 ],
			];

			// what WC_Cart hands the shipping method
			$rate_package = [
				'contents' => array_filter(
					$cart,
					static fn( array $item ): bool => $item['data']->needs_shipping()
				),
			];

			$from_rate  = $method->pack_cart_package( $rate_package );
			$from_order = $method->pack_order(
				$this->order(
					[
						$this->line( $first, 2 ),
						$this->line( $service, 1 ),
						$this->line( $virtual, 3 ),
						$this->line( $second, 1 ),
					]
				)
			);

			$this->assertSame( 5.0, $from_order->get_total_weight() );
			$this->assertEquals( $this->parcels( $from_rate ), $this->parcels( $from_order ) );
		}

		/**
		 * The export goes through the same overridable seam as the rate: a carrier's pack_package()
		 * override applies to it, and receives a WC-shaped package.
		 */
		public function test_a_pack_package_override_applies_to_the_export(): void {
			$product = $this->product( 10.0, 5.0, 3.0, 1.5 );

			// the override disables packing for the package
			$method = $this->method( \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY, \ShippingMethodPackOrderTest_OverridingMethod::class );

			$this->assertNull( $method->pack_order( $this->order( [ $this->line( $product, 2 ) ] ) ) );

			$this->assertIsArray( $method->seen_package );
			$this->assertCount( 1, $method->seen_package['contents'] );
			$contents = array_values( $method->seen_package['contents'] );
			$this->assertSame( $product, $contents[0]['data'] );
			$this->assertSame( 2, $contents[0]['quantity'] );
			$this->assertSame( 7, $contents[0]['product_id'] );
			$this->assertSame( 0, $contents[0]['variation_id'] );
			$this->assertSame( 'RU', $method->seen_package['destination']['country'] );
			$this->assertSame( '101000', $method->seen_package['destination']['postcode'] );

			// ... and it can swap the result: the export returns what the override returned
			$replacement = ( new \ReflectionClass( \ShippingMethodPackOrderTest_Method::class ) )->newInstanceWithoutConstructor();
			$replacement->stored_options = [ 'packing_algorithm' => \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL ];
			$fixed                       = $replacement->pack_cart_package( [ 'contents' => [ 'x' => [ 'data' => $this->product( 1.0, 1.0, 1.0, 9.0 ), 'quantity' => 1 ] ] ] );

			$method->override_result = $fixed;

			$this->assertSame( $fixed, $method->pack_order( $this->order( [ $this->line( $product, 2 ) ] ) ) );
		}

		public function test_a_method_without_box_packing_gets_null_as_at_rate_time(): void {
			$method              = $this->method();
			$method->box_packing = false;

			$this->assertNull( $method->pack_order( $this->order( [ $this->line( $this->product( 10.0, 5.0, 3.0, 1.5 ), 1 ) ] ) ) );
		}
	}
}
