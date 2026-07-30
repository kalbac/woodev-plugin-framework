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
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $cod_methods Payment method ids treated as cash on delivery.
		 *                               Defaults to `[ 'cod' ]`, WooCommerce core's own id.
		 */
		public function __construct( array $cod_methods = [ 'cod' ] ) {
			$this->cod_methods = $cod_methods;
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
							'Вес заказа %1$s кг превышает ограничение пункта выдачи — %2$s кг.',
							'woodev-plugin-framework'
						),
						number_format_i18n( $cart_weight / 1000, 2 ),
						number_format_i18n( $max_weight / 1000, 2 )
					),
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
						'В этом пункте выдачи недоступна оплата при получении.'
						. ' Выберите другой пункт или другой способ оплаты.',
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
