---
id: s4-p2-shipping-plugin-supports-docblock
title: Document Shipping_Plugin::supports()/$supports as the deliberate host-facing plugin-scoped capability surface
phase: S4 Shipping pattern conformance
type: docs
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-plugin.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "Shipping_Plugin::supports() docblock states it is a host-facing extension surface for plugin-scoped capabilities (mirrors Woodev_Payment_Gateway_Plugin's plugin-level supports()), parallel to the per-method Shipping_Method capability surface"
  - "the $args['supports'] entry in the __construct docblock notes these are plugin-scoped feature flags consumed by host plugins (no framework-side consumer yet)"
  - "no behaviour change, no new FEATURE_* constants, no method signature change"
---

# Task

Documentation-only clarification. The audit (item P6,
`docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-10.md`) found
`Shipping_Plugin::supports()` + the `$this->supports` array to be a **capability surface
with no in-framework consumer and no `FEATURE_*` constants** — unlike
`Woodev_Payment_Gateway_Plugin`, which declares plugin-scoped `FEATURE_CAPTURE_CHARGE` /
`FEATURE_MY_PAYMENT_METHODS` and consumes them.

Operator decision (2026-06-10): **document it as a deliberate host-facing extension
surface** — do NOT invent speculative constants, do NOT remove it. This records intent so
a future reader does not mistake it for dead code (and so a future plugin-scoped capability
has a documented home).

## `woodev/shipping-method/class-shipping-plugin.php`

Read both symbols with Serena `find_symbol` first, then edit only the docblocks.

### 1. `supports()` (around :589-599)

Extend its docblock to state that this is the **plugin-scoped** capability surface
(parallel to the per-method `Shipping_Method` capability vocabulary): host plugins declare
plugin-wide features via the `supports` constructor arg and query them here; the framework
ships no plugin-scoped `FEATURE_*` constants of its own (mirrors the per-gateway vs
per-plugin scope split documented for payment-gateway). Keep the signature and body
exactly as-is. Example shape (adapt wording to the file's style):

```php
/**
 * Checks whether the plugin declares support for a plugin-scoped feature.
 *
 * Host-facing extension surface: a host plugin passes plugin-wide capability flags via
 * the `supports` constructor arg and queries them here. This is the plugin-level
 * counterpart to the per-method capability surface on {@see Shipping_Method::supports()}
 * (cf. the per-gateway vs per-plugin scope split on Woodev_Payment_Gateway_Plugin). The
 * framework ships no plugin-scoped FEATURE_* constants of its own; the vocabulary is
 * defined by the host plugin.
 *
 * @since 1.5.0
 *
 * @param string $feature feature flag declared via the `supports` constructor arg
 * @return bool
 */
```

### 2. `__construct()` `$args['supports']` (around :52)

In the constructor's `@param array $args` block, refine the `@type string[] $supports`
line to note these are **plugin-scoped** feature flags consumed by the host plugin (there
is no framework-side consumer yet) — so it reads as deliberate, not vestigial. One line,
no behaviour change.

## What NOT to change
- No new `FEATURE_*` constants (operator explicitly declined the speculative-constants option).
- No signature/body change to `supports()` or the constructor.
- No change to any other method, hook, option key, or installed-site contract.

## Verification
- `composer check` green (this is docblock-only; PHPCS must still pass — watch line length ≤ 120 and tab indentation).

## Reference
- Audit: `docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-10.md` (P6)
- Pattern conventions ("Declare the capability at the right scope"): `docs-internal/wiki/capability-gated-feature-seam.md`
