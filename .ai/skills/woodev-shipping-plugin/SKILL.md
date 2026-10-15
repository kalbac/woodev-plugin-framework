---
name: woodev-shipping-plugin
description: Load before writing or changing a shipping plugin that runs on Woodev Framework v2. Guides plugin authors through shipping methods, rates, checkout, location, pickup, packing, orders, documents, webhooks, tracking, settings, v1 migration and rig acceptance.
---

# Woodev Shipping Plugin Development

Use this skill for a carrier plugin that consumes Woodev Framework v2. Also load [woodev-plugin-core](../woodev-plugin-core/SKILL.md) for shared plugin loading, lifecycle, settings, licensing, i18n, testing, and framework conventions; this skill adds shipping-specific seams.

The first two plugins built on these seams are the CDEK rewrite (`kalbac/woocommerce-edostavka`, branch `v2`) and the Yandex Delivery rewrite (`kalbac/woocommerce-yandex-delivery`, branch `main`), both outside this repository and both private — read them with `gh` or from the operator's checkouts (`~/Projects/…`). The references cite their files as worked examples and their lessons as sources; CDEK is the reference for a carrier with webhooks, a location dictionary and a courier call, Yandex for an offers-based export, a bare-answer geo adapter and a second carrier on one site; every rule here also names the framework code, gotcha or card it comes from. A claim you cannot find in `woodev/` does not exist — `rg` the framework before writing the call.

## Coding conventions

Group file-level class imports from the same namespace in one statement; keep `use function` and `use const` separate. Example: `use Woodev\Edostavka\Api\{Cdek_Api_Client, Cdek_Api_Exception};`

## Start with the framework seams

- Use `Shipping_Plugin` and concrete subclasses of `Shipping_Method`. The courier, pickup, and postal bases are `Shipping_Method_Courier`, `Shipping_Method_Pickup`, and `Shipping_Method_Postal`. See [shipping model and rates](references/model-rates-location-pickup.md).
- `Shipping_Plugin::register_shipping_methods()` is `final`; provide method classes through `get_shipping_method_classes()`. A class that does not extend `Shipping_Method` is silently filtered out. `Shipping_Method::calculate_shipping()` is `final`; implement `rate_package()` instead (`null` = not served; a thrown `Woodev_Plugin_Exception` = failed, the framework hides the method and logs it).
- **One method id per delivery type.** The framework tells pickup from courier by method id, so a v1 plugin that used one id for both ships two (CDEK: `edostavka_courier` + `edostavka_pickup`) and its migration rewrites the zone rows — see [settings and migration](references/settings-migration.md#splitting-a-v1-method-id).
- The framework supplies shared checkout and order plumbing, but the plugin must return its concrete checkout and pickup handlers from `Shipping_Plugin` overrides, and its webhook handler too if the carrier has inbound webhooks (`get_webhook_handler()` defaults to `null`). Follow the [override seam table](references/model-rates-location-pickup.md#plugin-override-seams).
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
| Inbound webhooks, only where the carrier pushes events | `Abstract_Webhook_Handler` verifies and dispatches; the trust, acknowledgement and subscription rules are carrier-specific | [orders and tracking](references/orders-tracking.md#delivery-statuses-and-tracking) |
| Waybills, barcodes, bulk print, carrier-named documents | `Document_Source`, `Bulk_Document_Source`, `Document_Result::pending()`, `Document_Type_Declarations` | [orders and tracking](references/orders-tracking.md#buyer-emails-and-carrier-documents) |
| A method yields no rate — tell the manager why | `Shipping_Method::report_hidden( $reason_code, $message )` beside your log line | [method features](references/method-features.md#why-a-method-is-hidden-the-admin-only-hint) |
| An export whose outcome is unknown | `supports_reconcile()` or the framework `woodev_retry_export` action — never your own retry button | [orders and tracking](references/orders-tracking.md#an-export-with-an-unknown-outcome-1253-1274) |
| A paid plugin without a valid licence | automatic: `License_Gate` hides the rates and refuses export / cancel / documents; flag a read-only order action `read_only` | [shipping traps](references/traps.md#licence-gate-1270) |
| Courier call and other order operations | order actions with `fields`, row flags, toolbar actions | [orders and tracking](references/orders-tracking.md#order-actions-row-flags-and-toolbar-actions-the-courier-call) |
| Finding orders by meta | a datastore-aware helper — `wc_get_orders()` drops `meta_query` on the posts store | [orders and tracking](references/orders-tracking.md#finding-orders--wc_get_orders-drops-meta_query-on-the-posts-store) |
| Buyer status emails, delivered / cancelled status | framework emails, `Delivered_Order_Status`, `Cancelled_Order_Status` | [orders and tracking](references/orders-tracking.md#buyer-emails-and-carrier-documents) |
| Merchant settings, cards, sections | `get_tab_settings_providers()`, `Settings_Group`, `get_export_section_extension()` | [settings and migration](references/settings-migration.md) |
| Milestones | `Woodev_Lifecycle` — silent without a reviews URL | [orders and tracking](references/orders-tracking.md#milestones) |
| v1 → v2 migration and its wizard | `Woodev_Lifecycle` upgrade routine, Setup Wizard v2 engine | [settings and migration](references/settings-migration.md#migrating-a-v1-plugin) |
| Acceptance, a second carrier on the same site | the rig, a harness that judges on carrier data | [acceptance on the rig](references/acceptance-rig.md), [shipping traps](references/traps.md) |

## Build the merchant settings and operations

- Use the settings ownership map in [settings and migration](references/settings-migration.md): plugin-global sections are supplied by `get_tab_settings_providers()`, while tariffs and per-method fields belong to the WooCommerce shipping-method instance. The framework adds shared sections — `Выгрузка заказов` when orders are registered (cards «Автоэкспорт», «Документы для печати», «Статусы доставки»: auto-export, delivered and cancelled status, «Обновить статусы сейчас», and your own fields and cards through `get_export_section_setting_ids()` / `get_export_section_extension()`) and `Дополнительно` for every carrier (logging, hide on cart; log failures through `log_error()` and debug lines through `log_debug()`) — keys and seams in [settings and migration](references/settings-migration.md#framework-sections-of-the-carrier-tab); use `woodev-settings` by default and take a concrete Integrations-tab exception to the operator.
- Use the shared locality fields and map, shipment/order handlers, canonical delivery statuses, tracking handler, and, when the carrier pushes inbound events, the webhook base. See [model, rates, location, and pickup](references/model-rates-location-pickup.md) and [orders and tracking](references/orders-tracking.md).
- Buyer status emails and carrier documents (waybill/barcode download, single and bulk) are FRAMEWORK features: never write carrier-owned buyer emails or a download flow. Declare `status_meta_key`/`status_map` on the provider (the framework publishes status changes, and the emails, by itself) and implement a `Document_Source` — see [orders and tracking](references/orders-tracking.md). Cost, date, problem and courier changes go through `Shipment_Facts_Events::record()`, never through carrier-owned notes and flags.
- Register the orders provider and shipment handler on EVERY request, not under `is_admin()` — see [orders and tracking](references/orders-tracking.md#shared-order-plumbing).
- For a v1 rewrite, preserve installed-site contracts byte-for-byte and make an explicit migration checklist from `docs-internal/migration/*`; run `npm run probe:signature` and follow `docs-internal/migration/signature-probe.md`. Migrate from the RELEASED code, set the oldest supported version by measuring release archives, and accept the migration on a stand with the real previous release — [settings and migration](references/settings-migration.md#migrating-a-v1-plugin).

## Before you call a carrier plugin done

One line per lesson that was found late on a real plugin; each links its detail.

- **Russian storefront text exists and is tested** — `languages/{domain}-ru_RU.po/.mo` plus a storefront-translation test (Yandex had no catalogue at all until the operator's visual pass; [core services](../woodev-plugin-core/services.md#internationalisation)).
- **Every string of a `Pickup_Point` is read by the shopper verbatim** — map carrier codes (`already_paid`, `card_on_receipt`) to translated customer labels, drop the codes with no meaning to a buyer ([pickup](references/model-rates-location-pickup.md#pickup-and-checkout)).
- **Every refusal path of `rate_package()` reports its reason** to the manager as well as to the log, from ONE helper ([hint](references/method-features.md#why-a-method-is-hidden-the-admin-only-hint)).
- **The plugin survives a second carrier and a removed Region field** — acceptance includes the two-carrier matrix and a shop with the region field removed ([second carrier](references/traps.md#a-second-carrier-plugin-on-the-same-site), [location](references/model-rates-location-pickup.md#location)).
- **The licence gate does not break your suite**: a signed in-force claim in integration fixtures, every WordPress time constant in the unit bootstrap ([core testing](../woodev-plugin-core/layout-and-testing.md)).
- **An own DSN for error reports** and the entry-file test stub that goes with it ([core services](../woodev-plugin-core/services.md#error-reporting)).
- **Acceptance judges on carrier data, in a real browser path** — block checkout through a real order POST, the classic city picked from the suggestions, never a typed name ([acceptance](references/acceptance-rig.md)).

## Use fixtures as worked examples

- Use the [fixture map](references/model-rates-location-pickup.md#fixture-map) to jump from each implementation seam to its working example. For the Yandex pilot pickup-source shape, see [`tests/_fixtures/woodev-yandex-pilot-plugin/`](../../../tests/_fixtures/woodev-yandex-pilot-plugin/).
- Use [shipping traps](references/traps.md) while designing and debugging. The linked gotchas explain real framework and WooCommerce failures that matter to carrier authors.
- A green unit suite does not accept a carrier plugin: see [acceptance on the rig](references/acceptance-rig.md).

## Store and carrier packaging

The store keeps its common boxes in «Доставка» → «Упаковка» (`woodev_boxes_boxes`); never duplicate that list inside a carrier. A carrier declares its OWN preset boxes with `Shipping_Plugin::get_box_presets()` in **fixed cm and kg** (stable string `id`, `name`, positive `length`/`width`/`height`, optional `max_weight`/`box_weight`, `cost_mode` = `carrier` | `fixed` | `merchant`), and each `Shipping_Method` that packs declares `FEATURE_BOX_PACKING` before `parent::__construct()`. Modes are `virtual` («всё в одну коробку», a real 3-D placement), `separately` and `boxes`; the old `single` is retired and reads as `virtual`. Everything else — cost modes, settings keys, parcel contents, cache identity, migration of a v1 list — is in [packaging](references/packaging.md).

On the WooCommerce shipping-zone screen the framework re-fires `wc-enhanced-select-init` when a method's settings modal opens, so a `.wc-product-search` / `.wc-enhanced-select` field in your method's `form_fields` works with no JS of yours.
