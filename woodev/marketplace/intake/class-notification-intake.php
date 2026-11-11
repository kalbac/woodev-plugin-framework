<?php
/**
 * Notification request intake.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;

defined( 'ABSPATH' ) || exit;

/**
 * What happens between «the marketplace POSTs a notification» and «we answer 200». Request time only records and schedules: two
 * database writes (the event, the Action Scheduler action) and **no call to the marketplace**, so the handler is a few
 * milliseconds plus the WordPress bootstrap and stays inside the tightest answer budget a marketplace sets.
 *
 * The class knows nothing about HTTP: the REST route and a plugin's legacy route hand it the body and the server variables and
 * print the {@see Intake_Response} it returns. What the body means ({@see Inbound_Decoder}) and what an error answer looks like
 * ({@see Push_Protocol}) are the marketplace's.
 *
 * @since 2.0.30
 */
final class Notification_Intake {
	private const UNAUTHORISED  = 'Unauthorized request';
	private const STORE_FAILED  = 'The notification could not be stored, please retry';
	private const TOO_MANY      = 'Too many requests';
	private const SCHEDULE_FAIL = 'Could not queue the notification drain: ';
	private const ASYNC_MISSED  = 'The immediate drain was not queued; the five-minute sweep will pick the event up.';

	/**
	 * Decoder.
	 *
	 * @var Inbound_Decoder
	 */
	private $decoder;

	/**
	 * The marketplace's endpoint rules.
	 *
	 * @var Push_Protocol
	 */
	private $protocol;

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
	 * Drain scheduler.
	 *
	 * @var Drain_Scheduler
	 */
	private $scheduler;

	/**
	 * Per-token rate limit.
	 *
	 * @var Push_Rate_Limiter
	 */
	private $limiter;

	/**
	 * Receives a line of text for the merchant's log, or null.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * Creates the intake.
	 *
	 * @since 2.0.30
	 *
	 * @param Inbound_Decoder             $decoder   Decoder.
	 * @param Push_Protocol               $protocol  The marketplace's endpoint rules.
	 * @param Event_Ledger                $ledger    Event ledger.
	 * @param Notification_Account_Reader $accounts  Accounts.
	 * @param Drain_Scheduler             $scheduler Drain scheduler.
	 * @param Push_Rate_Limiter           $limiter   Per-token rate limit.
	 * @param callable|null               $log       Receives a line of text for the merchant's log.
	 */
	public function __construct( Inbound_Decoder $decoder, Push_Protocol $protocol, Event_Ledger $ledger, Notification_Account_Reader $accounts, Drain_Scheduler $scheduler, Push_Rate_Limiter $limiter, ?callable $log = null ) {
		$this->decoder   = $decoder;
		$this->protocol  = $protocol;
		$this->ledger    = $ledger;
		$this->accounts  = $accounts;
		$this->scheduler = $scheduler;
		$this->limiter   = $limiter;
		$this->log       = $log;
	}

	/**
	 * Handles a notification on the URL with a token, `…/notifications/{account_id}/{token}`.
	 *
	 * The token is the credential. An address outside the marketplace's ranges is recorded on the event by the decoder and does
	 * not refuse the request: a 4xx would count against the URL in the marketplace's health check, and the token already matched.
	 *
	 * @since 2.0.30
	 *
	 * @param int                 $account_id Account id from the URL.
	 * @param string              $token      Token from the URL.
	 * @param string              $raw_body   Raw request body.
	 * @param array<string,mixed> $server     Request server variables.
	 * @return Intake_Response
	 */
	public function handle_token( int $account_id, string $token, string $raw_body, array $server ): Intake_Response {
		if ( $this->limiter->exceeded( $token ) ) {
			return $this->refusal( 429, Intake_Response::REASON_RATE_LIMITED, self::TOO_MANY, [ 'Retry-After' => (string) Intake_Response::RETRY_AFTER_SECOND ] );
		}

		try {
			$account = $this->accounts->find_by_token( $account_id, $token );
		} catch ( Account_Read_Exception $e ) {
			return $this->retryable( $e );
		}

		return $this->ingest( $account, $raw_body, $this->protocol->client_ip( $server ) );
	}

	/**
	 * Handles a notification on a URL that carries no token (the address a plugin registered before tokens existed and still
	 * serves through its own route).
	 *
	 * Nothing proves who sent it but the address, so the marketplace's allow-list is enforced here, with a 403. The account is
	 * the one the body names; the caller extracts that name, because only the plugin knows where the body keeps it.
	 *
	 * @since 2.0.30
	 *
	 * @param string              $owner    The cabinet identifier or the seller id the body names, '' when it names none.
	 * @param string              $raw_body Raw request body.
	 * @param array<string,mixed> $server   Request server variables.
	 * @return Intake_Response
	 */
	public function handle_tokenless( string $owner, string $raw_body, array $server ): Intake_Response {
		$ip = $this->protocol->client_ip( $server );

		if ( ! $this->protocol->is_allowed( $ip ) ) {
			return $this->refusal( 403, Intake_Response::REASON_UNAUTHORISED, self::UNAUTHORISED );
		}

		try {
			$account = $this->accounts->find_by_owner( $owner );
		} catch ( Account_Read_Exception $e ) {
			return $this->retryable( $e );
		}

		return $this->ingest( $account, $raw_body, $ip );
	}

	/**
	 * Decodes, stores and schedules one notification, then answers.
	 *
	 * @param Notification_Account|null $account  Account the endpoint resolved, if any.
	 * @param string                    $raw_body Raw request body.
	 * @param string                    $ip       Sender address.
	 * @return Intake_Response
	 */
	private function ingest( ?Notification_Account $account, string $raw_body, string $ip ): Intake_Response {
		$event = $this->decoder->decode( $raw_body, null === $account ? null : $account->id(), $ip );

		if ( null === $event ) {
			return Intake_Response::ok( $this->decoder->success_body() );
		}

		try {
			$this->ledger->record( $event );
		} catch ( \RuntimeException $e ) {
			return $this->retryable( $e );
		}

		// A duplicate schedules too: the marketplace's retry after a 500 from a failed enqueue lands here, and is the second chance.
		if ( null !== $account && '' === $event->ignore_reason() && ! $this->schedule_drain( $account->id() ) ) {
			return $this->retryable( null );
		}

		return Intake_Response::ok( $this->decoder->success_body() );
	}

	/**
	 * The error answer that makes the marketplace send the same notification again. An event is acknowledged only once it is
	 * durable and has something that will process it.
	 *
	 * @param \Throwable|null $cause What went wrong, for the merchant's log.
	 * @return Intake_Response
	 */
	private function retryable( ?\Throwable $cause ): Intake_Response {
		if ( null !== $cause ) {
			$this->log( self::STORE_FAILED . ': ' . $cause->getMessage() );
		}

		return $this->refusal( 500, Intake_Response::REASON_NOT_STORED, self::STORE_FAILED );
	}

	/**
	 * Builds an error answer in the marketplace's format.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $reason  One of `Intake_Response::REASON_*`.
	 * @param string               $message Short text.
	 * @param array<string,string> $headers Extra headers.
	 * @return Intake_Response
	 */
	private function refusal( int $status, string $reason, string $message, array $headers = [] ): Intake_Response {
		return new Intake_Response( $status, $this->protocol->error_body( $reason, $message ), $headers );
	}

	/**
	 * Makes sure something will process the account's events: the recurring sweep first (the durable recovery), then an
	 * immediate drain.
	 *
	 * @param int $account_id Account id.
	 * @return bool False when not even the sweep could be established, so nothing is certain to process the event.
	 */
	private function schedule_drain( int $account_id ): bool {
		try {
			if ( ! $this->scheduler->schedule( $account_id ) ) {
				$this->log( self::ASYNC_MISSED );
			}
		} catch ( \Throwable $e ) {
			$this->log( self::SCHEDULE_FAIL . $e->getMessage() );

			return false;
		}

		return true;
	}

	/**
	 * Writes a line to the merchant's log, when there is one.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function log( string $line ): void {
		if ( null !== $this->log ) {
			call_user_func( $this->log, $line );
		}
	}
}
