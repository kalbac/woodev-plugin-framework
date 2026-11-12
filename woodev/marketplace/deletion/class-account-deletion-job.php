<?php
/**
 * The `delete_account` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a disabled account with everything that belongs to it.
 *
 * - **Claimed.** The run takes the account row's claim through the {@see Job_Gate}, the one every sender and tool takes, so it
 *   waits for a run that is going and nothing else writes the account's tables while it works. It renews the claim after each
 *   batch; a run that lost it stops without deleting anything more.
 * - **In steps, in order.** Each step is `function ( int $account_id, int $limit ): int` and deletes «what is left of this
 *   account» in batches until it removes nothing. Nothing is remembered between runs, so an interrupted deletion resumes by
 *   running the steps again: the finished ones find nothing to do.
 * - **The account row last.** It goes only when every step is empty, fenced by the claim and by the switch being off, so no
 *   table refers to an account that no longer exists, and an interruption never leaves a row without its data's owner.
 * - **Bounded.** A run stops after {@see self::RUN_SECONDS} and queues itself again, so a shop with tens of thousands of links
 *   is deleted in many short Action Scheduler runs.
 *
 * The job does not use {@see Job_Gate::check()}: that check ends the run silently for a disabled account, which is the very
 * account this job works on.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Job {
	public const BATCH       = 500;
	public const RUN_SECONDS = 20;
	public const MAX_RETRIES = 3;

	private const RETRY_DELAY = 60;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Job gate, for the claim.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Progress the screen reads.
	 *
	 * @var Account_Deletion_Progress
	 */
	private $progress;

	/**
	 * Scheduler.
	 *
	 * @var Account_Deletion_Scheduler
	 */
	private $scheduler;

	/**
	 * Steps by name, in the order they run: `function ( int $account_id, int $limit ): int` (rows deleted; 0 when nothing is left).
	 *
	 * @var array<string,callable>
	 */
	private $steps;

	/**
	 * What the adapter does at the marketplace, or null when it does nothing.
	 *
	 * @var Account_Data_Cleaner|null
	 */
	private $cleaner;

	/**
	 * Tells whether the marketplace keeps a notification address for the account: `function ( Account $account ): bool`.
	 *
	 * @var callable
	 */
	private $has_address;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Unix time: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the job.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Account_Store         $accounts    Accounts.
	 * @param Job_Gate                   $gate        Job gate.
	 * @param Account_Deletion_Progress  $progress    Progress.
	 * @param Account_Deletion_Scheduler $scheduler   Scheduler.
	 * @param array<string,callable>     $steps       The steps, in order.
	 * @param Account_Data_Cleaner|null  $cleaner     The adapter's cleanup, when it has one.
	 * @param callable                   $has_address `function ( Account $account ): bool`, whether a notification address is registered for the account.
	 * @param callable                   $log_error   `function ( string $line ): void`.
	 * @param callable|null              $clock       `function (): int`; the system clock when null.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Job_Gate $gate, Account_Deletion_Progress $progress, Account_Deletion_Scheduler $scheduler, array $steps, ?Account_Data_Cleaner $cleaner, callable $has_address, callable $log_error, ?callable $clock = null ) {
		$this->accounts    = $accounts;
		$this->gate        = $gate;
		$this->progress    = $progress;
		$this->scheduler   = $scheduler;
		$this->steps       = $steps;
		$this->cleaner     = $cleaner;
		$this->has_address = $has_address;
		$this->log_error   = $log_error;
		$this->clock       = $clock ?? 'time';
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.5
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'run' ], 10, 2 );
	}

	/**
	 * Runs `delete_account`.
	 *
	 * @since 2.1.5
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $attempt    Failed runs so far.
	 * @return void
	 */
	public function run( $account_id = 0, $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;
		$account    = $this->accounts->get( $account_id );

		if ( null === $account ) {
			return;
		}

		if ( $account->is_enabled() ) {
			$this->progress->fail( $account_id, __( 'Аккаунт снова включён, поэтому удаление остановлено. Выключите аккаунт и повторите удаление.', 'woodev-plugin-framework' ) );
			return;
		}

		$fence = $this->gate->claim( $account_id );

		if ( null === $fence ) {
			$this->scheduler->later( $account_id, $attempt, Job_Gate::BUSY_DELAY );
			return;
		}

		try {
			$this->progress->begin( $account_id );

			if ( ! $this->erase( $account, $fence ) ) {
				$this->scheduler->later( $account_id, $attempt, 0 );
			}
		} catch ( Claim_Lost $e ) {
			$this->scheduler->later( $account_id, $attempt, Job_Gate::BUSY_DELAY );
		} catch ( \Throwable $e ) {
			$this->after_failure( $account_id, $attempt, $e );
		} finally {
			$this->gate->release( $account_id, $fence );
		}
	}

	/**
	 * Runs the steps from the first, then removes the account row.
	 *
	 * @param Account     $account Account.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return bool True when the account is gone or the deletion stopped for good; false when time ran out and the job must run again.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function erase( Account $account, Claim_Fence $fence ): bool {
		$account_id = $account->id();
		$deadline   = (int) call_user_func( $this->clock ) + self::RUN_SECONDS;

		foreach ( $this->steps_for( $account ) as $name => $step ) {
			do {
				$removed = (int) call_user_func( $step, $account_id, self::BATCH );
				$this->progress->record( $account_id, $name, $removed );
				$this->hold( $account_id, $fence );

				if ( $removed > 0 && (int) call_user_func( $this->clock ) >= $deadline ) {
					return false;
				}
			} while ( $removed > 0 );
		}

		$this->remove_row( $account_id, $fence, $this->unsubscribe( $account ) );

		return true;
	}

	/**
	 * The framework's steps, then the adapter's.
	 *
	 * @param Account $account Account.
	 * @return array<string,callable>
	 */
	private function steps_for( Account $account ): array {
		$steps = $this->steps;

		if ( null !== $this->cleaner ) {
			$cleaner         = $this->cleaner;
			$steps['plugin'] = static function ( int $account_id, int $limit ) use ( $cleaner, $account ): int {
				return $cleaner->erase_account_data( $account, $limit );
			};
		}

		return $steps;
	}

	/**
	 * Removes the notification address at the marketplace when the adapter can, and returns what the merchant must do by hand.
	 *
	 * @param Account $account Account.
	 * @return string[] Sentences for the merchant.
	 */
	private function unsubscribe( Account $account ): array {
		if ( ! call_user_func( $this->has_address, $account ) ) {
			return [];
		}

		if ( $this->cleaner_removed_address( $account ) ) {
			return [];
		}

		return [ __( 'Адрес уведомлений остался зарегистрированным в личном кабинете маркетплейса. Если он больше не нужен, уберите его там.', 'woodev-plugin-framework' ) ];
	}

	/**
	 * Asks the adapter to remove the address; any failure counts as «not removed».
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	private function cleaner_removed_address( Account $account ): bool {
		if ( null === $this->cleaner ) {
			return false;
		}

		try {
			return true === $this->cleaner->unsubscribe_notifications( $account );
		} catch ( \Throwable $e ) {
			// The message can echo a request (a key, an address): the log gets the class only.
			call_user_func( $this->log_error, 'Account deletion: the notification address was not removed (' . get_class( $e ) . ').' );

			return false;
		}
	}

	/**
	 * Deletes the account row, the last step, and ends the progress.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @param string[]    $notes      Sentences for the merchant.
	 * @return void
	 * @throws Claim_Lost When the row is still there and the claim is not the run's.
	 */
	private function remove_row( int $account_id, Claim_Fence $fence, array $notes ): void {
		if ( $this->accounts->delete( $account_id, $fence->token() ) ) {
			$this->progress->finish( $account_id, $notes );
			return;
		}

		$left = $this->accounts->get( $account_id );

		if ( null === $left ) {
			$this->progress->finish( $account_id, $notes );
		} elseif ( $left->is_enabled() ) {
			$this->progress->fail( $account_id, __( 'Аккаунт снова включён, поэтому он не удалён. Данные аккаунта уже удалены частично: выключите аккаунт и повторите удаление.', 'woodev-plugin-framework' ) );
		} else {
			throw new Claim_Lost( 'The account claim was taken over before the row was deleted.' );
		}
	}

	/**
	 * Extends the claim; stops the run when it was lost.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return void
	 * @throws Claim_Lost When another run holds the account now.
	 */
	private function hold( int $account_id, Claim_Fence $fence ): void {
		if ( ! $this->gate->still_holds( $account_id, $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over during the deletion.' );
		}
	}

	/**
	 * Retries a run that threw, a few times, then tells the merchant.
	 *
	 * @param int        $account_id Account id.
	 * @param int        $attempt    Failed runs so far.
	 * @param \Throwable $error      What was thrown.
	 * @return void
	 */
	private function after_failure( int $account_id, int $attempt, \Throwable $error ): void {
		call_user_func( $this->log_error, sprintf( 'Account deletion failed: %s at %s:%d', get_class( $error ), basename( $error->getFile() ), $error->getLine() ) );

		if ( $attempt + 1 < self::MAX_RETRIES ) {
			$this->scheduler->later( $account_id, $attempt + 1, self::RETRY_DELAY * ( $attempt + 1 ) );
			return;
		}

		$this->progress->fail( $account_id, __( 'Не удалось удалить все данные аккаунта. Повторите удаление позже; если ошибка повторится, включите логирование и напишите в поддержку.', 'woodev-plugin-framework' ) );
	}
}
