<?php
/**
 * What the shop has sent to the marketplace for the units of its orders.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `seller_actions` table: one row per (account, order, unit, action), written **before** the action is
 * sent, so the same action cannot go to the marketplace twice — from a repeated request, a second tab or a second manager — while
 * the shop does not yet know its outcome.
 *
 * A row carries the identity of what was sent (the unit's status at that moment and the typed values) and a state:
 *
 * - {@see self::SENDING}: a request is on its way; nothing else is sent for the unit and action until it answers, or until
 *   {@see self::SENDING_TTL} passes (the request died);
 * - {@see self::SENT} (the marketplace accepted it) and {@see self::UNKNOWN} (no answer, so it may have happened): the same
 *   identity is not sent again for {@see self::KEPT_TTL}. Another identity — the unit's status has moved on since the shop
 *   refreshed the order, or the merchant typed something else — is a new action and goes through.
 *
 * A unit the marketplace refused keeps no row: it can be sent again at once.
 *
 * The changes of shipments are claimed here the same way ({@see \Woodev\Framework\Marketplace\Flows\Shipment_Service}): their
 * action names hold a dot, which no seller action's id can, and a change of a whole shipment has order 0 and the shipment as its
 * unit.
 *
 * Taking a row is atomic (`INSERT IGNORE` on the primary key, else a compare-and-set `UPDATE`), and every later write carries the
 * claim token, so a request whose claim expired and was taken over changes nothing.
 *
 * @since 2.1.27
 */
final class Wpdb_Seller_Action_Store {
	/**
	 * A request is on its way.
	 */
	public const SENDING = 'sending';

	/**
	 * The marketplace accepted the action.
	 */
	public const SENT = 'sent';

	/**
	 * The answer was lost: the action may have happened.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Seconds after which a request that never answered stops holding its units.
	 */
	public const SENDING_TTL = 600;

	/**
	 * Seconds an accepted or unanswered action is not sent again while the unit stays as it was.
	 */
	public const KEPT_TTL = 3600;

	/**
	 * Most expired rows removed in one go.
	 */
	private const SWEEP_LIMIT = 200;

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @since 2.1.27
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full name of the `seller_actions` table.
	 * @param callable|null $clock Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $table;
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * Tells what keeps an action from being sent now. Changes nothing.
	 *
	 * @since 2.1.27
	 *
	 * @param int    $account_id Account.
	 * @param int    $order_id   WooCommerce order.
	 * @param string $unit_id    The marketplace's id of the unit.
	 * @param string $action     Action id.
	 * @param string $identity   Identity of what would be sent: 32 characters.
	 * @return string {@see self::SENDING}, {@see self::SENT} or {@see self::UNKNOWN}; empty when it can be sent.
	 */
	public function blocking_state( int $account_id, int $order_id, string $unit_id, string $action, string $identity ): string {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A claim is read from the database, never from a cache.
			$this->db->prepare(
				'SELECT identity, state FROM %i WHERE account_id = %d AND order_id = %d AND unit_key = %s AND action = %s AND claim_until > %s',
				$this->table,
				$account_id,
				$order_id,
				md5( $unit_id ),
				$action,
				$this->at( 0 )
			),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return '';
		}

		return self::SENDING === $row['state'] || $identity === $row['identity'] ? (string) $row['state'] : '';
	}

	/**
	 * Takes the right to send an action for a unit.
	 *
	 * @since 2.1.27
	 *
	 * @param int    $account_id Account.
	 * @param int    $order_id   WooCommerce order.
	 * @param string $unit_id    The marketplace's id of the unit.
	 * @param string $action     Action id.
	 * @param string $identity   Identity of what is sent: 32 characters.
	 * @return string|null The claim token; null when it must not be sent now ({@see self::blocking_state()}).
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function claim( int $account_id, int $order_id, string $unit_id, string $action, string $identity ): ?string {
		$token = bin2hex( random_bytes( 16 ) );
		$until = $this->at( self::SENDING_TTL );
		$key   = [ $account_id, $order_id, md5( $unit_id ), $action ];

		$inserted = $this->write(
			'INSERT IGNORE INTO %i (account_id, order_id, unit_key, action, identity, state, claim_token, claim_until) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)',
			array_merge( [ $this->table ], $key, [ $identity, self::SENDING, $token, $until ] )
		);

		if ( 1 === $inserted ) {
			return $token;
		}

		// A row is there: take it over when it has expired, or when it holds an answered action other than this one.
		$taken = $this->write(
			'UPDATE %i SET identity = %s, state = %s, claim_token = %s, claim_until = %s WHERE account_id = %d AND order_id = %d AND unit_key = %s AND action = %s AND (claim_until <= %s OR (state <> %s AND identity <> %s))',
			array_merge( [ $this->table, $identity, self::SENDING, $token, $until ], $key, [ $this->at( 0 ), self::SENDING, $identity ] )
		);

		return 1 === $taken ? $token : null;
	}

	/**
	 * Keeps the outcome of a claimed action, so it is not sent again for a while.
	 *
	 * @since 2.1.27
	 *
	 * @param string $token Claim token.
	 * @param string $state {@see self::SENT} or {@see self::UNKNOWN}.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function keep( string $token, string $state ): void {
		$this->write(
			'UPDATE %i SET state = %s, claim_until = %s WHERE claim_token = %s',
			[ $this->table, $state, $this->at( self::KEPT_TTL ), $token ]
		);
	}

	/**
	 * Forgets a claimed action that did not happen: it can be sent again.
	 *
	 * @since 2.1.27
	 *
	 * @param string $token Claim token.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function release( string $token ): void {
		$this->write( 'DELETE FROM %i WHERE claim_token = %s', [ $this->table, $token ] );
	}

	/**
	 * Removes a batch of an account's rows that hold nothing any more.
	 *
	 * @since 2.1.27
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function forget_expired( int $account_id ): void {
		Account_Rows::delete_where( $this->db, $this->table, 'account_id = %d AND claim_until <= %s', [ $account_id, $this->at( 0 ) ], self::SWEEP_LIMIT );
	}

	/**
	 * Deletes a batch of an account's rows.
	 *
	 * @since 2.1.27
	 *
	 * @param int $account_id Account.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		return Account_Rows::delete( $this->db, $this->table, $account_id, $limit );
	}

	/**
	 * @param string           $sql  Statement of fixed text and placeholders.
	 * @param array<int,mixed> $args Values.
	 * @return int Rows changed.
	 * @throws Persistence_Exception When the database refuses the statement.
	 */
	private function write( string $sql, array $args ): int {
		$changed = $this->db->query( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed statements of this class, prepared here.

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'a seller action', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $changed;
	}

	/**
	 * @param int $offset Seconds from now.
	 * @return string The UTC datetime the column holds.
	 */
	private function at( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) + $offset );
	}
}
