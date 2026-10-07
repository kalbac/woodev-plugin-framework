<?php
/**
 * Woodev Advanced Settings
 *
 * The «Дополнительно» settings of ONE carrier plugin: logging, and hiding the carrier's methods on the
 * cart page. They live on the plugin's own tab of the framework settings page
 * (`wp-admin/admin.php?page=woodev-settings`), the section after the carrier's own and «Выгрузка заказов»,
 * and the framework hands the tab to every {@see \Woodev\Framework\Shipping\Shipping_Plugin} through
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_settings_providers()} — a carrier writes no code.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Advanced_Settings' ) ) :

	/**
	 * Settings handler of one carrier plugin's «Дополнительно» section.
	 *
	 * Storage is the framework settings API's: one option per setting, `woodev_{plugin id}_advanced_{key}`.
	 * Both keys are the ones the shipped v1 carrier plugins stored inside their WooCommerce integration
	 * option (`woocommerce_{plugin id}_settings`: `enable_debug`, `disable_methods_on_cart`), so a site that
	 * upgrades keeps what it had chosen — {@see self::migrate_from_integration()} carries the values over,
	 * once, in the same way {@see Export_Settings} does.
	 *
	 * ONE logging key: `enable_debug`. The framework used to read `debug_mode` from the integration option
	 * while its (unused) {@see Shipping_Integration} wrote `enable_debug`, so neither ever reached the other.
	 *
	 * @since 2.0.2
	 */
	class Advanced_Settings extends \Woodev_Abstract_Settings {

		/** @var string the section id on the plugin's tab */
		public const SECTION_ID = 'advanced';

		/** @var string the logging switch: off = errors only, on = also debug lines and the carrier API requests/responses. Also v1's key. */
		public const SETTING_ENABLE_DEBUG = 'enable_debug';

		/** @var string «Не показывать на странице корзины». Also v1's key. */
		public const SETTING_DISABLE_ON_CART = 'disable_methods_on_cart';

		/** @var string the suffix of the handler id (the option namespace) after the plugin id */
		private const HANDLER_ID_SUFFIX = '_advanced';

		/** @var string the key, in the handler's option namespace, of the option that records the one-time migration as done */
		private const MIGRATED_FLAG = 'migrated_from_integration';

		/** @var string the carrier plugin's underscored id */
		private string $plugin_id;

		/** @var \Closure|null returns the v1 integration option's name, or null when there is none */
		private ?\Closure $legacy_option_key_resolver;

		/**
		 * @since 2.0.2
		 *
		 * @param string        $plugin_id                  the carrier plugin's underscored id ({@see \Woodev_Plugin::get_id_underscored()}).
		 * @param \Closure|null $legacy_option_key_resolver optional; returns the name of the v1 integration option, like
		 *                                                  {@see Export_Settings::__construct()}'s resolver. Called lazily, only
		 *                                                  while the migration is pending.
		 */
		public function __construct( string $plugin_id, ?\Closure $legacy_option_key_resolver = null ) {

			$this->plugin_id                  = $plugin_id;
			$this->legacy_option_key_resolver = $legacy_option_key_resolver;

			// BEFORE the parent loads the stored values: the carried-over v1 values must be what it reads.
			$this->migrate_from_integration();

			parent::__construct( $plugin_id . self::HANDLER_ID_SUFFIX );
		}

		/**
		 * The setting ids this handler owns, in display order — the section's field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [ self::SETTING_ENABLE_DEBUG, self::SETTING_DISABLE_ON_CART ];
		}

		/**
		 * Whether the merchant switched logging on. Default OFF: only errors are logged then.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_logging_enabled(): bool {
			return true === $this->get_value( self::SETTING_ENABLE_DEBUG );
		}

		/**
		 * Whether this carrier's methods are kept off the cart page (they are still offered at checkout). Default OFF.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_hidden_on_cart(): bool {
			return true === $this->get_value( self::SETTING_DISABLE_ON_CART );
		}

		/**
		 * Registers the two settings.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function register_settings() {

			$this->register_setting(
				self::SETTING_ENABLE_DEBUG,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Логирование', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);
			$this->register_control(
				self::SETTING_ENABLE_DEBUG,
				\Woodev_Control::TYPE_TOGGLE,
				[
					'tooltip'     => __( 'Выключено — в лог попадают только ошибки. Включено — ещё и подробные записи, в том числе запросы к перевозчику и его ответы.', 'woodev-plugin-framework' ),
					// Rule 10b, case 3: this must be SEEN, a tooltip is not always read.
					'description' => __( 'Не включайте без необходимости: в лог записывается много данных', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				self::SETTING_DISABLE_ON_CART,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Не показывать на странице корзины', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);
			$this->register_control(
				self::SETTING_DISABLE_ON_CART,
				\Woodev_Control::TYPE_TOGGLE,
				[
					'tooltip' => __( 'Способы доставки этого перевозчика не предлагаются в корзине — только при оформлении заказа.', 'woodev-plugin-framework' ),
				]
			);
		}

		/**
		 * One-time carry-over of the two values the v1 carrier plugins kept inside their WooCommerce integration
		 * option (`woocommerce_{plugin id}_settings` — an installed-site data contract), so a merchant who had
		 * logging on in v1 keeps it. Same rules as {@see Export_Settings::migrate_from_integration()}: the done-flag
		 * is written only once the v1 option was read as an array, a value already stored in the new place is never
		 * overwritten, and the v1 keys stay where they are.
		 *
		 * v1 stored a checkbox as `'yes'`/`'no'`; a bool is accepted too, any other type is treated as not set.
		 *
		 * Note for a carrier that migrates v1 `disable_methods_on_cart`: v1 showed that field only while WooCommerce's
		 * «Включить калькулятор доставки в корзине» was OFF, so a stored `yes` with the calculator ON was never in
		 * force. This carry-over copies it as written — the framework option is honoured regardless of the calculator.
		 *
		 * @return void
		 */
		private function migrate_from_integration(): void {

			$prefix = 'woodev_' . $this->plugin_id . self::HANDLER_ID_SUFFIX . '_';
			$flag   = $prefix . self::MIGRATED_FLAG;

			if ( 'yes' === get_option( $flag, '' ) ) {
				return;
			}

			$key    = null !== $this->legacy_option_key_resolver ? ( $this->legacy_option_key_resolver )() : null;
			$legacy = get_option( is_string( $key ) && '' !== $key ? $key : 'woocommerce_' . $this->plugin_id . '_settings', null );

			if ( ! is_array( $legacy ) ) {
				return; // a miss: no done-flag, so a later request tries again
			}

			foreach ( $this->get_owned_setting_ids() as $setting_id ) {

				if ( ! array_key_exists( $setting_id, $legacy ) || null !== get_option( $prefix . $setting_id, null ) ) {
					continue;
				}

				$value = $legacy[ $setting_id ];

				if ( is_string( $value ) ) {
					$value = wc_string_to_bool( $value );
				}

				if ( is_bool( $value ) ) {
					update_option( $prefix . $setting_id, $value ? 'yes' : 'no' );
				}
			}

			update_option( $flag, 'yes' );
		}
	}

endif;
