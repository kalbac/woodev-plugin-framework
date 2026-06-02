# Gotchas — Woodev Plugin Framework
> **16 atomic gotchas in 9 namespaces** — update count when adding/removing.
> Last updated: 2026-06-02 (polish session: added class_alias/PHPStan gotcha)

## Index

<!-- Format: - [namespace/tag] summary → [gotchas/slug.md](gotchas/slug.md) (s{N}) -->

### [naming/*] — Identifier conventions
- [naming/woodev-spelling] woodev (single 'd'), NEVER wooddev → [gotchas/woodev-spelling.md](gotchas/woodev-spelling.md) (s2)

### [php/*] — PHP / WordPress patterns
- [php/dependency-function-check-bug] get_missing_php_functions() uses extension_loaded instead of function_exists → [gotchas/dependency-function-check-bug.md](gotchas/dependency-function-check-bug.md) (s2)
- [php/namespace-migration-legacy-psr4] Legacy Woodev_* vs PSR-4 Woodev\Framework\* conventions → [gotchas/namespace-migration-legacy-psr4.md](gotchas/namespace-migration-legacy-psr4.md) (s2)
- [php/gateway-type-methods-required] Never blanket-ignore `Call to an undefined method` on a class hierarchy — same class of bug as 2026-05-31; audit 2026-06-01 found 3 more surviving instances → [gotchas/gateway-type-methods-required.md](gotchas/gateway-type-methods-required.md) (s3; recurred 2026-05-31; re-audited 2026-06-01)
- [php/blocks-handler-typed-property-trap] Non-nullable typed return on `get_blocks_handler()` can TypeError for pure-WordPress subclasses (property only initialized in Woocommerce_Plugin) → [gotchas/blocks-handler-typed-property-trap.md](gotchas/blocks-handler-typed-property-trap.md) (2026-06-01)
- [php/php84-implicit-nullable-payment-handlers] Legacy payment handler files use implicit-nullable `$arg = null` — deprecated PHP 8.4+, hidden by `error_reporting` mask in RealisticPaymentFixtureTest → [gotchas/php84-implicit-nullable-payment-handlers.md](gotchas/php84-implicit-nullable-payment-handlers.md) (2026-06-01)

### [deprecation/*] — Deprecation cycle
- [deprecation/deprecated-which-function] wc_deprecated_function vs _deprecated_function — which to use when → [gotchas/deprecated-which-function.md](gotchas/deprecated-which-function.md) (s2)
- [deprecation/hook-deprecator-usage] Use Woodev_Hook_Deprecator, not _deprecated_hook() directly → [gotchas/hook-deprecator-usage.md](gotchas/hook-deprecator-usage.md) (s2)

### [bootstrap/*] — Multi-version loading
- [bootstrap/singleton-instantiation] Bootstrap is singleton, constructor is private → [gotchas/singleton-instantiation.md](gotchas/singleton-instantiation.md) (s2)
- [bootstrap/plugin-registration-timing] register_plugin() must run before plugins_loaded → [gotchas/plugin-registration-timing.md](gotchas/plugin-registration-timing.md) (s2)
- [bootstrap/payment-gateway-conditional-load] Payment gateway base class loaded only when is_payment_gateway arg is set → [gotchas/payment-gateway-conditional-load.md](gotchas/payment-gateway-conditional-load.md) (s2)
- [bootstrap/multiversion-early-class-guards] Early-loaded support classes must be guarded and loaded from the selected framework copy → [gotchas/multiversion-early-class-guards.md](gotchas/multiversion-early-class-guards.md) (s4)
- [bootstrap/resolver-bootstrap-coupling] `Framework_Resolver` references `Woodev_Plugin_Bootstrap::instance()` in 3 places for notice wiring — undermines "minimal resolver" boundary; tests don't catch because happy-path data → see [../../docs-internal/audit-2026-06-01.md#m1](../../docs-internal/audit-2026-06-01.md) (2026-06-01)

### [php/*] — PHP class loading patterns
- [php/class-alias-phpstan-resolution] `class_alias()` in a conditionally-loaded file is invisible to PHPStan; use FQCN in internal code OR declare a real subclass → [gotchas/class-alias-phpstan-resolution.md](gotchas/class-alias-phpstan-resolution.md) (2026-06-02)

### [compat/*] — Backward compatibility, HPOS
- [compat/hpos-order-meta-safety] Never use get_post_meta() on orders — use Woodev_Order_Compatibility → [gotchas/hpos-order-meta-safety.md](gotchas/hpos-order-meta-safety.md) (s2)

### [lifecycle/*] — Install/upgrade routines
- [lifecycle/install-upgrade-detection] Lifecycle detects install vs upgrade by version comparison → [gotchas/lifecycle-install-upgrade-detection.md](gotchas/lifecycle-install-upgrade-detection.md) (s2)

### [woocommerce/*] — WooCommerce-specific
- [woocommerce/shipping-api-broken-contract] `Woodev\Framework\Shipping\Shipping_API` interface references 6 types (Rate_Response, Order_Response, Tracking_Response, Pickup_Points_Response, Exportable_Order, Shipping_Exception) that don't exist in the framework — masked by blanket PHPStan ignore → [gotchas/shipping-api-broken-contract.md](gotchas/shipping-api-broken-contract.md) (2026-06-01)

### [framework/*] — Framework internals
<!-- No entries yet -->

### [testing/*] — Testing patterns
<!-- No entries yet -->

### [api/*] — API layer
<!-- No entries yet -->

### [licensing/*] — License/EDD store
<!-- No entries yet -->

### [build/*] — Build/CI/release
<!-- No entries yet -->

## Archive (resolved gotchas)
<!-- Resolved gotchas move here; keep for 2 sessions then remove -->
