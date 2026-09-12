<?php
/**
 * #853 measurement: does an order whose carrier response yields an EMPTY
 * carrier order id still get a `carrier_order_id` meta row created?
 *
 * Abstract_Shipment_Handler::export() (abstract-shipment-handler.php:191) calls
 * Shipping_Order_Handler::set(), which routes through
 * Woodev_Order_Compatibility::update_order_meta() — the ONE place that decides
 * whether HPOS (WC_Order::update_meta_data()+save_meta_data()) or legacy
 * (update_post_meta()) actually gets called. This file proves neither branch
 * special-cases an empty string: both persist it exactly like any other value,
 * so the meta KEY exists on the order regardless of whether anything was
 * actually exported.
 *
 * Combined with
 * {@see \Woodev\Tests\Unit\ShippingOrdersQueryTest::test_is_exported_true_builds_an_exists_clause()}
 * — which pins that `Orders_Query::is_exported_meta_clauses()` builds a bare
 * `compare => 'EXISTS'` clause with no `value` comparison at all — the verdict
 * is: YES, a non-throwing carrier response with an empty id still marks the
 * order "exported" in the new-orders badge count. That is a separate defect
 * from #853 (this card only wires the cache flush) and gets its own card; this
 * test exists solely as the required measurement, not a fix.
 *
 * @package Woodev\Tests\Unit
 */

namespace Automattic\WooCommerce\Utilities {

	if ( ! class_exists( __NAMESPACE__ . '\OrderUtil', false ) ) {
		/**
		 * Minimal stand-in for WooCommerce's real OrderUtil, so
		 * Woodev_Plugin_Compatibility::is_hpos_enabled() can be forced true or
		 * false from a test without loading WooCommerce itself.
		 */
		class OrderUtil {

			/** @var bool */
			public static $hpos_enabled = false;

			/**
			 * @return bool
			 */
			public static function custom_orders_table_usage_is_enabled(): bool {
				return self::$hpos_enabled;
			}
		}
	}
}

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-order-compatibility.php';
}

namespace Woodev\Tests\Unit {

	use Automattic\WooCommerce\Utilities\OrderUtil;
	use Brain\Monkey\Functions;
	use Mockery;

	/**
	 * @covers \Woodev_Order_Compatibility::update_order_meta
	 */
	final class OrderCompatibilityEmptyCarrierIdTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			OrderUtil::$hpos_enabled = false;
		}

		protected function tearDown(): void {
			OrderUtil::$hpos_enabled = false;

			parent::tearDown();
		}

		/**
		 * Legacy (non-HPOS) CPT datastore: update_post_meta() is called with the
		 * empty string exactly as given — nothing skips or substitutes it.
		 */
		public function test_legacy_path_persists_an_empty_string_value_unconditionally(): void {
			OrderUtil::$hpos_enabled = false;

			Functions\expect( 'update_post_meta' )->once()->with( 123, 'carrier_order_id', '' );

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 123 );
			$order->shouldNotReceive( 'update_meta_data' );

			\Woodev_Order_Compatibility::update_order_meta( $order, 'carrier_order_id', '' );
		}

		/**
		 * HPOS (custom orders table) datastore: update_meta_data() + save_meta_data()
		 * are called with the empty string exactly as given — same result, different
		 * code path.
		 */
		public function test_hpos_path_persists_an_empty_string_value_unconditionally(): void {
			OrderUtil::$hpos_enabled = true;

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'update_meta_data' )->once()->with( 'carrier_order_id', '' );
			$order->shouldReceive( 'save_meta_data' )->once();

			\Woodev_Order_Compatibility::update_order_meta( $order, 'carrier_order_id', '' );
		}
	}
}
