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
	 * Collects anonymised reports of OUR plugins' fatal errors and uncaught exceptions for a
	 * Sentry-compatible receiver (self-hosted GlitchTip), when — and only when — the merchant
	 * consented and a receiver DSN is configured.
	 *
	 * The failing request does the cheapest possible thing: build the event and put it in
	 * {@see Event_Queue}. Nothing here touches the network; {@see Dispatcher} sends from WP-Cron.
	 *
	 * Installed exactly once per request by the winning framework copy, before any plugin code
	 * runs (see {@see \Woodev\Framework\Framework_Resolver::load_plugins()}); the static guard makes
	 * a second call a no-op. Every public entry point swallows its own failures: reporting must
	 * never break the page it reports on.
	 *
	 * Known limits: a single-file plugin (its file sits directly in the plugins directory) cannot
	 * be told apart from its neighbours and is not part of the scope; an «Uncaught …» fatal raised
	 * while a LATER exception handler replaced ours is still caught, but only through the shutdown
	 * handler, from the engine's own message.
	 *
	 * @since 2.0.2
	 */
	final class Error_Reporter {

		/** Fatal error types the shutdown handler reports. Warnings and notices are never touched. */
		const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR;

		/** Not secret, only stable: it makes the site hash differ from a bare hash of the address. */
		const SITE_SALT = 'woodev-error-reporter:v1:';

		/** Most events one request may queue. */
		const MAX_PER_REQUEST = 5;

		/** Bytes held back at install so the shutdown handler still has room after an out-of-memory fatal. */
		const MEMORY_RESERVE = 262144;

		/** @var string|null Emergency memory buffer: freed first thing in the shutdown handler. */
		private static ?string $reserve = null;

		/** @var bool Install-once guard. */
		private static bool $installed = false;

		/** @var bool Re-entrancy guard for report(). */
		private static bool $busy = false;

		/** @var bool Re-entrancy guard for the exception handler. */
		private static bool $in_handler = false;

		/** @var array<string,bool> Throw sites (`file:line`) of exceptions our handler already saw. */
		private static array $seen_sites = [];

		/** @var int Events queued by this request. */
		private static int $queued = 0;

		/** @var callable|null Exception handler that was active before ours. */
		private static $previous_exception_handler = null;

		/** @var Plugin_Scope|null */
		private static ?Plugin_Scope $scope = null;

		/**
		 * Installs the reporter. Safe to call repeatedly; only the first call does anything.
		 *
		 * Always registers the consent REST route (so the checkbox can be turned on) and the cron
		 * callback that sends the queue. The error handlers are hooked only when a receiver is
		 * configured AND the merchant consented — a site that did not opt in keeps PHP's own
		 * handlers untouched.
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
				add_action( Dispatcher::HOOK, [ Dispatcher::class, 'run' ] );

				if ( ! Consent::is_active() ) {
					return false;
				}

				// Built with `str_repeat`: a literal would be interned, and freeing it would release nothing.
				self::$reserve                    = str_repeat( ' ', self::MEMORY_RESERVE );
				self::$previous_exception_handler = set_exception_handler( [ self::class, 'handle_exception' ] );
				register_shutdown_function( [ self::class, 'handle_shutdown' ] );

				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Whether {@see self::install()} already ran this request.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_installed(): bool {
			return self::$installed;
		}

		/**
		 * Manual capture for a critical path — a caught Throwable worth knowing about.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable  $e         The throwable. Its class, code and trace are reported; its message is not.
		 * @param string|null $plugin_id Id of the plugin reporting it; attributes the event even
		 *                               when its stack never touches the plugin's directory.
		 * @return bool True when a report was queued for sending.
		 */
		public static function capture( \Throwable $e, ?string $plugin_id = null ): bool {
			return self::report( $e, $plugin_id, true );
		}

		/**
		 * Uncaught-exception handler: queue a report, then hand over to whoever was there before us.
		 *
		 * With no previous handler the exception is simply thrown again. PHP clears the user handler
		 * while it runs one, so the new throw is not delivered to us a second time — it ends the
		 * request with PHP's own «Uncaught …» fatal and exit status 255, exactly as if we were never
		 * installed. (Restoring the previous handler first, the obvious alternative, makes PHP call
		 * us again on the rethrow: the guard below stays as a belt for runtimes that behave so.)
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
			if ( self::$in_handler ) {
				throw $e;
			}

			self::$in_handler = true;
			self::$seen_sites[ $e->getFile() . ':' . $e->getLine() ] = true;

			self::report( $e, null, false );

			$previous = self::$previous_exception_handler;

			if ( null !== $previous && is_callable( $previous ) ) {
				try {
					call_user_func( $previous, $e );
				} finally {
					self::$in_handler = false;
				}

				return;
			}

			// The flag stays set on purpose: from here the process is ending.
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
			// First, before anything allocates: after an «Allowed memory size» fatal the heap is full,
			// and building the event would die with a SECOND fatal that no catch can intercept.
			self::$reserve = null;

			if ( self::$busy || ! self::$installed ) {
				return;
			}

			$error = error_get_last();

			if ( ! is_array( $error ) || ! self::is_fatal( (int) $error['type'] ) ) {
				return;
			}

			// An uncaught exception our own handler saw and re-threw comes back as an «Uncaught …»
			// fatal at the same file:line — it was queued once already.
			if ( isset( self::$seen_sites[ ( $error['file'] ?? '' ) . ':' . ( $error['line'] ?? 0 ) ] ) && 0 === strpos( (string) $error['message'], 'Uncaught ' ) ) {
				return;
			}

			self::$busy = true;

			try {
				if ( null !== self::$scope && Consent::is_active() ) {
					self::enqueue( self::event_builder()->from_fatal( $error ) );
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
			self::$reserve                    = null;
			self::$installed                  = false;
			self::$busy                       = false;
			self::$in_handler                 = false;
			self::$seen_sites                 = [];
			self::$queued                     = 0;
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
			$queued     = false;

			try {
				if ( Consent::is_active() ) {
					$queued = self::enqueue( self::event_builder()->from_throwable( $e, $plugin_id, $handled ) );
				}
			} catch ( \Throwable $failure ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- by design: never break the page.
				unset( $failure );
			}

			self::$busy = false;

			return $queued;
		}

		/**
		 * Filter and queue one event, and make sure a cron run is waiting to send it.
		 *
		 * @param array<string,mixed>|null $event Event, or null when the error was not ours.
		 * @return bool
		 */
		private static function enqueue( ?array $event ): bool {
			if ( null === $event || self::$queued >= self::MAX_PER_REQUEST ) {
				return false;
			}

			/**
			 * Filters an event just before it is queued; return false to drop it.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed>|false $event The anonymised event.
			 */
			$event = apply_filters( 'woodev_error_reporting_event', $event );

			if ( ! is_array( $event ) ) {
				return false;
			}

			if ( ! Event_Queue::push( $event ) ) {
				// An identical event is already waiting; its drain may have been consumed without
				// sending (lock contention), so make sure one is scheduled.
				Dispatcher::schedule();

				return false;
			}

			++self::$queued;
			Dispatcher::schedule();

			return true;
		}

		/**
		 * @return Event_Builder
		 */
		private static function event_builder(): Event_Builder {
			global $wp_version;

			return new Event_Builder(
				self::$scope,
				[
					'site'              => substr( hash( 'sha256', self::SITE_SALT . untrailingslashit( (string) home_url() ) ), 0, 16 ),
					'framework_version' => class_exists( '\Woodev_Plugin', false ) ? \Woodev_Plugin::VERSION : '',
					'wp_version'        => (string) $wp_version,
					'wc_version'        => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
					'php_version'       => PHP_VERSION,
					'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				],
				[
					'abspath'    => defined( 'ABSPATH' ) ? ABSPATH : '',
					'plugin_dir' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
				]
			);
		}
	}

endif;
