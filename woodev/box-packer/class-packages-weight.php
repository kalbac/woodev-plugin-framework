<?php

defined( 'ABSPATH' ) or exit;

if ( ! interface_exists( 'Woodev_Box_Packer_Packages_Weight' ) ) :

	class Woodev_Box_Packer_Packages_Weight {
		/**
		 * Packages.
		 *
		 * @var Woodev_Box_Packer_Packed_Box[]
		 */
		private $packages;

		/**
		 * Woodev_Box_Packer_Packages_Weight constructor.
		 *
		 * @param Woodev_Box_Packer_Packed_Box[] $packages .
		 */
		public function __construct( array $packages ) {
			$this->packages = $packages;
		}

		/**
		 * Get total packages weight.
		 *
		 * @return float
		 */
		public function get_total_weight(): float {
			$total_weight = 0.0;
			foreach ( $this->packages as $package ) {
				$total_weight += $package->get_packed_weight();
			}

			return $total_weight;
		}
	}

endif;
