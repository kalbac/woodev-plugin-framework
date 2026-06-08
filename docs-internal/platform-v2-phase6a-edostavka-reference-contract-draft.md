# Platform v2 Phase 6A Reference Draft Migration Contract: WooCommerce Edostavka
> Status: Phase 6A reference draft, non-production, not release-blocking
> Date: 2026-05-30
> Plugin ID: `edostavka` from copied reference evidence only
> Plugin slug: `woocommerce-edostavka` from copied reference path only
> Source version: `2.2.5.5` from copied reference header only
> Target version: requires real production repo / installed-site validation
> Repository: `plugins-reference/woocommerce-edostavka` copied reference only

## Phase 6A Boundary

This document is a framework-side validation artifact for the Platform v2 migration-contract workflow.

It is explicitly:

- reference-based;
- non-production;
- not release-blocking;
- not a real Phase 6B migration contract;
- not approval to rewrite `woocommerce-edostavka`;
- not evidence that production installed-site contracts are complete.

The copied plugin under `plugins-reference/woocommerce-edostavka` was inspected read-only. Production Phase 6B must happen in the real plugin repository with release history, package contents, and installed-site database evidence before any PHP rewrite begins.

## Purpose Of This Draft

The purpose is to validate that `platform-v2-migration-contract-template.md` is practically fillable from realistic plugin evidence without expanding framework resolver scope or starting production plugin rewrite work.

`woocommerce-edostavka` was selected before `woocommerce-yandex-delivery` because it exercises more migration-contract risk categories in one reference copy: legacy option/license migration maps, deprecated wrappers, WP-Cron scheduling, WooCommerce API webhook callbacks, webhook ID state, custom WooCommerce data-store keys, shipping method state, and order meta. `woocommerce-yandex-delivery` remains the stronger reference for custom database tables, custom REST routes, and multiple shipping method IDs, but Edostavka gives the better first draft target for validating installed-site continuity sections.

## Candidate Selection Gate

| Field | Required evidence | Status |
|-------|-------------------|--------|
| Selected plugin repository | Real production repository path/name. | Not satisfied; copied reference path is `plugins-reference/woocommerce-edostavka`. Requires real production repo / installed-site validation. |
| Current production release source | Git tag, release artifact, or deployed version reference. | Not satisfied; copied header says `2.2.5.5`, but production release source requires real production repo / installed-site validation. |
| Target migration version | Planned version that will ship Platform v2 migration. | Not satisfied; requires release plan in real production repo. |
| Plugin type | WooCommerce shipping method plugin. | Reference evidence: main class extends `Woodev_Plugin`; shipping class extends `WC_Shipping_Method`; hooks register WooCommerce shipping method. |
| Contract owner | Person/session responsible for confirming installed-site contracts. | Not satisfied for production; this Phase 6A draft was prepared as framework methodology validation only. |
| Evidence completeness | All required sections answerable from source/docs/release history/installed-site data. | Not satisfied; copied source fills many identifiers, but production options, license state, queue rows, webhook IDs, and package identity require Phase 6B evidence. |

## Installed-Site Identity

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Stable plugin ID | `edostavka` default method/plugin ID. | Preserve `edostavka` unless real production contract proves a migration. | `woocommerce-edostavka.php:56`, `woocommerce-edostavka.php:438-439`. | Preserve; filter `woocommerce_edostavka_shipping_id` makes installed-site validation required. |
| Plugin slug | `woocommerce-edostavka` from copied folder. | Preserve package slug if production package matches. | Reference path `plugins-reference/woocommerce-edostavka`. | Requires real production repo / installed-site validation. |
| Plugin basename | Expected `woocommerce-edostavka/woocommerce-edostavka.php`. | Preserve if production package matches. | Reference main file path `woocommerce-edostavka.php`. | Requires real production package validation. |
| Main plugin file | `woocommerce-edostavka.php`. | Preserve installed basename and update identity. | `plugins-reference/woocommerce-edostavka/woocommerce-edostavka.php`. | Requires real package validation. |
| Update identity | Woodev store download page and EDD download ID `216`. | Preserve updater continuity. | `woocommerce-edostavka.php:446-447`, `woocommerce-edostavka.php:473-488`. | Requires production license/updater state validation. |
| Text domain | `woocommerce-edostavka` passed to framework; no copied header `Text Domain:` evidence. | Preserve runtime text domain or document intentional change. | `woocommerce-edostavka.php:72-81`; header has `Domain Path: /languages`. | Requires production language-file/package validation. |

## Loader Contract

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Loader entry path | Main plugin file includes vendored `woodev/bootstrap.php`. | Keep include-based runtime loading. | `woocommerce-edostavka.php:29-30`. | Preserve include path discipline. |
| Framework include path | `woodev/bootstrap.php` from plugin directory. | Selected framework copy via Platform v2 resolver facade. | `woocommerce-edostavka.php:29-30`. | Do not move runtime behavior into bootstrap/resolver. |
| Framework version | `1.3.3`. | Target framework version requires production migration plan. | `woocommerce-edostavka.php:33-38`. | Update only in production Phase 6B loader rewrite. |
| Platform value | WooCommerce. | `woocommerce` closed Platform v2 value. | Requires WooCommerce hooks and `WC_Shipping_Method`. | Preserve WooCommerce requirement gating. |
| Main class | `WC_Edostavka_Integration` extends `Woodev_Plugin`. | Future target should satisfy WooCommerce platform contract. | `woocommerce-edostavka.php:44`. | Requires production rewrite plan; do not infer final class here. |
| Callback | `init_wc_edostavka_shipping_init`. | Preserve boot timing unless production contract changes it. | `woocommerce-edostavka.php:33-38`. | Requires Phase 6B loader design. |
| Early capabilities | Shipping classes are registered after WooCommerce shipping init; legacy arg has `load_shipping_method => false`. | Use only for class availability if needed. | `woocommerce-edostavka.php:33-38`, `woocommerce-edostavka.php:84-89`. | Do not convert into loose runtime type metadata. |
| Legacy `register_plugin()` args | `minimum_wc_version => 5.6`, `minimum_wp_version => 5.9`, `backwards_compatible => 1.2.1`, `load_shipping_method => false`. | Map through temporary adapter only if production migration window requires it. | `woocommerce-edostavka.php:33-38`. | Requires real migration plan. |

## Options And Settings

| Contract item | Current key/value shape | Target key/value shape | Evidence | Migration action |
|---------------|-------------------------|------------------------|----------|------------------|
| Plugin options | `woocommerce_edostavka_settings`, `wc_edostavka_shipping_fee_payments`, `wc_edostavka_webhook_ids`. | Preserve unless explicit idempotent migration exists. | `woocommerce-edostavka.php:95`, `woocommerce-edostavka.php:27`, `includes/class-wc-edostavka-integration.php:46`. | Requires installed option payload validation. |
| Settings arrays | WooCommerce integration settings include API credentials, sender/seller/customer/package/order/debug fields. | Preserve keys and value semantics. | `includes/class-wc-edostavka-integration.php:98-675`. | Requires real production settings export. |
| Feature flags | HPOS true; Cart/Checkout Blocks false. | Preserve or intentionally re-declare with tests. | `woocommerce-edostavka.php:72-81`. | Validate during production rewrite. |
| Transients | `woocommerce_edostavka_access_token`, `wc_edostavka_dadata_balance_{hash}`. | Preserve or safely regenerate. | `woocommerce-edostavka.php:25`, `includes/functions-api.php:265`. | Regeneration likely safe but requires production behavior validation. |
| Stored notices/admin dismissals | `wc_edostavka_upgraded_to_2_2_2_0` upgrade notice flag. | Preserve or intentionally retire. | `includes/class-lifecycle.php:86`, `woocommerce-edostavka.php:513`. | Requires release-history validation. |

## Legacy Migration Maps

| Legacy contract | Current replacement | Introduced/removed version | Evidence | Migration action |
|-----------------|---------------------|----------------------------|----------|------------------|
| Legacy option keys | `woocommerce_edostavka-integration_settings` migrated toward current settings. | Requires release history. | `includes/class-lifecycle.php:23`. | Preserve existing migration or add idempotent map in Phase 6B. |
| Legacy license keys/state | `cdek_woocommerce_shipping_method_license_key`, `cdek_woocommerce_shipping_method_license` migrated to framework license state. | Requires release history. | `includes/class-lifecycle.php:74-83`. | Preserve license continuity; validate installed DB. |
| Legacy hooks/actions/filters | `wc_edostavka_update_orders_query_args` deprecated/removed map. | Version `2.2.5.0`. | `woocommerce-edostavka.php:588-594`. | Preserve deprecated wrapper behavior if sites still use it. |
| Legacy queue hooks/groups | Action Scheduler group `wc_edostavka_location_cities` cleanup. | Requires release history. | `includes/class-lifecycle.php:64-67`. | Validate existing AS rows before migration. |
| Legacy method IDs/routes/tables | No source evidence of method ID rename; method ID is filterable. | Requires installed-site validation. | `woocommerce-edostavka.php:438-439`. | Preserve `edostavka` and validate filtered sites. |

## Licensing And Updater State

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| License key option name | Framework-managed, likely derived from plugin ID; legacy key was `cdek_woocommerce_shipping_method_license_key`. | Preserve active license state. | `includes/class-lifecycle.php:74-83`; framework naming requires real DB validation. | Requires installed-site validation. |
| License activation state option | Framework-managed activation state. | Preserve. | Framework lifecycle/license pattern; plugin source does not include DB values. | Requires installed-site validation. |
| Instance ID option | Framework-managed. | Preserve. | Not directly evidenced in copied plugin source. | Requires installed-site validation. |
| License status/cache options | Framework-managed license store/cache. | Preserve or refresh safely. | License instance usage in plugin; exact option rows require DB. | Requires installed-site validation. |
| EDD download ID | `216`. | Preserve unless release plan says otherwise. | `woocommerce-edostavka.php:446-447`. | Preserve updater identity. |
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
| CDEK shipping method | `edostavka` by default, filterable. | `title`, `tariff`, `sender_city`, `delivery_point`, `dropoff_address`, `services`, `show_delivery_time`, `additional_time`, `rate_instruction`, `fee`, `fee_type`, `fee_payments`, `static_price`, `free`, `round_cost`. | Preserve current ID and instance keys unless explicit migration exists. | `includes/class-wc-edostavka-shipping-method.php:15`, `:92-230`; `woocommerce-edostavka.php:438-439`. | Requires installed shipping-zone instance validation. |

## Public Extension Points

| Contract item | Current names | Target names | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Public actions | `wc_edostavka_order_status_changed_from_{old}_to_{new}`, `wc_edostavka_order_status_changed_to_{status}`, `wc_edostavka_order_status_changed`, `wc_edostavka_before_order_update`, `wc_edostavka_after_order_update`, notification actions. | Preserve or add deprecated wrappers. | `includes/class-wc-edostavka-order.php:58-75`, `:335-391`. | Requires hook usage audit in production. |
| Public filters | `woocommerce_edostavka_shipping_id`; order/status/query related filters. | Preserve or add deprecated wrappers. | `woocommerce-edostavka.php:438-439`, `woocommerce-edostavka.php:588-594`. | Requires usage audit. |
| Deprecated hook wrappers still used by sites | `wc_edostavka_update_orders_query_args`; deprecated functions for customer state and map helpers. | Preserve wrapper if production sites still use it. | `woocommerce-edostavka.php:588-594`, `includes/functions.php:1391-1419`. | Requires installed/custom-code validation. |
| Public PHP methods/classes called by integrations | `WC_Edostavka_Integration`, `WD_Edostavka_Shipping`, public procedural helpers. | Preserve or deprecate safely. | Copied source class/function names. | Requires production extension audit. |

## Scheduled Work And Queues

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Cron hook names | `wc_edostavka_orders_update`. | Preserve or reschedule idempotently. | `includes/class-wc-edostavka-cron.php:25-65`. | Validate next-run state in production DB. |
| Recurrence | Custom interval key `wc_edostavka_orders`; interval read from `woocommerce_edostavka_settings[cron_auto_update_orders_interval]`. | Preserve recurrence semantics. | `includes/class-wc-edostavka-cron.php:30-49`. | Requires installed option/schedule validation. |
| Cron payload shape | No args evidenced for `wp_schedule_event`. | Preserve no-arg shape unless production evidence differs. | `includes/class-wc-edostavka-cron.php:62-65`. | Validate cron array in production. |
| Action Scheduler hook names | No active scheduling calls found in copied plugin; cleanup references legacy group. | Requires production validation. | `includes/class-lifecycle.php:64-67`. | Do not invent AS contract from cleanup-only evidence. |
| Action Scheduler recurrence/single mode | Cleanup-only evidence. | Requires production validation. | `includes/class-lifecycle.php:64-67`. | Validate AS tables. |
| Action Scheduler payload shape | Unknown. | Requires production validation. | No copied scheduling call evidence. | Stop for Phase 6B if AS rows exist. |
| Action Scheduler group names | `wc_edostavka_location_cities` cleanup group. | Preserve cleanup/reschedule behavior if rows exist. | `includes/class-lifecycle.php:64-67`. | Requires installed AS table validation. |
| Queue identifiers | Unknown beyond cron hook and cleanup group. | Requires production validation. | Copied source incomplete for installed queue state. | Validate before rewrite. |
| Queue state options/tables | Unknown. | Requires production validation. | No option/table evidence beyond AS cleanup. | Validate installed DB. |
| Background job identifiers | None evidenced outside framework. | Requires production validation. | No copied plugin-specific background job evidence. | Confirm in Phase 6B. |

## Stored Data Schemas

| Contract item | Current schema | Target schema | Evidence | Migration action |
|---------------|----------------|---------------|----------|------------------|
| Custom database tables | No custom `CREATE TABLE` / `dbDelta` evidence in copied plugin. | No table migration unless production repo differs. | Source grep evidence from reference inspection. | Confirm in real repo. |
| Custom post types/statuses | No custom post type evidence; order status mappings/settings exist. | Preserve order status mapping semantics. | Integration settings and order hooks. | Requires installed settings validation. |
| WooCommerce data-store keys | `customer-location`, `customer-location-session`. | Preserve keys. | `woocommerce-edostavka.php:328-350`. | Preserve data-store compatibility. |
| Post meta keys | Direct SQL uses `_wc_edostavka_status`; other postmeta queries exist. | Preserve or migrate HPOS-safely. | `includes/functions.php:1105-1115`. | Requires HPOS/postmeta production validation. |
| Order meta keys | `_wc_edostavka_shipping`, `_wc_edostavka_status`, `_wc_edostavka_chosen_delivery_point`, `_wc_edostavka_customer_location`, `_wc_edostavka_cdek_order_id`, `_wc_edostavka_tracking_code`, `_wc_edostavka_history_statuses`, `_wc_edostavka_can_courier_call`, `_wc_edostavka_latest_order_update_time`, `_wc_edostavka_waybill_downloaded`, `_wc_edostavka_barcode_downloaded`. | Preserve via HPOS-safe access. | `includes/class-wc-edostavka-checkout.php:930-957`, `includes/class-wc-edostavka-order.php:288-423`. | Requires installed order meta validation. |
| User meta keys | Customer location data store persists customer-location object data. | Preserve data-store object semantics. | `includes/data-stores/class-wc-edostavka-customer-data-store.php:21-54`. | Requires installed customer data validation. |

## Checkout And Frontend State

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| WooCommerce session keys | Customer location session store uses object type key; checkout delivery state includes selected delivery point. | Preserve or intentionally reset with compatibility notice. | `includes/data-stores/class-wc-edostavka-customer-session-data-store.php:19-50`. | Requires installed/session behavior validation. |
| Checkout POST field names | `edostavka_shipping` derived from selected shipping method; chosen pickup/postamat fields validated. | Preserve compatibility handling. | `includes/class-wc-edostavka-checkout.php:663-745`. | Validate checkout flow in production plugin repo. |
| Shipping package payload keys | `edostavka_customer_location` package data. | Preserve. | Reference gap analysis evidence from Edostavka checkout/rate flow. | Requires live checkout/rate validation. |
| Shipping rate meta keys | `edostavka_rate`. | Preserve. | `includes/class-wc-edostavka-shipping-method.php:698-705`. | Preserve for integrations/templates. |
| Frontend localized object names | AJAX/frontend script payloads exist but full contract not filled from summary evidence. | Requires production validation. | Copied source has AJAX endpoints and checkout scripts; exact object names require deeper source audit. | Mark Phase 6B evidence gap. |

## Web And Admin Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| REST namespaces | No plugin-specific `register_rest_route()` evidence outside bundled framework. | No plugin REST contract unless production repo differs. | Reference inspection. | Confirm in Phase 6B. |
| REST route names | No plugin-specific REST routes evidenced. | No migration action unless production repo differs. | Reference inspection. | Confirm in Phase 6B. |
| WooCommerce API callback endpoints | `woocommerce_api_wc_edostavka_{resource}` for `order_status`, `print_form`, `download_photo`. | Preserve callback URL/action shape. | `includes/webhooks/abstract-wc-edostavka-webhook.php:28-32`, `includes/webhooks/class-wc-edostavka-webhook-handler.php:64-68`. | Requires webhook reachability and remote ID validation. |
| AJAX action names | `edostavka_get_tariff_by_code`, `edostavka_get_location_cities`, `get_related_location_cities`, `edostavka_get_deliverypoints`, `edostavka_set_customer_location`, `edostavka_set_customer_location_model`, `edostavka_set_customer_location_by_id`, `edostavka_set_delivery_point`, admin order actions. | Preserve. | `includes/class-wc-edostavka-ajax.php:11-49`. | Validate public/nopriv/admin capability behavior. |
| WooCommerce AJAX action names | `edostavka_set_customer_location`, `edostavka_set_customer_location_dadata`, `edostavka_get_offices_for_widget`. | Preserve. | `includes/class-wc-edostavka-ajax.php:11-49`. | Validate checkout frontend compatibility. |
| Admin page slugs | `wc_edostavka_orders`; screen option `wc_edostavka_orders_edit_per_page`. | Preserve. | `includes/admin/class-wc-edostavka-admin.php:74-80`, `:288-297`. | Validate admin deep links. |
| Capability checks | Admin submenu/callback capabilities require real source pass. | Preserve or document intentional change. | Admin class evidence incomplete in this draft. | Requires Phase 6B validation. |
| System-status rows | No plugin-specific system-status row evidence in reference summary. | Requires production validation. | Framework generic status may exist. | Confirm before rewrite. |

## Operational Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Log source names | Framework plugin log tied to plugin ID; API request hook `woodev_edostavka_api_request_performed` may be removed when debug disabled. | Preserve log source/hook continuity. | `woocommerce-edostavka.php:99-100`, `woocommerce-edostavka.php:553-563`. | Validate log file/source names in production. |
| Email IDs | `{method_id}_tracking`, `{method_id}_delivered_order`, `{method_id}_not_delivered_order`. | Preserve IDs. | Email classes under `includes/emails/`, IDs at lines `13-23`. | Preserve WooCommerce email settings. |
| Email template paths | `emails/tracking-code.php`, `emails/plain/tracking-code.php`, `emails/delivered-email.php`, `emails/plain/delivered-email.php`, `emails/not-delivered-email.php`, `emails/plain/not-delivered-email.php`. | Preserve or document intentional override break. | `includes/emails/class-wc-edostavka-*-email.php:13-23`. | Validate template overrides in production. |
| Email placeholders/template variables | Tracking/delivered/not-delivered templates use plugin-specific order state. | Preserve. | Requires deeper source/template audit. | Requires Phase 6B validation. |
| WooCommerce note sources | No custom WC Admin Note evidence. | Requires production validation. | Reference inspection found none. | Confirm in real repo. |
| Webhook identifiers | `wc_edostavka_webhook_ids` stores remote webhook IDs. | Preserve. | `includes/webhooks/class-wc-edostavka-webhook-handler.php:80,89,105`. | Validate installed option and remote webhook state. |
| Webhook callback URL/action names | `WC()->api_request_url( 'wc_edostavka_{resource}' )`. | Preserve URL shape or migrate remote webhooks idempotently. | `includes/webhooks/class-wc-edostavka-webhook-handler.php:64-68`. | Requires live webhook validation. |
| CLI commands | No CLI command evidence in copied reference. | No migration action unless production differs. | Reference inspection. | Confirm in Phase 6B. |

## Migration Routine Rules For A Real Phase 6B Contract

- Every option/license/webhook/method-ID migration must be idempotent.
- Existing `includes/class-lifecycle.php` migrations must be preserved or explicitly superseded with proof.
- License key and activation state must not require reactivation after migration.
- WP-Cron hook `wc_edostavka_orders_update` must be preserved or rescheduled idempotently.
- WooCommerce API webhook URLs must remain reachable or remote webhooks must be updated idempotently.
- Order meta must be accessed through HPOS-safe compatibility paths.
- Failure behavior must be documented before production PHP rewrite work starts.

## Release-Blocking Verification Gates For Real Phase 6B

- Fresh install works in the real production plugin repo.
- Upgrade from the latest production release works.
- Upgrade from at least one older supported production release works when feasible.
- License remains active after migration.
- Updater identity remains continuous for EDD download ID `216`.
- Existing integration and shipping instance settings remain unchanged.
- Shipping method ID `edostavka` remains available unless a production contract explicitly migrates it.
- Public hooks still fire or deprecated wrappers exist.
- Cron schedule and webhook callbacks remain correct after activation, deactivation, and upgrade.
- Stored order/customer/location data remains available through HPOS-safe paths.

## Template Workflow Validation

The current template was fillable for a realistic first reference draft without adding new sections.

The draft exposed no new framework template gap. The remaining unknowns are not template gaps; they are expected Phase 6B evidence gaps that require the real production repository, release history, package identity, installed options, license rows, Action Scheduler tables, cron array, webhook IDs, and live checkout/admin validation.

## Related

- [Platform v2 Implementation Spec](platform-v2-implementation-spec.md) — active Phase 6 contract requirements.
- [Platform v2 Migration Contract Template](platform-v2-migration-contract-template.md) — template exercised by this draft.
- [Platform v2 Phase 6A Reference Gap Analysis](platform-v2-phase6a-reference-gap-analysis.md) — boundary and prior reference coverage.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — rewrite-first policy and installed-site boundary.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md) — resolver responsibility boundary.
- [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — explicit loader API and metadata limits.
