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
		 * Wires the handler into the WooCommerce checkout.
		 *
		 * Hooks field injection onto `woocommerce_checkout_fields`, posted-data
		 * validation onto the `woocommerce_checkout_process` validation phase (so a
		 * failing field blocks checkout before any order is created), and the
		 * sanitize → validate → save pipeline onto `woocommerce_checkout_order_processed`
		 * — which fires AFTER the order is created and saved, so it has a real id and meta
		 * persistence works on BOTH classic and HPOS storage. (Persisting on
		 * `woocommerce_checkout_create_order` runs before the save: on classic storage the
		 * order id is still 0 and the meta is silently dropped.) Call once during plugin
		 * bootstrap. These are the standard WooCommerce checkout seams; no installed-site
		 * contract value is introduced.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {
			add_filter( 'woocommerce_checkout_fields', [ $this, 'handle_checkout_fields' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'handle_checkout_process' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );
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
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			$this->validate( $this->sanitize_posted_data( $this->get_posted_data() ) );
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
		 * Adds one entry per managed field under the given section (e.g. `order`),
		 * mapping the normalized descriptor to WooCommerce's field-args shape. Existing
		 * entries are preserved. The merged result is passed through the forward
		 * `..._checkout_fields` filter so the host plugin can refine field args.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $checkout_fields WC checkout fields, keyed by section
		 * @param string               $section         section to inject into; default `order`
		 *
		 * @return array<string, mixed>
		 */
		public function inject( array $checkout_fields, string $section = 'order' ): array {
			$section_fields = $checkout_fields[ $section ] ?? [];

			if ( ! is_array( $section_fields ) ) {
				$section_fields = [];
			}

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$section_fields[ $id ] = [
					'type'     => (string) $field['type'],
					'label'    => (string) $field['label'],
					'required' => (bool) $field['required'],
				];
			}

			$checkout_fields[ $section ] = $section_fields;

			/**
			 * Filters the checkout fields after the managed fields are injected.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string, mixed> $checkout_fields the merged checkout fields
			 * @param string               $section         the section that was injected into
			 */
			return (array) apply_filters( $this->hook( 'checkout_fields' ), $checkout_fields, $section );
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
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $values clean values keyed by field id
		 *
		 * @return bool true when every field is valid; false when any field blocks checkout
		 */
		public function validate( array $values ): bool {
			$valid = true;

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$value = $values[ $id ] ?? '';

				if ( $field['required'] && self::is_blank( $value ) ) {
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

			return $valid;
		}

		/**
		 * Saves the managed field values onto the order (HPOS-safe).
		 *
		 * Persists each value under the field id as the order-meta key via
		 * {@see \Woodev_Order_Compatibility::update_order_meta()} (the only persistence
		 * path, so HPOS and classic post-meta stores are both covered). Fires a per-field
		 * and a final forward hook so plugins can react to saved data.
		 *
		 * @since 1.5.0
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

				$value = $values[ $id ];

				\Woodev_Order_Compatibility::update_order_meta( $order, $id, $value );

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
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 * @param \WC_Order|int        $order  order object or id to save onto
		 *
		 * @return bool true when the data was valid and saved; false when checkout is blocked
		 */
		public function process( array $posted, $order ): bool {
			$values = $this->sanitize_posted_data( $posted );

			if ( ! $this->validate( $values ) ) {
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
	}

endif;
