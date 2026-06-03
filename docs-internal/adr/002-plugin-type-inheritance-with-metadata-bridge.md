# ADR-002: Use Inheritance for Plugin Type with Metadata Bridge

**Status:** accepted (metadata-bridge portion superseded)

**Date:** 2026-05-28

> **Superseded in part (2026-06-03, D-2 — see [ADR-005](005-platform-v2-clean-break-policy.md)):** the "deprecated metadata bridge maintained for ≥1 minor release" is overruled by the clean-break decision. Inheritance as the source of truth for plugin type **still holds**; the back-compat bridge for the legacy `is_payment_gateway` / `load_shipping_method` flags is being deleted (capabilities now come only from the explicit loader definition).

## Context

`docs-internal/platform-v2-dependency-matrix.md` identifies plugin type declaration as a P0 spike item because loader behavior changes depending on whether payment, shipping, WooCommerce, or future platform classes must be available before plugin initialization. `PLANS.md` section 2 defines the long-term class hierarchy around platform-specific inheritance, while section 5 calls the current `is_payment_gateway` flag inconvenient and unstable.

The top production risks are compatibility failures in about 12 existing production plugins whose entry files may still pass `is_payment_gateway`, `minimum_wc_version`, or `load_shipping_method` to `register_plugin()`. Removing metadata too early can prevent `Woodev_Payment_Gateway_Plugin` or shipping classes from loading before child plugin declarations, causing fatal errors during activation.

## Decision

- Use inheritance as the long-term source of truth for plugin type.
- Platform plugins should extend the correct base class, for example `Woodev_Woocommerce_Plugin` for WooCommerce plugins and future `Woodev_EDD_Plugin` for EDD plugins.
- Specialized WooCommerce plugin types should be expressed through the class hierarchy, for example payment plugins extending `Woodev_Payment_Gateway_Plugin` when that type is required.
- Keep `register_plugin()` metadata as a deprecated compatibility bridge for at least one minor v2.x release.
- Generalize legacy metadata such as `is_payment_gateway` into explicit compatibility metadata only where the loader still needs it for pre-instantiation module loading.
- Emit `_deprecated_argument()` warnings for legacy metadata, including `is_payment_gateway`, while preserving behavior during the bridge period.
- Treat this ADR as resolving the P0 spike question about plugin type declaration; later specs must define exact deprecation messages, migration paths, and removal timing.
- Do not design payment-gateway internals, shipping UI, licensing webhooks, or the box-packer algorithm as part of this ADR.

## Consequences

Positive:

- The PHP type system becomes the canonical source for platform and plugin-type behavior.
- Static analysis, `instanceof` checks, REST/status/admin branching, and future platform support become more reliable than string flags.
- Existing production plugins get a migration window instead of a breaking entry-file change at v2.0.0.
- Loader implementation can still satisfy early class-loading requirements while plugins migrate to typed inheritance.

Negative:

- The framework must maintain two declaration paths during the bridge period: inheritance and deprecated metadata.
- Deprecated metadata can drift from the actual instantiated class until all production plugins are migrated.
- Loader logic remains more complex for at least one minor v2.x release because it must honor old registration arguments and emit warnings.
- Removal timing must be coordinated carefully across the production plugin fleet to avoid activation-time fatal errors.

## Related

- [Platform v2 Dependency Matrix](../platform-v2-dependency-matrix.md) — P0 plugin type spike, metadata vs inheritance tradeoff, and top production risks.
- [PLANS.md](../../PLANS.md) — sections 2 and 5 define the target class hierarchy and plugin type open question.
- [FUTURE-BACKLOG.md](../FUTURE-BACKLOG.md) — framework decoupling backlog and deferred post-v2 work.
