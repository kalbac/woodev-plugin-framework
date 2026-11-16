<?php
/**
 * Scheduling of the `reconcile_stock` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for `woodev_{plugin id}_reconcile_stock`: one daily recurring run per account whose stock mode is
 * «marketplace is master» (arguments `[ account_id ]`), and the single continuations a run queues for itself (arguments
 * `[ account_id, cursor_link_id, attempt ]`).
 *
 * The hour of the daily run is {@see self::DEFAULT_HOUR} (site time) unless the filter `woodev_{plugin id}_reconcile_stock_hour`
 * returns another hour from 0 to 23. It is the plugin's technical choice, not a merchant setting.
 *
 * @since 2.1.8
 */
final class Reconcile_Stock_Scheduler {
	public const JOB          = 'reconcile_stock';
	public const HOUR_FILTER  = 'reconcile_stock_hour';
	public const DEFAULT_HOUR = 4;

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
	 * Returns the hook of the job.
	 *
	 * @since 2.1.8
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Returns the hour of the daily run, site time.
	 *
	 * @since 2.1.8
	 *
	 * @return int 0 to 23.
	 */
	public function hour(): int {
		$hour = apply_filters( $this->names->hook( self::HOUR_FILTER ), self::DEFAULT_HOUR ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Job_Names builds `woodev_{plugin id}_…` in snake case.

		return is_numeric( $hour ) ? min( 23, max( 0, (int) $hour ) ) : self::DEFAULT_HOUR;
	}

	/**
	 * Returns the next moment the daily run is due: the configured hour of the site's day, today when it is still ahead.
	 *
	 * @since 2.1.8
	 *
	 * @param int $now Unix time.
	 * @return int Unix time.
	 */
	public function next_run( int $now ): int {
		$due = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( $this->hour(), 0 );

		return ( $due->getTimestamp() > $now ? $due : $due->modify( '+1 day' ) )->getTimestamp();
	}

	/**
	 * Makes the daily runs match the accounts that need them: one for each of the given accounts, none for any other.
	 *
	 * @since 2.1.8
	 *
	 * @param int[] $account_ids Accounts that are enabled and in the `marketplace` stock mode.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler did not create a run.
	 */
	public function sync( array $account_ids ): void {
		$account_ids = array_values( array_unique( array_map( 'intval', $account_ids ) ) );

		$this->remove_unwanted( $account_ids );

		foreach ( $account_ids as $account_id ) {
			if ( as_has_scheduled_action( $this->hook(), [ $account_id ], $this->names->group() ) ) {
				continue;
			}

			if ( ! as_schedule_recurring_action( $this->next_run( time() ), DAY_IN_SECONDS, $this->hook(), [ $account_id ], $this->names->group() ) ) {
				throw new \RuntimeException( 'Action Scheduler did not create the daily stock reconcile of account ' . (int) $account_id . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}
	}

	/**
	 * Queues the continuation of a run (the next page, a wait or a retry); one waiting continuation per argument set is enough.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account id.
	 * @param int $cursor     Last link id the earlier pages covered.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @return bool False when Action Scheduler refused or threw.
	 */
	public function continue_later( int $account_id, int $cursor, int $attempt, int $delay ): bool {
		$args = [ $account_id, $cursor, $attempt ];

		try {
			return $this->has_pending( $args ) || 0 !== as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), $args, $this->names->group() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Removes the daily runs of accounts that no longer need one: switched off, in another stock mode, or deleted. Continuations
	 * (three arguments) are left to finish.
	 *
	 * @param int[] $wanted Accounts that keep their run.
	 * @return void
	 */
	private function remove_unwanted( array $wanted ): void {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => $this->hook(),
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => -1,
			]
		);

		foreach ( (array) $actions as $action ) {
			$args = (array) $action->get_args();

			if ( 1 === count( $args ) && ! in_array( (int) $args[0], $wanted, true ) ) {
				as_unschedule_all_actions( $this->hook(), $args, $this->names->group() );
			}
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
