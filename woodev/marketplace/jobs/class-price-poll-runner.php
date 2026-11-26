<?php
/**
 * The `poll_task` job for price tasks.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Price\Price_Intent;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Price\Price_Task_Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `woodev_{plugin id}_poll_task( task_id, attempt )` for a price task ({@see Price_Task_Opener}) and asks the pusher what the
 * marketplace decided ({@see Price_Pusher::settle()}). Each decision settles one price under the account claim:
 *
 * - **accepted**: the sent price becomes the marketplace's price;
 * - **a wait**: the price is sent again later, with no attempt counted;
 * - **quarantine** and **refusal**: the price is not sent again until it changes; the product keeps the reason (a quarantine in the
 *   framework's own sentence, a refusal in the marketplace's words).
 *
 * A price that no longer waits (pulled from the marketplace or detached meanwhile) is skipped. Prices still undecided when the
 * task's deadline passes expire: each is sent once more, and a price that expires a second time is not sent again until it changes.
 * A failed read is retried on the {@see Backoff} ladder, never more often than the poll interval; the deadline bounds the task, so
 * there is no attempt limit.
 *
 * The deadline and the interval are the pusher's ({@see Price_Task_Schedule}), one hour and one minute without it.
 *
 * `poll_task( 0 )` is the recovery sweep: an open price task nobody has looked at for {@see self::STALE_AFTER} (or two intervals,
 * whichever is longer) is polled again.
 *
 * @since 2.1.25
 */
final class Price_Poll_Runner {
	public const TASK_DEADLINE = 3600;
	public const POLL_INTERVAL = 60;
	public const STALE_AFTER   = 1800;
	public const SWEEP_LIMIT   = 50;

	private const SWEEP_TASK   = 0;
	private const FAILURE_CODE = 'poll_price_task_failed';

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Task ledger.
	 *
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * Price queue.
	 *
	 * @var Price_Sync_Queue
	 */
	private $queue;

	/**
	 * Pusher.
	 *
	 * @var Price_Pusher
	 */
	private $pusher;

	/**
	 * Schedules the task poll.
	 *
	 * @var Export_Scheduler
	 */
	private $scheduler;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Returns the marketplace's name for the merchant: `function (): string`.
	 *
	 * @var callable
	 */
	private $marketplace_name;

	/**
	 * Error ring.
	 *
	 * @var Api_Error_Ring
	 */
	private $ring;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Clock: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.25
	 *
	 * @param Job_Gate         $gate             Job gate.
	 * @param Task_Ledger      $ledger           Task ledger.
	 * @param Price_Sync_Queue $queue            Price queue.
	 * @param Price_Pusher     $pusher           Pusher.
	 * @param Export_Scheduler $scheduler        Schedules the task poll.
	 * @param Error_Classifier $classifier       Classifies a failed request.
	 * @param callable         $marketplace_name `function (): string`, the marketplace's name for the merchant.
	 * @param Api_Error_Ring   $ring             Error ring.
	 * @param callable         $log_error        `function ( string $line ): void`.
	 * @param callable|null    $clock            `function (): int`; the system clock when null.
	 */
	public function __construct( Job_Gate $gate, Task_Ledger $ledger, Price_Sync_Queue $queue, Price_Pusher $pusher, Export_Scheduler $scheduler, Error_Classifier $classifier, callable $marketplace_name, Api_Error_Ring $ring, callable $log_error, ?callable $clock = null ) {
		$this->gate             = $gate;
		$this->ledger           = $ledger;
		$this->queue            = $queue;
		$this->pusher           = $pusher;
		$this->scheduler        = $scheduler;
		$this->classifier       = $classifier;
		$this->marketplace_name = $marketplace_name;
		$this->ring             = $ring;
		$this->log_error        = $log_error;
		$this->clock            = $clock ?? 'time';
	}

	/**
	 * Returns the seconds between two reads of a pusher's price task.
	 *
	 * @since 2.1.25
	 *
	 * @param Price_Pusher $pusher Pusher.
	 * @return int
	 */
	public static function interval_of( Price_Pusher $pusher ): int {
		return $pusher instanceof Price_Task_Schedule ? $pusher->price_poll_interval() : self::POLL_INTERVAL;
	}

	/**
	 * Returns the seconds a pusher's price result is waited for.
	 *
	 * @since 2.1.25
	 *
	 * @param Price_Pusher $pusher Pusher.
	 * @return int
	 */
	public static function deadline_of( Price_Pusher $pusher ): int {
		return $pusher instanceof Price_Task_Schedule ? $pusher->price_task_deadline() : self::TASK_DEADLINE;
	}

	/**
	 * Hooks the job and its recovery sweep.
	 *
	 * @since 2.1.25
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->poll_hook(), [ $this, 'run' ], 10, 2 );
		add_action( 'action_scheduler_init', [ $this->scheduler, 'ensure_sweep' ] );
	}

	/**
	 * Runs the job. Tasks of another kind share the hook and are left to their own runners.
	 *
	 * @since 2.1.25
	 *
	 * @param mixed $task_id Task id; 0 runs the recovery sweep.
	 * @param mixed $attempt Failed reads in a row.
	 * @return void
	 */
	public function run( $task_id = 0, $attempt = 0 ): void {
		try {
			if ( self::SWEEP_TASK === (int) $task_id ) {
				$this->sweep();
				return;
			}

			$task = $this->ledger->get( (int) $task_id );

			if ( null !== $task && Async_Task::KIND_PRICE === $task->kind() && ! $task->is_final() ) {
				$this->start( $task, (int) $attempt );
			}
		} catch ( \Throwable $e ) {
			$this->report( null, 'poll_task (prices) ' . (int) $task_id . ' failed unexpectedly: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Applies the gate and the claim, then polls under the claim.
	 *
	 * @param Async_Task $task    Task.
	 * @param int        $attempt Failed reads in a row.
	 * @return void
	 */
	private function start( Async_Task $task, int $attempt ): void {
		list( $account, $wait ) = $this->gate->check( $task->account_id() );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->poll_later( $task, $attempt, $wait );
			return;
		}

		$fence = $this->gate->claim( $account->id() );

		if ( null === $fence ) {
			$this->poll_later( $task, $attempt, Job_Gate::BUSY_DELAY );
			return;
		}

		try {
			$this->poll( $account, $task, $attempt, $fence );
		} finally {
			$this->gate->release( $account->id(), $fence );
		}
	}

	/**
	 * Reads the task once and settles what it can.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed reads in a row.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 */
	private function poll( Account $account, Async_Task $task, int $attempt, Claim_Fence $fence ): void {
		try {
			$results = $this->pusher->settle( $account, $task );
		} catch ( \Throwable $thrown ) {
			$this->after_failure( $account, $task, $attempt, $thrown, $fence );
			return;
		}

		$task_id = (int) $task->task_id();

		// The read may have taken long: the claim is renewed before the first write.
		$this->hold( $account->id(), $fence );
		$this->decide( $account, $task_id, $results, $fence );

		if ( [] === $this->open_links( $task_id ) ) {
			$this->finish( $account, $task_id, $fence );
		} elseif ( $this->is_overdue( $task ) ) {
			$this->expire( $account, $task_id, $fence );
		} else {
			$this->ledger->record_poll( $task_id, Async_Task::STATUS_POLLING );
			$this->poll_later( $task, 0, self::interval_of( $this->pusher ) );
		}
	}

	/**
	 * Settles every open item the marketplace has decided on, and skips the ones whose price no longer waits.
	 *
	 * @param Account       $account Account.
	 * @param int           $task_id Task id.
	 * @param Item_Result[] $results What the pusher read.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function decide( Account $account, int $task_id, array $results, Claim_Fence $fence ): void {
		$answers = [];

		foreach ( $results as $result ) {
			$answers[ $result->link_id() ] = $result;
		}

		$waiting = $this->queue->awaiting( $account->id(), $task_id );

		foreach ( $this->open_links( $task_id ) as $link_id ) {
			if ( ! isset( $waiting[ $link_id ] ) ) {
				$this->ledger->settle_item( $task_id, $link_id, Task_Ledger::ITEM_SKIPPED );
			} elseif ( isset( $answers[ $link_id ] ) ) {
				$this->apply( $account, $task_id, $waiting[ $link_id ]['intent'], $answers[ $link_id ], $fence );
			}
		}
	}

	/**
	 * Applies one decision to the price and its ledger item.
	 *
	 * @param Account      $account Account.
	 * @param int          $task_id Task id.
	 * @param Price_Intent $intent  The price that was sent.
	 * @param Item_Result  $answer  The marketplace's decision.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function apply( Account $account, int $task_id, Price_Intent $intent, Item_Result $answer, Claim_Fence $fence ): void {
		$token = $fence->token();

		if ( $answer->is_ok() ) {
			$this->written( $this->queue->accept( $account->id(), $intent, $token ) );
			$this->ledger->settle_item( $task_id, $intent->link_id(), Task_Ledger::ITEM_IMPORTED );
			return;
		}

		$wait = $answer->retry_after_seconds();

		if ( null !== $wait ) {
			$this->written( $this->queue->release( $account->id(), $intent, max( 1, $wait ), $token ) );
			$this->ledger->settle_item( $task_id, $intent->link_id(), Task_Ledger::ITEM_SKIPPED );
			return;
		}

		$quarantined = Price_Refusal::QUARANTINED === $answer->code();
		$code        = $quarantined ? Price_Refusal::QUARANTINED : Price_Refusal::REFUSED;
		$message     = $quarantined ? $this->quarantine_sentence() : $this->refusal_text( $answer );

		$this->written( $this->queue->refuse( $account->id(), $intent, $code, $message, $token ) );
		$this->fail_item( $task_id, $intent->link_id(), $answer->code(), $answer->message() );
	}

	/**
	 * Ends a task whose deadline passed: every price still undecided is sent once more, or, when it already expired before, kept as
	 * refused until it changes.
	 *
	 * @param Account     $account Account.
	 * @param int         $task_id Task id.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function expire( Account $account, int $task_id, Claim_Fence $fence ): void {
		$waiting = $this->queue->awaiting( $account->id(), $task_id );

		foreach ( $this->open_links( $task_id ) as $link_id ) {
			$pair = $waiting[ $link_id ] ?? null;

			if ( null === $pair ) {
				$this->ledger->settle_item( $task_id, $link_id, Task_Ledger::ITEM_SKIPPED );
				continue;
			}

			if ( $pair['expired_before'] ) {
				$message = __( 'Маркетплейс не сообщил, принял ли он новую цену. Чтобы отправить её снова, измените цену.', 'woodev-plugin-framework' );
				$this->written( $this->queue->refuse( $account->id(), $pair['intent'], Price_Refusal::EXPIRED, $message, $fence->token() ) );
			} else {
				$message = __( 'Маркетплейс не сообщил, принял ли он новую цену. Она будет отправлена ещё раз.', 'woodev-plugin-framework' );
				$this->written( $this->queue->expire( $account->id(), $pair['intent'], $message, $fence->token() ) );
			}

			$this->fail_item( $task_id, $link_id, Price_Refusal::EXPIRED, $message );
		}

		$this->hold( $account->id(), $fence );
		$this->ledger->record_poll( $task_id, Async_Task::STATUS_EXPIRED );
	}

	/**
	 * Closes a task whose items are all settled.
	 *
	 * @param Account     $account Account.
	 * @param int         $task_id Task id.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function finish( Account $account, int $task_id, Claim_Fence $fence ): void {
		$status = Async_Task::STATUS_DONE;

		foreach ( $this->ledger->items( $task_id ) as $item ) {
			if ( Task_Ledger::ITEM_FAILED === $item['status'] ) {
				$status = Async_Task::STATUS_DONE_WITH_ERRORS;
				break;
			}
		}

		$this->hold( $account->id(), $fence );
		$this->ledger->record_poll( $task_id, $status );
	}

	/**
	 * Handles a failed read: an authorisation refusal pauses the account; past the deadline the task expires; otherwise the read is
	 * tried again later.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed reads in a row before this one.
	 * @param \Throwable  $thrown  What the pusher threw.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function after_failure( Account $account, Async_Task $task, int $attempt, \Throwable $thrown, Claim_Fence $fence ): void {
		$task_id = (int) $task->task_id();
		$error   = $this->classifier->classify( $thrown );

		if ( null === $error ) {
			$this->report( $account->id(), 'poll_task (prices) ' . $task_id . ' failed unexpectedly: ' . get_class( $thrown ) . ': ' . $thrown->getMessage() );
		} else {
			$this->log( 'poll_task (prices) ' . $task_id . ': ' . $error->describe() );
		}

		if ( null !== $error && $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
		}

		if ( $this->is_overdue( $task ) ) {
			$this->hold( $account->id(), $fence );
			$this->expire( $account, $task_id, $fence );
			return;
		}

		$this->ledger->record_poll( $task_id, Async_Task::STATUS_POLLING );

		if ( null !== $error && $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->poll_later( $task, $attempt, Account_Pause::SECONDS );
		} elseif ( null !== $error && $error->is( Remote_Error::THROTTLE ) ) {
			$this->poll_later( $task, $attempt, max( 1, (int) ( $error->retry_after() ?? self::interval_of( $this->pusher ) ) ) );
		} else {
			$this->poll_later( $task, $attempt + 1, max( self::interval_of( $this->pusher ), Backoff::seconds_after( $attempt + 1 ) ) );
		}
	}

	/**
	 * The recovery sweep: polls the open price tasks nobody has looked at lately (a refused or lost schedule).
	 *
	 * @return void
	 */
	private function sweep(): void {
		$stale = (int) call_user_func( $this->clock ) - max( self::STALE_AFTER, 2 * self::interval_of( $this->pusher ) );
		$tasks = $this->ledger->untouched_since( Async_Task::KIND_PRICE, [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], $stale, 0, self::SWEEP_LIMIT );

		foreach ( $tasks as $task ) {
			$this->ledger->mark_polled( (int) $task->task_id() );
			$this->poll_later( $task, 0, 0 );
		}
	}

	/**
	 * Lists the links of a task whose item is still pending.
	 *
	 * @param int $task_id Task id.
	 * @return int[]
	 */
	private function open_links( int $task_id ): array {
		$open = [];

		foreach ( $this->ledger->items( $task_id ) as $item ) {
			if ( Task_Ledger::ITEM_PENDING === $item['status'] ) {
				$open[] = $item['link_id'];
			}
		}

		return $open;
	}

	/**
	 * Marks an item failed with the reason.
	 *
	 * @param int    $task_id Task id.
	 * @param int    $link_id Link id.
	 * @param string $code    Error code.
	 * @param string $message Reason.
	 * @return void
	 */
	private function fail_item( int $task_id, int $link_id, string $code, string $message ): void {
		$this->ledger->settle_item(
			$task_id,
			$link_id,
			Task_Ledger::ITEM_FAILED,
			[
				[
					'code'    => $code,
					'message' => $message,
				],
			]
		);
	}

	/**
	 * Returns the sentence of a quarantined price.
	 *
	 * @return string
	 */
	private function quarantine_sentence(): string {
		/* translators: %s: marketplace name. */
		return sprintf( __( '%s держит новую цену в карантине: она намного ниже текущей', 'woodev-plugin-framework' ), (string) call_user_func( $this->marketplace_name ) );
	}

	/**
	 * Returns the marketplace's words on a refused price.
	 *
	 * @param Item_Result $answer Refusal.
	 * @return string
	 */
	private function refusal_text( Item_Result $answer ): string {
		return '' !== $answer->message() ? $answer->message() : __( 'Маркетплейс отклонил новую цену.', 'woodev-plugin-framework' );
	}

	/**
	 * Stops the run when a claim-fenced write was refused.
	 *
	 * @param bool $written What the write answered.
	 * @return void
	 * @throws Claim_Lost When the write changed nothing.
	 */
	private function written( bool $written ): void {
		if ( ! $written ) {
			throw new Claim_Lost( 'The account claim was taken over during the price poll.' );
		}
	}

	/**
	 * Proves, right before a task is closed, that the run still holds the account claim, and renews it.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function hold( int $account_id, Claim_Fence $fence ): void {
		if ( ! $this->gate->still_holds( $account_id, $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over during the price poll.' );
		}
	}

	/**
	 * Tells whether a task's deadline has passed.
	 *
	 * @param Async_Task $task Task.
	 * @return bool
	 */
	private function is_overdue( Async_Task $task ): bool {
		$submitted = $task->submitted_at();

		return null !== $submitted && (int) call_user_func( $this->clock ) - $submitted->getTimestamp() > self::deadline_of( $this->pusher );
	}

	/**
	 * Schedules the next poll; a refusal by Action Scheduler is recorded, and the sweep picks the task up again.
	 *
	 * @param Async_Task $task    Task.
	 * @param int        $attempt Failed reads in a row.
	 * @param int        $delay   Seconds to wait.
	 * @return void
	 */
	private function poll_later( Async_Task $task, int $attempt, int $delay ): void {
		try {
			$this->scheduler->poll_later( (int) $task->task_id(), $attempt, $delay );
		} catch ( \Throwable $e ) {
			$this->report( $task->account_id(), 'poll_task (prices) ' . $task->task_id() . ' could not be rescheduled; the sweep picks it up: ' . $e->getMessage() );
		}
	}

	/**
	 * Writes a failure to the error ring and the error log.
	 *
	 * @param int|null $account_id Account id, null when unknown.
	 * @param string   $message    Failure text.
	 * @return void
	 */
	private function report( ?int $account_id, string $message ): void {
		try {
			$this->ring->record( $account_id, '', 0, self::FAILURE_CODE, $message, '', $this->scheduler->poll_hook() );
		} catch ( \Throwable $e ) {
			unset( $e ); // The log line below still carries the failure.
		}

		$this->log( $message );
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
