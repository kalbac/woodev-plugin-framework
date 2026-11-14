<?php
/**
 * The skeleton every account-wide read job runs in.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;

defined( 'ABSPATH' ) || exit;

/**
 * Gate → claim → work → classify, for a job that reads the marketplace and writes only the shop.
 *
 * 1. The job gate: a disabled or deleted account ends the job with a recorded text, a lapsed licence or a paused account waits.
 * 2. The account claim ({@see Job_Gate::claim()}); a busy account waits {@see Job_Gate::BUSY_DELAY}.
 * 3. The work, handed the account and the claim; the claim is released afterwards, whatever happened.
 * 4. Every failure becomes a retry or a recorded text, by the adapter's {@see Error_Classifier}: a refused credential pauses the
 *    account, a wait is not an attempt, a server error retries on the {@see Backoff} ladder up to {@see self::MAX_ATTEMPTS}; any
 *    other refusal is given up with its text. Nothing is thrown out of the job.
 *
 * What differs between the jobs is passed in: the request path the error ring names next to a failed run, the ring's failure code
 * and the exceptions the job's own work throws to mean «give up, with this text» instead of going through the classifier.
 *
 * @since 2.1.6
 */
final class Account_Job {
	public const MAX_ATTEMPTS = 3;

	private const DEFAULT_WAIT = 60;

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

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
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * The request path the ring names.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * The ring's failure code.
	 *
	 * @var string
	 */
	private $failure_code;

	/**
	 * Exceptions the work throws to give the run up with their message.
	 *
	 * @var string[]
	 */
	private $give_up_on;

	/**
	 * Creates the skeleton of one job.
	 *
	 * @since 2.1.6
	 *
	 * @param Job_Gate         $gate         Job gate.
	 * @param Error_Classifier $classifier   The adapter's error classifier.
	 * @param Api_Error_Ring   $ring         Error ring.
	 * @param callable         $log_error    `function ( string $line ): void`.
	 * @param string           $path         The request path the ring names next to a failed run.
	 * @param string           $failure_code The ring's failure code.
	 * @param string[]         $give_up_on   Exception classes the work throws to give the run up with their message.
	 */
	public function __construct( Job_Gate $gate, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, string $path, string $failure_code, array $give_up_on = [] ) {
		$this->gate         = $gate;
		$this->classifier   = $classifier;
		$this->ring         = $ring;
		$this->log_error    = $log_error;
		$this->path         = $path;
		$this->failure_code = $failure_code;
		$this->give_up_on   = $give_up_on;
	}

	/**
	 * Applies the gate and the claim, runs the work and turns every failure into a retry or a recorded text.
	 *
	 * @since 2.1.6
	 *
	 * @param int      $account_id Account id.
	 * @param string   $context    Hook name, for the client's context and the error ring.
	 * @param callable $retry      `function ( int $delay, int $attempt ): bool`, schedules the job again.
	 * @param callable $give_up    `function ( string $text ): void`, records the failure for the merchant.
	 * @param int      $attempt    Failed attempts so far.
	 * @param callable $work       `function ( Account, Claim_Fence ): void`.
	 * @return void
	 */
	public function run( int $account_id, string $context, callable $retry, callable $give_up, int $attempt, callable $work ): void {
		try {
			list( $account, $wait ) = $this->gate->check( $account_id );

			if ( null === $account ) {
				$give_up( __( 'Аккаунт маркетплейса отключён или удалён.', 'woodev-plugin-framework' ) );
				return;
			}

			if ( $wait > 0 ) {
				$retry( $wait, $attempt );
				return;
			}

			$fence = $this->gate->claim( $account_id );

			if ( null === $fence ) {
				$retry( Job_Gate::BUSY_DELAY, $attempt );
				return;
			}

			try {
				$work( $account, $fence );
			} finally {
				$this->gate->release( $account_id, $fence );
			}
		} catch ( Claim_Lost $exception ) {
			$this->report( $account_id, $context, 'The account claim was taken over during the run.' );
			$retry( Job_Gate::BUSY_DELAY, $attempt );
		} catch ( \Throwable $exception ) {
			$this->after_throwable( $account_id, $context, $exception, $attempt, $retry, $give_up );
		}
	}

	/**
	 * Extends the account claim, or stops the run.
	 *
	 * @since 2.1.6
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return void
	 * @throws Claim_Lost When another run holds it now.
	 */
	public function hold( int $account_id, Claim_Fence $fence ): void {
		if ( ! $this->gate->still_holds( $account_id, $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over.' );
		}
	}

	/**
	 * Decides what a failed run turns into: the job's own give-up, the adapter's verdict, or an unexpected failure.
	 *
	 * @param int        $account_id Account id.
	 * @param string     $context    Hook name.
	 * @param \Throwable $exception  The failure.
	 * @param int        $attempt    Failed attempts so far.
	 * @param callable   $retry      Schedules the job again.
	 * @param callable   $give_up    Records the failure.
	 * @return void
	 */
	private function after_throwable( int $account_id, string $context, \Throwable $exception, int $attempt, callable $retry, callable $give_up ): void {
		if ( $this->is_give_up( $exception ) ) {
			$this->report( $account_id, $context, $exception->getMessage() );
			$give_up( $exception->getMessage() );
			return;
		}

		$error = $this->classifier->classify( $exception );

		if ( null !== $error ) {
			$this->after_failure( $account_id, $context, $exception, $error, $attempt, $retry, $give_up );
			return;
		}

		$this->report( $account_id, $context, get_class( $exception ) . ': ' . $exception->getMessage() );
		$give_up( sprintf( /* translators: %s: reason. */ __( 'Не удалось выполнить задачу: %s', 'woodev-plugin-framework' ), $exception->getMessage() ) );
	}

	/**
	 * Whether the work threw this to give the run up.
	 *
	 * @param \Throwable $exception The failure.
	 * @return bool
	 */
	private function is_give_up( \Throwable $exception ): bool {
		foreach ( $this->give_up_on as $class ) {
			if ( $exception instanceof $class ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Applies the adapter's verdict on a refused request.
	 *
	 * @param int          $account_id Account id.
	 * @param string       $context    Hook name.
	 * @param \Throwable   $thrown     The refusal as thrown.
	 * @param Remote_Error $error      What the classifier made of it.
	 * @param int          $attempt    Failed attempts so far.
	 * @param callable     $retry      Schedules the job again.
	 * @param callable     $give_up    Records the failure.
	 * @return void
	 */
	private function after_failure( int $account_id, string $context, \Throwable $thrown, Remote_Error $error, int $attempt, callable $retry, callable $give_up ): void {
		$this->report( $account_id, $context, $error->describe() );

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$account = $this->gate->check( $account_id )[0];

			if ( null !== $account ) {
				$this->gate->pause()->apply( $account, $error );
			}

			$give_up( $thrown->getMessage() );
			return;
		}

		if ( $error->is( Remote_Error::THROTTLE ) ) {
			// A wait is not a failed attempt.
			$retry( max( 1, (int) ( $error->retry_after() ?? self::DEFAULT_WAIT ) ), $attempt );
			return;
		}

		if ( $error->is( Remote_Error::RETRY ) && $attempt + 1 < self::MAX_ATTEMPTS ) {
			$retry( $error->retry_after() ?? Backoff::seconds_after( $attempt + 1 ), $attempt + 1 );
			return;
		}

		$give_up( $thrown->getMessage() );
	}

	/**
	 * Writes a failure to the error ring and the error log.
	 *
	 * @param int    $account_id Account id.
	 * @param string $context    Hook name.
	 * @param string $message    Failure text.
	 * @return void
	 */
	private function report( int $account_id, string $context, string $message ): void {
		try {
			$this->ring->record( $account_id > 0 ? $account_id : null, $this->path, 0, $this->failure_code, $message, '', $context );
		} catch ( \Throwable $e ) {
			unset( $e ); // The log line below still carries the failure.
		}

		call_user_func( $this->log_error, $context . ': account ' . $account_id . ': ' . $message );
	}
}
