<?php
/**
 * What the «Заказы» table knows about the labels of its rows, and the way it asks for them.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Ledger\Async_Task;

defined( 'ABSPATH' ) || exit;

/**
 * A local, read-only look at the label tasks and documents of an account (the tables {@see Wpdb_Label_Store} writes), keyed by the
 * posting number a label was made for; and the one write the table makes, which is asking for a set of labels.
 *
 * Only the recent past is read, and only the documents and tasks that name one of the asked posting numbers: a busy account's own
 * newest rows say nothing about the page on screen. A document is kept {@see Wpdb_Label_Store::KEEP_DAYS} days and a task that is
 * still open after a day is expired, so older rows are either gone or not news. The read is bounded ({@see self::LIMIT} rows of each)
 * and never calls the marketplace.
 *
 * The state of a posting number is `document_id` (the newest ready document that covers it, 0 for none), `open_task` (the id of the
 * label task that is being made now, 0 for none) and `failure` (what the merchant is told when the newest task for it ended without
 * a document, empty for none).
 *
 * @since 2.1.5
 */
final class Site_Orders_Labels {
	/**
	 * Most documents and most tasks one read looks at.
	 */
	private const LIMIT = 300;

	/**
	 * How long an unsuccessful task is still worth telling about, in seconds.
	 */
	private const FAILURE_WINDOW = DAY_IN_SECONDS;

	/**
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
	 * Full name of the documents table.
	 *
	 * @var string
	 */
	private $documents;

	/**
	 * The download address of a document: `function ( int $document_id ): string`.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * Asks the marketplace for labels and queues the poll: `function ( Account $account, string[] $keys ): int`, the local task id.
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the labels part.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb         $db        Database.
	 * @param string        $tasks     Full name of the tasks table.
	 * @param string        $documents Full name of the documents table.
	 * @param callable      $url       `function ( int $document_id ): string`, the address a document is downloaded from.
	 * @param callable      $request   `function ( Account $account, string[] $keys ): int`, asks for labels and returns the task id.
	 * @param callable|null $clock     Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $tasks, string $documents, callable $url, callable $request, ?callable $clock = null ) {
		$this->db        = $db;
		$this->tasks     = $tasks;
		$this->documents = $documents;
		$this->url       = $url;
		$this->request   = $request;
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * The state of each posting number: the state of a number nobody made labels for is all empty.
	 *
	 * @since 2.1.5
	 *
	 * @param int      $account_id Account.
	 * @param string[] $keys       Posting numbers.
	 * @return array<string,array{document_id:int,open_task:int,failure:string}> By posting number, for every number asked.
	 */
	public function states( int $account_id, array $keys ): array {
		$states = [];
		$wanted = array_flip( $keys );

		foreach ( $keys as $key ) {
			$states[ $key ] = self::none();
		}

		foreach ( $this->recent_documents( $account_id, $keys ) as $document ) {
			foreach ( array_intersect_key( array_flip( $document['keys'] ), $wanted ) as $key => $unused ) {
				if ( 0 === $states[ $key ]['document_id'] ) {
					$states[ $key ]['document_id'] = $document['id'];
				}
			}
		}

		$seen = [];

		foreach ( $this->recent_tasks( $account_id, $keys ) as $task ) {
			foreach ( array_intersect_key( array_flip( $task['keys'] ), $wanted ) as $key => $unused ) {
				if ( isset( $seen[ $key ] ) ) {
					continue; // Only the newest task of a number says anything.
				}

				$seen[ $key ] = true;
				$states[ $key ] = $this->task_state( $states[ $key ], $task );
			}
		}

		return $states;
	}

	/**
	 * The newest ready document that covers every one of the posting numbers.
	 *
	 * @since 2.1.5
	 *
	 * @param int      $account_id Account.
	 * @param string[] $keys       Posting numbers.
	 * @return int Document id; 0 when there is none.
	 */
	public function covering( int $account_id, array $keys ): int {
		foreach ( $this->recent_documents( $account_id, $keys ) as $document ) {
			if ( [] === array_diff( $keys, $document['keys'] ) ) {
				return $document['id'];
			}
		}

		return 0;
	}

	/**
	 * The address a document is downloaded from.
	 *
	 * @since 2.1.5
	 *
	 * @param int $document_id Document id.
	 * @return string
	 */
	public function url( int $document_id ): string {
		return (string) call_user_func( $this->url, $document_id );
	}

	/**
	 * Asks the marketplace for the labels of postings and queues the poll that collects them.
	 *
	 * @since 2.1.5
	 *
	 * @param Account  $account Account the postings belong to.
	 * @param string[] $keys    Posting numbers.
	 * @return int Local task id.
	 * @throws \Woodev\Framework\Marketplace\Flows\Label_Exception With a sentence for the merchant.
	 * @throws \Throwable When the marketplace failed in another way.
	 */
	public function request( Account $account, array $keys ): int {
		return (int) call_user_func( $this->request, $account, $keys );
	}

	/**
	 * The state of a number nobody made labels for.
	 *
	 * @since 2.1.5
	 *
	 * @return array{document_id:int,open_task:int,failure:string}
	 */
	public static function none(): array {
		return [
			'document_id' => 0,
			'open_task'   => 0,
			'failure'     => '',
		];
	}

	/**
	 * Applies the newest task of a number to its state.
	 *
	 * @param array{document_id:int,open_task:int,failure:string}      $state State so far.
	 * @param array{id:int,status:string,message:string,keys:string[]} $task  Newest task of the number.
	 * @return array{document_id:int,open_task:int,failure:string}
	 */
	private function task_state( array $state, array $task ): array {
		if ( in_array( $task['status'], [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], true ) ) {
			$state['open_task'] = $task['id'];
		} elseif ( in_array( $task['status'], [ Async_Task::STATUS_FAILED, Async_Task::STATUS_EXPIRED ], true ) && 0 === $state['document_id'] ) {
			$state['failure'] = '' === $task['message'] ? __( 'Маркетплейс не смог подготовить этикетки.', 'woodev-plugin-framework' ) : $task['message'];
		}

		return $state;
	}

	/**
	 * Ready documents of the account that name any of the posting numbers, newest first.
	 *
	 * @param int      $account_id Account.
	 * @param string[] $keys       Posting numbers.
	 * @return array<int,array{id:int,keys:string[]}>
	 */
	private function recent_documents( int $account_id, array $keys ): array {
		if ( [] === $keys ) {
			return [];
		}

		$names = $this->naming( 'external_keys', $keys );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				'SELECT document_id, external_keys FROM %i WHERE account_id = %d AND created_at >= %s AND (' . $names['sql'] . ') ORDER BY document_id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders only.
				...array_merge(
					[ $this->documents, $account_id, $this->stamp( $this->now() - Wpdb_Label_Store::KEEP_DAYS * DAY_IN_SECONDS ) ],
					$names['args'],
					[ self::LIMIT ]
				)
			), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
			'ARRAY_A'
		);
		$out   = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'id'   => (int) $row['document_id'],
				'keys' => self::keys( json_decode( (string) $row['external_keys'], true ) ),
			];
		}

		return $out;
	}

	/**
	 * Label tasks of the account from the last day that name any of the posting numbers, newest first.
	 *
	 * @param int      $account_id Account.
	 * @param string[] $keys       Posting numbers.
	 * @return array<int,array{id:int,status:string,message:string,keys:string[]}>
	 */
	private function recent_tasks( int $account_id, array $keys ): array {
		if ( [] === $keys ) {
			return [];
		}

		$names = $this->naming( 'result', $keys );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				'SELECT task_id, status, result FROM %i WHERE account_id = %d AND kind = %s AND submitted_at >= %s AND (' . $names['sql'] . ') ORDER BY task_id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders only.
				...array_merge(
					[ $this->tasks, $account_id, Async_Task::KIND_LABEL, $this->stamp( $this->now() - self::FAILURE_WINDOW ) ],
					$names['args'],
					[ self::LIMIT ]
				)
			), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
			'ARRAY_A'
		);
		$out   = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$result = json_decode( (string) $row['result'], true );
			$result = is_array( $result ) ? $result : [];

			$out[] = [
				'id'      => (int) $row['task_id'],
				'status'  => (string) $row['status'],
				'message' => trim( (string) ( $result[ Wpdb_Label_Store::RESULT_MESSAGE ] ?? '' ) ),
				'keys'    => self::keys( $result[ Wpdb_Label_Store::RESULT_EXTERNAL_KEYS ] ?? [] ),
			];
		}

		return $out;
	}

	/**
	 * The `WHERE` part that finds the stored JSON lists (a column of posting numbers) that name any of the keys: one prepared `LIKE`
	 * per key over the key as the store wrote it, quotes included, so `P-1` never matches `P-10`.
	 *
	 * @param string   $column The column that holds the JSON.
	 * @param string[] $keys   Posting numbers, not empty.
	 * @return array{sql:string,args:string[]}
	 */
	private function naming( string $column, array $keys ): array {
		$args = [];

		foreach ( $keys as $key ) {
			$args[] = '%' . $this->db->esc_like( (string) wp_json_encode( (string) $key ) ) . '%';
		}

		return [
			'sql'  => implode( ' OR ', array_fill( 0, count( $args ), $column . ' LIKE %s' ) ),
			'args' => $args,
		];
	}

	/**
	 * A stored list of posting numbers, cleaned.
	 *
	 * @param mixed $keys The decoded list.
	 * @return string[]
	 */
	private static function keys( $keys ): array {
		return is_array( $keys ) ? array_values( array_map( 'strval', array_filter( $keys, 'is_scalar' ) ) ) : [];
	}

	/**
	 * @return int The Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * @param int $time Unix time.
	 * @return string UTC `Y-m-d H:i:s`.
	 */
	private function stamp( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}
}
