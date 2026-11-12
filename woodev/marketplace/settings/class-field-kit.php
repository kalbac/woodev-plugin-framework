<?php
/**
 * Registration helpers for the marketplace settings screen's fields.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a setting together with its control on a {@see Settings_Handler}.
 *
 * Shared by {@see Accounts_Sections} (the fields of every account) and {@see Defaults_Section} (the same price-rule and exchange
 * fields as defaults), so one definition of a field serves both. `$setting_extra` is merged into the setting's arguments, which is
 * where a `show_if` condition goes.
 *
 * @since 2.1.5
 */
final class Field_Kit {
	/**
	 * Builds one `{value, label}` pair for `Woodev_Setting::set_options()`.
	 *
	 * @since 2.1.5
	 *
	 * @param string $value Option value.
	 * @param string $label Option label.
	 * @return array{value:string,label:string}
	 */
	public static function option( string $value, string $label ): array {
		return [
			'value' => $value,
			'label' => $label,
		];
	}

	/**
	 * Registers a setting + control on `$handler`.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler    $handler      Handler.
	 * @param string              $setting_id   Setting id.
	 * @param string              $setting_type `Woodev_Setting::TYPE_*`.
	 * @param array<string,mixed> $setting_args Setting args.
	 * @param string              $control_type `Woodev_Control::TYPE_*`.
	 * @param array<string,mixed> $control_args Control args.
	 * @return void
	 */
	public static function add(
		Settings_Handler $handler,
		string $setting_id,
		string $setting_type,
		array $setting_args,
		string $control_type,
		array $control_args = []
	): void {
		$handler->register_setting(
			$setting_id,
			$setting_type,
			$setting_args
		);
		$handler->register_control(
			$setting_id,
			$control_type,
			array_merge( [ 'label' => $setting_args['name'] ?? '' ], $control_args )
		);
	}

	/**
	 * Registers a text input.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler    $handler       Handler.
	 * @param string              $setting_id    Setting id.
	 * @param string              $label         Label.
	 * @param string              $default       Default value.
	 * @param string              $tooltip       Tooltip.
	 * @param string              $placeholder   Placeholder.
	 * @param array<string,mixed> $setting_extra Extra setting args (`show_if`).
	 * @return void
	 */
	public static function add_text( Settings_Handler $handler, string $setting_id, string $label, string $default, string $tooltip, string $placeholder, array $setting_extra = [] ): void {
		self::add(
			$handler,
			$setting_id,
			\Woodev_Setting::TYPE_STRING,
			array_merge(
				[
					'name'    => $label,
					'default' => $default,
				],
				$setting_extra
			),
			\Woodev_Control::TYPE_TEXT,
			[
				'tooltip'     => $tooltip,
				'placeholder' => $placeholder,
			]
		);
	}

	/**
	 * Registers a select control with explicit `{value, label}` pairs.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler                            $handler       Handler.
	 * @param string                                      $setting_id    Setting id.
	 * @param string                                      $label         Label.
	 * @param array<int,array{value:string,label:string}> $options       Option pairs.
	 * @param string                                      $tooltip       Tooltip.
	 * @param array<string,mixed>                         $setting_extra Extra setting args (`show_if`).
	 * @return void
	 */
	public static function add_select( Settings_Handler $handler, string $setting_id, string $label, array $options, string $tooltip = '', array $setting_extra = [] ): void {
		self::add(
			$handler,
			$setting_id,
			\Woodev_Setting::TYPE_STRING,
			array_merge(
				[
					'name'    => $label,
					'default' => $options[0]['value'] ?? '',
					'options' => $options,
				],
				$setting_extra
			),
			\Woodev_Control::TYPE_SELECT,
			array_filter(
				[
					'options' => $options,
					'tooltip' => $tooltip,
				],
				static function ( $v ): bool {
					return '' !== $v && [] !== $v;
				}
			)
		);
	}

	/**
	 * Registers a boolean toggle control.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler    $handler       Handler.
	 * @param string              $setting_id    Setting id.
	 * @param string              $label         Label.
	 * @param bool                $default       Default boolean state.
	 * @param string              $tooltip       Tooltip.
	 * @param array<string,mixed> $setting_extra Extra setting args (`show_if`).
	 * @return void
	 */
	public static function add_toggle( Settings_Handler $handler, string $setting_id, string $label, bool $default, string $tooltip = '', array $setting_extra = [] ): void {
		self::add(
			$handler,
			$setting_id,
			\Woodev_Setting::TYPE_BOOLEAN,
			array_merge(
				[
					'name'    => $label,
					'default' => $default,
				],
				$setting_extra
			),
			\Woodev_Control::TYPE_TOGGLE,
			array_filter( [ 'tooltip' => $tooltip ], 'strlen' )
		);
	}
}
