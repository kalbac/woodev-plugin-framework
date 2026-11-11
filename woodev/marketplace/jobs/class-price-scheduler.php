<?php
/**
 * Scheduling of the price jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls of the price policy:
 *
 * - `woodev_{plugin id}_recompute_prices` (args `[ account_id, after_link_id ]`, `[ …, attempt ]` on a retry): re-works the
 *   wanted price of every link of an account after a rule changed, one page per run, rescheduling itself with the cursor.
 * - `woodev_{plugin id}_refresh_prices` (args `[ account_id, offer_ids[≤100] ]`): the `from_marketplace` read of the
 *   marketplace's prices into the shop.
 *
 * There is no flush hook here: the one `flush_sync` action per account belongs to the outer sender
 * ({@see Flush_Sync_Runner}), which calls {@see Price_Flush_Runner::flush_under_claim()} for the price half.
 *
 * @since 2.0.30
 */
final class Price_Scheduler {
	public const RECOMPUTE_JOB = 'recompute_prices';
	public const REFRESH_JOB   = 'refresh_prices';

	/**
	 * Most links one recompute run re-works.
	 */
	public const RECOMPUTE_PAGE = 100;

	/**
	 * Most articles one refresh run reads.
	 */
	public const REFRESH_BATCH = 100;

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
	 * Returns the hook of the recompute job.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function recompute_hook(): string {
		return $this->names->hook( self::RECOMPUTE_JOB );
	}

	/**
	 * Returns the hook of the refresh job.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function refresh_hook(): string {
		return $this->names->hook( self::REFRESH_JOB );
	}

	/**
	 * Queues the re-working of every link of an account. A request that waits unstarted already covers this one.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function queue_recompute( int $account_id ): void {
		if ( as_has_scheduled_action( $this->recompute_hook(), [ $account_id, 0 ], $this->names->group() ) ) {
			return;
		}

		$this->recompute_from( $account_id, 0 );
	}

	/**
	 * Schedules the next page of a recompute, or the same page again after a failure.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id    Account id.
	 * @param int $after_link_id Last link id already re-worked.
	 * @param int $delay         Seconds to wait; 0 runs it as soon as a queue runner is free.
	 * @param int $attempt       Failed attempts of this page so far; 0 for the next page.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function recompute_from( int $account_id, int $after_link_id, int $delay = 0, int $attempt = 0 ): void {
		$args    = $attempt > 0 ? [ $account_id, $after_link_id, $attempt ] : [ $account_id, $after_link_id ];
		$planned = $delay > 0
			? as_schedule_single_action( time() + $delay, $this->recompute_hook(), $args, $this->names->group() )
			: as_enqueue_async_action( $this->recompute_hook(), $args, $this->names->group() );

		if ( 0 === $planned ) {
			throw new \RuntimeException( 'Action Scheduler did not queue the price recompute.' );
		}
	}

	/**
	 * Schedules a refresh batch again, after a wait or a failed attempt.
	 *
	 * @since 2.0.30
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @param int      $attempt    Failed attempts so far.
	 * @param int      $delay      Seconds to wait.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function refresh_later( int $account_id, array $offer_ids, int $attempt, int $delay ): void {
		if ( 0 === as_schedule_single_action( time() + max( 0, $delay ), $this->refresh_hook(), [ $account_id, array_values( $offer_ids ), $attempt ], $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not schedule the price refresh.' );
		}
	}

	/**
	 * Queues the `from_marketplace` read of some articles, in batches of {@see self::REFRESH_BATCH}.
	 *
	 * @since 2.0.30
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @return int Batches queued.
	 * @throws \RuntimeException When Action Scheduler refuses a batch.
	 */
	public function queue_refresh( int $account_id, array $offer_ids ): int {
		$batches = array_chunk( array_values( array_unique( array_map( 'strval', $offer_ids ) ) ), self::REFRESH_BATCH );

		foreach ( $batches as $batch ) {
			if ( 0 === as_enqueue_async_action( $this->refresh_hook(), [ $account_id, $batch ], $this->names->group() ) ) {
				throw new \RuntimeException( 'Action Scheduler did not queue the price refresh.' );
			}
		}

		return count( $batches );
	}
}
