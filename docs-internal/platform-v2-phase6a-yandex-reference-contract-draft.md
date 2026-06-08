# Platform v2 Phase 6A Reference Draft Migration Contract: WooCommerce Yandex Delivery
> Status: Phase 6A reference draft, non-production, not release-blocking
> Date: 2026-05-30
> Plugin ID: `yandex_delivery` from copied reference evidence only
> Plugin slug: `woocommerce-yandex-delivery` from copied reference path only
> Source version: `1.3.7` from copied reference header only
> Target version: requires real production repo / installed-site validation
> Repository: `plugins-reference/woocommerce-yandex-delivery` copied reference only

## Phase 6A Boundary

This document is a framework-side validation artifact for the Platform v2 migration-contract workflow.

It is explicitly:

- reference-based;
- non-production;
- not release-blocking;
- not a real Phase 6B migration contract;
- not approval to rewrite `woocommerce-yandex-delivery`;
- not evidence that production installed-site contracts are complete.

The copied plugin under `plugins-reference/woocommerce-yandex-delivery` was inspected read-only. Production Phase 6B must happen in the real plugin repository with release history, package contents, and installed-site database evidence before any PHP rewrite begins.

## Purpose Of This Draft

This is the second Phase 6A reference draft. The first draft exercised `woocommerce-edostavka` (legacy migration maps, WP-Cron, WC API webhooks, deprecated wrappers, custom data-store keys, and order meta). This second draft exercises `woocommerce-yandex-delivery` to confirm the template is workable for a plugin with a different installed-site surface: custom database tables, custom REST routes, Action Scheduler recurring scheduling, WC session keys, checkout POST fields, shipping rate meta, localized script objects, a custom WC_Email class, and competitor detection notices.

By creating a second reference draft from a different plugin shape, this session validates that the Phase 6A workflow is not overly tailored to one plugin and that no additional framework-side template/workflow gaps remain.

## Candidate Selection Gate

| Field | Required evidence | Status |
|-------|-------------------|--------|
| Selected plugin repository | Real production repository path/name. | Not satisfied; copied reference path is `plugins-reference/woocommerce-yandex-delivery`. Requires real production repo / installed-site validation. |
| Current production release source | Git tag, release artifact, or deployed version reference. | Not satisfied; copied header says `1.3.7`, but production release source requires real production repo / installed-site validation. |
| Target migration version | Planned version that will ship Platform v2 migration. | Not satisfied; requires release plan in real production repo. |
| Plugin type | WooCommerce shipping method plugin with custom data stores and REST routes. | Reference evidence: main class extends `Woodev_Plugin`; shipping classes extend `WC_Shipping_Method`; registers `WC_Data_Store` key `yandex-warehouses`; registers custom REST namespace/routes; registers custom `WC_Email` class. |
| Contract owner | Person/session responsible for confirming installed-site contracts. | Not satisfied for production; this Phase 6A draft was prepared as framework methodology validation only. |
| Evidence completeness | All required sections answerable from source/docs/release history/installed-site data. | Not satisfied; copied source fills many identifiers, but production options, license state, AS rows, warehouse table rows, warehouse REST endpoint reachability, and package identity require Phase 6B evidence. |

## Installed-Site Identity

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Stable plugin ID | `yandex_delivery` (used as `$method_id`, hook prefix, option prefix). | Preserve `yandex_delivery` unless real production contract proves a migration. | `woocommerce-yandex-delivery.php:28-30`, integration class key. | Preserve; integration settings and admin slugs depend on it. |
| Plugin slug | `woocommerce-yandex-delivery` from copied folder. | Preserve package slug if production package matches. | Reference path `plugins-reference/woocommerce-yandex-delivery`. | Requires real production repo / installed-site validation. |
| Plugin basename | Expected `woocommerce-yandex-delivery/woocommerce-yandex-delivery.php`. | Preserve if production package matches. | Reference main file path `woocommerce-yandex-delivery.php`. | Requires real production package validation. |
| Main plugin file | `woocommerce-yandex-delivery.php`. | Preserve installed basename and update identity. | Reference main file. | Requires real package validation. |
| Update identity | Woodev store download page and EDD download ID `821`. | Preserve updater continuity. | `get_download_id()` returns `821`; `get_sales_page_url()` returns `https://woodev.ru/downloads/yandex-dostavka-woocommerce`. | Requires production license/updater state validation. |
| Text domain | Framework text domain `woodev-plugin-framework`; no plugin-header `Text Domain:` line. | Preserve runtime text domain or document intentional change. | Header has `Domain Path: /languages` but no explicit `Text Domain:`; framework manages text domain. | Requires production language-file/package validation. |

## Loader Contract

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Loader entry path | Main plugin file includes vendored `woodev/bootstrap.php`. | Keep include-based runtime loading. | `woocommerce-yandex-delivery.php` includes `woodev/bootstrap.php`. | Preserve include path discipline. |
| Framework include path | `woodev/bootstrap.php` from plugin directory. | Selected framework copy via Platform v2 resolver facade. | Reference entry file. | Do not move runtime behavior into bootstrap/resolver. |
| Framework version | `1.4.0`. | Target framework version requires production migration plan. | `register_plugin()` first arg. | Update only in production Phase 6B loader rewrite. |
| Platform value | WooCommerce. | `woocommerce` closed Platform v2 value. | Requires WooCommerce hooks, `WC_Shipping_Method`, `WC_Integration`, `WC_Data_Store_WP`. | Preserve WooCommerce requirement gating. |
| Main class | `WC_Yandex_Delivery` extends `Woodev_Plugin` (direct, not a shipping or payment gateway plugin). | Future target should satisfy WooCommerce platform contract. | `woocommerce-yandex-delivery.php` constructor. | Requires production rewrite plan; do not infer final class here. |
| Callback | `init_wc_yandex_shipping_init`. | Preserve boot timing unless production contract changes it. | `register_plugin()` call. | Requires Phase 6B loader design. |
| Early capabilities | No `is_payment_gateway` or `load_shipping_method` flags; shipping classes loaded via `woocommerce_shipping_init`. | Use only for class availability if needed. | `register_plugin()` has only `minimum_wc_version`, `minimum_wp_version`, `backwards_compatible`. | Do not convert into loose runtime type metadata. |
| Legacy `register_plugin()` args | `minimum_wc_version => 5.6`, `minimum_wp_version => 5.9`, `backwards_compatible => 1.2.1`. | Map through temporary adapter only if production migration window requires it. | `woocommerce-yandex-delivery.php` `register_plugin()` call. | Requires real migration plan. |

## Options And Settings

| Contract item | Current key/value shape | Target key/value shape | Evidence | Migration action |
|---------------|-------------------------|------------------------|----------|------------------|
| Plugin options | `woocommerce_yandex_delivery_settings` (WC Integration API). | Preserve unless explicit idempotent migration exists. | Integration class `WC_Yandex_Delivery_Integration` fields. | Requires installed option payload validation. |
| Settings arrays | Integration settings include `auth_token`, `environment`, `order_export_statuses`, `order_delivered_status`, `order_cancelled_status`, `order_status_update_interval`, `package_method`, `vat_rate`, `dadata_token`, `dadata_secret`, `debug`, widget/map/address/autocomplete options. | Preserve keys and value semantics. | Integration `form_fields` set in `WC_Yandex_Delivery_Integration`. | Requires real production settings export. |
| Feature flags | HPOS true; Cart/Checkout Blocks false. | Preserve or intentionally re-declare with tests. | `woocommerce-yandex-delivery.php` `supported_features`. | Validate during production rewrite. |
| Transients | No plugin-specific transients evidenced outside framework API response caching. | Confirm in Phase 6B. | Reference inspection did not find explicitly named transients. | Requires installed transient validation. |
| Stored notices/admin dismissals | Competitor notifications through `Yandex_Delivery_Competitor` handler; milestone messages via framework. | Preserve or intentionally retire. | Competitor handler detects `yandex-go-delivery.php`, `cdek.php`, `russian-post-and-ems-for-woocommerce.php`; milestones tracked via `Woodev_Lifecycle`. | Requires release-history validation. |

## Legacy Migration Maps

| Legacy contract | Current replacement | Introduced/removed version | Evidence | Migration action |
|-----------------|---------------------|----------------------------|----------|------------------|
| Legacy option keys | No legacy migration evidence in copied plugin lifecycle. | No migration action unless production repo proves older migrations. | `WC_Yandex_Delivery_Lifecycle` has no `$upgrade_versions` array and no `upgrade_to_*` methods. | Requires Phase 6B confirmation in real repo. |
| Legacy license keys/state | No license migration evidence; license uses current framework pattern. | No migration action unless production repo differs. | Lifecycle `install()` only creates tables; no license migration. | Requires real repo validation. |
| Legacy hooks/actions/filters | No deprecated hooks or function wrappers found. | No migration action unless production repo differs. | Reference inspection found no `_deprecated_hook()`, `Hook_Deprecator`, or `_deprecated_function()` usage. | Confirm in Phase 6B. |
| Legacy queue hooks/groups | No legacy AS group cleanup evidence. | No migration action unless production repo differs. | Lifecycle does not clean up old groups. | Confirm in real repo. |
| Legacy method IDs/routes/tables | Only shipping method `yandex_delivery_express` is commented out; no evidence of a shipped rename. | No migration action unless production history shows a rename. | `yandex_delivery_express` commented out in `include_methods()`. | Requires release-history validation. |

## Licensing And Updater State

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| License key option name | Framework-managed, derived from plugin ID `yandex_delivery`. | Preserve active license state. | License instance accessed via `wc_yandex_shipping()->get_license_instance()`. | Requires installed-site validation. |
| License activation state option | Framework-managed activation state. | Preserve. | Framework lifecycle/license pattern; plugin source does not include DB values. | Requires installed-site validation. |
| Instance ID option | Framework-managed. | Preserve. | Not directly evidenced in copied plugin source. | Requires installed-site validation. |
| License status/cache options | Framework-managed license store/cache. | Preserve or refresh safely. | License instance usage in plugin; exact option rows require DB. | Requires installed-site validation. |
| EDD download ID | `821`. | Preserve unless release plan says otherwise. | `get_download_id()` returns `821`. | Preserve updater identity. |
| Updater state/options | Framework updater state tied to file, version, download ID. | Preserve continuity. | Main plugin constructor and `get_download_id()`. | Requires production update tests. |
| Beta update opt-in | Framework-managed. | Preserve if present. | Not directly evidenced in plugin copy. | Requires installed-site validation. |

## WooCommerce Method Contracts

### Payment Gateway IDs

| Gateway | Current ID | Target ID | Evidence | Migration action |
|---------|------------|-----------|----------|------------------|
| Not applicable | Not applicable | Not applicable | Shipping plugin evidence only. | No payment gateway contract in this draft. |

### Shipping Method IDs And Instance Settings

| Method | Current ID | Instance setting keys | Target ID/keys | Evidence | Migration action |
|--------|------------|-----------------------|----------------|----------|------------------|
| Yandex Other Day shipping method | `yandex_delivery_other_day` | `title`, `min_order_cost`, `max_order_cost`, `min_cost`, `max_cost`, `free_cost`, `fee`, `fee_type`, `round_cost`, `round_cost_range`, `include_insurance`, `show_commission`, `description_rate`, `shipping_class_id`, `coupon_free_shipping`, `tariff`, `shipment_type`, `platform_station`, `warehouse_id`, `show_time_interval`. | Preserve current ID and instance keys unless explicit migration exists. | `includes/class-shipping-method-other-day.php` `init_form_fields()`. | Requires installed shipping-zone instance validation. |
| Yandex Express shipping method (commented out) | `yandex_delivery_express` | Not active in copied reference. | Confirm status in production repo. | `woocommerce-yandex-delivery.php` `include_methods()`. | Requires Phase 6B validation of production status. |

## Public Extension Points

| Contract item | Current names | Target names | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Public actions | `wc_yandex_order_status_changed_from_{old}_to_{new}`, `wc_yandex_order_status_changed_to_{status}`, `wc_yandex_order_status_changed`, `wc_yandex_order_mark_as_delivered`, `wc_yandex_order_mark_as_cancelled`, `wc_yandex_delivery_update_sharing_url`, `wc_yandex_delivery_delete_sharing_url`, `wc_yandex_delivery_{id}_fallback_calculate_shipping`. | Preserve or add deprecated wrappers. | Order hooks in `includes/class-wc-yandex-delivery-order.php`, shipping fallback action. | Requires hook usage audit in production. |
| Public filters | `woodev_yandex_map_api_key`, `wc_yandex_suggestions_plugin_default_params`, `wc_yandex_delivery_checkout_script_params`, `wc_yandex_delivery_after_shipping_rate_outputs`, `wc_yandex_delivery_time_interval_params`, `wc_yandex_delivery_formatted_address`, `wc_yandex_delivery_clear_address_part_patterns`, `wc_yandex_delivery_clear_address_part`, `wc_yandex_delivery_order_statuses`, `wc_yandex_delivery_cancel_reasons`, `wc_yandex_delivery_shipping_method_pricing_calculator_params`, `wc_yandex_delivery_prepare_order_data`, `wc_yandex_delivery_rate_cost`, `wc_yandex_delivery_order_places`, `wc_yandex_disabled_statuses_for_export`, `wc_yandex_delivery_integration_form_fields`, `wc_yandex_delivery_abort_cached_response`. | Preserve or add deprecated wrappers. | Audit across all `includes/` files. | Requires usage audit. |
| Deprecated hook wrappers still used by sites | None evidenced in copied reference. | No migration action unless production repo proves deprecations. | Reference inspection found no deprecation infrastructure. | Confirm in Phase 6B. |
| Public PHP methods/classes called by integrations | `WC_Yandex_Delivery`, `WC_Yandex_Delivery_Integration`, `WC_Yandex_Delivery_Shipping_Method_Other_Day`, `WC_Yandex_Warehouse`, `WC_Yandex_Data_Store_Warehouses`, `wc_yandex_shipping()` singleton accessor, procedural helpers in `includes/functions.php`. | Preserve or deprecate safely. | Copied source class/function names. | Requires production extension audit. |

## Scheduled Work And Queues

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Cron hook names | No WP-Cron scheduling evidenced; plugin uses Action Scheduler exclusively. | Validate in Phase 6B for any WP-Cron usage. | Reference inspection found `WC()->queue()` usage, no `wp_schedule_event()`. | Confirm no WP-Cron in production. |
| Recurrence | N/A for WP-Cron; Action Scheduler handles all scheduling. | Validate in Phase 6B. | See AS section below. | N/A |
| Cron payload shape | N/A. | N/A. | No WP-Cron evidence. | N/A |
| Action Scheduler hook names | `wc_yandex_orders_update` (recurring), `wc_yandex_export_order` (single), `wc_yandex_update_order` (single). | Preserve or migrate idempotently. | `WC_Yandex_Delivery_Integration::process_admin_options()` schedules `wc_yandex_orders_update`; `WC_Yandex_Delivery::export_order()` schedules `wc_yandex_export_order`; `WC_Yandex_Delivery_AJAX::export_order()` and `WC_Yandex_Delivery_AJAX::confirm_order_offer()` schedule `wc_yandex_update_order`; `WC_Yandex_Delivery_Order::cancel_action()` schedules `wc_yandex_update_order` with delay. | Validate AS rows, next-run times, and pending/paused state. |
| Action Scheduler recurrence/single mode | `wc_yandex_orders_update`: recurring with configurable interval (default `180 * MINUTE_IN_SECONDS`). `wc_yandex_export_order` and `wc_yandex_update_order`: single actions with varying delays (`time() + 10`, `time() + 1`, `time() + MINUTE_IN_SECONDS`). | Preserve mode and interval semantics. | Integration settings `order_status_update_interval` controls recurrence; action callbacks registered in `includes/functions.php`. | Validate installed AS tables. |
| Action Scheduler payload shape | `wc_yandex_export_order`: `['order_id' => $order_id]`. `wc_yandex_update_order`: `['order_id' => $order_id, 'slim' => true/false]`. | Preserve payload shape. | Scheduling calls in main plugin, AJAX handler, and order class. | Validate pending AS args in production. |
| Action Scheduler group names | None explicitly declared; AS actions use default group. | Preserve or explicitly set group in migration. | No `group` parameter in `schedule_single()` or `schedule_recurring()` calls. | Verify default-group AS rows in installed DB. |
| Queue identifiers | `wc_yandex_orders_update`, `wc_yandex_export_order`, `wc_yandex_update_order` as AS action names. | Preserve. | Action callbacks in `includes/functions.php`. | Validate queue state in production. |
| Queue state options/tables | Action Scheduler custom tables (`actionshop_*`). | Preserve AS table integrity. | Uses `WC()->queue()` which delegates to AS. | Validate installed AS table state. |
| Background job identifiers | None evidenced outside framework; plugin uses Action Scheduler directly, not `Woodev_Background_Job_Handler`. | Validate in Phase 6B. | Reference inspection found no custom background job handler. | Confirm in real repo. |

## Stored Data Schemas

| Contract item | Current schema | Target schema | Evidence | Migration action |
|---------------|----------------|---------------|----------|------------------|
| Custom database tables | `{$wpdb->prefix}wc_yandex_delivery_warehouses` (16 columns: `id`, `name`, `address`, `station_id`, `geo_id`, `comment`, `time_from`, `time_to`, `contact_email`, `contact_name`, `contact_phone`, `flat`, `entrance`, `intercom`, `floor`). Primary key on `id`. | Preserve schema idempotently; only migrate if contract requires column changes. | `includes/class-lifecycle.php` `create_tables()`; `WC_Yandex_Data_Store_Warehouses` performs CRUD operations against this table. | Validate installed table structure and row count; ensure `install()` remains idempotent. |
| Custom post types/statuses | No custom post type evidence; order status mapping settings control WC order status transitions (`order_delivered_status`, `order_cancelled_status`). | Preserve order status mapping semantics. | Integration settings and order hooks. | Requires installed settings validation. |
| WooCommerce data-store keys | `yandex-warehouses` (custom `WC_Data_Store_WP` subclass). | Preserve key. | Registered via `woocommerce_data_stores` filter in main plugin class. | Preserve data-store compatibility; calls to `WC_Data_Store::load('yandex-warehouses')` must work after migration. |
| Post meta keys | No direct `post_meta` evidence; order meta access uses framework HPOS-compatible paths. | Preserve via HPOS-safe access. | `Woodev_Order_Compatibility` usage throughout plugin. | Requires HPOS-compatible migration validation. |
| Order meta keys | `_yandex_delivery_request_id`, `_yandex_delivery_destination_station_id`, `_yandex_delivery_destination_station_address`, `_yandex_delivery_destination_interval_from`, `_yandex_delivery_destination_interval_to`, `_yandex_delivery_sharing_url`, `_yandex_delivery_state_status`. | Preserve via HPOS-safe access; pay attention to UTC timezone semantics for interval keys. | Order meta operations throughout `includes/class-wc-yandex-delivery-order.php`. | Requires installed order meta validation; verify timezone handling for interval fields. |
| User meta keys | No user meta evidence beyond WooCommerce standard fields. | N/A unless production repo differs. | Reference inspection. | Confirm in Phase 6B. |

## Checkout And Frontend State

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| WooCommerce session keys | `chosen_yandex_pickup_point` (or `chosen_yandex_pickup_point_test` when test environment is active) — array indexed by `geo_id` with `[id, name]`; `chosen_yandex_time_interval` — string in `from::to` format. | Preserve session key semantics; `_test` suffix depends on Production-mode real contract. | `WC()->session->get()` calls throughout checkout and AJAX code. | Requires installed/session behavior validation; test-mode key suffix is environment-dependent. |
| Checkout POST field names | `yandex_pickup_point`, `yandex_pickup_point_address`, `yandex_geo_id`, `yandex_time_interval`, `yandex_delivery` array with `platform_station_id`, `platform_station_address`, `is_self_pickup`, `time_interval`. | Preserve field names; any rename would break checkout continuity. | Checkout handler and AJAX pickup-point handler. | Validate checkout flow in production plugin repo. |
| Shipping package payload keys | `destination.yandex_geo_id` added via `cart_shipping_packages` filter; `chosen_payment_method` also propagated into packages. | Preserve keys. | Checkout handler `cart_shipping_packages()` filter. | Preserve for shipping calculator compatibility. |
| Shipping rate meta keys | `yandex_delivery` array with `geo_id`, `pricing_commission`, `pricing_commission_amount` set on `WC_Shipping_Rate`. | Preserve meta shape. | `abstract-shipping-method.php` `calculate_shipping()` sets rate meta. | Preserve for integrations and templates that read rate meta. |
| Frontend localized object names | `wc_yandex_delivery_modal_map_params`, `wc_yandex_delivery_standard_modal_map_params`, `wc_yandex_delivery_checkout_params`, `wc_yandex_checkout_fields_autocomplete_params`, `wc_yandex_suggestions_plugin_params`, `wc_yandex_delivery_shipping_method_params`, `wc_yandex_single_order_params`, `wc_yandex_shipping_params`. | Preserve when external scripts may depend on them. | `wp_localize_script()` calls across checkout, admin, and integration script enqueues. | Requires frontend integration audit; external theme/plugin compatibility may depend on these names. |

## Web And Admin Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| REST namespaces | `wc-yandex-delivery` (derived from `plugin_id_dasherized`). | Preserve or version intentionally. | `WC_Yandex_Delivery_REST_API` handler class and `WC_Yandex_Delivery_Warehouses_REST_API` controller. | Validate REST endpoint reachability. |
| REST route names | `/{namespace}/warehouses` (GET, POST), `/{namespace}/warehouses/(?P<id>[\w-]+)` (GET, PUT/PATCH, DELETE). | Preserve. | `includes/rest-api/class-warehouses-rest-api.php`. | Validate REST route registration and capability checks. |
| WooCommerce API callback endpoints | No `woocommerce_api_` callbacks evidenced. | No migration action unless production repo differs. | Reference inspection found no WC API webhook endpoints. | Confirm in Phase 6B. |
| AJAX action names | `get_yandex_delivery_location_detect`, `get_yandex_delivery_shipment_points`, `set_yandex_delivery_pickup_point`, `set_yandex_delivery_time_interval`, `yandex_delivery_export_order`, `yandex_delivery_update_order`, `yandex_delivery_cancel_order`, `yandex_delivery_print_label_order`, `yandex_delivery_print_act`, `wc_yandex_delivery_get_order_offers`, `wc_yandex_delivery_confirm_order_offer`. | Preserve; note `set_yandex_delivery_pickup_point` and `set_yandex_delivery_time_interval` have `nopriv` variants for guest checkout. | `includes/class-ajax.php` `__construct()`. | Validate public/nopriv/admin capability behavior and nonce keys. |
| WooCommerce AJAX action names | No `wc_ajax_` actions evidenced outside framework. | No migration action unless production repo differs. | Reference inspection found only `wp_ajax_` hooks. | Confirm in Phase 6B. |
| Admin page slugs | `wc-yandex-orders` (submenu under `woocommerce`). | Preserve. | `WD_Yandex_Delivery_Admin::admin_menu()`. | Validate admin deep links. |
| Capability checks | Admin page requires `manage_woocommerce`; order meta box on orders with Yandex shipping method; REST requires `wc_rest_check_manager_permissions('shipping_methods')` with edit for write operations. | Preserve or document intentional change. | Admin, REST, and AJAX capability evidence. | Requires Phase 6B validation. |
| System-status rows | No plugin-specific system-status rows; framework base provides PHP info and REST API status through `Woodev_Plugin` and `Woodev_REST_API` hooks. | Preserve framework status rows. | `woocommerce_system_status_environment_rows` and `woocommerce_rest_prepare_system_status` used by framework. | Confirm no custom plugin status rows hidden in production code. |

## Operational Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Log source names | `yandex_delivery` (plugin method ID used as log handle via `Woodev_Plugin::log()`). | Preserve log source continuity. | Conditional logging in main plugin: `log()` checks `debug_log_enabled()` before calling `parent::log()`. | Validate log file/source names in production. |
| Email IDs | `wc_yandex_sharing_url_email`. | Preserve ID. | `WC_Yandex_Sharing_Url_Email` class registered via `woocommerce_email_classes` filter. | Preserve WooCommerce email settings. |
| Email template paths | `emails/sharing-url.php`, `emails/plain/sharing-url.php`. | Preserve or document intentional override break. | Email class `trigger()` references plugin template path. | Validate template overrides in production. |
| Email placeholders/template variables | `{order_number}`, `{order_date}`, `{status}`, `{sharing_url}`. | Preserve. | Email template and class evidence. | Requires Phase 6B validation. |
| WooCommerce note sources | Competitor notifications via `Yandex_Delivery_Competitor` handler detecting rival plugins and generating WC Admin notes. | Preserve competitor detection behavior or intentionally retire. | `includes/class-competitor.php`. | Requires Phase 6B decision on competitor detection retention. |
| Webhook identifiers | No webhook evidence. | No migration action unless production repo differs. | Reference inspection found no webhook ID state. | Confirm in Phase 6B. |
| Webhook callback URL/action names | No webhook callback evidence. | No migration action unless production repo differs. | No `woocommerce_api_` or custom webhook actions found. | Confirm in Phase 6B. |
| CLI commands | No CLI command evidence in copied reference. | No migration action unless production differs. | Reference inspection. | Confirm in Phase 6B. |

## Migration Routine Rules For A Real Phase 6B Contract

- Every option, license, warehouse-table, REST-route, AS-queue, method-ID, and checkout-state migration must be idempotent.
- Custom `wc_yandex_delivery_warehouses` table creation via `install()` must remain idempotent and non-destructive.
- Warehouse REST routes must remain reachable under the same namespace; route parameter patterns must not change.
- Action Scheduler hooks (`wc_yandex_orders_update`, `wc_yandex_export_order`, `wc_yandex_update_order`) must be preserved or rescheduled idempotently.
- WC session keys and checkout POST field names must remain unchanged unless an explicit migration with compatibility notice is provided.
- License key and activation state must not require reactivation after migration.
- Order meta must be accessed through HPOS-safe compatibility paths.
- `yandex-warehouses` data-store key must remain registered; `WC_Data_Store::load('yandex-warehouses')` must continue to work.
- Email ID `wc_yandex_sharing_url_email` must remain registered for sites that have customized email settings.
- Failure behavior must be documented before production PHP rewrite work starts.

## Release-Blocking Verification Gates For Real Phase 6B

- Fresh install works in the real production plugin repo, including warehouse table creation.
- Upgrade from the latest production release works, including existing warehouse table rows.
- Upgrade from at least one older supported production release works when feasible.
- License remains active after migration.
- Updater identity remains continuous for EDD download ID `821`.
- Existing integration settings and shipping instance settings remain unchanged.
- Shipping method IDs (`yandex_delivery_other_day`, and `yandex_delivery_express` if it was ever shipped) remain available unless a production contract explicitly migrates them.
- REST endpoints under `wc-yandex-delivery` namespace remain reachable with same route patterns and capability checks.
- Action Scheduler recurring hook `wc_yandex_orders_update` keeps correct interval and next-run state.
- WC session keys and checkout POST fields remain compatible with existing customizations and themes.
- Stored warehouse and order data remains available through existing data-store and HPOS-safe paths.
- Email `wc_yandex_sharing_url_email` continues to work for sites with active email customizations.

## Comparison With First Reference Draft (Edostavka)

This second draft exercises contract sections that the first Edostavka draft tested less:

| Section | Edostavka stress | Yandex stress |
|---------|------------------|---------------|
| Legacy Migration Maps | High — has `$upgrade_versions`, legacy option/license migration, deprecated hooks, AS group cleanup. | Low — no migration evidence in lifecycle; no deprecated hooks. |
| Scheduled Work | WP-Cron with custom interval; AS cleanup references only. | Action Scheduler recurring + single scheduling with payload shapes and variable delays; no WP-Cron. |
| WooCommerce API Callbacks | High — `woocommerce_api_wc_edostavka_{resource}` webhook endpoints with remote IDs. | None evidenced. |
| Stored Data Schemas | High — custom WC data-store keys, order meta, user meta, session store. | High — custom DB table (`wc_yandex_delivery_warehouses`) with explicit schema; custom WC data-store key; order meta. |
| REST Routes | None evidenced outside framework. | Full CRUD REST namespace with 5 routes, parameter patterns, and capability checks. |
| Checkout State | Session keys, POST field `edostavka_shipping`, rate meta, package payload. | More session keys, POST field arrays (`yandex_delivery[]`), shipping rate meta objects, test-mode key suffix. |
| Frontend Localization | Not fully filled from evidence. | 8 script object names documented from `wp_localize_script()` calls. |
| Emails | 3 email classes with tracking/delivered/not-delivered templates. | 1 email class with sharing URL template and custom form field. |
| Operational | Webhook IDs and callback URLs. | Competitor detection notes; no webhooks. |

Both drafts combined cover all contract sections with realistic plugin-specific evidence. The Edostavka draft stresses backwards-migration and hook/webhook continuity; the Yandex draft stresses table schemas, REST routes, Action Scheduler scheduling payloads, and checkout/session state fidelity.

## Template Workflow Validation

The migration-contract template was fillable for both reference plugins without requiring new sections or structural changes. Each draft revealed different evidence completeness patterns, which confirms that the template section coverage is appropriate for real production plugins of different shapes.

No new framework-side template gap appeared. The remaining unknowns in both drafts are expected Phase 6B evidence gaps requiring real production repositories, release histories, package identities, installed-site options/license rows, AS/cron table state, and live checkout/admin/REST validation.

Phase 6A is now validated against both reference plugins. The workflow is not tailored to a single plugin shape. Production Phase 6B can begin once a real plugin repository is selected.

## Related

- [Platform v2 Implementation Spec](platform-v2-implementation-spec.md) — active Phase 6 contract requirements.
- [Platform v2 Migration Contract Template](platform-v2-migration-contract-template.md) — template exercised by this draft.
- [Platform v2 Phase 6A Reference Gap Analysis](platform-v2-phase6a-reference-gap-analysis.md) — boundary and prior reference coverage.
- [Platform v2 Phase 6A Edostavka Reference Draft](platform-v2-phase6a-edostavka-reference-contract-draft.md) — first reference draft for comparison.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — rewrite-first policy and installed-site boundary.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md) — resolver responsibility boundary.
- [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — explicit loader API and metadata limits.