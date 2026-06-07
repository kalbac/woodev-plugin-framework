<?php
/**
 * Woodev Shipping AJAX Base
 *
 * The AJAX surface behind the pickup-point map (spec §4.1). An abstract base a
 * carrier extends to wire two nonce-protected endpoints to the provider-agnostic
 * map core (`assets/js/frontend/pickup-map.js`):
 *
 *  - **search** — `fetchPoints` in the JS — runs {@see Pickup_Point_Source::search()}
 *    and returns the normalized points as JSON.
 *  - **set**    — `selectPoint` in the JS — persists the chosen point in the WC
 *    session via {@see Pickup_Selection::set()}.
 *
 * CRITICAL — AJAX action names are installed-site contracts and are NOT derivable.
 * Every live carrier action string is distinct and bookmarked into front-end JS
 * (yandex: `get_yandex_delivery_shipment_points` / `set_yandex_delivery_pickup_point`;
 * edostavka: `edostavka_get_deliverypoints` / `edostavka_set_delivery_point`) — a
 * value derived from the method id (`yandex_delivery_express`) matches none of them
 * and would 404 every live request. So, exactly like the order handler's meta-key
 * map (§4.3), the PLUGIN SUPPLIES the action-name map (logical endpoint → real
 * action string); the framework hardcodes and derives nothing.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Ajax;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Ajax\\Shipping_AJAX' ) ) :

	/**
	 * Nonce-protected AJAX endpoints for the pickup-point map.
	 *
	 * A carrier subclasses this and constructs it with its own plugin-supplied
	 * action-name map and nonce action — the framework derives no contract string.
	 * The transform hooks ({@see Shipping_AJAX::parse_search_params()},
	 * {@see Shipping_AJAX::build_point_from_request()}) are overridable so a carrier
	 * can map its provider-specific request payload, while the base owns the WP
	 * plumbing (registration, nonce verification, JSON responses).
	 *
	 * @since 1.5.0
	 */
	abstract class Shipping_AJAX {

		/** @var string logical search endpoint (the map's `fetchPoints` half) */
		public const ENDPOINT_SEARCH = 'search';

		/** @var string logical set-selected-point endpoint (the map's `selectPoint` half) */
		public const ENDPOINT_SET = 'set';

		/** @var array<string, string> plugin-supplied map: logical endpoint => real installed-site AJAX action string */
		private array $action_map;

		/** @var string plugin-supplied nonce action verified on every request (the framework derives none) */
		private string $nonce_action;

		/** @var Pickup_Point_Source carrier-normalizing source used by the search endpoint */
		private Pickup_Point_Source $point_source;

		/** @var Pickup_Selection session-only store written by the set endpoint */
		private Pickup_Selection $pickup_selection;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $action_map       logical endpoint => real AJAX action string, supplied by the plugin (e.g. `[ 'search' => 'get_yandex_delivery_shipment_points', 'set' => 'set_yandex_delivery_pickup_point' ]`)
		 * @param string                $nonce_action     nonce action the front-end localizes and every handler checks
		 * @param Pickup_Point_Source   $point_source     source the search endpoint queries
		 * @param Pickup_Selection      $pickup_selection session store the set endpoint writes to
		 */
		public function __construct(
			array $action_map,
			string $nonce_action,
			Pickup_Point_Source $point_source,
			Pickup_Selection $pickup_selection
		) {
			$this->action_map       = $action_map;
			$this->nonce_action     = $nonce_action;
			$this->point_source     = $point_source;
			$this->pickup_selection = $pickup_selection;
		}

		/**
		 * Registers the AJAX handlers under the plugin-supplied action strings.
		 *
		 * Each mapped endpoint gets both the authenticated (`wp_ajax_`) and the
		 * guest (`wp_ajax_nopriv_`) hook, since checkout pickup search/selection
		 * happens for logged-out shoppers too. An endpoint absent from the map is
		 * simply not registered — a carrier may expose only the ones it needs.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {

			$callbacks = [
				self::ENDPOINT_SEARCH => 'handle_search',
				self::ENDPOINT_SET    => 'handle_set',
			];

			foreach ( $callbacks as $endpoint => $callback ) {

				$action = $this->action_map[ $endpoint ] ?? '';

				if ( '' === $action ) {
					continue;
				}

				add_action( "wp_ajax_{$action}", [ $this, $callback ] );
				add_action( "wp_ajax_nopriv_{$action}", [ $this, $callback ] );
			}
		}

		/**
		 * Handles the pickup-point search request.
		 *
		 * Verifies the nonce, maps the request into search params and returns the
		 * normalized points under `data.points` — the shape `pickup-map.js`
		 * (`normalizeResponse()`) expects.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_search(): void {

			$this->verify_request();

			try {

				$points = $this->point_source->search( $this->parse_search_params() );

				wp_send_json_success(
					[
						'points' => array_map(
							static fn( Pickup_Point $point ): array => $point->to_array(),
							$points
						),
					]
				);

			} catch ( \Exception $exception ) {

				wp_send_json_error( [ 'message' => $exception->getMessage() ] );
			}
		}

		/**
		 * Handles the set-selected-point request.
		 *
		 * Verifies the nonce, rebuilds the chosen point from the request and stores
		 * it in the WC session. Reads the payload the shipped JS actually posts —
		 * `point_id` plus the flattened `point.meta` (`pickup-map.js`
		 * `persistSelection()`) — not a `point` array.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_set(): void {

			$this->verify_request();

			try {

				$point = $this->build_point_from_request();

				$this->pickup_selection->set( $point );

				wp_send_json_success( [ 'point_id' => $point->get_code() ] );

			} catch ( \Exception $exception ) {

				wp_send_json_error( [ 'message' => $exception->getMessage() ] );
			}
		}

		/**
		 * Maps the posted request into {@see Pickup_Point_Source::search()} params.
		 *
		 * Default implementation reads the core search keys documented on the source
		 * interface (`city`, `postal_code`, `lat`, `lng`, `limit`). A carrier whose
		 * map JS posts additional `requestParams` overrides this to map them.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, mixed> search params (empty keys omitted)
		 */
		protected function parse_search_params(): array {

			$params = [];

			foreach ( [ 'city', 'postal_code' ] as $key ) {

				$value = \Woodev_Helper::get_posted_value( $key );

				if ( is_string( $value ) && '' !== $value ) {
					$params[ $key ] = $value;
				}
			}

			foreach ( [ 'lat', 'lng' ] as $key ) {

				$value = \Woodev_Helper::get_posted_value( $key );

				if ( is_scalar( $value ) && '' !== $value ) {
					$params[ $key ] = (float) $value;
				}
			}

			$limit = \Woodev_Helper::get_posted_value( 'limit' );

			if ( is_scalar( $limit ) && '' !== $limit ) {
				$params['limit'] = (int) $limit;
			}

			return $params;
		}

		/**
		 * Rebuilds the chosen pickup point from the posted request.
		 *
		 * Default implementation reads `point_id` — the only field the shipped map
		 * core guarantees — into the point's `code`. A carrier whose `point.meta`
		 * carries extra fields (lat/lng/name) overrides this to map them into the
		 * point, e.g. via {@see Pickup_Point::from_array()}.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point the chosen point reconstructed from the request
		 */
		protected function build_point_from_request(): Pickup_Point {

			$point_id = \Woodev_Helper::get_posted_value( 'point_id' );

			return Pickup_Point::from_array(
				[ 'code' => is_scalar( $point_id ) ? (string) $point_id : '' ]
			);
		}

		/**
		 * Verifies the request nonce, sending a JSON error and exiting on failure.
		 *
		 * The shipped map core posts the nonce under `security` (alongside a `nonce`
		 * alias); this checks `security` against the plugin-supplied nonce action.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		protected function verify_request(): void {

			if ( ! check_ajax_referer( $this->nonce_action, 'security', false ) ) {
				wp_send_json_error(
					[ 'message' => __( 'Security check failed.', 'woodev-plugin-framework' ) ],
					403
				);
			}
		}
	}

endif;
