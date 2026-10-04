<?php
/**
 * Public REST route the browser reports JavaScript errors and domain events to.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Browser_Rest_Controller' ) ) :

	/**
	 * `POST /woodev/v1/error-reporting/browser` — the browser half of the reporter (#1081, D7).
	 *
	 * Public on purpose: the pickup map is on the storefront and a shopper is logged out. The route is
	 * guarded by, in this order: reporting active (else 403), a valid `wp_rest` nonce (else 403 — core
	 * rejects a stale one before this class runs), a per-client rate limit (429), a body size cap (413)
	 * and a strict schema (400). What passes is handed to {@see Error_Reporter::report_browser()}, which
	 * re-validates it against what the server knows, drops what is not ours, applies the site-wide browser
	 * intake cap and queues the rest in the browser's own share of the queue, next to the PHP events. The
	 * answer is always `{queued: bool}` — a foreign or duplicate report is not an error for the browser.
	 *
	 * The schema checks SHAPE only. Shape is not anonymity: what is exported is decided by
	 * {@see Browser_Event_Builder} from sets the server holds.
	 *
	 * The per-client key is whatever {@see Rest_Rate_Limit_Trait} decides, deliberately and unchanged: the
	 * forwarding-header hint is a fairness bucket only, bounded by the coarse connection-address bucket
	 * (10× the budget) — and the site-wide intake cap and the browser's queue share bound what a client
	 * who rotates headers can achieve.
	 *
	 * Registered through {@see \Woodev_REST_V1_Registrar}, like the consent route.
	 *
	 * @since 2.0.2
	 */
	final class Browser_Rest_Controller {

		use Rest_Rate_Limit_Trait;

		/** Route under the `woodev/v1` namespace. */
		const ROUTE = '/error-reporting/browser';

		/** Largest request body accepted, in bytes. */
		const MAX_BODY = 8192;

		/** Requests one client may send per minute. */
		const RATE_LIMIT_MAX = 10;

		/** Top-level keys of a report; anything else is refused. */
		const KEYS = [ 'source', 'type', 'frames', 'pluginId', 'fieldId', 'code' ];

		/** Keys of one frame; anything else is refused. */
		const FRAME_KEYS = [ 'url', 'line', 'col' ];

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				self::ROUTE,
				[
					[
						'methods'             => 'POST',
						'callback'            => [ $this, 'create_item' ],
						'permission_callback' => [ $this, 'check_permissions' ],
					],
				]
			);
		}

		/**
		 * Reporting must be active, and the caller must hold a `wp_rest` nonce.
		 *
		 * A guest's nonce is not authentication (anyone can read it off a page): it only keeps a blind
		 * cross-site POST out. See the spec, D7.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request The request.
		 * @return true|\WP_Error
		 */
		public function check_permissions( \WP_REST_Request $request ) {
			if ( ! Consent::is_active() ) {
				return new \WP_Error( 'woodev_error_reporting_inactive', 'Error reporting is not active.', [ 'status' => 403 ] );
			}

			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error( 'woodev_error_reporting_invalid_nonce', 'Invalid or missing nonce.', [ 'status' => 403 ] );
			}

			return true;
		}

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request The request.
		 * @return array{queued:bool}|\WP_Error
		 */
		public function create_item( \WP_REST_Request $request ) {
			if ( $this->is_rate_limited( 'woodev_er_js_rl_', self::RATE_LIMIT_MAX ) ) {
				return new \WP_Error( 'woodev_error_reporting_rate_limited', 'Too many reports.', [ 'status' => 429 ] );
			}

			$body = (string) $request->get_body();

			if ( strlen( $body ) > self::MAX_BODY ) {
				return new \WP_Error( 'woodev_error_reporting_too_large', 'Report is too large.', [ 'status' => 413 ] );
			}

			$payload = json_decode( $body, true, 8 );

			if ( ! is_array( $payload ) || ! $this->is_valid( $payload ) ) {
				return new \WP_Error( 'woodev_error_reporting_invalid', 'Report does not match the schema.', [ 'status' => 400 ] );
			}

			return [ 'queued' => Error_Reporter::report_browser( $payload ) ];
		}

		/**
		 * The strict schema: known keys only, exact types, bounded sizes.
		 *
		 * @param array<mixed> $payload Decoded body.
		 * @return bool
		 */
		private function is_valid( array $payload ): bool {
			if ( [] !== array_diff( array_keys( $payload ), self::KEYS ) ) {
				return false;
			}

			$source = $payload['source'] ?? null;

			if ( ! in_array( $source, [ Browser_Event_Builder::SOURCE_ERROR, Browser_Event_Builder::SOURCE_REJECTION, Browser_Event_Builder::SOURCE_PICKUP ], true ) ) {
				return false;
			}

			if ( Browser_Event_Builder::SOURCE_PICKUP === $source ) {
				return null !== Browser_Event_Builder::token( $payload['pluginId'] ?? null )
					&& null !== Browser_Event_Builder::token( $payload['fieldId'] ?? null )
					&& null !== Browser_Event_Builder::token( $payload['code'] ?? null )
					&& ! isset( $payload['type'] )
					&& ! isset( $payload['frames'] );
			}

			if ( isset( $payload['pluginId'] ) || isset( $payload['fieldId'] ) || isset( $payload['code'] ) ) {
				return false;
			}

			$type = $payload['type'] ?? '';

			if ( ! is_string( $type ) || ( '' !== $type && 1 !== preg_match( '/^[A-Za-z_$][\w$]{0,63}$/D', $type ) ) ) {
				return false;
			}

			$frames = $payload['frames'] ?? null;

			// A list of at most MAX_FRAMES (`array_is_list()` is PHP 8.1; the framework supports 7.4).
			if ( ! is_array( $frames ) || count( $frames ) > Browser_Event_Builder::MAX_FRAMES || array_keys( $frames ) !== array_keys( array_values( $frames ) ) ) {
				return false;
			}

			foreach ( $frames as $frame ) {
				if ( ! $this->is_valid_frame( $frame ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * @param mixed $frame One decoded frame.
		 * @return bool
		 */
		private function is_valid_frame( $frame ): bool {
			if ( ! is_array( $frame ) || [] !== array_diff( array_keys( $frame ), self::FRAME_KEYS ) ) {
				return false;
			}

			if ( ! isset( $frame['url'] ) || ! is_string( $frame['url'] ) || strlen( $frame['url'] ) > Browser_Event_Builder::MAX_URL ) {
				return false;
			}

			foreach ( [ 'line', 'col' ] as $key ) {
				if ( isset( $frame[ $key ] ) && ( ! is_int( $frame[ $key ] ) || $frame[ $key ] < 0 || $frame[ $key ] > Browser_Event_Builder::MAX_POSITION ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * The address the web server observed — WooCommerce-free.
		 *
		 * The trait's own version calls `wc_clean()`, which does not exist on a site where
		 * WooCommerce is inactive; the reporter runs for every Woodev plugin, WooCommerce or not.
		 *
		 * @since 2.0.2
		 *
		 * @return string A validated IP address, or the shared «unknown» identity.
		 */
		protected function get_edge_ip(): string {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) ) : '';

			return '' !== $remote && false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : $this->rate_limit_unknown_identity();
		}
	}

endif;
