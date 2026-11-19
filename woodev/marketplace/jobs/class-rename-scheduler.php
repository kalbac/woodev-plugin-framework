<?php
/**
 * Scheduling of the article rename job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_rename_offer_ids( account_id, attempt, put_offs )`: sends the articles the merchant changed after the export. The job reads what
 * waits from the links (not from its arguments), so one queued action serves every change saved before it runs.
 *
 * @since 2.1.13
 */
final class Rename_Scheduler {
	public const JOB = 'rename_offer_ids';

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.13
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the job.
	 *
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Queues the job of an account. A job that waits unstarted already covers this one.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler is not there or refuses the action.
	 */
	public function queue( int $account_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler is not available.' );
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $this->hook(), [ $account_id, 0, 0 ], $this->names->group() ) ) {
			return;
		}

		$this->again( $account_id, 0, 0, 0 );
	}

	/**
	 * Schedules the job again, after a wait or a failed attempt or to carry on with the next page.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @param int $delay      Seconds to wait; 0 runs it as soon as a queue runner is free.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $put_offs   Waits so far.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	public function again( int $account_id, int $delay, int $attempt, int $put_offs ): void {
		$args    = [ $account_id, $attempt, $put_offs ];
		$planned = $delay > 0
			? as_schedule_single_action( time() + $delay, $this->hook(), $args, $this->names->group() )
			: as_enqueue_async_action( $this->hook(), $args, $this->names->group() );

		if ( 0 === $planned ) {
			throw new \RuntimeException( 'Action Scheduler did not queue the article change.' );
		}
	}
}
