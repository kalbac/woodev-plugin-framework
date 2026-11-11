<?php
/**
 * Sink for failed marketplace HTTP requests.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Records a marketplace API failure for the diagnostics error ring and the WooCommerce error log.
 *
 * @since 2.1.2
 */
interface Failure_Logger {
	/**
	 * Records one failed marketplace request.
	 *
	 * @since 2.1.2
	 *
	 * @param int    $account_id Account id (0 when not tied to an account).
	 * @param string $endpoint   Request path (for example, `/v1/product/info`).
	 * @param int    $status     HTTP status code (0 on transport failure).
	 * @param string $code       Marketplace error code, or '' when absent.
	 * @param string $message    Error message.
	 * @param string $trace_id   Optional trace/request id from response headers.
	 * @return void
	 */
	public function record_failure( int $account_id, string $endpoint, int $status, string $code, string $message, string $trace_id = '' ): void;
}
