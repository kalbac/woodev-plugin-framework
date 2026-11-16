<?php
/**
 * The lifecycle of a bulk run: start, tick, stop, recovery.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * An action applied to every product under a filter runs here, in the background, in parts (#1795). The runner owns the exclusion and
 * the bookkeeping; the {@see Bulk_Run_Worker} owns the work.
 *
 * **Who may work: one MySQL named lock per account** ({@see Bulk_Run_Lock}). Every tick and the stop take it, without waiting, before
 * they read the row, and release it after their last write. A second tick exits before it reads the row; a tick that died frees the
 * lock with its connection. The lock alone is not a fence — `wpdb` reconnects on error 2006 and carries on on a connection that holds
 * nothing — so a tick also:
 *
 * 1. writes a **tick token** right after the lock is granted, and fences every save and the terminal write with it;
 * 2. asks {@see Bulk_Run_Locking::is_held()} before every product write (the worker calls the `$owns` it is given);
 *
 * and stops at once, without a product write, a save or a next tick, when either says it lost the run.
 *
 * **A tick** (`bulk_run( account_id, run_id )`): lock; read the run; token; the account's gates; the user; then parts for as long as the next one
 * fits the {@see self::BUDGET_SECONDS} budget (the longest part so far decides) — after each, merge the counters and the report, save (fenced), re-read the cancel flag;
 * then queue the next tick with no delay and release. A part that reads nothing finishes the run.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Runner {
	/**
	 * Seconds of work one tick may spend; the import job's budget.
	 */
	public const BUDGET_SECONDS = Import_Jobs::BUDGET_SECONDS;

	/**
	 * The answers of {@see self::stop()}.
	 */
	public const STOP_CANCELLED = 'cancelled';
	public const STOP_REQUESTED = 'requested';
	public const STOP_GONE      = 'gone';

	/**
	 * @var Bulk_Run_Store
	 */
	private $store;

	/**
	 * @var Bulk_Run_Locking
	 */
	private $lock;

	/**
	 * @var Bulk_Run_Scheduler
	 */
	private $scheduler;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var string
	 */
	private $adapter_id;

	/**
	 * @var Bulk_Run_Worker
	 */
	private $worker;

	/**
	 * Returns the time in seconds as a float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Returns a fresh random token: `function (): string`, 32 hex characters.
	 *
	 * @var callable
	 */
	private $token;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.8
	 *
	 * @param Bulk_Run_Store     $store      Runs.
	 * @param Bulk_Run_Locking   $lock       The named lock.
	 * @param Bulk_Run_Scheduler $scheduler  Ticks.
	 * @param Wpdb_Account_Store $accounts   Accounts.
	 * @param string             $adapter_id Adapter of the plugin: a run of an account of another adapter ends.
	 * @param Bulk_Run_Worker    $worker     The work of a part.
	 * @param callable|null      $log        `function ( string $line ): void`; logs nothing when null.
	 * @param callable|null      $clock      `function (): float`; `microtime( true )` when null.
	 * @param callable|null      $token      `function (): string`; random bytes when null.
	 */
	public function __construct( Bulk_Run_Store $store, Bulk_Run_Locking $lock, Bulk_Run_Scheduler $scheduler, Wpdb_Account_Store $accounts, string $adapter_id, Bulk_Run_Worker $worker, ?callable $log = null, ?callable $clock = null, ?callable $token = null ) {
		$this->store      = $store;
		$this->lock       = $lock;
		$this->scheduler  = $scheduler;
		$this->accounts   = $accounts;
		$this->adapter_id = $adapter_id;
		$this->worker     = $worker;
		$this->log        = $log ?? static function ( string $line ): void {
		};
		$this->clock      = $clock ?? static function (): float {
			return microtime( true );
		};
		$this->token      = $token ?? static function (): string {
			return bin2hex( random_bytes( 16 ) );
		};
	}

	/**
	 * Hooks the tick.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'tick' ], 10, 2 );
		add_action( 'action_scheduler_init', [ $this->scheduler, 'ensure_sweep' ] );
	}

	/**
	 * Starts a run and queues its first tick.
	 *
	 * @since 2.1.8
	 *
	 * @param int                 $account_id     Account.
	 * @param string              $action         Action id.
	 * @param array<string,mixed> $payload        The action's validated fields.
	 * @param array<string,mixed> $query          The validated query: `search` and `filters`.
	 * @param int                 $user_id        Who starts it.
	 * @param int                 $products_total The apply-time count.
	 * @return string|null The run's id; null when a run of this account is still going.
	 * @throws \RuntimeException When Action Scheduler refuses the first tick (the run is ended, so it does not block the next start).
	 */
	public function start( int $account_id, string $action, array $payload, array $query, int $user_id, int $products_total ): ?string {
		$run_id = call_user_func( $this->token );

		if ( ! $this->store->start( $account_id, $run_id, $action, $payload, $query, $user_id, $products_total ) ) {
			return null;
		}

		if ( ! $this->scheduler->enqueue( $account_id, $run_id ) ) {
			$this->end_unqueued( $account_id, $run_id );

			throw new \RuntimeException( 'Action Scheduler did not queue the bulk run.' );
		}

		return $run_id;
	}

	/**
	 * Stops a run: «Остановить» carries the run id the line showed.
	 *
	 * With the lock free the run ends `cancelled` here; with a tick working it gets the cancel flag and finishes after its part. A run
	 * id that is not the current running run changes nothing — the merchant's second tab must not stop a newer run.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     The run the merchant saw.
	 * @return string One of the `STOP_*` constants.
	 */
	public function stop( int $account_id, string $run_id ): string {
		if ( $this->lock->acquire( $account_id ) ) {
			try {
				return $this->store->end_under_lock( $account_id, $run_id, Bulk_Run::CANCELLED ) ? self::STOP_CANCELLED : self::STOP_GONE;
			} finally {
				$this->lock->release( $account_id );
			}
		}

		return $this->store->request_cancel( $account_id, $run_id ) ? self::STOP_REQUESTED : self::STOP_GONE;
	}

	/**
	 * Queues a tick for a run that is running with none waiting: the next tick was lost between a save and its enqueue. Asked by the
	 * account line's activity read and by the sweep.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function recover( int $account_id ): void {
		$run = $this->store->find( $account_id );

		if ( null === $run || ! $run->is_running() || $this->scheduler->has_pending( $account_id, $run->run_id() ) ) {
			return;
		}

		// A tick that holds the lock is working: it queues the next one itself.
		if ( ! $this->lock->acquire( $account_id ) ) {
			return;
		}

		$this->lock->release( $account_id );
		$this->scheduler->enqueue( $account_id, $run->run_id() );
	}

	/**
	 * One tick of Action Scheduler. Account 0 is the recovery sweep.
	 *
	 * @since 2.1.8
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $run_id     Run id.
	 * @return void
	 */
	public function tick( $account_id, $run_id = '' ): void {
		$account_id = (int) $account_id;

		if ( 0 === $account_id ) {
			foreach ( $this->store->running_accounts() as $running ) {
				$this->recover( $running );
			}

			return;
		}

		if ( ! $this->lock->acquire( $account_id ) ) {
			return;
		}

		try {
			$this->work( $account_id, (string) $run_id );
		} catch ( Bulk_Run_Lost $e ) {
			return; // The connection that held the lock is gone: nothing more is written.
		} finally {
			$this->lock->release( $account_id );
		}
	}

	/**
	 * Everything a tick does while it holds the lock.
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @return void
	 * @throws Bulk_Run_Lost When the tick finds it lost the run.
	 */
	private function work( int $account_id, string $run_id ): void {
		$run = $this->store->find( $account_id );

		if ( null === $run || $run->run_id() !== $run_id || ! $run->is_running() ) {
			return;
		}

		if ( $run->cancel_requested() ) {
			$this->store->end_under_lock( $account_id, $run_id, Bulk_Run::CANCELLED );

			return;
		}

		$tick = (string) call_user_func( $this->token );

		if ( ! $this->store->take_tick( $account_id, $run_id, $tick ) ) {
			return;
		}

		$progress = [
			'cursor' => $run->cursor(),
			'counts' => $run->counts(),
			'report' => $run->report(),
		];
		$account  = $this->accounts->get( $account_id );
		$refusal  = $this->account_refusal( $account ) ?? $this->user_refusal( $account, $run );

		if ( null !== $refusal ) {
			$this->end( $run, $tick, Bulk_Run::STOPPED, $progress, $refusal );

			return;
		}

		try {
			$this->parts( $account, $run, $tick, $progress );
		} catch ( Bulk_Run_Lost $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			call_user_func( $this->log, 'Bulk run ' . $run_id . ' stopped by an error: ' . $e->getMessage() );
			$this->end( $run, $tick, Bulk_Run::STOPPED, $progress, __( 'Обработка остановлена из-за внутренней ошибки. Подробности — в журнале плагина.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * Works parts until the next one would not fit in the budget, the run is over, or it is lost.
	 *
	 * A part starts only when the time used plus the longest part of this tick still fits {@see self::BUDGET_SECONDS}; the first part
	 * always runs. This is a prediction from the longest part seen so far, not a hard bound: a later part slower than every
	 * earlier one can still end the tick past the budget (#1795).
	 *
	 * @param Account             $account  Account.
	 * @param Bulk_Run            $run      Run as the tick read it.
	 * @param string              $tick     The tick's token.
	 * @param array<string,mixed> $progress `cursor`, `counts`, `report`; updated as parts go, by reference: an error in a later part ends
	 *                                      the run with what the earlier parts saved, not with what the tick started from.
	 * @return void
	 * @throws Bulk_Run_Lost When the ownership check fails.
	 */
	private function parts( Account $account, Bulk_Run $run, string $tick, array &$progress ): void {
		$account_id = $run->account_id();
		$run_id     = $run->run_id();
		$began      = (float) call_user_func( $this->clock );
		$last       = $began;
		$longest    = 0.0;
		$owns       = function () use ( $account_id ): bool {
			return $this->lock->is_held( $account_id );
		};

		do {
			if ( ! $owns() ) {
				return;
			}

			$part = $this->worker->part( $account, $run, $progress['cursor'], $owns );

			if ( $part['busy'] > 0 ) {
				$this->scheduler->enqueue( $account_id, $run_id, $part['busy'] );

				return;
			}

			$progress = $this->merged( $progress, $part );

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			if ( $part['finished'] ) {
				$this->end( $run, $tick, Bulk_Run::DONE, $progress );

				return;
			}

			if ( ! $this->store->save( $account_id, $run_id, $tick, $progress['cursor'], $progress['counts'], $progress['report'] ) ) {
				return; // Another tick owns the run: no product write, no next tick.
			}

			if ( $this->store->is_cancel_requested( $account_id, $run_id ) ) {
				$this->end( $run, $tick, Bulk_Run::CANCELLED, $progress );

				return;
			}

			// The budget is checked before a part starts, not after it ended: a part begins only when the longest one of this tick
			// still fits. The first part always runs, so every tick moves the run forward.
			$now     = (float) call_user_func( $this->clock );
			$longest = max( $longest, $now - $last );
			$last    = $now;
		} while ( $now - $began + $longest <= self::BUDGET_SECONDS );

		$this->scheduler->enqueue( $account_id, $run_id );
	}

	/**
	 * Adds a part to the progress.
	 *
	 * @param array<string,mixed> $progress `cursor`, `counts`, `report`.
	 * @param array<string,mixed> $part     The worker's answer.
	 * @return array<string,mixed>
	 */
	private function merged( array $progress, array $part ): array {
		foreach ( (array) $part['counts'] as $key => $added ) {
			$progress['counts'][ $key ] = (int) ( $progress['counts'][ $key ] ?? 0 ) + (int) $added;
		}

		$progress['cursor'] = $part['cursor'];
		$progress['report'] = Bulk_Run_Report::merge( $progress['report'], $part['groups'] );

		return $progress;
	}

	/**
	 * The terminal write of a tick. A `stopped` run keeps its sentence in the cursor, where the account line reads it.
	 *
	 * @param Bulk_Run            $run      Run.
	 * @param string              $tick     The tick's token.
	 * @param string              $state    Terminal state.
	 * @param array<string,mixed> $progress `cursor`, `counts`, `report`.
	 * @param string              $sentence Why a `stopped` run stopped.
	 * @return void
	 */
	private function end( Bulk_Run $run, string $tick, string $state, array $progress, string $sentence = '' ): void {
		if ( '' !== $sentence ) {
			$progress['cursor']['stopped'] = $sentence;
		}

		$this->store->finish( $run->account_id(), $run->run_id(), $tick, $state, $progress['cursor'], $progress['counts'], $progress['report'] );
	}

	/**
	 * A run whose first tick could not be queued is ended at once, so the merchant can start again.
	 *
	 * @param int    $account_id Account.
	 * @param string $run_id     Run.
	 * @return void
	 */
	private function end_unqueued( int $account_id, string $run_id ): void {
		if ( $this->lock->acquire( $account_id ) ) {
			$this->store->end_under_lock( $account_id, $run_id, Bulk_Run::STOPPED );
			$this->lock->release( $account_id );
		}
	}

	/**
	 * @param Account|null $account Account.
	 * @return string|null
	 */
	private function account_refusal( ?Account $account ): ?string {
		if ( null === $account || ! $account->is_enabled() || $account->adapter_id() !== $this->adapter_id ) {
			return __( 'Аккаунт выключен или удалён.', 'woodev-plugin-framework' );
		}

		return null;
	}

	/**
	 * Sets the user who started the run, then asks the worker whether the run may go on.
	 *
	 * @param Account|null $account Account.
	 * @param Bulk_Run     $run     Run.
	 * @return string|null
	 */
	private function user_refusal( ?Account $account, Bulk_Run $run ): ?string {
		wp_set_current_user( $run->user_id() );

		return null === $account ? null : $this->worker->refusal( $account, $run );
	}
}
