<?php
/**
 * Woodev Checkout Field-Source REST Controller
 *
 * Serves the cascade `options` / `suggest` data for the custom checkout fields a
 * shipping plugin registers (spec §2 decision 7, §6). It exposes one read-only
 * route that resolves a `field_id` against its owning {@see Checkout_Fields}
 * registry and invokes that field's `source` callback with a SANITIZED context,
 * returning a `{ options: [ { value, label }, … ] }` payload.
 *
 * SECURITY (spec §11, Codex hardening HIGH #1): this is a PUBLIC guest-checkout
 * endpoint. Every request parameter is normalized BEFORE the source callback
 * runs, every option the source returns is escaped BEFORE it reaches the wire,
 * and a best-effort per-IP rate limit raises the bar against abuse. The route is
 * intentionally public because cascade option/suggest data is not sensitive; a
 * future SENSITIVE source callback must add its own authorization.
 *
 * The concrete field ids + their source callbacks are plugin-supplied through the
 * {@see Checkout_Fields} registry passed to the constructor, so the framework
 * mints no field-name contract of its own. The route registers under the
 * `woodev/v1` namespace and disambiguates by the `plugin_id` path segment (which
 * must match this controller's owning plugin), so multiple plugins' controllers
 * coexist without collision.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Field_Source_Controller' ) ) :

	/**
	 * Checkout field-source dispatch controller.
	 *
	 * Constructed with the owning {@see Checkout_Fields} registry and the plugin
	 * id it belongs to, so it routes lookups to the correct registry and rejects
	 * requests whose `plugin_id` path segment does not match.
	 *
	 * @since 2.0.2
	 */
	class Field_Source_Controller extends \WP_REST_Controller {

		/**
		 * Maximum accepted length (chars) for the free-text `q` / `parent` params.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const MAX_PARAM_LENGTH = 128;

		/**
		 * Rate-limit window, in seconds.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const RATE_LIMIT_WINDOW = 60; // MINUTE_IN_SECONDS — literal so the class loads WC-free.

		/**
		 * Maximum requests allowed per IP within {@see RATE_LIMIT_WINDOW}.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const RATE_LIMIT_MAX = 60;

		/**
		 * The field registry whose `source` callbacks this controller dispatches.
		 *
		 * @since 2.0.2
		 *
		 * @var Checkout_Fields
		 */
		private Checkout_Fields $fields;

		/**
		 * The owning plugin id this controller answers for.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Fields $fields    the owning field registry.
		 * @param string          $plugin_id the plugin id this controller routes for.
		 */
		public function __construct( Checkout_Fields $fields, string $plugin_id ) {
			$this->fields    = $fields;
			$this->plugin_id = $plugin_id;
		}

		/**
		 * Registers the field-source route.
		 *
		 * Read-only: a single `GET` endpoint under `woodev/v1`. The route is
		 * intentionally public.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				'woodev/v1',
				'/shipping/checkout/(?P<plugin_id>[\w-]+)/field-source/(?P<field_id>[\w-]+)',
				[
					[
						'methods'  => 'GET',
						'callback' => [ $this, 'handle_request' ],

						/*
						 * Intentionally public read: cascade option/suggest data is not
						 * sensitive. A future SENSITIVE source callback must add its own auth.
						 */
						'permission_callback' => '__return_true',
						'args'                => [
							'plugin_id' => [
								'type'     => 'string',
								'required' => true,
							],
							'field_id'  => [
								'type'     => 'string',
								'required' => true,
							],
							'country'   => [ 'type' => 'string' ],
							'parent'    => [ 'type' => 'string' ],
							'q'         => [ 'type' => 'string' ],
						],
					],
				]
			);
		}

		/**
		 * Handles a field-source request.
		 *
		 * Guards the `plugin_id` path segment against this controller's owner,
		 * applies the best-effort rate limit, normalizes the query context, invokes
		 * the field's source through {@see get_field_source()}, then escapes every
		 * returned option via {@see normalize_options()} before serializing.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_request( $request ) {

			if ( (string) $request->get_param( 'plugin_id' ) !== $this->plugin_id ) {
				return new \WP_Error(
					'woodev_field_source_not_found',
					__( 'Unknown field source.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			if ( $this->is_rate_limited() ) {
				return new \WP_Error(
					'woodev_field_source_rate_limited',
					__( 'Too many requests. Please slow down.', 'woodev-plugin-framework' ),
					[ 'status' => 429 ]
				);
			}

			$field_id = (string) $request->get_param( 'field_id' );

			$context = $this->normalize_context(
				[
					'country' => $request->get_param( 'country' ),
					'parent'  => $request->get_param( 'parent' ),
					'q'       => $request->get_param( 'q' ),
				]
			);

			$options = $this->normalize_options( $this->get_field_source( $field_id, $context ) );

			return rest_ensure_response( [ 'options' => $options ] );
		}

		/**
		 * Dispatches a field's source callback (pure, WC-free core).
		 *
		 * Looks up the field in the owning registry; if the field is absent or has
		 * no `source` callback, returns an empty list. Otherwise invokes the source
		 * with the given context and returns its RAW list — response escaping and
		 * normalization happen in {@see normalize_options()}, keeping this method
		 * unit-testable without WordPress.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $field_id field id to resolve.
		 * @param array<string, mixed> $context  sanitized dispatch context.
		 *
		 * @return array<int, mixed> raw source items (unescaped).
		 */
		public function get_field_source( string $field_id, array $context ): array {

			$field = $this->fields->get_field( $field_id );

			if ( null === $field || ! is_callable( $field['source'] ?? null ) ) {
				return [];
			}

			$result = ( $field['source'] )( $context );

			return is_array( $result ) ? $result : [];
		}

		/**
		 * Normalizes the raw request parameters into a safe dispatch context.
		 *
		 * SECURITY: applied BEFORE the source callback runs. `country` is uppercased,
		 * sanitized and kept only when it is a valid WC country code (empty string
		 * otherwise); `parent` and `q` are sanitized and capped to
		 * {@see MAX_PARAM_LENGTH} characters.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw raw request params (`country`, `parent`, `q`).
		 *
		 * @return array<string, string> normalized context.
		 */
		protected function normalize_context( array $raw ): array {

			$country = strtoupper( (string) wc_clean( wp_unslash( $raw['country'] ?? '' ) ) );

			return [
				'country' => '' !== $country && $this->is_valid_country( $country ) ? $country : '',
				'parent'  => $this->cap_length( (string) wc_clean( wp_unslash( $raw['parent'] ?? '' ) ) ),
				'q'       => $this->cap_length( (string) wc_clean( wp_unslash( $raw['q'] ?? '' ) ) ),
			];
		}

		/**
		 * Caps a string to {@see MAX_PARAM_LENGTH} characters (multibyte-aware).
		 *
		 * @since 2.0.2
		 *
		 * @param string $value value to cap.
		 *
		 * @return string
		 */
		protected function cap_length( string $value ): string {
			return function_exists( 'mb_substr' )
				? mb_substr( $value, 0, self::MAX_PARAM_LENGTH )
				: substr( $value, 0, self::MAX_PARAM_LENGTH );
		}

		/**
		 * Determines whether a country code is a valid WC country.
		 *
		 * When WooCommerce is available the code is checked against
		 * {@see \WC_Countries::get_countries()}. When WC is absent (a non-WC context,
		 * e.g. unit tests) the sanitized code is treated as a valid passthrough so
		 * the core dispatch remains framework-neutral.
		 *
		 * @since 2.0.2
		 *
		 * @param string $code uppercased, sanitized country code.
		 *
		 * @return bool
		 */
		protected function is_valid_country( string $code ): bool {

			if ( ! function_exists( 'WC' ) || null === WC() || null === WC()->countries ) {
				return true; // Non-WC context: sanitized passthrough.
			}

			return array_key_exists( $code, WC()->countries->get_countries() );
		}

		/**
		 * Normalizes + escapes the raw source options for the response.
		 *
		 * SECURITY: applied to whatever the source returns before it hits the wire.
		 * Each item is reduced to `{ value, label }`, the value is sanitized and cast
		 * to a string and the label is HTML-escaped — raw source HTML is never
		 * emitted. Malformed items (non-array, or missing a `value` key) are dropped.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, mixed> $options raw source items.
		 *
		 * @return array<int, array{value: string, label: string}> escaped options.
		 */
		protected function normalize_options( array $options ): array {

			$normalized = [];

			foreach ( $options as $option ) {

				if ( ! is_array( $option ) || ! array_key_exists( 'value', $option ) ) {
					continue;
				}

				$normalized[] = [
					'value' => (string) wc_clean( $option['value'] ),
					'label' => esc_html( (string) ( $option['label'] ?? '' ) ),
				];
			}

			return $normalized;
		}

		/**
		 * Best-effort per-IP rate limit (bar-raiser).
		 *
		 * Allows {@see RATE_LIMIT_MAX} requests per client IP within
		 * {@see RATE_LIMIT_WINDOW}, tracked in a transient keyed by the hashed IP.
		 * This is a weak defense — it is trivially defeated by proxies and does not
		 * account for shared / rotating IPv6 — but it raises the cost of trivial
		 * abuse of the public endpoint. Overridable so tests can bypass it.
		 *
		 * @since 2.0.2
		 *
		 * @return bool true when the caller has exceeded the window's budget.
		 */
		protected function is_rate_limited(): bool {

			$ip = $this->get_client_ip();

			if ( '' === $ip ) {
				return false;
			}

			$key   = 'woodev_fieldsrc_rl_' . md5( $ip );
			$count = (int) get_transient( $key );

			if ( $count >= self::RATE_LIMIT_MAX ) {
				return true;
			}

			set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

			return false;
		}

		/**
		 * Resolves the client IP for the rate-limit key.
		 *
		 * Uses WooCommerce's geolocation helper when present (which already applies
		 * the trusted-proxy logic), falling back to the raw remote address.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized client IP, or '' when unknown.
		 */
		protected function get_client_ip(): string {

			if ( class_exists( '\\WC_Geolocation' ) ) {
				return (string) \WC_Geolocation::get_ip_address();
			}

			return isset( $_SERVER['REMOTE_ADDR'] )
				? (string) wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '';
		}
	}

endif;
