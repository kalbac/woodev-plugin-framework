<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Packer_Single_Box' ) ) :

	class Woodev_Packer_Single_Box extends Woodev_Packer {
		/**
		 * Box name.
		 *
		 * @var string
		 */
		private $box_name;

		/**
		 * Woodev_Packer_Single_Box constructor.
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

			$this->packages = array(
				new Woodev_Box_Packer_Packed_Box( new Woodev_Packer_Box_Implementation(
					$this->get_package_dimension( 'length' ),
					$this->get_package_dimension( 'width' ),
					$this->get_package_dimension( 'height' ),
					0,
					null,
					$this->box_name,
					$this->box_name
				), $this->items )
			);

			$this->items = array();
		}

		/**
		 * Extracts the dimensions from Items.
		 *
		 * @return array
		 */
		private function get_items_dimensions(): array {
			return array(
				'height' => wc_list_pluck( $this->items, 'get_height' ),
				'length' => wc_list_pluck( $this->items, 'get_length' ),
				'width'  => wc_list_pluck( $this->items, 'get_width' )
			);
		}

		/**
		 * Get the max values.
		 *
		 * @return array
		 */
		private function get_max_values(): array {

			$find = array();

			foreach ( $this->get_items_dimensions() as $dimension => $values ) {
				$find[ $dimension ] = max( $values );
			}

			return $find;
		}

		private function get_greatest_dimension() {
			$max_values = $this->get_max_values();

			return array_search( max( $max_values ), $max_values, true );
		}

		/**
		 * @param string $dimension
		 *
		 * @return float|int
		 */
		private function get_package_dimension( string $dimension ) {

			$dimensions      = $this->get_items_dimensions();
			$diff_dimensions = array_diff_key( $dimensions, array_flip( array( $this->get_greatest_dimension() ) ) );
			$greatest        = $this->get_greatest_dimension() === $dimension ? $dimension : array_search( max( $diff_dimensions ), $diff_dimensions, true );

			return $greatest === $dimension ? max( $dimensions[ $greatest ] ) : array_sum( $dimensions[ $dimension ] );
		}
	}

endif;
