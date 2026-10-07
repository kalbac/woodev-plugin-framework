# Gotcha: [shipping/packaging] — Carrier presets must use fixed units
> Tags: shipping/packaging, box-packer/units | Session: s158

## What happens

A physical carrier carton changes size and weight capacity when the same plugin is installed on
a store using mm/g instead of cm/kg. Packing may split the cart or choose a carton that cannot fit it.

## Root cause

`wc_get_dimension( $value, 'cm' )` and `wc_get_weight( $value, 'kg' )` assume the input is in the
store's configured units. That is correct for saved store box rows and product measurements,
but carrier declarations describe a fixed physical carton and cannot depend on the store locale.

## Fix

❌ Convert every declaration as though it came from the merchant's store settings:

```php
$length = wc_get_dimension( $box['length'], 'cm' );
```

✅ Declare carrier presets in cm/kg and convert only store rows:

```php
$is_carrier = 'carrier' === ( $box['origin'] ?? '' );
$length = $is_carrier ? $box['length'] : wc_get_dimension( $box['length'], 'cm' );
```

Regression coverage must use real mm/g conversion factors; identity conversion mocks hide this bug.

## Related

- [../wiki/architecture.md](../wiki/architecture.md) — the packing units and carrier declaration seam.
- [percentage-box-cost-cache-needs-line-values.md](percentage-box-cost-cache-needs-line-values.md) — parcel identity also affects packaging charges.
