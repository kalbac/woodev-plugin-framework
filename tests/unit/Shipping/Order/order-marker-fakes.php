<?php
/**
 * Shared fakes for the carrier-marker tests (#967): shipping lines and an order with a meta
 * store that behaves like the real one — a value set on the object is visible to the
 * DB-backed reader (`get_post_meta`) only after `save_meta_data()`. Not a test file (no
 * `Test.php` suffix) — required by the tests.
 *
 * Built with Mockery on top of whatever `\WC_Order` stand-in the run declared first (many unit
 * tests declare their own, with differing signatures), so a subclass here would clash.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	if ( ! class_exists( 'WC_Order' ) ) {
		/**
		 * Minimal global \WC_Order stand-in (other unit tests declare the same one).
		 */
		class WC_Order {
			public function get_id() {
				return 123;
			}
		}
	}

	if ( ! class_exists( 'WC_Order_Item_Shipping' ) ) {
		/**
		 * Minimal global \WC_Order_Item_Shipping stand-in.
		 */
		class WC_Order_Item_Shipping {
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Mockery;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/exceptions/class-shipping-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

	/**
	 * Factory for the order and shipping-line doubles, plus the fake meta table they share.
	 */
	final class Order_Marker_Fakes {

		/**
		 * What `get_post_meta()` sees — order id => key => value.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public static $db = [];

		/**
		 * How many times each order's `save_meta_data()` ran — order id => count.
		 *
		 * @var array<int, int>
		 */
		public static $saves = [];

		/**
		 * Empties the fake meta table.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$db    = [];
			self::$saves = [];
		}

		/**
		 * The `get_post_meta( $id, $key )` stand-in.
		 *
		 * @param mixed  $id  order id.
		 * @param string $key meta key.
		 *
		 * @return mixed
		 */
		public static function get_post_meta( $id, $key ) {
			return self::$db[ (int) $id ][ $key ] ?? '';
		}

		/**
		 * A shipping line — only the getters `Order_Marker` reads.
		 *
		 * @param string               $method_id   bare shipping method id.
		 * @param int                  $instance_id zone-instance id.
		 * @param array<string, mixed> $meta        rate meta.
		 *
		 * @return \WC_Order_Item_Shipping
		 */
		public static function line( string $method_id, int $instance_id = 0, array $meta = [] ) {
			$entries = [];

			foreach ( $meta as $key => $value ) {
				$entries[] = (object) [
					'key'   => $key,
					'value' => $value,
				];
			}

			$line = Mockery::mock( \WC_Order_Item_Shipping::class );
			$line->shouldReceive( 'get_method_id' )->andReturn( $method_id );
			$line->shouldReceive( 'get_instance_id' )->andReturn( $instance_id );
			$line->shouldReceive( 'get_name' )->andReturn( 'Курьер' );
			$line->shouldReceive( 'get_total' )->andReturn( '350.00' );
			$line->shouldReceive( 'get_meta_data' )->andReturn( $entries );

			return $line;
		}

		/**
		 * An order with shipping lines.
		 *
		 * @param \WC_Order_Item_Shipping[] $lines shipping lines.
		 * @param int                       $id    order id.
		 *
		 * @return \WC_Order
		 */
		public static function order( array $lines, int $id = 42 ) {
			$pending = [];

			$order = Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'get_shipping_methods' )->andReturn( $lines );
			$order->shouldReceive( 'get_items' )->andReturn( $lines );
			$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
				static function ( $key, $value ) use ( &$pending ): void {
					$pending[ $key ] = $value;
				}
			);
			$order->shouldReceive( 'save_meta_data' )->andReturnUsing(
				static function () use ( &$pending, $id ): void {
					self::$saves[ $id ] = ( self::$saves[ $id ] ?? 0 ) + 1;

					foreach ( $pending as $key => $value ) {
						self::$db[ $id ][ $key ] = $value;
					}

					$pending = [];
				}
			);

			return $order;
		}
	}
}
