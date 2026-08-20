<?php
/**
 * Woodev_Test_Credential_Seeder — bridges the rig's own wp-config constants into
 * store-level settings options: the DaData provider's flat `woodev_location_*`
 * options via {@see self::maybe_seed()}, and (issue #375) the CDEK Integration's
 * array-shaped `woocommerce_{plugin_id}_settings` option via
 * {@see self::maybe_seed_into_array_option()}.
 *
 * Location Provider layer, block PR-C rig-visibility pull-forward: without a token in
 * {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider}'s own store option,
 * {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()} never returns
 * `true` and neither the "Локация" settings page's token field nor any location-backed
 * checkout field ever does anything observable on the rig. Rather than requiring the
 * operator to paste a token into wp-admin by hand before PR-C's rig checklist is even
 * performable, this bridges `WOODEV_TEST_DADATA_TOKEN` (and `WOODEV_TEST_DADATA_SECRET`)
 * — rig-only wp-config constants, same idiom as `WOODEV_TEST_PICKUP_STRATEGY` and friends
 * in `woodev-test-shipping-method.php` — into the option.
 *
 * FRAMEWORK CODE never reads these constants (D4 of the location-provider spec: the token
 * is a store setting, not a framework concern) — only this FIXTURE bridges them into the
 * option the framework itself reads
 * ({@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::token()}).
 *
 * Extracted to its own file, and its decision split into a WordPress-call-free pure method
 * ({@see self::should_seed()}), so that decision is directly unit-testable without mocking
 * `get_option()`/`update_option()` — same "own file for direct testability" reasoning as
 * `class-test-bulk-point-source.php`'s own docblock, applied to the pure/impure split
 * instead of the WP-bootstrap split.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Credential_Seeder' ) ) {

	/**
	 * Class Woodev_Test_Credential_Seeder
	 */
	class Woodev_Test_Credential_Seeder {

		/**
		 * Decides whether a rig constant's value should be seeded into a store option.
		 *
		 * Pure — no WordPress calls — so this rule is unit-testable on its own. The rule:
		 * seed ONLY when the constant carries a non-empty value AND the option is
		 * currently empty. An operator who has typed a real token into wp-admin (or a
		 * value already seeded on a previous request) is NEVER overwritten — this is the
		 * idempotent, non-destructive half of the contract.
		 *
		 * @since 2.0.2
		 *
		 * @param string $constant_value  the rig constant's current value ('' when undefined
		 *                                or the constant itself is empty).
		 * @param string $existing_option the option's current stored value ('' when unset).
		 *
		 * @return bool
		 */
		public static function should_seed( string $constant_value, string $existing_option ): bool {
			return '' !== $constant_value && '' === $existing_option;
		}

		/**
		 * Seeds a single store option from a single rig wp-config constant, applying
		 * {@see self::should_seed()}'s rule.
		 *
		 * @since 2.0.2
		 *
		 * @param string $option_name   the store option to (maybe) write.
		 * @param string $constant_name the rig wp-config constant to read from.
		 *
		 * @return void
		 */
		public static function maybe_seed( string $option_name, string $constant_name ): void {
			$constant_value = defined( $constant_name ) ? (string) constant( $constant_name ) : '';
			$existing_value = (string) get_option( $option_name, '' );

			if ( self::should_seed( $constant_value, $existing_value ) ) {
				update_option( $option_name, $constant_value );
			}
		}

		/**
		 * Seeds ONE field of an ARRAY-shaped option from a single rig wp-config
		 * constant, applying {@see self::should_seed()}'s SAME rule (issue #375).
		 *
		 * WHY THIS EXISTS. {@see self::maybe_seed()} reads/writes a FLAT option —
		 * exactly the shape `Location_Settings`/the location-provider fields use
		 * (`woodev_location_{field_id}`). Once #375 moved the CDEK credentials out of
		 * the location-provider surface and into {@see Woodev_Test_Cdek_Integration}
		 * (a `WC_Integration`), their storage became ONE array-shaped option
		 * (`woocommerce_{plugin_id}_settings`, `WC_Settings_API::get_option_key()`'s
		 * own construction) holding EVERY field of that integration keyed by field
		 * id — a flat `get_option()`/`update_option()` pair on the field id alone
		 * would silently miss/clobber every sibling field. This method reads the
		 * whole array, applies {@see self::should_seed()} to just the one field
		 * key, and writes the array back — the array-option counterpart of
		 * {@see self::maybe_seed()}, reusing its exact pure decision rather than
		 * duplicating it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $option_name   the array-shaped store option to (maybe) update.
		 * @param string $field_key     the key WITHIN that array to (maybe) seed.
		 * @param string $constant_name the rig wp-config constant to read from.
		 *
		 * @return void
		 */
		public static function maybe_seed_into_array_option( string $option_name, string $field_key, string $constant_name ): void {
			$constant_value = defined( $constant_name ) ? (string) constant( $constant_name ) : '';
			$settings       = get_option( $option_name, [] );
			$settings       = is_array( $settings ) ? $settings : [];
			$existing_value = isset( $settings[ $field_key ] ) ? (string) $settings[ $field_key ] : '';

			if ( self::should_seed( $constant_value, $existing_value ) ) {
				$settings[ $field_key ] = $constant_value;
				update_option( $option_name, $settings );
			}
		}
	}
}
