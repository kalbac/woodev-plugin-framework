<?php
/**
 * The shop's copy of an account's shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
use Woodev\Framework\Marketplace\Shipments\Shipment;
use Woodev\Framework\Marketplace\Shipments\Shipment_Cargo_Place;
use Woodev\Framework\Marketplace\Shipments\Shipment_Contents;
use Woodev\Framework\Marketplace\Shipments\Shipment_Requirement;
use Woodev\Framework\Marketplace\Shipments\Shipment_Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `shipments` and `shipment_units` tables ({@see Schema}). The page of shipments reads only these, never the
 * marketplace; the refresh job and the actions write them.
 *
 * **A full refresh never empties the screen.** It takes a generation ({@see self::begin_refresh()}), stamps every shipment it lists
 * with it, and only after the last page sweeps the shipments it did not see ({@see self::finish_listing()}). A shipment written by
 * an action meanwhile gets the generation in progress, so the refresh does not sweep it. A shipment whose units must be read is
 * marked stale — an open one, a new one, one whose state changed, one the marketplace is still working on, and one that holds units
 * to ship again — and reading them ({@see self::save_contents()}) clears the mark. A shipment that could not be read keeps it
 * and is tried again after a growing pause ({@see self::postpone_reading()}).
 *
 * **One action at a time per shipment.** {@see self::lock()} holds a shipment while an action on it is at the marketplace, so a
 * second tab or manager cannot send the same one twice.
 *
 * Per account, one option (no autoload) under the plugin's option family keeps the generations, the cursor of a long listing, the
 * time of the last finished refresh and the text of the last failure; the uninstaller removes it with the family, the account
 * deletion with {@see self::forget()}.
 *
 * @since 2.1.28
 */
final class Wpdb_Shipment_Store {
	/**
	 * Seconds the «refreshing» mark holds without a sign of life.
	 */
	public const REFRESHING_TTL = 900;

	/**
	 * Seconds an action holds a shipment before another may take it (the request died).
	 */
	public const LOCK_TTL = 300;

	/**
	 * Most open shipments a list of choices offers.
	 */
	public const CHOICES = 100;

	/**
	 * Seconds before a shipment that could not be read is tried again, doubled after every further failure. Shorter than the
	 * pause between two polls, so the next one tries it.
	 */
	public const RETRY_FIRST = 600;

	/**
	 * Longest pause between two tries.
	 */
	public const RETRY_MAX = 21600;

	/**
	 * Shipments one statement of the sweep removes.
	 */
	private const SWEEP = 500;

	private const META = 'shipments_';

	private const LABEL_LENGTH = 191;

	private const LONG_LENGTH = 500;

	/**
	 * Sort keys of the page => columns.
	 */
	private const SORTS = [
		'created' => 'created_at',
		'name'    => 'name',
	];

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the `shipments` table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Full name of the `shipment_units` table.
	 *
	 * @var string
	 */
	private $units;

	/**
	 * Option family of the plugin.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @since 2.1.28
	 *
	 * @param \wpdb         $db            Database.
	 * @param string        $table         Full name of the `shipments` table.
	 * @param string        $units_table   Full name of the `shipment_units` table.
	 * @param string        $option_prefix The plugin's option family, from {@see Uninstaller::option_prefix()}.
	 * @param callable|null $clock         Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $units_table, string $option_prefix, ?callable $clock = null ) {
		$this->db     = $db;
		$this->table  = $table;
		$this->units  = $units_table;
		$this->prefix = $option_prefix;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Builds the key of a shipment or a unit: the hash of the marketplace's id that the screen's row ids carry.
	 *
	 * @since 2.1.28
	 *
	 * @param string $remote_id The marketplace's id.
	 * @return string 32 hexadecimal characters.
	 */
	public static function key( string $remote_id ): string {
		return md5( $remote_id );
	}

	/**
	 * Marks a refresh as queued. The text of the last failure stays until a refresh ends.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function mark_refreshing( int $account_id ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = $this->now();

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Whether a refresh is queued or running and gave a sign of life within {@see self::REFRESHING_TTL}.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_refreshing( int $account_id ): bool {
		$at = $this->meta( $account_id )['refreshing_at'];

		return $at > 0 && $this->now() - $at < self::REFRESHING_TTL;
	}

	/**
	 * Ends the «refreshing» mark of a refresh that did not run: the time and the text of the last one stay.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function stop_refreshing( int $account_id ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = 0;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Starts a full listing: a new generation, from the first page.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return int The generation.
	 */
	public function begin_refresh( int $account_id ): int {
		$meta                  = $this->meta( $account_id );
		$meta['running']       = max( $meta['generation'], $meta['running'] ) + 1;
		$meta['cursor']        = '';
		$meta['refreshing_at'] = $this->now();

		$this->save_meta( $account_id, $meta );

		return $meta['running'];
	}

	/**
	 * The listing in progress.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return array{run:int,cursor:string} The generation (0 when no listing waits to go on) and the cursor of the next page.
	 */
	public function listing( int $account_id ): array {
		$meta = $this->meta( $account_id );
		$open = $meta['running'] > $meta['generation'];

		return [
			'run'    => $open ? $meta['running'] : 0,
			'cursor' => $open ? $meta['cursor'] : '',
		];
	}

	/**
	 * Keeps where the next page of the listing starts.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $cursor     Cursor.
	 * @return void
	 */
	public function save_cursor( int $account_id, string $cursor ): void {
		$meta                  = $this->meta( $account_id );
		$meta['cursor']        = $cursor;
		$meta['refreshing_at'] = $this->now();

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Ends a listing after its last page: the shipments it did not see are removed with their units.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @param int $run        The generation.
	 * @return void
	 * @throws Persistence_Exception When the database refuses.
	 */
	public function finish_listing( int $account_id, int $run ): void {
		do {
			$keys = (array) $this->db->get_col( $this->db->prepare( 'SELECT shipment_key FROM %i WHERE account_id = %d AND seen_run < %d LIMIT %d', $this->table, $account_id, $run, self::SWEEP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.

			foreach ( $keys as $key ) {
				$this->remove( $account_id, (string) $key );
			}

			$full = self::SWEEP === count( $keys );
		} while ( $full );

		$meta               = $this->meta( $account_id );
		$meta['generation'] = max( $meta['generation'], $run );
		$meta['cursor']     = '';

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Ends a refresh that went through everything it had to.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $warning    What the merchant should know about it, e.g. that something could not be read; empty for nothing.
	 * @return void
	 */
	public function finish_refresh( int $account_id, string $warning = '' ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshed_at']  = $this->now();
		$meta['refreshing_at'] = 0;
		$meta['error']         = $warning;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Records why a refresh failed and ends the «refreshing» mark; the screen keeps showing the last copy.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $text       Text for the merchant.
	 * @return void
	 */
	public function fail_refresh( int $account_id, string $text ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = 0;
		$meta['error']         = $text;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return int Unix time of the last finished refresh; 0 when none finished.
	 */
	public function refreshed_at( int $account_id ): int {
		return $this->meta( $account_id )['refreshed_at'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return string Why the last refresh failed; empty when it did not.
	 */
	public function last_error( int $account_id ): string {
		return $this->meta( $account_id )['error'];
	}

	/**
	 * Forgets the record of an account that was deleted.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function forget( int $account_id ): void {
		delete_option( $this->prefix . self::META . $account_id );
	}

	/**
	 * Writes what a listing, or the answer of a creation, says about a shipment. Its units stay; a shipment still changing, a new one
	 * and one whose state changed are marked for reading.
	 *
	 * @since 2.1.28
	 *
	 * @param int      $account_id Account.
	 * @param Shipment $shipment   Shipment.
	 * @param int      $run        Generation of the listing; 0 outside a listing.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function save_header( int $account_id, Shipment $shipment, int $run = 0 ): void {
		$key      = self::key( $shipment->id() );
		$known    = $this->find( $account_id, $key );
		$meta     = $this->meta( $account_id );
		$seen     = $run > 0 ? $run : max( $meta['running'], $meta['generation'] );
		$is_stale = null === $known || $shipment->is_still_changing() || (bool) $known['is_open'] !== $shipment->is_open() || 1 === (int) $known['is_stale'];
		$values   = $this->header_values( $shipment ) + [
			'is_stale' => $is_stale ? 1 : 0,
			'seen_run' => null === $known ? $seen : max( $seen, (int) $known['seen_run'] ),
		];

		if ( null === $known ) {
			$this->insert(
				$this->table,
				[
					'account_id'   => $account_id,
					'shipment_key' => $key,
				] + $values
			);

			return;
		}

		$this->update( $values, $account_id, $key );
	}

	/**
	 * Writes a shipment that was just read: its header, units and cargo places. The «ship again» marks of the units that stay are
	 * kept.
	 *
	 * @since 2.1.28
	 *
	 * @param int               $account_id Account.
	 * @param Shipment_Contents $contents   What was read.
	 * @param array<string,int> $order_ids  Posting key => WooCommerce order of the units.
	 * @return void
	 * @throws Persistence_Exception When the database refuses a write.
	 */
	public function save_contents( int $account_id, Shipment_Contents $contents, array $order_ids ): void {
		$shipment = $contents->shipment();
		$key      = self::key( $shipment->id() );

		$this->save_header( $account_id, $shipment );
		$this->update( [ 'is_stale' => 1 ], $account_id, $key ); // Stays marked if a write below fails half-way.

		$reship = [];

		foreach ( $this->units( $account_id, $key ) as $row ) {
			$reship[ $row['unit_key'] ] = (int) $row['needs_reshipment'];
		}

		Account_Rows::delete_where( $this->db, $this->units, 'account_id = %d AND shipment_key = %s', [ $account_id, $key ], PHP_INT_MAX );

		$counts = [
			'unit_count'     => 0,
			'blocking_count' => 0,
			'warning_count'  => 0,
			'reship_count'   => 0,
		];

		foreach ( $contents->units() as $position => $unit ) {
			$unit_key = self::key( $unit->id() );
			$again    = $reship[ $unit_key ] ?? 0;

			$this->insert(
				$this->units,
				[
					'account_id'       => $account_id,
					'shipment_key'     => $key,
					'unit_key'         => $unit_key,
					'unit_id'          => $unit->id(),
					'posting_key'      => mb_substr( $unit->posting_key(), 0, self::LABEL_LENGTH ),
					'order_id'         => $order_ids[ $unit->posting_key() ] ?? 0,
					'label'            => mb_substr( $unit->label(), 0, self::LONG_LENGTH ),
					'status_label'     => mb_substr( $unit->status_label(), 0, self::LABEL_LENGTH ),
					'is_movable'       => $unit->is_movable() ? 1 : 0,
					'needs_reshipment' => $again,
					'requirements'     => (string) wp_json_encode( array_map( [ self::class, 'requirement_values' ], $unit->requirements() ) ),
					'facts'            => (string) wp_json_encode( $unit->facts() ),
					'position'         => $position,
				]
			);

			++$counts['unit_count'];
			$counts['reship_count']   += $again;
			$counts['blocking_count'] += self::has_requirement( $unit, true ) ? 1 : 0;
			$counts['warning_count']  += ! self::has_requirement( $unit, true ) && self::has_requirement( $unit, false ) ? 1 : 0;
		}

		$cargo = [];

		foreach ( $contents->cargo_places() as $place ) {
			$cargo[] = [
				'id'    => $place->id(),
				'label' => $place->label(),
			];
		}

		$this->update(
			$counts + [
				'cargo'         => (string) wp_json_encode( $cargo ),
				'is_stale'      => 0,
				'read_attempts' => 0,
				'retry_at'      => null,
				'read_at'       => $this->at( 0 ),
			],
			$account_id,
			$key
		);
	}

	/**
	 * Puts off a shipment that could not be read: it stays marked and is listed again after a pause that doubles with every
	 * failure, from {@see self::RETRY_FIRST} up to {@see self::RETRY_MAX} seconds.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function postpone_reading( int $account_id, string $key ): void {
		$row      = $this->find( $account_id, $key );
		$attempts = min( 100, 1 + (int) ( $row['read_attempts'] ?? 0 ) );
		$pause    = (int) min( self::RETRY_MAX, self::RETRY_FIRST * ( 2 ** min( 20, $attempts - 1 ) ) );

		$this->update(
			[
				'is_stale'      => 1,
				'read_attempts' => $attempts,
				'retry_at'      => $this->at( $pause ),
			],
			$account_id,
			$key
		);
	}

	/**
	 * Marks a shipment for reading.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function mark_unread( int $account_id, string $key ): void {
		$this->update( [ 'is_stale' => 1 ], $account_id, $key );
	}

	/**
	 * Lets the shipments that are put off be read at once: the merchant asked for a refresh.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function retry_failed_reads( int $account_id ): void {
		$this->query( 'UPDATE %i SET retry_at = NULL WHERE account_id = %d AND retry_at IS NOT NULL', [ $this->table, $account_id ] );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return bool Whether a shipment of the account still waits to be read after a failure.
	 */
	public function has_failed_reads( int $account_id ): bool {
		return null !== $this->db->get_var( $this->db->prepare( 'SELECT 1 FROM %i WHERE account_id = %d AND read_attempts > 0 LIMIT 1', $this->table, $account_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return array<string,mixed>|null The row.
	 */
	public function find( int $account_id, string $key ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND shipment_key = %s', $this->table, $account_id, $key ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lists the shipments whose units have to be read now, open ones first. One an action holds is not listed (the action reads it),
	 * nor one put off after a failure.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @param int $limit      Most rows.
	 * @return array<int,array<string,mixed>> Rows.
	 */
	public function stale( int $account_id, int $limit ): array {
		$now = $this->at( 0 );

		return (array) $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND is_stale = 1 AND (lock_until IS NULL OR lock_until <= %s) AND (retry_at IS NULL OR retry_at <= %s) ORDER BY is_open DESC, created_at DESC, shipment_key ASC LIMIT %d', $this->table, $account_id, $now, $now, $limit ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.
	}

	/**
	 * Lists the newest open shipments, for a list of choices.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $search     Part of the name or the id; empty for all.
	 * @return array<int,array<string,mixed>> Rows, at most {@see self::CHOICES}.
	 */
	public function open( int $account_id, string $search = '' ): array {
		return $this->page(
			$account_id,
			[
				'open'      => true,
				'search'    => $search,
				'sort'      => 'created',
				'direction' => 'desc',
				'page'      => 1,
				'per_page'  => self::CHOICES,
			]
		)['rows'];
	}

	/**
	 * Reads a page of the list, filtered and sorted over the whole table.
	 *
	 * @since 2.1.28
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $query      `open` (bool; absent for all), `reship` (bool: only shipments with units to ship again),
	 *                                        `search`, `sort` (`created` or `name`), `direction`, `page`, `per_page`.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public function page( int $account_id, array $query ): array {
		list( $where, $args ) = $this->where( $account_id, $query );

		$column    = self::SORTS[ $query['sort'] ?? 'created' ] ?? 'created_at';
		$direction = 'asc' === ( $query['direction'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) ( $query['per_page'] ?? 20 ) );
		$offset    = ( max( 1, (int) ( $query['page'] ?? 1 ) ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery -- Our own table; the condition and the order are built from fixed pieces of this class.
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i s WHERE {$where}", array_merge( [ $this->table ], $args ) ) );
		$rows  = (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT s.* FROM %i s WHERE {$where} ORDER BY s.{$column} {$direction}, s.shipment_key ASC LIMIT %d OFFSET %d",
				array_merge( [ $this->table ], $args, [ $per_page, $offset ] )
			),
			'ARRAY_A'
		);
		// phpcs:enable

		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Counts the open and the handed-over shipments under the other filters.
	 *
	 * @since 2.1.28
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $query      The filters of {@see self::page()}; `open` is ignored.
	 * @return array{open:int,closed:int}
	 */
	public function state_counts( int $account_id, array $query ): array {
		unset( $query['open'] );

		list( $where, $args ) = $this->where( $account_id, $query );

		$rows   = (array) $this->db->get_results( $this->db->prepare( "SELECT s.is_open AS is_open, COUNT(*) AS found FROM %i s WHERE {$where} GROUP BY s.is_open", array_merge( [ $this->table ], $args ) ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery -- Our own table, fixed condition pieces.
		$counts = [
			'open'   => 0,
			'closed' => 0,
		];

		foreach ( $rows as $row ) {
			$counts[ 1 === (int) $row['is_open'] ? 'open' : 'closed' ] = (int) $row['found'];
		}

		return $counts;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return array<int,array<string,mixed>> Unit rows, in the marketplace's order.
	 */
	public function units( int $account_id, string $key ): array {
		return (array) $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND shipment_key = %s ORDER BY position ASC, unit_key ASC', $this->units, $account_id, $key ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.
	}

	/**
	 * Reads one page of a shipment's units.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @param int    $page       Page, from 1.
	 * @param int    $per_page   Rows per page.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public function units_page( int $account_id, string $key, int $page, int $per_page ): array {
		$total = (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND shipment_key = %s', $this->units, $account_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.
		$rows  = (array) $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND shipment_key = %s ORDER BY position ASC, unit_key ASC LIMIT %d OFFSET %d', $this->units, $account_id, $key, $per_page, ( max( 1, $page ) - 1 ) * $per_page ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.

		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @param string $unit_key   Unit key.
	 * @return array<string,mixed>|null The unit row.
	 */
	public function unit( int $account_id, string $key, string $unit_key ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND shipment_key = %s AND unit_key = %s', $this->units, $account_id, $key, $unit_key ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Keeps the units the marketplace says must be shipped again until the refresh has read their shipments, which are marked for
	 * reading: what a unit may do (be moved) changes with it.
	 *
	 * @since 2.1.28
	 *
	 * @param int                    $account_id Account.
	 * @param array<string,string[]> $units      Shipment id => ids of its units.
	 * @return void
	 * @throws Persistence_Exception When the database refuses a write.
	 */
	public function expect_reshipment( int $account_id, array $units ): void {
		foreach ( array_keys( $units ) as $shipment_id ) {
			$this->query( 'UPDATE %i SET is_stale = 1 WHERE account_id = %d AND shipment_key = %s', [ $this->table, $account_id, self::key( (string) $shipment_id ) ] );
		}

		$meta           = $this->meta( $account_id );
		$meta['reship'] = $units;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return array<string,string[]> The units kept by {@see self::expect_reshipment()}.
	 */
	public function expected_reshipment( int $account_id ): array {
		return $this->meta( $account_id )['reship'];
	}

	/**
	 * Marks the units the marketplace says must be shipped again, and clears the mark of every other.
	 *
	 * @since 2.1.28
	 *
	 * @param int                    $account_id Account.
	 * @param array<string,string[]> $units      Shipment id => ids of its units.
	 * @return void
	 * @throws Persistence_Exception When the database refuses a write.
	 */
	public function mark_reshipment( int $account_id, array $units ): void {
		$this->query( 'UPDATE %i SET needs_reshipment = 0 WHERE account_id = %d AND needs_reshipment = 1', [ $this->units, $account_id ] );
		$this->query( 'UPDATE %i SET reship_count = 0 WHERE account_id = %d AND reship_count > 0', [ $this->table, $account_id ] );

		foreach ( $units as $shipment_id => $unit_ids ) {
			$key = self::key( (string) $shipment_id );

			foreach ( (array) $unit_ids as $unit_id ) {
				$this->query(
					'UPDATE %i SET needs_reshipment = 1 WHERE account_id = %d AND shipment_key = %s AND unit_key = %s',
					[ $this->units, $account_id, $key, self::key( (string) $unit_id ) ]
				);
			}

			$count = (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND shipment_key = %s AND needs_reshipment = 1', $this->units, $account_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table, prepared.

			$this->query( 'UPDATE %i SET reship_count = %d WHERE account_id = %d AND shipment_key = %s', [ $this->table, $count, $account_id, $key ] );
		}
	}

	/**
	 * Holds a shipment for an action.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return string|null The lock token; null when another action holds the shipment, or it is gone.
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function lock( int $account_id, string $key ): ?string {
		$token = bin2hex( random_bytes( 16 ) );
		$taken = $this->query(
			'UPDATE %i SET lock_token = %s, lock_until = %s WHERE account_id = %d AND shipment_key = %s AND (lock_until IS NULL OR lock_until <= %s)',
			[ $this->table, $token, $this->at( self::LOCK_TTL ), $account_id, $key, $this->at( 0 ) ]
		);

		return 1 === $taken ? $token : null;
	}

	/**
	 * Lets a shipment go.
	 *
	 * @since 2.1.28
	 *
	 * @param string $token Lock token.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	public function unlock( string $token ): void {
		$this->query( "UPDATE %i SET lock_token = '', lock_until = NULL WHERE lock_token = %s", [ $this->table, $token ] );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param array<string,mixed> $row Shipment row.
	 * @return bool Whether an action holds the shipment now.
	 */
	public function is_locked( array $row ): bool {
		return null !== $row['lock_until'] && (string) $row['lock_until'] > $this->at( 0 );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param array<string,mixed> $row Shipment row.
	 * @return Shipment
	 */
	public function shipment( array $row ): Shipment {
		return new Shipment(
			(string) $row['remote_id'],
			(string) $row['name'],
			1 === (int) $row['is_open'],
			[
				'status_label'   => (string) $row['status_label'],
				'kind_label'     => (string) $row['kind_label'],
				'shipping_label' => (string) $row['shipping_label'],
				'created_at'     => self::time( $row['created_at'] ),
				'closed_at'      => self::time( $row['closed_at'] ),
				'facts'          => self::decode( $row['facts'] ?? '' ),
			]
		);
	}

	/**
	 * @since 2.1.28
	 *
	 * @param array<string,mixed> $row Unit row.
	 * @return Shipment_Unit
	 */
	public function unit_of( array $row ): Shipment_Unit {
		$requirements = [];

		foreach ( self::decode( $row['requirements'] ?? '' ) as $entry ) {
			$requirements[] = new Shipment_Requirement( (string) $entry['label'], (string) $entry['state'], (bool) $entry['blocking'], (string) $entry['note'] );
		}

		return new Shipment_Unit(
			(string) $row['unit_id'],
			(string) $row['posting_key'],
			(string) $row['label'],
			[
				'status_label' => (string) $row['status_label'],
				'movable'      => 1 === (int) $row['is_movable'],
				'requirements' => $requirements,
				'facts'        => self::decode( $row['facts'] ?? '' ),
			]
		);
	}

	/**
	 * The stored shipment with everything in it.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,mixed> $row Shipment row.
	 * @return Shipment_Contents
	 */
	public function contents( array $row ): Shipment_Contents {
		$places = [];

		foreach ( self::decode( $row['cargo'] ?? '' ) as $entry ) {
			$places[] = new Shipment_Cargo_Place( (string) $entry['id'], (string) $entry['label'] );
		}

		return new Shipment_Contents(
			$this->shipment( $row ),
			array_map( [ $this, 'unit_of' ], $this->units( (int) $row['account_id'], (string) $row['shipment_key'] ) ),
			$places
		);
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return int Shipments the account has.
	 */
	public function count_for_account( int $account_id ): int {
		return Account_Rows::count( $this->db, $this->table, $account_id );
	}

	/**
	 * Deletes a batch of an account's rows, the units first.
	 *
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		$deleted = Account_Rows::delete( $this->db, $this->units, $account_id, $limit );

		return $deleted > 0 ? $deleted : Account_Rows::delete( $this->db, $this->table, $account_id, $limit );
	}

	/**
	 * Removes one shipment and its units.
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @return void
	 */
	private function remove( int $account_id, string $key ): void {
		Account_Rows::delete_where( $this->db, $this->units, 'account_id = %d AND shipment_key = %s', [ $account_id, $key ], PHP_INT_MAX );
		Account_Rows::delete_where( $this->db, $this->table, 'account_id = %d AND shipment_key = %s', [ $account_id, $key ], 1 );
	}

	/**
	 * The condition of the list's filters, over the alias `s`.
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $query      Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function where( int $account_id, array $query ): array {
		$where = [ 's.account_id = %d' ];
		$args  = [ $account_id ];

		if ( isset( $query['open'] ) ) {
			$where[] = 's.is_open = %d';
			$args[]  = $query['open'] ? 1 : 0;
		}

		if ( ! empty( $query['reship'] ) ) {
			$where[] = 's.reship_count > 0';
		}

		$search = trim( (string) ( $query['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like  = '%' . $this->db->esc_like( $search ) . '%';
			$parts = [ 's.name LIKE %s', 's.remote_id LIKE %s' ];
			array_push( $args, $like, $like );

			if ( 1 === preg_match( '/^\d{1,19}$/D', $search ) ) {
				$parts[] = 'EXISTS ( SELECT 1 FROM %i u WHERE u.account_id = s.account_id AND u.shipment_key = s.shipment_key AND u.order_id = %d )';
				array_push( $args, $this->units, (int) $search );
			}

			$where[] = '( ' . implode( ' OR ', $parts ) . ' )';
		}

		return [ implode( ' AND ', $where ), $args ];
	}

	/**
	 * @param Shipment $shipment Shipment.
	 * @return array<string,mixed> The header columns.
	 */
	private function header_values( Shipment $shipment ): array {
		return [
			'remote_id'      => $shipment->id(),
			'name'           => mb_substr( $shipment->name(), 0, self::LABEL_LENGTH ),
			'is_open'        => $shipment->is_open() ? 1 : 0,
			'status_label'   => mb_substr( $shipment->status_label(), 0, self::LABEL_LENGTH ),
			'kind_label'     => mb_substr( $shipment->kind_label(), 0, self::LABEL_LENGTH ),
			'shipping_label' => mb_substr( $shipment->shipping_label(), 0, self::LONG_LENGTH ),
			'created_at'     => $shipment->created_at() > 0 ? gmdate( 'Y-m-d H:i:s', $shipment->created_at() ) : null,
			'closed_at'      => $shipment->closed_at() > 0 ? gmdate( 'Y-m-d H:i:s', $shipment->closed_at() ) : null,
			'facts'          => (string) wp_json_encode( $shipment->facts() ),
			'updated_at'     => $this->at( 0 ),
		];
	}

	/**
	 * @param Shipment_Unit $unit     Unit.
	 * @param bool          $blocking Which level to look for.
	 * @return bool Whether the unit has an unmet requirement of the level.
	 */
	private static function has_requirement( Shipment_Unit $unit, bool $blocking ): bool {
		foreach ( $unit->requirements() as $requirement ) {
			if ( ! $requirement->is_met() && $requirement->is_blocking() === $blocking ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param Shipment_Requirement $requirement Requirement.
	 * @return array<string,mixed>
	 */
	private static function requirement_values( Shipment_Requirement $requirement ): array {
		return [
			'label'    => $requirement->label(),
			'state'    => $requirement->state(),
			'blocking' => $requirement->is_blocking(),
			'note'     => $requirement->note(),
		];
	}

	/**
	 * @param string              $table  Table.
	 * @param array<string,mixed> $values Columns.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the row.
	 */
	private function insert( string $table, array $values ): void {
		if ( false === $this->db->insert( $table, $values ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			throw Persistence_Exception::database( 'a shipment', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * @param array<string,mixed> $values     Columns.
	 * @param int                 $account_id Account.
	 * @param string              $key        Shipment key.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the write.
	 */
	private function update( array $values, int $account_id, string $key ): void {
		$where = [
			'account_id'   => $account_id,
			'shipment_key' => $key,
		];

		if ( false === $this->db->update( $this->table, $values, $where ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			throw Persistence_Exception::database( 'a shipment', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * @param string           $sql  Statement of fixed text and placeholders.
	 * @param array<int,mixed> $args Values.
	 * @return int Rows changed.
	 * @throws Persistence_Exception When the database refuses the statement.
	 */
	private function query( string $sql, array $args ): int {
		$changed = $this->db->query( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed statements of this class, prepared here.

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'a shipment', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $changed;
	}

	/**
	 * @param int $account_id Account.
	 * @return array{generation:int,running:int,cursor:string,refreshed_at:int,refreshing_at:int,error:string,reship:array<string,string[]>}
	 */
	private function meta( int $account_id ): array {
		$stored = get_option( $this->prefix . self::META . $account_id, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'generation'    => max( 0, (int) ( $stored['generation'] ?? 0 ) ),
			'running'       => max( 0, (int) ( $stored['running'] ?? 0 ) ),
			'cursor'        => (string) ( $stored['cursor'] ?? '' ),
			'refreshed_at'  => max( 0, (int) ( $stored['refreshed_at'] ?? 0 ) ),
			'refreshing_at' => max( 0, (int) ( $stored['refreshing_at'] ?? 0 ) ),
			'error'         => (string) ( $stored['error'] ?? '' ),
			'reship'        => (array) ( $stored['reship'] ?? [] ),
		];
	}

	/**
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $meta       Record.
	 * @return void
	 */
	private function save_meta( int $account_id, array $meta ): void {
		update_option( $this->prefix . self::META . $account_id, $meta, false );
	}

	/**
	 * @param mixed $stored Column value.
	 * @return array<mixed>
	 */
	private static function decode( $stored ): array {
		$value = '' === (string) $stored ? [] : json_decode( (string) $stored, true );

		return is_array( $value ) ? $value : [];
	}

	/**
	 * @param mixed $datetime UTC datetime column; null for none.
	 * @return int Unix time; 0 for none.
	 */
	private static function time( $datetime ): int {
		return null === $datetime || '' === (string) $datetime ? 0 : (int) strtotime( $datetime . ' UTC' );
	}

	/**
	 * @return int Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * @param int $offset Seconds from now.
	 * @return string The UTC datetime the column holds.
	 */
	private function at( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() + $offset );
	}
}
