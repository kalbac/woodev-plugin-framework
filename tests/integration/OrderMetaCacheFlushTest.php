<?php
/**
 * The legacy (CPT) order-meta writers of `Woodev_Order_Compatibility` and WooCommerce's object-meta cache.
 *
 * WooCommerce 8.5 and 9.3 flush an order's cached meta on `updated_post_meta` only; adding or deleting a
 * key through `add_post_meta()` / `delete_post_meta()` left the cached copy stale, so an order read once
 * came back without the change on the next `wc_get_order()`. The helper now flushes it itself — see the
 * `flush_order_meta_cache()` docblock. Green on every WooCommerce version, red on 8.5 / 9.3 without the flush.
 */

namespace Woodev\Tests\Integration;

class OrderMetaCacheFlushTest extends TestCase {

	private const KEY = '_woodev_meta_cache_probe';

	/**
	 * @return \WC_Order an order on the legacy post store, its meta cache already primed.
	 */
	private function primed_cpt_order(): \WC_Order {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );

		$this->assertFalse( \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'this test needs the legacy post store' );

		$order = wc_create_order();
		$order->save();

		// The read that primes WooCommerce's meta cache — the state a real request is in.
		wc_get_order( $order->get_id() )->read_meta_data( true );

		return $order;
	}

	public function test_a_key_added_through_the_helper_is_visible_to_the_next_read(): void {
		$order = $this->primed_cpt_order();

		\Woodev_Order_Compatibility::add_order_meta( $order, self::KEY, 'added' );

		$this->assertSame( 'added', wc_get_order( $order->get_id() )->get_meta( self::KEY, true ) );
	}

	public function test_a_key_updated_through_the_helper_is_visible_to_the_next_read(): void {
		$order = $this->primed_cpt_order();

		\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'first' );
		wc_get_order( $order->get_id() )->read_meta_data( true );
		\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'second' );

		$this->assertSame( 'second', wc_get_order( $order->get_id() )->get_meta( self::KEY, true ) );
	}

	public function test_a_key_deleted_through_the_helper_is_gone_on_the_next_read(): void {
		$order = $this->primed_cpt_order();

		\Woodev_Order_Compatibility::add_order_meta( $order, self::KEY, 'doomed' );
		wc_get_order( $order->get_id() )->read_meta_data( true );
		\Woodev_Order_Compatibility::delete_order_meta( $order, self::KEY );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( self::KEY, true ) );
	}
}
