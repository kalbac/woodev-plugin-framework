<?php
/**
 * Failure logger backed by the marketplace `api_errors` ring table and WooCommerce's error log.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;

defined( 'ABSPATH' ) || exit;

/**
 * Implements {@see Failure_Logger} over {@see Api_Error_Ring} and an optional error-log sink (`Marketplace_Plugin::log_error`).
 *
 * Every recorded path and message is scrubbed through {@see Report_Redactor} before hitting either the database ring
 * or the log file, so a failed call never persists a raw header or webhook token.
 *
 * @since 2.1.2
 */
final class Error_Log implements Failure_Logger {
	/**
	 * Table-backed ring buffer.
	 *
	 * @var Api_Error_Ring
	 */
	private $ring;

	/**
	 * Error-log callback (`function ( string $message ): void`), or null.
	 *
	 * @var callable|null
	 */
	private $log_error;

	/**
	 * Adapter-declared secret field names (`Credential_Fields::secrets()`).
	 *
	 * @var string[]
	 */
	private $secret_field_names;

	/**
	 * Creates the failure logger.
	 *
	 * @since 2.1.2
	 *
	 * @param Api_Error_Ring $ring               Table-backed ring buffer.
	 * @param callable|null  $log_error          Optional `function ( string $message ): void` sink.
	 * @param string[]       $secret_field_names Optional adapter secret field names for redaction.
	 */
	public function __construct( Api_Error_Ring $ring, ?callable $log_error = null, array $secret_field_names = [] ) {
		$this->ring               = $ring;
		$this->log_error          = $log_error;
		$this->secret_field_names = $secret_field_names;
	}

	/**
	 * Records a failed marketplace HTTP call in the ring and forwards a redacted summary to the error log.
	 *
	 * @since 2.1.2
	 *
	 * @param int    $account_id Account id (0 when not tied to an account).
	 * @param string $endpoint   Request path.
	 * @param int    $status     HTTP status code (0 on transport failure).
	 * @param string $code       Marketplace error code, or '' when absent.
	 * @param string $message    Error message.
	 * @param string $trace_id   Optional trace/request id.
	 * @return void
	 */
	public function record_failure( int $account_id, string $endpoint, int $status, string $code, string $message, string $trace_id = '' ): void {
		$safe_endpoint = Report_Redactor::redact_text( $endpoint, $this->secret_field_names );
		$safe_code     = Report_Redactor::redact_text( $code, $this->secret_field_names );
		$safe_message  = Report_Redactor::redact_text( $message, $this->secret_field_names );
		$safe_trace    = Report_Redactor::redact_text( $trace_id, $this->secret_field_names );

		$this->ring->record( $account_id, $safe_endpoint, $status, $safe_code, $safe_message, $safe_trace );

		if ( null !== $this->log_error ) {
			call_user_func(
				$this->log_error,
				sprintf(
					'API failure [account=%d status=%d code=%s path=%s]: %s',
					$account_id,
					$status,
					'' !== $safe_code ? $safe_code : '-',
					$safe_endpoint,
					$safe_message
				)
			);
		}
	}
}
