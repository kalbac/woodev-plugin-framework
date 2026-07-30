<?php
/**
 * Woodev Pickup Point Address Target Resolver
 *
 * Decides which checkout fieldset — `billing_*` or `shipping_*` — receives a selected
 * pickup point's address, by following WooCommerce's own posted-address resolution
 * rather than deriving the answer from a setting of our own.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Address_Target' ) ) :

	/**
	 * Resolves the checkout fieldset that a pickup point's address is written into.
	 *
	 * This is not a decision this framework makes on its own — WooCommerce's checkout
	 * already has an answer, in `WC_Checkout::get_posted_address_data()`: the shipping
	 * value falls back to the billing value whenever `ship_to_different_address` is
	 * false, and that flag is itself forced false in "force shipping to billing" mode
	 * (`wc_ship_to_billing_address_only()`), which also drops the shipping fieldset from
	 * the form entirely. Following WooCommerce's own resolution — rather than deriving
	 * an equivalent check from the raw `woocommerce_ship_to_destination` option — is what
	 * keeps this correct under every store configuration without a setting of our own,
	 * and keeps it from silently drifting if WooCommerce ever changes those semantics.
	 *
	 * | Store configuration                              | Target     |
	 * |--------------------------------------------------|------------|
	 * | Force shipping to billing (`billing_only`)       | `billing`  |
	 * | Default, "ship to a different address" unchecked | `billing`  |
	 * | "Ship to a different address" checked            | `shipping` |
	 *
	 * The first two rows share a target for different reasons: in `billing_only` mode
	 * there is no shipping fieldset to write into at all, while in the default mode the
	 * fieldset exists but WooCommerce copies billing to shipping itself. Only the third
	 * row leaves billing and shipping genuinely distinct, and there this class does not
	 * touch billing — a plain rule that always wrote `shipping_*` would write nowhere
	 * visible for merchants running `billing_only`, which is the recommended
	 * configuration in Russia and the CIS, where a separate "billing address" concept
	 * does not exist in practice.
	 *
	 * This class only names the target fieldset. It does not write to it: the consuming
	 * checkout-field-layer task resolves the target into field writes through the
	 * layer's own field registry (e.g. a bounded-option select, or a WooCommerce-owned
	 * field such as `billing_state`), never a raw DOM assignment.
	 *
	 * @since 2.0.2
	 */
	class Address_Target {

		/**
		 * Resolves the fieldset prefix that a pickup point's address should target.
		 *
		 * The flag has two legitimate sources and they are not interchangeable. During
		 * `woocommerce_checkout_process` the authoritative value is
		 * `WC()->checkout()->get_posted_data()['ship_to_different_address']`, which
		 * WooCommerce has already ANDed with `! wc_ship_to_billing_address_only()` — so the
		 * `billing_only` guard below is redundant there, harmlessly. At page-render time the
		 * checkbox default comes from the `woocommerce_ship_to_different_address_checked`
		 * filter in `templates/checkout/form-shipping.php`, and the guard is load-bearing.
		 *
		 * Because the checkbox is live, a target resolved at render time goes stale the
		 * moment the customer ticks it. A caller that ships the answer to the browser must
		 * send the `billing_only` half and re-apply this rule against the checkbox at write
		 * time, rather than baking in a resolved prefix.
		 *
		 * @since 2.0.2
		 *
		 * @param bool $ship_to_different_address Whether the customer ticked "ship to a
		 *                                         different address" on the checkout form.
		 *
		 * @return string Either `billing` or `shipping` — a field-name prefix a caller
		 *                concatenates into e.g. `billing_city` / `shipping_city`.
		 */
		public static function resolve( bool $ship_to_different_address ): string {
			if ( wc_ship_to_billing_address_only() ) {
				return 'billing';
			}

			return $ship_to_different_address ? 'shipping' : 'billing';
		}
	}

endif;
