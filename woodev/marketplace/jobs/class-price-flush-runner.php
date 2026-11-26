<?php
/**
 * The price half of the account's flush.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Price\Flush_Result;
use Woodev\Framework\Marketplace\Price\Price_Grouping;
use Woodev\Framework\Marketplace\Price\Price_Intent;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Price\Push_Receipt;
use Woodev\Framework\Marketplace\Sync\Price_Groups;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Sync_Limits;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the due prices of one account (the `to_marketplace` direction) **under a claim the caller holds**.
 *
 * `flush_sync` has one outer per-account sender ({@see Flush_Sync_Runner}): it re-reads the account, asks the licence gate,
 * takes the account claim, runs the halves — stock, then this price half — inside one request budget, and releases the claim.
 * This class is the price half. It registers no hook, schedules nothing, and **never acquires, renews or releases the claim**:
 * {@see self::flush_under_claim()} is the seam the outer sender defines.
 *
 * - Preconditions it checks itself: the account's `price_direction` is `to_marketplace` (a pending intent of an account that
 *   switched to `from_marketplace` or `off` is never sent) and the plugin's write gate lets the account write.
 * - Each round takes up to one request's worth of due pairs ({@see Price_Sync_Queue::due()}), snapshots them into
 *   `in_flight_*`, checks the claim again right before the request and sends. Every queue write carries the claim token in its
 *   SQL, so a sender whose claim was taken over changes nothing; a settlement that was refused for that reason is not counted
 *   as sent and ends the run.
 * - For a pusher with {@see Price_Grouping} a round takes whole price groups, and some groups are held, put off or turned down
 *   instead of sent ({@see Price_Groups}).
 * - An accepted item makes the snapshot the sent payload; a newer pending revision is sent later. A refused item keeps the
 *   marketplace's text verbatim and backs the pair off by the ladder of {@see Backoff}; the attempt cap of the price cadence
 *   stops the retries of one revision until the payload changes.
 * - A refused call follows {@see Remote_Error}: authorisation errors pause the account; a wait is not a failed attempt; a
 *   server or transport failure is an uncertain outcome (the request may have been applied), so the pair keeps its snapshot
 *   and waits at least the price queue's minimum interval before the newest payload is sent.
 * - The caller's budget is in requests: the half sends at most `$remaining_budget` requests and returns how many it used. The
 *   time budget is the caller's {@see Account_Run}: the half checks its deadline before every round and starts no request
 *   after it.
 * - **Asynchronous acceptance** (2.1.25): the lines of a pending or duplicate receipt that have no immediate answer open a price
 *   task ({@see Price_Task_Opener}) and wait for it; nothing sends them meanwhile, and {@see Price_Poll_Runner} settles them. Lines
 *   such a receipt answers at once are settled as above, except that a refusal is final, as the task's would be: the price is not
 *   sent again until it changes ({@see Price_Refusal_Writer}).
 *
 * @since 2.1.0
 */
final class Price_Flush_Runner {
	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Accounts: the account is re-read, and the claim is asked whether it is still held, never taken.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Price queue.
	 *
	 * @var Price_Sync_Queue
	 */
	private $queue;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Pusher.
	 *
	 * @var Price_Pusher
	 */
	private $pusher;

	/**
	 * Per-request limits of the marketplace.
	 *
	 * @var Sync_Limits
	 */
	private $limits;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Opens the task of an upload the marketplace decides on later.
	 *
	 * @var Price_Task_Opener
	 */
	private $tasks;

	/**
	 * Keeps a price the marketplace turned down for good.
	 *
	 * @var Price_Refusal_Writer
	 */
	private $refusals;

	/**
	 * Price groups; null for a marketplace that keeps one price per link.
	 *
	 * @var Price_Groups|null
	 */
	private $groups;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @since 2.1.25 `$tasks` and `$refusals`.
	 *
	 * @param Job_Gate             $gate       Job gate (the pause and the write gate).
	 * @param Wpdb_Account_Store   $accounts   Accounts.
	 * @param Price_Sync_Queue     $queue      Price queue.
	 * @param Wpdb_Link_Store      $links      Links.
	 * @param Price_Pusher         $pusher     Pusher.
	 * @param Sync_Limits          $limits     Per-request limits of the marketplace.
	 * @param Error_Classifier     $classifier Classifies a failed request.
	 * @param callable             $log_error  `function ( string $line ): void`.
	 * @param Price_Task_Opener    $tasks      Opens the task of an upload the marketplace decides on later.
	 * @param Price_Refusal_Writer $refusals Keeps a price the marketplace turned down for good.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Account_Store $accounts, Price_Sync_Queue $queue, Wpdb_Link_Store $links, Price_Pusher $pusher, Sync_Limits $limits, Error_Classifier $classifier, callable $log_error, Price_Task_Opener $tasks, Price_Refusal_Writer $refusals ) {
		$this->gate       = $gate;
		$this->accounts   = $accounts;
		$this->queue      = $queue;
		$this->links      = $links;
		$this->pusher     = $pusher;
		$this->limits     = $limits;
		$this->classifier = $classifier;
		$this->log_error  = $log_error;
		$this->tasks      = $tasks;
		$this->refusals   = $refusals;
		$this->groups     = $pusher instanceof Price_Grouping ? new Price_Groups( $pusher, $queue, $links, Price_Poll_Runner::interval_of( $pusher ) ) : null;
	}

	/**
	 * Sends the due prices of an account while the caller's claim holds.
	 *
	 * The seam of the outer sender: it has checked the account, the licence and the pause, holds `$token` and passes what is
	 * left of its request budget. This half re-reads the account, never exceeds the budget and reports the requests it really
	 * sent.
	 *
	 * @since 2.1.0
	 *
	 * @param int               $account_id       Account id.
	 * @param string            $token            Account claim token the caller holds.
	 * @param int               $remaining_budget Most requests this half may send.
	 * @param Account_Run|null  $run              The caller's run: no request starts once its deadline has passed. Null = no time limit.
	 * @param Flush_Result|null $result           Tally to fill, for the merchant's message.
	 * @return int Requests used.
	 * @throws \RuntimeException When the database refuses a queue statement.
	 */
	public function flush_under_claim( int $account_id, string $token, int $remaining_budget, ?Account_Run $run = null, ?Flush_Result $result = null ): int {
		$result  = $result ?? new Flush_Result();
		$account = $this->accounts->get( $account_id );

		if ( null === $account || ! $account->is_enabled() ) {
			return 0;
		}

		if ( ! Price_Intents::pushes( $account ) ) {
			$result->mark_disabled();

			return 0;
		}

		if ( ! $this->gate->allows_writes( $account ) ) {
			$result->mark_disabled( __( 'Цены пока не отправляются: аккаунт ещё не готов к записи на маркетплейс.', 'woodev-plugin-framework' ) );

			return 0;
		}

		$this->queue->forget_orphans( $account_id, $token );

		if ( null !== $this->groups ) {
			$this->groups->release_resolved( $account, $token, $result );
		}

		$used = 0;

		while ( $used < $remaining_budget ) {
			if ( self::out_of_time( $run ) ) {
				return $used; // The run's time is up: a request that starts now would end after it.
			}

			if ( ! $this->accounts->holds_claim( $account_id, $token ) ) {
				$this->claim_lost( $account );

				return $used;
			}

			$batch = $this->begin_batch( $account, $token, $result );

			if ( [] === $batch ) {
				return $used;
			}

			list( $sent, $go_on ) = $this->send( $account, $token, $batch, $result, $run );
			$used                += $sent ? 1 : 0;

			if ( ! $go_on ) {
				return $used;
			}
		}

		return $used;
	}

	/**
	 * Takes the due pairs, whole price groups for a marketplace that has them, and snapshots them as in flight.
	 *
	 * @param Account      $account Account.
	 * @param string       $token   Claim token.
	 * @param Flush_Result $result  Tally.
	 * @return array<int,array{0:Price_Intent,1:Price_Line|null}> Intent and its line (null when the link vanished).
	 */
	private function begin_batch( Account $account, string $token, Flush_Result $result ): array {
		if ( null === $this->groups ) {
			$groups = array_map(
				static function ( Price_Intent $intent ): array {
					return [ $intent ];
				},
				$this->queue->due( $account->id(), $this->limits->price_batch() )
			);
		} else {
			$groups = $this->groups->next_batch( $account, $this->limits->price_batch(), $token, $result );
		}

		$batch = [];

		foreach ( $groups as $group ) {
			$batch = array_merge( $batch, $this->begin_group( $account, $token, $group ) );
		}

		return $batch;
	}

	/**
	 * Snapshots the pairs of one group as in flight. A group is sent whole or not at all: when one of its pairs changed
	 * meanwhile, none of it goes out now and the next run reads the group again.
	 *
	 * @param Account        $account Account.
	 * @param string         $token   Claim token.
	 * @param Price_Intent[] $group   Pairs of one group.
	 * @return array<int,array{0:Price_Intent,1:Price_Line|null}> Intent and its line; empty when the group cannot go.
	 */
	private function begin_group( Account $account, string $token, array $group ): array {
		$begun = [];

		foreach ( $group as $intent ) {
			if ( ! $this->queue->begin( $account->id(), $intent, $token ) ) {
				return [];
			}

			$begun[] = [ $intent, $this->line_of( $intent ) ];
		}

		return $begun;
	}

	/**
	 * Builds the line of an intent.
	 *
	 * @param Price_Intent $intent Intent.
	 * @return Price_Line|null Null when the link is gone, is not a card, or the payload holds no readable price.
	 */
	private function line_of( Price_Intent $intent ): ?Price_Line {
		$record = $this->links->get( $intent->link_id() );

		if ( null === $record || ! $record->is_offer() || null === $record->offer_id() ) {
			return null;
		}

		try {
			return new Price_Line( $this->links->to_product_link( $record ), $intent->pair() );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Sends one batch and settles each pair.
	 *
	 * @param Account                                            $account Account.
	 * @param string                                             $token   Claim token.
	 * @param array<int,array{0:Price_Intent,1:Price_Line|null}> $batch   Intents and their lines.
	 * @param Flush_Result                                       $result  Tally.
	 * @param Account_Run|null                                   $run     The caller's run, whose deadline is checked right before the request.
	 * @return array{0:bool,1:bool} Whether a request was sent, and whether the run goes on.
	 * @throws \Throwable What the pusher threw, when it is not a marketplace answer.
	 */
	private function send( Account $account, string $token, array $batch, Flush_Result $result, ?Account_Run $run = null ): array {
		$lines = [];

		foreach ( $batch as list( $intent, $line ) ) {
			if ( null === $line ) {
				$this->reject( $account, $token, $intent, __( 'Товар или его связь с карточкой маркетплейса больше не существуют.', 'woodev-plugin-framework' ), $result );
				continue;
			}

			$lines[ $intent->link_id() ] = $line;
		}

		if ( [] === $lines ) {
			return [ false, true ];
		}

		if ( ! $this->accounts->holds_claim( $account->id(), $token ) ) {
			// A side effect outside our tables follows a fresh proof of ownership.
			$this->claim_lost( $account );

			return [ false, false ];
		}

		if ( self::out_of_time( $run ) ) {
			return [ false, false ]; // Preparing the batch used the run's time; the snapshot is sent by the next run.
		}

		try {
			$receipt = $this->pusher->submit( $account, array_values( $lines ) );
		} catch ( \Throwable $thrown ) {
			$error = $this->classifier->classify( $thrown );

			if ( null === $error ) {
				throw $thrown;
			}

			return [ $error->was_sent(), $this->after_failure( $account, $token, $batch, $lines, $error, $result ) ];
		}

		if ( ! $receipt->is_pending() ) {
			return [ true, $this->settle_all( $account, $token, $batch, $receipt, $result ) ];
		}

		return [ true, $this->settle_pending( $account, $token, $batch, $receipt, $result ) ];
	}

	/**
	 * Settles a batch whose receipt is pending: what it refuses at once is final, what it accepts or puts off at once is settled as an
	 * immediate answer is, and the rest waits for the price task.
	 *
	 * @param Account                                            $account Account.
	 * @param string                                             $token   Claim token.
	 * @param array<int,array{0:Price_Intent,1:Price_Line|null}> $batch   Intents of the request.
	 * @param Push_Receipt                                       $receipt The marketplace's answer.
	 * @param Flush_Result                                       $result  Tally.
	 * @return bool False when the claim is gone: the run must stop.
	 */
	private function settle_pending( Account $account, string $token, array $batch, Push_Receipt $receipt, Flush_Result $result ): bool {
		$answers  = $receipt->immediate_results();
		$answered = [];
		$awaited  = [];
		$go_on    = true;

		foreach ( $batch as list( $intent, $line ) ) {
			if ( null === $line ) {
				continue;
			}

			$answer = $answers[ $intent->link_id() ] ?? null;

			if ( null === $answer ) {
				$awaited[] = [ $intent, $line ];
			} elseif ( $answer->is_ok() || null !== $answer->retry_after_seconds() ) {
				$answered[] = [ $intent, $line ];
			} else {
				$go_on = $this->refuse_for_good( $account, $token, $intent, $answer, $result ) && $go_on;
			}
		}

		$go_on = $this->settle_all( $account, $token, $answered, $receipt, $result ) && $go_on;

		return $this->await_all( $account, $token, $awaited, $receipt, $result ) && $go_on;
	}

	/**
	 * Records a refusal that no retry can change.
	 *
	 * @param Account      $account Account.
	 * @param string       $token   Claim token.
	 * @param Price_Intent $intent  Intent.
	 * @param Item_Result  $answer  The marketplace's refusal.
	 * @param Flush_Result $result  Tally.
	 * @return bool False when the claim is gone.
	 */
	private function refuse_for_good( Account $account, string $token, Price_Intent $intent, Item_Result $answer, Flush_Result $result ): bool {
		$message = $this->refusals->write( $account->id(), $intent, $answer, $token );

		if ( null === $message ) {
			return $this->lost( $account );
		}

		$result->add_failed( $message );

		return true;
	}

	/**
	 * Hands the prices a pending receipt has not answered to its price task.
	 *
	 * @param Account                                       $account Account.
	 * @param string                                        $token   Claim token.
	 * @param array<int,array{0:Price_Intent,1:Price_Line}> $awaited Prices without an answer.
	 * @param Push_Receipt                                  $receipt The marketplace's answer.
	 * @param Flush_Result                                  $result  Tally.
	 * @return bool False when the claim is gone: the run must stop.
	 */
	private function await_all( Account $account, string $token, array $awaited, Push_Receipt $receipt, Flush_Result $result ): bool {
		if ( [] === $awaited ) {
			return true;
		}

		// A task is written outside the claim's fence: it follows a fresh proof of ownership.
		if ( ! $this->accounts->holds_claim( $account->id(), $token ) || ! $this->tasks->open( $account->id(), $receipt, $awaited, $token ) ) {
			return $this->lost( $account );
		}

		$result->add_awaiting( count( $awaited ) );

		return true;
	}

	/**
	 * Settles every pair of a list from the marketplace's immediate answers.
	 *
	 * @param Account                                            $account Account.
	 * @param string                                             $token   Claim token.
	 * @param array<int,array{0:Price_Intent,1:Price_Line|null}> $batch   Intents of the request.
	 * @param Push_Receipt                                       $receipt The marketplace's answer.
	 * @param Flush_Result                                       $result  Tally.
	 * @return bool False when a settlement was refused because the claim is gone: the run must stop.
	 */
	private function settle_all( Account $account, string $token, array $batch, Push_Receipt $receipt, Flush_Result $result ): bool {
		$answers = $receipt->immediate_results();
		$go_on   = true;

		foreach ( $batch as list( $intent, $line ) ) {
			if ( null !== $line && ! $this->settle( $account, $token, $intent, $answers[ $intent->link_id() ] ?? null, $result ) ) {
				$go_on = false;
			}
		}

		return $go_on;
	}

	/**
	 * Settles one pair from the marketplace's answer.
	 *
	 * @param Account          $account Account.
	 * @param string           $token   Claim token.
	 * @param Price_Intent     $intent  Intent.
	 * @param Item_Result|null $answer  The marketplace's answer, null when it gave none.
	 * @param Flush_Result     $result  Tally.
	 * @return bool False when the settlement was refused because the claim is gone: the run must stop.
	 */
	private function settle( Account $account, string $token, Price_Intent $intent, ?Item_Result $answer, Flush_Result $result ): bool {
		if ( null !== $answer && $answer->is_ok() ) {
			if ( ! $this->queue->accept( $account->id(), $intent, $token ) ) {
				return $this->lost( $account );
			}

			$result->add_sent();

			return true;
		}

		$wait = null === $answer ? null : $answer->retry_after_seconds();

		if ( null !== $wait ) {
			$result->add_waiting();

			return $this->queue->defer( $account->id(), $intent, max( 1, $wait ), $token ) || $this->lost( $account );
		}

		$text = null === $answer ? __( 'Маркетплейс не вернул ответ по этому товару.', 'woodev-plugin-framework' ) : $answer->message();

		return $this->reject( $account, $token, $intent, $text, $result );
	}

	/**
	 * Records a refusal and backs the pair off.
	 *
	 * @param Account      $account Account.
	 * @param string       $token   Claim token.
	 * @param Price_Intent $intent  Intent.
	 * @param string       $message The marketplace's text.
	 * @param Flush_Result $result  Tally.
	 * @param int          $minimum Least seconds to wait.
	 * @return bool False when the claim is gone.
	 */
	private function reject( Account $account, string $token, Price_Intent $intent, string $message, Flush_Result $result, int $minimum = 0 ): bool {
		$result->add_failed( $message );

		return $this->queue->reject( $account->id(), $intent, $message, max( $minimum, Backoff::seconds_after( $intent->attempts() + 1 ) ), $token ) || $this->lost( $account );
	}

	/**
	 * Applies the consequence of a refused call.
	 *
	 * @param Account                                            $account Account.
	 * @param string                                             $token   Claim token.
	 * @param array<int,array{0:Price_Intent,1:Price_Line|null}> $batch   Intents of the request.
	 * @param array<int,Price_Line>                              $lines   Lines that were in it.
	 * @param Remote_Error                                       $error   The refusal.
	 * @param Flush_Result                                       $result  Tally.
	 * @return bool False: the run stops.
	 */
	private function after_failure( Account $account, string $token, array $batch, array $lines, Remote_Error $error, Flush_Result $result ): bool {
		$this->log( 'flush_sync (prices): account ' . $account->id() . ': ' . $error->describe() );

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
		}

		$waits     = $error->is( Remote_Error::THROTTLE ) || $error->is( Remote_Error::PAUSE_ACCOUNT );
		$uncertain = $error->is( Remote_Error::RETRY );

		foreach ( $batch as list( $intent, $line ) ) {
			if ( null === $line || ! isset( $lines[ $intent->link_id() ] ) ) {
				continue;
			}

			if ( $waits ) {
				$this->queue->defer( $account->id(), $intent, max( 1, (int) ( $error->retry_after() ?? Job_Gate::BUSY_DELAY ) ), $token );
				$result->add_waiting();
				continue;
			}

			// A server or transport failure may have been applied: the next send is at least one price interval away.
			$this->reject( $account, $token, $intent, $error->message(), $result, $uncertain ? $this->queue->cadence()->min_interval() : 0 );
		}

		return false;
	}

	/**
	 * Logs that the claim was lost and says so.
	 *
	 * @param Account $account Account.
	 * @return bool Always false.
	 */
	private function lost( Account $account ): bool {
		$this->claim_lost( $account );

		return false;
	}

	/**
	 * Logs a lost claim.
	 *
	 * @param Account $account Account.
	 * @return void
	 */
	private function claim_lost( Account $account ): void {
		$this->log( 'flush_sync (prices): account ' . $account->id() . ': the claim was taken over; the current sender continues.' );
	}

	/**
	 * Tells whether the caller's run has used its time, so no request may start.
	 *
	 * @param Account_Run|null $run The caller's run; null has no time limit.
	 * @return bool
	 */
	private static function out_of_time( ?Account_Run $run ): bool {
		return null !== $run && $run->remaining_seconds() <= 0.0;
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
