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

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;
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

		use Rest_Rate_Limit_Trait;

		/**
		 * Maximum accepted length (chars) for the free-text `q` / `parent` params.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const MAX_PARAM_LENGTH = 128;

		/**
		 * Maximum requests allowed per IP per rate-limit window (default 60s window,
		 * see {@see Rest_Rate_Limit_Trait::is_rate_limited()}).
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

			// The plugin id is baked into the route PATH as a literal (not a
			// `(?P<plugin_id>…)` capture) so that each shipping plugin registers a DISTINCT
			// route. A shared capture-group route would be identical across plugins, and
			// WordPress dispatches to the FIRST matching route — so plugin B's request would
			// land in plugin A's controller and 404 on the id mismatch. (Codex review P2.)
			$plugin_segment = preg_replace( '/[^\w-]/', '', $this->plugin_id );

			// A plugin id made only of stripped characters would yield a malformed
			// `/shipping/checkout//field-source/` route — fall back to the same default the
			// handler uses for an empty plugin id. (Codex re-critic.)
			if ( '' === (string) $plugin_segment ) {
				$plugin_segment = 'shipping';
			}

			register_rest_route(
				'woodev/v1',
				'/shipping/checkout/' . $plugin_segment . '/field-source/(?P<field_id>[\w-]+)',
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
							'field_id' => [
								'type'     => 'string',
								'required' => true,
							],
							'country'  => [ 'type' => 'string' ],
							'parent'   => [ 'type' => 'string' ],
							'q'        => [ 'type' => 'string' ],
						],
					],
				]
			);
		}

		/**
		 * Handles a field-source request.
		 *
		 * Applies the best-effort rate limit, normalizes the query context, invokes
		 * the field's source through {@see get_field_source()}, then escapes every
		 * returned option via {@see normalize_options()} before serializing. The
		 * plugin id is enforced by the route path itself (see {@see register_routes()}).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_request( \WP_REST_Request $request ) {

			// No plugin_id guard needed: the route path embeds this controller's plugin id
			// as a literal segment (see register_routes()), so only this plugin's requests
			// ever reach here. (Codex review P2.)
			if ( $this->is_rate_limited( 'woodev_fieldsrc_rl_', self::RATE_LIMIT_MAX ) ) {
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

			$parent = (string) wc_clean( wp_unslash( $raw['parent'] ?? '' ) );
			$search = (string) wc_clean( wp_unslash( $raw['q'] ?? '' ) );

			return [
				'country' => '' !== $country && $this->is_valid_country( $country ) ? $country : '',
				'parent'  => $this->cap_length( $parent, self::MAX_PARAM_LENGTH ),
				'q'       => $this->cap_length( $search, self::MAX_PARAM_LENGTH ),
			];
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

		// is_rate_limited(), get_client_ip() and cap_length() are provided by
		// Rest_Rate_Limit_Trait (shared with Pickup_Controller) — see that trait for the
		// rate-limit mechanism and its proxy/IPv6 caveats.
	}

endif;
