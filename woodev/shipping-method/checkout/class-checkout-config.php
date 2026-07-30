<?php
/**
 * Checkout Config.
 *
 * Builds a JS-safe config array from a {@see Checkout_Fields} set.
 * The emitted array contains only serialisable data — no PHP callables,
 * no raw source/sanitize/validate callbacks. Takeover conditions are
 * evaluated eagerly against the injected country list so the browser
 * receives a plain `{ country_code: bool }` map instead of a predicate.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping\Checkout
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Config' ) ) :

	/**
	 * Builds a JS-safe config array from a {@see Checkout_Fields} set.
	 *
	 * Shape of the returned array:
	 * ```
	 * [
	 *   'fields'   => [ field_id => [ id, type, section, source_kind, depends_on, required, is_pickup_slot ] ],
	 *   'endpoint' => '{rest_base}/shipping/checkout/{plugin_id}/field-source',
	 *   'nonce'    => string,
	 *   'takeover' => [ field_id => [ country_code => bool ] ],
	 * ]
	 * ```
	 *
	 * @since 2.0.2
	 */
	class Checkout_Config {

		/**
		 * Plugin identifier used to build the REST endpoint.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * REST API base URL (no trailing slash).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $rest_base;

		/**
		 * WP nonce for the field-source REST endpoint.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $nonce;

		/**
		 * Country codes to evaluate takeover conditions against.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private array $countries;

		/**
		 * Constructor.
		 *
		 * Country codes are injected for testability; the real caller should
		 * pass `array_keys( WC()->countries->get_countries() )` — this class
		 * never calls `WC()` itself.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $plugin_id Plugin identifier (used in REST endpoint path).
		 * @param string   $rest_base REST API base URL without a trailing slash.
		 * @param string   $nonce     WP nonce for the field-source endpoint.
		 * @param string[] $countries Country codes to evaluate takeover predicates against.
		 */
		public function __construct( string $plugin_id, string $rest_base, string $nonce, array $countries ) {
			$this->plugin_id = $plugin_id;
			$this->rest_base  = rtrim( $rest_base, '/' );
			$this->nonce      = $nonce;
			$this->countries  = $countries;
		}

		/**
		 * Builds the JS-safe config array from the given field set.
		 *
		 * Iterates all normalized field descriptors and emits only the safe
		 * subset of keys. Callable seams (`source`, `takeover_condition`,
		 * `sanitize_callback`, `validate_callback`) are stripped. For each
		 * field whose `takeover_condition` is a callable the method evaluates
		 * it against every country in {@see $countries} and stores the boolean
		 * result map under `$config['takeover'][$field_id]`.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Fields $fields Normalized field definitions to emit.
		 *
		 * @return array{
		 *     fields: array<string, array{
		 *         id: string,
		 *         type: string,
		 *         section: string,
		 *         source_kind: string|null,
		 *         depends_on: string|null,
		 *         required: bool|array<string, mixed>,
		 *         is_pickup_slot: bool
		 *     }>,
		 *     endpoint: string,
		 *     nonce: string,
		 *     takeover: array<string, array<string, bool>>
		 * }
		 */
		public function build( Checkout_Fields $fields ): array {
			$out_fields = [];
			$takeover   = [];

			foreach ( $fields->get_fields() as $id => $def ) {
				$out_fields[ $id ] = [
					'id'             => $def['id'],
					'type'           => $def['type'],
					'section'        => $def['section'],
					'source_kind'    => $def['source_kind'],
					'depends_on'     => $def['depends_on'],
					'required'       => $def['required'],
					'is_pickup_slot' => $def['is_pickup_slot'],
				];

				$condition = $def['takeover_condition'] ?? null;
				if ( is_callable( $condition ) ) {
					$map = [];
					foreach ( $this->countries as $code ) {
						$map[ $code ] = (bool) $condition( [ 'country' => $code ] );
					}
					$takeover[ $id ] = $map;
				}
			}

			return [
				'fields'   => $out_fields,
				'endpoint' => $this->rest_base . '/shipping/checkout/' . $this->plugin_id . '/field-source',
				'nonce'    => $this->nonce,
				'takeover' => $takeover,
			];
		}
	}

endif;
