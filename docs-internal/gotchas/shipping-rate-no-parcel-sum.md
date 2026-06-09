# Do NOT sum per-parcel prices in the framework rate seam

**Namespace:** `[shipping/rate-calc]`
**Discovered:** session 3 (2026-06-09), during the packing→rate-calc design.

## Rule

`Shipping_Method::calculate_rate()` is a **final** template that packs the cart (when
`FEATURE_BOX_PACKING` is on) and hands the parcels to the abstract seam
`rate_package( array $package, ?\Woodev_Packer_Result $packed )`. The framework owns the
**packing wiring only**. It must **not** provide a default that sums per-parcel carrier
prices.

## Why (the footgun)

Real Russian carriers (СДЭК, Яндекс Доставка, Почта России) quote a whole **multi-place**
shipment in **one** tariff request and return **one** price: the base fee is charged once,
there are volumetric/quantity discounts, and inter-place rules apply. A built-in
"price = Σ rate(parcel)" aggregator would therefore:

- produce **wrong** (usually inflated) prices that don't match the carrier's own tariff, and
- fire **N** API calls instead of one.

A wrong-but-easy default in billing-sensitive code is worse than no default — a plugin can
ship it without noticing the mispricing.

## ❌ Wrong (do not add this to the framework)

```php
// In Shipping_Method or a base "aggregator": DO NOT DO THIS.
protected function rate_packed_package( \Woodev_Packer_Result $packed, array $package ): ?Shipping_Rate {
    $total = 0.0;
    foreach ( $packed->get_packages() as $parcel ) {
        $total += $this->rate_parcel( $parcel ); // N requests, summed — wrong tariff
    }
    return new Shipping_Rate( /* ... */ (string) $total );
}
```

## ✅ Correct

The carrier subclass receives the whole packed result and decides how to quote — typically
one multi-place request:

```php
protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
    $places = $packed ? $packed->to_array()['packages'] : []; // l/w/h/weight/item_count per parcel
    // ...build ONE carrier tariff request from $places, return its single price...
}
```

`$packed` is `null` when the method does not support box-packing, the cart has nothing
physical to pack (virtual-only), or the WC-aware packer is unavailable — rate without
dimensional data in that case.

## Related
- `docs-internal/platform-v2-s3-shipping-rate-packing-spec.md` — the full design (Variant B)
- `docs-internal/platform-v2-s2-boxpacker-spec.md` — the packer/dispatcher producing the parcels
- [[contract-string-not-derivable]] — another "the plugin must supply carrier specifics" rule
