<?php
/**
 * Settings field-schema builder.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON field schema a React control consumes from a settings handler.
 *
 * Single source of truth for the field-schema shape shared by the settings page
 * and the setup wizard (controlType / options / value / tooltip / min / max /
 * step / is_multi / description / name / type).
 *
 * @since 2.0.2
 */
final class Field_Schema {

	/**
	 * Resolves the field schema for the given handler.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Abstract_Settings $handler     settings handler.
	 * @param string[]                  $setting_ids optional subset of setting ids; empty = all.
	 * @return array<string,array<string,mixed>> schema keyed by setting id.
	 */
	public static function from_handler( $handler, array $setting_ids = [] ): array {
		$schema = [];

		foreach ( $handler->get_settings( $setting_ids ) as $setting ) {
			$control = $setting->get_control();

			// Mask secrets: sensitive fields and constant-backed fields never emit
			// their stored value to the browser — only whether a value is present.
			// A field declaring a constant_name is secret-bearing regardless of
			// whether the constant is currently defined: when undefined it falls
			// back to the stored option, which must still never be emitted.
			$constant_name    = $setting->get_constant_name();
			$has_constant     = null !== $constant_name;
			$constant_managed = $has_constant && defined( $constant_name );
			$is_secret        = $setting->is_sensitive() || $has_constant;
			$stored           = $handler->get_value( $setting->get_id() );
			$is_set           = '' !== (string) ( is_array( $stored ) ? implode( '', $stored ) : $stored );

			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $is_secret ? '' : $stored,
				'is_multi'    => $setting->is_is_multi(),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control && $control->get_description() ? $control->get_description() : $setting->get_description(),
				'tooltip'     => $control ? $control->get_tooltip() : '',
				'placeholder' => $control ? $control->get_placeholder() : '',
				'required'    => $setting->is_required(),
			];

			// Any secret (declared sensitive OR constant-backed) is masked in the UI
			// via the password control; a defined constant additionally renders the
			// read-only wp-config note (ControlField checks constant_managed first).
			if ( $is_secret ) {
				$entry['sensitive'] = true;
				$entry['is_set']    = $is_set;
			}
			if ( $constant_managed ) {
				$entry['constant_managed'] = true;
				$entry['constant_name']    = $constant_name;
			}

			if ( $control && null !== $control->get_min() ) {
				$entry['min'] = $control->get_min();
			}
			if ( $control && null !== $control->get_max() ) {
				$entry['max'] = $control->get_max();
			}
			if ( $control && null !== $control->get_step() ) {
				$entry['step'] = $control->get_step();
			}

			$schema[ $setting->get_id() ] = $entry;
		}

		return $schema;
	}
}
