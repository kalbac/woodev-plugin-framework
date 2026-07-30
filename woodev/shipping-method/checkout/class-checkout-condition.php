<?php
/**
 * Woodev Checkout Condition Evaluator
 *
 * Evaluates a conditional-required spec against a flat state map. The spec
 * grammar mirrors the s40 settings show_if grammar exactly so that the PHP
 * server-side evaluator and the forthcoming JS mirror (Task 10) stay in
 * semantic lock-step.
 *
 * Grammar (flat):
 *   Single-condition: { state, operator, value }
 *   Multi-condition:  { relation: 'AND'|'OR', conditions: [ {state,operator,value}, ... ] }
 *
 * Operators: =, !=, in, not_in  (string comparison; bool → '1'/'').
 *
 * Fail-open gate: malformed spec or unknown operator → false (never trap a
 * paying customer on a broken optional-field spec). This matches the JS mirror.
 *
 * @see docs-internal/specs/2026-07-06-checkout-field-layer-design.md §2 (decision 8), §11
 *
 * Pure PHP — no WooCommerce calls.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Condition' ) ) :

	/**
	 * Evaluates condition-spec arrays used in the `required` field descriptor.
	 *
	 * All methods are static — this class is a stateless function namespace.
	 *
	 * @since 2.0.2
	 */
	class Checkout_Condition {

		/**
		 * Determine whether a field is required given its `required` descriptor
		 * and the current checkout state.
		 *
		 * Rules:
		 * - Plain bool → returned as-is (passthrough gate).
		 * - Non-array / empty array → false.
		 * - Multi-condition: `conditions` key present → evaluate AND/OR relation.
		 *   Empty `conditions: []` → false (Codex parity: avoids every([])==true).
		 * - Single-condition: evaluate directly.
		 * - Unknown operator or malformed spec → false (fail-open).
		 *
		 * @since 2.0.2
		 *
		 * @param bool|array<string, mixed> $required Condition spec or plain bool.
		 * @param array<string, mixed>      $state    Flat key→value checkout state map.
		 *
		 * @return bool Whether the field is required.
		 */
		public static function is_required( $required, array $state ): bool {
			if ( is_bool( $required ) ) {
				return $required;
			}

			if ( ! is_array( $required ) || [] === $required ) {
				return false;
			}

			if ( isset( $required['conditions'] ) && is_array( $required['conditions'] ) ) {
				if ( [] === $required['conditions'] ) {
					return false;
				}

				$relation = strtoupper( (string) ( $required['relation'] ?? 'AND' ) );
				$results  = array_map(
					static fn( $c ) => is_array( $c ) && self::evaluate( $c, $state ),
					$required['conditions']
				);

				return 'OR' === $relation
					? in_array( true, $results, true )
					: ! in_array( false, $results, true );
			}

			return self::evaluate( $required, $state );
		}

		/**
		 * Evaluate a single { state, operator, value } condition triplet.
		 *
		 * Missing or non-scalar state values are normalised to '' by
		 * {@see self::scalar()}.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $c     Single condition: state/operator/value keys.
		 * @param array<string, mixed> $state Flat checkout state map.
		 *
		 * @return bool Result of the comparison, or false for unknown operators.
		 */
		private static function evaluate( array $c, array $state ): bool {
			$actual   = self::scalar( $state[ (string) ( $c['state'] ?? '' ) ] ?? '' );
			$operator = (string) ( $c['operator'] ?? '' );
			$value    = $c['value'] ?? '';

			switch ( $operator ) {
				case '=':
					return $actual === self::scalar( $value );
				case '!=':
					return $actual !== self::scalar( $value );
				case 'in':
					return is_array( $value ) && in_array( $actual, array_map( [ self::class, 'scalar' ], $value ), true );
				case 'not_in':
					return is_array( $value ) && ! in_array( $actual, array_map( [ self::class, 'scalar' ], $value ), true );
				default:
					return false; // fail-open gate: unknown operator → never required
			}
		}

		/**
		 * Coerce any scalar/bool value to a comparison string.
		 *
		 * - bool true  → '1'
		 * - bool false → ''
		 * - non-scalar (array, object, null) → ''
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $v Value to coerce.
		 *
		 * @return string Normalised string for comparison.
		 */
		private static function scalar( $v ): string {
			if ( is_bool( $v ) ) {
				return $v ? '1' : '';
			}

			return is_scalar( $v ) ? (string) $v : '';
		}
	}

endif;
