<?php
/**
 * Action Scheduler, inbound-event and async-task queue health for diagnostics.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the state of Action Scheduler for one marketplace plugin's group (`woodev_{plugin_id}`) through its public API
 * (`as_get_scheduled_actions()`, the store's `query_actions()`), whatever data store it uses, alongside the plugin's own
 * inbound `events` and async `tasks` tables.
 *
 * Without Action Scheduler the answers are `null` / `0`; the table queries run with `$wpdb->suppress_errors( true )`.
 *
 * @since 2.1.2
 */
final class Queue_Health {
	/**
	 * Grace window (seconds) before a scheduled due action is counted as overdue.
	 */
	public const OVERDUE_GRACE_SECONDS = 300;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Action Scheduler group slug (`Job_Names::group()`).
	 *
	 * @var string
	 */
	private $group;

	/**
	 * Optional marketplace schema for `events` / `tasks` queue counts.
	 *
	 * @var Schema|null
	 */
	private $schema;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Action Scheduler store override (tests); null reads `ActionScheduler::store()`.
	 *
	 * @var object|null
	 */
	private $store;

	/**
	 * Creates the queue health reader.
	 *
	 * @since 2.1.2
	 *
	 * @param \wpdb         $db     Database handle.
	 * @param Job_Names     $names  Plugin job names (supplies the Action Scheduler group).
	 * @param Schema|null   $schema Optional marketplace schema for `events` and `tasks` counts.
	 * @param callable|null $clock  `function (): int`, defaults to `time`.
	 * @param object|null   $store  Action Scheduler store with `get_date( $id )` and `query_actions( $query, 'count' )`; `ActionScheduler::store()` when null.
	 */
	public function __construct( \wpdb $db, Job_Names $names, ?Schema $schema = null, ?callable $clock = null, ?object $store = null ) {
		$this->db     = $db;
		$this->group  = $names->group();
		$this->schema = $schema;
		$this->clock  = $clock ?? 'time';
		$this->store  = $store;
	}

	/**
	 * Unix timestamp of the last finished action of this plugin's group, or null when none finished or the queue cannot be read.
	 *
	 * «Last run» is the moment the newest action FINISHED, not the date it was scheduled for, so the query orders by `modified`
	 * and the date is read through the store's `get_date()`, which answers the execution date of a completed action.
	 *
	 * @since 2.1.2
	 *
	 * @return int|null
	 */
	public function last_run_at(): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$ids = as_get_scheduled_actions(
			[
				'status'   => 'complete',
				'group'    => $this->group,
				'orderby'  => 'modified',
				'order'    => 'DESC',
				'per_page' => 1,
			],
			'ids'
		);

		$store = $this->store();
		if ( ! is_array( $ids ) || [] === $ids || null === $store ) {
			return null;
		}

		$date = $store->get_date( (string) reset( $ids ) );

		return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
	}

	/**
	 * Counts this plugin's pending actions that are past their time by more than the grace window.
	 *
	 * @since 2.1.2
	 *
	 * @return int
	 */
	public function overdue_count(): int {
		$store = $this->store();
		if ( null === $store || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		return max(
			0,
			(int) $store->query_actions(
				[
					'status'       => 'pending',
					'group'        => $this->group,
					'date'         => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) - self::OVERDUE_GRACE_SECONDS ),
					'date_compare' => '<=',
				],
				'count'
			)
		);
	}

	/**
	 * The Action Scheduler store, or null when Action Scheduler is not loaded.
	 *
	 * @return object|null
	 */
	private function store(): ?object {
		if ( null !== $this->store ) {
			return $this->store;
		}

		return class_exists( '\\ActionScheduler' ) ? \ActionScheduler::store() : null;
	}

	/**
	 * Summarizes the plugin's inbound `events` and async `tasks` queues when a {@see Schema} was provided.
	 *
	 * @since 2.1.2
	 *
	 * @return array{events_pending:int,events_processing:int,events_failed:int,oldest_event_age:?int,open_tasks:int,failed_task_items:int}
	 */
	public function ledger_summary(): array {
		$empty = [
			'events_pending'    => 0,
			'events_processing' => 0,
			'events_failed'     => 0,
			'oldest_event_age'  => null,
			'open_tasks'        => 0,
			'failed_task_items' => 0,
		];

		if ( null === $this->schema ) {
			return $empty;
		}

		$events     = $this->schema->table_name( 'events', $this->db->prefix );
		$tasks      = $this->schema->table_name( 'tasks', $this->db->prefix );
		$task_items = $this->schema->table_name( 'task_items', $this->db->prefix );
		$now        = (int) call_user_func( $this->clock );

		$prev = $this->db->suppress_errors( true );

		$event_rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'SELECT status, COUNT(*) AS cnt, MIN(received_at) AS oldest FROM %i GROUP BY status', $events ),
			'ARRAY_A'
		);

		$open_tasks = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)',
				$tasks,
				Async_Task::STATUS_SUBMITTED,
				Async_Task::STATUS_POLLING
			)
		);

		$failed_items = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$task_items,
				'failed'
			)
		);

		$this->db->suppress_errors( $prev );

		$summary                      = $empty;
		$summary['open_tasks']        = max( 0, $open_tasks );
		$summary['failed_task_items'] = max( 0, $failed_items );

		$oldest_waiting = null;

		foreach ( is_array( $event_rows ) ? $event_rows : [] as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$count  = (int) ( $row['cnt'] ?? 0 );

			if ( Wpdb_Event_Ledger::STATUS_NEW === $status || Wpdb_Event_Ledger::STATUS_RETRY === $status ) {
				$summary['events_pending'] += $count;
				$oldest_ts                  = self::gmt_to_timestamp( $row['oldest'] ?? null );
				if ( null !== $oldest_ts && ( null === $oldest_waiting || $oldest_ts < $oldest_waiting ) ) {
					$oldest_waiting = $oldest_ts;
				}
			} elseif ( Wpdb_Event_Ledger::STATUS_PROCESSING === $status ) {
				$summary['events_processing'] = $count;
			} elseif ( Wpdb_Event_Ledger::STATUS_FAILED === $status ) {
				$summary['events_failed'] = $count;
			}
		}

		if ( null !== $oldest_waiting ) {
			$summary['oldest_event_age'] = max( 0, $now - $oldest_waiting );
		}

		return $summary;
	}

	/**
	 * Parses a MySQL UTC datetime string into a Unix timestamp, ignoring zero-date placeholders.
	 *
	 * @param mixed $value Raw `Y-m-d H:i:s` string or null.
	 * @return int|null
	 */
	private static function gmt_to_timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return false === $dt ? null : $dt->getTimestamp();
	}
}
