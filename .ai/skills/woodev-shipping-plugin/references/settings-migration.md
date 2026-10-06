# Settings and v1 migration

## Settings ownership map

The default settings page is `woodev-settings`. Add plugin-global settings through `Shipping_Plugin::get_tab_settings_providers()`, returning `Settings_Provider` descriptors whose handler is a `Woodev_Abstract_Settings` instance and whose sections use `Settings_Section::create()` or `create_connection()`. Per-method tariffs and options belong in `Shipping_Method::get_method_form_fields()` and are stored on that WooCommerce shipping-method instance. For shared settings patterns, see [woodev-plugin-core settings](../../woodev-plugin-core/settings.md), especially “Contribute a tab” and “Connection blocks”.

| Settings | Owner and location | Notes / example |
|---|---|---|
| Carrier credentials and authentication | Plugin-global settings handler section | Use `create_connection()` for a connection test; implement `\Woodev_Settings_Connection_Test::test_connection( string $connection_id, array $values )`. Mark secrets `sensitive => true`; use `constant_name` when an environment constant may supply the secret. See core [settings](../../woodev-plugin-core/settings.md) and `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (`Woodev_Test_Settings`). Do not copy `class-test-cdek-integration.php` here — it is the Integrations-tab exception demo. |
| Sender, origin, account, shared defaults | Plugin-global settings handler section | These values affect multiple tariffs; include them in the rate-cache context when rates depend on them. |
| Enabled methods and tariff-specific fields | WooCommerce shipping-method instance fields | Use `get_method_form_fields()`; use plugin-global settings only when the value truly is shared across instances. See `tests/_fixtures/woodev-realistic-shipping-plugin/includes/abstract-class-realistic-shipping-method.php`. |
| Default dimensions | Framework-owned `Вес и габариты` section | Store-wide fallbacks used only when product dimensions are missing: `Default_Dimensions_Settings`. |
| Packing and shipping classes | Per-method `Shipping_Method` feature declarations and fields | Use `FEATURE_BOX_PACKING` / `FEATURE_SHIPPING_CLASSES`; parcel packing comes from `woodev/box-packer/`. |
| Location source and default locality | Framework-owned `Локация` section | Added when the plugin opts in via `needs_location_provider()` and supplies its adapter. The provider registry owns provider selection and locality fields. |
| Pickup map and shared checkout behavior | Framework-owned `Карта` and `Форма заказа` sections | Map settings appear when the returned `Pickup_Handler` needs them. Do not add a second picker. |
| Order export toggle and statuses | Framework-owned `Выгрузка` section | Added when the plugin registers its `Orders_Provider` and shipment handler on this request. Merchant values are in `Export_Settings`; `Order_Automation` reads them. The plugin does not contribute export settings through `get_export_settings()`. |
| Status mapping and tracking details | Provider/handler configuration and order metadata | Define the carrier raw-status map and tracking metadata when building `Orders_Provider`; render events in `Abstract_Tracking_Handler`. |
| Tools | Framework-owned `Инструменты` section | Register actions through `Shipping_Tools_Registry`; this section exists only when a tool is registered. |

`Shipping_Integration` (`woodev/shipping-method/settings/class-shipping-integration.php`) is a valid compatibility seam for an existing WooCommerce Integrations surface, returned by `get_integration_handler()`. The default is `woodev-settings`; Rule 8 in `docs-internal/AGENT-RULES.md` leaves exceptions to the operator's judgment. Bring a concrete case to the operator instead of treating the Integrations tab as forbidden or deprecated. Preserve `woocommerce_{plugin_id}_settings` and other stored values during migration.

The shared shipping page (`Shipping_Settings_Tab::register()`) includes `Форма заказа`, `Вес и габариты`, optional `Локация` and `Карта`, registered `Инструменты`, and `Выгрузка` when export is configured. Do not add duplicate sections or a second tools page. Sources: `woodev/shipping-method/settings/class-shipping-settings-tab.php` and `woodev/shipping-method/class-shipping-plugin.php`.

## Migrating a v1 plugin

Inventory installed-site contracts in the old plugin: option names and values, shipping method IDs, order/session metadata keys, hook names/signatures, cron and REST routes, admin slugs, and existing orders' stored data. Internal APIs may change on v2; persisted or externally consumed contracts must remain byte-for-byte stable (ADR-005: `docs-internal/adr/005-platform-v2-clean-break-policy.md`).

Use a plugin-specific checklist from `docs-internal/migration/*`; explicitly map old keys to new framework fields. Migrate in-flight orders as well as settings, make migrations idempotent, and check for external readers before removing legacy keys. See `docs-internal/specs/2026-06-25-shipping-module-decisions.md` §19.

Run `npm run probe:signature` against the v1 source and follow `docs-internal/migration/signature-probe.md` to discover extension hooks and signatures that are easy to miss. Preserve real public contracts; do not mechanically carry over internal architecture.
