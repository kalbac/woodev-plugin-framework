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
 * Reads the state of Action Scheduler for one marketplace plugin's group (`woodev_{plugin_id}`), alongside the plugin's
 * inbound `events` and async `tasks` tables.
 *
 * Queries run with `$wpdb->suppress_errors( true )` so a shop where Action Scheduler tables are not installed yet (or
 * an in-memory unit-test database without them) degrades cleanly to `null` / `0`.
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
	 * Creates the queue health reader.
	 *
	 * @since 2.1.2
	 *
	 * @param \wpdb         $db     Database handle.
	 * @param Job_Names     $names  Plugin job names (supplies the Action Scheduler group).
	 * @param Schema|null   $schema Optional marketplace schema for `events` and `tasks` counts.
	 * @param callable|null $clock  `function (): int`, defaults to `time`.
	 */
	public function __construct( \wpdb $db, Job_Names $names, ?Schema $schema = null, ?callable $clock = null ) {
		$this->db     = $db;
		$this->group  = $names->group();
		$this->schema = $schema;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Unix timestamp of the most recent Action Scheduler claim or completed plugin action, or null when unknown.
	 *
	 * @since 2.1.2
	 *
	 * @return int|null
	 */
	public function last_run_at(): ?int {
		$claims  = $this->db->prefix . 'actionscheduler_claims';
		$actions = $this->db->prefix . 'actionscheduler_actions';
		$groups  = $this->db->prefix . 'actionscheduler_groups';

		$prev = $this->db->suppress_errors( true );

		$claim_gmt = $this->db->get_var( "SELECT MAX(date_created_gmt) FROM {$claims}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table name.

		$completed_gmt = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"SELECT MAX(a.last_attempt_gmt) FROM {$actions} a INNER JOIN {$groups} g ON a.group_id = g.group_id WHERE g.slug = %s AND a.status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table names.
				$this->group,
				'complete'
			)
		);

		$this->db->suppress_errors( $prev );

		$claim_ts     = self::gmt_to_timestamp( $claim_gmt );
		$completed_ts = self::gmt_to_timestamp( $completed_gmt );

		if ( null === $claim_ts ) {
			return $completed_ts;
		}
		if ( null === $completed_ts ) {
			return $claim_ts;
		}

		return max( $claim_ts, $completed_ts );
	}

	/**
	 * Counts pending actions in this plugin's Action Scheduler group whose scheduled time is older than the grace window.
	 *
	 * @since 2.1.2
	 *
	 * @return int
	 */
	public function overdue_count(): int {
		$actions   = $this->db->prefix . 'actionscheduler_actions';
		$groups    = $this->db->prefix . 'actionscheduler_groups';
		$threshold = gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) - self::OVERDUE_GRACE_SECONDS );

		$prev  = $this->db->suppress_errors( true );
		$count = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$actions} a INNER JOIN {$groups} g ON a.group_id = g.group_id WHERE g.slug = %s AND a.status = %s AND a.scheduled_date_gmt > %s AND a.scheduled_date_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table names.
				$this->group,
				'pending',
				'0000-00-00 00:00:00',
				$threshold
			)
		);
		$this->db->suppress_errors( $prev );

		return max( 0, (int) $count );
	}

	/**
	 * Counts pending actions in this plugin's Action Scheduler group (due or future).
	 *
	 * @since 2.1.2
	 *
	 * @return int
	 */
	public function pending_actions_count(): int {
		$actions = $this->db->prefix . 'actionscheduler_actions';
		$groups  = $this->db->prefix . 'actionscheduler_groups';

		$prev  = $this->db->suppress_errors( true );
		$count = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$actions} a INNER JOIN {$groups} g ON a.group_id = g.group_id WHERE g.slug = %s AND a.status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table names.
				$this->group,
				'pending'
			)
		);
		$this->db->suppress_errors( $prev );

		return max( 0, (int) $count );
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
