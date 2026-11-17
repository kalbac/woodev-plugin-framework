<?php
/**
 * The `from_marketplace` price refresh job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_refresh_prices( account_id, offer_ids[≤100], attempt, mode )`: reads the marketplace's prices for the articles
 * and writes them into the products ({@see Price_Refresher}). It only reads from the marketplace, so it takes no account claim.
 * A refused read follows {@see Remote_Error}: an authorisation error pauses the account and the batch waits the pause out, a
 * wait is not a failed attempt, a server or transport failure retries on the ladder of {@see Backoff} and gives up after five
 * attempts with a log line.
 *
 * With `mode = pull` (the merchant's one-time «Загрузить цены», #1797) the batch runs through {@see Account_Job}: the job gate and the
 * account claim, because the pull also settles the price the sender had queued and must not run beside it. A busy account waits
 * {@see Job_Gate::BUSY_DELAY} and the batch is tried again.
 *
 * @since 2.1.0
 */
final class Price_Refresh_Runner {
	public const MAX_ATTEMPTS = 5;

	private const FAILURE_CODE = 'pull_prices_failed';

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
	 * The skeleton the pull runs in: gate, claim, work, classify.
	 *
	 * @var Account_Job
	 */
	private $pulls;

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
	 * @param Api_Error_Ring   $ring       Error ring, which the pull's failures are written to (2.1.10).
	 */
	public function __construct( Job_Gate $gate, Price_Refresher $refresher, Price_Scheduler $scheduler, Error_Classifier $classifier, callable $log_error, Api_Error_Ring $ring ) {
		$this->gate       = $gate;
		$this->refresher  = $refresher;
		$this->scheduler  = $scheduler;
		$this->classifier = $classifier;
		$this->log_error  = $log_error;
		$this->pulls      = new Account_Job( $gate, $classifier, $ring, $log_error, '', self::FAILURE_CODE );
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->refresh_hook(), [ $this, 'run' ], 10, 5 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $offer_ids  Articles.
	 * @param mixed $attempt    Failed attempts so far.
	 * @param mixed $mode       {@see Price_Scheduler::PULL_MODE} for the merchant's pull of prices (2.1.10); absent for the read driven by a notification.
	 * @param mixed $put_offs   Waits so far (2.1.12), for a pull; absent on a batch that has not waited.
	 * @return void
	 */
	public function run( $account_id = 0, $offer_ids = [], $attempt = 0, $mode = '', $put_offs = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;
		$offer_ids  = array_slice( array_values( array_unique( array_map( 'strval', (array) $offer_ids ) ) ), 0, Price_Scheduler::REFRESH_BATCH );

		if ( Price_Scheduler::PULL_MODE === $mode ) {
			$this->pull( $account_id, $offer_ids, $attempt, max( 0, (int) $put_offs ) );

			return;
		}

		try {
			$this->start( $account_id, $offer_ids, $attempt );
		} catch ( \Throwable $e ) {
			$this->log( 'refresh_prices: account ' . $account_id . ' (attempt ' . ( $attempt + 1 ) . '): ' . get_class( $e ) . ': ' . $e->getMessage() );
			$this->retry_later( $account_id, $offer_ids, $attempt );
		}
	}

	/**
	 * Runs a batch of the merchant's pull of prices in the account-job skeleton: a gated or busy account waits and tries again with the
	 * same batch, a refused read follows the adapter's verdict, nothing is thrown out of the job.
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @param int      $attempt    Failed attempts so far.
	 * @param int      $put_offs   Waits so far.
	 * @return void
	 */
	private function pull( int $account_id, array $offer_ids, int $attempt, int $put_offs ): void {
		if ( [] === $offer_ids ) {
			return;
		}

		$this->pulls->run(
			$account_id,
			$this->scheduler->refresh_hook(),
			function ( int $delay, int $failed, int $waits ) use ( $account_id, $offer_ids ): bool {
				$this->scheduler->refresh_later( $account_id, $offer_ids, $failed, $delay, Price_Scheduler::PULL_MODE, $waits );

				return true;
			},
			function ( string $text ) use ( $account_id ): void {
				$this->log( 'refresh_prices (pull): account ' . $account_id . ' gave up: ' . $text );
			},
			$attempt,
			function ( Account $account, Claim_Fence $fence ) use ( $offer_ids ): void {
				$extend = function () use ( $account, $fence ): void {
					$this->pulls->hold( $account->id(), $fence );
				};
				$result = $this->refresher->pull_once( $account, $offer_ids, $fence, $extend );

				// One line per article, with its own sentence: the merchant has to find the product that needs a look. A batch holds at
				// most REFRESH_BATCH articles, so this is bounded.
				foreach ( $result->skipped() as $offer_id => $reason ) {
					$this->log( 'refresh_prices (pull): account ' . $account->id() . ': article ' . $offer_id . ' not loaded: ' . $reason );
				}
			},
			$put_offs
		);
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
