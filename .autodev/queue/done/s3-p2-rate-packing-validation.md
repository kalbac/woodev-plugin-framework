---
id: s3-p2-rate-packing-validation
title: Validation gate — template wiring delivers parcels to rate_package()
phase: S3 Shipping rate-calc
type: test
touches_contract_zone: false
writes_guard: false
file_set:
  - tests/unit/ShippingMethodBoxPackingTest.php
depends_on:
  - s3-p1-rate-package-seam
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green
  - "test proves: supports(FEATURE_BOX_PACKING) + non-virtual cart -> rate_package() receives a non-null Woodev_Packer_Result whose parcels reflect the cart"
  - "test proves: supports(FEATURE_BOX_PACKING) + virtual-only cart -> rate_package() receives null"
  - "test proves: NOT supports(FEATURE_BOX_PACKING) + physical cart -> rate_package() receives null (packing is opt-in)"
---

# Task

Add additive behavioral tests proving the `calculate_rate()` template wiring lands the
packed parcels in `rate_package()`. Extend `tests/unit/ShippingMethodBoxPackingTest.php`
(it already has the `WC_Shipping_Method` stub, the `WC_Product` stub, the box-packer
`require_once` graph, and the reflection `invoke()` helper). Purely additive — do not
weaken existing assertions.

Depends on `s3-p1-rate-package-seam` (the new `calculate_rate` template + `rate_package`
seam must exist first).

## 1. Make the `WC_Shipping_Method` stub support `supports()`

The template calls `$this->supports( self::FEATURE_BOX_PACKING )`. The current stub
(`ShippingMethodBoxPackingTest_WC_Shipping_Method_Stub`, an empty class) has no
`supports()` method or `$supports` property, and methods under test are built with
`newInstanceWithoutConstructor()`. Give the stub a real `supports()`:

```php
class ShippingMethodBoxPackingTest_WC_Shipping_Method_Stub {

    /** @var string[] declared supported features. */
    public array $supports = [];

    public function supports( $feature ): bool {
        return in_array( $feature, $this->supports, true );
    }
}
```

(Keep the existing `class_alias( ..., 'WC_Shipping_Method' )`. Only do this inside the
existing `if ( ! class_exists( 'WC_Shipping_Method', false ) )` guard so a real WC class
in another process is not shadowed.)

## 2. Record the packed arg in the fixture method

Update `ShippingMethodBoxPackingTest_Method` (already migrated to `rate_package()` in P1)
so a test can observe what the template passed:

```php
/** @var \Woodev_Packer_Result|null|false  false = rate_package() not called yet. */
public $received_packed = false;

protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
    $this->received_packed = $packed;
    return null;
}
```

Initialise `$received_packed` to `false` (a sentinel distinct from a legitimate `null`)
so a test can assert `rate_package()` actually ran.

## 3. Tests

Use `make_method()` + the reflection `invoke()` helper already in the file. Set
`$method->supports = [ ... ]` to declare features (the stub property added in step 1).
Reuse the `@runInSeparateProcess` / `@preserveGlobalState disabled` pattern from
`test_pack_package_packs_non_virtual_contents()` for any test that builds the
`WC_Product` stub (class-table isolation — gotcha brain-monkey-function-pollution).

Add three tests:

1. **`test_calculate_rate_passes_parcels_to_rate_package_when_box_packing_supported`**
   (separate process): non-virtual `WC_Product` (e.g. 10×5×3, weight 1.5, qty 2),
   `$method->supports = [ Shipping_Method::FEATURE_BOX_PACKING ]`, stored option
   `packing_algorithm => ALGORITHM_VIRTUAL`. Invoke `calculate_rate` via reflection with
   the package. Assert `$method->received_packed instanceof \Woodev_Packer_Result` and its
   `to_array()['total_weight']` equals `3.0` (2 × 1.5) and `packages` is non-empty.

2. **`test_calculate_rate_passes_null_for_virtual_only_cart`** (separate process):
   a virtual `WC_Product` (`$product->virtual = true`), box-packing supported. Invoke
   `calculate_rate`. Assert `$method->received_packed === null` (packing produced nothing)
   AND that `rate_package()` ran (i.e. it is null, not the `false` sentinel — assert
   `false !== $method->received_packed` then `assertNull`).

3. **`test_calculate_rate_passes_null_when_box_packing_not_supported`**: physical
   `WC_Product`, but `$method->supports = []` (feature OFF). Invoke `calculate_rate`.
   Assert `$method->received_packed === null` and `false !== $method->received_packed`
   (rate_package ran but the template never packed because the feature is off). This one
   needs the `WC_Product` stub too → separate process.

Reference the `FEATURE_BOX_PACKING` constant as
`\Woodev\Framework\Shipping\Shipping_Method::FEATURE_BOX_PACKING`.

## What NOT to change
- Do not modify the production `Shipping_Method` class (that is P1).
- Do not weaken or remove the existing 6 tests in the file.

## Verification
`composer check` green; the 3 new tests pass; total unit test count increases by 3.

## Spec reference
`docs-internal/platform-v2-s3-shipping-rate-packing-spec.md` §P2
