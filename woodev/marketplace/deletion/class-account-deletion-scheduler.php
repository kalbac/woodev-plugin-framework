<?php
/**
 * Scheduling of the account deletion job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Jobs\Job_Names;

defined( 'ABSPATH' ) || exit;

/**
 * One Action Scheduler action, `woodev_{plugin id}_delete_account( account_id, attempt )`. The first run is `attempt` 0; a run that
 * has more to do, finds the account busy or failed schedules the next one itself.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Scheduler {
	public const JOB = 'delete_account';

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.5
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the job.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Queues the deletion of an account unless one is already queued or running.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return bool False when Action Scheduler is not available or refused the action.
	 */
	public function queue( int $account_id ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		if ( as_has_scheduled_action( $this->hook(), [ $account_id, 0 ], $this->names->group() ) ) {
			return true;
		}

		return 0 !== as_enqueue_async_action( $this->hook(), [ $account_id, 0 ], $this->names->group() );
	}

	/**
	 * Schedules the next run of a deletion that is not finished.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $attempt    Failed runs so far.
	 * @param int $delay      Seconds to wait.
	 * @return bool False when Action Scheduler is not available or refused the action.
	 */
	public function later( int $account_id, int $attempt, int $delay ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		return 0 !== as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), [ $account_id, $attempt ], $this->names->group() );
	}

	/**
	 * Returns the Action Scheduler group of the plugin.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function group(): string {
		return $this->names->group();
	}
}
