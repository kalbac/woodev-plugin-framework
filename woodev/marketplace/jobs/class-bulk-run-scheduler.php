<?php
/**
 * Scheduling of the bulk run's ticks.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for `woodev_{plugin id}_bulk_run( account_id, run_id )` (#1795): one tick of a run, and the recurring recovery
 * sweep `bulk_run( 0, '' )` that queues the tick of a run whose next tick was lost. The runner reschedules itself after every tick
 * with no delay; Action Scheduler's own retry is not used.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Scheduler {
	public const JOB = 'bulk_run';

	/**
	 * Seconds between two recovery sweeps: the export sweep's interval.
	 */
	public const SWEEP_INTERVAL = Export_Scheduler::SWEEP_INTERVAL;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.8
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * The hook of the tick.
	 *
	 * @since 2.1.8
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Queues the tick of a run.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @param int    $delay      Seconds to wait.
	 * @return bool False when Action Scheduler is not there or refused the action.
	 */
	public function enqueue( int $account_id, string $run_id, int $delay = 0 ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		try {
			return 0 !== as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), [ $account_id, $run_id ], $this->names->group() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether a tick of this run waits in the queue.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @return bool
	 */
	public function has_pending( int $account_id, string $run_id ): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $this->hook(), [ $account_id, $run_id ], $this->names->group() );
	}

	/**
	 * Makes sure the recurring recovery sweep exists.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 * @throws \RuntimeException When Action Scheduler did not create it.
	 */
	public function ensure_sweep(): void {
		$args = [ 0, '' ];

		if ( as_has_scheduled_action( $this->hook(), $args, $this->names->group() ) ) {
			return;
		}

		if ( ! as_schedule_recurring_action( time() + self::SWEEP_INTERVAL, self::SWEEP_INTERVAL, $this->hook(), $args, $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the bulk run recovery sweep.' );
		}
	}
}
