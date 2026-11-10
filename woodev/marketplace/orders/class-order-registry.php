<?php
/**
 * The remote-order registry.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the postings table (spec 3.1): one row per remote order group, keyed by `(account_id, external_key)`,
 * where the key is the group key (Ozon: the posting number; Wildberries: the cart id).
 *
 * The row is the durable identity reservation behind the order writer. Its existence says nothing about who may create the
 * WooCommerce order: the holder of the row's claim does (spec 3.5). Every write after the reservation carries the claim token,
 * so a writer whose claim expired and was taken over changes nothing.
 *
 * @since 2.0.29
 */
final class Order_Registry {
	use \Woodev\Framework\Marketplace\Ledger\Claimable;

	/**
	 * Seconds a writer's claim lasts (spec 5.5 step 3).
	 */
	public const CLAIM_TTL = 120;

	/**
	 * Database.
	 *
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
	 * Clock: returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the registry.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full name of the postings table.
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
	 * Reserves the row of a group: inserts it unless it exists. Two writers that reserve at once end with one row.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @return void
	 * @throws \RuntimeException When the database refuses the insert.
	 */
	public function reserve( int $account_id, string $key ): void {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here; the registry is our own table.
			$this->db->prepare(
				'INSERT IGNORE INTO %i (account_id, external_key, first_seen_at) VALUES (%d, %s, %s)',
				$this->table,
				$account_id,
				$key,
				$this->claim_clock( 0 )
			)
		);

		if ( false === $changed ) {
			throw new \RuntimeException( 'The order registry row could not be reserved: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Claims a group's row for one writer.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @return string|null The token, or null when another writer holds the row.
	 */
	public function claim( int $account_id, string $key ): ?string {
		return $this->acquire( $this->table, self::key( $account_id, $key ), self::CLAIM_TTL );
	}

	/**
	 * Tells whether a token still owns the row (spec 3.5 rule 2: asked right before a side effect).
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @param string $token      Claim token.
	 * @return bool
	 */
	public function holds_claim( int $account_id, string $key, string $token ): bool {
		return $this->holds( $this->table, self::key( $account_id, $key ), $token );
	}

	/**
	 * Releases a claim; a writer that no longer holds it changes nothing.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @param string $token      Claim token.
	 * @return void
	 */
	public function release_claim( int $account_id, string $key, string $token ): void {
		$this->release( $this->table, self::key( $account_id, $key ), $token );
	}

	/**
	 * Reads a row.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @return array{wc_order_id:int|null,members:array<string,mixed>,status_raw:string,is_terminal:bool,lines:array<int,array<string,mixed>>,baseline:bool}|null
	 */
	public function find( int $account_id, string $key ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A claimed row must be read fresh.
			$this->db->prepare( 'SELECT wc_order_id, members, status_raw, is_terminal, `lines`, baseline FROM %i WHERE account_id = %d AND external_key = %s', $this->table, $account_id, $key ),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'wc_order_id' => null === $row['wc_order_id'] ? null : (int) $row['wc_order_id'],
			'members'     => self::decode( $row['members'] ),
			'status_raw'  => (string) $row['status_raw'],
			'is_terminal' => 1 === (int) $row['is_terminal'],
			'lines'       => array_values( self::decode( $row['lines'] ) ),
			'baseline'    => 1 === (int) $row['baseline'],
		];
	}

	/**
	 * Registers the WooCommerce order of a group: succeeds only for the claim holder and only while no order is registered.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @param string $token      Claim token.
	 * @param int    $order_id   WooCommerce order id.
	 * @return bool False when the claim was lost or another writer registered an order first.
	 */
	public function attach_order( int $account_id, string $key, string $token, int $order_id ): bool {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare(
				'UPDATE %i SET wc_order_id = %d WHERE account_id = %d AND external_key = %s AND wc_order_id IS NULL AND claim_token = %s AND claim_until > %s',
				$this->table,
				$order_id,
				$account_id,
				$key,
				$token,
				$this->claim_clock( 0 )
			)
		);

		return 1 === $changed;
	}

	/**
	 * Records the state of the last refresh, fenced by the claim (spec 3.5 rule 1).
	 *
	 * The lines keep the quantity each one already took from stock (`qty_applied`, written only by the registry-only accounting
	 * of spec 5.3); this write replaces only what the marketplace says (`qty_seen`).
	 *
	 * @since 2.0.29
	 *
	 * @param int                                                       $account_id  Account id.
	 * @param string                                                    $key         Group key.
	 * @param string                                                    $token       Claim token.
	 * @param string                                                    $status_raw  Marketplace status.
	 * @param bool                                                      $is_terminal Whether the order will not change any more.
	 * @param array<int,array{offer_id:string,sku:string,qty_seen:int}> $lines    Lines as the marketplace sent them.
	 * @param array<string,mixed>|null                                  $members     Member snapshots of a grouped order; null for one-member orders.
	 * @return bool False when the claim was lost.
	 * @throws \RuntimeException When the database refused the write (never read as «claim lost»).
	 */
	public function record( int $account_id, string $key, string $token, string $status_raw, bool $is_terminal, array $lines, ?array $members ): bool {
		$row    = $this->find( $account_id, $key );
		$merged = self::merge_lines( null === $row ? [] : $row['lines'], $lines );
		return $this->write_if_claimed(
			$this->table,
			self::key( $account_id, $key ),
			$token,
			'status_raw = %s, is_terminal = %d, `lines` = %s, members = ' . ( null === $members ? 'NULL' : '%s' ) . ', refreshed_at = %s',
			array_merge(
				[ substr( $status_raw, 0, 32 ), $is_terminal ? 1 : 0, (string) json_encode( $merged ) ], // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The layer uses no WordPress function.
				null === $members ? [] : [ (string) json_encode( $members ) ], // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The layer uses no WordPress function.
				[ $this->claim_clock( 0 ) ]
			)
		);
	}

	/**
	 * Records what one line has taken from shop stock (spec 5.3 registry-only accounting), fenced by the claim. Written right after
	 * the stock write of that line, so a failure on a later line leaves this one accounted.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id  Account id.
	 * @param string $key         Group key.
	 * @param string $token       Claim token.
	 * @param string $line_key    Line identity, {@see self::line_identity()}.
	 * @param int    $qty_applied Units the line has taken from stock now.
	 * @return bool False when the claim was lost or the line is unknown.
	 * @throws \RuntimeException When the database refused the write (never read as «claim lost»).
	 */
	public function apply_line( int $account_id, string $key, string $token, string $line_key, int $qty_applied ): bool {
		$row = $this->find( $account_id, $key );

		if ( null === $row ) {
			return false;
		}

		$lines = $row['lines'];
		$found = false;

		foreach ( $lines as $index => $line ) {
			if ( self::line_key( $line ) === $line_key ) {
				$lines[ $index ]['qty_applied'] = max( 0, $qty_applied );
				$found                          = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		return $this->write_if_claimed(
			$this->table,
			self::key( $account_id, $key ),
			$token,
			'`lines` = %s',
			[ (string) json_encode( array_values( $lines ) ) ] // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The layer uses no WordPress function.
		);
	}

	/**
	 * Marks a row as a cutover baseline (spec 5.3): what the group did to shop stock before the cutover was already mirrored there, so
	 * each line starts where the group stands without a stock write — `qty_applied = qty_seen` for a live or delivered group, 0 for a
	 * cancelled one. A later cancellation then gives a live group's units back once, and a finished group never moves stock.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @param string $token      Claim token.
	 * @param bool   $cancelled  Whether the group is cancelled (its lines then take `qty_applied = 0`).
	 * @return bool False when the claim was lost.
	 * @throws \RuntimeException When the database refused the write (never read as «claim lost»).
	 */
	public function mark_baseline( int $account_id, string $key, string $token, bool $cancelled ): bool {
		$row = $this->find( $account_id, $key );

		if ( null === $row ) {
			return false;
		}

		$lines = [];
		foreach ( $row['lines'] as $line ) {
			$line['qty_applied'] = $cancelled ? 0 : (int) ( $line['qty_seen'] ?? 0 );
			$lines[]             = $line;
		}

		return $this->write_if_claimed(
			$this->table,
			self::key( $account_id, $key ),
			$token,
			'baseline = 1, `lines` = %s',
			[ (string) json_encode( $lines ) ] // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The layer uses no WordPress function.
		);
	}

	/**
	 * Identity of a line inside a row, the one {@see self::apply_line()} takes.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $line Line as {@see self::find()} returns it.
	 * @return string
	 */
	public static function line_identity( array $line ): string {
		return self::line_key( $line );
	}

	/**
	 * Lists the groups that are not finished, the longest unrefreshed first (spec 5.5 step 1b).
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most keys to return.
	 * @return string[]
	 */
	public function open_keys( int $account_id, int $limit ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read fresh by the poller.
			$this->db->prepare(
				'SELECT external_key FROM %i WHERE account_id = %d AND is_terminal = 0 ORDER BY refreshed_at IS NOT NULL, refreshed_at ASC, external_key ASC LIMIT %d',
				$this->table,
				$account_id,
				max( 1, $limit )
			),
			'ARRAY_A'
		);

		return array_map(
			static function ( $row ): string {
				return (string) $row['external_key'];
			},
			(array) $rows
		);
	}

	/**
	 * Returns the recorded status of each known group among the keys.
	 *
	 * @since 2.0.29
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $keys       Group keys.
	 * @return array<string,string> Key => recorded status; a key without a row is absent.
	 */
	public function statuses( int $account_id, array $keys ): array {
		if ( [] === $keys ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per key.
			$this->db->prepare(
				'SELECT external_key, status_raw FROM %i WHERE account_id = %d AND external_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only placeholders are concatenated.
				array_merge( [ $this->table, $account_id ], array_values( $keys ) )
			),
			'ARRAY_A'
		);

		$found = [];
		foreach ( (array) $rows as $row ) {
			$found[ (string) $row['external_key'] ] = (string) $row['status_raw'];
		}

		return $found;
	}

	/**
	 * Keeps each line's applied quantity when the marketplace sends the same line again.
	 *
	 * @param array<int,array<string,mixed>>                            $stored   Lines in the row.
	 * @param array<int,array{offer_id:string,sku:string,qty_seen:int}> $incoming Lines of the refresh.
	 * @return array<int,array{offer_id:string,sku:string,qty_seen:int,qty_applied:int}>
	 */
	private static function merge_lines( array $stored, array $incoming ): array {
		$applied = [];
		foreach ( $stored as $line ) {
			$applied[ self::line_key( $line ) ] = (int) ( $line['qty_applied'] ?? 0 );
		}

		$merged = [];
		foreach ( $incoming as $line ) {
			$key = self::line_key( $line );

			$merged[] = [
				'offer_id'    => (string) $line['offer_id'],
				'sku'         => (string) $line['sku'],
				'qty_seen'    => (int) $line['qty_seen'],
				'qty_applied' => $applied[ $key ] ?? 0,
			];

			unset( $applied[ $key ] );
		}

		// A line the marketplace no longer sends but that took stock stays, so the restore of spec 5.3 still finds it.
		foreach ( $stored as $line ) {
			$key = self::line_key( $line );
			if ( isset( $applied[ $key ] ) && $applied[ $key ] > 0 ) {
				$merged[] = [
					'offer_id'    => (string) ( $line['offer_id'] ?? '' ),
					'sku'         => (string) ( $line['sku'] ?? '' ),
					'qty_seen'    => 0,
					'qty_applied' => $applied[ $key ],
				];
			}
		}

		return $merged;
	}

	/**
	 * Identity of a line inside a row.
	 *
	 * @param array<string,mixed> $line Line.
	 * @return string
	 */
	private static function line_key( array $line ): string {
		return (string) ( $line['sku'] ?? '' ) . '|' . (string) ( $line['offer_id'] ?? '' );
	}

	/**
	 * Decodes a JSON column.
	 *
	 * @param mixed $value Column value.
	 * @return array<mixed>
	 */
	private static function decode( $value ): array {
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Key map of a row.
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Group key.
	 * @return array<string,mixed>
	 */
	private static function key( int $account_id, string $key ): array {
		return [
			'account_id'   => $account_id,
			'external_key' => $key,
		];
	}

	/**
	 * Returns the database.
	 *
	 * @since 2.0.29
	 *
	 * @return \wpdb
	 */
	protected function claim_db(): \wpdb {
		return $this->db;
	}

	/**
	 * Returns the current Unix time.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	protected function claim_now(): int {
		return (int) call_user_func( $this->clock );
	}
}
