# Yandex Delivery Data-Preservation Checklist (S1 second pilot)

> Status: release-blocking contract list for the eventual production yandex rewrite.
> Date: 2026-06-04
> Plugin ID: `wc_yandex_delivery` (dasherized `wc-yandex-delivery`)
> EDD download ID: **821** (operator-supplied 2026-06-04)
> Source of shape: `plugins-reference/woocommerce-yandex-delivery` (read-only evidence; not copied)
> Format mirrors `docs-internal/migration/edostavka-data-preservation-checklist.md`.

## Scope And Caveat

This checklist enumerates the installed-site data contracts the **eventual yandex rewrite onto
the S1 shipping module** must preserve byte-for-byte (`CLAUDE.md` clean-break policy →
*Installed-site data contracts*). Yandex is the **second pilot** (audit D-5): it carries the
PVZ-map reference, so its contracts validate the new PVZ abstraction.

**Every item below must be preserved by the eventual rewrite — NOT enforced by the framework
module.** The S1 yandex-fixture (`tests/_fixtures/woodev-yandex-pilot-plugin/` +
`tests/unit/YandexPilotFixtureTest.php`) only proves a yandex-shaped plugin loads end-to-end
through the new module and asserts the identity-level strings (method IDs, REST ns, warehouse
table, meta prefix). All other contracts are documented here for the rewrite.

Strings are copied **verbatim** from the reference with `file:line` provenance. Three items a
read-only static survey cannot confirm are flagged **PENDING OPERATOR** (decision §6c) — the
operator supplies them from a live install; they are release-blocking but do not block the
framework-build queue.

## Installed-Site Identity

| Contract item | Current value | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Stable plugin ID | `wc_yandex_delivery` | lifecycle option prefix | Preserve | No |
| Shipping method ID (express) | `yandex_delivery_express` | `includes/class-shipping-method-express.php:11` | Preserve byte-for-byte | **Yes** (asserted) |
| Shipping method ID (other-day) | `yandex_delivery_other_day` | `includes/class-shipping-method-other-day.php:11` | Preserve byte-for-byte | **Yes** (asserted) |
| EDD download ID | **821** | operator-supplied (2026-06-04) | Preserve updater identity continuity | No |

## Options And Settings

| Contract item | Current key | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Primary integration settings | `woocommerce_yandex_delivery_settings` | `woocommerce-yandex-delivery.php:226` | Preserve / migrate idempotently | **Yes** (asserted) |
| Shared (DaData) settings | `wc_woodev_shared_settings` | `woodev/shipping-method/class-shipping-method-integration.php:269` | Preserve / migrate idempotently | No |
| DaData token (inside integration settings) | `dadata_token` | `includes/class-checkout.php` (integration option) | Preserve | No |
| Activation flag | `woodev_wc_yandex_delivery_is_active` | `woodev/class-lifecycle.php:130` | Preserve | No |
| Milestone messages | `woodev_wc_yandex_delivery_milestone_messages` | `woodev/class-lifecycle.php:381` | Preserve | No |
| Milestone version | `woodev_wc_yandex_delivery_milestone_version` | `woodev/class-lifecycle.php:528` | Preserve | No |
| Orders-per-page | `wc_yandex_orders_per_page` | `includes/admin/class-admin.php:129` | Preserve | No |
| Setup-wizard complete | `wc_wc_yandex_delivery_setup_wizard_complete` | `woodev/admin/abstract-plugin-admin-setup-wizard.php:1059` | Preserve | No |

## WooCommerce Method Contracts

### Shipping Method IDs And Instance Settings

| Method | Current ID | Instance setting keys | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Yandex Express | `yandex_delivery_express` | shared base keys (below) | Preserve method ID; verify zone rows in production checklist | **Yes** (ID only) |
| Yandex Other-day | `yandex_delivery_other_day` | base keys + `tariff`, `shipment_type`, `platform_station`, `warehouse_id`, `show_time_interval` | Preserve method ID + per-instance keys | **Yes** (ID only) |

**Base per-instance setting keys** (`includes/abstract-shipping-method.php:25–151`), preserve verbatim:
`title`, `min_order_cost`, `max_order_cost`, `min_cost`, `max_cost`, `free_cost`, `fee`,
`fee_type`, `round_cost`, `round_cost_range`, `include_insurance`, `show_commission`,
`description_rate`, `shipping_class_id`, `coupon_free_shipping`.
Other-day adds (`includes/class-shipping-method-other-day.php:46–84`): `tariff`,
`shipment_type`, `platform_station`, `warehouse_id`, `show_time_interval`.

### WooCommerce Shipping-Zone Persistence

| Contract item | Current shape | Migration action | Fixture-enforced? |
|---|---|---|---|
| Shipping-zone method rows | `woocommerce_shipping_zone_methods` with `method_id ∈ {yandex_delivery_express, yandex_delivery_other_day}` + `instance_id` | Preserve `method_id` exactly; do not force zone recreation | No — verify against production DB |
| Per-instance method settings | `woocommerce_{method_id}_{instance_id}_settings` (WC convention) | Inventory exact keys from production; migrate idempotently | No |

## Stored Data Schemas

| Contract item | Current schema | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Warehouse custom table | `{$wpdb->prefix}wc_yandex_delivery_warehouses` (via WC data store `WC_Yandex_Data_Store_Warehouses`) | DDL `includes/class-lifecycle.php:54–73`; store `…/class-data-store-warehouses.php:13–31` | **Preserve table + schema byte-for-byte** (decision §6b: per-plugin table, no migration) — **CONFIRMED** from reference DDL | **Yes** (table name asserted) |
| Warehouse columns (exact DDL) | `id bigint(20) unsigned NOT NULL auto_increment` (PRIMARY KEY) · `name varchar(200) NOT NULL` · `address varchar(255) NOT NULL` · `station_id varchar(255) NOT NULL` · `geo_id bigint(20) NULL` · `comment longtext NULL` · `time_from varchar(200) NULL` · `time_to varchar(200) NULL` · `contact_email varchar(200) NULL` · `contact_name varchar(200) NULL` · `contact_phone varchar(200) NULL` · `flat varchar(200) NULL` · `entrance varchar(200) NULL` · `intercom varchar(200) NULL` · `floor varchar(200) NULL` | `includes/class-lifecycle.php:54–73` | Preserve schema byte-for-byte (DB schema `auto_guardable:false` → human-only) | No |
| Order meta keys | `_yandex_delivery_request_id`, `_yandex_delivery_destination_station_id`, `_yandex_delivery_destination_station_address`, `_yandex_delivery_destination_interval_from`, `_yandex_delivery_destination_interval_to`, `_yandex_delivery_sharing_url`, `_yandex_delivery_state_status` | `includes/class-order.php:45–100` | Preserve via HPOS-safe access | **Yes** (prefix `_yandex_delivery_` asserted) |

## Checkout And Frontend State

| Contract item | Current shape | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Chosen-point session key | `chosen_yandex_pickup_point` (prod) / `chosen_yandex_pickup_point_test` (test) | `includes/functions.php:316–323` | Preserve (incl. test-env variant) | **Yes** (asserted) |
| Time-interval session key | `chosen_yandex_time_interval` | `includes/class-ajax.php:96` | Preserve | No |
| Hidden checkout fields | `yandex_pickup_point`, `yandex_pickup_point_address`, `yandex_geo_id`, `yandex_time_interval` | `includes/class-checkout.php:251–253,370–372` | Preserve field names | No |
| DaData suggestions | jQuery `suggestions-jquery@22.6.0` (CDN) gated on `dadata_token` | `includes/class-checkout.php:156–182` | Preserve behavior; ship as `Address_Normalizer` impl in plugin | No |

## Scheduled Work And Queues

| Contract item | Current shape | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Single update event | `wc_yandex_update_order`, payload `['order_id' => int, 'slim' => bool]` (Action Scheduler `schedule_single`) | `includes/class-ajax.php:126`, `includes/functions.php:519`, `includes/class-order.php:416` — all 3 identical; callback `wc_yandex_delivery_update_order($order_id, $is_update)` via `add_action(…,10,2)` (`functions.php:542`) | Preserve hook + **payload shape AND key order** — AS passes the assoc values positionally, so `'slim'` MUST stay the 2nd entry (maps to `$is_update`); reordering keys or reading by key silently breaks it (`auto_guardable:false` → human-only) — **CONFIRMED** | No |
| Recurring update | `wc_yandex_orders_update`, interval from `order_status_update_interval` option | `includes/class-integration.php:57` (`schedule_recurring`) | Preserve hook + recurrence | No |

## Web And Admin Surface

| Contract item | Current value | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| REST namespace | `wc-yandex-delivery` (`$plugin->get_id_dasherized()`) | `includes/rest-api/class-warehouses-rest-api.php:8` | Preserve namespace | **Yes** (asserted) |
| REST routes | `/warehouses`, `/warehouses/(?P<id>[\w-]+)` | `includes/rest-api/class-warehouses-rest-api.php:14,31` | Preserve route shape | No |
| Admin page slug | `wc-yandex-orders` | `includes/admin/class-admin.php:39` | Preserve | No |
| AJAX actions (frontend) | `get_yandex_delivery_location_detect`, `get_yandex_delivery_shipment_points`, `set_yandex_delivery_pickup_point`, `set_yandex_delivery_time_interval` | `includes/class-ajax.php:10–17` (auth + nopriv) | Preserve | No |
| AJAX actions (admin) | `yandex_delivery_export_order`, `yandex_delivery_update_order`, `yandex_delivery_cancel_order`, `yandex_delivery_print_label_order`, `yandex_delivery_print_act`, `wc_yandex_delivery_get_order_offers`, `wc_yandex_delivery_confirm_order_offer` | `includes/class-ajax.php:19–25` | Preserve | No |

## Operational Surface

| Contract item | Current value | Provenance | Migration action | Fixture-enforced? |
|---|---|---|---|---|
| Log source name | `yandex-delivery` | `woodev/class-plugin.php` (WC logger source) | Preserve | No |
| Webhook endpoints | **None** — yandex is outbound-only (no incoming webhook) | full-plugin grep | N/A (generic webhook base validated by edostavka, not yandex — spec §4.3) | No |
| Map API key (default, overridable) | `8bc059fe-74ce-41b5-8128-49037d12f0ba` via `apply_filters('woodev_yandex_map_api_key', …)` | `includes/class-checkout.php:106` | Preserve filter; ship in yandex `Map_Provider` | No |

## Public Hooks (third-party contract)

Preserve these `do_action`/`apply_filters` names (subset; full list in survey). Renaming breaks
third-party subscribers — `hooks` is a contract zone in `.autodev/INVARIANTS.md`:
`wc_yandex_order_status_changed`, `wc_yandex_order_status_changed_to_{status}`,
`wc_yandex_order_mark_as_delivered`, `wc_yandex_order_mark_as_cancelled`,
`wc_yandex_delivery_update_sharing_url`, `wc_yandex_delivery_delete_sharing_url`,
`woodev_yandex_map_api_key`, `wc_yandex_suggestions_plugin_default_params`,
`wc_yandex_delivery_checkout_script_params`, `wc_yandex_delivery_rate_cost`,
`wc_yandex_delivery_prepare_order_data`, `wc_yandex_delivery_order_statuses`,
`wc_yandex_disabled_statuses_for_export`, `wc_yandex_delivery_integration_form_fields`.

## Operator Data — RESOLVED (2026-06-04)

1. **EDD download ID = `821`** (operator-supplied) → fills `license_and_updater` zone / updater identity continuity.
2. **Warehouse table = `{$wpdb->prefix}wc_yandex_delivery_warehouses`** — name + 15-column DDL **CONFIRMED** from `includes/class-lifecycle.php:54–73` (full types in Stored Data Schemas). DB schema = human-only guard.
3. **`wc_yandex_update_order` payload = `['order_id' => int, 'slim' => bool]`** (Action Scheduler single event) — **CONFIRMED** identical across 3 call sites; ⚠️ `'slim'` maps *positionally* to callback param 2 `$is_update` (`add_action(…,10,2)`), so key order is part of the contract. Payload shape = human-only guard.

## Release-Blocking Verification Gates (for the eventual rewrite)

- Method IDs `yandex_delivery_express` / `yandex_delivery_other_day` stable (zone rows key on them).
- `woocommerce_yandex_delivery_settings` + all per-instance keys preserved.
- Warehouse table `wc_yandex_delivery_warehouses` + schema preserved (no forced migration — decision §6b).
- Order meta `_yandex_delivery_*` preserved via HPOS-safe access.
- Session keys `chosen_yandex_pickup_point(_test)` + `chosen_yandex_time_interval` preserved.
- Cron `wc_yandex_update_order` (single, payload shape) + `wc_yandex_orders_update` (recurring) preserved.
- REST ns `wc-yandex-delivery` + routes, admin slug `wc-yandex-orders`, AJAX action families preserved.
- Log source `yandex-delivery`, public `wc_yandex_*` hooks preserved.
- License active + updater identity (EDD download id `821`) continuous.

## Related
- `docs-internal/platform-v2-s1-shipping-spec.md` — the S1 architecture this preserves contracts for.
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — the format mirrored.
- `.autodev/INVARIANTS.md` — these strings become guard candidates (see `guard-yandex-contracts` task).
- `plugins-reference/woocommerce-yandex-delivery` — the read-only evidence source.
