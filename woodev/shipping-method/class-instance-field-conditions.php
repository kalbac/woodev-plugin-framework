<?php
/**
 * «Show this field when…» for the instance forms of Woodev shipping methods.
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Instance_Field_Conditions' ) ) :

	/**
	 * Turns the `show_if` declaration of a WooCommerce `form_fields` entry into a data attribute
	 * that `instance-field-conditions.js` evaluates in the browser.
	 *
	 * The declaration has the shape of the settings API's `show_if` ({@see \Woodev\Framework\Settings_API\Setting::evaluate_conditions()}):
	 * one condition, or a list of conditions with an optional `relation` of `AND` (default) or `OR`.
	 * A condition is `[ 'setting' => <form field key>, 'operator' => '=' | '!=' | 'in' | 'not_in', 'value' => … ]`;
	 * `operator` defaults to `=`. The `setting` is the UNPREFIXED key as it is written in `form_fields`;
	 * the WooCommerce id (`woocommerce_{method_id}_{key}`) is resolved here, so a plugin never spells it.
	 * A checkbox is compared as `yes` / `no`. The whole declaration may be a `Closure` returning the array,
	 * called when the form is built (not when the method is constructed) — for a condition that depends on
	 * a stored option.
	 *
	 * A declaration that cannot be honoured — malformed, an unknown operator or controlling field, a field
	 * that depends on itself — is dropped as a whole and the field stays visible: a broken rule must never
	 * hide a setting the merchant needs. Hiding is presentation only: a hidden field is still submitted and
	 * keeps its saved value.
	 *
	 * @since 2.0.2
	 */
	final class Instance_Field_Conditions {

		/** Attribute carrying the JSON condition on the field's control. */
		public const ATTRIBUTE = 'data-woodev-show-if';

		/** Operators the browser handler knows — the same four the settings API evaluates. */
		private const OPERATORS = [ '=', '!=', 'in', 'not_in' ];

		/**
		 * Adds {@see self::ATTRIBUTE} to every field that declares a usable `show_if`.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $fields   form fields, key => definition.
		 * @param callable            $field_id maps a field key to the id of its control (`WC_Settings_API::get_field_key()`).
		 * @return array<string,mixed>
		 */
		public static function apply( array $fields, callable $field_id ): array {

			foreach ( $fields as $key => $field ) {

				if ( ! is_array( $field ) || ! isset( $field['show_if'] ) ) {
					continue;
				}

				$encoded = self::encode( $field['show_if'], (string) $key, array_map( 'strval', array_keys( $fields ) ), $field_id );

				if ( null === $encoded ) {
					continue;
				}

				if ( ! isset( $field['custom_attributes'] ) || ! is_array( $field['custom_attributes'] ) ) {
					$field['custom_attributes'] = [];
				}

				$field['custom_attributes'][ self::ATTRIBUTE ] = $encoded;
				$fields[ $key ]                                = $field;
			}

			return $fields;
		}

		/**
		 * Validates a declaration and returns the JSON the browser reads, or null when it is to be ignored.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed    $declaration the field's `show_if`.
		 * @param string   $own_key     key of the field that declares it.
		 * @param string[] $known_keys  every key of the form.
		 * @param callable $field_id    maps a field key to the id of its control.
		 * @return string|null
		 */
		public static function encode( $declaration, string $own_key, array $known_keys, callable $field_id ): ?string {

			if ( $declaration instanceof \Closure ) {
				$declaration = $declaration( $own_key );
			}

			if ( ! is_array( $declaration ) || [] === $declaration ) {
				return null;
			}

			// a single bare condition is a one-condition group, as in the settings API
			if ( isset( $declaration['setting'] ) ) {
				$declaration = [ $declaration ];
			}

			$relation = 'AND';

			if ( isset( $declaration['relation'] ) ) {
				$relation = is_string( $declaration['relation'] ) ? strtoupper( $declaration['relation'] ) : '';

				if ( 'AND' !== $relation && 'OR' !== $relation ) {
					return null;
				}
			}

			$conditions = [];

			foreach ( $declaration as $key => $condition ) {

				if ( 'relation' === $key ) {
					continue;
				}

				$normalized = self::normalize_condition( $condition, $own_key, $known_keys, $field_id );

				if ( null === $normalized ) {
					return null;
				}

				$conditions[] = $normalized;
			}

			if ( [] === $conditions ) {
				return null;
			}

			$json = wp_json_encode(
				[
					'relation'   => $relation,
					'conditions' => $conditions,
				]
			);

			return is_string( $json ) ? $json : null;
		}

		/**
		 * One condition in its wire form, or null when it is invalid.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed    $condition  raw condition.
		 * @param string   $own_key    key of the declaring field.
		 * @param string[] $known_keys every key of the form.
		 * @param callable $field_id   maps a field key to the id of its control.
		 * @return array{field:string,operator:string,value:string|string[]}|null
		 */
		private static function normalize_condition( $condition, string $own_key, array $known_keys, callable $field_id ): ?array {

			if ( ! is_array( $condition ) || ! isset( $condition['setting'] ) || ! is_string( $condition['setting'] ) ) {
				return null;
			}

			$setting  = $condition['setting'];
			$operator = $condition['operator'] ?? '=';

			if ( '' === $setting || $setting === $own_key || ! in_array( $setting, $known_keys, true ) ) {
				return null;
			}

			if ( ! is_string( $operator ) || ! in_array( $operator, self::OPERATORS, true ) ) {
				return null;
			}

			$value = $condition['value'] ?? ( in_array( $operator, [ 'in', 'not_in' ], true ) ? null : '' );

			if ( in_array( $operator, [ 'in', 'not_in' ], true ) ) {

				$list = is_array( $value ) ? $value : ( is_scalar( $value ) ? [ $value ] : [] );

				// every member is compared as a string, so PHP's `137` and the browser's «137» are the same value
				if ( [] === $list || array_filter( $list, 'is_scalar' ) !== $list ) {
					return null;
				}

				$value = array_values(
					array_map(
						static function ( $member ): string {
							return self::stringify( $member );
						},
						$list
					)
				);

			} elseif ( is_scalar( $value ) ) {
				$value = self::stringify( $value );
			} else {
				return null;
			}

			return [
				'field'    => (string) call_user_func( $field_id, $setting ),
				'operator' => $operator,
				'value'    => $value,
			];
		}

		/**
		 * A scalar as the string the browser compares it as (a boolean is `yes` / `no`, the way a checkbox is stored).
		 *
		 * @since 2.0.2
		 *
		 * @param bool|int|float|string $value scalar.
		 * @return string
		 */
		private static function stringify( $value ): string {
			return is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
		}
	}

endif;
