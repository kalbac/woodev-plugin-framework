<?php
/**
 * Woodev Checkout Handler
 *
 * The checkout orchestration backbone (spec §4.2): injects the plugin's
 * {@see Checkout_Fields} into the WooCommerce checkout, reads + sanitizes +
 * validates posted data, and saves the surviving values onto the order in an
 * HPOS-safe way via {@see \Woodev_Order_Compatibility}. Each step fires a forward
 * framework hook so the host plugin can attach its own `handle_*` callbacks.
 *
 * Contract-neutral by construction: field ids are supplied by the host plugin
 * (via `Checkout_Fields`), and the per-field order-meta key IS that plugin-supplied
 * id — the framework hardcodes no installed-site contract string here. The hooks
 * introduced by this class are NEW forward contracts, not renames of any existing
 * installed-site hook.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.2.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Handler' ) ) :

	/**
	 * Orchestrates custom checkout fields through the WooCommerce checkout.
	 *
	 * Holds a {@see Checkout_Fields} definition set and runs posted data through the
	 * uniform `sanitize → validate → save` pipeline. Sanitization and validation are
	 * delegated per field to the descriptor's callback seams; persistence is delegated
	 * to {@see \Woodev_Order_Compatibility} so it stays HPOS-safe. Every stage fires a
	 * forward hook, namespaced by a plugin-supplied token, so plugins can react without
	 * the framework owning any contract value.
	 *
	 * @since 1.5.0
	 */
	class Checkout_Handler {

		/** @var Checkout_Fields the field definitions this handler manages */
		private Checkout_Fields $fields;

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		private string $hook_prefix;

		/**
		 * Shipping method ids that unconditionally require a non-empty pickup field.
		 *
		 * Populated via {@see set_requires_pickup_methods()}. When non-empty,
		 * {@see validate()} runs an independent backstop guard after the per-field
		 * loop to ensure a pickup method can never be placed without a pickup point —
		 * regardless of the field's condition-spec.
		 *
		 * @var string[]
		 */
		private array $requires_pickup_methods = [];

		/**
		 * Registry of native WC field ids claimed by a plugin_id.
		 *
		 * Used by {@see guard_native_field_conflicts()} to detect multi-plugin
		 * conflicts at registration time. Keyed by field id, value is the
		 * plugin_id string of the first handler that registered that field.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, string>
		 */
		private static array $native_field_registry = [];

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param Checkout_Fields $fields      field definitions to inject and handle
		 * @param string          $hook_prefix plugin-supplied token (e.g. the plugin id) that
		 *        namespaces this handler's forward hooks so each plugin's hooks stay distinct;
		 *        defaults to none, yielding bare `woodev_shipping_*` hooks
		 */
		public function __construct( Checkout_Fields $fields, string $hook_prefix = '' ) {
			$this->fields      = $fields;
			$this->hook_prefix = $hook_prefix;
		}

		/**
		 * Gets the field definitions this handler manages.
		 *
		 * @since 1.5.0
		 *
		 * @return Checkout_Fields
		 */
		public function get_fields(): Checkout_Fields {
			return $this->fields;
		}

		/**
		 * Registers the shipping method ids that unconditionally require a pickup point.
		 *
		 * When set, {@see validate()} runs an independent backstop guard (separate from the
		 * per-field condition-spec loop) that blocks checkout whenever one of these methods
		 * is active and the first `is_pickup_slot` field is blank. This catches the case
		 * where a malformed or missing condition-spec would otherwise silently let an order
		 * place without a mandatory pickup point.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $ids Shipping method ids, e.g. `[ 'carrier_pickup', 'carrier_pickup_express' ]`.
		 *
		 * @return void
		 */
		public function set_requires_pickup_methods( array $ids ): void {
			$this->requires_pickup_methods = array_values( $ids );
		}

		/**
		 * Wires the handler into the WooCommerce checkout.
		 *
		 * Hooks field injection onto `woocommerce_checkout_fields`, posted-data
		 * validation onto the `woocommerce_checkout_process` validation phase (so a
		 * failing field blocks checkout before any order is created), and the
		 * sanitize → validate → save pipeline onto `woocommerce_checkout_order_processed`
		 * — which fires AFTER the order is created and saved, so it has a real id and meta
		 * persistence works on BOTH classic and HPOS storage. (Persisting on
		 * `woocommerce_checkout_create_order` runs before the save: on classic storage the
		 * order id is still 0 and the meta is silently dropped.) Also enqueues frontend
		 * assets on `wp_enqueue_scripts` and registers the field-source REST route on
		 * `rest_api_init`. Not gated on `is_checkout()` so the REST route is available
		 * on API requests. Call once during plugin bootstrap.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {
			add_filter( 'woocommerce_checkout_fields', [ $this, 'handle_checkout_fields' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'handle_checkout_process' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );

			$this->guard_native_field_conflicts();
		}

		/**
		 * Returns the plugin token that identifies this handler.
		 *
		 * Exposes the constructor-injected `$hook_prefix` as a stable public accessor.
		 * Used to namespace the JS config global and the REST route plugin-id segment.
		 * Falls back to `'shipping'` when the prefix was left empty (anonymous handler).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function plugin_id(): string {
			return '' !== $this->hook_prefix ? $this->hook_prefix : 'shipping';
		}

		/**
		 * Returns a JS-identifier-safe version of the plugin id.
		 *
		 * Used as the suffix in the `woodev_checkout_field_config_{suffix}` global name
		 * so the name is always a valid JS identifier regardless of what the plugin
		 * supplies as its id token.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function config_object_suffix(): string {
			return preg_replace( '/[^a-z0-9_]/i', '_', $this->plugin_id() );
		}

		/**
		 * Enqueues the checkout-field store and classic adapter scripts.
		 *
		 * Only runs on the checkout page and only when there is at least one managed
		 * field. Localizes the full JS config (field descriptors, REST endpoint, nonce,
		 * takeover map, i18n strings) onto the classic adapter handle so it can
		 * bootstrap without any inline PHP.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {

			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return;
			}

			if ( [] === $this->fields->get_fields() ) {
				return;
			}

			$store_path   = self::asset_path( 'js/frontend/checkout-field-store.js' );
			$classic_path = self::asset_path( 'js/frontend/checkout-field-classic.js' );

			wp_enqueue_script(
				'woodev-checkout-field-store',
				self::asset_url( 'js/frontend/checkout-field-store.js' ),
				[],
				file_exists( $store_path ) ? (string) filemtime( $store_path ) : (string) \Woodev_Plugin::VERSION,
				true
			);

			wp_enqueue_script(
				'woodev-checkout-field-classic',
				self::asset_url( 'js/frontend/checkout-field-classic.js' ),
				[ 'jquery', 'selectWoo', 'woodev-checkout-field-store' ],
				file_exists( $classic_path ) ? (string) filemtime( $classic_path ) : (string) \Woodev_Plugin::VERSION,
				true
			);

			$config          = ( new Checkout_Config(
				$this->plugin_id(),
				rtrim( rest_url( 'woodev/v1' ), '/' ),
				wp_create_nonce( 'wp_rest' ),
				array_keys( WC()->countries->get_countries() )
			) )->build( $this->fields );
			$config['i18n']  = [
				'required' => __( 'Заполните обязательное поле.', 'woodev-plugin-framework' ),
			];

			wp_localize_script(
				'woodev-checkout-field-classic',
				'woodev_checkout_field_config_' . $this->config_object_suffix(),
				$config
			);
		}

		/**
		 * Registers the field-source REST route for this handler.
		 *
		 * Delegates to {@see Field_Source_Controller::register_routes()} so the route
		 * is available on all REST requests, not just checkout-page loads.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			( new \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller( $this->fields, $this->plugin_id() ) )->register_routes();
		}

		/**
		 * Warns when two handlers try to enhance the same native WC field.
		 *
		 * Maintains a static registry of native-field-id → plugin_id claims.
		 * If a field id that belongs to the WooCommerce billing/shipping address
		 * namespace (see {@see is_native_wc_field()}) is already registered by a
		 * different handler, fires `_doing_it_wrong` so the developer sees the conflict
		 * immediately. Last registration wins — the warning is advisory only.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function guard_native_field_conflicts(): void {

			foreach ( array_keys( $this->fields->get_fields() ) as $id ) {
				if ( ! $this->is_native_wc_field( $id ) ) {
					continue;
				}

				if ( isset( self::$native_field_registry[ $id ] ) && self::$native_field_registry[ $id ] !== $this->plugin_id() ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							"checkout field '%s' is enhanced by more than one shipping plugin; last registration wins",
							$id
						),
						'2.0.2'
					);
				}

				self::$native_field_registry[ $id ] = $this->plugin_id();
			}
		}

		/**
		 * Resets the static native-field registry.
		 *
		 * Provided for unit-test teardown so that tests that register handlers with
		 * conflicting native-field ids do not bleed state into subsequent tests.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_native_field_registry(): void {
			self::$native_field_registry = [];
		}

		/**
		 * Resolves the filesystem path to a shipping-framework asset.
		 *
		 * Mirrors {@see asset_url()} but returns a local path suitable for
		 * `filemtime()` and `file_exists()` checks.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory
		 *
		 * @return string absolute filesystem path to the asset
		 */
		private static function asset_path( string $relative ): string {
			return dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * This file lives in `checkout/`, a direct child of the shipping-method root;
		 * `assets/` is a sibling of that root. Resolving from this file keeps the
		 * handler self-contained — it needs no plugin instance to locate its assets.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory
		 *
		 * @return string absolute URL to the asset
		 */
		private static function asset_url( string $relative ): string {
			$file = self::asset_path( $relative );

			return plugins_url( basename( $file ), $file );
		}

		/**
		 * Injects the managed fields into the WooCommerce checkout fields.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param mixed $checkout_fields the WC checkout fields, keyed by section
		 *
		 * @return array<string, mixed>
		 */
		public function handle_checkout_fields( $checkout_fields ): array {
			return $this->inject( (array) $checkout_fields );
		}

		/**
		 * Validates the posted field values during the checkout validation phase.
		 *
		 * Runs while WooCommerce is still collecting validation errors, so a blank
		 * required field or a failing `validate_callback` halts checkout before an order
		 * exists.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Builds a `$state` map (chosen shipping method + billing country) and
		 *              passes it to `validate()` so conditional-required specs (A2) can be
		 *              resolved at validation time.
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			$state = [
				'chosen_shipping_method' => $this->chosen_shipping_method(),
				'country'                => $this->posted_country(),
			];
			$this->validate( $this->sanitize_posted_data( $this->get_posted_data() ), $state );
		}

		/**
		 * Sanitizes, validates and saves the posted values onto the created order.
		 *
		 * Fires on `woocommerce_checkout_order_processed`, AFTER the order has been saved,
		 * so it has a real id and meta persistence works on classic + HPOS storage. Only
		 * reached once validation has passed, so the re-validation inside
		 * {@see self::process()} adds no duplicate notices.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param int                  $order_id    the created order id (unused; the order object is used)
		 * @param array<string, mixed> $posted_data the posted checkout data (unused; raw post is read directly)
		 * @param \WC_Order            $order       the created, saved order
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ): void {
			$this->process( $this->get_posted_data(), $order );
		}

		/**
		 * Reads the raw posted checkout data.
		 *
		 * Returns the unslashed `$_POST`; per-field cleaning happens in
		 * {@see self::sanitize_posted_data()}. WooCommerce verifies the checkout nonce
		 * before its checkout hooks fire, so no separate nonce check is performed here.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, mixed>
		 */
		protected function get_posted_data(): array {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			return (array) wp_unslash( $_POST );
		}

		/**
		 * Injects the managed fields into a WooCommerce checkout-fields array.
		 *
		 * Each managed field is placed under its own `section` descriptor key
		 * (default `'order'`). When a field already exists in WooCommerce's array it
		 * is **enhanced in place**: only the keys this framework owns (`type`, `label`,
		 * `required`, plus `options` when pre-filled) are overridden; all other WC
		 * args (`class`, `priority`, `validate`, `custom_attributes`, …) are
		 * preserved unchanged via `array_merge( $existing, $our_overrides )`.
		 *
		 * For an options-kind root field (has a callable `source`, `source_kind ===
		 * 'options'`, `depends_on === null`) the source is invoked with the current
		 * customer billing country as context to pre-fill the native `<select>`
		 * `options` map (`[ value => label ]`). Dependent and suggest-kind fields
		 * receive their options dynamically via the field-source REST endpoint and
		 * are left without a static `options` key.
		 *
		 * The fully-merged result is passed through the forward `..._checkout_fields`
		 * filter so the host plugin can refine field args further.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Fields are grouped by their own `section`; existing WC args
		 *              are preserved (conservative merge); options-kind root fields
		 *              have their source() called to pre-fill the options map.
		 *
		 * @param array<string, mixed> $checkout_fields WC checkout fields, keyed by section
		 * @param string               $section         unused override kept for BC; per-field
		 *                                              `section` key is the primary path
		 *
		 * @return array<string, mixed>
		 */
		public function inject( array $checkout_fields, string $section = 'order' ): array {
			$country = $this->current_country();

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$field_section = '' !== ( $field['section'] ?? '' ) ? (string) $field['section'] : $section;

				if ( ! isset( $checkout_fields[ $field_section ] ) || ! is_array( $checkout_fields[ $field_section ] ) ) {
					$checkout_fields[ $field_section ] = [];
				}

				// Build only the keys we own. `required` is touched ONLY when the descriptor is
				// opinionated, so enhancing a native WC field never silently changes its required
				// flag (Codex review P1 + re-critic):
				// - a condition-spec (array) → WC static `false` (WC must not block a blank
				// conditional field regardless of the chosen method; our validate() + store
				// gate enforce conditional requiredness instead);
				// - an explicit bool `true` → WC `required`;
				// - a default/`false` required → leave WC's own required flag UNTOUCHED (e.g.
				// turning `billing_city` into a select must not un-require it).
				$our_overrides = [
					'type'  => (string) $field['type'],
					'label' => (string) $field['label'],
				];

				if ( is_array( $field['required'] ) ) {
					$our_overrides['required'] = false;
				} elseif ( true === $field['required'] ) {
					$our_overrides['required'] = true;
				}

				// Pre-fill options for root options-kind fields (source must be callable,
				// source_kind must be 'options', depends_on must be null).
				$is_options_root = null === $field['depends_on']
					&& 'options' === ( $field['source_kind'] ?? null )
					&& is_callable( $field['source'] ?? null );

				if ( $is_options_root ) {
					$raw_options = (array) ( $field['source'] )( [ 'country' => $country ] );
					$options_map = [];
					foreach ( $raw_options as $item ) {
						if ( is_array( $item ) && isset( $item['value'], $item['label'] ) ) {
							$options_map[ (string) $item['value'] ] = (string) $item['label'];
						}
					}
					$our_overrides['options'] = $options_map;
				}

				// Conservative merge: start from whatever WC already has for this field,
				// then overlay only our keys — preserving validate, class, priority, etc.
				$existing_wc_args                         = $checkout_fields[ $field_section ][ $id ] ?? [];
				$checkout_fields[ $field_section ][ $id ] = array_merge(
					is_array( $existing_wc_args ) ? $existing_wc_args : [],
					$our_overrides
				);
			}

			/**
			 * Filters the checkout fields after the managed fields are injected.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string, mixed> $checkout_fields the merged checkout fields
			 * @param string               $section         the primary section (legacy param, kept for BC)
			 */
			return (array) apply_filters( $this->hook( 'checkout_fields' ), $checkout_fields, $section );
		}

		/**
		 * Returns the current WooCommerce customer billing country.
		 *
		 * Returns an empty string when WC is not available (e.g. in unit tests).
		 * Override in subclasses or test doubles to supply a specific country code
		 * without bootstrapping WooCommerce.
		 *
		 * @since 2.0.2
		 *
		 * @return string ISO 3166-1 alpha-2 country code, or empty string.
		 */
		protected function current_country(): string {
			return ( function_exists( 'WC' ) && WC()->customer ) ? (string) WC()->customer->get_billing_country() : '';
		}

		/**
		 * Sanitizes posted checkout data for the managed fields.
		 *
		 * For each field, pulls its raw value from the posted data by id and runs it
		 * through the field's `sanitize_callback`, falling back to `wc_clean`. Fields
		 * absent from the post resolve to `''`.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 *
		 * @return array<string, mixed> clean values keyed by field id
		 */
		public function sanitize_posted_data( array $posted ): array {
			$clean = [];

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$raw      = $posted[ $id ] ?? '';
				$callback = $field['sanitize_callback'] ?? null;

				$clean[ $id ] = is_callable( $callback ) ? $callback( $raw ) : wc_clean( $raw );
			}

			return $clean;
		}

		/**
		 * Validates sanitized field values, blocking checkout on any failure.
		 *
		 * A required field that is blank fails. A field whose `validate_callback` returns
		 * `false` or a {@see \WP_Error} fails. Every failure adds a WooCommerce error
		 * notice — which halts checkout — and the method returns `false` overall.
		 *
		 * The `required` descriptor is resolved via {@see Checkout_Condition::is_required()}
		 * which handles both plain booleans and conditional condition-spec arrays (A2 gating).
		 * Pass `$state` with the runtime context (chosen shipping method, billing country) so
		 * condition-spec `required` values can be evaluated correctly.
		 *
		 * After the per-field loop an independent pickup backstop runs when
		 * {@see set_requires_pickup_methods()} has been called: if the chosen method is one of
		 * the declared pickup methods AND the `is_pickup_slot` field value is blank, checkout is
		 * blocked regardless of that field's condition-spec.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$state` parameter for conditional-required (A2) evaluation.
		 * @since 2.0.2 Added independent pickup backstop guard.
		 *
		 * @param array<string, mixed> $values clean values keyed by field id
		 * @param array<string, mixed> $state  flat checkout-state map, e.g.
		 *                                     `['chosen_shipping_method' => 'carrier_pickup', 'country' => 'RU']`
		 *
		 * @return bool true when every field is valid; false when any field blocks checkout
		 */
		public function validate( array $values, array $state = [] ): bool {
			$valid = true;

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$value    = $values[ $id ] ?? '';
				$required = Checkout_Condition::is_required( $field['required'], $state );

				if ( $required && self::is_blank( $value ) ) {
					$this->add_error( self::required_message( $field ) );
					$valid = false;
					continue;
				}

				$callback = $field['validate_callback'] ?? null;

				if ( self::is_blank( $value ) || ! is_callable( $callback ) ) {
					continue;
				}

				$result = $callback( $value, $field );

				if ( false === $result || $result instanceof \WP_Error ) {
					$message = $result instanceof \WP_Error ? (string) $result->get_error_message() : self::invalid_message( $field );
					$this->add_error( $message );
					$valid = false;
				}
			}

			// Independent pickup backstop: runs after the per-field loop regardless of spec.
			if ( [] !== $this->requires_pickup_methods ) {
				$chosen = (string) ( $state['chosen_shipping_method'] ?? '' );

				if ( self::chosen_method_matches( $chosen, $this->requires_pickup_methods ) ) {
					$pickup_field = null;

					foreach ( $this->fields->get_fields() as $field ) {
						if ( ! empty( $field['is_pickup_slot'] ) ) {
							$pickup_field = $field;
							break;
						}
					}

					if ( null !== $pickup_field ) {
						$pickup_value = $values[ $pickup_field['id'] ] ?? '';

						if ( self::is_blank( $pickup_value ) ) {
							$this->add_error(
								sprintf(
									/* translators: %s: pickup field label */
									__( '%s is required for pickup delivery.', 'woodev-plugin-framework' ),
									'' !== (string) $pickup_field['label'] ? (string) $pickup_field['label'] : (string) $pickup_field['id']
								)
							);
							$valid = false;
						}
					}
				}
			}

			return $valid;
		}

		/**
		 * Determines whether a chosen shipping method value matches any of the given method ids.
		 *
		 * WooCommerce passes the method value as either `method_id` (bare, when only one instance
		 * is configured) or `method_id:instance_id` (when multiple instances exist). This helper
		 * matches both shapes so plugins only need to register the base method id.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $chosen The `chosen_shipping_method` value from checkout state.
		 * @param string[] $ids    Method ids to match against (base ids without instance suffix).
		 *
		 * @return bool True when `$chosen` is or starts with one of the given ids.
		 */
		private static function chosen_method_matches( string $chosen, array $ids ): bool {
			foreach ( $ids as $id ) {
				if ( $chosen === $id || 0 === strpos( $chosen, $id . ':' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Saves the managed field values onto the order (HPOS-safe).
		 *
		 * Persists each value under the field id as the order-meta key via
		 * {@see self::persist_field()} → {@see \Woodev_Order_Compatibility::update_order_meta()}
		 * (the only persistence path, so HPOS and classic post-meta stores are both covered).
		 * Fires a per-field and a final forward hook so plugins can react to saved data.
		 *
		 * Fields whose id is a native WooCommerce address key (starts with `billing_` or
		 * `shipping_`) are skipped — WooCommerce already persists those as core order
		 * properties; writing them again as plugin meta double-stores the value and causes
		 * drift after edits/refunds. See {@see self::is_native_wc_field()}.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Native WC address fields (`billing_*` / `shipping_*`) are skipped.
		 *
		 * @param \WC_Order|int        $order  order object or id to save onto
		 * @param array<string, mixed> $values clean values keyed by field id
		 *
		 * @return void
		 */
		public function save( $order, array $values ): void {
			foreach ( $this->fields->get_fields() as $id => $field ) {
				if ( ! array_key_exists( $id, $values ) ) {
					continue;
				}

				// Skip native WC address fields — WooCommerce already persists these as core
				// order properties; adding our own meta would double-store and cause drift.
				if ( $this->is_native_wc_field( $id ) ) {
					continue;
				}

				$value = $values[ $id ];

				$this->persist_field( $order, $id, $value );

				/**
				 * Fires after a single checkout field value is saved to the order.
				 *
				 * @since 1.5.0
				 *
				 * @param \WC_Order|int $order the order saved onto
				 * @param string        $id    the field id (also the order-meta key)
				 * @param mixed         $value the saved value
				 */
				do_action( $this->hook( 'checkout_field_saved' ), $order, $id, $value );
			}

			/**
			 * Fires after all managed checkout fields are saved to the order.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order|int        $order  the order saved onto
			 * @param array<string, mixed> $values the saved values keyed by field id
			 */
			do_action( $this->hook( 'checkout_data_saved' ), $order, $values );
		}

		/**
		 * Runs posted data through the full sanitize → validate → save pipeline.
		 *
		 * Returns `false` without saving when validation blocks checkout, so the caller
		 * can abort the order. On success the values are persisted and a final forward
		 * hook fires.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Builds a `$state` map from the `$posted` data and threads it through
		 *              `validate()` for consistent conditional-required evaluation at save time.
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 * @param \WC_Order|int        $order  order object or id to save onto
		 *
		 * @return bool true when the data was valid and saved; false when checkout is blocked
		 */
		public function process( array $posted, $order ): bool {
			$values = $this->sanitize_posted_data( $posted );
			$state  = [
				'chosen_shipping_method' => wc_clean( (string) wp_unslash( $posted['shipping_method'][0] ?? '' ) ),
				'country'                => wc_clean( (string) wp_unslash( $posted['billing_country'] ?? '' ) ),
			];

			if ( ! $this->validate( $values, $state ) ) {
				return false;
			}

			$this->save( $order, $values );

			/**
			 * Fires after posted checkout data is sanitized, validated and saved.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order|int        $order  the order saved onto
			 * @param array<string, mixed> $values the saved values keyed by field id
			 */
			do_action( $this->hook( 'checkout_processed' ), $order, $values );

			return true;
		}

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 *
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		private function hook( string $name ): string {
			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}

		/**
		 * Adds a WooCommerce error notice when one is available.
		 *
		 * @since 1.5.0
		 *
		 * @param string $message the error message
		 *
		 * @return void
		 */
		private function add_error( string $message ): void {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Determines whether a value counts as blank for validation.
		 *
		 * Strings are blank when they trim to empty; everything else is blank only when
		 * `null` or an empty array. A literal `'0'` is therefore NOT blank.
		 *
		 * @since 1.5.0
		 *
		 * @param mixed $value the value to test
		 *
		 * @return bool
		 */
		private static function is_blank( $value ): bool {
			if ( is_string( $value ) ) {
				return '' === trim( $value );
			}

			return null === $value || [] === $value;
		}

		/**
		 * Builds the default "required field" error message for a descriptor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function required_message( array $field ): string {
			$label = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['id'];

			/* translators: %s: checkout field label */
			return sprintf( __( '%s is a required field.', 'woodev-plugin-framework' ), $label );
		}

		/**
		 * Builds the default "invalid value" error message for a descriptor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function invalid_message( array $field ): string {
			$label = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['id'];

			/* translators: %s: checkout field label */
			return sprintf( __( '%s is not valid.', 'woodev-plugin-framework' ), $label );
		}

		/**
		 * Returns the chosen shipping method for the first package from the posted data.
		 *
		 * WooCommerce posts `shipping_method` as a zero-indexed array keyed by package index;
		 * we take index 0 as the primary method. WooCommerce verifies the checkout nonce
		 * before its checkout hooks fire, so no separate nonce check is performed here.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized shipping method id, e.g. `carrier_pickup:3`, or empty string
		 */
		private function chosen_shipping_method(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			return wc_clean( (string) wp_unslash( $_POST['shipping_method'][0] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		/**
		 * Returns the billing country from the posted data.
		 *
		 * WooCommerce verifies the checkout nonce before its checkout hooks fire, so no
		 * separate nonce check is performed here.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized ISO 2-letter country code, or empty string
		 */
		private function posted_country(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			return wc_clean( (string) wp_unslash( $_POST['billing_country'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		/**
		 * Determines whether a field id belongs to the native WooCommerce address namespace.
		 *
		 * WooCommerce persists `billing_*` and `shipping_*` fields as core order properties
		 * via its own checkout pipeline. Writing them again as plugin order-meta would
		 * double-store the value and cause silent drift after order edits or refunds. This
		 * heuristic covers the two address namespaces that WC always owns; plugin-defined
		 * ids (e.g. `carrier_pickup_point`, `pvz_id`) never start with these prefixes.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id field id to test
		 *
		 * @return bool true when WooCommerce already persists this field natively
		 */
		protected function is_native_wc_field( string $id ): bool {
			return 0 === strpos( $id, 'billing_' ) || 0 === strpos( $id, 'shipping_' );
		}

		/**
		 * Persists a single field value onto the order via HPOS-safe meta storage.
		 *
		 * Extracted as a protected seam so subclasses (and unit-test spies) can intercept
		 * persistence without depending on {@see \Woodev_Order_Compatibility} in test contexts.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order|int $order order object or id to persist onto
		 * @param string        $id    field id used as the order-meta key
		 * @param mixed         $value the value to persist
		 *
		 * @return void
		 */
		protected function persist_field( $order, string $id, $value ): void {
			\Woodev_Order_Compatibility::update_order_meta( $order, $id, $value );
		}
	}

endif;
