<?php
/**
 * Unit fixtures for the delivery status decision (#1564): orders that behave like separate requests, and a `$wpdb`
 * that answers the status lock.
 *
 * The decision re-reads the order under {@see \Woodev\Framework\Shipping\Order\Order_Lock} and saves it through the order
 * object's own meta API, so a test needs (a) one persisted "database" that every order object copies from and saves to, and
 * (b) a lock it can refuse. Two order objects loaded at different moments are two requests with different snapshots — the
 * situation the lock exists for.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;

if ( ! trait_exists( __NAMESPACE__ . '\Fakes_Status_Orders', false ) ) {

	/**
	 * Gives a test case `$this->meta` (the persisted meta of order 123), {@see self::snapshot_order()} and a fake `$wpdb`.
	 */
	trait Fakes_Status_Orders {

		/** @var array<string,mixed> the "database": the persisted meta of the one order every snapshot is of. */
		private array $meta = [];

		/** @var array<int,array<string,mixed>> each snapshot's own copy of the meta, by snapshot number. */
		private array $snapshots = [];

		/** @var array<int,array<string,true>> the keys each snapshot changed since it last read or saved, like WC's dirty meta. */
		private array $changed = [];

		/** @var int how many times an order object saved its meta. */
		private int $saves = 0;

		/** @var object the fake `$wpdb`. */
		private $lock_wpdb;

		/** @var mixed whatever `$GLOBALS['wpdb']` held before the test. */
		private $previous_wpdb;

		/**
		 * Call from `setUp()`.
		 *
		 * @param array<string,mixed> $meta the persisted meta to start from.
		 * @return void
		 */
		private function fake_status_orders( array $meta = [] ): void {
			$this->meta      = $meta;
			$this->snapshots = [];
			$this->changed   = [];
			$this->saves     = 0;

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
			// The compatibility getter on the legacy tables: it reads the persisted meta, like `get_post_meta()`.
			Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );

			$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
			$this->lock_wpdb     = new class() {
				public string $dbname = 'wp';
				public string $prefix = 'wp_';
				public bool $grant    = true;
				/** @var string[] */
				public array $log = [];

				public function prepare( string $query, ...$args ): string {
					return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $query ), $args );
				}

				public function get_var( string $query ): string {
					$this->log[] = 'acquire';

					return $this->grant ? '1' : '0';
				}

				public function query( string $query ): int {
					$this->log[] = 'release';

					return 1;
				}
			};
			$GLOBALS['wpdb']     = $this->lock_wpdb;
		}

		/**
		 * Call from `tearDown()`.
		 *
		 * @return void
		 */
		private function restore_status_wpdb(): void {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		/**
		 * An order object loaded NOW: its own copy of the persisted meta, like a `wc_get_order()` of this moment.
		 *
		 * `read_meta_data( true )` refreshes the copy from the database, `save_meta_data()` writes back the keys it changed.
		 *
		 * @param bool $fail_save whether `save_meta_data()` throws (a database error).
		 * @return \WC_Order&\Mockery\MockInterface
		 */
		private function snapshot_order( bool $fail_save = false ) {
			$n                     = count( $this->snapshots );
			$this->snapshots[ $n ] = $this->meta;
			$this->changed[ $n ]   = [];

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 123 );
			$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
			$order->shouldReceive( 'read_meta_data' )->andReturnUsing(
				function () use ( $n ) {
					$this->snapshots[ $n ] = $this->meta;
					$this->changed[ $n ]   = [];
				}
			);
			$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->snapshots[ $n ][ $key ] ?? '' );
			$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
				function ( string $key, $value ) use ( $n ) {
					$this->snapshots[ $n ][ $key ] = $value;
					$this->changed[ $n ][ $key ]   = true;
				}
			);
			$order->shouldReceive( 'save_meta_data' )->andReturnUsing(
				function () use ( $n, $fail_save ) {
					if ( $fail_save ) {
						throw new \RuntimeException( 'database error' );
					}

					// WooCommerce writes only the meta rows that changed, so a stale snapshot cannot undo other keys.
					foreach ( array_keys( $this->changed[ $n ] ) as $key ) {
						$this->meta[ $key ] = $this->snapshots[ $n ][ $key ];
					}

					$this->changed[ $n ] = [];
					++$this->saves;
				}
			);

			return $order;
		}
	}
}
