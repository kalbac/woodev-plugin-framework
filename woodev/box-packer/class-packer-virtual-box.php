<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Virtual_Box' ) ) :

	/**
	 * Packs every item into ONE virtual box — the «everything in one box» choice — and sizes that box the
	 * way a person packs: the big items go down first, the small ones fill the gaps between them.
	 *
	 * How the box is found (#1212):
	 *
	 * 1. Candidate FOOTPRINTS (length × width) are built from the items' own sizes — single sides and sums
	 *    of two or three sides, because a real base is «the lamp plus the shoe box next to it», never an
	 *    arbitrary number. The ones nearest to a cube-like base for the items' total volume are tried first.
	 * 2. For each footprint the items are really PLACED into an open-top box with that base, largest first,
	 *    each at the deepest-bottom-left free space it fits (any of the six rotations, lying flat when it
	 *    can). The free space is tracked as a set of maximal empty boxes, so an item that fits a gap between
	 *    two bigger ones goes there. The height the stack reaches is the box's height for that footprint.
	 * 3. The smallest-volume box wins — volume is what the carrier charges for (volumetric weight). Among
	 *    boxes within {@see self::VOLUME_BAND} of the smallest, the one with the shortest longest side wins:
	 *    a slightly larger but much shorter parcel is the better shipment (no «sausage»).
	 *
	 * The work is bounded by counts ({@see self::MAX_ATTEMPTS}, {@see self::MAX_PLACEMENTS}, {@see self::MAX_UNITS}); the
	 * previous arithmetic grid box ({@see self::grid_dimensions()}) is the volume CEILING the result never
	 * exceeds and the fallback when no placement fits under it or the cart is over MAX_UNITS. The result is
	 * deterministic for the same items.
	 */
	class Woodev_Packer_Virtual_Box extends Woodev_Packer {

		/** Float comparison tolerance (sizes are cm with at most a few decimals). */
		const EPSILON = 1e-6;

		/**
		 * Boxes whose volume is within this fraction of the smallest found compete on the longest side instead.
		 * 10 %: at most a tenth more volumetric weight, bought only when it makes the parcel genuinely shorter.
		 */
		const VOLUME_BAND = 0.10;

		/**
		 * The work is bounded by COUNTS, never by the clock: the same items must give the same box on a slow
		 * server and a fast one, because the rate cache keys on the box. Footprints tried per call:
		 * MAX_PLACEMENTS / units, between MIN_ATTEMPTS and MAX_ATTEMPTS; above MAX_UNITS no placement runs at
		 * all and the grid box is returned (measured: see the report on #1212).
		 */
		const MAX_ATTEMPTS = 48;
		const MIN_ATTEMPTS = 3;
		const MAX_PLACEMENTS = 400;
		const MAX_UNITS = 120;

		/** The most the shortest side may grow, relatively, to settle a rounding error ({@see self::settled_box()}). */
		const SETTLE_LIMIT = 1e-7;

		/** How many distinct item sides feed the footprint candidates (the largest ones). */
		const MAX_DISTINCT_SIDES = 12;

		/** How many of those sides also form three-side sums. */
		const MAX_TRIPLE_SIDES = 6;

		/**
		 * The placement behind the last {@see self::pack()}: the frame it was made in and where each unit
		 * went ({@see self::get_placement()}). Empty when the grid fallback sized the box.
		 *
		 * @var array{length: float, width: float, height: float, units: array<int, array<int, float>>}|array{}
		 */
		private $placement = [];

		/**
		 * Pack all items into one virtual box.
		 *
		 * @throws Woodev_Packer_Exception When there are no items.
		 */
		public function pack() {
			if ( ! $this->items || count( $this->items ) === 0 ) {
				throw new Woodev_Packer_Exception( 'No items to pack!' );
			}

			$this->packages = [];
			$this->items    = $this->order_items_by_volume_desc( $this->items );

			$dimensions = $this->calculate_virtual_box_dimensions( $this->items );
			$virtual_box = $this->settled_box( $dimensions, $this->items );

			// every item fits by construction — the box is sized from a real placement of these items
			$this->packages[] = new Woodev_Box_Packer_Packed_Box( $virtual_box, $this->items );
			$this->items      = [];
		}

		/**
		 * The box object for the sides, nudged just enough that the packed-box view ({@see Woodev_Box_Packer_Packed_Box},
		 * which compares every item with the box strictly: each side, then the running volume) reports EVERY
		 * placed unit. The sides come from sums and a placement made with {@see self::EPSILON} tolerance, so the
		 * box's volume can land a rounding error under the summed item volume (3 × 10.5 × 7.3 × 2.1 cm did) and
		 * the last unit would be reported as not fitting, with its weight lost. Only the shortest side grows,
		 * by a relative step doubling from one rounding unit and capped at {@see self::SETTLE_LIMIT} — far below
		 * a micrometre on a parcel side, and no change at all when the box already satisfies the view.
		 *
		 * @param  array{length: float, width: float, height: float} $dimensions sides, longest first
		 * @param  Woodev_Box_Packer_Item[]                          $items      in the order the packed box walks them
		 * @return Woodev_Packer_Box_Implementation
		 */
		private function settled_box( array $dimensions, array $items ): Woodev_Packer_Box_Implementation {
			$sides  = [ $dimensions['length'], $dimensions['width'], $dimensions['height'] ];
			$needed = 0.0;
			$axes   = [ 0.0, 0.0, 0.0 ];

			foreach ( $items as $item ) {
				$needed += $item->get_volume();
				$axes    = [ max( $axes[0], $item->get_length() ), max( $axes[1], $item->get_width() ), max( $axes[2], $item->get_height() ) ];
			}

			// the items' sides are sorted longest first, the box's are too: compare them axis by axis
			foreach ( $sides as $i => $side ) {
				$sides[ $i ] = max( $side, $axes[ $i ] );
			}

			$step = 2.3e-16;

			do {
				$box = new Woodev_Packer_Box_Implementation( $sides[0], $sides[1], $sides[2], 0, null, 'virtual_box', 'Виртуальная коробка' );

				if ( $box->get_volume() >= $needed || $step > self::SETTLE_LIMIT ) {
					break;
				}

				$sides[2] *= 1 + $step;
				$step     *= 2;
			} while ( true );

			return $box;
		}

		/**
		 * The placement behind the last box, so a test or a diagnostic can verify that the units really fit
		 * without overlapping: the frame — `length`, `width`, `height` of the box as it was packed, corner at
		 * the origin — and `units`, one entry per unit, largest first (the order of {@see self::units()}),
		 * each its near and far corner [x0, y0, z0, x1, y1, z1] inside that frame. The frame's sides are the
		 * box's sides, only not sorted. Empty when the grid fallback sized the box: there is no placement.
		 *
		 * @since 2.0.2
		 * @return array{length: float, width: float, height: float, units: array<int, array<int, float>>}|array{}
		 */
		public function get_placement(): array {
			return $this->placement;
		}

		/**
		 * The box for the items: the placed box when the budget allows one, never larger than the grid box.
		 *
		 * @param  Woodev_Box_Packer_Item[] $items
		 * @return array{length: float, width: float, height: float} sides, longest first
		 */
		private function calculate_virtual_box_dimensions( array $items ): array {
			$this->placement = [];

			$units = $this->units( $items );
			$grid  = $this->grid_dimensions( $items );
			$best  = count( $units ) <= self::MAX_UNITS ? $this->fitted_dimensions( $units, $grid ) : null;

			if ( null === $best || $best[0] * $best[1] * $best[2] > $grid[0] * $grid[1] * $grid[2] + self::EPSILON ) {
				$this->placement = [];
				$best            = $grid;
			}

			return [
				'length' => $best[0],
				'width'  => $best[1],
				'height' => $best[2],
			];
		}

		/**
		 * Items as plain sorted triples [a >= b >= c], largest first — the order a person packs in.
		 *
		 * @param  Woodev_Box_Packer_Item[] $items
		 * @return array<int, array{0: float, 1: float, 2: float}>
		 */
		private function units( array $items ): array {
			$units = [];

			foreach ( $items as $item ) {
				$sides = [ (float) $item->get_length(), (float) $item->get_width(), (float) $item->get_height() ];
				rsort( $sides );
				$units[] = $sides;
			}

			// a total order: volume, then each side — the result must not depend on input order
			usort(
				$units,
				static function ( array $a, array $b ): int {
					return [ $b[0] * $b[1] * $b[2], $b[0], $b[1], $b[2] ] <=> [ $a[0] * $a[1] * $a[2], $a[0], $a[1], $a[2] ];
				}
			);

			return $units;
		}

		/**
		 * The smallest box a real placement of the units reaches, or null when the budget allowed none.
		 *
		 * @param  array<int, array{0: float, 1: float, 2: float}> $units sorted by {@see self::units()}
		 * @param  array{0: float, 1: float, 2: float}             $grid  the grid box, longest side first
		 * @return array{0: float, 1: float, 2: float}|null sides, longest first
		 */
		private function fitted_dimensions( array $units, array $grid ): ?array {
			$count       = count( $units );
			$total       = 0.0;
			$min_side    = PHP_FLOAT_MAX;
			$cap         = 0.0; // a stack of every unit standing on end always fits: the open-top ceiling
			$need_length = 0.0; // every unit must stand on its smallest face at the very least
			$need_width  = 0.0;

			foreach ( $units as $unit ) {
				$total      += $unit[0] * $unit[1] * $unit[2];
				$min_side    = min( $min_side, $unit[2] );
				$cap        += $unit[0];
				$need_length = max( $need_length, $unit[1] );
				$need_width  = max( $need_width, $unit[2] );
			}

			$attempts = min( self::MAX_ATTEMPTS, max( self::MIN_ATTEMPTS, intdiv( self::MAX_PLACEMENTS, $count ) ) );
			$results  = [];
			$placed   = [];

			foreach ( array_slice( $this->footprints( $units, $need_length, $need_width, $grid[0], $total ), 0, $attempts ) as $footprint ) {
				$fit = $this->fit( $units, $footprint[0], $footprint[1], $cap, $min_side );

				if ( null !== $fit ) {
					$results[] = [ $footprint[0], $footprint[1], $fit['height'] ];
					$placed[]  = $fit['placement'];
				}
			}

			$best = $this->choose( $results );

			if ( null !== $best ) {
				$this->placement = [
					'length' => $best[0],
					'width'  => $best[1],
					'height' => $best[2],
					'units'  => $placed[ array_search( $best, $results, true ) ],
				];
				rsort( $best );
			}

			return $best;
		}

		/**
		 * Candidate footprints, most promising first: bases built from the items' sides whose area is closest
		 * to the base of a cube holding the items' volume, so the first attempts are the compact shapes.
		 *
		 * @param  array<int, array{0: float, 1: float, 2: float}> $units
		 * @param  float                                           $need_length the smallest usable length
		 * @param  float                                           $need_width  the smallest usable width
		 * @param  float                                           $max_side    no side beyond the grid's longest
		 * @param  float                                           $total       the units' summed volume
		 * @return array<int, array{0: float, 1: float}> length >= width
		 */
		private function footprints( array $units, float $need_length, float $need_width, float $max_side, float $total ): array {
			$distinct = [];

			foreach ( $units as $unit ) {
				foreach ( $unit as $side ) {
					$distinct[ (string) round( $side, 6 ) ] = $side;
				}
			}

			$distinct = array_values( $distinct );
			rsort( $distinct );
			$distinct = array_slice( $distinct, 0, self::MAX_DISTINCT_SIDES );

			$values = [];
			$count  = count( $distinct );
			$triple = min( $count, self::MAX_TRIPLE_SIDES );

			foreach ( $distinct as $i => $a ) {
				$values[] = $a;

				for ( $j = $i; $j < $count; $j++ ) {
					$values[] = $a + $distinct[ $j ];

					if ( $j < $triple ) {
						for ( $k = $j; $k < $triple; $k++ ) {
							$values[] = $a + $distinct[ $j ] + $distinct[ $k ];
						}
					}
				}
			}

			$values = array_values(
				array_filter(
					array_unique( array_map( static fn( float $v ): float => round( $v, 6 ), $values ) ),
					static fn( float $v ): bool => $v <= $max_side + self::EPSILON && $v >= $need_width - self::EPSILON
				)
			);
			rsort( $values );

			// the base of a cube that would hold the items with a fifth of air: a compact parcel's footprint
			$target = pow( $total * 1.2, 2 / 3 );
			$list   = [];

			foreach ( $values as $length ) {
				if ( $length < $need_length - self::EPSILON ) {
					continue;
				}

				foreach ( $values as $width ) {
					if ( $width > $length + self::EPSILON ) {
						continue;
					}

					$area   = $length * $width;
					$list[] = [ $length, $width, abs( log( max( $area, 1e-9 ) ) - log( max( $target, 1e-9 ) ) ) ];
				}
			}

			usort(
				$list,
				static function ( array $a, array $b ): int {
					return [ $a[2], $a[0] * $a[1], $a[0] ] <=> [ $b[2], $b[0] * $b[1], $b[0] ];
				}
			);

			return array_map( static fn( array $f ): array => [ $f[0], $f[1] ], $list );
		}

		/**
		 * Places the units into an open-top box with the given base and tells how high the stack gets.
		 *
		 * First-fit decreasing over MAXIMAL free spaces: for each unit (largest first) the free spaces are
		 * walked deepest-bottom-left first and the unit takes the first one it fits, rotated so it lies as
		 * flat as possible. Every free space the unit now overlaps is cut into the pieces left of, right of,
		 * in front of, behind, below and above it, so the gaps around it stay available to smaller units.
		 *
		 * A free space is a 6-tuple [x0, y0, z0, x1, y1, z1] — its near and far corners.
		 *
		 * @param  array<int, array{0: float, 1: float, 2: float}> $units    sorted largest first
		 * @param  float                                           $length   base length
		 * @param  float                                           $width    base width
		 * @param  float                                           $cap      height ceiling; always enough
		 * @param  float                                           $min_side the smallest unit side: a free
		 *                                                                   space thinner than it is useless
		 * @return array{height: float, placement: array<int, array<int, float>>}|null the height reached and
		 *                 where each unit went; null if a unit found no place (cannot happen within $cap)
		 */
		private function fit( array $units, float $length, float $width, float $cap, float $min_side ): ?array {
			$eps      = self::EPSILON;
			$spaces   = [ [ 0.0, 0.0, 0.0, $length, $width, $cap ] ];
			$top      = 0.0;
			$placement = [];

			foreach ( $units as $unit ) {
				$placed = null;
				$sizes  = $this->orientations( $unit );

				foreach ( $spaces as $space ) {
					foreach ( $sizes as $size ) {
						if ( $space[0] + $size[0] <= $space[3] + $eps && $space[1] + $size[1] <= $space[4] + $eps && $space[2] + $size[2] <= $space[5] + $eps ) {
							$placed = [ $space[0], $space[1], $space[2], $space[0] + $size[0], $space[1] + $size[1], $space[2] + $size[2] ];
							break 2;
						}
					}
				}

				if ( null === $placed ) {
					return null;
				}

				$top         = max( $top, $placed[5] );
				$placement[] = $placed;
				$spaces      = $this->cut_spaces( $spaces, $placed, $min_side );
			}

			return [
				'height'    => $top,
				'placement' => $placement,
			];
		}

		/**
		 * The distinct rotations of a unit, flattest first (smallest height, then the longest side along x).
		 *
		 * @param  array{0: float, 1: float, 2: float} $unit
		 * @return array<int, array{0: float, 1: float, 2: float}> [dx, dy, dz]
		 */
		private function orientations( array $unit ): array {
			[ $a, $b, $c ] = $unit;

			$all = [ [ $a, $b, $c ], [ $b, $a, $c ], [ $a, $c, $b ], [ $c, $a, $b ], [ $b, $c, $a ], [ $c, $b, $a ] ];

			$distinct = [];

			foreach ( $all as $size ) {
				$distinct[ implode( '|', $size ) ] = $size;
			}

			return array_values( $distinct );
		}

		/**
		 * The free spaces after a unit is placed: those it overlaps are cut around it, pieces thinner than
		 * the smallest unit and pieces inside another space are dropped, and the rest is ordered
		 * deepest-bottom-left first for the next unit.
		 *
		 * This is the hot loop of the whole algorithm — hence the inlined overlap and containment tests.
		 *
		 * @param  array<int, array<int, float>> $spaces   [x0, y0, z0, x1, y1, z1] each
		 * @param  array<int, float>             $placed   [x0, y0, z0, x1, y1, z1] of the unit
		 * @param  float                         $min_side
		 * @return array<int, array<int, float>>
		 */
		private function cut_spaces( array $spaces, array $placed, float $min_side ): array {
			$eps    = self::EPSILON;
			$thin   = max( $min_side - $eps, $eps ); // a piece has to be at least this big on every axis
			$kept   = [];
			$pieces = [];

			foreach ( $spaces as $s ) {
				if (
					$placed[0] >= $s[3] - $eps || $placed[3] <= $s[0] + $eps
					|| $placed[1] >= $s[4] - $eps || $placed[4] <= $s[1] + $eps
					|| $placed[2] >= $s[5] - $eps || $placed[5] <= $s[2] + $eps
				) {
					$kept[] = $s;
					continue;
				}

				$cuts = [
					[ $s[0], $s[1], $s[2], $placed[0], $s[4], $s[5] ], // left of the unit
					[ $placed[3], $s[1], $s[2], $s[3], $s[4], $s[5] ], // right of it
					[ $s[0], $s[1], $s[2], $s[3], $placed[1], $s[5] ], // in front of it
					[ $s[0], $placed[4], $s[2], $s[3], $s[4], $s[5] ], // behind it
					[ $s[0], $s[1], $s[2], $s[3], $s[4], $placed[2] ], // below it
					[ $s[0], $s[1], $placed[5], $s[3], $s[4], $s[5] ], // above it
				];

				foreach ( $cuts as $p ) {
					if ( $p[3] - $p[0] >= $thin && $p[4] - $p[1] >= $thin && $p[5] - $p[2] >= $thin ) {
						$pieces[] = $p;
					}
				}
			}

			// a new piece inside a kept space or inside another new piece adds nothing; a kept space can
			// never lie inside a new piece, because the piece is part of a space the kept one was not in
			$result = $kept;
			$count  = count( $pieces );

			foreach ( $pieces as $i => $a ) {
				foreach ( $kept as $b ) {
					if ( $a[0] >= $b[0] - $eps && $a[1] >= $b[1] - $eps && $a[2] >= $b[2] - $eps && $a[3] <= $b[3] + $eps && $a[4] <= $b[4] + $eps && $a[5] <= $b[5] + $eps ) {
						continue 2;
					}
				}

				for ( $j = 0; $j < $count; $j++ ) {
					if ( $i === $j ) {
						continue;
					}

					$b = $pieces[ $j ];

					if ( $a[0] >= $b[0] - $eps && $a[1] >= $b[1] - $eps && $a[2] >= $b[2] - $eps && $a[3] <= $b[3] + $eps && $a[4] <= $b[4] + $eps && $a[5] <= $b[5] + $eps ) {
						// of two equal pieces the first one stays
						if ( $j < $i || $b[0] < $a[0] - $eps || $b[1] < $a[1] - $eps || $b[2] < $a[2] - $eps || $b[3] > $a[3] + $eps || $b[4] > $a[4] + $eps || $b[5] > $a[5] + $eps ) {
							continue 2;
						}
					}
				}

				$result[] = $a;
			}

			// deepest-bottom-left first: z, then y, then x; the far corner breaks the remaining ties
			$z0 = array_column( $result, 2 );
			$y0 = array_column( $result, 1 );
			$x0 = array_column( $result, 0 );
			$x1 = array_column( $result, 3 );
			$y1 = array_column( $result, 4 );
			$z1 = array_column( $result, 5 );
			array_multisort( $z0, SORT_ASC, SORT_NUMERIC, $y0, SORT_ASC, SORT_NUMERIC, $x0, SORT_ASC, SORT_NUMERIC, $x1, SORT_ASC, SORT_NUMERIC, $y1, SORT_ASC, SORT_NUMERIC, $z1, SORT_ASC, SORT_NUMERIC, $result );

			return $result;
		}

		/**
		 * The winner among placed boxes: the smallest volume, except that a box within {@see self::VOLUME_BAND}
		 * of it with a shorter longest side is preferred. Ties fall to the smaller volume, then to the sides.
		 *
		 * @param  array<int, array{0: float, 1: float, 2: float}> $results [length, width, height] of each placed box
		 * @return array{0: float, 1: float, 2: float}|null the winning entry, as given
		 */
		private function choose( array $results ): ?array {
			if ( [] === $results ) {
				return null;
			}

			$volume = static fn( array $r ): float => $r[0] * $r[1] * $r[2];
			$least  = min( array_map( $volume, $results ) );
			$band   = $least * ( 1 + self::VOLUME_BAND ) + self::EPSILON;
			$best   = null;
			$key    = static function ( array $r ) use ( $volume ): array {
				$sides = $r;
				rsort( $sides );

				return [ $sides[0], $volume( $r ), $sides[1], $sides[2] ];
			};

			foreach ( $results as $r ) {
				if ( $volume( $r ) <= $band && ( null === $best || $key( $r ) < $key( $best ) ) ) {
					$best = $r;
				}
			}

			return $best;
		}

		/**
		 * The arithmetic grid box (the pre-#1212 algorithm): the items in an a × b × c grid of the per-axis
		 * largest sizes, the arrangement with the shortest longest side and then the smallest volume. Cheap,
		 * always holds the items, and often far too big: the volume ceiling and the fallback for the placed box.
		 *
		 * @param  Woodev_Box_Packer_Item[] $items
		 * @return array{0: float, 1: float, 2: float} sides, longest first
		 */
		private function grid_dimensions( array $items ): array {
			$n = count( $items );

			// Sort each dimension independently so the largest values are assigned
			// to whichever grid axis expands the least.
			$lengths = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_length(), $items );
			$widths  = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_width(), $items );
			$heights = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_height(), $items );

			rsort( $lengths );
			rsort( $widths );
			rsort( $heights );

			// Prefix sums for O(1) slice-sum inside the double loop.
			$sum_l = array_fill( 0, $n + 1, 0.0 );
			$sum_w = array_fill( 0, $n + 1, 0.0 );
			$sum_h = array_fill( 0, $n + 1, 0.0 );
			for ( $i = 0; $i < $n; $i++ ) {
				$sum_l[ $i + 1 ] = $sum_l[ $i ] + $lengths[ $i ];
				$sum_w[ $i + 1 ] = $sum_w[ $i ] + $widths[ $i ];
				$sum_h[ $i + 1 ] = $sum_h[ $i ] + $heights[ $i ];
			}

			$best_max_dim = PHP_FLOAT_MAX;
			$best_volume  = PHP_FLOAT_MAX;
			$best         = [ $sum_l[1], $sum_w[1], $sum_h[ $n ] ]; // (1,1,N) as initial fallback

			// Enumerate every 3-D grid arrangement (a × b × c):
			// a items placed side-by-side along L, b along W, c stacked along H.
			// c is set to ceil(N / (a×b)) — the minimum layers needed to hold all items.
			for ( $a = 1; $a <= $n; $a++ ) {
				$max_b = (int) ceil( $n / $a );

				for ( $b = 1; $b <= $max_b; $b++ ) {
					$c = (int) ceil( $n / ( $a * $b ) );

					$l = $sum_l[ $a ];
					$w = $sum_w[ $b ];
					$h = $sum_h[ $c ];

					$max_dim = max( $l, $w, $h );
					$volume  = $l * $w * $h;

					if (
						$max_dim < $best_max_dim - 1e-9 ||
						( $max_dim < $best_max_dim + 1e-9 && $volume < $best_volume )
					) {
						$best_max_dim = $max_dim;
						$best_volume  = $volume;
						$best         = [ $l, $w, $h ];
					}
				}
			}

			rsort( $best );

			return [ (float) $best[0], (float) $best[1], (float) $best[2] ];
		}

		/**
		 * Сортирует товары по объему в порядке убывания.
		 *
		 * @param Woodev_Box_Packer_Item[] $items Массив товаров.
		 *
		 * @return Woodev_Box_Packer_Item[] Отсортированный массив товаров.
		 */
		private function order_items_by_volume_desc( array $items ): array {
			usort(
				$items,
				function ( Woodev_Box_Packer_Item $a, Woodev_Box_Packer_Item $b ) {
					return $b->get_volume() <=> $a->get_volume();
				}
			);

			return $items;
		}
	}

endif;
