<?php
/**
 * Database task ledger.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * The task ledger over the `tasks` and `task_items` tables of spec 3.1. Times are stored in UTC.
 *
 * @since 2.0.29
 */
final class Wpdb_Task_Ledger implements Task_Ledger {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the tasks table.
	 *
	 * @var string
	 */
	private $tasks;

	/**
	 * Full name of the items table.
	 *
	 * @var string
	 */
	private $items;

	/**
	 * Clock: Unix time in seconds.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the ledger.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $tasks Full name of the tasks table.
	 * @param string        $items Full name of the items table.
	 * @param callable|null $clock Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $tasks, string $items, ?callable $clock = null ) {
		$this->db    = $db;
		$this->tasks = $tasks;
		$this->items = $items;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Deletes a batch of an account's tasks: the items of its tasks first, then the tasks.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		$deleted = Account_Rows::delete_where( $this->db, $this->items, "task_id IN ( SELECT task_id FROM {$this->tasks} WHERE account_id = %d )", [ $account_id ], $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours.

		return $deleted > 0 ? $deleted : Account_Rows::delete( $this->db, $this->tasks, $account_id, $limit );
	}

	/**
	 * Counts an account's tasks.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function count_for_account( int $account_id ): int {
		return Account_Rows::count( $this->db, $this->tasks, $account_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param Async_Task        $task  Task.
	 * @param array<int,string> $items Link id => seller article.
	 * @return int
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function open( Async_Task $task, array $items ): int {
		$inserted = $this->db->insert(
			$this->tasks,
			[
				'account_id'   => $task->account_id(),
				'kind'         => $task->kind(),
				'status'       => $task->status(),
				'submitted_at' => $this->now(),
				'item_count'   => count( $items ),
				'result'       => (string) json_encode( $task->result() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
			]
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( 'The task row could not be stored: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$task_id = (int) $this->db->insert_id;
		$this->add_items( $task_id, $items );

		return $task_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int               $task_id Task id.
	 * @param array<int,string> $items   Link id => seller article.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function add_items( int $task_id, array $items ): void {
		foreach ( $items as $link_id => $offer_id ) {
			$row = [
				'task_id'  => $task_id,
				'link_id'  => (int) $link_id,
				'offer_id' => (string) $offer_id,
				'status'   => self::ITEM_PENDING,
			];

			if ( false === $this->db->insert( $this->items, $row ) ) {
				throw new \RuntimeException( 'The task item could not be stored: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}

		if ( [] !== $items ) {
			$this->db->query( $this->db->prepare( 'UPDATE %i SET item_count = (SELECT COUNT(*) FROM %i WHERE task_id = %d) WHERE task_id = %d', $this->tasks, $this->items, $task_id, $task_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int    $task_id        Task id.
	 * @param string $remote_task_id Marketplace task id.
	 * @param string $request_hash   SHA-1 of the request body.
	 * @return void
	 */
	public function attach_remote( int $task_id, string $remote_task_id, string $request_hash ): void {
		$this->db->update(
			$this->tasks,
			[
				'remote_task_id' => $remote_task_id,
				'request_hash'   => $request_hash,
			],
			[ 'task_id' => $task_id ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int    $task_id      Task id.
	 * @param string $request_hash SHA-1 of the request body.
	 * @param int[]  $link_ids     Links in the body.
	 * @return void
	 */
	public function mark_sending( int $task_id, string $request_hash, array $link_ids ): void {
		$task   = $this->get( $task_id );
		$result = null === $task ? [] : $task->result();

		$result['sent_links'] = array_values( array_map( 'intval', $link_ids ) );

		$this->db->update(
			$this->tasks,
			[
				'request_hash'   => $request_hash,
				'last_polled_at' => $this->now(),
				'result'         => (string) json_encode( $result ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
			],
			[ 'task_id' => $task_id ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	public function unmark_sending( int $task_id ): void {
		$task   = $this->get( $task_id );
		$result = null === $task ? [] : $task->result();

		unset( $result['sent_links'] );

		$this->db->update(
			$this->tasks,
			[
				'request_hash' => '',
				'result'       => (string) json_encode( $result ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
			],
			[ 'task_id' => $task_id ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $link_id Link id.
	 * @return string|null
	 */
	public function sent_offer_id( int $link_id ): ?string {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare(
				'SELECT i.offer_id, t.result FROM %i AS i INNER JOIN %i AS t ON t.task_id = i.task_id WHERE i.link_id = %d AND t.request_hash <> %s ORDER BY t.task_id DESC',
				$this->items,
				$this->tasks,
				$link_id,
				''
			),
			'ARRAY_A'
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$result = json_decode( (string) ( $row['result'] ?? '' ), true );
			$sent   = is_array( $result ) ? array_map( 'intval', (array) ( $result['sent_links'] ?? [] ) ) : [];

			if ( in_array( $link_id, $sent, true ) && '' !== (string) $row['offer_id'] ) {
				return (string) $row['offer_id'];
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $link_id Link id.
	 * @return void
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function forget_sent( int $link_id ): void {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare( 'SELECT DISTINCT t.task_id, t.result FROM %i AS i INNER JOIN %i AS t ON t.task_id = i.task_id WHERE i.link_id = %d', $this->items, $this->tasks, $link_id ),
			'ARRAY_A'
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$result = json_decode( (string) ( $row['result'] ?? '' ), true );
			$sent   = is_array( $result ) ? array_map( 'intval', (array) ( $result['sent_links'] ?? [] ) ) : [];

			if ( ! in_array( $link_id, $sent, true ) ) {
				continue;
			}

			$result['sent_links'] = array_values( array_diff( $sent, [ $link_id ] ) );

			if ( false === $this->db->update( $this->tasks, [ 'result' => (string) json_encode( $result ) ], [ 'task_id' => (int) $row['task_id'] ] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
				throw new \RuntimeException( 'The send history of a link could not be ended: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	public function mark_polled( int $task_id ): void {
		$this->db->query( $this->db->prepare( 'UPDATE %i SET last_polled_at = %s, poll_count = poll_count + 1 WHERE task_id = %d', $this->tasks, $this->now(), $task_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param string   $kind     Task kind.
	 * @param string[] $statuses Statuses.
	 * @param int      $before   Unix time.
	 * @param int      $since    Unix time.
	 * @param int      $limit    Most tasks.
	 * @return Async_Task[]
	 */
	public function untouched_since( string $kind, array $statuses, int $before, int $since, int $limit ): array {
		if ( [] === $statuses ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'SELECT task_id FROM %i WHERE kind = %s AND status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ') AND submitted_at > %s AND COALESCE(last_polled_at, submitted_at) < %s ORDER BY task_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->tasks, $kind ], array_values( $statuses ), [ gmdate( 'Y-m-d H:i:s', $since ), gmdate( 'Y-m-d H:i:s', $before ), $limit ] )
			),
			'ARRAY_A'
		);
		$ids  = array_map( 'intval', array_column( is_array( $rows ) ? $rows : [], 'task_id' ) );

		return array_values( array_filter( array_map( [ $this, 'get' ], $ids ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param string $kind     Task kind.
	 * @param int[]  $task_ids Task ids.
	 * @param int    $before   Unix time.
	 * @param int    $limit    Most tasks.
	 * @return Async_Task[]
	 */
	public function untouched_among( string $kind, array $task_ids, int $before, int $limit ): array {
		$task_ids = array_values( array_unique( array_filter( array_map( 'intval', $task_ids ) ) ) );

		if ( [] === $task_ids ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'SELECT task_id FROM %i WHERE kind = %s AND task_id IN (' . implode( ', ', array_fill( 0, count( $task_ids ), '%d' ) ) . ') AND COALESCE(last_polled_at, submitted_at) < %s ORDER BY COALESCE(last_polled_at, submitted_at) ASC, task_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->tasks, $kind ], $task_ids, [ gmdate( 'Y-m-d H:i:s', $before ), $limit ] )
			),
			'ARRAY_A'
		);
		$ids  = array_map( 'intval', array_column( is_array( $rows ) ? $rows : [], 'task_id' ) );

		return array_values( array_filter( array_map( [ $this, 'get' ], $ids ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return Async_Task|null
	 */
	public function get( int $task_id ): ?Async_Task {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE task_id = %d', $this->tasks, $task_id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.

		if ( ! is_array( $row ) ) {
			return null;
		}

		$result = json_decode( (string) ( $row['result'] ?? '' ), true );

		return new Async_Task(
			(int) $row['account_id'],
			(string) $row['kind'],
			null === $row['remote_task_id'] || '' === $row['remote_task_id'] ? null : (string) $row['remote_task_id'],
			(string) $row['status'],
			(int) $row['item_count'],
			new \DateTimeImmutable( (string) $row['submitted_at'], new \DateTimeZone( 'UTC' ) ),
			(int) $row['task_id'],
			is_array( $result ) ? $result : []
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return array<int,array{link_id:int,offer_id:string,status:string,errors:array<int,mixed>}>
	 */
	public function items( int $task_id ): array {
		$rows  = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE task_id = %d ORDER BY link_id ASC', $this->items, $task_id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$errors = json_decode( (string) ( $row['errors'] ?? '' ), true );

			$items[ (int) $row['link_id'] ] = [
				'link_id'  => (int) $row['link_id'],
				'offer_id' => (string) $row['offer_id'],
				'status'   => (string) $row['status'],
				'errors'   => is_array( $errors ) ? $errors : [],
			];
		}

		return $items;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,array<int,array{task_id:int,kind:string,status:string,offer_id:string}>>
	 */
	public function open_items( int $account_id, array $link_ids ): array {
		$link_ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $link_ids ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare(
				'SELECT i.link_id, i.offer_id, t.task_id, t.kind, t.status FROM %i i INNER JOIN %i t ON t.task_id = i.task_id'
				. ' WHERE t.account_id = %d AND i.status = %s AND t.status IN (%s, %s) AND i.link_id IN (' . implode( ', ', array_fill( 0, count( $link_ids ), '%d' ) ) . ')' // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				. ' ORDER BY t.task_id ASC, i.link_id ASC',
				array_merge( [ $this->items, $this->tasks, $account_id, self::ITEM_PENDING, Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], $link_ids )
			),
			'ARRAY_A'
		);

		$open = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$open[ (int) $row['link_id'] ][] = [
				'task_id'  => (int) $row['task_id'],
				'kind'     => (string) $row['kind'],
				'status'   => (string) $row['status'],
				'offer_id' => (string) $row['offer_id'],
			];
		}

		return $open;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int          $task_id Task id.
	 * @param int          $link_id Link id.
	 * @param string       $status  `ITEM_*`.
	 * @param array<mixed> $errors  Marketplace errors.
	 * @return void
	 */
	public function settle_item( int $task_id, int $link_id, string $status, array $errors = [] ): void {
		$this->db->update(
			$this->items,
			[
				'status' => $status,
				'errors' => [] === $errors ? null : (string) json_encode( $errors ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
			],
			[
				'task_id' => $task_id,
				'link_id' => $link_id,
			]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $task_id Task id.
	 * @param string                   $status  New status.
	 * @param array<string,mixed>|null $result  New result, or null to keep it.
	 * @return void
	 */
	public function record_poll( int $task_id, string $status, ?array $result = null ): void {
		$now     = $this->now();
		$columns = [
			'status'         => $status,
			'last_polled_at' => $now,
		];

		if ( ! in_array( $status, [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], true ) ) {
			$columns['finished_at'] = $now;
		}

		if ( null !== $result ) {
			$stored = $this->get( $task_id );
			$sent   = null === $stored ? null : ( $stored->result()['sent_links'] ?? null );

			if ( null !== $sent ) {
				$result['sent_links'] = $sent; // What was sent stays known whatever the outcome.
			}

			$columns['result'] = (string) json_encode( $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
		}

		$this->db->update( $this->tasks, $columns, [ 'task_id' => $task_id ] );
		$this->db->query( $this->db->prepare( 'UPDATE %i SET poll_count = poll_count + 1 WHERE task_id = %d', $this->tasks, $task_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Current UTC time for a datetime column.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) );
	}
}
