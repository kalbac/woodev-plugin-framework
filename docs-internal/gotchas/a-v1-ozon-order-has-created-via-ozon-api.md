# A v1 Ozon order carries `created_via = ozon_api` and the framework matches marketplace orders by exact `created_via`

**Namespace:** `[woocommerce/orders]` · **Discovered:** s240 (21.11.2026, Ozon Seller PR #113)

## What happens

After the v2 update, orders created by the v1 plugin lost the framework's details box, the cancel action and the label button: they were not recognised as marketplace orders.

## Root cause

The framework identifies an adapter's orders by the exact `created_via` value the adapter declares. v1 wrote `ozon_api`; the v2 adapter declares a different value.

## Fix

- ❌ Assume old orders are recognised because the meta key is the same.
- ✅ A migration step normalises the v1 `created_via` to the adapter's value (Ozon: `Orders_Step`). Any plugin porting a marketplace from v1 checks what v1 wrote.

## Related

- [s240 session](../sessions/s240.md)
