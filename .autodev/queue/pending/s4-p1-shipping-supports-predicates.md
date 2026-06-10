---
id: s4-p1-shipping-supports-predicates
title: Add supports_box_packing()/supports_shipping_classes() predicate wrappers; route raw supports() sites through them
phase: S4 Shipping pattern conformance
type: refactor
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-method.php
  - tests/unit/ShippingMethodBoxPackingTest.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "two new public predicate methods exist on Shipping_Method: supports_box_packing(): bool and supports_shipping_classes(): bool, each returning $this->supports( self::FEATURE_* )"
  - "all 4 raw $this->supports( self::FEATURE_BOX_PACKING|FEATURE_SHIPPING_CLASSES ) call sites now go through the predicate (init_form_fields x2, calculate_rate x1, is_available_for_package x1)"
  - "no FEATURE_* constant added or renamed; no installed-site contract string changed; add_support() and the woodev_shipping_method_{id}_supports_{name} hook untouched"
  - "new unit tests assert each predicate reflects add_support()/the $supports array"
---

# Task

Apply the **Capability-Gated Feature Seam** supporting convention to
`Woodev\Framework\Shipping\Shipping_Method`: wrap the two framework-owned feature
checks in named `supports_*()` predicates, the same way `Woodev_Payment_Gateway`
does (`supports_refunds()`, `supports_voids()`, `supports_tokenization()` —
`woodev/payment-gateway/class-payment-gateway.php:1530,1767,2884`).

Rationale + audit map: `docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-10.md`
(item M7). Convention source: `docs-internal/wiki/capability-gated-feature-seam.md`
("Conventions that make it read well" → "Wrap supports( self::FEATURE_X ) in a named
predicate when the capability is checked in more than one place"), ADR-006.

Both features are currently checked at 2 sites each → both qualify for a predicate.

## 1. `woodev/shipping-method/class-shipping-method.php`

Add two `public` predicate methods. Place them near the other capability machinery —
immediately **before** `add_support()` (around line 603) is a good home, or directly
after the `is_*_shipping()` predicate cluster. Match the file's existing tab indentation
and docblock style (type declarations + `@since 2.0.0` are required for new PSR-4 code).

```php
/**
 * Determines whether this method combines package contents into parcels before rating.
 *
 * Named predicate over {@see self::FEATURE_BOX_PACKING}: one point of change and a
 * self-documenting capability surface (the convention from Woodev_Payment_Gateway's
 * supports_*() wrappers).
 *
 * @since 2.0.0
 *
 * @return bool
 */
public function supports_box_packing(): bool {
    return $this->supports( self::FEATURE_BOX_PACKING );
}

/**
 * Determines whether this method is gated by a configured WooCommerce shipping class.
 *
 * Named predicate over {@see self::FEATURE_SHIPPING_CLASSES}.
 *
 * @since 2.0.0
 *
 * @return bool
 */
public function supports_shipping_classes(): bool {
    return $this->supports( self::FEATURE_SHIPPING_CLASSES );
}
```

Then route the **4 existing raw call sites** through the predicates (pure substitution,
identical runtime semantics — `WC_Shipping_Method::supports()` stays the source of truth):

| Site | Method | Current | New |
|------|--------|---------|-----|
| ~:124 | `init_form_fields()` | `if ( $this->supports( self::FEATURE_SHIPPING_CLASSES ) ) {` | `if ( $this->supports_shipping_classes() ) {` |
| ~:136 | `init_form_fields()` | `if ( $this->supports( self::FEATURE_BOX_PACKING ) ) {` | `if ( $this->supports_box_packing() ) {` |
| ~:322 | `calculate_rate()` | `$packed = $this->supports( self::FEATURE_BOX_PACKING )` | `$packed = $this->supports_box_packing()` |
| ~:376 | `is_available_for_package()` | `if ( $this->supports( self::FEATURE_SHIPPING_CLASSES ) && ! $this->has_only_selected_shipping_class( $package ) ) {` | `if ( $this->supports_shipping_classes() && ! $this->has_only_selected_shipping_class( $package ) ) {` |

Use Serena `find_symbol` to read exact current bodies before editing; line numbers are
approximate. After editing, no raw `$this->supports( self::FEATURE_BOX_PACKING )` or
`$this->supports( self::FEATURE_SHIPPING_CLASSES )` should remain in the file (the only
remaining `supports(` references are the predicate bodies themselves).

## 2. `tests/unit/ShippingMethodBoxPackingTest.php`

Add two focused tests in the `Woodev\Tests\Unit\ShippingMethodBoxPackingTest` class.
The fixture (`ShippingMethodBoxPackingTest_Method`) and the WC stub already expose the
`$supports` array + `supports()`, so the predicates are public and need **no reflection**:

```php
public function test_supports_box_packing_predicate_reflects_declared_support(): void {
    $method = $this->make_method();

    $this->assertFalse( $method->supports_box_packing() );

    $method->supports = [ \Woodev\Framework\Shipping\Shipping_Method::FEATURE_BOX_PACKING ];

    $this->assertTrue( $method->supports_box_packing() );
}

public function test_supports_shipping_classes_predicate_reflects_declared_support(): void {
    $method = $this->make_method();

    $this->assertFalse( $method->supports_shipping_classes() );

    $method->supports = [ \Woodev\Framework\Shipping\Shipping_Method::FEATURE_SHIPPING_CLASSES ];

    $this->assertTrue( $method->supports_shipping_classes() );
}
```

(`make_method()` builds via `newInstanceWithoutConstructor()`, so `$method->supports`
starts as the stub's default `[]` — assert false first, then declare and assert true.)

## What NOT to change
- Do NOT add, rename, or remove any `FEATURE_*` constant.
- Do NOT touch `add_support()`, `init_settings()`, the `$supports` ctor default, or any
  hook name (esp. `woodev_shipping_method_{id}_supports_{name}`, `woodev_shipping_*`).
- Do NOT introduce predicates for the WC-native features (`FEATURE_SHIPPING_ZONES`,
  `FEATURE_INSTANCE_SETTINGS`) — those are consumed by WC core, not woodev code; they are
  conforming as-is (audit item M5).
- Do NOT change the plugin-level `Shipping_Plugin::supports()` — that is the separate
  task s4-p2.
- No deprecation shims (clean-break: internal API, ADR-005).

## Verification
- `composer check` green (PHPCS, PHPStan 0, unit tests).
- Grep confirms zero remaining raw `$this->supports( self::FEATURE_BOX_PACKING )` /
  `$this->supports( self::FEATURE_SHIPPING_CLASSES )` outside the two predicate bodies.

## Reference
- Audit: `docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-10.md` (M7)
- Pattern: `docs-internal/wiki/capability-gated-feature-seam.md`, `docs-internal/adr/006-capability-gated-feature-seam.md`
- Exemplar: `woodev/payment-gateway/class-payment-gateway.php:1530,1767,2884`
