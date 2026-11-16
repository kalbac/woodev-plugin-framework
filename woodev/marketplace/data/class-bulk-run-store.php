<?php
/**
 * The bulk runs of the accounts.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;

defined( 'ABSPATH' ) || exit;

/**
 * One row per account in `bulk_runs`: the active run, or the last finished one (#1795). Every change of a run that is not a start is
 * a conditional `UPDATE`, because an option cannot do that:
 *
 * - a **start** replaces only a terminal row ({@see self::start()}), so a run that is still going is never overwritten;
 * - a **tick** writes its token first ({@see self::take_tick()}), and then every save and the terminal write carry `tick = <token>`
 *   ({@see self::save()}, {@see self::finish()}), so a tick that lost its connection (and with it the named lock) changes nothing;
 * - **cancel** is written only by {@see self::request_cancel()}, a column no save touches, so a tick's save cannot erase it.
 *
 * The store never takes the named lock; {@see \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lock} does.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Store {
	/**
	 * Columns read for a run: every one but the heavy `items`.
	 */
	private const COLUMNS = 'account_id, run_id, state, cancel, tick, action, payload, filter, user_id, `cursor`, counts, report, started_at, finished_at';

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
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
	 * Creates the store.
	 *
	 * @since 2.1.8
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full name of the bulk runs table.
	 * @param callable|null $clock `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $table;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Reads the run of an account.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return Bulk_Run|null Null when the account never ran one.
	 */
	public function find( int $account_id ): ?Bulk_Run {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE account_id = %d', $this->table, $account_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Own table, read fresh; the column list is a constant.
			'ARRAY_A'
		);

		return is_array( $row ) ? new Bulk_Run( $row ) : null;
	}

	/**
	 * Accounts that have a run in progress: the sweep's list.
	 *
	 * @since 2.1.8
	 *
	 * @return int[]
	 */
	public function running_accounts(): array {
		$ids = $this->db->get_col( $this->db->prepare( 'SELECT account_id FROM %i WHERE state = %s', $this->table, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Starts a run. A finished run (or none) gives way; a running one never does.
	 *
	 * @since 2.1.8
	 *
	 * @param int                 $account_id     Account.
	 * @param string              $run_id         Token of the new run.
	 * @param string              $action         Action id.
	 * @param array<string,mixed> $payload        The action's validated fields.
	 * @param array<string,mixed> $filter         The validated query.
	 * @param int                 $user_id        Who starts it.
	 * @param int                 $products_total The apply-time count.
	 * @return bool False when a run is still going (or another start won the race).
	 */
	public function start( int $account_id, string $run_id, string $action, array $payload, array $filter, int $user_id, int $products_total ): bool {
		$existing = $this->find( $account_id );
		$values   = $this->start_values( $run_id, $action, $payload, $filter, $user_id, $products_total );

		if ( null === $existing ) {
			return 1 === $this->db->insert( $this->table, [ 'account_id' => $account_id ] + $values ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table; the primary key refuses a second row.
		}

		if ( $existing->is_running() ) {
			return false;
		}

		$sets = [];
		$args = [ $this->table ];

		foreach ( $values as $column => $value ) {
			$sets[] = ( 'cursor' === $column ? '`cursor`' : $column ) . ' = ' . ( null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' ) );

			if ( null !== $value ) {
				$args[] = $value;
			}
		}

		$args[] = $account_id;
		$args[] = $existing->run_id();
		$args[] = Bulk_Run::RUNNING;

		$changed = $this->db->query( $this->db->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE account_id = %d AND run_id = %s AND state <> %s', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Own table; only placeholders are concatenated.

		return 1 === $changed;
	}

	/**
	 * Writes the token of the tick that owns the run now. Called right after the named lock is granted.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     The run the tick read from the queued action.
	 * @param string $token      Fresh random token.
	 * @return bool False when the run is not that one, or no longer running.
	 */
	public function take_tick( int $account_id, string $run_id, string $token ): bool {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET tick = %s WHERE account_id = %d AND run_id = %s AND state = %s', $this->table, $token, $account_id, $run_id, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Saves the progress of a tick.
	 *
	 * @since 2.1.8
	 *
	 * @param int                            $account_id Account.
	 * @param string                         $run_id     Run.
	 * @param string                         $tick       The writer's token; another tick's save is refused.
	 * @param array<string,mixed>            $cursor     Position.
	 * @param array<string,int>              $counts     Counters.
	 * @param array<int,array<string,mixed>> $report     Groups.
	 * @return bool False when this tick no longer owns the run: it must stop at once.
	 */
	public function save( int $account_id, string $run_id, string $tick, array $cursor, array $counts, array $report ): bool {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET `cursor` = %s, counts = %s, report = %s WHERE account_id = %d AND run_id = %s AND tick = %s AND state = %s', $this->table, $this->encode( $cursor ), $this->encode( $counts ), $this->encode( $report ), $account_id, $run_id, $tick, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * The terminal write of a tick: the final state with the last position, counters and report. It is the owner's last write.
	 *
	 * @since 2.1.8
	 *
	 * @param int                            $account_id Account.
	 * @param string                         $run_id     Run.
	 * @param string                         $tick       The writer's token.
	 * @param string                         $state      `done`, `cancelled` or `stopped`.
	 * @param array<string,mixed>            $cursor     Position.
	 * @param array<string,int>              $counts     Counters.
	 * @param array<int,array<string,mixed>> $report     Groups.
	 * @return bool False when this tick no longer owns the run.
	 */
	public function finish( int $account_id, string $run_id, string $tick, string $state, array $cursor, array $counts, array $report ): bool {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET state = %s, finished_at = %s, `cursor` = %s, counts = %s, report = %s WHERE account_id = %d AND run_id = %s AND tick = %s AND state = %s', $this->table, $state, $this->now(), $this->encode( $cursor ), $this->encode( $counts ), $this->encode( $report ), $account_id, $run_id, $tick, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Ends a running run without a tick: «Остановить» that got the named lock itself, or a tick that stops a run before it took its token.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @param string $state      `cancelled` or `stopped`.
	 * @return bool False when the run is not that one, or already over.
	 */
	public function end_under_lock( int $account_id, string $run_id, string $state ): bool {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET state = %s, finished_at = %s WHERE account_id = %d AND run_id = %s AND state = %s', $this->table, $state, $this->now(), $account_id, $run_id, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Asks the tick that works on the run to stop after its part.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     The run the merchant saw on the line.
	 * @return bool False when that run is not the running one any more.
	 */
	public function request_cancel( int $account_id, string $run_id ): bool {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET cancel = 1 WHERE account_id = %d AND run_id = %s AND state = %s', $this->table, $account_id, $run_id, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Reads the cancel flag fresh, after a part.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @return bool
	 */
	public function is_cancel_requested( int $account_id, string $run_id ): bool {
		return '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT cancel FROM %i WHERE account_id = %d AND run_id = %s', $this->table, $account_id, $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
	}

	/**
	 * The save of the part that froze the remote-cards run's list (§2.7): the list and the position after the freeze — cursor, counters
	 * and report — in **one** conditional `UPDATE`, fenced by the tick like {@see self::save()}. They are never two writes: a process
	 * that dies between them would leave a stored list the run does not know it has, and the next tick would freeze again from a
	 * snapshot that may have expired or changed.
	 *
	 * @since 2.1.10
	 *
	 * @param int                                 $account_id Account.
	 * @param string                              $run_id     Run.
	 * @param string                              $tick       The writer's token; another tick's save is refused.
	 * @param array<int,array{0:string,1:string}> $items      `[ article, group key ]` of every card, in the order the import must go.
	 * @param array<string,mixed>                 $cursor     Position after the freeze.
	 * @param array<string,int>                   $counts     Counters.
	 * @param array<int,array<string,mixed>>      $report     Groups.
	 * @return bool False when this tick no longer owns the run: it must stop at once.
	 */
	public function save_frozen( int $account_id, string $run_id, string $tick, array $items, array $cursor, array $counts, array $report ): bool {
		$json = wp_json_encode( $items, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) && 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET items = %s, `cursor` = %s, counts = %s, report = %s WHERE account_id = %d AND run_id = %s AND tick = %s AND state = %s', $this->table, $json, $this->encode( $cursor ), $this->encode( $counts ), $this->encode( $report ), $account_id, $run_id, $tick, Bulk_Run::RUNNING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Reads the frozen card list of a run. The one place that reads the heavy `items` column.
	 *
	 * @since 2.1.10
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @return array<int,array{0:string,1:string}>|null Null when the run has no list yet (or is not that run any more).
	 */
	public function items( int $account_id, string $run_id ): ?array {
		$json  = $this->db->get_var( $this->db->prepare( 'SELECT items FROM %i WHERE account_id = %d AND run_id = %s', $this->table, $account_id, $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
		$items = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;

		return is_array( $items ) ? $items : null;
	}

	/**
	 * Deletes a batch of an account's rows (the account's deletion).
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		return Account_Rows::delete( $this->db, $this->table, $account_id, $limit );
	}

	/**
	 * The columns a start writes, in the order of the table.
	 *
	 * @param string              $run_id         Run.
	 * @param string              $action         Action.
	 * @param array<string,mixed> $payload        Fields.
	 * @param array<string,mixed> $filter         Query.
	 * @param int                 $user_id        User.
	 * @param int                 $products_total Estimate.
	 * @return array<string,int|string|null>
	 */
	private function start_values( string $run_id, string $action, array $payload, array $filter, int $user_id, int $products_total ): array {
		return [
			'run_id'      => $run_id,
			'state'       => Bulk_Run::RUNNING,
			'cancel'      => 0,
			'tick'        => null,
			'action'      => $action,
			'payload'     => $this->encode( $payload ),
			'filter'      => $this->encode( $filter ),
			'user_id'     => $user_id,
			'cursor'      => $this->encode( [] ),
			'items'       => null,
			'counts'      => $this->encode( [ 'products_total' => $products_total ] ),
			'report'      => $this->encode( [] ),
			'started_at'  => $this->now(),
			'finished_at' => null,
		];
	}

	/**
	 * @param array<mixed> $value Value.
	 * @return string JSON, Cyrillic kept as is: the report column stays small.
	 */
	private function encode( array $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * @return string `Y-m-d H:i:s`, UTC.
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) );
	}
}
