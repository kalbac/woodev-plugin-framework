<?php
/**
 * The legacy (CPT) order-meta writers of `Woodev_Order_Compatibility` and WooCommerce's object-meta cache.
 *
 * WooCommerce 8.5 and 9.3 flush an order's cached meta on `updated_post_meta` only; adding or deleting a
 * key through `add_post_meta()` / `delete_post_meta()` left the cached copy stale, so an order read once
 * came back without the change on the next `wc_get_order()`. The helper now flushes it itself — see the
 * `flush_order_meta_cache()` docblock. Green on every WooCommerce version, red on 8.5 / 9.3 without the flush.
 *
 * The helper flushes only where WooCommerce's own `WC_Post_Data::flush_object_meta_cache` is NOT hooked to the
 * action that just fired (a second invalidation is a fresh cache prefix, not a no-op). WordPress fires
 * `added_post_meta` for the FIRST `update_post_meta()` of a key, so an update is judged by what fired too.
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

	/**
	 * Runs $write and reports the order's cache prefix at the moment WooCommerce's own listeners are done
	 * (priority 99 on the post-meta action) and once the helper has returned.
	 *
	 * @return array{0: string, 1: string} [ prefix after the core hooks, prefix after the helper ]
	 */
	private function prefixes_around( \WC_Order $order, string $action, callable $write ): array {
		$group    = 'object_' . $order->get_id();
		$captured = '';
		$capture  = static function () use ( &$captured, $group ) {
			$captured = \WC_Cache_Helper::get_cache_prefix( $group );
		};

		add_action( $action, $capture, 99 );
		$write();
		remove_action( $action, $capture, 99 );

		$this->assertNotSame( '', $captured, "$action did not fire" );

		return array( $captured, \WC_Cache_Helper::get_cache_prefix( $group ) );
	}

	/**
	 * The helper invalidates the order's cache exactly when WooCommerce has no listener of its own on $action.
	 */
	private function assert_invalidated_only_if_core_does_not( string $action, string $after_core, string $after_helper ): void {
		if ( has_action( $action, array( 'WC_Post_Data', 'flush_object_meta_cache' ) ) ) {
			$this->assertSame( $after_core, $after_helper, 'WooCommerce flushed already; the helper must not invalidate again' );
		} else {
			$this->assertNotSame( $after_core, $after_helper, 'nobody flushed; the helper must' );
		}
	}

	public function test_an_add_invalidates_the_cache_only_when_woocommerce_does_not_hook_added_post_meta(): void {
		$order = $this->primed_cpt_order();

		[ $after_core, $after_helper ] = $this->prefixes_around(
			$order,
			'added_post_meta',
			function () use ( $order ) {
				\Woodev_Order_Compatibility::add_order_meta( $order, self::KEY, 'added' );
			}
		);

		$this->assert_invalidated_only_if_core_does_not( 'added_post_meta', $after_core, $after_helper );
	}

	public function test_a_delete_invalidates_the_cache_only_when_woocommerce_does_not_hook_deleted_post_meta(): void {
		$order = $this->primed_cpt_order();
		\Woodev_Order_Compatibility::add_order_meta( $order, self::KEY, 'doomed' );

		[ $after_core, $after_helper ] = $this->prefixes_around(
			$order,
			'deleted_post_meta',
			function () use ( $order ) {
				\Woodev_Order_Compatibility::delete_order_meta( $order, self::KEY );
			}
		);

		$this->assert_invalidated_only_if_core_does_not( 'deleted_post_meta', $after_core, $after_helper );
	}

	public function test_the_first_update_of_a_new_key_is_visible_to_the_next_read(): void {
		$order = $this->primed_cpt_order();

		// update_post_meta() on a key that does not exist yet fires added_post_meta, not updated_post_meta.
		\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'first' );

		$this->assertSame( 'first', wc_get_order( $order->get_id() )->get_meta( self::KEY, true ) );
	}

	public function test_the_first_update_of_a_new_key_invalidates_the_cache_only_when_woocommerce_does_not_hook_added_post_meta(): void {
		$order = $this->primed_cpt_order();

		[ $after_core, $after_helper ] = $this->prefixes_around(
			$order,
			'added_post_meta',
			function () use ( $order ) {
				\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'first' );
			}
		);

		$this->assert_invalidated_only_if_core_does_not( 'added_post_meta', $after_core, $after_helper );
	}

	public function test_an_update_of_an_existing_key_never_invalidates_the_cache_beyond_woocommerce(): void {
		$order = $this->primed_cpt_order();
		\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'first' );

		// Every supported WooCommerce hooks updated_post_meta — the helper adds nothing on top.
		$this->assertNotFalse( has_action( 'updated_post_meta', array( 'WC_Post_Data', 'flush_object_meta_cache' ) ) );

		[ $after_core, $after_helper ] = $this->prefixes_around(
			$order,
			'updated_post_meta',
			function () use ( $order ) {
				\Woodev_Order_Compatibility::update_order_meta( $order, self::KEY, 'second' );
			}
		);

		$this->assertSame( $after_core, $after_helper );
	}
}
