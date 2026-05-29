# Gotchas — Woodev Plugin Framework
> **12 atomic gotchas in 6 namespaces** — update count when adding/removing.
> Last updated: 2026-05-29 (s4)

## Index

<!-- Format: - [namespace/tag] summary → [gotchas/slug.md](gotchas/slug.md) (s{N}) -->

### [naming/*] — Identifier conventions
- [naming/woodev-spelling] woodev (single 'd'), NEVER wooddev → [gotchas/woodev-spelling.md](gotchas/woodev-spelling.md) (s2)

### [php/*] — PHP / WordPress patterns
- [php/dependency-function-check-bug] get_missing_php_functions() uses extension_loaded instead of function_exists → [gotchas/dependency-function-check-bug.md](gotchas/dependency-function-check-bug.md) (s2)
- [php/namespace-migration-legacy-psr4] Legacy Woodev_* vs PSR-4 Woodev\Framework\* conventions → [gotchas/namespace-migration-legacy-psr4.md](gotchas/namespace-migration-legacy-psr4.md) (s2)
- [php/gateway-type-methods-required] is_credit_card_gateway/is_echeck_gateway/get_payment_type must exist — accidentally deleted in s2 cleanup → [gotchas/gateway-type-methods-required.md](gotchas/gateway-type-methods-required.md) (s3)

### [deprecation/*] — Deprecation cycle
- [deprecation/deprecated-which-function] wc_deprecated_function vs _deprecated_function — which to use when → [gotchas/deprecated-which-function.md](gotchas/deprecated-which-function.md) (s2)
- [deprecation/hook-deprecator-usage] Use Woodev_Hook_Deprecator, not _deprecated_hook() directly → [gotchas/hook-deprecator-usage.md](gotchas/hook-deprecator-usage.md) (s2)

### [bootstrap/*] — Multi-version loading
- [bootstrap/singleton-instantiation] Bootstrap is singleton, constructor is private → [gotchas/singleton-instantiation.md](gotchas/singleton-instantiation.md) (s2)
- [bootstrap/plugin-registration-timing] register_plugin() must run before plugins_loaded → [gotchas/plugin-registration-timing.md](gotchas/plugin-registration-timing.md) (s2)
- [bootstrap/payment-gateway-conditional-load] Payment gateway base class loaded only when is_payment_gateway arg is set → [gotchas/payment-gateway-conditional-load.md](gotchas/payment-gateway-conditional-load.md) (s2)
- [bootstrap/multiversion-early-class-guards] Early-loaded support classes must be guarded and loaded from the selected framework copy → [gotchas/multiversion-early-class-guards.md](gotchas/multiversion-early-class-guards.md) (s4)

### [compat/*] — Backward compatibility, HPOS
- [compat/hpos-order-meta-safety] Never use get_post_meta() on orders — use Woodev_Order_Compatibility → [gotchas/hpos-order-meta-safety.md](gotchas/hpos-order-meta-safety.md) (s2)

### [lifecycle/*] — Install/upgrade routines
- [lifecycle/install-upgrade-detection] Lifecycle detects install vs upgrade by version comparison → [gotchas/lifecycle-install-upgrade-detection.md](gotchas/lifecycle-install-upgrade-detection.md) (s2)

### [woocommerce/*] — WooCommerce-specific
<!-- No entries yet -->

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
