<?php
/**
 * The `from_marketplace` price refresh job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_refresh_prices( account_id, offer_ids[≤100], attempt )`: reads the marketplace's prices for the articles
 * and writes them into the products ({@see Price_Refresher}). It only reads from the marketplace, so it takes no account claim.
 * A refused read follows {@see Remote_Error}: an authorisation error pauses the account and the batch waits the pause out, a
 * wait is not a failed attempt, a server or transport failure retries on the ladder of {@see Backoff} and gives up after five
 * attempts with a log line.
 *
 * @since 2.1.0
 */
final class Price_Refresh_Runner {
	public const MAX_ATTEMPTS = 5;

	/**
	 * Seconds a throttled batch waits when the marketplace names no wait.
	 */
	private const THROTTLE_SECONDS = 60;

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Refresher.
	 *
	 * @var Price_Refresher
	 */
	private $refresher;

	/**
	 * Scheduler.
	 *
	 * @var Price_Scheduler
	 */
	private $scheduler;

	/**
	 * Classifies a failed read.
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
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Gate         $gate       Job gate.
	 * @param Price_Refresher  $refresher  Refresher.
	 * @param Price_Scheduler  $scheduler  Scheduler.
	 * @param Error_Classifier $classifier Classifies a failed read.
	 * @param callable         $log_error  `function ( string $line ): void`.
	 */
	public function __construct( Job_Gate $gate, Price_Refresher $refresher, Price_Scheduler $scheduler, Error_Classifier $classifier, callable $log_error ) {
		$this->gate       = $gate;
		$this->refresher  = $refresher;
		$this->scheduler  = $scheduler;
		$this->classifier = $classifier;
		$this->log_error  = $log_error;
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->refresh_hook(), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $offer_ids  Articles.
	 * @param mixed $attempt    Failed attempts so far.
	 * @return void
	 */
	public function run( $account_id = 0, $offer_ids = [], $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;
		$offer_ids  = array_slice( array_values( array_unique( array_map( 'strval', (array) $offer_ids ) ) ), 0, Price_Scheduler::REFRESH_BATCH );

		try {
			$this->start( $account_id, $offer_ids, $attempt );
		} catch ( \Throwable $e ) {
			$this->log( 'refresh_prices: account ' . $account_id . ' (attempt ' . ( $attempt + 1 ) . '): ' . get_class( $e ) . ': ' . $e->getMessage() );
			$this->retry_later( $account_id, $offer_ids, $attempt );
		}
	}

	/**
	 * Applies the gate, then refreshes.
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @param int      $attempt    Failed attempts so far.
	 * @return void
	 * @throws \Throwable What the refresh threw, when it is not a marketplace answer.
	 */
	private function start( int $account_id, array $offer_ids, int $attempt ): void {
		list( $account, $wait ) = $this->gate->check( $account_id );

		if ( null === $account || [] === $offer_ids ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->scheduler->refresh_later( $account_id, $offer_ids, $attempt, $wait );

			return;
		}

		try {
			$this->refresher->refresh( $account, $offer_ids );
		} catch ( \Throwable $thrown ) {
			$error = $this->classifier->classify( $thrown );

			if ( null === $error ) {
				throw $thrown;
			}

			$this->after_failure( $account, $offer_ids, $attempt, $error );
		}
	}

	/**
	 * Applies the consequence of a refused read.
	 *
	 * @param Account      $account   Account.
	 * @param string[]     $offer_ids Articles.
	 * @param int          $attempt   Failed attempts so far.
	 * @param Remote_Error $error     The refusal.
	 * @return void
	 */
	private function after_failure( Account $account, array $offer_ids, int $attempt, Remote_Error $error ): void {
		$this->log( 'refresh_prices: account ' . $account->id() . ': ' . $error->describe() );

		switch ( $error->consequence() ) {
			case Remote_Error::PAUSE_ACCOUNT:
				$this->gate->pause()->apply( $account, $error );
				$this->scheduler->refresh_later( $account->id(), $offer_ids, $attempt, Account_Pause::SECONDS );

				return;
			case Remote_Error::THROTTLE:
				$this->scheduler->refresh_later( $account->id(), $offer_ids, $attempt, max( 1, (int) ( $error->retry_after() ?? self::THROTTLE_SECONDS ) ) );

				return;
			case Remote_Error::RETRY:
				$this->retry_later( $account->id(), $offer_ids, $attempt );

				return;
		}
	}

	/**
	 * Schedules the batch again on the ladder, or gives up after the last attempt. The writes are idempotent, so the batch is
	 * repeated whole: what was already refreshed compares equal and is not saved again.
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @param int      $attempt    Failed attempts before this one.
	 * @return void
	 */
	private function retry_later( int $account_id, array $offer_ids, int $attempt ): void {
		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			$this->log( 'refresh_prices: account ' . $account_id . ' gave up on ' . count( $offer_ids ) . ' articles after ' . self::MAX_ATTEMPTS . ' attempts.' );

			return;
		}

		try {
			$this->scheduler->refresh_later( $account_id, $offer_ids, $attempt + 1, Backoff::seconds_after( $attempt + 1 ) );
		} catch ( \Throwable $e ) {
			$this->log( 'refresh_prices: the retry was not scheduled: ' . $e->getMessage() );
		}
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
