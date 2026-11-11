<?php
/**
 * The `refresh_catalog` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Catalog_Refresher;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `woodev_{plugin id}_refresh_catalog` (at most 60 s): re-reads its account and stops silently when it is disabled, asks the
 * licence (a lapsed licence reschedules the job hourly without calling the marketplace), honours the account pause, refreshes the
 * tree and the attributes of the mapped categories through the adapter's {@see Catalog_Refresher}, and never throws out.
 *
 * A pass that runs out of time schedules itself again with the same freshness limit, so finished work is not repeated. A failure the
 * marketplace may recover from retries on the {@see Backoff} ladder and stops at the fifth failed attempt. Every failure that is not
 * already in the error ring through the client (an unexpected exception, a continuation that Action Scheduler refused, the final
 * give-up) is written to the ring and to the error log, so the diagnostics page shows an incomplete catalogue instead of a queue
 * action that looks finished.
 *
 * No account claim: the catalogue cache belongs to the marketplace, not to an account, and the job writes nothing a claim guards.
 *
 * @since 2.0.30
 */
final class Refresh_Catalog_Runner {
	public const BUDGET_SECONDS = 60;
	public const MAX_ATTEMPTS   = 5;
	public const CONTINUE_DELAY = 60;
	public const FIRST_ENABLED  = 0;

	private const FAILURE_CODE = 'refresh_catalog_failed';

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Marketplace id, to find "the first enabled account".
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Job gate: enabled, licence, pause.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * The marketplace's tree and attribute reads.
	 *
	 * @var Catalog_Refresher
	 */
	private $refresher;

	/**
	 * Scheduler.
	 *
	 * @var Refresh_Catalog_Scheduler
	 */
	private $scheduler;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Error ring.
	 *
	 * @var Api_Error_Ring
	 */
	private $ring;

	/**
	 * Writes a line to the error log.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Clock returning a Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the runner.
	 *
	 * @since 2.0.30
	 *
	 * @param Wpdb_Account_Store        $accounts       Accounts.
	 * @param string                    $marketplace_id The adapter's {@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::id()}.
	 * @param Job_Gate                  $gate           Job gate.
	 * @param Catalog_Refresher         $refresher      The adapter's refresher.
	 * @param Refresh_Catalog_Scheduler $scheduler      Scheduler.
	 * @param Error_Classifier          $classifier     The adapter's error classifier.
	 * @param Api_Error_Ring            $ring           Error ring.
	 * @param callable                  $log_error      `function ( string $line ): void`, the error-level sink.
	 * @param callable|null             $clock          `function (): int`; the system clock when null.
	 */
	public function __construct( Wpdb_Account_Store $accounts, string $marketplace_id, Job_Gate $gate, Catalog_Refresher $refresher, Refresh_Catalog_Scheduler $scheduler, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, ?callable $clock = null ) {
		$this->accounts       = $accounts;
		$this->marketplace_id = $marketplace_id;
		$this->gate           = $gate;
		$this->refresher      = $refresher;
		$this->scheduler      = $scheduler;
		$this->classifier     = $classifier;
		$this->ring           = $ring;
		$this->log_error      = $log_error;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $account_id Account id, 0 for the first enabled account.
	 * @param mixed $since      Freshness limit of a pass being continued, 0 for a new pass.
	 * @param mixed $attempt    Failed attempts so far.
	 * @return void
	 */
	public function run( $account_id = self::FIRST_ENABLED, $since = 0, $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$since      = (int) $since;
		$attempt    = (int) $attempt;

		try {
			$this->execute( $account_id, $since, $attempt );
		} catch ( \Throwable $e ) {
			$this->report( $account_id, 'refresh_catalog failed unexpectedly: ' . get_class( $e ) . ': ' . $e->getMessage() );
			$this->retry_or_give_up( $account_id, $since, $attempt, Backoff::seconds_after( $attempt + 1 ) );
		}
	}

	/**
	 * Does the work of one run.
	 *
	 * @param int $account_id Account id, 0 for the first enabled account.
	 * @param int $since      Freshness limit, 0 for a new pass.
	 * @param int $attempt    Failed attempts so far.
	 * @return void
	 */
	private function execute( int $account_id, int $since, int $attempt ): void {
		// The account first: a disabled or deleted account ends its chain whatever the licence says.
		$resolved = self::FIRST_ENABLED === $account_id ? $this->first_enabled() : $account_id;
		$account  = null;
		$wait     = 0;

		if ( null !== $resolved ) {
			list( $account, $wait ) = $this->gate->check( $resolved );
		}

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->continue_later( $account_id, $since, $attempt, $wait );
			return;
		}

		$started = (int) call_user_func( $this->clock );
		$since   = $since > 0 ? $since : $started;

		try {
			$result = $this->refresher->refresh( $account, $this->scheduler->hook(), $since, (float) ( $started + self::BUDGET_SECONDS ) );
		} catch ( \Throwable $thrown ) {
			$error = $this->classifier->classify( $thrown );

			if ( null === $error ) {
				throw $thrown; // Not the marketplace's refusal: run() records it and retries.
			}

			$this->after_remote_failure( $account, $account_id, $since, $attempt, $error );
			return;
		}

		foreach ( $result->failures() as $category => $text ) {
			$this->report( $account->id(), 'refresh_catalog: category ' . $category . ' not refreshed: ' . $text );
		}

		if ( ! $result->is_complete() ) {
			$this->continue_later( $account_id, $since, $attempt, self::CONTINUE_DELAY );
		}
	}

	/**
	 * Finds the account that pays when the job names none: the first enabled one.
	 *
	 * @return int|null Null when there is none; the job then has nothing to do.
	 */
	private function first_enabled(): ?int {
		$enabled = $this->accounts->all( $this->marketplace_id, true );

		return isset( $enabled[0] ) ? $enabled[0]->id() : null;
	}

	/**
	 * Applies the adapter's verdict on a refused request. The failed call itself is already in the ring through the client.
	 *
	 * @param Account      $account    Account that paid.
	 * @param int          $account_id Account id of the job arguments.
	 * @param int          $since      Freshness limit.
	 * @param int          $attempt    Failed attempts so far.
	 * @param Remote_Error $error      The error.
	 * @return void
	 */
	private function after_remote_failure( Account $account, int $account_id, int $since, int $attempt, Remote_Error $error ): void {
		$this->log( 'refresh_catalog: account ' . $account->id() . ': ' . $error->describe() );

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
			$this->continue_later( $account_id, $since, $attempt, Account_Pause::SECONDS );
			return;
		}

		if ( $error->is( Remote_Error::RETRY ) || $error->is( Remote_Error::THROTTLE ) ) {
			$this->retry_or_give_up( $account_id, $since, $attempt, $error->retry_after() ?? Backoff::seconds_after( $attempt + 1 ) );
			return;
		}

		// API changed or the tree call itself refused: nothing a retry can fix, the notice and the ring carry it.
		$this->report( $account->id(), 'refresh_catalog stopped: ' . $error->kind() );
	}

	/**
	 * Schedules another attempt, or ends the job in a recorded failure at the attempt limit.
	 *
	 * @param int $account_id Account id.
	 * @param int $since      Freshness limit.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	private function retry_or_give_up( int $account_id, int $since, int $attempt, int $delay ): void {
		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			$this->report( $account_id, 'refresh_catalog gave up after ' . self::MAX_ATTEMPTS . ' failed attempts; the cached catalogue is kept' );
			return;
		}

		$this->continue_later( $account_id, $since, $attempt + 1, $delay );
	}

	/**
	 * Schedules a continuation. When Action Scheduler refuses, the loss is recorded where support looks: the job would otherwise end
	 * as a normally completed action with the rest of the refresh gone.
	 *
	 * @param int $account_id Account id.
	 * @param int $since      Freshness limit.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	private function continue_later( int $account_id, int $since, int $attempt, int $delay ): void {
		try {
			$this->scheduler->continue_later( $account_id, $since, $attempt, $delay );
		} catch ( \Throwable $e ) {
			$this->report( $account_id, 'refresh_catalog could not be continued, the next weekly run starts over: ' . $e->getMessage() );
		}
	}

	/**
	 * Writes a failure to the error ring and the error log.
	 *
	 * @param int    $account_id Account id of the job; 0 (the weekly job with no account) is stored as none.
	 * @param string $message    Failure text.
	 * @return void
	 */
	private function report( int $account_id, string $message ): void {
		try {
			$this->ring->record( $account_id > 0 ? $account_id : null, '', 0, self::FAILURE_CODE, $message, '', $this->scheduler->hook() );
		} catch ( \Throwable $e ) {
			// The ring is the diagnostics surface; the log line below still carries the failure.
			unset( $e );
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
