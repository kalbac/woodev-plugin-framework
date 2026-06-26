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

			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $handler->get_value( $setting->get_id() ),
				'is_multi'    => $setting->is_is_multi(),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control && $control->get_description() ? $control->get_description() : $setting->get_description(),
				'tooltip'     => $control ? $control->get_tooltip() : '',
			];

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
