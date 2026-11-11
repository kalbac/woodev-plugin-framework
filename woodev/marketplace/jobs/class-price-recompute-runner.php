<?php
/**
 * The price recompute job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Sync\Price_Intents;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_recompute_prices( account_id, after_link_id )`: after a rule changed on the account, a profile or a link,
 * every link of the account gets its wanted price worked out again ({@see Price_Intents::recompute_page()}) — one page per run,
 * then the job schedules itself with the cursor. Nothing is sent here: the flush sends the pairs whose payload really changed
 * (so a rule edit that changes nothing for a product costs a read, not a request).
 *
 * A page that fails (a database error while writing an intent) is retried with the same cursor on the ladder of {@see Backoff}
 * and given up after five attempts with a log line — never dropped silently; the completed prefix is re-worked idempotently.
 *
 * @since 2.1.0
 */
final class Price_Recompute_Runner {
	public const MAX_ATTEMPTS = 5;

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Intents.
	 *
	 * @var Price_Intents
	 */
	private $intents;

	/**
	 * Scheduler.
	 *
	 * @var Price_Scheduler
	 */
	private $scheduler;

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
	 * @param Job_Gate        $gate      Job gate.
	 * @param Price_Intents   $intents   Intents.
	 * @param Price_Scheduler $scheduler Scheduler.
	 * @param callable        $log_error `function ( string $line ): void`.
	 */
	public function __construct( Job_Gate $gate, Price_Intents $intents, Price_Scheduler $scheduler, callable $log_error ) {
		$this->gate      = $gate;
		$this->intents   = $intents;
		$this->scheduler = $scheduler;
		$this->log_error = $log_error;
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->recompute_hook(), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id    Account id.
	 * @param mixed $after_link_id Last link id already re-worked.
	 * @param mixed $attempt       Failed attempts of this page so far.
	 * @return void
	 */
	public function run( $account_id = 0, $after_link_id = 0, $attempt = 0 ): void {
		$account_id    = (int) $account_id;
		$after_link_id = (int) $after_link_id;
		$attempt       = (int) $attempt;

		try {
			list( $account, $wait ) = $this->gate->check( $account_id );

			if ( null === $account ) {
				return;
			}

			if ( $wait > 0 ) {
				$this->scheduler->recompute_from( $account_id, $after_link_id, $wait, $attempt );

				return;
			}

			$next = $this->intents->recompute_page( $account, $after_link_id, Price_Scheduler::RECOMPUTE_PAGE );

			if ( $next > 0 ) {
				$this->scheduler->recompute_from( $account_id, $next );
			}
		} catch ( \Throwable $e ) {
			$this->retry( $account_id, $after_link_id, $attempt, $e );
		}
	}

	/**
	 * Schedules the same page again, or gives up after the last attempt.
	 *
	 * @param int        $account_id    Account id.
	 * @param int        $after_link_id Cursor of the failed page.
	 * @param int        $attempt       Failed attempts before this one.
	 * @param \Throwable $error         What failed.
	 * @return void
	 */
	private function retry( int $account_id, int $after_link_id, int $attempt, \Throwable $error ): void {
		$this->log( 'recompute_prices: account ' . $account_id . ' after link ' . $after_link_id . ' (attempt ' . ( $attempt + 1 ) . '): ' . get_class( $error ) . ': ' . $error->getMessage() );

		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			$this->log( 'recompute_prices: account ' . $account_id . ' gave up after link ' . $after_link_id . '; save the price rule again to restart it.' );

			return;
		}

		try {
			$this->scheduler->recompute_from( $account_id, $after_link_id, Backoff::seconds_after( $attempt + 1 ), $attempt + 1 );
		} catch ( \Throwable $e ) {
			$this->log( 'recompute_prices: the retry was not scheduled: ' . $e->getMessage() );
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
