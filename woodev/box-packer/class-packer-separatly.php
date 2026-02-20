<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Packer_Separately' ) ) :

	class Woodev_Packer_Separately extends Woodev_Packer {
		/**
		 * Box name.
		 *
		 * @var string
		 */
		private $box_name;

		/**
		 * Woodev_Packer_Separately constructor.
		 *
		 * @param string $box_name .
		 */
		public function __construct( string $box_name = '' ) {
			$this->box_name = $box_name;
		}

		/**
		 * Pack items to boxes creating packages.
		 *
		 * @throws Woodev_Packer_Exception .
		 */
		public function pack() {
			if ( ! $this->items || 0 === count( $this->items ) ) {
				throw new Woodev_Packer_Exception( __( 'No items to pack!' ) );
			}

			$this->packages = array();
			// Pack items.
			foreach ( $this->items as $item ) {

				$product = $item->get_product();

				$packed_box = new Woodev_Box_Packer_Packed_Box( new Woodev_Packer_Box_Implementation(
					$item->get_length(),
					$item->get_width(),
					$item->get_height(),
					0,
					null,
					Woodev_Helper::str_convert( $this->get_box_name( $product ) ),
					$this->get_box_name( $product )
				), array( $item ) );

				$packed_box->get_packed_items();
				// Calculates weight!
				$this->packages[] = $packed_box;
			}

			$this->items = array();
		}

		/**
		 * @param WC_Product $product
		 *
		 * @return string
		 */
		private function get_box_name( WC_Product $product ): string {
			if ( $product instanceof WC_Product ) {
				return str_replace( array(
					'{product_name}',
					'{product_sku}',
					'{product_id}'
				), array(
					$product->get_name(),
					$product->get_sku(),
					$product->get_id()
				), $this->box_name );
			}

			return $this->box_name;
		}
	}

endif;
