<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Free_Space' ) ) :

	/**
	 * A fixed-size container that units are really PLACED into, one after another — the placement machinery
	 * of the virtual box ({@see Woodev_Packer_Virtual_Box}, #1212) lifted out so a box whose size is given
	 * (a merchant's box, a parcel-locker cell) can answer «does this item still fit» with a real position
	 * instead of a volume sum (#1214).
	 *
	 * The free space is tracked as a set of MAXIMAL empty boxes. A unit takes the first free space it fits,
	 * walked deepest-bottom-left first, in any of its six rotations (lying flat when it can); every free space
	 * the unit now overlaps is cut into the pieces left of, right of, in front of, behind, below and above it,
	 * so the gaps around it stay available to smaller units. A unit that fits nowhere is refused and the
	 * container is left as it was. The work is deterministic: the same units in the same order give the same
	 * placement.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Packer_Free_Space {

		/** Float comparison tolerance (sizes are cm with at most a few decimals). */
		const EPSILON = 1e-6;

		/**
		 * The most units a caller places one by one: the work grows with the units AND the free spaces, and it is
		 * bounded by counts, never by the clock, so the same cart gives the same answer on a slow server and a
		 * fast one. Above it the callers fall back to the arithmetic they had before the placement (#1212, #1214).
		 */
		const MAX_UNITS = 120;

		/**
		 * Free spaces, [x0, y0, z0, x1, y1, z1] each — their near and far corners.
		 *
		 * @var array<int, array<int, float>>
		 */
		private $spaces;

		/** @var float */
		private $min_side;

		/**
		 * @since 2.0.2
		 *
		 * @param float $length   container length
		 * @param float $width    container width
		 * @param float $height   container height
		 * @param float $min_side the smallest side of any unit that will be placed: a free space thinner than
		 *                        it is useless and is dropped
		 */
		public function __construct( float $length, float $width, float $height, float $min_side ) {
			$this->spaces   = [ [ 0.0, 0.0, 0.0, $length, $width, $height ] ];
			$this->min_side = $min_side;
		}

		/**
		 * Places a unit into the first free space it fits and returns where it went.
		 *
		 * @since 2.0.2
		 *
		 * @param  array{0: float, 1: float, 2: float} $unit sides, in any order (the rotations are tried longest side first)
		 * @return array<int, float>|null the unit's near and far corner [x0, y0, z0, x1, y1, z1], or null
		 *                                when it fits nowhere (the container is unchanged)
		 */
		public function place( array $unit ): ?array {
			$eps    = self::EPSILON;
			$placed = null;

			rsort( $unit );

			$sizes = $this->orientations( $unit );

			foreach ( $this->spaces as $space ) {
				foreach ( $sizes as $size ) {
					if ( $space[0] + $size[0] <= $space[3] + $eps && $space[1] + $size[1] <= $space[4] + $eps && $space[2] + $size[2] <= $space[5] + $eps ) {
						$placed = [ $space[0], $space[1], $space[2], $space[0] + $size[0], $space[1] + $size[1], $space[2] + $size[2] ];
						break 2;
					}
				}
			}

			if ( null !== $placed ) {
				$this->spaces = $this->cut_spaces( $this->spaces, $placed, $this->min_side );
			}

			return $placed;
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
	}

endif;
