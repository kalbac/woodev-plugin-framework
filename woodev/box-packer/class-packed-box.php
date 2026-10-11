<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Box_Packer_Packed_Box' ) ) :

	final class Woodev_Box_Packer_Packed_Box {

		/** @var float */
		private $packed_volume;
		/** @var float */
		private $packed_weight;
		/** @var float */
		private $packed_value;
		/** @var Woodev_Box_Packer_Box */
		private $box;
		/** @var Woodev_Box_Packer_Item[] */
		private $items_to_pack;
		/** @var Woodev_Box_Packer_Item[] */
		private $packed_items = [];
		/** @var Woodev_Box_Packer_Item[] */
		private $nofit_items = [];
		/** @var float */
		private $success_percent = 0.0;

		/** @var bool */
		private $check_placement;

		/**
		 * @param Woodev_Box_Packer_Box    $box
		 * @param Woodev_Box_Packer_Item[] $items
		 * @param bool                     $check_placement Take an item only when it can really be placed
		 *                                                  into the box next to those already taken (#1214),
		 *                                                  not just when the sides and the summed volume
		 *                                                  allow it. The best of the box's axis orders
		 *                                                  is kept; at most {@see Woodev_Packer_Free_Space::MAX_UNITS}
		 *                                                  placements are tried per order, the rest of the
		 *                                                  items wait for a later parcel. @since 2.0.2
		 */
		public function __construct( Woodev_Box_Packer_Box $box, array $items, bool $check_placement = false ) {
			$this->box             = $box;
			$this->items_to_pack   = $items;
			$this->check_placement = $check_placement;
		}

		/**
		 * @return Woodev_Box_Packer_Box
		 */
		public function get_box(): Woodev_Box_Packer_Box {
			return $this->box;
		}

		/**
		 * @return Woodev_Box_Packer_Item[]
		 */
		public function get_packed_items(): array {
			$this->try_to_pack();

			return $this->packed_items;
		}

		/**
		 * Get packed weight.
		 *
		 * @return float
		 */
		public function get_packed_weight(): float {
			return $this->packed_weight;
		}

		/**
		 * Get packed value.
		 *
		 * @return float
		 */
		public function get_packed_value(): float {
			return $this->packed_value;
		}

		/**
		 * @return Woodev_Box_Packer_Item[]
		 */
		public function get_nofit_items(): array {
			$this->try_to_pack();

			return $this->nofit_items;
		}

		/**
		 * How good is this box in packing given items. Higher is better.
		 *
		 * @return float
		 */
		public function get_success_percent(): float {
			$this->try_to_pack();

			return $this->success_percent;
		}

		private function try_to_pack() {

			if ( ! $this->items_to_pack || count( $this->items_to_pack ) === 0 ) {
				return;
			}

			$best = null;

			foreach ( $this->containers() as $space ) {
				$attempt = $this->pack_attempt( $space );

				if ( null === $best || $attempt['percent'] > $best['percent'] ) {
					$best = $attempt;
				}

				// every item that could be taken was taken, so turning the box cannot do any better
				if ( ! $attempt['refused'] ) {
					break;
				}
			}

			$this->packed_items   = $best['packed'];
			$this->nofit_items    = $best['unpacked'];
			$this->packed_weight  = $best['weight'];
			$this->packed_volume  = $best['volume'];
			$this->packed_value   = $best['value'];
			$this->success_percent = $best['percent'];
		}

		/**
		 * One greedy pass over the items into one container (or by the sides/weight/volume rule alone when
		 * the container is null). With a container, at most {@see Woodev_Packer_Free_Space::MAX_UNITS} items
		 * are tried to place: the work is bounded by what this parcel really attempts, not by the cart size,
		 * and an item past the budget is left for a later parcel — never taken without a placement.
		 *
		 * @param  Woodev_Packer_Free_Space|null $space
		 * @return array{packed: Woodev_Box_Packer_Item[], unpacked: Woodev_Box_Packer_Item[], weight: float, volume: float, value: float, percent: float, refused: bool}
		 */
		private function pack_attempt( ?Woodev_Packer_Free_Space $space ): array {
			$packed   = [];
			$unpacked = [];
			$weight   = $this->box->get_weight();
			$volume   = 0;
			$value    = 0;
			$tried    = 0;
			$refused  = false;

			foreach ( $this->items_to_pack as $item ) {

				if ( ! $this->can_be_packed( $item, $weight, $volume ) ) {
					$unpacked[] = $item;
					continue;
				}

				if ( null !== $space ) {
					if ( $tried >= Woodev_Packer_Free_Space::MAX_UNITS ) {
						$unpacked[] = $item;
						$refused    = true;
						continue;
					}

					++$tried;

					if ( null === $space->place( [ $item->get_length(), $item->get_width(), $item->get_height() ] ) ) {
						$unpacked[] = $item;
						$refused    = true;
						continue;
					}
				}

				$packed[] = $item;
				$volume  += $item->get_volume();
				$weight  += $item->get_weight();
				$value   += $item->get_value();
			}

			return [
				'packed'   => $packed,
				'unpacked' => $unpacked,
				'weight'   => $weight,
				'volume'   => $volume,
				'value'    => $value,
				'percent'  => $this->success_percent_of( $packed, $unpacked, $weight, $volume ),
				'refused'  => $refused,
			];
		}

		private function containers(): \Generator {
			if ( ! $this->check_placement ) {
				yield null;

				return;
			}

			// the smallest side of any item that can enter the empty box at all: thinner free spaces are useless
			$min_side = PHP_FLOAT_MAX;

			foreach ( $this->items_to_pack as $item ) {
				if ( $this->can_fit_to_empty_box( $item ) ) {
					$min_side = min( $min_side, $item->get_length(), $item->get_width(), $item->get_height() );
				}
			}

			[ $a, $b, $c ] = [ (float) $this->box->get_length(), (float) $this->box->get_width(), (float) $this->box->get_height() ];

			// the box as given first, then its other axis orders: one greedy pass turns a load that tiles the
			// box in another orientation into extra parcels (4 x 40x25x10 in 50x40x20 would take two)
			$seen = [];

			foreach ( [ [ $a, $b, $c ], [ $b, $a, $c ], [ $a, $c, $b ], [ $c, $a, $b ], [ $b, $c, $a ], [ $c, $b, $a ] ] as $axes ) {
				$key = implode( '|', $axes );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;

				yield new Woodev_Packer_Free_Space( $axes[0], $axes[1], $axes[2], (float) $min_side );
			}
		}

		/**
		 * See if an item fits into the box at all
		 *
		 * @param Woodev_Box_Packer_Item $item
		 *
		 * @return bool
		 */
		private function can_fit_to_empty_box( Woodev_Box_Packer_Item $item ): bool {
			return $this->box->get_length() >= $item->get_length() && $this->box->get_width() >= $item->get_width() && $this->box->get_height() >= $item->get_height() && $item->get_volume() <= $this->box->get_volume();
		}

		/**
		 * If item can still fit to the box regarding weight and volume
		 *
		 * @param Woodev_Box_Packer_Item $item
		 * @param float                  $current_weight
		 * @param float                  $current_volume
		 *
		 * @return bool
		 */
		private function can_be_packed( Woodev_Box_Packer_Item $item, float $current_weight, float $current_volume ): bool {
			// Check dimensions
			if ( ! $this->can_fit_to_empty_box( $item ) ) {
				return false;
			}
			// Check max weight
			if ( $this->box->get_max_weight() > 0 ) {
				if ( $current_weight + $item->get_weight() > $this->box->get_max_weight() ) {
					return false;
				}
			}

			return ! ( $current_volume + $item->get_volume() > $this->box->get_volume() );
		}

		private function success_percent_of( array $packed, array $unpacked, float $packed_total_weight, float $packed_volume ): float {
			// Get weight of unpacked items
			$unpacked_weight = 0;
			$unpacked_volume = 0;

			foreach ( $unpacked as $item ) {
				$unpacked_weight += $item->get_weight();
				$unpacked_volume += $item->get_volume();
			}

			// Calculate packing success % based on % of weight and volume of all items packed
			$packed_weight_ratio      = null;
			$packed_volume_ratio      = null;
			$packed_weight_to_compare = $packed_total_weight - $this->box->get_weight();

			if ( $packed_weight_to_compare + $unpacked_weight > 0 ) {
				$packed_weight_ratio = $packed_weight_to_compare / ( $packed_weight_to_compare + $unpacked_weight );
			}

			if ( $packed_volume + $unpacked_volume ) {
				$packed_volume_ratio = $packed_volume / ( $packed_volume + $unpacked_volume );
			}

			if ( is_null( $packed_weight_ratio ) && is_null( $packed_volume_ratio ) ) {
				// Fallback to amount packed
				return count( $packed ) / ( count( $unpacked ) + count( $packed ) ) * 100;
			}

			if ( is_null( $packed_weight_ratio ) ) {
				// Volume only
				return $packed_volume_ratio * 100;
			}

			if ( is_null( $packed_volume_ratio ) ) {
				// Weight only
				return $packed_weight_ratio * 100;
			}

			return $packed_weight_ratio * $packed_volume_ratio * 100;
		}
	}

endif;
