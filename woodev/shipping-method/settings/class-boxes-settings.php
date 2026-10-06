<?php
/**
 * Woodev Boxes Settings
 *
 * Store-level settings handler owning the «Коробки» section of the «Доставка» tab (#1138): the one
 * store-wide list of the boxes a merchant packs orders into. It is what the `boxes` packing
 * algorithm ({@see \Woodev_Packer_Dispatcher::ALGORITHM_BOXES}) packs into, through
 * {@see \Woodev_WC_Packer_Dispatcher}. Registered with the `boxes` option namespace (`woodev_boxes_*`).
 *
 * The list is a STORE decision, never a carrier's: the boxes on the merchant's shelf are the same whichever
 * carrier ships the parcel, and four carriers must not mean four lists to keep. It lives on the framework
 * settings page (`woodev-settings`), so a carrier plugin gets it without writing code.
 *
 * Shape of the control. The settings API has no repeater / list field, so the list is the simplest control that
 * exists — a textarea, ONE BOX PER LINE: `name; length; width; height; max weight; box weight`. The first four
 * are required, the last two optional (empty = no weight limit / the box weighs nothing). Decimal commas
 * are accepted. A repeater is a follow-up; the stored value is the text itself, so a later control can read it
 * unchanged.
 *
 * Units: the merchant types the values in the STORE's units (`woocommerce_dimension_unit` /
 * `woocommerce_weight_unit`) — the same ones a product's own fields are in — and the description says which.
 * {@see \Woodev_WC_Packer_Dispatcher::get_store_boxes()} converts them to the packer's cm / kg.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Boxes_Settings' ) ) :

	/**
	 * Settings handler for the store's list of boxes («Коробки» section).
	 *
	 * @since 2.0.2
	 */
	class Boxes_Settings extends \Woodev_Abstract_Settings {

		/** @var string the section id on the «Доставка» tab */
		public const SECTION_ID = 'boxes';

		/** @var string setting id: the list, one box per line */
		public const SETTING_BOXES = 'boxes';

		/** @var string separates a line's fields */
		public const FIELD_SEPARATOR = ';';

		/**
		 * @since 2.0.2
		 */
		public function __construct() {
			parent::__construct( 'boxes' );
		}

		/**
		 * Returns the live handler, reached through the tab singleton — the same instance the admin
		 * screen edits, safe to call from any request (see {@see Default_Dimensions_Settings::current()}).
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function current(): self {
			return Shipping_Settings_Tab::instance()->get_boxes_settings();
		}

		/**
		 * The setting ids this handler owns, in display order — the section's field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [ self::SETTING_BOXES ];
		}

		/**
		 * The store's boxes, in the store's units, in the order the merchant wrote them.
		 *
		 * A line that does not parse ({@see self::parse_line()}) is skipped, not guessed at: the control refuses
		 * such a list on save, so one only gets here when the value was written around it.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{id: string, name: string, length: float, width: float, height: float, max_weight: float, box_weight: float}>
		 */
		public function get_boxes(): array {
			return self::parse( (string) $this->get_value( self::SETTING_BOXES ) );
		}

		/**
		 * Parses the list text into boxes. Blank lines are ignored; a line that does not parse is skipped.
		 *
		 * The id of a box is its position among the lines that parsed (`box-1`, `box-2`, …).
		 *
		 * @since 2.0.2
		 *
		 * @param string $text one box per line.
		 * @return array<int, array{id: string, name: string, length: float, width: float, height: float, max_weight: float, box_weight: float}>
		 */
		public static function parse( string $text ): array {
			$boxes = [];

			foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
				$box = self::parse_line( (string) $line );

				if ( null !== $box ) {
					$boxes[] = [ 'id' => 'box-' . ( count( $boxes ) + 1 ) ] + $box;
				}
			}

			return $boxes;
		}

		/**
		 * Parses one line: `name; length; width; height[; max weight[; box weight]]`.
		 *
		 * Null for a line that is no box: no name, fewer than four or more than six fields, a dimension that is
		 * not a number above zero, or a weight that is neither empty nor a number of zero or more.
		 *
		 * @since 2.0.2
		 *
		 * @param string $line one line of the list.
		 * @return array{name: string, length: float, width: float, height: float, max_weight: float, box_weight: float}|null
		 */
		public static function parse_line( string $line ): ?array {
			$fields = array_map( 'trim', explode( self::FIELD_SEPARATOR, $line ) );

			if ( count( $fields ) < 4 || count( $fields ) > 6 ) {
				return null;
			}

			$name = trim( wp_strip_all_tags( $fields[0] ) );

			if ( '' === $name ) {
				return null;
			}

			$size = [];

			foreach ( [ 1, 2, 3 ] as $index ) {
				$value = self::to_number( $fields[ $index ] );

				if ( null === $value || $value <= 0 ) {
					return null;
				}

				$size[] = $value;
			}

			$weights = [];

			foreach ( [ 4, 5 ] as $index ) {
				// an omitted weight, or an empty one, is 0: no limit / a box that weighs nothing
				$value = isset( $fields[ $index ] ) && '' !== $fields[ $index ] ? self::to_number( $fields[ $index ] ) : 0.0;

				if ( null === $value || $value < 0 ) {
					return null;
				}

				$weights[] = $value;
			}

			return [
				'name'       => $name,
				'length'     => $size[0],
				'width'      => $size[1],
				'height'     => $size[2],
				'max_weight' => $weights[0],
				'box_weight' => $weights[1],
			];
		}

		/**
		 * Whether a list text is acceptable: every non-blank line is a box.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the submitted text.
		 * @return bool
		 */
		public static function is_valid_list( $value ): bool {

			if ( ! is_string( $value ) ) {
				return false;
			}

			foreach ( (array) preg_split( '/\R/', $value ) as $line ) {
				if ( '' !== trim( (string) $line ) && null === self::parse_line( (string) $line ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * A number as a merchant writes it — decimal point or comma — or null when it is not one.
		 *
		 * @param string $text the field's text.
		 * @return float|null
		 */
		private static function to_number( string $text ): ?float {
			$text = str_replace( ',', '.', $text );

			return is_numeric( $text ) ? (float) $text : null;
		}

		/**
		 * Registers the list: optional, empty by default (no boxes — everything then packs separately).
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function register_settings() {

			$this->register_setting(
				self::SETTING_BOXES,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'             => __( 'Список коробок', 'woodev-plugin-framework' ),
					'default'          => '',
					'validate'         => [ self::class, 'is_valid_list' ],
					'validate_message' => __( 'Каждая непустая строка должна быть вида «название; длина; ширина; высота; макс. вес; вес коробки». Размеры — числа больше нуля, веса — числа не меньше нуля.', 'woodev-plugin-framework' ),
				]
			);
			$this->register_control(
				self::SETTING_BOXES,
				\Woodev_Control::TYPE_TEXTAREA,
				[
					'tooltip'     => sprintf(
						/* translators: 1: the store's dimension unit, e.g. cm; 2: the store's weight unit, e.g. kg */
						__( 'По одной коробке в строке: «название; длина; ширина; высота; макс. вес; вес коробки». Размеры (внутренние) — в %1$s, веса — в %2$s. Макс. вес и вес коробки можно не указывать: пусто — без ограничения и коробка ничего не весит. Макс. вес считается вместе с самой коробкой.', 'woodev-plugin-framework' ),
						Default_Dimensions_Settings::get_dimension_unit(),
						Default_Dimensions_Settings::get_weight_unit()
					),
					'placeholder' => 'Малая; 20; 15; 10; 2; 0.1',
				]
			);
		}
	}

endif;
