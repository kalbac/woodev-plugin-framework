# Core «Local pickup» exists only when the STORE'S checkout page holds the Checkout block

**Namespace:** `[rig/browser]`
**Discovered:** 2026-10-05 (s153, #1091 — SP-11 C-4, WooCommerce 11.1)

> **Measured on:** the macOS rig, where `woocommerce_checkout_page_id` is `13` (`/classic-checkout/`)
> and the block checkout is a second page, `/checkout/` (id 7).

## What happens

To check that our pickup button coexists with WooCommerce's own Local pickup you enable it —
`woocommerce_pickup_location_settings` → `enabled: yes`, plus a location in
`pickup_location_pickup_locations` — and open the block checkout. There is no «Ship / Pickup» toggle
and no `pickup_location` rate. `wcSettings.localPickupEnabled` is `true`, which makes it read like a
broken render rather than a missing method.

## Root cause

`Automattic\WooCommerce\Blocks\Shipping\ShippingController::register_local_pickup()` registers the
`pickup_location` method only when `CartCheckoutUtils::is_checkout_block_default()` — the page
`wc_get_page_id( 'checkout' )` points at contains `woocommerce/checkout` (or a block theme's checkout
template does). On this rig that page is the CLASSIC one, so the method never exists, on any page.

The same fact changes one more observable: a block-checkout order on the rig lands on
`/classic-checkout/order-received/…`, because the «order received» URL is built from the store's
checkout page. Neither is a defect of the framework.

## Fix

❌ Reading the absent toggle as «our forced block broke the shipping-method block».

✅ For the duration of the probe make the block page the store's checkout page, and put it back:

```bash
wp option update woocommerce_checkout_page_id 7     # the block page
# … enable Local pickup, run the probe …
wp option update woocommerce_checkout_page_id 13    # the rig's standard
wp option delete woocommerce_pickup_location_settings pickup_location_pickup_locations
```

What was measured with it on (C-4): choosing «Pickup» sets `prefersCollection`, core unmounts the
shipping-methods and shipping-address blocks and our two forced blocks with them, the pickup field's
validation error and echo are cleared, and the order is placed with a `pickup_location` line and no
carrier point or marker.

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — which rig URL is which checkout
- [a-forced-inner-block-does-not-render-inside-a-parent-woocommerce-forced-in](a-forced-inner-block-does-not-render-inside-a-parent-woocommerce-forced-in.md) — what unmounts with a parent
- [../wiki/local-rig.md](../wiki/local-rig.md) — the rig's standard option values
