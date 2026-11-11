<?php
/**
 * Scheduling of the export jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for `woodev_{plugin id}_export_batch` (args `[ account_id, link_ids, attempt ]`, at most
 * {@see self::BATCH_SIZE} links) and `woodev_{plugin id}_poll_task` (args `[ task_id, attempt ]`), plus the recurring recovery sweep
 * `poll_task( 0, 0 )`. Action Scheduler's own retry is not used: the runners reschedule themselves with an attempt counter.
 *
 * @since 2.1.0
 */
final class Export_Scheduler {
	public const EXPORT_JOB = 'export_batch';
	public const POLL_JOB   = 'poll_task';

	/**
	 * Most links in one `export_batch` (one import request).
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Seconds between two recovery sweeps (`poll_task( 0 )`).
	 */
	public const SWEEP_INTERVAL = 900;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the export batch.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function export_hook(): string {
		return $this->names->hook( self::EXPORT_JOB );
	}

	/**
	 * Returns the hook of the task poll.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function poll_hook(): string {
		return $this->names->hook( self::POLL_JOB );
	}

	/**
	 * Makes sure the recurring recovery sweep exists.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 * @throws \RuntimeException When Action Scheduler did not create it.
	 */
	public function ensure_sweep(): void {
		$args = [ 0, 0 ];

		if ( as_has_scheduled_action( $this->poll_hook(), $args, $this->names->group() ) ) {
			return;
		}

		if ( ! as_schedule_recurring_action( time() + self::SWEEP_INTERVAL, self::SWEEP_INTERVAL, $this->poll_hook(), $args, $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the export recovery sweep.' );
		}
	}

	/**
	 * Queues the export of links, in batches of {@see self::BATCH_SIZE}. Every batch is tried even when an earlier one was refused,
	 * so one bad moment of Action Scheduler strands as few links as possible.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return int Batches queued.
	 * @throws Export_Not_Scheduled When Action Scheduler refused or threw on a batch; it names the links of those batches.
	 */
	public function queue_export( int $account_id, array $link_ids ): int {
		$batches     = array_chunk( array_values( array_unique( array_map( 'intval', $link_ids ) ) ), self::BATCH_SIZE );
		$unscheduled = [];

		foreach ( $batches as $batch ) {
			if ( ! $this->enqueue_batch( $account_id, $batch ) ) {
				$unscheduled = array_merge( $unscheduled, $batch );
			}
		}

		if ( [] !== $unscheduled ) {
			throw new Export_Not_Scheduled( $unscheduled );
		}

		return count( $batches );
	}

	/**
	 * Queues one batch.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $batch      Link ids.
	 * @return bool False when Action Scheduler refused it or threw.
	 */
	private function enqueue_batch( int $account_id, array $batch ): bool {
		try {
			return 0 !== as_enqueue_async_action( $this->export_hook(), [ $account_id, $batch, 0 ], $this->names->group() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Schedules an export batch again.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @param int   $attempt    Failed attempts so far.
	 * @param int   $delay      Seconds to wait.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function retry_export( int $account_id, array $link_ids, int $attempt, int $delay ): void {
		$this->single( $this->export_hook(), [ $account_id, array_values( array_map( 'intval', $link_ids ) ), $attempt ], $delay );
	}

	/**
	 * Schedules the next poll of a task.
	 *
	 * @since 2.1.0
	 *
	 * @param int $task_id Task id.
	 * @param int $attempt Failed polls in a row.
	 * @param int $delay   Seconds to wait.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function poll_later( int $task_id, int $attempt, int $delay ): void {
		$this->single( $this->poll_hook(), [ $task_id, $attempt ], $delay );
	}

	/**
	 * Schedules a single action.
	 *
	 * @param string       $hook  Hook.
	 * @param array<mixed> $args  Arguments.
	 * @param int          $delay Seconds to wait.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler answers 0.
	 */
	private function single( string $hook, array $args, int $delay ): void {
		if ( 0 === as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not schedule ' . $hook . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}
}
