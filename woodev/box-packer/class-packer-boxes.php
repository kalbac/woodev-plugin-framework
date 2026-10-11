<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Boxes' ) ) :

	class Woodev_Packer_Boxes extends Woodev_Packer {

		/**
		 * Pack items to boxes creating packages.
		 *
		 * @throws Woodev_Packer_Exception
		 */
		public function pack() {

			if ( ! $this->items || count( $this->items ) === 0 ) {
				throw new Woodev_Packer_Exception( 'No items to pack!' );
			}

			$this->packages = [];
			// no add_box() call leaves `$boxes` null — every item then fits no box (below), it is not an error
			$this->boxes = $this->order_boxes_by_volume( $this->boxes ?: [] );

			if ( ! $this->boxes ) {
				$this->items_cannot_pack = $this->items;
				$this->items             = [];
			}
			// Keep looping until packed
			while ( ! empty( $this->items ) ) {

				$this->items  = $this->order_items( $this->items );
				$best_package = $this->find_best_packed_box();

				if ( $best_package->get_success_percent() === 0.0 ) {
					$this->items_cannot_pack = $this->items;
					$this->items             = [];
				} else {
					$this->items      = $best_package->get_nofit_items();
					$this->packages[] = $best_package;
				}
			}
		}

		private function find_best_packed_box(): ?Woodev_Box_Packer_Packed_Box {
			// Maximise packed units first; on equal fill prefer store boxes, then the smallest volume.
			$best_percent = -1;
			$best_package = null;
			$best_is_carrier = false;
			foreach ( $this->boxes as $box ) {
				$package = new Woodev_Box_Packer_Packed_Box( $box, $this->items, true );
				// a box that provably cannot reach the best fill so far cannot win: it is given up early
				if ( $best_percent >= 0 && ! $package->try_to_beat( (float) $best_percent ) ) {
					continue;
				}
				$data = $package->get_box()->get_internal_data();
				$is_carrier = is_array( $data ) && 'carrier' === ( $data['origin'] ?? '' );
				$percent = $package->get_success_percent();
				if ( $percent > $best_percent || ( $percent === $best_percent && ( $best_is_carrier || ! $is_carrier ) ) ) {
					$best_percent = $percent;
					$best_package = $package;
					$best_is_carrier = $is_carrier;
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
				uasort(
					$sort,
					static function ( Woodev_Box_Packer_Box $a, Woodev_Box_Packer_Box $b ) {
						if ( $a->get_volume() === $b->get_volume() ) {
							if ( $a->get_max_weight() === $b->get_max_weight() ) {
								return 0;
							}

							return $a->get_max_weight() < $b->get_max_weight() ? 1 : - 1;
						}

						return $a->get_volume() < $b->get_volume() ? 1 : - 1;
					}
				);
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
				uasort(
					$sort,
					static function ( Woodev_Box_Packer_Item $a, Woodev_Box_Packer_Item $b ) {
						if ( $a->get_volume() === $b->get_volume() ) {
							if ( $a->get_weight() === $b->get_weight() ) {
								return 0;
							}

							return $a->get_weight() < $b->get_weight() ? 1 : - 1;
						}

						return $a->get_volume() < $b->get_volume() ? 1 : - 1;
					}
				);
			}

			return $sort;
		}
	}

endif;
