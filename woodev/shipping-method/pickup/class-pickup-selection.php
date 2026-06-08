<?php
/**
 * Woodev Pickup Selection
 *
 * Persists the pickup point a shopper chose during checkout in the WooCommerce
 * session — and ONLY the session. This is the checkout-state half of the chosen
 * point; the order-meta half (writing the point onto the placed order under the
 * plugin's order-meta prefix) is owned by the Phase-3 order handler, a distinct
 * installed-site contract that does not share this namespace.
 *
 * Platform-neutral rule (spec §3.2): the session key is SUPPLIED BY THE PLUGIN —
 * the framework hardcodes no contract string. A carrier passes its own installed
 * session key; this class just stores the chosen {@see Pickup_Point} under it and
 * rebuilds it on read.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.1.v.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Selection' ) ) :

	/**
	 * Session-only store for the checkout's chosen pickup point.
	 *
	 * Round-trips a {@see Pickup_Point} through WooCommerce session storage via
	 * {@see Pickup_Point::to_array()} / {@see Pickup_Point::from_array()} under the
	 * plugin-supplied session key. No order meta, no post meta, no order object is
	 * involved here.
	 *
	 * @since 1.5.0
	 */
	class Pickup_Selection {

		/** @var string plugin-supplied WC session key under which the point is stored */
		private string $session_key;

		/** @var \WC_Session|null injected session handler; defaults to `WC()->session` */
		private ?\WC_Session $session;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param string           $session_key plugin-supplied WC session key (no framework default — the carrier owns this contract string)
		 * @param \WC_Session|null $session     session handler; defaults to `WC()->session` when null
		 */
		public function __construct( string $session_key, ?\WC_Session $session = null ) {
			$this->session_key = $session_key;
			$this->session     = $session;
		}

		/**
		 * Stores the chosen pickup point in the WC session.
		 *
		 * Persists {@see Pickup_Point::to_array()} under the plugin-supplied key.
		 * A no-op when no session is available (e.g. outside a customer request).
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point $point chosen pickup point
		 *
		 * @return void
		 */
		public function set( Pickup_Point $point ): void {
			$session = $this->get_session();

			if ( null === $session ) {
				return;
			}

			$session->set( $this->session_key, $point->to_array() );
		}

		/**
		 * Gets the chosen pickup point from the WC session.
		 *
		 * Rebuilds the point with {@see Pickup_Point::from_array()}. Returns null
		 * when nothing is stored or no session is available.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point|null the stored point, or null when none is selected
		 */
		public function get(): ?Pickup_Point {
			$session = $this->get_session();

			if ( null === $session ) {
				return null;
			}

			$data = $session->get( $this->session_key );

			if ( ! is_array( $data ) ) {
				return null;
			}

			return Pickup_Point::from_array( $data );
		}

		/**
		 * Clears the chosen pickup point from the WC session.
		 *
		 * A no-op when no session is available.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function clear(): void {
			$session = $this->get_session();

			if ( null === $session ) {
				return;
			}

			$session->set( $this->session_key, null );
		}

		/**
		 * Resolves the session handler to use.
		 *
		 * Prefers the injected handler (used by tests); otherwise falls back to the
		 * live `WC()->session`, which may be absent outside a customer request.
		 *
		 * @since 1.5.0
		 *
		 * @return \WC_Session|null
		 */
		private function get_session(): ?\WC_Session {
			if ( null !== $this->session ) {
				return $this->session;
			}

			if ( function_exists( 'WC' ) && isset( WC()->session ) ) {
				return WC()->session;
			}

			return null;
		}
	}

endif;
