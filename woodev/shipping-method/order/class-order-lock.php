<?php
/**
 * Woodev Order Lock
 *
 * A per-order MySQL named lock (`GET_LOCK` / `RELEASE_LOCK`) shared by every framework path that
 * must not run twice at once for the same order: the wizard's save
 * ({@see \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::update()}, #981) and the carrier
 * export ({@see Abstract_Shipment_Handler::export()}, #945).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Order_Lock' ) ) :

	/**
	 * One named lock per (scope, order, database).
	 *
	 * MySQL grants a name to ONE connection at a time and frees it the moment that connection
	 * closes, so a request that died holding the lock blocks nobody. Holding two names at once
	 * on one connection needs MySQL 5.7+ / MariaDB 10.0.2+ — the same floor the wizard's lock
	 * already required.
	 *
	 * @since 2.0.2
	 */
	final class Order_Lock {

		/**
		 * The lock's name for one order.
		 *
		 * Scoped to the database and the table prefix: MySQL lock names are server-wide, and
		 * without the digest two sites on one server (or the blogs of a multisite) would block
		 * each other's orders that happen to share an id. Names are at most 64 characters (MySQL
		 * 5.7+, MariaDB 10.0.2+); with a short scope this shape stays under that for any order id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $scope    what is being serialised, e.g. `edit` or `export`; `[a-z0-9_]` only.
		 * @param int    $order_id the order.
		 * @return string
		 */
		public static function name( string $scope, int $order_id ): string {
			global $wpdb;

			return 'woodev_order_' . $scope . '_' . $order_id . '_' . substr( md5( (string) $wpdb->dbname . '|' . (string) $wpdb->prefix ), 0, 16 );
		}

		/**
		 * Takes the lock, waiting up to `$timeout` seconds (`0` = do not wait).
		 *
		 * One `SELECT GET_LOCK()` on the request's own database connection.
		 *
		 * @since 2.0.2
		 *
		 * @param string $scope    see {@see self::name()}.
		 * @param int    $order_id the order.
		 * @param int    $timeout  seconds to wait for a lock another request holds.
		 * @return bool whether the lock is held; false on a timeout or a database error.
		 */
		public static function acquire( string $scope, int $order_id, int $timeout ): bool {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a server-side lock, not data: nothing to cache.
			$granted = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $scope, $order_id ), $timeout ) );

			return '1' === (string) $granted;
		}

		/**
		 * Releases a lock taken by {@see self::acquire()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string $scope    see {@see self::name()}.
		 * @param int    $order_id the order.
		 * @return void
		 */
		public static function release( string $scope, int $order_id ): void {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases a server-side lock: nothing to cache.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $scope, $order_id ) ) );
		}
	}

endif;
