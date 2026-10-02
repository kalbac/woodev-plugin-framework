<?php
/**
 * Woodev Default Dimensions Settings
 *
 * Store-level settings handler owning the «Вес и габариты» section of the «Доставка» tab
 * (#955): the length, width, height and weight a product is packed with when it has none of its own.
 * Registered with the `default_dimensions` option namespace (`woodev_default_dimensions_*`).
 *
 * The values are a STORE decision, never a carrier's. Dimensions and weight describe the PRODUCT, not
 * the carrier that ships it; customers compare carriers' prices on the same parcel, so two carriers
 * must not quote one cart as two different parcels; and with four or more carriers a merchant fills
 * one place, not four. They live on the framework settings page (`woodev-settings`) — a carrier
 * plugin gets the fields without writing code.
 *
 * The fields are required and pre-filled (weight 100 g, 10 × 10 × 10 cm — what v1 CDEK and v1 Yandex
 * both used), converted to the store's units at registration. A merchant who never opens the section
 * still gets sane parcels, and the effective value is always positive.
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
	 * Settings handler for the product-without-dimensions fallback («Вес и габариты» section).
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

		/** @var float the built-in default weight, in grams (v1 CDEK / v1 Yandex used the same) */
		public const BUILTIN_WEIGHT_G = 100.0;

		/** @var float the built-in default length, width and height, in centimetres */
		public const BUILTIN_SIZE_CM = 10.0;

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
		 * The fallback for one setting, in the store's unit — always positive.
		 *
		 * The fields are required and refused when not positive, so the stored value normally is. A value
		 * that got past that anyway (written straight to the database, or left by an older version) is
		 * replaced by the registered default rather than passed on as a zero-size parcel.
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

			if ( is_numeric( $value ) && (float) $value > 0 ) {
				return (float) $value;
			}

			$default = $this->get_setting( $setting_id )->get_default();

			return is_numeric( $default ) ? (float) $default : 0.0;
		}

		/**
		 * The store's dimension unit as WooCommerce has it (`cm` when unset, WooCommerce's own default).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function get_dimension_unit(): string {
			$unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

			return '' !== $unit ? $unit : 'cm';
		}

		/**
		 * The store's weight unit as WooCommerce has it (`kg` when unset, WooCommerce's own default).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function get_weight_unit(): string {
			$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );

			return '' !== $unit ? $unit : 'kg';
		}

		/**
		 * A built-in default converted to the store's unit and rounded to the control's step, so
		 * 100 g in a store that weighs in pounds is 0.22, not 0.2204622622.
		 *
		 * @since 2.0.2
		 *
		 * @param float $value value in the built-in unit (g / cm).
		 * @param bool  $weight true for a weight, false for a dimension.
		 * @return float
		 */
		private static function convert_builtin_default( float $value, bool $weight ): float {

			$converted = $weight
				? wc_get_weight( $value, self::get_weight_unit(), 'g' )
				: wc_get_dimension( $value, self::get_dimension_unit(), 'cm' );

			return round( (float) $converted, 2 );
		}

		/**
		 * Registers the four settings: required, pre-filled with the built-in default
		 * ({@see self::BUILTIN_WEIGHT_G}, {@see self::BUILTIN_SIZE_CM}) converted to the store's units.
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
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана длина.', 'woodev-plugin-framework' ),
					'default' => self::convert_builtin_default( self::BUILTIN_SIZE_CM, false ),
				],
				self::SETTING_WIDTH  => [
					/* translators: %s: the store's dimension unit, e.g. cm */
					'name'    => sprintf( __( 'Ширина по умолчанию, %s', 'woodev-plugin-framework' ), $dimension_unit ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана ширина.', 'woodev-plugin-framework' ),
					'default' => self::convert_builtin_default( self::BUILTIN_SIZE_CM, false ),
				],
				self::SETTING_HEIGHT => [
					/* translators: %s: the store's dimension unit, e.g. cm */
					'name'    => sprintf( __( 'Высота по умолчанию, %s', 'woodev-plugin-framework' ), $dimension_unit ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указана высота.', 'woodev-plugin-framework' ),
					'default' => self::convert_builtin_default( self::BUILTIN_SIZE_CM, false ),
				],
				self::SETTING_WEIGHT => [
					/* translators: %s: the store's weight unit, e.g. kg */
					'name'    => sprintf( __( 'Вес по умолчанию, %s', 'woodev-plugin-framework' ), self::get_weight_unit() ),
					'tooltip' => __( 'Подставляется в расчёт доставки, если у товара не указан вес.', 'woodev-plugin-framework' ),
					'default' => self::convert_builtin_default( self::BUILTIN_WEIGHT_G, true ),
				],
			];

			foreach ( $fields as $id => $field ) {

				$this->register_setting(
					$id,
					\Woodev_Setting::TYPE_FLOAT,
					[
						'name'             => $field['name'],
						'default'          => $field['default'],
						'required'         => true,
						// Strictly positive: a zero or negative size is no size a parcel can have. The
						// callback only sees a non-empty value — an empty one is refused by `required`.
						'validate'         => static function ( $value ): bool {
							return is_numeric( $value ) && (float) $value > 0;
						},
						'validate_message' => __( 'Значение должно быть больше нуля.', 'woodev-plugin-framework' ),
					]
				);
				$this->register_control(
					$id,
					\Woodev_Control::TYPE_NUMBER,
					[
						'tooltip' => $field['tooltip'],
						'min'     => 0.01,
						'step'    => 0.01,
						// the browser itself refuses 0 and -1; the server-side validate stays the real guard
						'native_bounds' => true,
					]
				);
			}
		}
	}

endif;
