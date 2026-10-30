# Gotcha: [woocommerce/shipping-cache] — the package hash ignores the phone: a phone-dependent rate is not recalculated on a phone-only edit

> Tags: shipping, package-hash, phone, block-checkout, classic-checkout | Session: s204 (Ozon W5a; rig WooCommerce 11.2.1, 12 probe runs)

## What happens

Ozon refuses certain phone numbers (`check-client`), so its rate depends on the phone. The buyer corrects only the phone —
and the shipping rate stays what it was: **0 rate recalculations in 3 of 3 phone edits** on both checkouts. Block checkout
does send the new phone (`POST /wc/store/v1/batch` → `/cart/update-customer`, in billing and shipping address), but
WooCommerce answers from the session-cached rates. Classic checkout sends nothing at all on a phone edit.

## Root cause

WooCommerce hashes items and destination into the package; the phone is not part of it, so an unchanged hash serves the
cached rates. Classic `checkout.js` only fires `update_order_review` for fields carrying `update_totals_on_change`, and the
phone fields do not.

## Fix

❌ Wrong: add a client script or an `extensionCartUpdate` call to «push» the phone — the block checkout already pushes it.

✅ Correct — two small parts, both measured to re-run rates exactly once per edit (also on clear-to-empty and set-again):

1. `woocommerce_cart_shipping_packages` — write a **salted hash of the recipient phone's digits** into every package (never
   the raw phone: packages are stored in the session and in the rate cache key).
2. Classic only — add the class `update_totals_on_change` to `billing_phone` / `shipping_phone`, and remember the posted phone
   from `woocommerce_checkout_update_order_review`.

Mind which field is the recipient's: with `ship_to_destination = billing_only` it is the billing phone.

## Related

- [payment-method-change-recalculates-nothing-in-either-checkout](payment-method-change-recalculates-nothing-in-either-checkout.md) — the same hash blind spot for the payment method
- [woocommerce-caches-rates-per-package-hash-so-a-rate-gate-must-change-the-package](woocommerce-caches-rates-per-package-hash-so-a-rate-gate-must-change-the-package.md) — put the input INTO the package
- Plugin-author skill: `.ai/skills/woodev-shipping-plugin/references/traps.md`; measurements: `docs/research/2026-10-30-w5a-phone-transport.md` in `woodev-ozon-delivery`
