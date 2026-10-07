# Gotcha: [shipping/rate-cache] — Percentage box costs need per-line values
> Tags: shipping/rate-cache, box-packer/cost | Session: s158

## What happens

A cached quote can retain an old packaging surcharge when a cart's total stays the same but the
values of items allocated to different boxes change. This matters when the boxes have different
percentage costs, or when some items travel unboxed.

## Root cause

Percentage cost belongs to the contents of each packed parcel. The package-wide `contents_cost`
therefore does not identify all inputs to that cost. Physical dimensions and quantities alone
also cannot distinguish two allocations with different merchandise values.

## Fix

❌ Key only the package total:

```php
$context['cost'] = $package['contents_cost'];
```

✅ Include the unit value of each source line (after discounts, before taxes):

```php
$context['packing']['values'] = Packaging::get_value_context( $package['contents'] );
```

## Related

- [../wiki/architecture.md](../wiki/architecture.md) — packing and per-package item allocation.
- [shipping-rate-no-parcel-sum.md](shipping-rate-no-parcel-sum.md) — a box surcharge does not replace the carrier's shipment quote.
