<?php
/**
 * Shared pickup confirmation and constraint validation.
 *
 * @package Woodev\Framework\Shipping\Pickup
 * @since 2.0.2
 */
namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Selection_Service' ) ) :

	/**
	 * Applies the same domain pipeline to classic and Store API selections.
	 *
	 * @since 2.0.2
	 */
	final class Pickup_Selection_Service {
		/**
		 * Checks live payment and weight constraints.
		 *
		 * @since 2.0.2
		 * @param Pickup_Point $point Point fetched from the carrier.
		 * @param string       $payment_method Server-validated gateway id.
		 * @param int          $cart_weight Cart weight in grams.
		 * @return array{allowed: bool, reason: string|null}
		 */
		public static function check( Pickup_Point $point, string $payment_method, int $cart_weight ): array {
			return ( new Constraint_Checker() )->check( $point, $payment_method, $cart_weight );
		}

		/**
		 * Confirms a point and announces only the final allowed, corrected selection.
		 *
		 * @since 2.0.2
		 * @param Pickup_Point                                                                         $point Authoritative carrier point.
		 * @param array{field_id: string, method_id: string, payment_method: string, cart_weight: int} $context Server context.
		 * @param callable|null                                                                        $accept_point Optional server scope guard for the corrected point.
		 * @param bool                                                                                 $announce Whether to fire persistence after an allowed verdict.
		 * @return array<string, mixed> Existing classic reply shape.
		 */
		public static function confirm( Pickup_Point $point, array $context, ?callable $accept_point = null, bool $announce = true ): array {
			$computed = Selection_Result::from_verdict(
				self::check( $point, $context['payment_method'], $context['cart_weight'] )
			);

			/**
			 * Filters the result of confirming one pickup point.
			 *
			 * This runs ONCE per confirmation, not once per drawn point, and it is therefore
			 * the one place in the pickup flow a plugin MAY call the carrier. Its sibling
			 * `woodev_shipping_pickup_point_selectable` runs while DRAWING the list — once per
			 * point, on every map pan — and must stay cheap; do not confuse the two.
			 *
			 * It is also the last cheap moment to catch a constraint only the carrier knows.
			 * {@see Constraint_Checker} treats unknown constraint data as PERMISSIVE by design
			 * (a carrier's list response routinely omits `accepts_cod`/`max_weight`), so a
			 * point the framework reports as selectable may still be one this carrier refuses
			 * for this order. After this, the next gate is the checkout POST itself.
			 *
			 * MALFORMED RETURNS FAIL CLOSED, SILENTLY: a return that is not an array, or whose
			 * `allowed`/`reason` pair is missing or wrongly typed, reverts to `$computed`
			 * entirely — no warning, no notice. Nothing tells you your filter was ignored, so
			 * match the documented shape exactly; see
			 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize()} for the
			 * two-tier rule (verdict all-or-nothing, advice normalised key by key).
			 *
			 * `close` and `refresh_checkout` are THREE-STATE, not booleans:
			 * - leave them `null` (or omit them) to DEFER to the plugin's configured default;
			 * - return an explicit `true`/`false` to decide this one selection.
			 * An explicit `false` is preserved as `false` — it is a decision ("do not close"),
			 * never re-read as the unspoken `null`, which would hand control straight back to
			 * the default you just overrode.
			 *
			 * `point` is a CORRECTED POINT, not a flag. The browser replaces the point it is
			 * holding with whatever comes back here, so it must be the same shape
			 * {@see Pickup_Point::to_browser_array()} emits on the two read routes — i.e.
			 * {@see Pickup_Point::to_browser_array()} (`id`, `name`, `address`,
			 * `short_address`, `locality`, `postal_code`, `phone`, `instruction`, `work_time`,
			 * `point_short_name`, `lat`, `lng`, `type` => `{ code, label }`, `payment_methods`,
			 * `services`, `photos`, `accepts_cod`, `max_weight`). The easy, correct way to build one is to
			 * mutate the freshly-resolved `$point` and call `to_browser_array()` on it yourself
			 * rather than assembling the keys by hand.
			 *
			 * Whatever you return is REBUILT through {@see Pickup_Point::from_array()} and
			 * re-serialized before it leaves this route, so the browser never receives an
			 * unescaped or unknown field regardless of what a filter hands over — the browser
			 * does not re-escape these strings, so nothing else would. Three things follow:
			 * a point that does not satisfy `from_array()`'s own validation is dropped as if
			 * you had returned `null` (the verdict beside it still stands); a key the point
			 * shape does not know is dropped; and the `selectable` entry is derived from this
			 * result's own `allowed`/`reason` rather than read from your array, so the two can
			 * never disagree. Returning an already-escaped `to_browser_array()` shape is safe —
			 * `esc_html()` does not double-encode. See
			 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize_point()}.
			 *
			 * Populate it when confirmation taught the domain something the listing did not
			 * know — the carrier returned a refined address or a corrected postcode for this
			 * point, say. Leave it `null` (the default) to mean "nothing to update, keep the
			 * point you already have"; `null` is not "clear the point".
			 *
			 * @since 2.0.2
			 *
			 * @param array{
			 *     allowed: bool,
			 *     reason: string|null,
			 *     close: bool|null,
			 *     refresh_checkout: bool|null,
			 *     point: array<string, mixed>|null,
			 * }                    $computed The framework's own result: its
			 *                                {@see Constraint_Checker} verdict, with all three
			 *                                advice fields still unspoken.
			 * @param Pickup_Point   $point    The point being confirmed, freshly resolved from
			 *                                 the carrier.
			 * @param array{
			 *     field_id: string,
			 *     method_id: string,
			 *     payment_method: string,
			 *     cart_weight: int,
			 * }                    $context  The checkout field, the chosen shipping method
			 *                                (from the framework, never from the request), the
			 *                                chosen gateway, and the cart weight in GRAMS.
			 *                                `method_id` is the BARE method id, `:instance_id`
			 *                                already stripped — and it is `''` whenever
			 *                                WooCommerce cannot yet tell us (no session
			 *                                started, no rate chosen); a domain keying off it
			 *                                must treat `''` as "unknown", never match it
			 *                                against a real method id. Same for
			 *                                `payment_method`, and `cart_weight` is `0` on a
			 *                                cart that is not loaded — see
			 *                                the server cart weight.
			 */
			$filtered  = apply_filters( 'woodev_shipping_pickup_point_selection', $computed, $point, $context );
			$sanitized = Selection_Result::sanitize( $filtered, $computed );

			// Fired AFTER the domain filter above and AFTER sanitize() has resolved the
			// FINAL verdict — never off `$computed`, which predates whatever a domain
			// filter just decided. A domain filter is free to FLIP `allowed`, and a
			// point it just refused must never be remembered; gating on the sanitized,
			// post-filter result is what makes that true regardless of what any
			// listener here does. Not the `woodev_shipping_pickup_point_selection`
			// filter itself: that contract is the domain VOLUNTEERING advice on top of
			// a verdict, not a side-effect seam, and this controller stays free of
			// WooCommerce globals either way — firing an action is not reading one.
			if ( true === $sanitized['allowed'] ) {
				// The EFFECTIVE point, not the pre-filter one. The filter above may return a
				// corrected point, and its own contract says the browser REPLACES what it is
				// holding with that — so the address replacement, and with it whatever the
				// checkout later reports as the current locality, follow the CORRECTED point.
				// A listener keying off the pre-filter point would then file the selection
				// under a locality the checkout no longer reports, and the restore would miss
				// silently. `sanitize_point()` already validated this exact array through
				// `Pickup_Point::from_array()`; rebuilding it here re-runs that one method
				// rather than duplicating its rules, and a non-null `$sanitized['point']` is
				// precisely the signal that it validated.
				$effective_point = $point;

				if ( null !== $sanitized['point'] && is_array( $filtered['point'] ?? null ) ) {
					$corrected = Pickup_Point::from_array( $filtered['point'] );

					if ( null !== $corrected ) {
						$effective_point = $corrected;
					}
				}

				if ( null !== $accept_point && ! $accept_point( $effective_point ) ) {
					$sanitized['allowed'] = false;
					$sanitized['reason'] = __( 'The chosen pickup point is no longer available. Please choose a pickup point again.', 'woodev-plugin-framework' );
					return $sanitized;
				}
				if ( $announce ) {
					self::announce( $effective_point, $context );
				}
			}
			return $sanitized;
		}

		/**
		 * Announces an allowed selection after all commands have been validated.
		 *
		 * @since 2.0.2
		 * @param Pickup_Point         $point Confirmed effective point.
		 * @param array<string, mixed> $context Server selection context.
		 * @return void
		 */
		public static function announce( Pickup_Point $point, array $context ): void {
			/**
			 * Fires after a pickup point selection has been confirmed and allowed —
			 * the write side of pickup-selection persistence (issue #176).
			 *
			 * @since 2.0.2
			 *
			 * @param Pickup_Point         $point   The confirmed point, INCLUDING any
			 *                                       correction a domain filter applied
			 *                                       above — this is the point the browser
			 *                                       ends up holding, so a listener that
			 *                                       derives a storage key from it agrees
			 *                                       with what the checkout will report.
			 * @param array{
			 *     field_id: string,
			 *     method_id: string,
			 *     payment_method: string,
			 *     cart_weight: int,
			 * }                           $context Same shape as the
			 *                                       `woodev_shipping_pickup_point_selection`
			 *                                       filter's own `$context` — see
			 *                                       that filter's docblock just
			 *                                       above for what each key means,
			 *                                       including why `method_id` is
			 *                                       already the bare, normalized id.
			 */
			do_action( 'woodev_shipping_pickup_point_selected', $point, $context );
		}
	}

endif;
