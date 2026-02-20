<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Packer_Boxes' ) ) :

	class Woodev_Packer_Boxes extends Woodev_Packer {

		/**
		 * Pack items to boxes creating packages.
		 *
		 * @throws Woodev_Packer_Exception
		 */
		public function pack() {

			if ( ! $this->items || sizeof( $this->items ) === 0 ) {
				throw new Woodev_Packer_Exception( __( 'No items to pack!' ) );
			}

			$this->packages = array();
			$this->boxes    = $this->order_boxes_by_volume( $this->boxes );

			if ( ! $this->boxes ) {
				$this->items_cannot_pack = $this->items;
				$this->items             = array();
			}
			// Keep looping until packed
			while ( sizeof( $this->items ) > 0 ) {

				$this->items  = $this->order_items( $this->items );
				$best_package = $this->find_best_packed_box();

				if ( $best_package->get_success_percent() === 0.0 ) {
					$this->items_cannot_pack = $this->items;
					$this->items             = array();
				} else {
					$this->items      = $best_package->get_nofit_items();
					$this->packages[] = $best_package;
				}
			}
		}

		/**
		 * Pack all items to all boxes and try to find one best success package
		 *
		 * @return Woodev_Box_Packer_Packed_Box Best packed package possible
		 */
		private function find_best_packed_box(): ?Woodev_Box_Packer_Packed_Box {
			$packages = array();
			foreach ( $this->boxes as $box ) {
				$packages[] = new Woodev_Box_Packer_Packed_Box( $box, $this->items );
			}
			// Find the best success rate
			$best_percent = 0;
			$best_package = null;
			/** @var Woodev_Box_Packer_Packed_Box $package */
			foreach ( $packages as $package ) {
				if ( $package->get_success_percent() >= $best_percent ) {
					$best_percent = $package->get_success_percent();
					$best_package = $package;
				}
			}

			return $best_package;
		}

		/**
		 * Order boxes by weight and volume
		 *
		 * @param array $sort
		 *
		 * @return array
		 */
		private function order_boxes_by_volume( array $sort ) {
			if ( ! empty( $sort ) ) {
				uasort( $sort, static function ( Woodev_Box_Packer_Box $a, Woodev_Box_Packer_Box $b ) {
					if ( $a->get_volume() === $b->get_volume() ) {
						if ( $a->get_max_weight() === $b->get_max_weight() ) {
							return 0;
						}

						return $a->get_max_weight() < $b->get_max_weight() ? 1 : - 1;
					}

					return $a->get_volume() < $b->get_volume() ? 1 : - 1;
				} );
			}

			return $sort;
		}

		/**
		 * Order items by weight and volume
		 *
		 * @param array $sort
		 *
		 * @return array
		 */
		private function order_items( array $sort ): array {
			if ( ! empty( $sort ) ) {
				uasort( $sort, static function ( Woodev_Box_Packer_Item $a, Woodev_Box_Packer_Item $b ) {
					if ( $a->get_volume() === $b->get_volume() ) {
						if ( $a->get_weight() === $b->get_weight() ) {
							return 0;
						}

						return $a->get_weight() < $b->get_weight() ? 1 : - 1;
					}

					return $a->get_volume() < $b->get_volume() ? 1 : - 1;
				} );
			}

			return $sort;
		}
	}

endif;
