# Gotcha: [woocommerce/shipping-cache] — Cart and checkout share one cached rate set; a cart-only filter leaks both ways
> Tags: woocommerce/shipping, shipping/settings | Session: s158

## What happens

«Не показывать на странице корзины» removed the carrier's rates on the cart page — and the carrier
was then missing at checkout too (and checkout's rates reappeared on the cart). Classic and Store API.

## Root cause

`WC_Shipping::calculate_shipping_for_package()` runs `woocommerce_package_rates` only when it
recalculates, stores the ALREADY FILTERED rates in the session keyed by the package hash, and on a
hit returns them without filtering. Cart and checkout produce the same package hash, so whichever
page computes first decides for both. On a cold request packages are also collected BEFORE
`woocommerce_shipping_methods` registers the carrier's methods.

## Fix

❌ Filter in `woocommerce_package_rates` and trust it to run on every page.

✅ Partition the cache: add a context key to the package on the cart page
(`woocommerce_cart_shipping_packages`) so its hash differs, decided from the plugin's OWN setting
and the request context — never from whether its methods are registered yet. Test through the real
`WC_Shipping` cache branch with shipping debug mode OFF, both directions, cold request.

## Related

- `woodev/shipping-method/class-shipping-plugin.php` — `mark_cart_page_packages()`.
- `tests/integration/Shipping/ShippingCartPageRateCacheTest.php`
