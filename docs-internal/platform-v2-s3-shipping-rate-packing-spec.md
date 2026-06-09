# S3 — Packing seam → real rate-calc (single-seam template)

> Spec written 2026-06-09 (session 3). Design approved by operator (Variant B,
> single-seam template). Branch: `feat/shipping-rate-packing-seam` off `main`.
> Executed via the autodev pattern: atomic task specs in `.autodev/queue/pending/`,
> worker writes files, adversarial critic reviews contract-adjacent diff, machine
> gate = `composer check` green.

## Problem

`Woodev\Framework\Shipping\Shipping_Method` already has a box-packing seam landed in
session 2 (`FEATURE_BOX_PACKING`, the `packing_algorithm` instance setting,
`pack_package()` → `?\Woodev_Packer_Result`, `get_packing_algorithm()`), but **nothing
in the rate-calculation flow ever calls `pack_package()`**. The rate flow is:

```
calculate_shipping( array $package ): void   // final
  └─ calculate_rate( array $package ): ?Shipping_Rate   // abstract — subclass implements
```

A shipping method cannot turn cart contents into parcels and rate by packed boxes
without manually remembering to call `pack_package()` itself. The task is to weave
packing into the rate flow as a reusable template-method seam that a migrating plugin
consumes.

## Design — Variant B (single-seam template)

The framework owns the *wiring* (pack when the feature is on; hand the parcels to the
carrier seam) but stays **unopinionated about aggregation**. Real Russian carriers
(СДЭК, Яндекс, Почта) quote a whole multi-place shipment in **one** request and return
**one** price — so a built-in "sum the per-parcel prices" aggregator would produce
subtly-wrong prices and N API calls. Aggregation is therefore the subclass's job.

### Contract change (internal API — free to break on v2)

`calculate_rate()` stops being abstract and becomes a concrete template that packs (when
the method opts into `FEATURE_BOX_PACKING`) and dispatches to a single new abstract carrier
seam, `rate_package()`, which always receives the packed result as a **nullable** argument:

```php
/**
 * Template: packs the cart into parcels when this method opts into box-packing,
 * then hands the (nullable) packed result to the carrier-specific rate_package().
 *
 * Overriding this method bypasses packing — implement rate_package() instead.
 *
 * @since 2.0.0
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
 * @param array                       $package WooCommerce shipping package.
 * @param \Woodev_Packer_Result|null  $packed  Parcels produced by the configured
 *        packing algorithm, or null when this method does not support box-packing
 *        OR the cart has nothing physical to pack (e.g. a virtual-only cart). When
 *        non-null, the carrier decides how to quote — typically one multi-place
 *        request, not a sum of per-parcel prices.
 * @return Shipping_Rate|null the rate, or null to add no rate.
 * @since 2.0.0
 */
abstract protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate;
```

### Why this shape

- **Truly woven** — `pack_package()` is called automatically by the template based on
  the `FEATURE_BOX_PACKING` opt-in. A migrating plugin cannot forget to pack.
- **One seam, no footgun** — the parcels arrive in the carrier method's signature, so it
  is impossible to "turn the feature on and have nothing happen". No second abstract
  method that a non-packing method would be forced to stub.
- **Unopinionated** — the framework imposes no cost-aggregation model. The carrier owns
  the price (single multi-place request, or whatever its tariff needs).
- **Virtual-only handled** — `pack_package()` already returns `null` when there is nothing
  physical to pack; the carrier seam receives `null` and rates normally.
- **`calculate_shipping()` unchanged** — it still calls `calculate_rate()`; the only change
  is that `calculate_rate()` is now concrete. The layering mirrors the existing
  final→template→abstract structure.

### Non-goals (YAGNI)

- No built-in per-parcel summing aggregator (billing footgun; add later as an opt-in helper
  only if a real consumer needs it).
- No change to `pack_package()`, `get_packing_algorithm()`, the `packing_algorithm` setting,
  `Shipping_Rate`, or any hook name.

## Installed-site contracts — untouched

Internal method signatures only. **Preserved byte-for-byte:** the `packing_algorithm`
option key, all `woodev_shipping_method_*` / `woodev_shipping_*` hook names, method ids,
REST namespaces, order-meta prefixes. The change is confined to `Shipping_Method` internals
and the in-repo test fixtures that subclass it.

## Blast radius

Every concrete subclass that implemented the old abstract `calculate_rate( array ): ?Shipping_Rate`
must move to `rate_package( array, ?\Woodev_Packer_Result ): ?Shipping_Rate`. In-repo that is
exactly 5 files (all return a trivial fixture rate):

1. `tests/_fixtures/woodev-test-shipping-method/class-woodev-test-shipping-method.php`
2. `tests/_fixtures/woodev-realistic-shipping-plugin/includes/abstract-class-realistic-shipping-method.php`
3. `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php`
4. `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php`
5. `tests/unit/ShippingMethodBoxPackingTest.php` (in-test fixture `ShippingMethodBoxPackingTest_Method`)

(Production migrating plugins live in their own repos and adopt the new seam at rewrite time.)

## Tasks (atomic, each leaves `composer check` green)

### P1 — core seam + fixture migration (`.autodev/queue/pending/s3-p1-rate-package-seam.md`)
Make `calculate_rate()` concrete (template above); add abstract `rate_package(array, ?\Woodev_Packer_Result)`;
migrate the 5 subclasses to the new signature (ignoring `$packed`, preserving their current
behavior). `composer check` green. **Contract-adjacent → adversarial critic required.**

### P2 — validation gate (`.autodev/queue/pending/s3-p2-rate-packing-validation.md`)
Additive behavioral test proving the template wiring end-to-end:
- a method that **supports** `FEATURE_BOX_PACKING` with non-virtual cart contents →
  `rate_package()` receives a non-null `Woodev_Packer_Result` whose parcels reflect the cart;
- the same method with a **virtual-only** cart → `rate_package()` receives `null`;
- a method that does **not** support `FEATURE_BOX_PACKING` → `rate_package()` receives `null`
  even with physical contents (packing is opt-in).

Lives alongside the existing box-packing scaffolding (`ShippingMethodBoxPackingTest.php` has
the `WC_Shipping_Method` / `WC_Product` stubs + dispatcher requires). The `WC_Shipping_Method`
stub must expose a `supports()` method + `$supports` array so the template's
`$this->supports( self::FEATURE_BOX_PACKING )` check works under
`newInstanceWithoutConstructor()`.

## After both land
Holistic adversarial review pass over the whole feature (not just the last diff), then PR
off this branch; merge only after green GitHub Actions + operator decision.

## Related
- `docs-internal/platform-v2-s2-boxpacker-spec.md` — the box-packer + dispatcher this builds on
- `docs-internal/platform-v2-s1-shipping-spec.md` — the shipping module
- Gotcha `[[reflection-setaccessible-version-guard]]` — reflection tests on PHP 8.5
- Gotcha `[[brain-monkey-function-pollution]]` — `@runInSeparateProcess` for stub isolation
