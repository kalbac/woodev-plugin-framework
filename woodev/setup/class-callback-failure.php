<?php
/**
 * Setup wizard plugin-callback failure logging.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * One place where a failure of a plugin-supplied wizard callback (validation, on_save,
 * action, visibility, content) is logged.
 *
 * The callbacks are the plugin author's — they can throw an `\Error` (TypeError,
 * ArgumentCountError…) as readily as an `\Exception` — so callers catch `\Throwable`, log
 * here with the secrets masked, and hand the browser a generic message only (audit #6).
 *
 * @since 2.0.2
 */
final class Callback_Failure {

	/**
	 * Logs a caught Throwable (message only, secrets masked).
	 *
	 * @since 2.0.2
	 *
	 * @param string     $what what failed, e.g. `on_save failed for step "connection"`.
	 * @param \Throwable $e    the caught failure.
	 * @return void
	 */
	public static function log( string $what, \Throwable $e ): void {
		error_log( sprintf( '[woodev] setup wizard %s: %s', $what, \Woodev_API_Base::redact_secret_log_text( $e->getMessage() ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a plugin callback failure.
	}
}
