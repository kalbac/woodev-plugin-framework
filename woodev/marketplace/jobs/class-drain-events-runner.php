<?php
/**
 * Event drain runner.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Ledger\Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_drain_events( account_id )`: claims up to 50 due events of an account, hands each to the handler of its
 * type and records the outcome.
 *
 * - An account that is gone or switched off is left alone, silently.
 * - A handler that throws sets the event to `retry` with the ladder of {@see Backoff}; the fifth failed attempt makes it
 *   `failed` with the text. The runner never throws out: a failure of the ledger itself leaves the rows `processing` until
 *   their lease expires and the next run takes them over.
 * - Every state change carries the claim token (the ledger's rule), so a runner whose rows were reclaimed discards its result.
 * - It stops starting events after 20 seconds and asks for another run; whatever it did not start keeps its lease.
 * - While the licence is not in force nothing is claimed and no handler runs: the events stay `new` in the ledger (the intake
 *   keeps storing them) and the drain asks to run again in an hour, so a renewed licence resumes the work without the merchant
 *   touching anything.
 *
 * @since 2.1.0
 */
final class Drain_Events_Runner {
	public const BATCH_SIZE            = 50;
	public const BUDGET_SECONDS        = 20;
	public const MAX_ATTEMPTS          = 5;
	public const REQUEUE_SECONDS       = 125;
	public const LICENSE_RETRY_SECONDS = 3600;

	/**
	 * Event ledger.
	 *
	 * @var Event_Ledger
	 */
	private $ledger;

	/**
	 * Accounts.
	 *
	 * @var Notification_Account_Reader
	 */
	private $accounts;

	/**
	 * Handlers.
	 *
	 * @var Event_Handler_Registry
	 */
	private $handlers;

	/**
	 * Scheduler.
	 *
	 * @var Drain_Scheduler
	 */
	private $scheduler;

	/**
	 * Clock: Unix time as a float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Licence gate; null leaves the drain ungated.
	 *
	 * @var License_Gate|null
	 */
	private $license;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @param Event_Ledger                $ledger    Event ledger.
	 * @param Notification_Account_Reader $accounts  Accounts.
	 * @param Event_Handler_Registry      $handlers  Handlers.
	 * @param Drain_Scheduler             $scheduler Scheduler.
	 * @param callable|null               $clock     Returns the Unix time as a float; the system clock when null.
	 * @param License_Gate|null           $license   Licence gate asked before anything is claimed.
	 */
	public function __construct( Event_Ledger $ledger, Notification_Account_Reader $accounts, Event_Handler_Registry $handlers, Drain_Scheduler $scheduler, ?callable $clock = null, ?License_Gate $license = null ) {
		$this->ledger    = $ledger;
		$this->accounts  = $accounts;
		$this->handlers  = $handlers;
		$this->scheduler = $scheduler;
		$this->license   = $license;
		$this->clock     = $clock ?? static function (): float {
			return microtime( true );
		};
	}

	/**
	 * Hooks the drain and the sweep.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'run' ] );
		add_action( $this->scheduler->sweep_hook(), [ $this, 'run' ] );
	}

	/**
	 * Drains one account.
	 *
	 * @since 2.1.0
	 *
	 * @param int|string $account_id Account id (Action Scheduler passes the stored argument).
	 * @return void
	 */
	public function run( $account_id ): void {
		$account_id = (int) $account_id;

		try {
			$account = $this->accounts->find( $account_id );

			if ( null === $account || ! $account->is_enabled() ) {
				return;
			}

			if ( null !== $this->license && ! $this->license->is_in_force() ) {
				$this->scheduler->schedule_later( $account_id, self::LICENSE_RETRY_SECONDS );

				return;
			}

			$this->drain( $account_id );
		} catch ( \Throwable $e ) {
			// The ledger or the database failed. Nothing is lost: unclaimed rows stay due and claimed ones return when the lease ends.
			return;
		}
	}

	/**
	 * Returns the wait after a failed attempt.
	 *
	 * @since 2.1.0
	 *
	 * @param int $attempts Attempts made, counting the failed one.
	 * @return int Seconds.
	 */
	public static function delay_after( int $attempts ): int {
		return Backoff::seconds_after( $attempts );
	}

	/**
	 * Claims a batch and works it until the budget ends.
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	private function drain( int $account_id ): void {
		$started = $this->now();
		$batch   = $this->ledger->claim( $account_id, self::BATCH_SIZE );
		$events  = $batch->events();

		foreach ( $events as $event ) {
			if ( $this->now() - $started > self::BUDGET_SECONDS ) {
				$this->scheduler->schedule_later( $account_id, self::REQUEUE_SECONDS );

				return;
			}

			$this->process( $event, $batch->token() );
		}

		if ( count( $events ) >= self::BATCH_SIZE ) {
			$this->scheduler->schedule_later( $account_id, 0 );
		}
	}

	/**
	 * Runs one event's handler and records the outcome under the batch's token.
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @param string        $token Claim token.
	 * @return void
	 */
	private function process( Inbound_Event $event, string $token ): void {
		$event_id = (int) $event->event_id();

		try {
			$result = $this->handlers->for_type( $event->type() )->handle( $event );
		} catch ( \Throwable $e ) {
			$this->record_failure( $event, $token, $e );

			return;
		}

		// False means the rows were reclaimed while the handler ran: another runner owns the event now, our result is void.
		$this->ledger->complete( $event_id, $token, $result );
	}

	/**
	 * Schedules a retry, or fails the event for good on the last attempt.
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @param string        $token Claim token.
	 * @param \Throwable    $error What the handler threw.
	 * @return void
	 */
	private function record_failure( Inbound_Event $event, string $token, \Throwable $error ): void {
		$event_id = (int) $event->event_id();
		$text     = get_class( $error ) . ': ' . $error->getMessage();

		if ( $event->attempts() >= self::MAX_ATTEMPTS ) {
			$this->ledger->fail( $event_id, $token, $text );

			return;
		}

		$this->ledger->retry( $event_id, $token, self::delay_after( $event->attempts() ), $text );
	}

	/**
	 * Reads the clock.
	 *
	 * @return float
	 */
	private function now(): float {
		return (float) call_user_func( $this->clock );
	}
}
