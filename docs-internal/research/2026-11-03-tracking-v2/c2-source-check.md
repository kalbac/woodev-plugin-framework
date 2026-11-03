TRACKC2 — v2 tracking map checked against source

# Task C2 — v2 tracking map checked against PHP source

Date: 03.11.2026. Method: Serena `activate_project` by path on the framework and on each of the four carrier repos (each activation confirmed; `find_symbol`/`search_for_pattern` results reported paths under the activated repo). No network, no rig, no tests run. Marks: **READ** = source inspected; **MEASURED** = grep/search result; **UNVERIFIED** = reason given. Paths are relative to the repo named in the heading; "FW" = `woodev-plugin-framework/woodev/`. Serena is 0-based for lines; the `symbol` is the stable pointer.

Side effect to know about: activating Serena on `woodev-ozon-delivery` rewrote its tracked `.serena/project.yml` (+187 lines of template comments). I reverted it with `git checkout -- .serena/project.yml`; that repo is clean again. The other three carrier repos were untouched by activation. Russian Post has a pre-existing untracked `dist/` (not mine).

## 1. Per carrier, buyer side

| | CDEK (`woocommerce-edostavka`) | Yandex (`woocommerce-yandex-delivery`) | Russian Post (`woodev-russian-post`) | Ozon (`woodev-ozon-delivery`) |
|---|---|---|---|---|
| Tracking-number order meta | `_wc_edostavka_tracking_code` — **READ** `includes/class-plugin.php` provider `tracking_meta_key` (~l.431), `Cdek_Document_Source::CDEK_NUMBER_META`; written by `Cdek_Status_Synchronizer` (`update_meta_data`, "a cash-on-delivery order is numbered after the export settled") | `_yandex_delivery_request_id` (`Yandex_Shipment_Handler::REQUEST_ID_META`) — it is BOTH `carrier_order_id_meta_key` and `tracking_meta_key` — **READ** `includes/orders/class-yandex-order-actions.php` `register()`. Buyer link meta: `_yandex_delivery_sharing_url` (`Yandex_Tracking_Link::META_KEY`), written by the API client/poller (`write_if_empty`) | `_wc_russian_post_tracking_number` (`Plugin::ORDER_META_PREFIX . 'tracking_number'`, `Russian_Post_Order_Reader::META_TRACKING_NUMBER`) — **READ** | **No `tracking_meta_key` at all** — **READ** `includes/class-plugin.php` `register_orders_integration()`. Numbers are `posting_number` values inside the array meta `_woodev_ozon_postings` (`Ozon_Export_Request::POSTINGS_META`); carrier order id `ozon_order_id`; marker `_woodev_ozon_active_order` |
| Provider marker meta (what `Orders_Registry` matches on) | CDEK marker (provider third arg in `class-plugin.php` ~l.420) — not needed further | `_yandex_delivery_state_status` | n/a | `_woodev_ozon_active_order` |
| Tracking URL | template `https://www.cdek.ru/ru/tracking?order_id={tracking}` (provider) | `tracking_url_meta_key` = `_yandex_delivery_sharing_url` (per-order link) | `Russian_Post_Tracking_Status::TRACKING_URL_TEMPLATE = https://www.pochta.ru/tracking#{tracking}` | none declared |
| Own buyer email | **None** — **MEASURED** no `woocommerce_email*`, `WC_Email` or template in `includes/` | **None** — **MEASURED** same grep | **Yes**: `Russian_Post_Tracking_Email`, id `wc_russian_post_tracking_email`, registered through `woocommerce_email_classes` in `Russian_Post_Tracking_Email_Registrar::register()`; triggered by the plugin export hook `woodev_shipping_wc_russian_post_shipment_exported` (const `EXPORTED_HOOK`, built from `Plugin::PLUGIN_ID`); sent flag `_wc_russian_post_tracking_number_email_sent` (`META_SENT`). After sending it writes the framework flag `_woodev_delivery_email_customer_shipment_created` (`META_FRAMEWORK_SHIPMENT_CREATED_SENT`) so the framework mail is not sent a second time. Template `templates/emails/tracking-number.php` calls `woocommerce_email_order_details` and `woocommerce_email_order_meta` — **READ** | **None** — **MEASURED** no `woocommerce_email*` hook. Only checkout hint `woocommerce_review_order_after_shipping` (`Ozon_Buyer_Hints`, not tracking) |
| My Account order view / thank-you page | **None** — **MEASURED** no `woocommerce_view_order`, `woocommerce_thankyou`, `woocommerce_order_details_*` hook in any of the four repos | same | same | same |
| Fires when number is set or changed | **Nothing.** `wc_edostavka_update_tracking_code` and `wc_edostavka_delete_tracking_code` are declared removed hooks mapped to `null` (`includes/class-plugin.php` ~l.1275-1276): **READ**; no `do_action` of either: **MEASURED**. The number is stored by `Cdek_Status_Synchronizer` (sync/webhook) with no dedicated action. What does fire: `woodev_edostavka_export_confirmed` (`Cdek_Shipment_Handler::EXPORT_CONFIRMED_HOOK`, args order, carrier uuid — the uuid is NOT the tracking number), `woodev_edostavka_intake_confirmed`, plus the framework's `woodev_shipping_order_exported` and `woodev_shipping_delivery_status_changed` | **Nothing number-specific.** `woodev_yandex_delivery_status_sync_failed` only; framework hooks below apply | `wc_russian_post_order_tracking_number_changed( $barcode, $order_id )` — **READ** `includes/order/class-russian-post-order-export.php` ~l.481: fires only inside the export path and only when the stored barcode differs from the new one (`$barcode_changed`). The status poller (`class-russian-post-tracking-poller.php`) fires `wc_russian_post_order_status_changed`, `…_to_{new}`, `…_from_{old}_to{new}` — no number event | **Nothing number-specific** (`do_action` grep: `woodev_shipping_order_exported`, plugin `…_shipment_exported`, `woodev_shipping_ozon_delivery_shipment_cancelled`, `woodev_ozon_delivery_configuration_ready`) |

Framework hooks that fire for ANY of the four carriers (**READ**):

- `woodev_shipping_order_exported( $order, $carrier_order_id )` and the plugin-prefixed `woodev_shipping_{prefix}_shipment_exported( $order, $carrier_order_id )` — `FW/shipping-method/order/abstract-shipment-handler.php`; never fires without a carrier order id. For Ozon the same pair is fired from `Ozon_Shipment_Handler` (~l.459-460).
- `woodev_shipping_delivery_status_changed( $order, $previous, $current, Orders_Provider $provider )` — `FW/shipping-method/order/class-delivery-status-events.php` (~l.199); only when the canonical state actually changed and the write was not silent. This is the only fleet-wide "something happened to the shipment" event; it is NOT a tracking-number event.

Conclusion for report A's question: the v1 CDEK auto-fill is dead and has no replacement; the Russian Post one still works but only for numbers created by export. Yandex and Ozon never fed v1 at all.

## 2. Framework buyer surfaces

**Shared emails — READ** `FW/shipping-method/email/class-delivery-status-emails.php` (`Delivery_Status_Emails`, singleton, registers on `woocommerce_email_classes`; listens on `woodev_shipping_delivery_status_changed` at priority 10, 4 args) and `class-delivery-status-email.php` (`Delivery_Status_Email`):

| Email id | Canonical states that trigger it | Enabled by default |
|---|---|---|
| `customer_shipment_created` | `CREATED`, `IN_TRANSIT` | yes |
| `customer_shipment_pickup` | `READY_FOR_PICKUP` | yes |
| `customer_shipment_delivered` | `DELIVERED` | yes |
| `customer_shipment_exception` | `RETURNED`, `FAILED` | **no** |

- Sent marker: `'_woodev_delivery_email_' . sanitize_key( $this->id )` (`Delivery_Status_Email::trigger()`), one per EMAIL not per status; the value written is the canonical status that sent it, any non-empty value means "sent". The id is not prefixed, so the keys are exactly `_woodev_delivery_email_customer_shipment_created|pickup|delivered|exception`. The marker is written BEFORE `send()` (claim-then-send).
- Placeholders (**READ**, `get_placeholders()`): `{order_number}`, `{tracking_number}`, `{tracking_url}`, `{carrier_name}`, `{pickup_point}`, `{delivery_date}`. `{delivery_date}` is hard-coded `''` in the framework; only the filter `woodev_shipping_delivery_email_placeholders( $placeholders, $order, $provider )` can fill it. `{tracking_number}` = order meta at `Orders_Provider::get_tracking_meta_key()`, empty when the provider declares none (Ozon). `{tracking_url}` = `$provider->resolve_tracking_url( $order )`, else the URL template with `{tracking}` replaced. In the HTML body `{tracking_url}` becomes an `<a>`.
- Recipient is the billing email only; no send without a valid billing email.
- Template `FW/shipping-method/templates/emails/shipment-status.php` calls `woocommerce_email_header`, the body, **`woocommerce_email_order_details`** (core prints the order table, which is where core's `woocommerce_email_before_order_table` fires — core behaviour, **UNVERIFIED** here because WooCommerce core is not in these repos) and `woocommerce_email_footer`.

**Buyer-side renderer of tracking on My Account / order view / thank-you: none in the framework.** **MEASURED**: `search_for_pattern` over `woodev/` for `view_order|thankyou|order_details_after|my.?account` finds no tracking renderer (only payment-gateway "my payment methods" and licence links); over `woodev/shipping-method` the only `woocommerce_*` buyer hook is the checkout one (`woocommerce_review_order_after_shipping`).

**Delivery status log (#1564) — READ** `FW/shipping-method/order/class-delivery-status-log.php`, `final class Delivery_Status_Log`, `@since 2.0.24`:
- `public const META_PREFIX = '_woodev_delivery_status_log_'`; `public static meta_key( Orders_Provider ): string` = prefix + provider id.
- `public static read( \WC_Order, Orders_Provider ): array` returns `[ [ state, unix time, raw code ], … ]`, oldest first, `[]` when nothing recorded. It reads the order object's own meta, so freshness is the caller's.
- A non-carrier plugin CAN read it: the methods are public static, the class is in the class map (`FW/class-map.php`), and a provider is obtainable with `Orders_Registry::instance()->resolve_provider_for_order( $order )` or `->get_provider( $id )`. It must guard `class_exists( '\Woodev\Framework\Shipping\Order\Delivery_Status_Log' )` and the framework version (2.0.24+). Entries are forward-only and only exist for shipments the framework saw change; JSON encoding and the 20-entry cap are **UNVERIFIED** (not read beyond the docblock; `decode()`/`stage()` bodies not opened).

**Public API a separate plugin can call to ask "does this order already have carrier tracking, and what is it?" — READ:**
1. `\Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->resolve_provider_for_order( \WC_Order ): ?Orders_Provider` — public, first provider whose marker meta is non-empty wins (`class-orders-registry.php`, "the single source for which provider owns this order", @since 2.0.2).
2. `Orders_Provider::get_tracking_meta_key(): ?string`, `get_tracking_url_template()`, `resolve_tracking_url( \WC_Order ): ?string`, `get_label()`, `get_id()`.
3. `Orders_Registry::instance()->get_providers()` (filter `woodev_shipping_orders_providers`).
4. Number = `\Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_tracking_meta_key() )` — a scalar for CDEK, Yandex (request id), Russian Post; **not available** for Ozon (no key; array in `_woodev_ozon_postings`).
There is no single "get tracking for order" function; the pair (1)+(2) is the closest. All of this exists only when the shipping module of framework v2 is loaded.

## 3. Plugin base class

- A non-shipping, non-gateway WooCommerce plugin extends `\Woodev\Framework\Woocommerce_Plugin` — **READ** `FW/class-woocommerce-plugin.php`: `abstract class Woocommerce_Plugin extends \Woodev_Plugin`, constructor `( string $id, string $version, array $args = [] )` with `supported_features` (hpos/blocks). `Shipping_Plugin` and `Payment_Gateway_Plugin` extend it. `\Woodev_Plugin` is the WordPress-only base.
- Settings registration lives on `\Woodev_Plugin`, not on `Woocommerce_Plugin`: the plugin overrides `public function get_settings_providers(): array` (default `[]`, `FW/class-plugin.php` ~l.1696) and returns `\Woodev\Framework\Settings\Settings_Provider[]`. `Woodev_Plugin::init_settings_page()` (~l.364, called from the constructor path at ~l.212) calls `Settings_Page_Registry::instance()->register_plugin( $this )`, which later calls `$plugin->get_settings_providers()` (`class-settings-page-registry.php` ~l.411). So the plugin writes no registration call.
- Building a provider: `Settings_Provider::create( $id, $label, \Woodev_Abstract_Settings $handler, Settings_Section[] $sections, array $args )` (args: `capability`, `legacy_option_key`, `legacy_page`, `supports`) or the type-checked `create_with_sections()`. Sections: `Settings_Section::create( id, label, [field ids], description )`.
- Existing example: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` — `Woodev_Test_Plugin::get_settings_providers()` (~l.510) with `Woodev_Test_Settings extends \Woodev_Abstract_Settings` (~l.321). **Caveat:** that fixture extends `\Woodev_Plugin`, not `Woocommerce_Plugin`. The only `Woocommerce_Plugin` test double is `Testable_Woocommerce_Plugin` (`tests/unit/WoocommercePluginTest.php` ~l.181) and has no settings provider. Production examples that combine both: `Shipping_Plugin::get_settings_providers()` (`FW/shipping-method/class-shipping-plugin.php` ~l.1512). Settings page tests: `tests/unit/SettingsProviderTest.php`, `SettingsPageRegistryTest.php`.

## 4. Coexistence

Where the buyer sees the number twice TODAY, for an order shipped by one of our v2 carriers that also carries a tracking-plugin number:

1. **Every WooCommerce email that prints the order table.** v1 appends its table at `woocommerce_email_before_order_table` priority 0 for all emails except refunds (report A §1). The framework status emails (`shipment-status.php`) and the Russian Post email (`tracking-number.php`) both call `woocommerce_email_order_details`, so the tracking plugin's table lands inside them while the body already carries `{tracking_number}`/`{tracking_url}` (default body of `customer_shipment_created`: "…Tracking number: {tracking_number}. {tracking_url}"). Affected: framework mails for CDEK, Yandex, Russian Post; Russian Post's own mail; for Ozon the framework mail has an EMPTY `{tracking_number}` (no key), so the buyer sees only the tracking plugin's number there — no duplicate, but also a framework email with a blank number. (The firing of `before_order_table` from `order_details` is core behaviour: **UNVERIFIED** here.)
2. **Two separate emails.** If the tracking plugin's own email is sent (manual action, or automatically if v2 adds auto-send) while the carrier mail goes out, the buyer gets two mails with the number. Russian Post is the only carrier that suppresses its framework mail after its own; nothing suppresses a third-party mail.
3. **My Account / order view / thank-you: no duplicate today** — none of the four carriers nor the framework renders tracking there (**MEASURED**), so the tracking plugin's `woocommerce_view_order` table would be the only one.

Single check a tracking plugin needs (**inference from READ facts**): before rendering ANY surface (email table, own email, account table) for an order, ask the framework whether a carrier owns it —

```php
$provider = class_exists( '\Woodev\Framework\Shipping\Admin\Orders\Orders_Registry' )
	? \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->resolve_provider_for_order( $order )
	: null;
$carrier_owns_it = null !== $provider;   // marker meta set = our carrier exported / owns the shipment
```

and skip its own output when `$carrier_owns_it` (optionally behind a merchant-override filter, because a combined order can carry two parcels). Use the marker, not the tracking meta key: Ozon has no tracking key, and CDEK/Russian Post can get the number later than the marker. For the email-table case the tracking plugin should additionally skip emails whose `$email->id` starts with `customer_shipment_` or equals `wc_russian_post_tracking_email`.

## Corrections to c-framework-map.md

1. **Yandex tracking key (§2 table, "UNVERIFIED")** — it is `_yandex_delivery_request_id` (the same meta as the carrier order id), buyer link `_yandex_delivery_sharing_url`, marker `_yandex_delivery_state_status`. Source: `includes/orders/class-yandex-order-actions.php`.
2. **Ozon tracking key (§2, "UNVERIFIED")** — Ozon declares NO `tracking_meta_key`; numbers are `posting_number` rows in `_woodev_ozon_postings`. Consequence missing from the map: the framework status email's `{tracking_number}`/`{tracking_url}` are empty for Ozon, and any "read the carrier's number from `get_tracking_meta_key()`" logic returns nothing for it. The map's "framework order-meta model must be a list" is right, but it is Ozon's own meta, not a framework contract.
3. **"CDEK `woocommerce_edostavka_email_tracking_core_url` is a carrier-specific hook" (§5)** — wrong: it is a removed hook mapped to `null` in `includes/class-plugin.php` (~l.1277); CDEK v2 has no buyer email or email hook at all (**MEASURED**). Same for Russian Post's `wc_russian_post_tracking_url`: the map presents it as a live "tracking URL filter" (§2); it is removed (`null`, `woodev-russian-post/includes/class-plugin.php` ~l.1020).
4. **CDEK status source (§2)** — the map says CDEK "uses framework tracking/status synchronisation"; in source the number is written by the plugin's own `Cdek_Status_Synchronizer` (sync + webhook share it). No number-change action exists; the map's "tracking hooks" list is empty for CDEK, Yandex and Ozon, and the only number-change hook in the four plugins is Russian Post's `wc_russian_post_order_tracking_number_changed` (export path only).
5. **Framework emails (§1)** — upgrade "READ docs" to source: ids `customer_shipment_created|pickup|delivered|exception` (the map named only `customer_shipment_created` and "status-change IDs"); `customer_shipment_exception` is disabled by default; `{delivery_date}` is always empty unless the filter fills it; the sent marker is written BEFORE the send.
6. **Delivery status log (§1)** — the map says it is "not a log for arbitrary manual tracking numbers absent an `Orders_Provider`" (correct) but implies it is internal. It has a public static reader: `Delivery_Status_Log::read( $order, $provider )` and `meta_key( $provider )` (2.0.24), and the map's "JSON, capped at 20, UTC timestamp" is **UNVERIFIED** by me (docblock confirms oldest-first `[state, unix time, raw code]` only).
7. **Settings registration (§3, G5 "not established")** — established: override `Woodev_Plugin::get_settings_providers()` (`FW/class-plugin.php` ~l.1696); `init_settings_page()` registers the plugin automatically. The map's base class `\Woodev\Framework\Woocommerce_Plugin` is **confirmed** (`FW/class-woocommerce-plugin.php`). The only complete fixture with a provider extends `\Woodev_Plugin`, not `Woocommerce_Plugin` (§3 above).
8. **Buyer-side renderer (§1 and G3, "UNVERIFIED")** — confirmed absent: no framework or carrier code renders tracking on My Account, order view or thank-you (**MEASURED**). The only buyer tracking output is in emails.
9. **Coexistence recommendation (§5, "render only when no recognised carrier tracking number")** — the right test is the provider marker via `Orders_Registry::resolve_provider_for_order()`, not "carrier tracking number exists": the number may arrive after the marker (CDEK COD) or not exist as a key (Ozon). The map did not name the function; it exists and is public.
10. **Russian Post email (§2 "Buyer display")** — confirmed, with specifics: email id `wc_russian_post_tracking_email`, triggered by `woodev_shipping_wc_russian_post_shipment_exported`, own flag `_wc_russian_post_tracking_number_email_sent`, writes `_woodev_delivery_email_customer_shipment_created`.

## Not established

- WooCommerce core firing `woocommerce_email_before_order_table` from `woocommerce_email_order_details` (core not read) — the double-display in §4 item 1 depends on it.
- Status-log JSON format and the 20-entry cap; the body of `Delivery_Status_Log::decode()/stage()` was not opened.
- CDEK's marker meta key name (read as the third argument of the provider; irrelevant to the findings).
- Whether Ozon's `Ozon_Status_Presentation` or `Ozon_Order_Panel` show anything to the buyer: `Ozon_Status_Presentation` hooks are admin-only filters (`woodev_shipping_orders_row`, `…_preview`, `…_order_metabox_fields`); the order panel was not opened beyond the postings reads.
- Multi-parcel behaviour of the framework email for Ozon (1:N postings) — not traced.
