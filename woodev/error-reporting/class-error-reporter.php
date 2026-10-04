<?php
/**
 * Framework error reporter — PHP side.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Error_Reporter' ) ) :

	/**
	 * Sends anonymised reports of OUR plugins' fatal errors and uncaught exceptions to a
	 * Sentry-compatible receiver (self-hosted GlitchTip), when — and only when — the merchant
	 * consented and a receiver DSN is configured.
	 *
	 * Installed exactly once per request by the winning framework copy (see
	 * {@see \Woodev\Framework\Framework_Resolver::load_plugins()}); the static guard makes a
	 * second call a no-op. Every public entry point swallows its own failures: reporting must
	 * never break the page it reports on.
	 *
	 * @since 2.0.2
	 */
	final class Error_Reporter {

		/** Fatal error types the shutdown handler reports. Warnings and notices are never touched. */
		const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR;

		/** Not secret, only stable: it makes the site hash differ from a bare hash of the address. */
		const SITE_SALT = 'woodev-error-reporter:v1:';

		/** @var bool Install-once guard. */
		private static bool $installed = false;

		/** @var bool Re-entrancy guard. */
		private static bool $busy = false;

		/** @var bool Whether the exception handler already saw an exception this request. */
		private static bool $exception_seen = false;

		/** @var callable|null Exception handler that was active before ours. */
		private static $previous_exception_handler = null;

		/** @var Plugin_Scope|null */
		private static ?Plugin_Scope $scope = null;

		/**
		 * Installs the reporter. Safe to call repeatedly; only the first call does anything.
		 *
		 * Always registers the consent REST route (so the checkbox can be turned on). The error
		 * handlers are hooked only when a receiver is configured AND the merchant consented — a
		 * site that did not opt in keeps PHP's own handlers untouched.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $registered_plugins The resolver's registered plugins.
		 * @return bool True when the error handlers were hooked by this call.
		 */
		public static function install( array $registered_plugins ): bool {
			if ( self::$installed ) {
				return false;
			}

			self::$installed = true;

			try {
				self::$scope = Plugin_Scope::from_registered_plugins( $registered_plugins );

				\Woodev_REST_V1_Registrar::register_controller( new Consent_Rest_Controller() );

				if ( ! Consent::is_active() ) {
					return false;
				}

				self::$previous_exception_handler = set_exception_handler( [ self::class, 'handle_exception' ] );
				register_shutdown_function( [ self::class, 'handle_shutdown' ] );

				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Manual capture for a critical path — a caught Throwable worth knowing about.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable  $e         The throwable.
		 * @param string|null $plugin_id Id of the plugin reporting it; attributes the event even
		 *                               when its stack never touches the plugin's directory.
		 * @return bool True when a report was handed to the transport.
		 */
		public static function capture( \Throwable $e, ?string $plugin_id = null ): bool {
			return self::report( $e, $plugin_id, true );
		}

		/**
		 * Uncaught-exception handler: report, then hand over to whoever was there before us.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $e The uncaught throwable.
		 * @return void
		 * @throws \Throwable Re-thrown when no previous handler exists, so PHP's own fatal still happens.
		 */
		public static function handle_exception( \Throwable $e ): void {
			self::$exception_seen = true;

			self::report( $e, null, false );

			$previous = self::$previous_exception_handler;

			if ( null !== $previous && is_callable( $previous ) ) {
				call_user_func( $previous, $e );

				return;
			}

			// Nothing before us: a registered handler suppresses PHP's «Uncaught …» fatal, so give
			// the default behaviour back and throw again.
			restore_exception_handler();

			throw $e;
		}

		/**
		 * Shutdown handler: report the request's last error when it was a fatal one.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function handle_shutdown(): void {
			if ( self::$busy || ! self::$installed ) {
				return;
			}

			$error = error_get_last();

			if ( ! is_array( $error ) || ! self::is_fatal( (int) $error['type'] ) ) {
				return;
			}

			// An uncaught exception that our own handler re-threw comes back as an «Uncaught …»
			// fatal — it was reported once already.
			if ( self::$exception_seen && 0 === strpos( (string) $error['message'], 'Uncaught ' ) ) {
				return;
			}

			self::$busy = true;

			try {
				if ( null !== self::$scope && Consent::is_active() ) {
					self::submit( self::event_builder()->from_fatal( $error ) );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- by design: never break the page.
				unset( $e );
			}

			self::$busy = false;
		}

		/**
		 * Whether an `error_get_last()` type is one the shutdown handler reports.
		 *
		 * @since 2.0.2
		 *
		 * @param int $type E_* value.
		 * @return bool
		 */
		public static function is_fatal( int $type ): bool {
			return 0 !== ( $type & self::FATAL_TYPES );
		}

		/**
		 * Forgets the install state. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$installed                  = false;
			self::$busy                       = false;
			self::$exception_seen             = false;
			self::$previous_exception_handler = null;
			self::$scope                      = null;
		}

		/**
		 * @param \Throwable  $e         The throwable.
		 * @param string|null $plugin_id Explicit owner.
		 * @param bool        $handled   False for an uncaught exception.
		 * @return bool
		 */
		private static function report( \Throwable $e, ?string $plugin_id, bool $handled ): bool {
			if ( self::$busy || ! self::$installed || null === self::$scope ) {
				return false;
			}

			self::$busy = true;
			$sent       = false;

			try {
				if ( Consent::is_active() ) {
					$sent = self::submit( self::event_builder()->from_throwable( $e, $plugin_id, $handled ) );
				}
			} catch ( \Throwable $failure ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- by design: never break the page.
				unset( $failure );
			}

			self::$busy = false;

			return $sent;
		}

		/**
		 * Filter, rate-limit and send one event.
		 *
		 * @param array<string,mixed>|null $event Event, or null when the error was not ours.
		 * @return bool
		 */
		private static function submit( ?array $event ): bool {
			if ( null === $event ) {
				return false;
			}

			/**
			 * Filters an event just before it is sent; return false to drop it.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed>|false $event The anonymised event.
			 */
			$event = apply_filters( 'woodev_error_reporting_event', $event );
			$dsn   = Consent::get_dsn();

			if ( ! is_array( $event ) || null === $dsn ) {
				return false;
			}

			$limiter = new Rate_Limiter();

			if ( ! $limiter->allow( $limiter->signature( $event ) ) ) {
				return false;
			}

			return ( new Transport() )->send( $event, $dsn );
		}

		/**
		 * @return Event_Builder
		 */
		private static function event_builder(): Event_Builder {
			global $wp_version;

			$home = (string) home_url();

			return new Event_Builder(
				self::$scope,
				[
					'site'              => substr( hash( 'sha256', self::SITE_SALT . untrailingslashit( $home ) ), 0, 16 ),
					'framework_version' => class_exists( '\Woodev_Plugin', false ) ? \Woodev_Plugin::VERSION : '',
					'wp_version'        => (string) $wp_version,
					'wc_version'        => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
					'php_version'       => PHP_VERSION,
					'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				],
				[
					'abspath'    => defined( 'ABSPATH' ) ? ABSPATH : '',
					'plugin_dir' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
					'home_url'   => $home,
				]
			);
		}
	}

endif;
