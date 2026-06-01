# Gotcha: [shipping/shipping-api-broken-contract] — Shipping_API interface references types that don't exist in the framework
> Tags: shipping, interface, phpstan, static-analysis, contract
> Discovered: 2026-06-01 (independent audit)

## What happens
`woodev/shipping-method/api/interface-shipping-api.php` (namespace `Woodev\Framework\Shipping`) declares the `Shipping_API` contract with return types and `@throws` for **6 classes/interfaces that do not exist in the framework**:
- `Woodev_Shipping_API_Rate_Response`
- `Woodev_Shipping_API_Order_Response`
- `Woodev_Shipping_API_Tracking_Response`
- `Woodev_Shipping_API_Pickup_Points_Response`
- `Woodev_Exportable_Order`
- `Woodev_Shipping_Exception` (must extend `Throwable` per `@throws`)

Running PHPStan with the blanket ignore for this interface removed reveals **20 errors** in 5 methods (`calculate_rates`, `get_pickup_points`, `create_order`, `get_order`, `cancel_order`, `get_tracking`). The ignore in `phpstan.neon:130-131` masks the entire interface as "cross-cutting contract gap".

Any plugin attempting to `implements \Woodev\Framework\Shipping\Shipping_API` will be type-unsafe: PHPStan reports errors on the implementation, the framework ships a contract that **cannot be satisfied** with the types it actually provides.

## Root cause
The interface was added in v2 platform split as the namespaced counterpart of legacy `Woodev_Shipping_API` (see `woodev/shipping-method/api/interface-shipping-api.php` header — `@since 1.5.0` per a recent commit). The namespacing was done without porting the concrete response classes, exceptions, and `Woodev_Exportable_Order` interface from any of the production plugins that previously used the legacy global versions. The blanket PHPStan ignore was added to silence the resulting errors, locking the broken state into CI.

## Fix
**Two options — both required to remove the ignore:**

### Option A — port the missing types into the framework (preferred)
Copy the `Woodev_Shipping_API_*` response classes, `Woodev_Shipping_Exception`, and `Woodev_Exportable_Order` from one of the read-only `plugins-reference/woocommerce-edostavka` or `woocommerce-yandex-delivery` copies. Generalize their constructors, docblocks, and namespaces to fit the framework. This is the cleanest path because the types ARE the contract — porting them turns the interface into a usable plugin-inheritable shape.

### Option B — narrow the interface to types that exist
Reduce the method signatures to return `array` or stdClass until real response classes are added. The `@throws` clauses must reference an existing Throwable (likely a new framework-level `\Woodev\Framework\Shipping\Shipping_Exception`).

### ❌ Wrong — leave the ignore in place
```neon
- message: '#(Woodev_Shipping|Woodev_Exportable|not subtype of Throwable)#'
  path: woodev/shipping-method/api/interface-shipping-api.php
```
This is the current state. It hides 20 type errors in the framework's own shipping contract.

### ✅ Correct — after fix, the line is removed entirely
```neon
# (no entry — the interface type-checks cleanly)
```

## Related
- [php/gateway-type-methods-required](gotchas/gateway-type-methods-required.md) — same root cause (blanket-ignore masking), broader scope
- [shipping/multiversion-early-class-guards](../gotchas/multiversion-early-class-guards.md) — class-availability guards (separate concern)
- [../platform-v2-implementation-spec.md](../platform-v2-implementation-spec.md) — section 9.3 names Shipping as WC-only specialized module
- [../FUTURE-BACKLOG.md](../FUTURE-BACKLOG.md) — "Shipping Module Boilerplate" (deferred post-v2.0, but the contract is a v2.0 concern)
