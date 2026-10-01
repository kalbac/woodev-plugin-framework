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

		/**
		 * The CHECKOUT budget: a rate calculation, or any other call a customer is waiting for in
		 * the middle of a checkout request ({@see self::run_at_checkout()}, #1017).
		 *
		 * @var string
		 */
		public const RATES = 'rates';

		/** @var string a reference-data load outside a checkout request (pickup points, directories) */
		public const REFERENCE = 'reference';

		/** @var string a shipment export, cancel or update */
		public const EXPORT = 'export';

		/** @var string anything the framework did not classify — every non-shipping API */
		public const DEFAULT_PURPOSE = 'default';

		/** @var int the historical timeout of every API call, in seconds */
		public const DEFAULT_TIMEOUT = 60;

		/** @var string[] the purposes of the scopes currently running, outermost first */
		private static array $scopes = [];

		/**
		 * The purpose of the call being made now: the innermost scope's, or `default` outside any.
		 *
		 * @since 2.0.2
		 *
		 * @return string one of this class's purposes
		 */
		public static function current(): string {
			return [] === self::$scopes ? self::DEFAULT_PURPOSE : self::$scopes[ count( self::$scopes ) - 1 ];
		}

		/**
		 * The purposes of every scope running now, outermost first — `default` alone outside any.
		 *
		 * A nested scope never lengthens the wait of the one around it, so the timeout of a call is
		 * the SHORTEST of these ({@see Woodev_API_Base::get_request_timeout()}): a reference lookup
		 * a carrier makes while a customer waits for its rate stays at the rate timeout.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] one or more of this class's purposes
		 */
		public static function active(): array {
			return [] === self::$scopes ? [ self::DEFAULT_PURPOSE ] : self::$scopes;
		}

		/**
		 * Runs a callback with a purpose in force, restoring the previous purpose afterwards.
		 *
		 * Scopes nest, and an inner scope can only SHORTEN the wait, never lengthen it: the effective
		 * timeout is the smallest of the running scopes' ({@see self::active()}).
		 *
		 * @since 2.0.2
		 *
		 * @param string   $purpose  one of this class's purposes
		 * @param callable $callback the work that calls the API
		 * @return mixed whatever the callback returns
		 * @throws \Throwable whatever the callback throws, after the purpose is restored
		 */
		public static function run( string $purpose, callable $callback ) {

			$depth          = count( self::$scopes );
			self::$scopes[] = $purpose;

			try {
				return $callback();
			} finally {
				self::$scopes = array_slice( self::$scopes, 0, $depth );
			}
		}

		/**
		 * Runs a callback under the checkout budget: a customer is waiting for it inside a checkout
		 * request, exactly as for a rate answer (#1017).
		 *
		 * The budget is the `rates` one ({@see self::RATES}), so a call that would otherwise get the
		 * generic minute — or the reference 20 s — gives up in the same seconds a rate call does.
		 * The caller knows it is on the checkout path; the framework does not sniff the request
		 * (classic AJAX, Store API and the plugin's own REST routes would each need their own test,
		 * and one of them would be missed). Admin callers simply do not use this.
		 *
		 * @since 2.0.2
		 *
		 * @param callable $callback the work that calls the API
		 * @return mixed whatever the callback returns
		 * @throws \Throwable whatever the callback throws, after the purpose is restored
		 */
		public static function run_at_checkout( callable $callback ) {
			return self::run( self::RATES, $callback );
		}

		/**
		 * Runs a reference-data load (pickup points, directories) with the timeout its request needs
		 * — the ONE place that decides it (#1017).
		 *
		 * Inside a checkout request the customer waits for the lookup like for a rate, so it gets the
		 * checkout budget ({@see self::run_at_checkout()}); anywhere else (the admin order editor,
		 * the admin pickup routes) it keeps the `reference` timeout.
		 *
		 * @since 2.0.2
		 *
		 * @param bool     $at_checkout whether a customer is waiting for this call in a checkout request
		 * @param callable $callback    the work that calls the API
		 * @return mixed whatever the callback returns
		 * @throws \Throwable whatever the callback throws, after the purpose is restored
		 */
		public static function run_reference( bool $at_checkout, callable $callback ) {
			return $at_checkout ? self::run_at_checkout( $callback ) : self::run( self::REFERENCE, $callback );
		}

		/**
		 * The framework's timeout for a purpose, in seconds.
		 *
		 * @since 2.0.2
		 *
		 * @param string $purpose one of this class's purposes
		 * @return int rates (the checkout budget) 8, reference 20, export 30, anything else 60
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
