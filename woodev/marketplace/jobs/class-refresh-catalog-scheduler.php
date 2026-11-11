<?php
/**
 * Scheduling of the `refresh_catalog` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for `woodev_{plugin id}_refresh_catalog`: a weekly recurring run, an async run when the marketplace announces
 * a tree change, and the reschedule of a pass that ran out of time. The job arguments are `[ account_id, since, attempt ]`;
 * `account_id` 0 means "the first enabled account", `since` and `attempt` are filled by the runner when it reschedules itself.
 *
 * @since 2.0.30
 */
final class Refresh_Catalog_Scheduler {
	public const JOB  = 'refresh_catalog';
	public const WEEK = 604800;

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
	 * Returns the hook of the job.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Makes sure the weekly run exists.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 * @throws \RuntimeException When Action Scheduler did not create it.
	 */
	public function ensure_weekly(): void {
		$args = [ 0 ];

		if ( as_has_scheduled_action( $this->hook(), $args, $this->names->group() ) ) {
			return;
		}

		if ( ! as_schedule_recurring_action( time() + self::WEEK, self::WEEK, $this->hook(), $args, $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the weekly catalogue refresh.' );
		}
	}

	/**
	 * Queues a run for an account now, unless one is already waiting.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account that pays for the calls.
	 * @return bool Whether a run is queued.
	 */
	public function queue_now( int $account_id ): bool {
		$args = [ $account_id ];

		if ( $this->has_pending( $args ) ) {
			return true;
		}

		try {
			return 0 !== as_enqueue_async_action( $this->hook(), $args, $this->names->group() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Queues the continuation of a pass; one waiting continuation per argument set is enough.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account that pays for the calls.
	 * @param int $since      Freshness limit of the pass being continued.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action (it answers 0 instead of throwing).
	 */
	public function continue_later( int $account_id, int $since, int $attempt, int $delay ): void {
		$args = [ $account_id, $since, $attempt ];

		if ( $this->has_pending( $args ) ) {
			return;
		}

		if ( 0 === as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), $args, $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the refresh_catalog continuation.' );
		}
	}

	/**
	 * Tells whether a run with these arguments is waiting.
	 *
	 * @param int[] $args Job arguments.
	 * @return bool
	 */
	private function has_pending( array $args ): bool {
		$ids = as_get_scheduled_actions(
			[
				'hook'     => $this->hook(),
				'args'     => $args,
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		return is_array( $ids ) && [] !== $ids;
	}
}
