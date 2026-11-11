<?php
/**
 * Read-only database queries for marketplace diagnostics.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Reads recent inbound events, product-link counts, stock/price/task backlog and recent API errors from the
 * marketplace plugin's own tables (`Schema`), redacting free-text fields via {@see Report_Redactor}.
 *
 * Never performs remote HTTP calls.
 *
 * @since 2.1.2
 */
final class Diagnostics_Reader {
	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Marketplace schema.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param \wpdb         $db     Database handle.
	 * @param Schema        $schema Marketplace schema.
	 * @param callable|null $clock  `function (): int`, defaults to `time`.
	 */
	public function __construct( \wpdb $db, Schema $schema, ?callable $clock = null ) {
		$this->db     = $db;
		$this->schema = $schema;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Returns the newest inbound events of an account (`type`, `status`, `received_at`, `attempts`, redacted `result`).
	 *
	 * @since 2.1.2
	 *
	 * @param int      $account_id    Account id.
	 * @param int      $limit         Max rows (clamped to 1..50).
	 * @param string[] $secret_fields Adapter secret field names for redaction.
	 * @param string[] $known_secrets Known plaintext secrets/tokens for redaction.
	 * @return array<int,array{event_id:int,type:string,status:string,received_at:int,attempts:int,result:string}>
	 */
	public function recent_events( int $account_id, int $limit = 10, array $secret_fields = [], array $known_secrets = [] ): array {
		$table = $this->schema->table_name( 'events', $this->db->prefix );
		$limit = max( 1, min( 50, $limit ) );

		$prev = $this->db->suppress_errors( true );
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT event_id, type, status, received_at, attempts, result FROM %i WHERE account_id = %d ORDER BY event_id DESC LIMIT %d',
				$table,
				$account_id,
				$limit
			),
			'ARRAY_A'
		);
		$this->db->suppress_errors( $prev );

		$result = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$result[] = [
				'event_id'    => (int) $row['event_id'],
				'type'        => (string) $row['type'],
				'status'      => (string) $row['status'],
				'received_at' => self::utc_to_timestamp( (string) ( $row['received_at'] ?? '' ) ) ?? 0,
				'attempts'    => (int) $row['attempts'],
				'result'      => Report_Redactor::redact_text( (string) ( $row['result'] ?? '' ), $secret_fields, $known_secrets ),
			];
		}

		return $result;
	}

	/**
	 * Counts product links of an account grouped by `card_state`.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return array{ready:int,pending:int,failed:int,archived:int}
	 */
	public function link_counts( int $account_id ): array {
		$table  = $this->schema->table_name( 'links', $this->db->prefix );
		$counts = [
			'ready'    => 0,
			'pending'  => 0,
			'failed'   => 0,
			'archived' => 0,
		];

		$prev = $this->db->suppress_errors( true );
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT card_state, COUNT(*) AS cnt FROM %i WHERE account_id = %d GROUP BY card_state',
				$table,
				$account_id
			),
			'ARRAY_A'
		);
		$this->db->suppress_errors( $prev );

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$state = (string) ( $row['card_state'] ?? '' );
			$count = (int) $row['cnt'];

			if ( Product_Link::STATE_CREATED === $state || 'ready' === $state ) {
				$counts['ready'] += $count;
			} elseif ( Product_Link::STATE_PENDING_REMOTE_IDS === $state || 'pending' === $state || 'moderating' === $state ) {
				$counts['pending'] += $count;
			} elseif ( 'failed' === $state ) {
				$counts['failed'] += $count;
			} elseif ( 'archived' === $state ) {
				$counts['archived'] += $count;
			}
		}

		return $counts;
	}

	/**
	 * Summarizes the stock/price sync state, open async tasks, failed task items and active rate-limit throttle for an account.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return array{stock_pending:int,stock_errors:int,price_pending:int,price_errors:int,open_tasks:int,failed_items:int,throttled_until:?int}
	 */
	public function sync_backlog( int $account_id ): array {
		$sync_table  = $this->schema->table_name( 'sync_state', $this->db->prefix );
		$tasks_table = $this->schema->table_name( 'tasks', $this->db->prefix );
		$items_table = $this->schema->table_name( 'task_items', $this->db->prefix );
		$throttle    = $this->schema->table_name( 'throttle', $this->db->prefix );
		$now_gmt     = gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) );

		$prev = $this->db->suppress_errors( true );

		$sync_rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT kind, pending_rev, sent_rev, last_error FROM %i WHERE account_id = %d',
				$sync_table,
				$account_id
			),
			'ARRAY_A'
		);

		$open_tasks = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i WHERE account_id = %d AND status IN (%s, %s)',
				$tasks_table,
				$account_id,
				Async_Task::STATUS_SUBMITTED,
				Async_Task::STATUS_POLLING
			)
		);

		$failed_items = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i i INNER JOIN %i t ON i.task_id = t.task_id WHERE t.account_id = %d AND i.status = %s',
				$items_table,
				$tasks_table,
				$account_id,
				'failed'
			)
		);

		$disabled_until = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT MAX(disabled_until) FROM %i WHERE account_id = %d AND disabled_until > %s',
				$throttle,
				$account_id,
				$now_gmt
			)
		);

		$this->db->suppress_errors( $prev );

		$stock_pending = 0;
		$stock_errors  = 0;
		$price_pending = 0;
		$price_errors  = 0;

		foreach ( is_array( $sync_rows ) ? $sync_rows : [] as $row ) {
			$kind    = (string) ( $row['kind'] ?? '' );
			$dirty   = (int) ( $row['pending_rev'] ?? 0 ) > (int) ( $row['sent_rev'] ?? 0 );
			$errored = '' !== trim( (string) ( $row['last_error'] ?? '' ) );

			if ( 'stock' === $kind ) {
				$stock_pending += $dirty ? 1 : 0;
				$stock_errors  += $errored ? 1 : 0;
			} elseif ( 'price' === $kind ) {
				$price_pending += $dirty ? 1 : 0;
				$price_errors  += $errored ? 1 : 0;
			}
		}

		return [
			'stock_pending'   => $stock_pending,
			'stock_errors'    => $stock_errors,
			'price_pending'   => $price_pending,
			'price_errors'    => $price_errors,
			'open_tasks'      => max( 0, $open_tasks ),
			'failed_items'    => max( 0, $failed_items ),
			'throttled_until' => is_string( $disabled_until ) ? self::utc_to_timestamp( $disabled_until ) : null,
		];
	}

	/**
	 * Reads the newest entries from the `api_errors` ring table, redacted.
	 *
	 * @since 2.1.2
	 *
	 * @param int      $limit         Max entries.
	 * @param string[] $secret_fields Adapter secret field names for redaction.
	 * @param string[] $known_secrets Known plaintext secrets/tokens for redaction.
	 * @return array<int,array{account_id:int,path:string,http_status:int,code:string,message:string,trace_id:string,occurred_at:int}>
	 */
	public function recent_errors( int $limit = 20, array $secret_fields = [], array $known_secrets = [] ): array {
		$table = $this->schema->table_name( 'api_errors', $this->db->prefix );
		$limit = max( 1, min( 200, $limit ) );

		$prev = $this->db->suppress_errors( true );
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT account_id, path, http_status, code, message, trace_id, occurred_at FROM %i ORDER BY error_id DESC LIMIT %d',
				$table,
				$limit
			),
			'ARRAY_A'
		);
		$this->db->suppress_errors( $prev );

		$result = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$result[] = [
				'account_id'  => (int) ( $row['account_id'] ?? 0 ),
				'path'        => Report_Redactor::redact_text( (string) ( $row['path'] ?? '' ), $secret_fields, $known_secrets ),
				'http_status' => (int) ( $row['http_status'] ?? 0 ),
				'code'        => Report_Redactor::redact_text( (string) ( $row['code'] ?? '' ), $secret_fields, $known_secrets ),
				'message'     => Report_Redactor::redact_text( (string) ( $row['message'] ?? '' ), $secret_fields, $known_secrets ),
				'trace_id'    => Report_Redactor::redact_text( (string) ( $row['trace_id'] ?? '' ), $secret_fields, $known_secrets ),
				'occurred_at' => self::utc_to_timestamp( (string) ( $row['occurred_at'] ?? '' ) ) ?? 0,
			];
		}

		return $result;
	}

	/**
	 * Formats a Unix timestamp in site local time (`d.m.Y H:i`).
	 *
	 * @since 2.1.2
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function date_text( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return (string) wp_date( 'd.m.Y H:i', $timestamp );
	}

	/**
	 * Formats a past timestamp as a relative Russian string (`5 мин назад`, `только что`).
	 *
	 * @since 2.1.2
	 *
	 * @param int $timestamp Past Unix timestamp.
	 * @param int $now       Current Unix timestamp.
	 * @return string
	 */
	public static function age_text( int $timestamp, int $now ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		$diff = $now - $timestamp;
		if ( $diff < 60 ) {
			return __( 'только что', 'woodev-plugin-framework' );
		}

		/* translators: %s: human-readable elapsed time from human_time_diff(). */
		return sprintf( __( '%s назад', 'woodev-plugin-framework' ), human_time_diff( $timestamp, $now ) );
	}

	/**
	 * Converts a UTC `Y-m-d H:i:s` string into a Unix timestamp.
	 *
	 * @param string $utc MySQL UTC datetime.
	 * @return int|null
	 */
	private static function utc_to_timestamp( string $utc ): ?int {
		if ( '' === $utc || 0 === strpos( $utc, '0000-00-00' ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );

		return false === $dt ? null : $dt->getTimestamp();
	}
}
