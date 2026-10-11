---
name: woodev-shipping-plugin
description: Load before writing or changing a shipping plugin that runs on Woodev Framework v2. Guides plugin authors through shipping methods, rates, checkout, location, pickup, packing, orders, documents, webhooks, tracking, settings, v1 migration and rig acceptance.
---

# Woodev Shipping Plugin Development

Use this skill for a carrier plugin that consumes Woodev Framework v2. Also load [woodev-plugin-core](../woodev-plugin-core/SKILL.md) for shared plugin loading, lifecycle, settings, licensing, i18n, testing, and framework conventions; this skill adds shipping-specific seams.

The first plugin built on these seams is the CDEK rewrite (`kalbac/woocommerce-edostavka`, branch `v2`, outside this repository). The references cite its files as worked examples and its lessons as sources; every rule here also names the framework code, gotcha or card it comes from. A claim you cannot find in `woodev/` does not exist — `rg` the framework before writing the call.

## Start with the framework seams

- Use `Shipping_Plugin` and concrete subclasses of `Shipping_Method`. The courier, pickup, and postal bases are `Shipping_Method_Courier`, `Shipping_Method_Pickup`, and `Shipping_Method_Postal`. See [shipping model and rates](references/model-rates-location-pickup.md).
- `Shipping_Plugin::register_shipping_methods()` is `final`; provide method classes through `get_shipping_method_classes()`. A class that does not extend `Shipping_Method` is silently filtered out. `Shipping_Method::calculate_shipping()` is `final`; implement `rate_package()` instead (`null` = not served; a thrown `Woodev_Plugin_Exception` = failed, the framework hides the method and logs it).
- **One method id per delivery type.** The framework tells pickup from courier by method id, so a v1 plugin that used one id for both ships two (CDEK: `edostavka_courier` + `edostavka_pickup`) and its migration rewrites the zone rows — see [settings and migration](references/settings-migration.md#splitting-a-v1-method-id).
- The framework supplies shared checkout and order plumbing, but the plugin must return its concrete checkout, pickup, and webhook handlers from `Shipping_Plugin` overrides. Follow the [override seam table](references/model-rates-location-pickup.md#plugin-override-seams).
- **Every `Shipping_Method` MUST set `$this->method_title` and `$this->method_description`** (admin-facing name and one-line description, in Russian) in its constructor, BEFORE `parent::__construct()` — like `$this->supports`. WooCommerce reads them for the «Create shipping method» modal and the zone's method list; empty, the card is blank and the merchant cannot tell what they add. The framework falls back to the default title of the delivery type and raises `_doing_it_wrong()` (WP_DEBUG) so you notice — the fallback is a safety net, not a name.
- **"Carrier not configured" is one override point: `Shipping_Plugin::is_configured(): bool`** (base: `Woodev_Plugin::is_configured()`, #1155). While it answers `false` the base plugin shows a non-dismissible warning («<плагин> не настроен…») linking to the carrier's tab on `woodev-settings`, even with no method added to any zone. The default derives from the carrier integration's declared credentials; a carrier whose settings live elsewhere or whose rule is richer (a test mode counts as configured) overrides it in one line: `public function is_configured(): bool { return $this->get_my_settings()->is_configured(); }`. Keep it cheap and free of remote calls (it runs on every wp-admin page). Never re-implement the notice or check configuration per shipping method.
- `get_plugin_name()` returns a plain, untranslated string (it runs before `init`) and feeds the HTTP `User-Agent` — see [shipping traps](references/traps.md#plugin-identity-and-wiring).
- Declare HPOS compatibility in the plugin loader definition and support both classic and block checkout. Do not implement a carrier-owned city dropdown or a second pickup selector/map. See [checkout and pickup](references/model-rates-location-pickup.md).

## Seam checklist — what the framework already does, so you do not

| Concern | Use the framework seam | Detail |
|---|---|---|
| Quote refusal, failures, cache | `rate_package()` → `null` / exception; opt-in `get_rate_cache_context()` | [model and rates](references/model-rates-location-pickup.md) |
| Optional method features | `FEATURE_COST_LIMITS`, `FEATURE_INSURANCE`, `FEATURE_FEE_PAYMENTS`, `FEATURE_CITY_LIMIT`, `declare_services()`, instance `show_if` | [method features](references/method-features.md) |
| Packing | store boxes in «Доставка» → «Упаковка», `get_box_presets()`, `virtual` / `separately` / `boxes` | [packaging](references/packaging.md) |
| City / region fields and carrier city ids | `Location_Adapter`, a carrier `Location_Provider` via `woodev_location_providers`, `Location_Service` helpers | [location](references/model-rates-location-pickup.md#location) |
| Pickup points, parcel lockers | `Point_Source`, `Pickup_Handler`, `Selection_Scope`, `woodev_shipping_pickup_point_selectable` | [pickup](references/model-rates-location-pickup.md#pickup-and-checkout) |
| Export, cancel, a parcel already in transit, refusal | `Abstract_Shipment_Handler` (`is_handed_over()`, `supports_refusal()` / `refuse()`) | [orders and tracking](references/orders-tracking.md#cancel-a-parcel-already-on-its-way-and-refusal-1204) |
| Delivery status, neutral cost / date / issue / courier events | `status_meta_key` + `status_map`; `Shipment_Facts_Events::record()` | [orders and tracking](references/orders-tracking.md#carrier-neutral-shipment-facts-1205) |
| Webhooks | `Abstract_Webhook_Handler` — body is a hint, 200 for every authentic event, opt-in cap-aware subscriptions | [orders and tracking](references/orders-tracking.md#delivery-statuses-and-tracking) |
| Waybills, barcodes, bulk print | `Document_Source`, `Bulk_Document_Source`, `Document_Result::pending()` | [orders and tracking](references/orders-tracking.md#buyer-emails-and-carrier-documents) |
| Courier call and other order operations | order actions with `fields`, row flags, toolbar actions | [orders and tracking](references/orders-tracking.md#order-actions-row-flags-and-toolbar-actions-the-courier-call) |
| Finding orders by meta | a datastore-aware helper — `wc_get_orders()` drops `meta_query` on the posts store | [orders and tracking](references/orders-tracking.md#finding-orders--wc_get_orders-drops-meta_query-on-the-posts-store) |
| Buyer status emails, delivered / cancelled status | framework emails, `Delivered_Order_Status`, `Cancelled_Order_Status` | [orders and tracking](references/orders-tracking.md#buyer-emails-and-carrier-documents) |
| Merchant settings, cards, sections | `get_tab_settings_providers()`, `Settings_Group`, `get_export_section_extension()` | [settings and migration](references/settings-migration.md) |
| Milestones | `Woodev_Lifecycle` — silent without a reviews URL | [orders and tracking](references/orders-tracking.md#milestones) |
| v1 → v2 migration and its wizard | `Woodev_Lifecycle` upgrade routine, Setup Wizard v2 engine | [settings and migration](references/settings-migration.md#migrating-a-v1-plugin) |
| Acceptance, a second carrier on the same site | the rig, a harness that judges on carrier data | [acceptance on the rig](references/acceptance-rig.md), [shipping traps](references/traps.md) |

## Build the merchant settings and operations

- Use the settings ownership map in [settings and migration](references/settings-migration.md): plugin-global sections are supplied by `get_tab_settings_providers()`, while tariffs and per-method fields belong to the WooCommerce shipping-method instance. The framework adds shared sections — `Выгрузка заказов` when orders are registered (cards «Автоэкспорт», «Документы для печати», «Статусы доставки»: auto-export, delivered and cancelled status, «Обновить статусы сейчас», and your own fields and cards through `get_export_section_setting_ids()` / `get_export_section_extension()`) and `Дополнительно` for every carrier (logging, hide on cart; log failures through `log_error()` and debug lines through `log_debug()`) — keys and seams in [settings and migration](references/settings-migration.md#framework-sections-of-the-carrier-tab); use `woodev-settings` by default and take a concrete Integrations-tab exception to the operator.
- Use the shared locality fields and map, shipment/order handlers, canonical delivery statuses, tracking handler, and webhook base. See [model, rates, location, and pickup](references/model-rates-location-pickup.md) and [orders and tracking](references/orders-tracking.md).
- Buyer status emails and carrier documents (waybill/barcode download, single and bulk) are FRAMEWORK features: never write carrier-owned buyer emails or a download flow. Declare `status_meta_key`/`status_map` on the provider (the framework publishes status changes, and the emails, by itself) and implement a `Document_Source` — see [orders and tracking](references/orders-tracking.md). Cost, date, problem and courier changes go through `Shipment_Facts_Events::record()`, never through carrier-owned notes and flags.
- Register the orders provider and shipment handler on EVERY request, not under `is_admin()` — see [orders and tracking](references/orders-tracking.md#shared-order-plumbing).
- For a v1 rewrite, preserve installed-site contracts byte-for-byte and make an explicit migration checklist from `docs-internal/migration/*`; run `npm run probe:signature` and follow `docs-internal/migration/signature-probe.md`. Migrate from the RELEASED code, set the oldest supported version by measuring release archives, and accept the migration on a stand with the real previous release — [settings and migration](references/settings-migration.md#migrating-a-v1-plugin).

## Use fixtures as worked examples

- Use the [fixture map](references/model-rates-location-pickup.md#fixture-map) to jump from each implementation seam to its working example. For the Yandex pilot pickup-source shape, see [`tests/_fixtures/woodev-yandex-pilot-plugin/`](../../../tests/_fixtures/woodev-yandex-pilot-plugin/).
- Use [shipping traps](references/traps.md) while designing and debugging. The linked gotchas explain real framework and WooCommerce failures that matter to carrier authors.
- A green unit suite does not accept a carrier plugin: see [acceptance on the rig](references/acceptance-rig.md).

## Store and carrier packaging

The store keeps its common boxes in «Доставка» → «Упаковка» (`woodev_boxes_boxes`); never duplicate that list inside a carrier. A carrier declares its OWN preset boxes with `Shipping_Plugin::get_box_presets()` in **fixed cm and kg** (stable string `id`, `name`, positive `length`/`width`/`height`, optional `max_weight`/`box_weight`, `cost_mode` = `carrier` | `fixed` | `merchant`), and each `Shipping_Method` that packs declares `FEATURE_BOX_PACKING` before `parent::__construct()`. Modes are `virtual` («всё в одну коробку», a real 3-D placement), `separately` and `boxes`; the old `single` is retired and reads as `virtual`. Everything else — cost modes, settings keys, parcel contents, cache identity, migration of a v1 list — is in [packaging](references/packaging.md).

On the WooCommerce shipping-zone screen the framework re-fires `wc-enhanced-select-init` when a method's settings modal opens, so a `.wc-product-search` / `.wc-enhanced-select` field in your method's `form_fields` works with no JS of yours.
