<?php
/**
 * Scheduling of the `reconcile_stock` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for `woodev_{plugin id}_reconcile_stock`: one daily recurring run per enabled account that syncs stock
 * (any stock mode but «off»; arguments `[ account_id ]`), and the single continuations a run queues for itself (arguments
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
	 * Most continuations an account may queue in one day; with the daily run that is the whole of its day's work.
	 */
	public const MAX_CONTINUATIONS_PER_DAY = 60;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Unix time: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.8
	 *
	 * @param Job_Names     $names Job names of the plugin.
	 * @param callable|null $clock `function (): int`, the moment the daily window and the first run are worked out from; the system clock when null.
	 */
	public function __construct( Job_Names $names, ?callable $clock = null ) {
		$this->names = $names;
		$this->clock = $clock ?? 'time';
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
	 * @param int[] $account_ids Accounts that are enabled and sync stock.
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

			if ( ! as_schedule_recurring_action( $this->next_run( (int) call_user_func( $this->clock ) ), DAY_IN_SECONDS, $this->hook(), [ $account_id ], $this->names->group() ) ) {
				throw new \RuntimeException( 'Action Scheduler did not create the daily stock reconcile of account ' . (int) $account_id . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}
	}

	/**
	 * Queues the continuation of a run (the next page, a wait or a retry); one waiting continuation per argument set is enough.
	 * An account gets at most {@see self::MAX_CONTINUATIONS_PER_DAY} continuations a day, so a pass that cannot finish stops
	 * instead of calling the marketplace all day.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account id.
	 * @param int $cursor     Last link id the earlier pages covered.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @param int $put_offs   Waits so far (2.1.12); carried as a fourth argument only when it is not 0.
	 * @return bool False when Action Scheduler refused or threw, or the day's continuations are spent.
	 */
	public function continue_later( int $account_id, int $cursor, int $attempt, int $delay, int $put_offs = 0 ): bool {
		$args = [ $account_id, $cursor, $attempt ];

		if ( $put_offs > 0 ) {
			$args[] = $put_offs;
		}

		try {
			if ( $this->has_pending( $args ) ) {
				return true;
			}

			$used = $this->continuations_today( $account_id );

			if ( $used >= self::MAX_CONTINUATIONS_PER_DAY ) {
				return false;
			}

			if ( 0 === as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), $args, $this->names->group() ) ) {
				return false;
			}

			set_transient( $this->counter_key( $account_id ), $used + 1, DAY_IN_SECONDS );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Returns the continuations an account was given in the current window (the site's day, counted from the hour of the daily run).
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function continuations_today( int $account_id ): int {
		return max( 0, (int) get_transient( $this->counter_key( $account_id ) ) );
	}

	/**
	 * Names the transient that counts an account's continuations of the current window. The window is the site's day measured from
	 * the hour of the daily run, the boundary the schedule itself uses, so a daily run always opens a fresh allowance.
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function counter_key( int $account_id ): string {
		$window = ( new \DateTimeImmutable( '@' . (int) call_user_func( $this->clock ) ) )
			->setTimezone( wp_timezone() )
			->modify( '-' . $this->hour() . ' hours' )
			->format( 'Ymd' );

		return $this->hook() . '_cont_' . $account_id . '_' . $window;
	}

	/**
	 * Removes the daily runs of accounts that no longer need one: switched off, with stock sync off, or deleted. Continuations
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
