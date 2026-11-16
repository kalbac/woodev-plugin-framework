<?php
/**
 * The MySQL named lock of an account's bulk run.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * `GET_LOCK( name, 0 )` per account, on the `Order_Lock` pattern (`woodev/shipping-method/order/class-order-lock.php`): MySQL grants a
 * name to one connection at a time and frees it the moment that connection closes, so a tick that died holding it blocks nobody.
 *
 * The name is scoped by database and by the run table's own name (which carries the table prefix and the marketplace id), because
 * lock names are server-wide: two sites on one server, or two marketplace plugins on one site, never block each other.
 *
 * **The lock alone is not a fence.** `wpdb::query()` reconnects on error 2006 and re-runs the query on a new connection that does not
 * hold the lock; {@see self::is_held()} is how a tick notices (`IS_USED_LOCK( name ) = CONNECTION_ID()`), and the tick token in
 * {@see \Woodev\Framework\Marketplace\Data\Bulk_Run_Store} fences the saves.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Lock implements Bulk_Run_Locking {
	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * @var string
	 */
	private $scope;

	/**
	 * Creates the lock of one marketplace's runs.
	 *
	 * @since 2.1.8
	 *
	 * @param \wpdb  $db    Database; the connection that takes the lock is this object's own.
	 * @param string $table Full name of the bulk runs table.
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->scope = substr( md5( ( isset( $db->dbname ) ? (string) $db->dbname : '' ) . '|' . $table ), 0, 16 );
	}

	/**
	 * The lock's name for one account: at most 49 characters, under MySQL's 64.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return string
	 */
	public function name( int $account_id ): string {
		return 'woodev_bulk_' . $account_id . '_' . $this->scope;
	}

	/**
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function acquire( int $account_id ): bool {
		return '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name( $account_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A server-side lock, not data: nothing to cache.
	}

	/**
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function release( int $account_id ): void {
		$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $account_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Releases a server-side lock: nothing to cache.
	}

	/**
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_held( int $account_id ): bool {
		return '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name( $account_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A server-side lock, not data.
	}
}
