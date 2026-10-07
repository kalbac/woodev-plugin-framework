---
name: woodev-shipping-plugin
description: Load before writing or changing a shipping plugin that runs on Woodev Framework v2. Guides plugin authors through shipping methods, rates, checkout, pickup, orders, tracking, settings, and v1 migration.
---

# Woodev Shipping Plugin Development

Use this skill for a carrier plugin that consumes Woodev Framework v2. Also load [woodev-plugin-core](../woodev-plugin-core/SKILL.md) for shared plugin loading, lifecycle, settings, licensing, i18n, testing, and framework conventions; this skill adds shipping-specific seams.

## Start with the framework seams

- Use `Shipping_Plugin` and concrete subclasses of `Shipping_Method`. The courier, pickup, and postal bases are `Shipping_Method_Courier`, `Shipping_Method_Pickup`, and `Shipping_Method_Postal`. See [shipping model and rates](references/model-rates-location-pickup.md).
- `Shipping_Plugin::register_shipping_methods()` is `final`; provide method classes through `get_shipping_method_classes()`. A class that does not extend `Shipping_Method` is silently filtered out. `Shipping_Method::calculate_shipping()` is `final`; implement `rate_package()` instead.
- The framework supplies shared checkout and order plumbing, but the plugin must return its concrete checkout, pickup, and webhook handlers from `Shipping_Plugin` overrides. Follow the [override seam table](references/model-rates-location-pickup.md#plugin-override-seams).
- Declare HPOS compatibility in the plugin loader definition and support both classic and block checkout. Do not implement a carrier-owned city dropdown or a second pickup selector/map. See [checkout and pickup](references/model-rates-location-pickup.md).

## Build the merchant settings and operations

- Use the settings ownership map in [settings and migration](references/settings-migration.md): plugin-global sections are supplied by `get_tab_settings_providers()`, while tariffs and per-method fields belong to the WooCommerce shipping-method instance. The framework adds shared sections — `Выгрузка заказов` when orders are registered (auto-export, delivered status, «Обновить статусы сейчас», and your own fields through `get_export_section_setting_ids()`) and `Дополнительно` for every carrier (logging, hide on cart; log through `log()` for errors and `log_debug()` for debug lines) — keys and seams in [settings and migration](references/settings-migration.md#framework-sections-of-the-carrier-tab); use `woodev-settings` by default and take a concrete Integrations-tab exception to the operator.
- Use the shared locality fields and map, shipment/order handlers, canonical delivery statuses, tracking handler, and webhook base. See [model, rates, location, and pickup](references/model-rates-location-pickup.md) and [orders and tracking](references/orders-tracking.md).
- Buyer status emails and carrier documents (waybill/barcode download) are FRAMEWORK features: never write carrier-owned buyer emails or a download flow. Declare `status_meta_key`/`status_map` on the provider (the framework publishes status changes, and the emails, by itself) and implement a `Document_Source` — see [orders and tracking](references/orders-tracking.md).
- For a v1 rewrite, preserve installed-site contracts byte-for-byte and make an explicit migration checklist from `docs-internal/migration/*`; run `npm run probe:signature` and follow `docs-internal/migration/signature-probe.md`.

## Use fixtures as worked examples

- Use the [fixture map](references/model-rates-location-pickup.md#fixture-map) to jump from each implementation seam to its working example. For the Yandex pilot pickup-source shape, see [`tests/_fixtures/woodev-yandex-pilot-plugin/`](../../../tests/_fixtures/woodev-yandex-pilot-plugin/).
- Use [shipping traps](references/traps.md) while designing and debugging. The linked gotchas explain real framework and WooCommerce failures that matter to carrier authors.
