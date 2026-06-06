<?php
/**
 * Woodev Abstract Tracking Handler
 *
 * Base class for reading a shipment's tracking history from a carrier and
 * exposing it for display. It drives the carrier seam
 * {@see \Woodev\Framework\Shipping\Shipping_API::get_tracking()} and normalizes
 * the carrier's response into a typed `Tracking_Event` list, so callers see one
 * shape regardless of the carrier. Each concrete carrier implements only the
 * carrier-specific mapping ({@see self::map_events()}) and builds events through
 * {@see self::make_event()}; the carrier's raw payload shape never leaks past
 * this seam.
 *
 * Display is wired through forward-only, plugin-namespaced action hooks
 * (`woodev_shipping_{prefix}_tracking_admin_display` /
 * `…_tracking_frontend_display`). No installed-site contract string — no method
 * id, no existing hook name, no meta key — is introduced here: the hook prefix is
 * supplied by the plugin and these hooks are new.
 *
 * The normalized event is a typed array shape rather than its own class because
 * this task's file set is a single file and the framework keeps one class per
 * file; if the event grows behavior, promote it to its own `class-tracking-event.php`
 * value object (mirroring `class-pickup-point.php`).
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.3.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Shipping_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Abstract_Tracking_Handler' ) ) :

	/**
	 * Reads and displays a shipment's tracking history from a carrier.
	 *
	 * A `Tracking_Event` is one normalized, carrier-neutral step of a shipment's
	 * tracking history. Concrete carriers build events from their own response shape
	 * in {@see self::map_events()}; everything downstream consumes this typed
	 * structure rather than the raw payload.
	 *
	 * @phpstan-type Tracking_Event array{status: string, description: string, timestamp: int, location: string}
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Tracking_Handler {

		/** @var Shipping_API carrier API seam */
		protected Shipping_API $api;

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		protected string $hook_prefix;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param Shipping_API $api         carrier API used to fetch tracking history
		 * @param string       $hook_prefix plugin-supplied token (e.g. the plugin id) that namespaces forward hooks; defaults to none
		 */
		public function __construct( Shipping_API $api, string $hook_prefix = '' ) {
			$this->api         = $api;
			$this->hook_prefix = $hook_prefix;
		}

		/**
		 * Fetches and normalizes a shipment's tracking history.
		 *
		 * Calls {@see Shipping_API::get_tracking()} and hands the response to the
		 * concrete carrier's {@see self::map_events()} to produce a typed event list.
		 * A carrier/network failure is not fatal: an empty history is returned rather
		 * than surfaced to the caller.
		 *
		 * @since 1.5.0
		 *
		 * @param string $tracking_number the carrier-assigned tracking number
		 * @return array normalized tracking history, oldest-to-newest as the carrier returns it
		 * @phpstan-return list<Tracking_Event>
		 */
		public function get_history( string $tracking_number ): array {

			try {
				$response = $this->api->get_tracking( $tracking_number );
			} catch ( \Woodev_API_Exception $exception ) {
				return array();
			}

			return $this->map_events( $response );
		}

		/**
		 * Fires the admin display hook for a shipment's tracking history.
		 *
		 * Intended to be called from the plugin's order-admin screen. Subscribers
		 * render the typed history; the framework renders nothing itself.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order           the order being displayed
		 * @param string    $tracking_number the carrier-assigned tracking number
		 * @return void
		 */
		public function display_admin( \WC_Order $order, string $tracking_number ): void {

			/**
			 * Fires to render a shipment's tracking history on the admin order screen.
			 *
			 * @since 1.5.0
			 *
			 * @param array     $history normalized tracking history (list of Tracking_Event arrays)
			 * @param \WC_Order $order   the order being displayed
			 */
			do_action( $this->hook( 'tracking_admin_display' ), $this->get_history( $tracking_number ), $order );
		}

		/**
		 * Fires the frontend display hook for a shipment's tracking history.
		 *
		 * Intended to be called from a customer-facing template (e.g. order details).
		 * Subscribers render the typed history; the framework renders nothing itself.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order           the order being displayed
		 * @param string    $tracking_number the carrier-assigned tracking number
		 * @return void
		 */
		public function display_frontend( \WC_Order $order, string $tracking_number ): void {

			/**
			 * Fires to render a shipment's tracking history on the storefront.
			 *
			 * @since 1.5.0
			 *
			 * @param array     $history normalized tracking history (list of Tracking_Event arrays)
			 * @param \WC_Order $order   the order being displayed
			 */
			do_action( $this->hook( 'tracking_frontend_display' ), $this->get_history( $tracking_number ), $order );
		}

		/**
		 * Builds one normalized, carrier-neutral tracking event.
		 *
		 * Concrete carriers call this from {@see self::map_events()} so every event
		 * shares the same typed shape regardless of the carrier's payload.
		 *
		 * @since 1.5.0
		 *
		 * @param string $status      carrier status label or code for this step
		 * @param string $description human-readable description of the step
		 * @param int    $timestamp   Unix timestamp the step occurred (0 when the carrier gives none)
		 * @param string $location    location the step occurred at (empty when the carrier gives none)
		 * @return array the normalized event
		 * @phpstan-return Tracking_Event
		 */
		protected static function make_event( string $status, string $description = '', int $timestamp = 0, string $location = '' ): array {
			return array(
				'status'      => $status,
				'description' => $description,
				'timestamp'   => $timestamp,
				'location'    => $location,
			);
		}

		/**
		 * Maps a carrier tracking response into a typed event list.
		 *
		 * Each carrier returns its history in a different shape, so the concrete
		 * handler extracts and normalizes it (building each step with
		 * {@see self::make_event()}); the base class only knows the result is a list
		 * of `Tracking_Event` arrays.
		 *
		 * @since 1.5.0
		 *
		 * @param \Woodev_API_Response $response the tracking response from the carrier
		 * @return array normalized tracking history
		 * @phpstan-return list<Tracking_Event>
		 */
		abstract protected function map_events( \Woodev_API_Response $response ): array;

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		protected function hook( string $name ): string {

			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}
	}

endif;
