# `#order_data .order_data_column { width: 31% }` beats a custom order box's column CSS — override through the same id

**Namespace:** `[admin-ui/orders]` · **Discovered:** s239 (21.11.2026, #1900)

## What happens

Columns of the marketplace order details box stay at a third of the width whatever our class says, so wide values (links, long ids) are squeezed.

## Root cause

WooCommerce sets `#order_data .order_data_column { width: 31%; }`; an id selector outranks any class-only rule of ours.

## Fix

- ❌ `.woodev-order-box .order_data_column { width: 100%; }`
- ✅ `#order_data .woodev-order-box .order_data_column { width: … }` — start the selector with the same id.

## Related

- [a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement](a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement.md) — confirm in the browser
- [s239 session](../sessions/s239.md)
