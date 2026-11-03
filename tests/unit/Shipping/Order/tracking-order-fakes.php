<?php
/**
 * Unit fixture for the buyer tracking tests (#1575): an order whose meta is one array, on both storage layouts.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;

if ( ! trait_exists( __NAMESPACE__ . '\Fakes_Tracking_Orders', false ) ) {

	/**
	 * Gives a test case `$this->meta` and {@see self::tracking_order()}.
	 *
	 * The order answers `get_meta()` (HPOS and the status log) and `get_post_meta()` (the legacy tables) from the same
	 * array, so a test does not depend on which storage layout an earlier test left switched on.
	 */
	trait Fakes_Tracking_Orders {

		/** @var array<string,mixed> order meta of order 123. */
		private array $meta = [];

		/**
		 * Call from `setUp()`.
		 *
		 * @return void
		 */
		private function fake_tracking_orders(): void {
			$this->meta = [];
			Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
			// The real WP_Error-free parser; the framework validates tracking URLs through it.
			Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		}

		/**
		 * @return \WC_Order
		 */
		private function tracking_order() {
			$order = Mockery::mock( '\\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 123 );
			$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( string $key ) { return $this->meta[ $key ] ?? ''; } );

			return $order;
		}
	}
}
