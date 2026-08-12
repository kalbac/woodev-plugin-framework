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
 * Location-provider layer (Task 9, 2026-08-12 plan; spec D1/D4/D15/§4.4): when a
 * {@see \Woodev\Framework\Shipping\Location\Location_Service} collaborator is
 * injected AND {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()},
 * {@see self::build()} adds ONE extra `location` block to this SAME config
 * object — there is exactly one config object and one enqueue path (spec
 * intent), the location block just rides inside it. The block is entirely
 * absent (no `location` key at all, not merely `null`) when no service is
 * injected or the layer is inactive, so an existing consumer that never heard
 * of the location layer sees no shape change. See {@see self::build_location_block()}
 * for the block's own shape and the D4/D15 non-leak guarantees.
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
	 *   'fields'   => [ field_id => [ id, type, section, source_kind, location_level, depends_on, required, is_pickup_slot ] ],
	 *   'endpoint' => '{rest_base}/shipping/checkout/{plugin_id}/field-source',
	 *   'nonce'    => string,
	 *   'takeover' => [ field_id => [ country_code => bool ] ],
	 *   // Present only when a Location_Service was injected AND is_active() (Task 9):
	 *   'location' => [
	 *     'endpoints' => [ 'suggest' => string, 'select' => string ],
	 *     'nonce'     => string, // same wp_rest nonce as the top-level 'nonce' above
	 *     'countries' => string[],
	 *     'mode'      => string,
	 *     'levels'    => [ country_code => [ 'region' => bool, 'settlement' => bool, 'address' => bool ] ],
	 *     'current'   => [ 'key' => string, 'level' => string ]|null,
	 *     'implicit'  => bool,
	 *   ],
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
		 * The Location Provider layer's service façade, or `null` when this
		 * config was built without location-layer awareness (e.g. an older
		 * caller, or a unit test that does not care about it). `null` and "the
		 * service reports {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()}
		 * `false`" collapse to the exact same outcome — no `location` key in
		 * the built array — so a caller never needs to branch on which one it
		 * is.
		 *
		 * @since 2.0.2
		 * @var \Woodev\Framework\Shipping\Location\Location_Service|null
		 */
		private ?\Woodev\Framework\Shipping\Location\Location_Service $location_service;

		/**
		 * Constructor.
		 *
		 * Country codes are injected for testability; the real caller should
		 * pass `array_keys( WC()->countries->get_countries() )` — this class
		 * never calls `WC()` itself.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$location_service` collaborator
		 *              (location-provider layer Task 9).
		 *
		 * @param string                                                    $plugin_id        Plugin identifier (used in REST endpoint path).
		 * @param string                                                    $rest_base        REST API base URL without a trailing slash.
		 * @param string                                                    $nonce            WP nonce for the field-source endpoint.
		 * @param string[]                                                  $countries        Country codes to evaluate takeover predicates against.
		 * @param \Woodev\Framework\Shipping\Location\Location_Service|null $location_service Location Provider layer façade; `null` omits the
		 *                                                                                        `location` block entirely.
		 */
		public function __construct(
			string $plugin_id,
			string $rest_base,
			string $nonce,
			array $countries,
			?\Woodev\Framework\Shipping\Location\Location_Service $location_service = null
		) {
			$this->plugin_id        = $plugin_id;
			$this->rest_base        = rtrim( $rest_base, '/' );
			$this->nonce            = $nonce;
			$this->countries        = $countries;
			$this->location_service = $location_service;
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
		 *         location_level: string|null,
		 *         depends_on: string|null,
		 *         required: bool|array<string, mixed>,
		 *         is_pickup_slot: bool
		 *     }>,
		 *     endpoint: string,
		 *     nonce: string,
		 *     takeover: array<string, array<string, bool>>,
		 *     location?: array{
		 *         endpoints: array{suggest: string, select: string},
		 *         nonce: string,
		 *         countries: string[],
		 *         mode: string,
		 *         levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *         current: array{key: string, level: string}|null,
		 *         implicit: bool
		 *     }
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
					'location_level' => $def['location_level'] ?? null,
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

			$config = [
				'fields'   => $out_fields,
				'endpoint' => $this->rest_base . '/shipping/checkout/' . $this->plugin_id . '/field-source',
				'nonce'    => $this->nonce,
				'takeover' => $takeover,
			];

			if ( null !== $this->location_service && $this->location_service->is_active() ) {
				$config['location'] = $this->build_location_block( $this->location_service );
			}

			return $config;
		}

		/**
		 * Builds the location-provider config block (Task 9; spec D1, D4, D15, §4.4).
		 *
		 * Only ever called once {@see self::build()} has already confirmed
		 * {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()}, so
		 * every read here is against an active, configured provider.
		 *
		 * - `endpoints` — the FLEET-WIDE `woodev/v1/location/(suggest|select)` routes
		 *   (Task 8's {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}),
		 *   never namespaced by `plugin_id` — there is exactly one active location
		 *   provider per store.
		 * - `nonce` — deliberately the SAME `wp_rest` cookie nonce as the top-level
		 *   `$config['nonce']` (both come from one `wp_create_nonce( 'wp_rest' )` call
		 *   at the caller), because that is the exact nonce
		 *   {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::check_select_permission()}
		 *   verifies — minting a second, differently-named nonce for the same action
		 *   would just be two names for one value.
		 * - `countries` — the intersection of the injected WC selling-country list
		 *   ({@see $countries}) and {@see \Woodev\Framework\Shipping\Location\Location_Service::get_supported_countries()}:
		 *   the UNION across the whole D15 chain (D15 gate fix, block PR-B) — a
		 *   country only the FALLBACK covers at some level must still surface here,
		 *   not just what the ACTIVE provider alone lists. A country the store does
		 *   not even sell to is not useful information for the client-side D2
		 *   arbitration this list feeds, hence the intersection with the WC list.
		 * - `mode` — the store's field-presentation setting (spec D7). Nothing
		 *   declares that setting yet (Tasks 13/14), so the only HONEST value today
		 *   is the one mode D7 says is unconditionally available regardless of
		 *   provider capability: free-text typeahead. This constant is the one
		 *   TODO-shaped line in this method — Task 13/14 replaces it with a real
		 *   read from the settings surface.
		 * - `levels` — a MAP, `{ [country]: { region, settlement, address } }`, one
		 *   entry per country in `countries` above (D15 amendment follow-up:
		 *   per-country suggest levels — DaData genuinely serves `address` in
		 *   RU/BY/KZ/UZ but not in AM/AZ/KG/TJ/TM, so a single flat per-level
		 *   answer is no longer honest across every country the layer covers).
		 *   Shipped as a full map rather than the current country's answer alone
		 *   because this config is emitted ONCE per page render (`wp_localize_script`,
		 *   no round-trip on a plain country-field change) — the client's D2/D15
		 *   country-switch handling (Task 11) needs to re-evaluate per-level
		 *   support the instant the customer picks a different country, and a
		 *   single-country answer would go stale at that exact moment with no way
		 *   to refresh it. Each per-country entry answers whether the D15
		 *   provider-fallback chain
		 *   ({@see \Woodev\Framework\Shipping\Location\Location_Service::get_levels_for_country()})
		 *   resolves ANY provider for that level IN that country. The client
		 *   learns only this — NEVER which provider serves a level (spec D15) —
		 *   because neither this method nor `get_levels_for_country()` ever reads
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_id()}.
		 * - `current`/`implicit` — from
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Service::get_customer_record()}.
		 *   `current`'s shape (`{ key, level }`) is deliberately byte-for-byte the
		 *   same as what
		 *   {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_select_request()}
		 *   returns under its own `current` key (Task 8), so the client can update
		 *   from either response without the two ever disagreeing. `implicit` is
		 *   `false` when there is no record at all — an implicit flag is only
		 *   meaningful attached to an actual record.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 `levels` changed from a single flat per-level map to a
		 *              per-country map (D15 amendment follow-up).
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Service $service The active, already-confirmed façade.
		 *
		 * @return array{
		 *     endpoints: array{suggest: string, select: string},
		 *     nonce: string,
		 *     countries: string[],
		 *     mode: string,
		 *     levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *     current: array{key: string, level: string}|null,
		 *     implicit: bool
		 * }
		 */
		private function build_location_block( \Woodev\Framework\Shipping\Location\Location_Service $service ): array {

			$supported_countries = $service->get_supported_countries();

			$countries = [];
			foreach ( $this->countries as $code ) {
				if ( in_array( $code, $supported_countries, true ) ) {
					$countries[] = $code;
				}
			}

			$levels = [];
			foreach ( $countries as $code ) {
				$levels[ $code ] = $service->get_levels_for_country( $code );
			}

			$customer = $service->get_customer_record();
			$current  = null;
			$implicit = false;

			if ( null !== $customer ) {
				$current  = [
					'key'   => $customer['record']->key(),
					'level' => $customer['record']->level(),
				];
				$implicit = (bool) $customer['implicit'];
			}

			return [
				'endpoints' => [
					'suggest' => $this->rest_base . '/location/suggest',
					'select'  => $this->rest_base . '/location/select',
				],
				'nonce'     => $this->nonce,
				'countries' => array_values( $countries ),
				'mode'      => 'typeahead',
				'levels'    => $levels,
				'current'   => $current,
				'implicit'  => $implicit,
			];
		}
	}

endif;
