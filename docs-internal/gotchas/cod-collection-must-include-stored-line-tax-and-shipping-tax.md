# Gotcha: [shipping/cod] — COD collection must include the stored line tax and shipping tax, whatever the price-entry mode

> Tags: shipping, cod, tax, woocommerce | Session: s184

## What happens

The cash-on-delivery amount a carrier plugin hands the carrier must include the line tax and the shipping tax WooCommerce stored
on the order when taxes are on; an amount built without them differs from what the buyer was charged (CDEK edostavka#111,
Yandex yandex#110 — both found by the s184 audit).

## Root cause

- Whether prices are entered with or without tax (`woocommerce_prices_include_tax`) changes where the tax sits; code that handles
  only one mode is wrong for the other.
- Deciding «is this line taxable?» from `$product->get_tax_status()` reads the LIVE product, which may have changed since the
  order was placed. The order item already stores the tax it was charged.

## Fix

❌ `$line->get_total()` alone, or a taxability test on the current product.

✅ Collect from the order's STORED numbers: per line `get_total() + get_total_tax()`, and shipping `get_shipping_total() +
get_shipping_total_tax()` — the same arithmetic in both price-entry modes, no look at the live product. Test with taxes on, in
both modes, and with a product whose tax status changed after the order.

## Related

- [a-cast-is-not-a-degradation](a-cast-is-not-a-degradation.md) — money arithmetic must degrade to the stored value
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
