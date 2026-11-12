<?php
/**
 * An AJAX answer caught instead of ending the request.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Brain\Monkey\Functions;

/**
 * `wp_send_json_*()` ends the request; the tests make it throw this instead, so the code after the call does not run, as in
 * production, and the answer can be read.
 */
final class Json_Answer extends \RuntimeException {
	/**
	 * Whether it was `wp_send_json_success()`.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * Payload.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * HTTP status, null when none was given.
	 *
	 * @var int|null
	 */
	public $status;

	/**
	 * Creates the answer.
	 *
	 * @param bool     $success Whether it was a success.
	 * @param mixed    $data    Payload.
	 * @param int|null $status  HTTP status.
	 */
	public function __construct( bool $success, $data, ?int $status ) {
		parent::__construct( $success ? 'success' : 'error' );

		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}

	/**
	 * Makes `wp_send_json_success()` and `wp_send_json_error()` throw an answer.
	 *
	 * @return void
	 */
	public static function install(): void {
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = null ) {
				throw new self( true, $data, $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new self( false, $data, $status );
			}
		);
	}
}
