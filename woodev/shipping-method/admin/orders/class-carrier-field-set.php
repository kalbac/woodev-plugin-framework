<?php
/**
 * Shipping orders — a carrier's own order fields for one tariff
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Settings\Field_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Carrier_Field_Set' ) ) :

	/**
	 * The fields a carrier asks for when its tariff is put on an order by hand — declared value,
	 * package dimensions, extra services (#710 spec D7, O13) — as a real Settings API handler.
	 *
	 * A carrier declares them in PHP ({@see Orders_Provider::get_order_fields()}) with the SAME
	 * vocabulary as its settings page: a setting `type` and a control `type`, `name`,
	 * `description`, `options`, `default`, `required`, `validate`, `show_if`, `min` / `max` /
	 * `step`, `tooltip`, `placeholder` — the arguments of
	 * {@see \Woodev_Abstract_Settings::register_setting()} and `::register_control()`, plus one
	 * of the framework's own: `meta_key`, the order-meta key the value is stored under (the
	 * carrier's export reads it back from there). This class registers them on a handler that
	 * stores nothing of its own, so the three things the wizard needs all come from the code the
	 * settings page already runs:
	 *
	 *  - the field definitions the React `ControlField` renders ({@see Field_Schema}), so the
	 *    plugin ships no JS (Rule 9);
	 *  - the server-side check ({@see \Woodev_Setting::update_value()}) — required, format, range,
	 *    enum, the carrier's own `validate` callback and `show_if` visibility;
	 *  - the persistence: one order-meta key per field, through
	 *    {@see \Woodev_Order_Compatibility} so HPOS and post meta are both covered.
	 *
	 * A set belongs to ONE tariff: the declaration is asked for the chosen method, so a courier
	 * tariff and a pickup one may ask for different things. An invalid definition (no `meta_key`,
	 * an id that is not `[a-z0-9_]+`, a type or control the Settings API does not know) is
	 * logged and skipped — a carrier's typo must never take the wizard down.
	 *
	 * @since 2.0.2
	 */
	final class Carrier_Field_Set extends \Woodev_Abstract_Settings {

		/**
		 * The declaration as the carrier returned it: field id => definition.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private $declaration;

		/**
		 * Field id => order-meta key, for the definitions that were accepted.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, string>
		 */
		private $meta_keys = [];

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string                              $provider_id carrier id — names the handler.
		 * @param array<string, array<string, mixed>> $declaration field id => definition.
		 */
		public function __construct( string $provider_id, array $declaration ) {
			// Read by register_settings(), which the parent constructor calls.
			$this->declaration = $declaration;

			parent::__construct( 'shipping_order_fields_' . $provider_id );
		}

		/**
		 * The fields a provider declares for one tariff.
		 *
		 * Resolves the zone-instance method when the caller has none, so the declaration can read
		 * the method's own settings for its defaults.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider          $provider    the carrier.
		 * @param string                   $method_id   the bare shipping method id.
		 * @param int                      $instance_id the zone-instance id, 0 when unknown.
		 * @param \WC_Shipping_Method|null $method      the method instance when the caller already holds it.
		 * @return self an empty set when the carrier declares none, or its declaration fails.
		 */
		public static function for_rate( Orders_Provider $provider, string $method_id, int $instance_id, ?\WC_Shipping_Method $method = null ): self {
			if ( ! $provider->has_order_fields() ) {
				return new self( $provider->get_id(), [] );
			}

			if ( null === $method && $instance_id > 0 && class_exists( '\WC_Shipping_Zones' ) ) {
				$resolved = \WC_Shipping_Zones::get_shipping_method( $instance_id );
				$method   = $resolved instanceof \WC_Shipping_Method ? $resolved : null;
			}

			return new self(
				$provider->get_id(),
				$provider->get_order_fields(
					[
						'provider_id' => $provider->get_id(),
						'method_id'   => $method_id,
						'instance_id' => $instance_id,
						'rate_id'     => $instance_id > 0 ? $method_id . ':' . $instance_id : $method_id,
						'is_pickup'   => $method instanceof \Woodev\Framework\Shipping\Shipping_Method && $method->is_pickup_shipping(),
						'method'      => $method,
					]
				)
			);
		}

		/**
		 * Registers the accepted definitions as settings + controls.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function register_settings() {
			foreach ( $this->declaration as $id => $definition ) {
				$id = (string) $id;

				if ( ! is_array( $definition ) || ! $this->accepts( $id, $definition ) ) {
					continue;
				}

				$type    = $this->setting_type( $definition );
				$control = $this->control_type( $definition, $type );

				$registered = $this->register_setting(
					$id,
					$type,
					array_intersect_key(
						$definition,
						array_flip( [ 'name', 'description', 'is_multi', 'options', 'default', 'required', 'validate', 'validate_message', 'show_if' ] )
					)
				);

				$registered = $registered && $this->register_control(
					$id,
					$control,
					array_intersect_key(
						$definition,
						array_flip( [ 'name', 'description', 'options', 'min', 'max', 'step', 'tooltip', 'placeholder' ] )
					)
				);

				if ( ! $registered ) {
					$this->unregister_setting( $id );
					$this->log( sprintf( 'order field "%s" was not accepted by the Settings API and is skipped.', $id ) );

					continue;
				}

				$this->meta_keys[ $id ] = (string) $definition['meta_key'];
			}
		}

		/**
		 * Nothing is stored on a handler of this kind — a value lives on the order.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function load_settings() {
			// Intentionally empty: the parent would read an option per field.
		}

		/**
		 * Whether the carrier declared no usable field for this tariff.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_empty(): bool {
			return [] === $this->meta_keys;
		}

		/**
		 * The accepted field ids, in declaration order.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function field_ids(): array {
			return array_keys( $this->meta_keys );
		}

		/**
		 * The order-meta keys the fields are stored under, keyed by field id.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		public function meta_keys(): array {
			return $this->meta_keys;
		}

		/**
		 * The definitions the React `ControlField` renders, in declaration order.
		 *
		 * A LIST, not an id-keyed map: a JSON object does not promise the order of integer-like keys,
		 * and the carrier decides what the manager sees first. Each entry is a
		 * {@see Field_Schema} entry plus its `id`; its `value` is the declared default.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function to_schema(): array {
			$schema = Field_Schema::from_handler( $this );
			$list   = [];

			foreach ( array_keys( $this->meta_keys ) as $id ) {
				if ( isset( $schema[ $id ] ) ) {
					$list[] = [ 'id' => $id ] + $schema[ $id ];
				}
			}

			return $list;
		}

		/**
		 * Checks and normalises what the wizard sent for these fields.
		 *
		 * Only declared ids are read — anything else never reaches the order. A field left out of
		 * the request takes its declared default (so a required field with none is reported), a
		 * field hidden by its `show_if` is neither checked nor kept, and the rest goes through
		 * {@see \Woodev_Setting::update_value()}: coerced to its type and validated the way the
		 * settings page validates it. Problems come back as data, never as notices.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw what the request carried under `carrier_fields`.
		 * @return array{values: array<string, mixed>, errors: array<int, array{field: string, code: string, message: string}>}
		 *         `values` holds the visible fields, valid ones only.
		 */
		public function normalize( array $raw ): array {
			$submitted = [];

			foreach ( array_keys( $this->meta_keys ) as $id ) {
				$setting = $this->get_setting( $id );

				if ( array_key_exists( $id, $raw ) ) {
					$submitted[ $id ] = self::clean( $raw[ $id ], $setting );
				} elseif ( null !== $setting->get_default() ) {
					$submitted[ $id ] = $setting->get_default();
				} else {
					$submitted[ $id ] = $setting->is_is_multi() ? [] : '';
				}
			}

			$values = [];
			$errors = [];

			foreach ( $this->filter_visible_values( $submitted ) as $id => $value ) {
				$setting = $this->get_setting( (string) $id );

				try {
					$setting->update_value( $value );
					$values[ $id ] = $setting->get_value();
				} catch ( \Woodev_Plugin_Exception $exception ) {
					$errors[] = [
						'field'   => 'carrier_fields.' . $id,
						'code'    => 'invalid_carrier_field',
						'message' => $exception->getMessage(),
					];
				}
			}

			return [
				'values' => $values,
				'errors' => $errors,
			];
		}

		/**
		 * Writes the values onto the order — one meta key per field. An empty value (or a field
		 * absent from `$values`, e.g. hidden by its `show_if`) removes the key, so an edit that
		 * clears a field really clears it.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order  the saved order.
		 * @param array<string, mixed> $values a {@see self::normalize()} `values` map.
		 * @return void
		 */
		public function persist( \WC_Order $order, array $values ): void {
			foreach ( $this->meta_keys as $id => $meta_key ) {
				$value = array_key_exists( $id, $values ) ? $this->to_storage( $this->get_setting( $id ), $values[ $id ] ) : null;

				if ( null === $value ) {
					if ( ! self::is_blank( \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key ) ) ) {
						\Woodev_Order_Compatibility::delete_order_meta( $order, $meta_key );
					}

					continue;
				}

				\Woodev_Order_Compatibility::update_order_meta( $order, $meta_key, $value );
			}
		}

		/**
		 * Removes the stored values of this set's fields whose meta key the new tariff does not use —
		 * what an edit that moves an order to another tariff leaves behind otherwise.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @param string[]  $keep  order-meta keys that stay (the new tariff's own).
		 * @return void
		 */
		public function forget_except( \WC_Order $order, array $keep ): void {
			foreach ( array_diff( $this->meta_keys, $keep ) as $meta_key ) {
				if ( ! self::is_blank( \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key ) ) ) {
					\Woodev_Order_Compatibility::delete_order_meta( $order, $meta_key );
				}
			}
		}

		/**
		 * Reads the stored values back — the inverse of {@see self::persist()}, what an edit opens with.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return array<string, mixed> field id => value, stored fields only.
		 */
		public function read( \WC_Order $order ): array {
			$values = [];

			foreach ( $this->meta_keys as $id => $meta_key ) {
				$stored = \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key );

				if ( self::is_blank( $stored ) ) {
					continue;
				}

				$setting = $this->get_setting( $id );

				if ( \Woodev_Setting::TYPE_BOOLEAN === $setting->get_type() && ! $setting->is_is_multi() ) {
					$stored = in_array( $stored, [ 'yes', '1', 1, true ], true );
				}

				$values[ $id ] = $stored;
			}

			return $values;
		}

		/**
		 * Whether a definition can be registered — logs why not.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $id         the field id.
		 * @param array<string, mixed> $definition the definition.
		 * @return bool
		 */
		private function accepts( string $id, array $definition ): bool {
			if ( '' === $id || 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) ) {
				$this->log( sprintf( 'order field id "%s" is not [a-z0-9_]+ and is skipped.', $id ) );

				return false;
			}

			if ( ! isset( $definition['meta_key'] ) || ! is_string( $definition['meta_key'] ) || '' === $definition['meta_key'] ) {
				$this->log( sprintf( 'order field "%s" declares no "meta_key" (the order-meta key its value is stored under) and is skipped.', $id ) );

				return false;
			}

			return true;
		}

		/**
		 * The Settings API type of a definition: what it says, else what its control implies.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $definition the definition.
		 * @return string
		 */
		private function setting_type( array $definition ): string {
			if ( isset( $definition['type'] ) && is_string( $definition['type'] ) ) {
				return $definition['type'];
			}

			switch ( $definition['control'] ?? '' ) {
				case \Woodev_Control::TYPE_TOGGLE:
				case \Woodev_Control::TYPE_CHECKBOX:
					return \Woodev_Setting::TYPE_BOOLEAN;
				case \Woodev_Control::TYPE_NUMBER:
				case \Woodev_Control::TYPE_RANGE:
					return \Woodev_Setting::TYPE_FLOAT;
				case \Woodev_Control::TYPE_EMAIL:
					return \Woodev_Setting::TYPE_EMAIL;
				case \Woodev_Control::TYPE_URL:
					return \Woodev_Setting::TYPE_URL;
			}

			return \Woodev_Setting::TYPE_STRING;
		}

		/**
		 * The control of a definition: what it says, else what its setting type (and options) imply.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $definition the definition.
		 * @param string               $type       the setting type.
		 * @return string
		 */
		private function control_type( array $definition, string $type ): string {
			if ( isset( $definition['control'] ) && is_string( $definition['control'] ) ) {
				return $definition['control'];
			}

			if ( ! empty( $definition['options'] ) ) {
				return ! empty( $definition['is_multi'] ) ? \Woodev_Control::TYPE_MULTISELECT : \Woodev_Control::TYPE_SELECT;
			}

			switch ( $type ) {
				case \Woodev_Setting::TYPE_BOOLEAN:
					return \Woodev_Control::TYPE_TOGGLE;
				case \Woodev_Setting::TYPE_INTEGER:
				case \Woodev_Setting::TYPE_FLOAT:
					return \Woodev_Control::TYPE_NUMBER;
				case \Woodev_Setting::TYPE_EMAIL:
					return \Woodev_Control::TYPE_EMAIL;
				case \Woodev_Setting::TYPE_URL:
					return \Woodev_Control::TYPE_URL;
			}

			return \Woodev_Control::TYPE_TEXT;
		}

		/**
		 * A request value cleaned the way its control needs; scalars and lists of scalars only.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed           $value   the received value.
		 * @param \Woodev_Setting $setting the field's setting.
		 * @return mixed
		 */
		private static function clean( $value, \Woodev_Setting $setting ) {
			if ( is_array( $value ) ) {
				return array_values(
					array_map(
						static function ( $element ) use ( $setting ) {
							return self::clean( $element, $setting );
						},
						array_filter( $value, 'is_scalar' )
					)
				);
			}

			if ( ! is_scalar( $value ) ) {
				return '';
			}

			if ( ! is_string( $value ) ) {
				return $value;
			}

			$control = $setting->get_control();
			$kind    = $control instanceof \Woodev_Control ? $control->get_type() : '';

			if ( \Woodev_Control::TYPE_TEXTAREA === $kind ) {
				return sanitize_textarea_field( $value );
			}

			// A richtext value is sanitised by the setting itself (wp_kses_post).
			return \Woodev_Control::TYPE_RICHTEXT === $kind ? $value : wc_clean( $value );
		}

		/**
		 * The value as it is stored in the order meta; null when there is nothing to store.
		 *
		 * A boolean is stored `yes` / `no` — the Settings API's own storage contract.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Setting $setting the field's setting.
		 * @param mixed           $value   the normalised value.
		 * @return mixed
		 */
		private function to_storage( \Woodev_Setting $setting, $value ) {
			if ( \Woodev_Setting::TYPE_BOOLEAN === $setting->get_type() && ! $setting->is_is_multi() ) {
				return is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( in_array( $value, [ 'yes', '1', 1 ], true ) ? 'yes' : 'no' );
			}

			return self::is_blank( $value ) ? null : $value;
		}

		/**
		 * Whether a stored or submitted value says nothing.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the value.
		 * @return bool
		 */
		private static function is_blank( $value ): bool {
			return null === $value || false === $value || '' === $value || [] === $value;
		}

		/**
		 * Diagnostic line — a broken declaration is a carrier plugin's bug the manager cannot fix.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message what went wrong.
		 * @return void
		 */
		private function log( string $message ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier plugin's order-field declaration.
			error_log( sprintf( '[woodev] carrier "%1$s": %2$s', substr( $this->get_id(), strlen( 'shipping_order_fields_' ) ), $message ) );
		}
	}

endif;
