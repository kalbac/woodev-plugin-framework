<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Box_Packer_Packed_Box' ) ) :

	final class Woodev_Box_Packer_Packed_Box {

		/** No floor: success percent is never negative, so nothing is given up. */
		const NO_FLOOR = -1.0;

		/** A pass is given up only when it falls below the floor by more than this (percent, float noise). */
		const FLOOR_MARGIN = 1e-7;

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

		/** @var bool */
		private $packing_done = false;

		/** @var bool Every order fell below the floor: the numbers are partial and the box cannot win. */
		private $abandoned = false;

		/** @var float */
		private $abandoned_floor = self::NO_FLOOR;

		/** @var float[] Total weight and volume of all the items, and their count: what the percent is relative to. */
		private $totals = [ 0.0, 0.0, 0 ];

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

		/**
		 * Packs the items unless this box provably cannot reach a success percent of $floor, and tells which.
		 * Selecting among boxes only needs the best one, so a box (or one of its axis orders) that cannot match
		 * the best so far is given up early; its numbers are then partial and no getter result of it may be used
		 * as a packing. The result of the box that is NOT given up is exactly what the getters return.
		 *
		 * @since 2.0.2
		 *
		 * @param  float $floor the success percent to reach (ties count), {@see self::NO_FLOOR} for none
		 * @return bool false when the box cannot reach it
		 */
		public function try_to_beat( float $floor ): bool {
			$this->try_to_pack( $floor );

			return ! $this->abandoned;
		}

		private function try_to_pack( float $floor = self::NO_FLOOR ) {

			// the packing is deterministic, so it is done once; a run given up under a floor is reused only by
			// a caller that asks for at least that floor
			if ( $this->packing_done && ( ! $this->abandoned || $floor >= $this->abandoned_floor ) ) {
				return;
			}

			$this->packing_done    = true;
			$this->abandoned       = false;
			$this->abandoned_floor = self::NO_FLOOR;

			if ( ! $this->items_to_pack || count( $this->items_to_pack ) === 0 ) {
				return;
			}

			$this->totals = [ 0.0, 0.0, count( $this->items_to_pack ) ];

			foreach ( $this->items_to_pack as $item ) {
				$this->totals[0] += $item->get_weight();
				$this->totals[1] += $item->get_volume();
			}

			$best  = null;
			$to_be = $floor; // what an attempt has to reach to matter: the caller's floor, then the best pass so far

			foreach ( $this->containers() as $space ) {
				$attempt = $this->pack_attempt( $space, $to_be );

				if ( null === $best || $attempt['percent'] > $best['percent'] ) {
					$best = $attempt;
				}

				if ( $attempt['abandoned'] ) {
					continue;
				}

				// every item that could be taken was taken, so turning the box cannot do any better
				if ( ! $attempt['refused'] ) {
					break;
				}

				$to_be = max( $to_be, $best['percent'] );
			}

			if ( $best['abandoned'] ) {
				// no order reached the floor: the box cannot win and its numbers are partial
				$this->abandoned       = true;
				$this->abandoned_floor = $floor;
			}

			$this->packed_items    = $best['packed'];
			$this->nofit_items     = $best['unpacked'];
			$this->packed_weight   = $best['weight'];
			$this->packed_volume   = $best['volume'];
			$this->packed_value    = $best['value'];
			$this->success_percent = $best['percent'];
		}

		private function pack_attempt( ?Woodev_Packer_Free_Space $space, float $floor = self::NO_FLOOR ): array {
			$packed   = [];
			$unpacked = [];
			$weight   = $this->box->get_weight();
			$volume   = 0;
			$value    = 0;
			$tried    = 0;
			$refused  = false;
			$lost_w   = 0;
			$lost_v   = 0;
			$check    = null !== $space && $floor > self::NO_FLOOR;
			$bound    = 0.0;

			foreach ( $this->items_to_pack as $item ) {

				if ( ! $this->can_be_packed( $item, $weight, $volume ) ) {
					$unpacked[] = $item;
					$lost_w    += $item->get_weight();
					$lost_v    += $item->get_volume();
				} elseif ( null !== $space && $tried >= Woodev_Packer_Free_Space::MAX_UNITS ) {
					$unpacked[] = $item;
					$lost_w    += $item->get_weight();
					$lost_v    += $item->get_volume();
					$refused    = true;
				} elseif ( null !== $space && ( ++$tried && null === $space->place( [ $item->get_length(), $item->get_width(), $item->get_height() ] ) ) ) {
					$unpacked[] = $item;
					$lost_w    += $item->get_weight();
					$lost_v    += $item->get_volume();
					$refused    = true;
				} else {
					$packed[] = $item;
					$volume  += $item->get_volume();
					$weight  += $item->get_weight();
					$value   += $item->get_value();

					continue;
				}

				// the pass can only lose more from here: when even taking every remaining item cannot reach
				// the floor, nothing it does matters any more. Only once it has refused an item: a pass that
				// refuses nothing ends the search over the box's axis orders, and that has to stay as it is.
				if ( $check && $refused ) {
					$bound = $this->percent_bound( $lost_w, $lost_v, count( $unpacked ) );

					if ( $bound < $floor - self::FLOOR_MARGIN ) {
						return [
							'packed'    => $packed,
							'unpacked'  => $unpacked,
							'weight'    => $weight,
							'volume'    => $volume,
							'value'     => $value,
							'percent'   => $bound,
							'refused'   => true,
							'abandoned' => true,
						];
					}
				}
			}

			return [
				'packed'    => $packed,
				'unpacked'  => $unpacked,
				'weight'    => $weight,
				'volume'    => $volume,
				'value'     => $value,
				'percent'   => $this->success_percent_of( $packed, $unpacked, $weight, $volume ),
				'refused'   => $refused,
				'abandoned' => false,
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

		/**
		 * The best success percent a pass can still reach when the items it has left out so far stay left out
		 * and every other item is taken — the same formula as {@see self::success_percent_of()} with a constant
		 * total, so it never falls below the percent the pass finally gets.
		 *
		 * @param float $lost_weight weight of the items left out so far
		 * @param float $lost_volume volume of the items left out so far
		 * @param int   $lost_count  how many items are left out so far
		 *
		 * @return float
		 */
		private function percent_bound( float $lost_weight, float $lost_volume, int $lost_count ): float {
			[ $total_weight, $total_volume, $total_count ] = $this->totals;

			$weight_ratio = $total_weight > 0 ? ( $total_weight - $lost_weight ) / $total_weight : null;
			$volume_ratio = $total_volume ? ( $total_volume - $lost_volume ) / $total_volume : null;

			if ( null === $weight_ratio && null === $volume_ratio ) {
				return ( $total_count - $lost_count ) / $total_count * 100;
			}

			if ( null === $weight_ratio ) {
				return $volume_ratio * 100;
			}

			if ( null === $volume_ratio ) {
				return $weight_ratio * 100;
			}

			return $weight_ratio * $volume_ratio * 100;
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
