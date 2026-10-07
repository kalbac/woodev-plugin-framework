# Gotcha: [woocommerce/shipping-cache] — Changing the payment method recalculates nothing, classic or blocks; a payment-dependent rate needs the method in the package AND a trigger
> Tags: woocommerce/shipping, shipping/settings | Session: s159

## What happens

A shipping fee that depends on the chosen payment method (`fee_payments`, #1144) keeps showing the rate for the
PREVIOUS method after the customer switches gateway, and the order is then priced differently from what was shown.

## Root cause

Three independent facts (WooCommerce 11.1.2):

1. **The package hash does not contain the payment method.** `WC_Shipping::calculate_shipping_for_package()`
   (`includes/class-wc-shipping.php`) serves the stored rates while `get_package_hash()` is unchanged.
2. **Classic:** `checkout.js` `payment_method_selected` only toggles the gateway box and fires the body event
   `payment_method_selected`; it never triggers `update_checkout`, and `update_totals_on_change` is not on the gateway
   radios. `WC_AJAX::update_order_review()` (`includes/class-wc-ajax.php:420`) copies `$_POST['payment_method']` into
   `chosen_payment_method` — but only when something else triggered the update.
3. **Blocks:** the session's `chosen_payment_method` is written only by the checkout POST
   (`StoreApi/Utilities/CheckoutTrait.php:182`, `Routes/V1/Checkout.php:472`), never when the shopper changes gateway.

## Fix

❌ Read `WC()->session->get( 'chosen_payment_method' )` inside the rate code and trust it to be current.

✅ Put the method on every package (`woocommerce_cart_shipping_packages`) so it is part of the hash, and send it
explicitly: classic → a `change` listener on `input[name="payment_method"]` that triggers `update_checkout`; blocks →
`extensionCartUpdate()` to a `woocommerce_store_api_register_update_callback` callback that sets the session value
(the route recalculates the cart, shipping included — `StoreApi/Schemas/V1/CartExtensionsSchema.php`
`get_item_response()`). Both only while some method instance actually uses the option — see `Fee_Payments`.

⚠ Never define `WC()` in a unit test (`Functions\when( 'WC' )`): Brain Monkey leaves the function defined for the rest of
the process and ~300 later tests that guard on `function_exists( 'WC' )` fail. Inject a stand-in instead.

## Related

- [cart-and-checkout-share-one-shipping-rate-cache-entry](cart-and-checkout-share-one-shipping-rate-cache-entry.md)
- `woodev/shipping-method/class-fee-payments.php`, `src/checkout-blocks/payment-recalc.ts`
