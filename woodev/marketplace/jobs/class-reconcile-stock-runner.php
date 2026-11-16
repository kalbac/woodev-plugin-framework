<?php
/**
 * The `reconcile_stock` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Sync\Readback_Progress;
use Woodev\Framework\Marketplace\Sync\Stock_Mode;
use Woodev\Framework\Marketplace\Sync\Stock_Readback;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_reconcile_stock( account_id, cursor_link_id, attempt )`: the daily stock reconcile of an account that syncs
 * stock. It reads the marketplace's stock of the account's linked cards a page at a time ({@see Stock_Readback}):
 *
 * - **«Marketplace is master»**: the shop's stock takes what the marketplace holds. The readback queues nothing for the
 *   marketplace, so the shop never sends the number it just received back.
 * - **«Site is master»**: only `remote_present` / `remote_reserved` of the account's rows are refreshed; the shop and the
 *   marketplace are left as they are.
 *
 * - **Schedule.** One recurring run a day per enabled account that syncs stock ({@see Reconcile_Stock_Scheduler}), kept in step
 *   with the accounts by {@see self::ensure_schedules()} at most once an hour.
 * - **Run.** {@see Account_Job} applies the gate (account enabled, licence, pause) and the account claim and classifies a failed
 *   read; the work stops silently when the account does not sync stock any more.
 * - **Pages.** 200 links by link id after the cursor. A run has {@see self::BUDGET_REQUESTS} requests (HTTP requests when the
 *   pusher implements {@see \Woodev\Framework\Marketplace\Stock\Reports_Read_Requests}) and {@see self::BUDGET_SECONDS} seconds
 *   ({@see Account_Run}); when they are spent the run queues itself and stops. The cursor moves only past links that were read:
 *   {@see Readback_Progress} names the warehouse groups the budget did not reach. An account gets at most
 *   {@see Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY} continuations a day, which caps the day's traffic.
 *
 * @since 2.1.8
 */
final class Reconcile_Stock_Runner {
	public const BUDGET_REQUESTS = 20;
	public const BUDGET_SECONDS  = 20.0;
	public const PAGE_SIZE       = 200;
	public const CONTINUE_DELAY  = 30;

	private const FAILURE_CODE = 'reconcile_stock_failed';
	private const CHECK_SUFFIX = '_check';

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

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
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The comparison and the shop write of one page.
	 *
	 * @var Stock_Readback
	 */
	private $readback;

	/**
	 * Scheduler.
	 *
	 * @var Reconcile_Stock_Scheduler
	 */
	private $scheduler;

	/**
	 * The gate → claim → work → classify skeleton.
	 *
	 * @var Account_Job
	 */
	private $job;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Clock of the run's deadline: `function (): float`, or null for `microtime( true )`.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.8
	 *
	 * @param Job_Gate                  $gate           Job gate.
	 * @param Wpdb_Account_Store        $accounts       Accounts.
	 * @param string                    $marketplace_id The adapter's id.
	 * @param Wpdb_Link_Store           $links          Links.
	 * @param Stock_Readback            $readback       The comparison and the shop write.
	 * @param Reconcile_Stock_Scheduler $scheduler      Scheduler.
	 * @param Error_Classifier          $classifier     The adapter's error classifier.
	 * @param Api_Error_Ring            $ring           Error ring.
	 * @param callable                  $log_error      `function ( string $line ): void`.
	 * @param callable|null             $clock          `function (): float` for the run's deadline; `microtime( true )` when null.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Account_Store $accounts, string $marketplace_id, Wpdb_Link_Store $links, Stock_Readback $readback, Reconcile_Stock_Scheduler $scheduler, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, ?callable $clock = null ) {
		$this->gate           = $gate;
		$this->accounts       = $accounts;
		$this->marketplace_id = $marketplace_id;
		$this->links          = $links;
		$this->readback       = $readback;
		$this->scheduler      = $scheduler;
		$this->log_error      = $log_error;
		$this->clock          = $clock;
		$this->job            = new Account_Job( $gate, $classifier, $ring, $log_error, '', self::FAILURE_CODE );
	}

	/**
	 * Hooks the job and its schedule check.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'run' ], 10, 3 );
		add_action( 'action_scheduler_init', [ $this, 'ensure_schedules' ] );
	}

	/**
	 * Makes sure every enabled account that syncs stock has its daily run and no other account has one; checked at
	 * most once an hour.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 */
	public function ensure_schedules(): void {
		$transient = $this->scheduler->hook() . self::CHECK_SUFFIX;

		if ( false !== get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, '1', HOUR_IN_SECONDS );

		try {
			$this->scheduler->sync( $this->stock_sync_account_ids() );
		} catch ( \Throwable $e ) {
			$this->log( 'reconcile_stock could not be scheduled: ' . $e->getMessage() );
		}
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.8
	 *
	 * @param mixed $account_id     Account id.
	 * @param mixed $cursor_link_id Last link id the earlier runs of this pass covered, 0 for a new pass.
	 * @param mixed $attempt        Failed attempts so far.
	 * @return void
	 */
	public function run( $account_id = 0, $cursor_link_id = 0, $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$cursor     = max( 0, (int) $cursor_link_id );

		$this->job->run(
			$account_id,
			$this->scheduler->hook(),
			function ( int $delay, int $failures ) use ( $account_id, $cursor ): bool {
				return $this->scheduler->continue_later( $account_id, $cursor, $failures, $delay );
			},
			static function (): void {
				// Nothing to tell the merchant: the next daily run starts again, and a failure is already in the ring and the log.
			},
			(int) $attempt,
			function ( Account $account, Claim_Fence $fence ) use ( $cursor ): void {
				$this->reconcile( $account, $fence, $cursor );
			}
		);
	}

	/**
	 * Reconciles page after page until a page is short or the run's budget is spent.
	 *
	 * The cursor moves only past links that were really read: a page whose last warehouse group the budget did not reach is
	 * resumed from its first unread link, and a full page that used the last request of the budget is not read again.
	 *
	 * @param Account     $account Account.
	 * @param Claim_Fence $fence   The run's claim.
	 * @param int         $cursor  Last link id already covered.
	 * @return void
	 * @throws \RuntimeException When the continuation could not be queued.
	 * @throws \Throwable        What the marketplace read threw; {@see Account_Job} classifies it.
	 */
	private function reconcile( Account $account, Claim_Fence $fence, int $cursor ): void {
		if ( Stock_Mode::is_off( $account ) ) {
			return; // The mode changed after the run was queued: stock is not synced for this account any more.
		}

		// «Marketplace is master» writes the shop; in «site is master» the daily read only keeps `remote_present` fresh.
		$refresh_only = ! Stock_Mode::is_marketplace( $account );
		$run          = $this->account_run( $account, $fence );

		do {
			$page = $this->links->page( $account->id(), $cursor, self::PAGE_SIZE );

			if ( [] === $page ) {
				return;
			}

			$progress = $this->readback->reconcile_page( $run, $page, $refresh_only );

			if ( ! $progress->is_complete() ) {
				$this->continue_from( $account->id(), (int) $progress->resume_cursor() );

				return;
			}

			$cursor = (int) end( $page )->link_id();

			if ( count( $page ) < self::PAGE_SIZE ) {
				return;
			}

			if ( ! $run->can_request() ) {
				$this->continue_from( $account->id(), $cursor );

				return;
			}
		} while ( true );
	}

	/**
	 * Starts the run's budget over the claim.
	 *
	 * @param Account     $account Account.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return Account_Run
	 */
	private function account_run( Account $account, Claim_Fence $fence ): Account_Run {
		$account_id = $account->id();

		return new Account_Run(
			$account,
			$fence,
			function () use ( $account_id, $fence ): bool {
				return $this->gate->still_holds( $account_id, $fence );
			},
			self::BUDGET_REQUESTS,
			self::BUDGET_SECONDS,
			$this->clock
		);
	}

	/**
	 * Queues the rest of the pass.
	 *
	 * @param int $account_id Account id.
	 * @param int $cursor     Last link id already covered.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refused the action, or the account's continuations of the day are spent.
	 */
	private function continue_from( int $account_id, int $cursor ): void {
		if ( ! $this->scheduler->continue_later( $account_id, $cursor, 0, self::CONTINUE_DELAY ) ) {
			throw new \RuntimeException( 'The stock reconcile could not be continued after link ' . (int) $cursor . ' (Action Scheduler refused it, or ' . Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY . ' continuations were already used today); the next daily run starts over.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Lists the enabled accounts that sync stock (any stock mode but «off»).
	 *
	 * @return int[]
	 */
	private function stock_sync_account_ids(): array {
		$ids = [];

		foreach ( $this->accounts->all( $this->marketplace_id, true ) as $account ) {
			if ( ! Stock_Mode::is_off( $account ) ) {
				$ids[] = $account->id();
			}
		}

		return $ids;
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
