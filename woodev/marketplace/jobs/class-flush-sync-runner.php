<?php
/**
 * The `flush_sync` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Flush_Result;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Sync_Limits;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `woodev_{plugin id}_flush_sync( account_id )`, recurring every minute per enabled account, as the **single sender** of
 * the account:
 *
 * 1. the job gate: account enabled, licence, account pause — any wait just ends this run, the next minute tries again;
 * 2. the plugin's write gate: an account the plugin holds back writes nothing to the marketplace;
 * 3. the account row's claim — the one every other writer of the account takes — or the run exits at once: two senders never
 *    write the same pairs, and the marketplace receives an account's writes in send order;
 * 4. the stock flush ({@see Stock_Flusher}) inside one {@see Account_Run}: the claim is renewed before every request (archive
 *    calls included), every fenced write is checked, and every request counts against the run's one budget;
 * 5. then, still under the same claim, the **price half** with the token and what is left of the run's request budget (10 per
 *    run, shared by stock and price) — not called when the stock half lost the claim, or when less of the run's 20 s is left
 *    than one marketplace request may take; the claim is released in `finally`.
 *
 * **The price seam.** The price half is
 * `function ( int $account_id, string $token, int $remaining_budget, Account_Run $run ): int`, returning the requests it sent.
 * It never acquires or releases the claim, sends at most `$remaining_budget` requests and starts none once
 * `$run->remaining_seconds()` is 0; it is not called when stock used the whole budget. It is the constructor's `$price_half`,
 * else whatever the filter `woodev_{plugin id}_flush_sync_price_half` returns ({@see Price_Flush_Runner::flush_under_claim()}).
 * A price half that reports more than it was given is logged.
 *
 * Nothing is thrown out of the job: a failure is written to the error log and the next minute runs again.
 *
 * @since 2.1.0
 */
final class Flush_Sync_Runner {
	public const JOB      = 'flush_sync';
	public const INTERVAL = 60;

	/**
	 * Name of the filter that supplies the price half when none was injected, after the plugin prefix.
	 */
	public const PRICE_HALF_FILTER = 'flush_sync_price_half';

	private const CHECK_TRANSIENT = 'flush_check';

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Stock flusher.
	 *
	 * @var Stock_Flusher
	 */
	private $flusher;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * The adapter's id, which the accounts are listed by.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Per-request limits of the marketplace (how long one request may take).
	 *
	 * @var Sync_Limits
	 */
	private $limits;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * The price half, or null to ask the filter.
	 *
	 * @var callable|null
	 */
	private $price_half;

	/**
	 * Requests one run may send, stock and price together.
	 *
	 * @var int
	 */
	private $budget;

	/**
	 * Clock of the run's deadline: `function (): float`, or null for `microtime( true )`.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Names          $names          Job names of the plugin.
	 * @param Job_Gate           $gate           Job gate.
	 * @param Stock_Flusher      $flusher        Stock flusher.
	 * @param Wpdb_Account_Store $accounts       Accounts.
	 * @param string             $marketplace_id The adapter's id.
	 * @param Sync_Limits        $limits         Per-request limits of the marketplace.
	 * @param callable           $log_error      `function ( string $line ): void`.
	 * @param callable|null      $price_half     `function ( int $account_id, string $token, int $remaining_budget, Account_Run $run ): int`; null asks the filter.
	 * @param int                $budget         Requests per run, stock and price together.
	 * @param callable|null      $clock          `function (): float` for the run's deadline; `microtime( true )` when null.
	 */
	public function __construct( Job_Names $names, Job_Gate $gate, Stock_Flusher $flusher, Wpdb_Account_Store $accounts, string $marketplace_id, Sync_Limits $limits, callable $log_error, ?callable $price_half = null, int $budget = Stock_Flusher::MAX_REQUESTS, ?callable $clock = null ) {
		$this->names          = $names;
		$this->gate           = $gate;
		$this->flusher        = $flusher;
		$this->accounts       = $accounts;
		$this->marketplace_id = $marketplace_id;
		$this->limits         = $limits;
		$this->log_error      = $log_error;
		$this->price_half     = $price_half;
		$this->budget         = max( 1, $budget );
		$this->clock          = $clock;
	}

	/**
	 * Returns the hook of the job.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::JOB );
	}

	/**
	 * Hooks the job and its schedule check.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->hook(), [ $this, 'handle' ] );
		add_action( 'action_scheduler_init', [ $this, 'ensure_schedules' ] );
	}

	/**
	 * The Action Scheduler callback.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @return void
	 */
	public function handle( $account_id = 0 ): void {
		$this->run( $account_id );
	}

	/**
	 * Runs one flush of an account.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @return Stock_Flush_Result|null Null when the run did not flush (gate, write gate, another sender, failure).
	 */
	public function run( $account_id = 0 ): ?Stock_Flush_Result {
		$account_id = (int) $account_id;

		try {
			return $this->flush( $account_id );
		} catch ( \Throwable $e ) {
			$this->log( 'flush_sync: account ' . $account_id . ': ' . get_class( $e ) . ': ' . $e->getMessage() );

			return null;
		}
	}

	/**
	 * Makes sure every enabled account has its recurring flush; checked at most once an hour.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function ensure_schedules(): void {
		$transient = $this->names->hook( self::CHECK_TRANSIENT );

		if ( false !== get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, '1', HOUR_IN_SECONDS );

		try {
			foreach ( $this->accounts->all( $this->marketplace_id, true ) as $account ) {
				if ( ! as_has_scheduled_action( $this->hook(), [ $account->id() ], $this->names->group() ) ) {
					as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, $this->hook(), [ $account->id() ], $this->names->group() );
				}
			}
		} catch ( \Throwable $e ) {
			$this->log( 'flush_sync could not be scheduled: ' . $e->getMessage() );
		}
	}

	/**
	 * Applies the gate and the claim, then flushes under the claim.
	 *
	 * @param int $account_id Account id.
	 * @return Stock_Flush_Result|null
	 */
	private function flush( int $account_id ): ?Stock_Flush_Result {
		list( $account, $wait ) = $this->gate->check( $account_id );

		if ( null === $account || $wait > 0 || ! $this->gate->allows_writes( $account ) ) {
			return null;
		}

		$fence = $this->gate->claim( $account_id );

		if ( null === $fence ) {
			return null; // Another run holds the account: it is the sender now.
		}

		$run = new Account_Run(
			$account,
			$fence,
			function () use ( $account_id, $fence ): bool {
				return $this->gate->still_holds( $account_id, $fence );
			},
			$this->budget,
			Stock_Flusher::BUDGET_SECONDS,
			$this->clock
		);

		try {
			$result = $this->flusher->flush( $run );

			// The price half gets the run, so it stops starting requests at the run's deadline; it is not entered at all when less
			// time is left than one marketplace request may take.
			if ( ! $result->claim_lost && $run->remaining_seconds() >= $this->limits->request_seconds() ) {
				$result->price_requests = $this->flush_prices( $account_id, $run );
			}

			return $result;
		} finally {
			$this->gate->release( $account_id, $fence );
		}
	}

	/**
	 * Runs the price half under the run's claim with what is left of the budget.
	 *
	 * @param int         $account_id Account id.
	 * @param Account_Run $run        The run: its claim, its budget left and its deadline.
	 * @return int Requests the price half sent.
	 */
	private function flush_prices( int $account_id, Account_Run $run ): int {
		$half      = $this->price_half ?? apply_filters( $this->names->hook( self::PRICE_HALF_FILTER ), null ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Job_Names builds `woodev_{plugin id}_…` in snake case.
		$remaining = $run->remaining();

		if ( $remaining <= 0 || ! is_callable( $half ) ) {
			return 0;
		}

		$used = (int) call_user_func( $half, $account_id, $run->token(), $remaining, $run );

		if ( $used > $remaining ) {
			$this->log( 'flush_sync: account ' . $account_id . ': the price half sent ' . $used . ' requests, ' . $remaining . ' were left.' );
		}

		return $used;
	}

	/**
	 * Writes a line to the error log.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function log( string $line ): void {
		call_user_func( $this->log_error, $line );
	}
}
