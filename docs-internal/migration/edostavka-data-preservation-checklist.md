# Edostavka Data-Preservation Checklist (Pilot Fixture)
> Status: reference checklist for the eventual production rewrite
> Date: 2026-06-03
> Plugin ID: edostavka (production); fixture id: woodev-edostavka-pilot
> EDD download ID: 216
> Source of shape: `plugins-reference/woocommerce-edostavka` (read-only evidence; not copied)

## Scope And Caveat

This checklist enumerates the installed-site data contracts that the **eventual production
edostavka rewrite** must preserve. It is derived from the migration-contract template
(`docs-internal/platform-v2-migration-contract-template.md`) and the edostavka installed-site
contract data captured during Task P2.

**Every item below must be preserved by the eventual rewrite — NOT enforced by this fixture.**

The pilot fixture (`tests/_fixtures/woodev-edostavka-pilot-plugin/`) only validates that an
edostavka-shaped plugin loads end-to-end through the new Platform v2 load path, and asserts
exactly two of these strings:

- shipping method ID `edostavka`
- settings option key `woocommerce_edostavka_settings`

All other contracts in this document are documented here for the rewrite and are intentionally
**out of fixture scope** (YAGNI — the fixture is not a working shipping plugin).

## Installed-Site Identity

| Contract item | Current value | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| Stable plugin ID | `edostavka` | Preserve | No — must be preserved by the eventual rewrite |
| Shipping method ID | `edostavka` | Preserve | **Yes** (asserted) |
| EDD download ID | `216` | Preserve unless release plan says otherwise | No — must be preserved by the eventual rewrite |

## Options And Settings

| Contract item | Current key | Migration action | Enforced by fixture? |
|---------------|-------------|------------------|----------------------|
| Primary settings option | `woocommerce_edostavka_settings` | Preserve / migrate idempotently | **Yes** (asserted) |
| Webhook IDs option | `wc_edostavka_webhook_ids` | Preserve / migrate idempotently | No — must be preserved by the eventual rewrite |
| Shipping fee payments option | `wc_edostavka_shipping_fee_payments` | Preserve / migrate idempotently | No — must be preserved by the eventual rewrite |
| Migration flag | `wc_edostavka_upgraded_to_2_2_2_0` | Preserve (idempotency guard) | No — must be preserved by the eventual rewrite |

## Legacy Migration Maps

| Legacy contract | Current replacement | Migration action | Enforced by fixture? |
|-----------------|---------------------|------------------|----------------------|
| Legacy settings option | `woocommerce_edostavka-integration_settings` → `woocommerce_edostavka_settings` | Preserve existing migration or add idempotent map | No — must be preserved by the eventual rewrite |
| Legacy license key option | `cdek_woocommerce_shipping_method_license_key` | Preserve existing migration or add idempotent map | No — must be preserved by the eventual rewrite |

## Licensing And Updater State

| Contract item | Current value | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| EDD download ID | `216` | Preserve continuity | No — must be preserved by the eventual rewrite |
| Legacy license key option | `cdek_woocommerce_shipping_method_license_key` | Preserve / migrate idempotently | No — must be preserved by the eventual rewrite |

## WooCommerce Method Contracts

### Shipping Method IDs And Instance Settings

| Method | Current ID | Instance setting storage | Migration action | Enforced by fixture? |
|--------|------------|--------------------------|------------------|----------------------|
| Edostavka | `edostavka` | Global option `woocommerce_edostavka_settings`; zone instances also persist through WooCommerce shipping-zone method rows keyed by `method_id = edostavka` and may have per-instance options shaped as `woocommerce_edostavka_{instance_id}_settings` | Preserve method ID byte-for-byte; enumerate and verify all active zone rows + per-instance settings in the production checklist before rewrite | **Yes** for method ID/global option only; zone rows and per-instance settings are not fixture-enforced |

### WooCommerce Shipping-Zone Persistence

| Contract item | Current shape | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| Shipping-zone method rows | WooCommerce stores enabled zone methods in `woocommerce_shipping_zone_methods` with `method_id = edostavka` and an `instance_id` | Preserve `method_id` exactly; rewrite must not force merchants to recreate shipping zones | No — must be verified against production DB/state during rewrite |
| Per-instance method settings | Potential option key shape `woocommerce_edostavka_{instance_id}_settings` for each active zone instance | Inventory exact keys from production plugin behavior and migrate idempotently if used | No — must be verified against production plugin before rewrite |
| Global method settings | `woocommerce_edostavka_settings` | Preserve / migrate idempotently | **Yes** (asserted) |

## Scheduled Work And Queues

| Contract item | Current shape | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| Cron hook name | `wc_edostavka_orders_update` | Preserve / reschedule idempotently | No — must be preserved by the eventual rewrite |
| Cron schedule name | `wc_edostavka_orders` | Preserve / reschedule idempotently | No — must be preserved by the eventual rewrite |

## Stored Data Schemas

| Contract item | Current schema | Migration action | Enforced by fixture? |
|---------------|----------------|------------------|----------------------|
| Custom database tables | None — uses postmeta/HPOS + WC shipping-zone-methods | No table migration needed | No — must be preserved by the eventual rewrite |
| Order meta keys | `_wc_edostavka_` prefix (`status`, `cdek_order_id`, `tracking_code`, `customer_location`, `delivery_point`) | Preserve via HPOS-safe access | No — must be preserved by the eventual rewrite |
| Shipping-item meta | `edostavka_rate` | Preserve / migrate idempotently | No — must be preserved by the eventual rewrite |
| WooCommerce data stores | `customer-location`, `customer-location-session` | Preserve / migrate idempotently | No — must be preserved by the eventual rewrite |

## Checkout And Frontend State

| Contract item | Current shape | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| Customer-location data store | `customer-location` | Preserve or reset intentionally | No — must be preserved by the eventual rewrite |
| Customer-location session store | `customer-location-session` | Preserve or reset intentionally | No — must be preserved by the eventual rewrite |
| Shipping rate meta | `edostavka_rate` | Preserve or migrate idempotently | No — must be preserved by the eventual rewrite |

## Web And Admin Surface

| Contract item | Current value | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| REST namespace | `wc/v3` | Preserve or version intentionally | No — must be preserved by the eventual rewrite |
| WooCommerce API callback endpoints | `woocommerce_api_wc_edostavka_*` | Preserve webhook callback URL shape | No — must be preserved by the eventual rewrite |
| AJAX action names | `edostavka_*` family — `edostavka_get_deliverypoints`, `edostavka_set_customer_location`, `edostavka_set_delivery_point`, `edostavka_order_action` | Preserve | No — must be preserved by the eventual rewrite |
| Admin page slug | `wc_edostavka_orders` | Preserve | No — must be preserved by the eventual rewrite |

## Operational Surface

| Contract item | Current value | Migration action | Enforced by fixture? |
|---------------|---------------|------------------|----------------------|
| Log source name | `edostavka_orders` | Preserve | No — must be preserved by the eventual rewrite |
| Webhook endpoint identifiers | `woocommerce_api_wc_edostavka_*` | Preserve / migrate intentionally | No — must be preserved by the eventual rewrite |

## Release-Blocking Verification Gates (for the eventual rewrite)

These gates are the responsibility of the production migration contract — they are **not**
covered by the pilot fixture:

- Existing `woocommerce_edostavka_settings` remain unchanged unless an explicit migration says otherwise.
- Shipping method ID `edostavka` remains stable (user shipping-zone configuration depends on `woocommerce_shipping_zone_methods.method_id`).
- Existing WooCommerce shipping-zone rows and any `woocommerce_edostavka_{instance_id}_settings` options remain attached to the same zone instances after migration.
- Legacy option keys (`woocommerce_edostavka-integration_settings`, `cdek_woocommerce_shipping_method_license_key`) continue to migrate idempotently.
- Cron hook `wc_edostavka_orders_update` on schedule `wc_edostavka_orders` remains correct after activation, deactivation, and upgrade.
- Order meta under `_wc_edostavka_` is preserved via HPOS-safe access.
- AJAX `edostavka_*` actions, REST namespace `wc/v3`, and webhook endpoints `woocommerce_api_wc_edostavka_*` remain stable.
- License remains active and updater identity (download ID `216`) stays continuous after migration.

## Related

- [Platform v2 Migration Contract Template](../platform-v2-migration-contract-template.md) — the template this checklist is filled from.
- [Platform v2 Implementation Spec](../platform-v2-implementation-spec.md) — Phase 6 gates and load-path source of truth.
- `tests/_fixtures/woodev-edostavka-pilot-plugin/` — the edostavka-shaped pilot fixture this checklist accompanies.
- `tests/unit/EdostavkaPilotFixtureTest.php` — validates the new load path and the two asserted contract strings.
