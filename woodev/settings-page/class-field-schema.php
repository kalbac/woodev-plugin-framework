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
 * and the setup wizard (controlType / options / value / tooltip / placeholder /
 * min / max / step / is_multi / description / name / type / required).
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
	 * @param string                    $provider_id exposed tab id for search routes; blank = handler id.
	 * @return array<string,array<string,mixed>> schema keyed by setting id.
	 */
	public static function from_handler( $handler, array $setting_ids = [], string $provider_id = '' ): array {
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
			// What the form SHOWS may differ from what is stored (a mode the runtime
			// clamps on read); a save posts the shown value. Secrets never get here.
			$shown = $is_secret ? $stored : $handler->get_display_value( $setting->get_id() );

			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $is_secret ? '' : $shown,
				'is_multi'    => $setting->is_is_multi(),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control && $control->get_description() ? $control->get_description() : $setting->get_description(),
				'tooltip'     => $control ? $control->get_tooltip() : '',
				'placeholder' => $control ? $control->get_placeholder() : '',
				'required'    => $setting->is_required(),
			];

			if ( $control && \Woodev_Control::TYPE_TEXTAREA === $control->get_type() && null !== $control->get_rows() ) {
				$entry['rows'] = $control->get_rows();
			}

			// A toggle's ON-only line travels only when one was declared.
			if ( $control && '' !== $control->get_description_on() ) {
				$entry['description_on'] = $control->get_description_on();
			}

			// Only `TYPE_LOCATION_PICKER` controls carry a resolved store country
			// (issue #376) — every other control's `Woodev_Control::$country`
			// stays '', so this key is omitted rather than shipping a meaningless
			// empty string on every other field's schema entry.
			if ( $control && '' !== $control->get_country() ) {
				$entry['country'] = $control->get_country();
			}

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

			// Opt-in: only a control that asked for it renders min / max / step on the DOM input.
			if ( $control && $control->is_native_bounds() ) {
				$entry['native_bounds'] = true;
			}

			if ( null !== $setting->get_validate() ) {
				$entry['server_validated'] = true;
			}

			if ( $control && \Woodev_Control::TYPE_SEARCH_SELECT === $control->get_type() ) {
				$entry['value_label'] = $is_secret ? '' : $control->get_value_label( $shown );
				$entry['search_url']  = rest_url( 'woodev/v1/settings/' . rawurlencode( '' !== $provider_id ? $provider_id : $handler->get_id() ) . '/control/' . rawurlencode( $setting->get_id() ) . '/search' );
			}
			if ( $control && \Woodev_Control::TYPE_BOXES_TABLE === $control->get_type() ) {
				$entry['dimension_factor'] = (float) wc_get_dimension( 1, 'cm' );
				$entry['weight_factor']    = (float) wc_get_weight( 1, 'kg' );
			}

			if ( $control && [] !== $control->get_box_preset() ) {
				$entry['box_preset'] = $control->get_box_preset();
			}

			$disabled_if = $setting->get_disabled_if_conditions();
			if ( ! empty( $disabled_if ) ) {
				$entry['disabled_if'] = $disabled_if;
			}

			$show_if = $setting->get_show_if_conditions();
			if ( ! empty( $show_if ) ) {
				$entry['show_if'] = $show_if;
			}

			// `disabled_reason` is its own schema key — it must never overwrite the
			// authored `description`. Both are legitimate at once: the description
			// explains what the option does, the reason explains why it is
			// currently unavailable. The React client renders both distinctly.
			if ( $control && ( $control->is_disabled() || ! empty( $disabled_if ) ) ) {
				$entry['disabled']        = $control->is_disabled();
				$entry['disabled_reason'] = $control->get_disabled_reason();
			}

			$schema[ $setting->get_id() ] = $entry;
		}

		return $schema;
	}
}
