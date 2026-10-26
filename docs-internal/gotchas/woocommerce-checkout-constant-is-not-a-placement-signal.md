# Gotcha: [woocommerce/checkout] — `WOOCOMMERCE_CHECKOUT` is defined by `update_order_review` too; "an order is being placed" is `woocommerce_before_checkout_process`

**Namespace:** `woocommerce/checkout` · **Discovered:** s199 (2026-10-26), #1381
> **Measured on:** WooCommerce 11.2.1 source — `class-wc-ajax.php:402` (`update_order_review()`), `:632`
> (`checkout()`), `class-wc-checkout.php:1381–1392` (`process_checkout()`), `class-wc-form-handler.php:527–537`.

## What happens

A rule that must apply only when the shopper PLACES the order (the #1381 guard: a city record that disagrees
with the form gives no rate and no pickup point) looks for a "we are in checkout processing" signal.
`wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true )` reads like one — and `WC_AJAX::update_order_review()`
defines it as well, on every field change. Keyed on the constant, the guard would fire on every refresh,
which is exactly the typeahead flicker the operator ruled out. Keyed on `wc-ajax=checkout` alone, it misses
the no-JS form post (`WC_Form_Handler::checkout_action()`), which processes an order without any `wc-ajax`.

## Root cause

`WOOCOMMERCE_CHECKOUT` means "a checkout CONTEXT" to WooCommerce (notices and totals behave differently),
not "an order is being created". The one thing both placement paths share is `WC_Checkout::process_checkout()`,
whose first action is `woocommerce_before_checkout_process` (then `woocommerce_checkout_process`), fired
BEFORE `update_session()` copies the posted address into `WC()->customer` and rates. Nothing else fires it.

## Fix

```php
// ❌ fires on update_order_review as well
if ( defined( 'WOOCOMMERCE_CHECKOUT' ) ) { … }

// ❌ misses the no-JS form post; true from the first byte of the request, before the posted address is in WC()->customer
if ( 'checkout' === get_query_var( 'wc-ajax' ) ) { … }

// ✅ true only once process_checkout() has started — wc-ajax=checkout AND the form post; a plain read, no hook to register
if ( did_action( 'woocommerce_before_checkout_process' ) > 0 ) { … }
```

Used by `Location_Service::is_native_address_authority()` (`woodev/shipping-method/location/class-location-service.php`).
In a Brain Monkey unit test, `do_action( 'woocommerce_before_checkout_process' )` is all it takes to put the
request "at placement".

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — the Store API side is detected by `WC()->is_store_api_request()` instead
- [a-session-merge-at-shutdown-must-not-dirty-a-clean-session](a-session-merge-at-shutdown-must-not-dirty-a-clean-session.md) — the other #1381 trap, on the merge side
