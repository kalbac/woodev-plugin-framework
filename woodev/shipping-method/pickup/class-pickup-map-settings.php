<?php
/**
 * Woodev Pickup Map Settings
 *
 * Store-level settings handler owning the «Карта» section of the «Доставка» tab
 * (design S1/S9). Registered with the `pickup_map` option namespace
 * (`woodev_pickup_map_*`) so it never collides with `Location_Settings`'s
 * `woodev_location_*` options.
 *
 * Task 8 (issue #362, design S7) fills in the three pickup map behaviour settings:
 * `pickup_button_placement`, `pickup_replace_address`, `pickup_close_on_select`. All
 * three are STORE decisions, never a carrier's — a customer sees them across every
 * carrier on the same checkout at once, so a per-carrier answer would put the trigger
 * in different places, or replace the address for one carrier and not another, on the
 * same page. {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler}'s own
 * `$replace_address`/`$close_on_select` constructor arguments are removed accordingly
 * (clean-break v2 line, ADR-005) — see that class's own docblock.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Map_Settings' ) ) :

	/**
	 * Settings handler for the pickup map behaviour («Карта» section). Owns three
	 * settings the store decides for every carrier at once (design S7, issue #362):
	 * button placement, address replacement, close-on-select. See
	 * {@see self::register_settings()} for why these three, specifically, are store-level
	 * rather than carrier-level.
	 *
	 * @since 2.0.2
	 */

	class Pickup_Map_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Setting id of the store's accent colour (issue #379). The id is the one the
		 * framework has always used for this field — only its home changed.
		 */
		public const SETTING_ACCENT_COLOR = 'pickup_accent_color';

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 */
		public function __construct() {
			parent::__construct( 'pickup_map' );
		}

		/**
		 * Returns the live handler, reached through the tab singleton rather than
		 * constructed directly — mirrors the shape a shared, store-level setting needs:
		 * every reader (Task 8's {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::resolve_pickup_slot_placements()}
		 * and {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::get_js_config()}) must
		 * see the SAME instance the admin screen edits, never a second copy of its own.
		 *
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()} is a
		 * lazily-created singleton and {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::get_map_settings()}
		 * lazily constructs the handler on first call — so this is safe to call from ANY
		 * request, including a frontend `wp_enqueue_scripts` one where the tab itself was
		 * never registered (`register()` never ran, no shipping plugin declared itself yet):
		 * both calls still return a real, usable object, never `null`, because neither one
		 * depends on `register()` having run first.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function current(): self {
			return \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()->get_map_settings();
		}

		/**
		 * Gets the settings ids this handler owns, in registration order. Used by
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab} to build the
		 * `Settings_Section` without duplicating this handler's own field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [
				'pickup_button_placement',
				'pickup_replace_address',
				'pickup_close_on_select',
				self::SETTING_ACCENT_COLOR,
			];
		}

		/**
		 * Validates a stored accent colour: a `#rrggbb` hex, either case.
		 *
		 * Deliberately stricter than WordPress's `sanitize_hex_color()`, which also accepts the
		 * `#rgb` short form — the colour control submits `#rrggbb` only, so a short form here can
		 * only be hand-crafted input. Anchored with `\z`, not `$`, which would also let a
		 * trailing newline through. An EMPTY value never reaches this callback
		 * ({@see \Woodev_Setting::get_validation_error()} returns early for it): empty is the
		 * "inherit the carrier / framework default" state, not an error.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the submitted value.
		 * @return bool
		 */
		public static function is_valid_accent_color( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', $value );
		}

		protected function register_settings() {

			$this->register_setting(
				'pickup_button_placement',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Расположение кнопки', 'woodev-plugin-framework' ),
					'options' => [
						'rate'   => __( 'В строке выбранного метода', 'woodev-plugin-framework' ),
						'review' => __( 'После списка методов', 'woodev-plugin-framework' ),
					],
					'default' => 'rate',
				]
			);
			$this->register_control(
				'pickup_button_placement',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( '«В строке выбранного метода» — кнопка стоит прямо рядом с названием способа доставки в списке. «После списка методов» — одна общая кнопка выносится под весь список способов доставки.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'pickup_replace_address',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Подстановка адреса', 'woodev-plugin-framework' ),
					'default' => true,
				]
			);
			$this->register_control(
				'pickup_replace_address',
				\Woodev_Control::TYPE_CHECKBOX,
				[
					'tooltip' => __( 'Когда включено, после выбора пункта выдачи его адрес подставляется в поля доставки формы оформления заказа вместо адреса, который до этого ввёл покупатель.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'pickup_close_on_select',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Закрывать карту', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);
			$this->register_control(
				'pickup_close_on_select',
				\Woodev_Control::TYPE_CHECKBOX,
				[
					'tooltip' => __( 'Когда включено, карта пунктов выдачи закрывается сама сразу после выбора точки. Выключено — покупатель закрывает карту вручную.', 'woodev-plugin-framework' ),
				]
			);

			// Issue #379. The default is EMPTY on purpose: empty means "no store override",
			// so the resolution chain falls through to the carrier's own brand colour and
			// then the framework's — Pickup_Handler::resolve_accent_color(). A concrete
			// default here would be frozen into every store the first time it saved the tab
			// and silently outvote every carrier's brand colour from then on.
			$this->register_setting(
				self::SETTING_ACCENT_COLOR,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'             => __( 'Акцентный цвет', 'woodev-plugin-framework' ),
					'description'      => __( 'Оставьте пустым — тогда цвет берётся из настроек способа доставки.', 'woodev-plugin-framework' ),
					'default'          => '',
					'validate'         => [ self::class, 'is_valid_accent_color' ],
					'validate_message' => __( 'Введите цвет в формате #rrggbb.', 'woodev-plugin-framework' ),
				]
			);
			$this->register_control(
				self::SETTING_ACCENT_COLOR,
				\Woodev_Control::TYPE_COLOR,
				[
					'tooltip' => __( 'Цвет кнопок, выбранных пунктов и кластеров на карте пунктов выдачи; цвет текста на нём подбирается автоматически, чтобы он читался. Если поле пустое, используется фирменный цвет самого способа доставки.', 'woodev-plugin-framework' ),
				]
			);
		}
	}

endif;
