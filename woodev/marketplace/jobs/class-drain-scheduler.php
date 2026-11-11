<?php
/**
 * Schedules the event drain in Action Scheduler.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that talks to Action Scheduler about `drain_events`. The notification intake asks for a drain with
 * {@see self::schedule()}; the runner asks for the next one with {@see self::schedule_later()}.
 *
 * Two kinds of action share the plugin's group ({@see Job_Names::group()}):
 *
 * - `woodev_{plugin id}_drain_events( account_id )` — an async action, at most one pending per account;
 * - `woodev_{plugin id}_drain_events_sweep( account_id )` — a recurring five-minute action per account that drains whatever a
 *   failed enqueue or an expired lease left behind. It is a hook of its own because `as_has_scheduled_action()` could not tell
 *   the pending async action from the recurring one under a single name, and a recurring action pending forever would then
 *   stop every async one from being queued.
 *
 * @since 2.0.30
 */
final class Drain_Scheduler {
	public const JOB       = 'drain_events';
	public const SWEEP_JOB = 'drain_events_sweep';

	/**
	 * Seconds between sweeps.
	 */
	public const SWEEP_INTERVAL = 300;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.0.30
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the drain.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Returns the hook of the recurring sweep.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function sweep_hook(): string {
		return $this->names->hook( self::SWEEP_JOB );
	}

	/**
	 * Makes sure the account's events will be processed: establishes the recurring sweep first, then queues an immediate drain
	 * unless one is already pending.
	 *
	 * The sweep comes first because it is the recovery: if the immediate drain cannot be queued, the sweep still processes the
	 * event within five minutes. Only when the sweep itself cannot be established does this throw, and the caller must then not
	 * acknowledge the notification.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @return bool True when the immediate drain is queued (or one was already pending); false when only the sweep will
	 *              process the event.
	 * @throws \RuntimeException When the sweep could not be established.
	 */
	public function schedule( int $account_id ): bool {
		$this->ensure_sweep( $account_id );

		return $this->has_pending_drain( $account_id ) || $this->queue_async( $account_id );
	}

	/**
	 * Queues the next drain after a delay, for a runner that has more to do.
	 *
	 * The running action is not a successor: only a pending drain stops this from queueing another.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @param int $delay      Seconds from now.
	 * @return void
	 */
	public function schedule_later( int $account_id, int $delay ): void {
		if ( ! $this->has_pending_drain( $account_id ) ) {
			as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), [ $account_id ], $this->names->group() );
		}
	}

	/**
	 * Creates the recurring sweep of an account if it does not exist (pending or running).
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action (it returns 0 rather than throwing).
	 */
	public function ensure_sweep( int $account_id ): void {
		if ( as_has_scheduled_action( $this->sweep_hook(), [ $account_id ], $this->names->group() ) ) {
			return;
		}

		if ( ! as_schedule_recurring_action( time() + self::SWEEP_INTERVAL, self::SWEEP_INTERVAL, $this->sweep_hook(), [ $account_id ], $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the recurring drain sweep.' );
		}
	}

	/**
	 * Tells whether a drain is waiting in the queue. `as_has_scheduled_action()` also answers true for the action that is
	 * running right now, which would make a running drain believe a successor exists.
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	private function has_pending_drain( int $account_id ): bool {
		$ids = as_get_scheduled_actions(
			[
				'hook'     => $this->hook(),
				'args'     => [ $account_id ],
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		return is_array( $ids ) && [] !== $ids;
	}

	/**
	 * Queues an immediate drain.
	 *
	 * @param int $account_id Account id.
	 * @return bool False when Action Scheduler refused it or threw.
	 */
	private function queue_async( int $account_id ): bool {
		try {
			return 0 !== as_enqueue_async_action( $this->hook(), [ $account_id ], $this->names->group() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
