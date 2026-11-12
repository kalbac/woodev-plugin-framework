<?php
/**
 * Label tasks and label documents.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
use Woodev\Framework\Marketplace\Ledger\Async_Task;

defined( 'ABSPATH' ) || exit;

/**
 * The two tables that a label touches: `tasks` (rows of `kind = label`) and `documents` (the label body, a blob; never a file
 * under `uploads/`).
 *
 * A task row keeps in `result` whatever JSON the marketplace answered next to the remote-order keys the task was made for, so the
 * UI can be adjusted without a schema change.
 */
final class Wpdb_Label_Store {
	/**
	 * Days a document is kept.
	 */
	public const KEEP_DAYS = 7;

	/**
	 * Key of the task `result` that holds the remote-order keys.
	 */
	public const RESULT_EXTERNAL_KEYS = 'external_keys';

	/**
	 * Key of the task `result` that holds the marketplace's answer, minus the file itself.
	 */
	public const RESULT_ANSWER = 'answer';

	/**
	 * Key of the task `result` that holds the text shown to the merchant when the task failed.
	 */
	public const RESULT_MESSAGE = 'message';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Tasks table.
	 *
	 * @var string
	 */
	private $tasks;

	/**
	 * Documents table.
	 *
	 * @var string
	 */
	private $documents;

	/**
	 * Clock: `function (): int` Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @param \wpdb         $db        Database.
	 * @param string        $tasks     Full name of the tasks table.
	 * @param string        $documents Full name of the documents table.
	 * @param callable|null $clock     `function (): int` Unix time; defaults to `time()`.
	 */
	public function __construct( \wpdb $db, string $tasks, string $documents, ?callable $clock = null ) {
		$this->db        = $db;
		$this->tasks     = $tasks;
		$this->documents = $documents;
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * Deletes a batch of an account's stored documents. Its tasks belong to the task ledger.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		return Account_Rows::delete( $this->db, $this->documents, $account_id, $limit );
	}

	/**
	 * Counts an account's stored documents.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function count_for_account( int $account_id ): int {
		return Account_Rows::count( $this->db, $this->documents, $account_id );
	}

	/**
	 * Stores a new label task.
	 *
	 * @param int      $account_id     Account id.
	 * @param string   $remote_task_id The marketplace's task id.
	 * @param string[] $external_keys       Remote-order keys the task was made for.
	 * @return int Local task id.
	 * @throws Persistence_Exception When the row could not be written.
	 */
	public function add_task( int $account_id, string $remote_task_id, array $external_keys ): int {
		$external_keys = array_values( $external_keys );
		$inserted = $this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$this->tasks,
			[
				'account_id'     => $account_id,
				'kind'           => Async_Task::KIND_LABEL,
				'remote_task_id' => $remote_task_id,
				'status'         => Async_Task::STATUS_SUBMITTED,
				'submitted_at'   => $this->stamp( $this->now() ),
				'item_count'     => count( $external_keys ),
				'request_hash'   => sha1( implode( ',', $external_keys ) ),
				'result'         => (string) wp_json_encode( [ self::RESULT_EXTERNAL_KEYS => $external_keys ] ),
			]
		);

		if ( false === $inserted ) {
			throw Persistence_Exception::database( 'a label task', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Reads a task.
	 *
	 * @param int $task_id Local task id.
	 * @return array{task_id:int,account_id:int,kind:string,remote_task_id:string,status:string,submitted_at:int,poll_count:int,result:array<string,mixed>}|null Null when there is no such label task.
	 * @throws Persistence_Exception When the read failed in the database (a missing task is not an error).
	 */
	public function task( int $task_id ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A polled task must be read fresh.
			$this->db->prepare( 'SELECT task_id, account_id, kind, remote_task_id, status, submitted_at, poll_count, result FROM %i WHERE task_id = %d AND kind = %s', $this->tasks, $task_id, Async_Task::KIND_LABEL ),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			$this->require_no_error( 'a label task' );

			return null;
		}

		$result = json_decode( (string) $row['result'], true );

		return [
			'task_id'        => (int) $row['task_id'],
			'account_id'     => (int) $row['account_id'],
			'kind'           => (string) $row['kind'],
			'remote_task_id' => (string) $row['remote_task_id'],
			'status'         => (string) $row['status'],
			'submitted_at'   => (int) strtotime( (string) $row['submitted_at'] . ' UTC' ),
			'poll_count'     => (int) $row['poll_count'],
			'result'         => is_array( $result ) ? $result : [],
		];
	}

	/**
	 * Records one poll of a task and keeps it `polling`.
	 *
	 * @param int $task_id Local task id.
	 * @return void
	 * @throws Persistence_Exception When the write failed.
	 */
	public function touch_polled( int $task_id ): void {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare(
				'UPDATE %i SET status = %s, last_polled_at = %s, poll_count = poll_count + 1 WHERE task_id = %d AND status IN (%s, %s)',
				$this->tasks,
				Async_Task::STATUS_POLLING,
				$this->stamp( $this->now() ),
				$task_id,
				Async_Task::STATUS_SUBMITTED,
				Async_Task::STATUS_POLLING
			)
		);

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'the poll of a label task', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Finishes a task. A task that is already finished is left as it is.
	 *
	 * @param int                 $task_id Local task id.
	 * @param string              $status  `Async_Task::STATUS_*` of a finished task.
	 * @param array<string,mixed> $result  The whole new `result`.
	 * @return bool False when the task was already finished (nothing changed): the status is the fence, the loser of two runners changes 0 rows.
	 * @throws Persistence_Exception When the write failed.
	 */
	public function finish_task( int $task_id, string $status, array $result ): bool {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare(
				'UPDATE %i SET status = %s, finished_at = %s, result = %s WHERE task_id = %d AND status IN (%s, %s)',
				$this->tasks,
				$status,
				$this->stamp( $this->now() ),
				(string) wp_json_encode( $result ),
				$task_id,
				Async_Task::STATUS_SUBMITTED,
				Async_Task::STATUS_POLLING
			)
		);

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'the end of a label task', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === (int) $changed;
	}

	/**
	 * Stores a label body.
	 *
	 * @param int      $account_id   Account id.
	 * @param int      $task_id      Local task id.
	 * @param string[] $external_keys     Remote-order keys the body covers.
	 * @param string   $content_type Content type to stream it with.
	 * @param string   $body         The bytes.
	 * @return int Document id.
	 * @throws Persistence_Exception When the row could not be written.
	 */
	public function add_document( int $account_id, int $task_id, array $external_keys, string $content_type, string $body ): int {
		$inserted = $this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$this->documents,
			[
				'account_id'      => $account_id,
				'task_id'         => $task_id,
				'external_keys' => (string) wp_json_encode( array_values( $external_keys ) ),
				'content_type'    => $content_type,
				'body'            => $body,
				'created_at'      => $this->stamp( $this->now() ),
			]
		);

		if ( false === $inserted ) {
			throw Persistence_Exception::database( 'a label document', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Publishes a label document and ends its task in one transaction: the document exists only if this call won the status fence
	 * of the task (`submitted` or `polling` → `done`). A poll that lost (the task expired or finished meanwhile) or failed leaves
	 * nothing behind, so there is no cleanup that could fail.
	 *
	 * @param int                 $task_id      Local task id.
	 * @param int                 $account_id   Account id.
	 * @param string[]            $external_keys     Remote-order keys the body covers.
	 * @param string              $content_type Content type to stream it with.
	 * @param string              $body         The bytes.
	 * @param array<string,mixed> $result       The whole new task `result`.
	 * @return int|null Document id, null when the task was no longer open.
	 * @throws Persistence_Exception When a write failed (nothing is kept).
	 * @throws \Throwable What a failed write threw, after the rollback.
	 */
	public function publish_document( int $task_id, int $account_id, array $external_keys, string $content_type, string $body, array $result ): ?int {
		$this->db->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.

		try {
			$document_id = $this->add_document( $account_id, $task_id, $external_keys, $content_type, $body );

			if ( ! $this->finish_task( $task_id, Async_Task::STATUS_DONE, $result ) ) {
				$this->db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.

				return null;
			}
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.

			throw $e;
		}

		if ( false === $this->db->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.
			throw Persistence_Exception::database( 'a label document', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $document_id;
	}

	/**
	 * Reads a document.
	 *
	 * @param int $document_id Document id.
	 * @return array{document_id:int,account_id:int,task_id:int,external_keys:string[],content_type:string,body:string}|null
	 */
	public function document( int $document_id ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A download reads the current row.
			$this->db->prepare( 'SELECT document_id, account_id, task_id, external_keys, content_type, body FROM %i WHERE document_id = %d', $this->documents, $document_id ),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$external_keys = json_decode( (string) $row['external_keys'], true );

		return [
			'document_id'     => (int) $row['document_id'],
			'account_id'      => (int) $row['account_id'],
			'task_id'         => (int) $row['task_id'],
			'external_keys' => is_array( $external_keys ) ? array_values( array_map( 'strval', $external_keys ) ) : [],
			'content_type'    => (string) $row['content_type'],
			'body'            => (string) $row['body'],
		];
	}

	/**
	 * Finds the document of a task, for the screen that offers the download.
	 *
	 * @param int $task_id Local task id.
	 * @return int|null Document id.
	 */
	public function document_of_task( int $task_id ): ?int {
		$id = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh read.
			$this->db->prepare( 'SELECT document_id FROM %i WHERE task_id = %d ORDER BY document_id DESC LIMIT 1', $this->documents, $task_id )
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Expires label tasks still open after 24 hours, then deletes the documents older than the keep time and the label tasks that finished as long ago.
	 *
	 * @return int Documents deleted.
	 */
	public function prune(): int {
		$limit = $this->stamp( $this->now() - self::KEEP_DAYS * DAY_IN_SECONDS );

		// A task still open after 24 hours is expired, whatever stopped its polls.
		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare(
				'UPDATE %i SET status = %s, finished_at = %s WHERE kind = %s AND submitted_at < %s AND status IN (%s, %s)',
				$this->tasks,
				Async_Task::STATUS_EXPIRED,
				$this->stamp( $this->now() ),
				Async_Task::KIND_LABEL,
				$this->stamp( $this->now() - DAY_IN_SECONDS ),
				Async_Task::STATUS_SUBMITTED,
				Async_Task::STATUS_POLLING
			)
		);

		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare( 'DELETE FROM %i WHERE kind = %s AND submitted_at < %s AND status NOT IN (%s, %s)', $this->tasks, Async_Task::KIND_LABEL, $limit, Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING )
		);

		return (int) $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here.
			$this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->documents, $limit )
		);
	}

	/**
	 * Throws when the last statement failed in the database.
	 *
	 * @param string $what What was being read.
	 * @return void
	 * @throws Persistence_Exception When `$wpdb->last_error` is set.
	 */
	private function require_no_error( string $what ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw Persistence_Exception::database( $what, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	public function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Formats a Unix time as a UTC `datetime`.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function stamp( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}
}
