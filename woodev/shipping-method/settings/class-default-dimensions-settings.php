<?php
/**
 * Woodev Default Dimensions Settings
 *
 * Store-level settings handler owning the «Габариты по умолчанию» section of the «Доставка» tab
 * (#955): the length, width, height and weight a product is packed with when it has none of its own.
 * Registered with the `default_dimensions` option namespace (`woodev_default_dimensions_*`).
 *
 * The values are a STORE decision, never a carrier's: the packer ({@see \Woodev_WC_Packer_Dispatcher})
 * builds one set of items for every carrier and for the rate cache key, so a per-carrier answer would
 * make two carriers quote the same cart as two different parcels. They live on the framework settings
 * page (`woodev-settings`) — a carrier plugin gets the fields without writing code.
 *
 * Units: the merchant types the value in the STORE's units (`woocommerce_dimension_unit` /
 * `woocommerce_weight_unit`) — the same ones a product's own fields are in — and the field label says
 * which. The packer converts the effective value to its cm / kg at the same boundary as a product's own.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Default_Dimensions_Settings' ) ) :

	/**
	 * Settings handler for the product-without-dimensions fallback («Габариты по умолчанию» section).
	 *
	 * @since 2.0.2
	 */
	class Default_Dimensions_Settings extends \Woodev_Abstract_Settings {

		/** @var string the section id on the «Доставка» tab */
		public const SECTION_ID = 'default_dimensions';

		/** @var string setting id: length, in the store's dimension unit */
		public const SETTING_LENGTH = 'default_length';

		/** @var string setting id: width, in the store's dimension unit */
		public const SETTING_WIDTH = 'default_width';

		/** @var string setting id: height, in the store's dimension unit */
		public const SETTING_HEIGHT = 'default_height';

		/** @var string setting id: weight, in the store's weight unit */
		public const SETTING_WEIGHT = 'default_weight';

		/**
		 * @since 2.0.2
		 */
		public function __construct() {
			parent::__construct( 'default_dimensions' );
		}

		/**
		 * Returns the live handler, reached through the tab singleton — the same instance the admin
		 * screen edits, safe to call from any request (see {@see \Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings::current()}).
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function current(): self {
			return Shipping_Settings_Tab::instance()->get_default_dimensions_settings();
		}

		/**
		 * The setting ids this handler owns, in display order — the section's field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [ self::SETTING_LENGTH, self::SETTING_WIDTH, self::SETTING_HEIGHT, self::SETTING_WEIGHT ];
		}

		/**
		 * The fallback for one setting, in the store's unit, or 0.0 when the merchant left it empty.
		 *
		 * An empty field, a non-numeric stored value and a non-positive one all mean «no fallback»:
		 * a zero or negative size is no size a parcel can have.
		 *
		 * @since 2.0.2
		 *
		 * @param string $dimension `length`, `width`, `height` or `weight`.
		 * @return float
		 */
		public function get_fallback( string $dimension ): float {

			$setting_id = 'default_' . $dimension;

			if ( ! in_array( $setting_id, $this->get_owned_setting_ids(), true ) ) {
				return 0.0;
			}

			$value = $this->get_value( $setting_id );

			return is_numeric( $value ) && (float) $value > 0 ? (float) $value : 0.0;
		}

		/**
		 * The store's dimension unit as WooCommerce has it (`cm` when unset, WooCommerce's own default).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function get_dimension_unit(): string {
			return (string) get_option( 'woocommerce_dimension_unit', 'cm' );
		}

		/**
		 * The store's weight unit as WooCommerce has it (`kg` when unset, WooCommerce's own default).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function get_weight_unit(): string {
			return (string) get_option( 'woocommerce_weight_unit', 'kg' );
		}

		/**
		 * Registers the four settings. Empty by default — an empty setting changes nothing: the packer
		 * keeps turning a missing value into 0.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function register_settings() {

			$dimension_unit = self::get_dimension_unit();
			$fields         = [
				self::SETTING_LENGTH => [
					/* translators: %s: the store's dimension unit, e.g. cm */
					'name'    => sprintf( __( 'Длина по умолчанию, %s', 'woodev-plugin-framework' ), $dimension_unit ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана длина. Пусто — ничего не подставляется.', 'woodev-plugin-framework' ),
				],
				self::SETTING_WIDTH  => [
					/* translators: %s: the store's dimension unit, e.g. cm */
					'name'    => sprintf( __( 'Ширина по умолчанию, %s', 'woodev-plugin-framework' ), $dimension_unit ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана ширина. Пусто — ничего не подставляется.', 'woodev-plugin-framework' ),
				],
				self::SETTING_HEIGHT => [
					/* translators: %s: the store's dimension unit, e.g. cm */
					'name'    => sprintf( __( 'Высота по умолчанию, %s', 'woodev-plugin-framework' ), $dimension_unit ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана высота. Пусто — ничего не подставляется.', 'woodev-plugin-framework' ),
				],
				self::SETTING_WEIGHT => [
					/* translators: %s: the store's weight unit, e.g. kg */
					'name'    => sprintf( __( 'Вес по умолчанию, %s', 'woodev-plugin-framework' ), self::get_weight_unit() ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указан вес. Пусто — ничего не подставляется.', 'woodev-plugin-framework' ),
				],
			];

			foreach ( $fields as $id => $field ) {

				$this->register_setting(
					$id,
					\Woodev_Setting::TYPE_FLOAT,
					[
						'name'    => $field['name'],
						'default' => '',
					]
				);
				$this->register_control(
					$id,
					\Woodev_Control::TYPE_NUMBER,
					[
						'tooltip' => $field['tooltip'],
						'min'     => 0,
						'step'    => 0.01,
					]
				);
			}
		}
	}

endif;
