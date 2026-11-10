<?php
/**
 * Exception of the marketplace stores.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A write the database refused, or a job that could not be queued.
 *
 * The message names what failed and the database's own text; it reaches the plugin log and the support report, never the merchant.
 *
 * @since 2.0.29
 */
final class Persistence_Exception extends \RuntimeException {
	/**
	 * A write or read the database refused.
	 *
	 * @since 2.0.29
	 *
	 * @param string $what  What was being written, e.g. `a label task`.
	 * @param string $error `$wpdb->last_error`; may be empty.
	 * @return self
	 */
	public static function database( string $what, string $error = '' ): self {
		return new self( 'Could not write ' . $what . ( '' === $error ? '.' : ': ' . $error ) );
	}

	/**
	 * A job that could not be queued.
	 *
	 * @since 2.0.29
	 *
	 * @param string $what What was being queued.
	 * @return self
	 */
	public static function queue( string $what ): self {
		return new self( 'Could not queue ' . $what . '.' );
	}
}
