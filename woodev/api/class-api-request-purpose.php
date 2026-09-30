<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_Request_Purpose' ) ) :

	/**
	 * What the API call that is being made now is FOR — the one thing the HTTP timeout depends on (#954).
	 *
	 * A checkout waits for a rate answer, so a rate call must give up in seconds; an order export
	 * is worth waiting for longer; a payment or licensing call keeps the historical minute. The
	 * purpose is an AMBIENT scope rather than a parameter of every API method: the framework calls a
	 * carrier through seams it does not own (a plugin's `rate_package()`, a point source, a
	 * shipment handler's injected API), so it cannot pass a purpose in — it can only mark the
	 * stretch of code it is running. {@see self::run()} sets the purpose for exactly the duration of
	 * a callback and restores the previous one in a `finally`, so an exception or a nested scope
	 * can never leave a short timeout behind for the next, unrelated call.
	 *
	 * {@see Woodev_API_Base::get_request_timeout()} turns the current purpose into seconds.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_API_Request_Purpose {

		/** @var string a rate calculation a customer is waiting for at checkout */
		public const RATES = 'rates';

		/** @var string a reference-data load (pickup points, directories) */
		public const REFERENCE = 'reference';

		/** @var string a shipment export, cancel or update */
		public const EXPORT = 'export';

		/** @var string anything the framework did not classify — every non-shipping API */
		public const DEFAULT_PURPOSE = 'default';

		/** @var int the historical timeout of every API call, in seconds */
		public const DEFAULT_TIMEOUT = 60;

		/** @var string the purpose of the scope currently running */
		private static string $current = self::DEFAULT_PURPOSE;

		/**
		 * The purpose of the call being made now.
		 *
		 * @since 2.0.2
		 *
		 * @return string one of this class's purposes
		 */
		public static function current(): string {
			return self::$current;
		}

		/**
		 * Runs a callback with a purpose in force, restoring the previous purpose afterwards.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $purpose  one of this class's purposes
		 * @param callable $callback the work that calls the API
		 * @return mixed whatever the callback returns
		 * @throws \Throwable whatever the callback throws, after the purpose is restored
		 */
		public static function run( string $purpose, callable $callback ) {

			$previous      = self::$current;
			self::$current = $purpose;

			try {
				return $callback();
			} finally {
				self::$current = $previous;
			}
		}

		/**
		 * The framework's timeout for a purpose, in seconds.
		 *
		 * @since 2.0.2
		 *
		 * @param string $purpose one of this class's purposes
		 * @return int rates 8, reference 20, export 30, anything else 60
		 */
		public static function default_timeout( string $purpose ): int {

			switch ( $purpose ) {
				case self::RATES:
					return 8;
				case self::REFERENCE:
					return 20;
				case self::EXPORT:
					return 30;
				default:
					return self::DEFAULT_TIMEOUT;
			}
		}
	}

endif;
