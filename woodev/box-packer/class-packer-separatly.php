<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Separately' ) ) :

	/**
	 * Packs each item into its own individual box.
	 *
	 * Accepts any Woodev_Box_Packer_Item — no WooCommerce dependency.
	 * For WC-specific box labelling (product name/SKU in the box label),
	 * use the WC-specific dispatcher layer.
	 *
	 * @since 1.4.1
	 */
	class Woodev_Packer_Separately extends Woodev_Packer {

		/**
		 * Label template for each package box.
		 *
		 * @var string
		 */
		private $box_name;

		/**
		 * @since  1.4.1
		 * @param  string $box_name Label applied to every package. Default empty.
		 */
		public function __construct( string $box_name = '' ) {
			$this->box_name = $box_name;
		}

		/**
		 * Packs each item into its own Woodev_Packer_Box_Implementation.
		 *
		 * @since  1.4.1
		 * @throws Woodev_Packer_Exception If no items have been added.
		 */
		public function pack() {
			if ( ! $this->items || 0 === count( $this->items ) ) {
				throw new Woodev_Packer_Exception( __( 'No items to pack!' ) );
			}

			$this->packages = [];
			$index          = 0;

			foreach ( $this->items as $item ) {
				$box_label  = $this->box_name ?: 'Package';
				$box_id     = 'package-' . $index;
				$packed_box = new Woodev_Box_Packer_Packed_Box(
					new Woodev_Packer_Box_Implementation(
						$item->get_length(),
						$item->get_width(),
						$item->get_height(),
						0,
						null,
						$box_id,
						$box_label
					),
					[ $item ]
				);

				$packed_box->get_packed_items();
				$this->packages[] = $packed_box;
				$index++;
			}

			$this->items = [];
		}
	}

endif;
