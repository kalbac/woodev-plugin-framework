<?php
/**
 * Woodev Pickup Point Constraint Checker
 *
 * Decides whether a pickup point can be selected for the current cart and payment method.
 * Cash-on-delivery support and weight limits exist at every carrier this framework
 * targets (CDEK, Yandex, OZON) so they are framework mechanism, not plugin domain.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Constraint_Checker' ) ) :

	/**
	 * Computes a point's selectable/blocked verdict.
	 *
	 * The verdict travels to the browser alongside the point (`selectable: { allowed, reason }`)
	 * and is rendered there — greyed out, with the reason shown in the balloon — never
	 * re-evaluated client-side. That client-side gate is UX only: a later checkout-processing
	 * step re-checks the chosen point server-side, because a client gate must never be the
	 * only gate.
	 *
	 * @since 2.0.2
	 */
	class Constraint_Checker {

		/**
		 * Payment method ids treated as cash on delivery.
		 *
		 * @var string[]
		 */
		private array $cod_methods;

		/**
		 * Supplies the order's items for the storage-cell check: `fn(): Woodev_Packer_Packable_Item[]`, sizes in cm.
		 *
		 * @var callable
		 */
		private $parcel_items;

		/**
		 * The order, prepared once for the cell check (see {@see self::parcel()}); null until first needed.
		 *
		 * @var array{known: bool, units: array<int, array<int, float>>, count: int, sides: float[], volume: float,
		 *            min_side: float}|null
		 */
		private ?array $parcel = null;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Optional `$parcel_items` (issue #1215).
		 *
		 * @param string[]      $cod_methods  Payment method ids treated as cash on delivery.
		 *                                    Defaults to `[ 'cod' ]`, WooCommerce core's own id.
		 * @param callable|null $parcel_items `fn(): Woodev_Packer_Packable_Item[]` — the order's items, sizes in cm,
		 *                                    read only when a point has storage cells and read once per checker.
		 *                                    Defaults to the live WooCommerce cart.
		 */
		public function __construct( array $cod_methods = [ 'cod' ], ?callable $parcel_items = null ) {
			$this->cod_methods  = $cod_methods;
			$this->parcel_items = $parcel_items ?? [ $this, 'cart_items' ];
		}

		/**
		 * Checks whether a point can be selected for the given payment method and cart weight.
		 *
		 * Both `$point`'s max weight and `$cart_weight` are in GRAMS. WooCommerce's own weight
		 * unit is a store setting (`woocommerce_weight_unit` — kg, g, lbs, or oz); converting
		 * to grams is the caller's responsibility. This method never converts, so passing a
		 * cart weight in the store's configured unit will silently mis-gate real orders.
		 *
		 * Unknown constraint data is permissive: a carrier's list response frequently omits
		 * `accepts_cod`/`max_weight` (they arrive only with a details call), so a point whose
		 * inputs are unknown is emitted as selectable rather than incorrectly greyed out. The
		 * server re-check at checkout processing remains the backstop. A `max_weight` of zero
		 * or a negative number is likewise treated as "no limit", not as the most restrictive
		 * limit possible: several carriers encode "no limit" as `0`, and {@see Pickup_Point}
		 * casts whatever the carrier sent, so non-numeric junk (e.g. `"n/a"`) also lands as
		 * `0` — a gate deriving "the tightest possible limit" from that value would invert the
		 * "unknown is permissive" rule this method exists to uphold.
		 *
		 * When a point violates both constraints, the weight reason wins, not the COD one.
		 * Weight is the unfixable constraint at the point picker — nothing the customer does at
		 * checkout clears it except removing items from the cart — while COD is fixable by
		 * switching payment method. Showing the fixable reason first would send the customer to
		 * change gateway and walk into a second, unfixable wall; showing the unfixable one first
		 * tells them immediately that this point cannot take this order at all. The weight check
		 * therefore runs first and short-circuits the COD check once the verdict is blocked.
		 *
		 * A point that publishes storage cells (a parcel locker) must also be able to hold the order: the
		 * order's items are really PLACED into each cell in turn, as into a box of fixed size
		 * ({@see \Woodev_Packer_Free_Space}, every rotation, deterministic), and the point is refused only when
		 * NO cell takes them. That check is unfixable by the customer too, so it comes after weight and before COD.
		 * It stays permissive like the rest: no cells, no items, an item without a size, or a cart that cannot be
		 * read all mean "cannot prove it does not fit" and give no size verdict. A cell may also carry its own
		 * weight limit, compared with `$cart_weight`. Above {@see \Woodev_Packer_Free_Space::MAX_UNITS} units the
		 * placement is skipped and the order must pass the cheaper necessary test — every item fits the cell
		 * side by side and the summed volume fits.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point          The point being evaluated.
		 * @param string       $payment_method The chosen WooCommerce payment method id.
		 * @param int          $cart_weight    Current cart weight in GRAMS (not the store's
		 *                                     configured weight unit — the caller converts).
		 *
		 * @return array{allowed: bool, reason: string|null}
		 */
		public function check( Pickup_Point $point, string $payment_method, int $cart_weight ): array {
			$verdict = [
				'allowed' => true,
				'reason'  => null,
			];

			$max_weight = $point->get_max_weight();

			if ( null !== $max_weight && $max_weight > 0 && $cart_weight > $max_weight ) {
				$verdict = [
					'allowed' => false,
					'reason'  => sprintf(
						/* translators: 1: cart weight in kg, 2: point weight limit in kg */
						__(
							'The order weight of %1$s kg exceeds the pickup point limit of %2$s kg.',
							'woodev-plugin-framework'
						),
						number_format_i18n( $cart_weight / 1000, 2 ),
						number_format_i18n( $max_weight / 1000, 2 )
					),
				];
			}

			// Size comes right after weight and before COD for the same reason weight does: the customer cannot
			// fix it at checkout by switching gateway. A point with no cells is never size-checked (#1215).
			if ( $verdict['allowed'] && [] !== $point->get_cells() && ! $this->fits_a_cell( $point->get_cells(), $cart_weight ) ) {
				$verdict = [
					'allowed' => false,
					'reason'  => __( 'The order does not fit the cells of this parcel locker', 'woodev-plugin-framework' ),
				];
			}

			if ( $verdict['allowed']
				&& false === $point->get_accepts_cod()
				&& in_array( $payment_method, $this->cod_methods, true )
			) {
				// Concatenated rather than a single literal: one string long enough to name
				// both the problem and the fix does not fit the 120-char line limit at this
				// indent depth, and phpcs' Generic.Files.LineLength is a suppressed warning
				// here (see phpcs.xml), not a hard gate — this file still targets 120 by hand.
				$verdict = [
					'allowed' => false,
					'reason'  => __(
						'This pickup point does not accept cash on delivery.'
						. ' Choose another point or another payment method.',
						'woodev-plugin-framework'
					),
				];
			}

			/**
			 * Filters a pickup point's selectable verdict.
			 *
			 * A filter must return `array{allowed: bool, reason: string|null}` to take effect.
			 * Any other return — a non-array, a missing or wrongly-typed `allowed`, a missing
			 * `reason` key, or a `reason` that is neither a string nor null — is silently
			 * discarded in favour of the framework's computed verdict: the filter fails closed,
			 * with no error and no notice raised to the integrator. Extra keys returned
			 * alongside the two expected ones are dropped, not merged into the verdict that
			 * reaches the browser as `selectable` in the point's JSON payload.
			 *
			 * @since 2.0.2
			 *
			 * @param array{allowed: bool, reason: string|null} $verdict        The computed
			 *                                                                  verdict.
			 * @param Pickup_Point                               $point          The point being
			 *                                                                  evaluated.
			 * @param string                                     $payment_method The chosen
			 *                                                                  WooCommerce
			 *                                                                  payment method id.
			 * @param int                                        $cart_weight    Current cart
			 *                                                                  weight in grams.
			 */
			$filtered = apply_filters(
				'woodev_shipping_pickup_point_selectable',
				$verdict,
				$point,
				$payment_method,
				$cart_weight
			);

			return self::sanitize_verdict( $filtered, $verdict );
		}

		/**
		 * Converts a weight expressed in the store's configured unit into GRAMS.
		 *
		 * Both {@see self::check()}'s `$cart_weight` parameter and a {@see Pickup_Point}'s
		 * own `max_weight` are GRAMS by contract — this is the single conversion authority
		 * every caller of that contract must go through (the checkout-process re-check in
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler}, and the REST controller's
		 * injected cart-weight callable), never a raw pass-through of `$weight`. WooCommerce's
		 * own weight unit is a store setting (`woocommerce_weight_unit` — kg, g, lbs, or oz);
		 * `wc_get_weight( $weight, 'g' )` is the same conversion authority WooCommerce itself
		 * uses, so every caller compares against `max_weight` in the same unit regardless of
		 * the store's configured unit.
		 *
		 * @since 2.0.2
		 *
		 * @param float|int|string $weight weight in the store's configured unit.
		 *
		 * @return int weight in grams.
		 */
		public static function to_grams( $weight ): int {
			return (int) wc_get_weight( $weight, 'g' );
		}

		/**
		 * Whether the order can be held by at least one of the cells — `false` ONLY when that is proven.
		 *
		 * @since 2.0.2
		 *
		 * @param array[] $cells       The point's cells ({@see Pickup_Point::get_cells()}).
		 * @param int     $cart_weight Order weight in GRAMS, 0 when unknown.
		 *
		 * @return bool
		 */
		private function fits_a_cell( array $cells, int $cart_weight ): bool {
			$parcel = $this->parcel();

			if ( ! $parcel['known'] ) {
				return true;
			}

			foreach ( $cells as $cell ) {
				if ( null !== $cell['max_weight'] && $cart_weight > $cell['max_weight'] ) {
					continue;
				}

				$sides = [ $cell['length'], $cell['width'], $cell['height'] ];

				if ( $this->cell_holds( $sides, $parcel ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether one cell holds the prepared order: the side-and-volume test first (a refusal it gives is final),
		 * then — for up to {@see \Woodev_Packer_Free_Space::MAX_UNITS} units — a real placement.
		 *
		 * @since 2.0.2
		 *
		 * @param float[] $sides  The cell's three sides, in any order.
		 * @param array   $parcel The order from {@see self::parcel()}.
		 *
		 * @return bool
		 */
		private function cell_holds( array $sides, array $parcel ): bool {
			rsort( $sides );

			$eps = \Woodev_Packer_Free_Space::EPSILON;

			// every item fits the cell on its own (sorted sides against sorted sides), and the volumes add up
			foreach ( [ 0, 1, 2 ] as $rank ) {
				if ( $parcel['sides'][ $rank ] > $sides[ $rank ] + $eps ) {
					return false;
				}
			}

			if ( $parcel['volume'] > $sides[0] * $sides[1] * $sides[2] + $eps ) {
				return false;
			}

			if ( [] === $parcel['units'] ) {
				// over the placement threshold: the necessary conditions above are all that is checked
				return true;
			}

			// the cell as given and its other axis orders: one greedy pass can fail where another orientation tiles
			$orders = [];

			foreach ( [ [ 0, 1, 2 ], [ 1, 0, 2 ], [ 0, 2, 1 ], [ 2, 0, 1 ], [ 1, 2, 0 ], [ 2, 1, 0 ] ] as $axes ) {
				$order = [ $sides[ $axes[0] ], $sides[ $axes[1] ], $sides[ $axes[2] ] ];

				$orders[ implode( '|', $order ) ] = $order;
			}

			foreach ( $orders as $order ) {
				$space  = new \Woodev_Packer_Free_Space( $order[0], $order[1], $order[2], $parcel['min_side'] );
				$placed = true;

				foreach ( $parcel['units'] as $unit ) {
					if ( null === $space->place( $unit ) ) {
						$placed = false;
						break;
					}
				}

				if ( $placed ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The order prepared for the cell check, built once per checker.
		 *
		 * `known` is false — no size verdict at all — when there is nothing to place or an item has a side that is
		 * not a positive number: a parcel whose size is not known cannot be proven too big. `units` are the
		 * physical units (an item of quantity three is three), biggest first so that the large ones take their
		 * space before the small ones fill the gaps; it is `[]` above {@see \Woodev_Packer_Free_Space::MAX_UNITS}
		 * units, where only `sides` (the largest of each item's longest, middle and shortest side) and `volume`
		 * are used.
		 *
		 * @since 2.0.2
		 *
		 * @return array{known: bool, units: array<int, array<int, float>>, count: int, sides: float[], volume: float, min_side: float}
		 */
		private function parcel(): array {
			if ( null !== $this->parcel ) {
				return $this->parcel;
			}

			$parcel = [
				'known'    => false,
				'units'    => [],
				'count'    => 0,
				'sides'    => [ 0.0, 0.0, 0.0 ],
				'volume'   => 0.0,
				'min_side' => 0.0,
			];

			$items = ( $this->parcel_items )();

			if ( ! is_array( $items ) || [] === $items ) {
				return $this->parcel = $parcel;
			}

			$shapes   = [];
			$min_side = PHP_FLOAT_MAX;

			foreach ( $items as $item ) {
				if ( ! $item instanceof \Woodev_Packer_Packable_Item ) {
					return $this->parcel = $parcel;
				}

				$sides = [ $item->get_length(), $item->get_width(), $item->get_height() ];

				foreach ( $sides as $side ) {
					if ( ! is_finite( $side ) || $side <= 0.0 ) {
						return $this->parcel = $parcel; // an item without a size: nothing can be proven
					}
				}

				rsort( $sides );

				$quantity = max( 1, $item->get_quantity() );

				$parcel['count']  += $quantity;
				$parcel['volume'] += $sides[0] * $sides[1] * $sides[2] * $quantity;
				$min_side          = min( $min_side, $sides[2] );

				foreach ( [ 0, 1, 2 ] as $rank ) {
					$parcel['sides'][ $rank ] = max( $parcel['sides'][ $rank ], $sides[ $rank ] );
				}

				$shapes[] = [ $sides, $quantity ];
			}

			$parcel['known']    = true;
			$parcel['min_side'] = $min_side;

			if ( $parcel['count'] <= \Woodev_Packer_Free_Space::MAX_UNITS ) {
				// biggest volume first; equal volumes keep the order of the items, so the answer is deterministic
				$order = array_keys( $shapes );

				usort(
					$order,
					static function ( int $a, int $b ) use ( $shapes ): int {
						$volume_a = $shapes[ $a ][0][0] * $shapes[ $a ][0][1] * $shapes[ $a ][0][2];
						$volume_b = $shapes[ $b ][0][0] * $shapes[ $b ][0][1] * $shapes[ $b ][0][2];

						return $volume_b <=> $volume_a ?: $a <=> $b;
					}
				);

				foreach ( $order as $index ) {
					for ( $i = 0; $i < $shapes[ $index ][1]; $i++ ) {
						$parcel['units'][] = $shapes[ $index ][0];
					}
				}
			}

			return $this->parcel = $parcel;
		}

		/**
		 * The default supplier of the order's items: the live WooCommerce cart, sizes converted to cm. Empty
		 * when WooCommerce or its cart is not there — which gives no size verdict.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev_Packer_Packable_Item[]
		 */
		private function cart_items(): array {
			if ( ! function_exists( 'WC' ) || ! class_exists( '\\Woodev_WC_Packer_Dispatcher' ) ) {
				return [];
			}

			$cart = WC()->cart ?? null;

			return $cart ? \Woodev_WC_Packer_Dispatcher::from_cart_items( $cart->get_cart() ) : [];
		}

		/**
		 * Validates a filtered verdict and fails closed to the computed one when malformed.
		 *
		 * A third-party filter can return anything — a string, an object, an array missing
		 * `allowed`, or a non-bool/non-string-or-null shape. Every downstream reader does
		 * `$verdict['allowed']`, so a malformed return must never reach the caller: it would
		 * become an undefined-index notice at best and a silently permissive verdict at worst.
		 * A well-formed return is rebuilt key-by-key rather than passed through, so any extra
		 * key a filter adds is dropped rather than reaching the browser.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                                     $filtered The filter's return value.
		 * @param array{allowed: bool, reason: string|null} $computed The pre-filter verdict,
		 *                                                             used as the fail-closed
		 *                                                             fallback.
		 *
		 * @return array{allowed: bool, reason: string|null}
		 */
		private static function sanitize_verdict( $filtered, array $computed ): array {
			/*
			 * {@see Selection_Result::sanitize()} carries a deliberate COPY of the four guards
			 * below — see its own note for why the two are not shared (this method is private,
			 * and the two validate the same keys in service of different contracts, so they are
			 * entitled to diverge on purpose). If you change what counts as a well-formed
			 * verdict here, go and decide explicitly whether that one should follow.
			 */
			if ( ! is_array( $filtered ) ) {
				return $computed;
			}

			if ( ! array_key_exists( 'allowed', $filtered ) || ! is_bool( $filtered['allowed'] ) ) {
				return $computed;
			}

			if ( ! array_key_exists( 'reason', $filtered ) ) {
				return $computed;
			}

			if ( null !== $filtered['reason'] && ! is_string( $filtered['reason'] ) ) {
				return $computed;
			}

			return [
				'allowed' => $filtered['allowed'],
				'reason'  => $filtered['reason'],
			];
		}
	}

endif;
