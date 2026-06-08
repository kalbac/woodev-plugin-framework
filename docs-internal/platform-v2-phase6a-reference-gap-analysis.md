# Platform v2 Phase 6A Reference Gap Analysis
> Status: complete
> Date: 2026-05-30

## Purpose

This document validates the Phase 6 migration-contract workflow against copied reference plugins without starting production-plugin migration work.

The reference inputs are read-only evidence for framework-side methodology only:

- `plugins-reference/woocommerce-edostavka`
- `plugins-reference/woocommerce-yandex-delivery`

This is not a production migration contract for either plugin. Phase 6B must happen in the real selected plugin repository with release history and installed-site evidence.

## Phase Boundary

Phase 6A in this repository may refine the contract workflow, template, and evidence checklist. It may inspect copied reference plugins, but it must not edit them and must not rewrite production plugin loaders or classes.

Phase 6B begins only after a production plugin is explicitly selected and work moves to that real plugin repository. Phase 6B requires a filled plugin-specific contract before PHP rewrite work begins.

The resolver/bootstrap boundary remains unchanged: do not expand `woodev/bootstrap.php` or `Framework_Resolver`, do not move payment, shipping, licensing, lifecycle, REST, settings, or runtime behavior into early loading, and keep production plugin loading include-based.

## Reference Coverage

Both reference plugins are useful because they expose complementary installed-site surfaces.

| Reference plugin | Why it was useful | Evidence examples |
|------------------|-------------------|-------------------|
| `woocommerce-edostavka` | Better stress test for legacy migrations, WP-Cron, WC API webhooks, deprecated hooks, Action Scheduler cleanup, and customer-location data stores. | `woocommerce-edostavka.php:33-36`, `includes/class-lifecycle.php:21-86`, `includes/class-wc-edostavka-cron.php:25-64`, `includes/webhooks/abstract-wc-edostavka-webhook.php:28-32` |
| `woocommerce-yandex-delivery` | Better stress test for multiple shipping method IDs, custom database tables, custom REST routes, checkout/session state, and Action Scheduler recurring queues. | `woocommerce-yandex-delivery.php:29-36`, `includes/class-lifecycle.php:41-73`, `includes/rest-api/class-warehouses-rest-api.php:8-15`, `includes/class-integration.php:42-58` |

## Template Fit

The original template already covered the required Platform v2 contract standard from `platform-v2-implementation-spec.md` section 11:

- Stable plugin ID, slug, basename, main file, update identity, version, and target version.
- Option keys, settings arrays, feature flags, transients, licensing, updater state, and EDD download IDs.
- WooCommerce shipping method IDs and instance setting keys.
- Public actions, filters, deprecated hooks, AJAX names, REST namespaces, admin slugs, capabilities, logs, emails, notes, and system-status rows.
- Scheduled work, queues, stored schemas, migration rules, and release-blocking verification gates.

The reference check found no need for framework runtime code changes.

## Gaps Found

| Gap | Evidence | Template change |
|-----|----------|-----------------|
| WooCommerce API callback endpoints were not explicit. These are neither REST routes nor AJAX actions. | Edostavka registers `woocommerce_api_wc_edostavka_{resource}` callbacks in `includes/webhooks/abstract-wc-edostavka-webhook.php:28-32`. | Added `WooCommerce API callback endpoints` and `Webhook callback URL/action names` rows. |
| Action Scheduler needed sharper fields for hook names, single/recurring mode, args, and groups. | Edostavka schedules `wc_edostavka_run_update_order_hook` with group `wc_edostavka_order_actions` and cleans legacy group `wc_edostavka_location_cities`; Yandex schedules recurring `wc_yandex_orders_update`. | Split queue rows into Action Scheduler hook names, mode, payload shape, group names, and queue state. |
| WooCommerce data-store keys were not explicit. | Edostavka registers `customer-location` and `customer-location-session`; Yandex registers `yandex-warehouses`. | Added `WooCommerce data-store keys` under Stored Data Schemas. |
| Checkout/session/request-field state did not fit options or schemas. | Edostavka uses `chosen_delivery_point`; Yandex uses `chosen_yandex_pickup_point`, `chosen_yandex_time_interval`, `yandex_pickup_point`, and related checkout fields. | Added `Checkout And Frontend State` section. |
| Shipping package/rate payloads can be compatibility contracts. | Yandex reads `yandex_delivery` rate meta and shipping package keys; Edostavka reads `edostavka_customer_location` package data. | Added shipping package payload keys and shipping rate meta keys. |
| Email template paths and placeholders are not the same as email IDs. | Edostavka and Yandex register custom `WC_Email` classes with plugin templates. | Added email template path and placeholder rows. |
| Historical migrations need a dedicated map. | Edostavka migrates `woocommerce_edostavka-integration_settings` and legacy license options in `includes/class-lifecycle.php:21-86`. | Added `Legacy Migration Maps` section. |
| Text domain evidence may be explicit or implicit. | Edostavka passes `text_domain`; Yandex lacks a plugin-header `Text Domain` line in the copied entry file. | Clarified text-domain row evidence requirement. |

## Outcome

`docs-internal/platform-v2-migration-contract-template.md` is now sufficient for Phase 6A methodology validation against the two copied reference plugins.

The safe next step remains Phase 6B in a real selected plugin repository. This repo should not produce a ready-for-rewrite contract from copied reference inputs alone.

## Related

- [Platform v2 Implementation Spec](platform-v2-implementation-spec.md) — active Phase 6 contract requirements.
- [Platform v2 Migration Contract Template](platform-v2-migration-contract-template.md) — updated reference-validated template.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — rewrite-first policy and installed-site boundary.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md) — resolver responsibility boundary.
- [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — loader API and metadata limits.
