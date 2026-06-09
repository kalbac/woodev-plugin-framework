<?php

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Packer_Packable_Item' ) ) :

	/**
	 * Input contract for the packer dispatcher.
	 *
	 * Implement this interface to pass item data to Woodev_Packer_Dispatcher::pack()
	 * without coupling to WooCommerce. The concrete DTO is Woodev_Packer_Input_Item.
	 *
	 * @since 1.4.1
	 */
	interface Woodev_Packer_Packable_Item {

		/**
		 * @since 1.4.1
		 * @return float Item length in cm (or any consistent unit).
		 */
		public function get_length(): float;

		/**
		 * @since 1.4.1
		 * @return float Item width in cm.
		 */
		public function get_width(): float;

		/**
		 * @since 1.4.1
		 * @return float Item height in cm.
		 */
		public function get_height(): float;

		/**
		 * @since 1.4.1
		 * @return float Item weight in kg.
		 */
		public function get_weight(): float;

		/**
		 * @since 1.4.1
		 * @return int Number of units of this item (≥ 1).
		 */
		public function get_quantity(): int;
	}

endif;
