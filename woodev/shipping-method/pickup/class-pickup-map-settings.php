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
			];
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
					'tooltip' => __( 'Когда включено, после выбора пункта выдачи его адрес подставляется в поля доставки на чекауте вместо адреса, который до этого ввёл покупатель.', 'woodev-plugin-framework' ),
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
		}
	}

endif;
