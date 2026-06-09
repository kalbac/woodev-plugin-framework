---
id: s3-p1-rate-package-seam
title: Weave pack_package into rate-flow via single-seam template (calculate_rate → rate_package)
phase: S3 Shipping rate-calc
type: feat
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-method.php
  - tests/_fixtures/woodev-test-shipping-method/class-woodev-test-shipping-method.php
  - tests/_fixtures/woodev-realistic-shipping-plugin/includes/abstract-class-realistic-shipping-method.php
  - tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php
  - tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php
  - tests/unit/ShippingMethodBoxPackingTest.php
depends_on: []
contract_zones_touched:
  - "Shipping_Method internal seam signatures (calculate_rate / new rate_package) — internal API, free to break on v2; adversarial critic required because migrating plugins consume this seam"
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "calculate_rate() is concrete (no longer abstract); calculate_shipping() still calls it unchanged"
  - "new abstract rate_package( array $package, ?\\Woodev_Packer_Result $packed ): ?Shipping_Rate exists"
  - "calculate_rate packs only when supports(FEATURE_BOX_PACKING); passes pack_package() result (or null) to rate_package()"
  - "all 5 in-repo subclasses implement rate_package() with the new signature and preserve prior behavior"
  - "no installed-site contract changed: packing_algorithm option key, woodev_shipping_* hook names, method ids untouched"
---

# Task

Weave the existing `pack_package()` seam into the rate-calculation flow of
`Woodev\Framework\Shipping\Shipping_Method` using the **single-seam template** design
(spec Variant B). The framework owns the packing *wiring*; the carrier subclass owns
price aggregation (no built-in summing — see spec rationale).

Full design + rationale: `docs-internal/platform-v2-s3-shipping-rate-packing-spec.md`.

## 1. `woodev/shipping-method/class-shipping-method.php`

**Change `calculate_rate()` from abstract to a concrete template** that packs when the
method opts into box-packing and dispatches to a new abstract carrier seam. Place the
concrete `calculate_rate()` where the old abstract declaration was (around line 304–314),
and add the new abstract `rate_package()` immediately after it.

```php
/**
 * Calculates the shipping rate for the package.
 *
 * Template method: when this method opts into {@see self::FEATURE_BOX_PACKING},
 * the cart contents are packed into parcels via {@see self::pack_package()} and
 * the (nullable) result is handed to {@see self::rate_package()}. Methods that do
 * not support box-packing receive a null packed result. Overriding this method
 * bypasses packing — implement {@see self::rate_package()} instead.
 *
 * @since 1.4.0
 *
 * @param array $package Package data.
 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
 */
protected function calculate_rate( array $package ): ?Shipping_Rate {

    $packed = $this->supports( self::FEATURE_BOX_PACKING )
        ? $this->pack_package( $package )
        : null;

    return $this->rate_package( $package, $packed );
}

/**
 * Produces the shipping rate for a package.
 *
 * Implemented by concrete shipping methods. When this method supports
 * {@see self::FEATURE_BOX_PACKING} and the cart has physical contents, $packed
 * carries the parcels produced by the configured packing algorithm; the carrier
 * decides how to quote them (typically one multi-place request, not a sum of
 * per-parcel prices). $packed is null when this method does not support
 * box-packing OR there is nothing physical to pack (e.g. a virtual-only cart).
 *
 * @since 2.0.0
 *
 * @param array                      $package Package data.
 * @param \Woodev_Packer_Result|null $packed  Packed parcels, or null (see above).
 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
 */
abstract protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate;
```

Do NOT change `calculate_shipping()` — it still calls `calculate_rate( $package )`. Do NOT
change `pack_package()`, `get_packing_algorithm()`, `init_form_fields()`, or any hook.

## 2. Migrate the 5 subclasses

Each currently implements `protected function calculate_rate( array $package ): ?Shipping_Rate`.
Rename to the new seam and add the nullable packed parameter, preserving the existing body
(these are trivial fixture rates; they ignore `$packed`). Add a `?\Woodev_Packer_Result $packed`
parameter and keep the return value identical.

For each file, the new signature is:

```php
protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
    // ... existing body unchanged ...
}
```

Files:
- `tests/_fixtures/woodev-test-shipping-method/class-woodev-test-shipping-method.php` (line ~66) — keep `@inheritDoc` or replace with a one-line docblock; preserve the returned `Shipping_Rate`.
- `tests/_fixtures/woodev-realistic-shipping-plugin/includes/abstract-class-realistic-shipping-method.php` (line ~43).
- `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php` (line ~79).
- `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php` (line ~125).
- `tests/unit/ShippingMethodBoxPackingTest.php` — the in-test fixture `ShippingMethodBoxPackingTest_Method::calculate_rate()` (line ~114) returns `null`; rename to `rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate` and keep returning `null`. (The existing P1/P2 tests in this file do not call `calculate_rate`, so behavior is unchanged.)

If any of these fixtures reference `\Woodev_Packer_Result`, it is the global-namespace
class (the shipping namespace already uses `\Woodev_Packer_Result` in `pack_package()`);
in a namespaced file use the leading-backslash FQCN. The fixture files at the repo root
(woodev-test-shipping-method etc.) are in the global namespace, so `?\Woodev_Packer_Result`
is also correct there. Do not add a `use` import for it unless the file already imports
other global packer classes.

## What NOT to change
- Do not add a per-parcel summing aggregator (explicit non-goal — billing footgun).
- Do not touch `Shipping_Rate`, the dispatcher, or `pack_package()`.
- Do not change any installed-site contract string.
- Do not add deprecation shims for the renamed internal method (clean-break policy: v2 internal APIs break freely).

## Verification
- `composer check` green.
- Confirm no other in-repo file declares `function calculate_rate` (grep) before finishing.

## Spec reference
`docs-internal/platform-v2-s3-shipping-rate-packing-spec.md` §P1
