# Russian Post v2 Plugin Design

**Date:** 2026-10-21 (s191)  
**Target:** the next line (`2.0.0`) of the existing plugin `woodev-russian-post` (plugin id `wc_russian_post`, EDD download 8772, text domain `woocommerce-russian-post`), written from scratch on Framework v2. The third carrier plugin on the v2 framework, after CDEK (`2.3.0.4`) and Yandex (`2.0.5`).  
**Release rule:** speed. Decision 13: the EDD folder is `woodev-russian-post`, so CDEK v2 and Yandex v2 load first and Russian Post v1 is quarantined on merchants' sites; every day without a v2 leaves those merchants without Russian Post. Nothing here is allowed to delay the release for a nice-to-have.  
**Evidence:** code claims cite repository-relative file paths. Framework paths are relative to the framework repo root; `v1:` means `plugins-reference/woodev-russian-post` (1.2.6.0, read-only); `cdek:` means `/Users/maksimmartirosov/Projects/woocommerce-edostavka`, branch `v2`; `yandex:` means `/Users/maksimmartirosov/Projects/woocommerce-yandex-delivery`, branch `main` (both read-only). Measurements cite the fact base in `docs-internal/research/2026-10-21-russian-post-v2/` (letters A–L, V). Anything not read in code or measured is marked **UNVERIFIED**; anything the author inferred is marked **interpretation**.

## 0. How to read this spec

Section 1 lists the binding operator decisions (brainstorm of 21.10.2026); nothing below reopens them. Section 2 is the v1 inventory. Section 3 maps every capability onto a framework seam and names the gaps. Sections 4–12 are the design per area. Section 13 is the installed-site data contract with a fate for every v1 key, section 14 the migration, sections 15–16 coexistence and acceptance, section 17 what is out of release 1. Section 18 is the UNVERIFIED register, the interpretations and the questions only the operator can answer. Section 19 is the card list.

**Two source notes the reader must know.**

1. **Report L is committed and decision 7 is amended.** [l-new-api.md](../research/2026-10-21-russian-post-v2/l-new-api.md) ("Russian Post's new business API documentation", 21.10.2026) is in the fact base; its claims are labelled **L** below. It amended `decisions.md` item 7: tracking is REST by token, no SOAP, no tracking login. That is settled, not an open question (§10).
2. **Decision 6 and the only Ф7 measurement disagree, and the operator has settled how to proceed.** Decision 6 asks for the Ф7 label "printed from the admin (one order)" with batches deferred; the only Ф7 measurement (G) was on an order *already in a batch*. Operator answer of 21.10.2026 (D14): measure first (card R1) and design both paths (§8.6).

## Amendment — wave-0 measurements (21.10.2026, s191)

Cards R1, R2, R3, R4 and R7 ran on the test account the same day; the reports are in the research folder (`wave0-r*.md`). Where
they settle a branch or an UNVERIFIED item, they win over the text below.

| Card | Outcome | Effect on this spec |
|---|---|---|
| R1 | Ф7 for an order **outside a batch: NO** for ONLINE_PARCEL, the postamat COMBINED product, PARCEL_CLASS_1, ONLINE_COURIER, POSTAL_PARCEL (500 `Forbidden mail type` / 404); **EMS is the exception** (1-page label from `forms/backlog/{id}/forms`, body PDF although the content type says zip). Inside a batch `f7pdf` gives a 1-page PDF. ([wave0-r1.md](../research/2026-10-21-russian-post-v2/wave0-r1.md)) | §8.6 branch 3 is the design: the label is offered only when the order is already in a batch (EMS also before), no implicit single-order batch in release 1 |
| R2 | Order-create mass limits: ONLINE_PARCEL 20 000 g, PARCEL_CLASS_1 5 000 g, POSTAL_PARCEL 20 000 g, ONLINE_COURIER 31 500 g, EMS 31 500 g; the API applies volumetric weight itself (5 000 cm³/kg) when `dimension` is sent; only real 10-digit leaf ТН ВЭД codes pass; only KZ/AM/KG are declaration-free (both directions confirmed, 161 open countries swept). ([wave0-r2.md](../research/2026-10-21-russian-post-v2/wave0-r2.md)) | §4 and §9.4 limits use these numbers; §9.2 rule table confirmed |
| R3 | Token tracking answers in ~0.1 s; `Language` header / query change nothing; status groups seen: RECEPTION_EXPECTED, IN_WAY, READY_FOR_DELIVERY, GIVING; an unknown valid-format barcode returns 200 with `trackingItemOperationGroupStatuses: null`; no limit header or endpoint. ([wave0-r3.md](../research/2026-10-21-russian-post-v2/wave0-r3.md)) | §10: the mapper treats `null` statuses as "no data yet"; the daily cap default stays 100; the real quota stays UNVERIFIED |
| R4 | `find-from-rectangle` carries closed flags and hours in the list, but costs ~7× bytes per row (~22× per city viewport); the widget set is a subset of its open points. ([wave0-r4.md](../research/2026-10-21-russian-post-v2/wave0-r4.md)) | §5 unchanged: widget backend for release 1; `find-from-rectangle` is the documented candidate if a fallback is ever built |
| R7 | Domestic products reject a goods list (`UNEXPECTED_GOODS`); COD = `insr-value` + `payment`, no `payment-method` (CASH/PLASTIC_CARD → 400); `dimension` in whole cm; a prefixed `order-num` is found by exact match in «Новые» (`backlog/search`) and after batching (`shipment/search`); one wp.org collision (`russian_post_courier` in an abandoned Dokan add-on, 0 active installs). ([wave0-r7.md](../research/2026-10-21-russian-post-v2/wave0-r7.md)) | §8.2 body rules follow these; §8.5 duplicate guard can rely on exact `order-num` search (U25 closed); method ids kept |

## 1. Operator decisions (binding)

Source: `docs-internal/research/2026-10-21-russian-post-v2/decisions.md`, walked item by item with the operator on 21.10.2026. Where the agenda or a report disagrees, `decisions.md` wins. Decision numbers D1–D13 are the file's own; D14–D16 are the operator's later answers of the same day, recorded here.

| ID | Decision | Where it lands |
|---|---|---|
| D1 | The «Виджет» cabinet is dropped entirely. A migration screen carries over what the old options hold (sender index, default dimensions/weight) and explains the Otpravka keys. Otpravka needs a contract (legal entity / ИП / самозанятый); private persons cannot use it. Widget-only shops: number unknown. | §12, §14 |
| D2 | Points: **no full passport download**. Live through the non-public widget backend, server-side through the framework viewport strategy with a short (minutes) cache; never browser-direct. Closed check at selection via `GET /api/pvz/{id}`. `pochta.ru` find-from-rectangle to be measured later. No fallback source now; keep the source behind `Point_Source`. | §5 |
| D3 | Like CDEK: one shipping method per destination kind (courier, post office, a separate postamat method). Each instance has exactly ONE tariff chosen by the merchant; on checkout one method = one tariff. Partner ПВЗ and «Доставка плюс» (54020): not committed, decide by measurement. | §4 |
| D4 | Prices through Otpravka `POST /1.0/tariff` (merchant keys), cache by route/weight/tariff/declared value, own quota counter, fall back to the public calculator when Otpravka fails or the quota is near. The quota can be raised by the merchant's personal manager (say so in docs). `GET /1.0/settings` (cache) shows which tariffs the merchant may use; `GET /1.0/user-shipping-points` feeds the sender picker. Contract prices stay unmeasured. | §4, §6 |
| D5 | Address at checkout: only the framework Location layer (DaData key once, shared). `clean/address` at order export; the manager sees an «address doubtful» flag. Trust only the quality/validation codes. | §7 |
| D6 | Export: order create + tracking number + Ф7 label from the admin (one order). Batches, Ф103/`checkin`, courier call: later, after a check on a real account. | §8 |
| D7 | Tracking: onto the framework polling mechanism, active orders in turn; with many active orders statuses update slower. **Amended 21.10.2026 by report L:** REST by token, not SOAP (§0 note 1). | §10 |
| D8 | Old-site data: NEW method ids, no permanent legacy code. A one-time background migration (batched, restartable, progress visible in the admin) rewrites shipping zones (CDEK pattern), rewrites old orders' shipping-line method ids and converts order meta to the v2 format (the two serialized-object metas to plain arrays). Losing a zone method is acceptable; losing order data is not. Removed a few versions later. | §13, §14 |
| D9 | Credentials: password-type fields; declare `Authorization` and `X-User-Authorization` secret so the framework masks them; never e-mail a credential link. **No encryption at rest.** | §11 |
| D10 | Block checkout: give what the framework gives, no completeness guarantee, release not held. | §16 |
| D11 | International is **in release 1** (Task K: order create works on the test account); country codes from the official ISO-numeric dictionary, not v1's table. The migration keeps old international orders' data. | §9 |
| D12 | Wizard: short — Otpravka keys with a connection check → sender point from `user-shipping-points` → default weight/dimensions → the tariffs this account can use, with the reason one is unavailable; plus the v1 migration screen. The six-step v1 wizard is not ported. | §12 |
| D13 | Coexistence: nothing more to do. Merchants are warned in the Telegram channel and the documentation. | §15 |
| D14 | Ф7 for an order outside a batch: **measure first (card R1) and design both paths** (operator, 21.10.2026). If Ф7 exists straight from «Новые», the label button is offered there; if not, only for an order already in a batch. No implicit single-order batch in either branch. | §8.6 |
| D15 | Old orders: **full migration, as D8 says** — shipping-line method ids and order meta are rewritten for every old order. **No read-only legacy path** (operator, 21.10.2026). | §14 |
| D16 | No fallback point source: **the risk of the unofficial widget backend is accepted** by the operator. The protections of §5.7 are the whole defence (operator, 21.10.2026). | §5.1, §5.7 |

**Operator rules that bind every section** (memory and project rules): the checkout is fool-proof and the admin trusts the merchant; merchant diagnostics appear only behind debug logging; no plugin may spam an external server — every automatic call needs backoff, a cap and a stated worst case; plugin settings live on `woodev-settings`, never on a WooCommerce Integrations tab; UI strings follow the render-path i18n rules of `AGENTS.md`.

## 2. v1 (1.2.6.0) feature inventory

Condensed from the fact base [a-v1-inventory.md](../research/2026-10-21-russian-post-v2/a-v1-inventory.md), which carries file:line for each row; confirmed defects are from [v-verify.md](../research/2026-10-21-russian-post-v2/v-verify.md).

| # | Surface | v1 behaviour | Fate in v2 |
|---:|---|---|---|
| 1 | Methods | `wc_russian_post_courier`, `wc_russian_post_postal`; one tariff per instance in practice (`allowed_shipment_type`, `any` collapses to one rate id). | Replaced by new ids, §4. |
| 2 | Rates | Widget backend `POST /api/pvz/courier_tariff_public` / `pick_up_tariff_public`, keyed by a cabinet `guid`; two or three remote calls per method per cart refresh; 24 h cache. | Dropped. Otpravka `POST /1.0/tariff`, §6. |
| 3 | Points | Russian Post's own widget in a modal (`ecomStartWidget`); point id/type taken from `$_POST`. | Framework map and `Point_Source`, §5. |
| 4 | Address | DaData in the browser with the token printed into page JS; `clean/address` at five call sites, one crashes on API failure. | Framework Location layer; one export-time cleaner, §7. |
| 5 | Export | Two modes (`otpravka`: `PUT /2.0/user/backlog`; `widget`: `cms.pochta.ru/api/cms/orders_public`). PUT/DELETE cached 24 h (confirmed). No label/document/batch code. | One mode, no cache on writes, §8. |
| 6 | International | ≈250 country codes (8 duplicated), customs declaration sent for every foreign country, description truncated to 57 chars, origin fixed to 643. | Rebuilt, §9. |
| 7 | Tracking | SOAP `getOperationHistory`, `WSDL_CACHE_NONE`, 12 h Action Scheduler, ≤50 orders per run. | REST by token, §10. |
| 8 | Webhooks | `?wc-api=russian_post_barcode` / `russian_post_status` called by the CMS cabinet. | Dropped with the cabinet (D1), §13. |
| 9 | Settings | `WC_Integration` tab; password, token, tracking password in plain `text` inputs; `X-User-Authorization` logged in clear under debug. | `woodev-settings`, masked, §11. |
| 10 | Registration | `cms.pochta.ru/api/cms/install` on activation; e-mail with a credential link to the admin. | Dropped (D1, D9). |
| 11 | Orders page | `WP_List_Table` under WooCommerce, slug `wc_russian_post_orders`; reflected XSS. | Framework orders page, §13. |
| 12 | CDEK bridge | Dead on CDEK v2 (constants and every `wc_edostavka_*` symbol it needs are gone). | Not ported; no CDEK-specific code, §15. |
| 13 | Wizard | Six steps mirroring the settings screen. | Not ported (D12). |

## 3. Capability → framework seam map

Gap = the framework has no mechanism and the plugin cannot reasonably build it alone. Items marked "No" are plugin work on an existing seam. "Done" marks a Yandex-spec gap that has since shipped in the framework (verified by grep today).

| Capability | Framework seam (file) | Plugin work | GAP? |
|---|---|---|---|
| Register methods | `Shipping_Plugin::register_shipping_methods()` final (`woodev/shipping-method/class-shipping-plugin.php`); `Shipping_Method::get_method_id()` is a static abstract (`class-shipping-method.php:222`), so the id is the plugin's choice; bases `Shipping_Method_Courier`, `Shipping_Method_Pickup` (abstract `get_point_source()`, `class-shipping-method-pickup.php:51`), `Shipping_Method_Postal`. | Four method classes, §4. | No |
| Quote | `rate_package()` abstract (`class-shipping-method.php:1249`); API failure hides the method and fires `woodev_shipping_method_rate_calculation_failed`. | Tariff call, §6. | No |
| Rate cache | `FEATURE_RATE_CACHE` opt-in; default context from the framework. | Add keys, §6.3. | No |
| Price depends on payment | `Shipping_Method::declares_payment_dependent_price()` (`class-shipping-method.php:2947`) read by `Fee_Payments::declare_payment_dependent_price()` (`class-fee-payments.php:133`). | Return `true` (COD switches category to declared value, §6.4). | Done |
| Declared value / COD / insurance | `FEATURE_DECLARED_VALUE`, `FEATURE_COD`, `FEATURE_INSURANCE` (`class-shipping-method.php:56-68`); `Shipping_Helper::get_package_declared_value()`. | Map to `mail-category`. | No |
| Packing | `FEATURE_BOX_PACKING`; `Woodev_Packer_Result`. | One parcel: mass and, for postamats, the cell class. | No |
| Location adapter | `Location_Adapter::resolve( Location_Record )` (`location/interface-location-adapter.php:90`); `Shipping_Plugin::get_location_adapter()` (`class-shipping-plugin.php:2822`); the first resolution per session always reaches the adapter, so it needs its own transient. | `Russian_Post_Location_Adapter`: representative destination index, §6.2. | No |
| Point source | `Point_Source::STRATEGY_VIEWPORT`, `fetch_points( Point_Query )`, `fetch_details( string )` (`pickup/interface-point-source.php`); `Point_Query` caps a bbox at 10°×10° (`class-point-query.php:68`) and carries the method context (`with_method_context()`, `get_types()`). | `Russian_Post_Widget_Point_Source`, §5. | No |
| Point model | `Pickup_Point::from_array()` — id, name, address, lat, lng, `type {code,label}`, schedule, `time_zone`, `accepts_cod`, `max_weight`, `cells`, icons. No `closed` key. | Mapper, §5.4. | No |
| Point constraints | `Constraint_Checker::check()` (`pickup/class-constraint-checker.php:128`): weight → cells → COD, permissive on unknown data; filter `woodev_shipping_pickup_point_selectable`. | COD per type, §5.6. The closed verdict does **not** travel through the filter (the mapper drops the flags and the filter never sees the raw record); it is `null` from `fetch_details()`, §5.5. | No |
| Selection re-check | `Pickup_Handler` re-fetches the chosen point through `fetch_details()` at checkout processing with the «reference» timeout/budget, and an outage allows checkout by default (`pickup/class-pickup-handler.php:2422`, `fetch_point()`, filter `woodev_shipping_pickup_recheck_outage_allows_checkout`). | `fetch_details()` returns `null` for a closed or temporarily closed point (a `null` is an authoritative negative; a throw would invoke the outage-allows policy), with a fresh, budgeted check at confirmation, §5.5. | No |
| **Viewport "too many points" signal** | `Pickup_Controller` has per-IP limits 240/60/15 (`rest-api/class-pickup-controller.php:104-139`) but no truncation field in the points response. | A wide viewport needs more upstream tiles than the budget allows (§5.3). | **Yes — G1** |
| Map | `Map_Provider_Registry`, `Yandex_Map_Provider`, embedded provider; per-point icons. | Use as is. | No |
| Checkout fields | `Checkout_Handler`, `Pickup_Handler`, `Pickup_Field`; wiring as in `yandex:includes/class-plugin.php:320-395`. | Wiring. | No |
| COD gateway coordination | `woodev_shipping_available_payment_gateways` (checkout handler). | Hide COD for postamat/partner and for products with no COD category. | No |
| Hidden-method reasons | `Shipping_Method::report_hidden( $reason_code, $message, $context )` (`class-shipping-method.php:1032`), shown to `manage_woocommerce` only, with `assets/js/frontend/hidden-shipping-reasons.js`. | Use for every "method is not offered" case, §4.5. | Done |
| API base | `Woodev_API_Base`; `get_secret_header_names()` (`woodev/api/class-api-base.php:2654`) masks `Authorization` but **not** `X-User-Authorization`; `get_secret_param_names()` (:1501); `Woodev_API_Rate_Limit_Exception` with `Retry-After`; `Woodev_API_Request_Purpose::{RATES,REFERENCE,EXPORT}`. | Override the two name lists, §11. | No |
| Cacheable API | `Woodev_Cacheable_Request_Trait` (default TTL **86 400 s**, `traits/cacheable-request-trait.php:10`); `abstract-cacheable-api-base.php` never restricts the HTTP method and `should_cache()` is not tested on save. | **Never use the trait on write requests** (the v1 bug); each read declares its own TTL, §11.2. | No |
| Export | `Abstract_Shipment_Handler::export()` calls one `api->create_order()`, idempotent under `Order_Lock`; `is_transport_failure()` (:506), `supports_reconcile()` / `find_exported_order()` (:537/:561), `EXPORT_UNKNOWN_META`, `supports_update()` / `update()` (:899/:919 — the read-only «refresh carrier state» seam, §8.3), `cancel()` (:790), abstract `extract_carrier_order_id()` (:1071). | Order body, §8. | No |
| Duplicate guard | `Shipment_Fingerprint` (`order/class-shipment-fingerprint.php`: `META`, `for_order()`, `is_outdated()`). | No automatic reconcile in release 1 (`supports_reconcile()` stays `false`), §8.5. | No |
| Auto-export | `Order_Automation::SETTING_AUTO_EXPORT`, default OFF. | Map v1 `auto_export_orders` / `export_statuses`. | No |
| Documents | `Document_Source::get_document_types( WC_Order )` / `get_document( WC_Order, string )` (`order/interface-document-source.php`), `Document_Result` (pending instead of waiting); model `yandex:includes/documents/class-yandex-document-source.php`. | Ф7 and the international forms, §8.6, §9.5. | No |
| Tracking | `Abstract_Tracking_Handler::map_events()` (`order/abstract-tracking-handler.php:202`), `Delivery_Status` (nine states, `order/class-delivery-status.php:40-75`), `Shipment_Facts`, `Delivery_Sync_Status` (records freshness only, schedules nothing). | Poller on Action Scheduler (model `yandex:includes/tracking/class-yandex-status-poller.php`), §10. | No |
| Tracking link | `Orders_Provider::resolve_tracking_url()` / `get_tracking_url_template()` (`admin/orders/class-orders-provider.php:483-495`). | A fixed template, §10.4. | Done |
| Orders page | `Orders_Provider::create()`; marker queried with `EXISTS`. | Provider, §13. | No |
| Settings | `Woodev_Abstract_Settings`, `Woodev_Setting` (`sensitive`), `Woodev_Control::TYPE_PASSWORD`, `Woodev_Settings_Connection_Test`; model `yandex:includes/settings/class-yandex-settings-handler.php`. | Settings handler, §11, §12. | No |
| Wizard | `Setup\Setup_Wizard`, `Woocommerce_Setup_Wizard`, `Step`, `Step_Action`, `Action_Outcome` (`woodev/setup/`); contract in `2026-10-08-setup-wizard-v2-contract.md`; model `yandex:includes/setup/class-yandex-migration-wizard.php`. | Wizard + migration screen, §12. | No |
| Zone-method rewrite and migration scaffolding | Not in the framework: each plugin ships its own `*_Zone_Method_Store`, `*_Option_Reader`, journal and lock (`cdek:includes/lifecycle/`, `yandex:includes/lifecycle/`). | Third copy. | **Yes — G2** (lift, optional) |
| Old-order rewrite | **No precedent.** CDEK's migration only *detects* v1 shipping items (`cdek:includes/lifecycle/class-cdek-lifecycle.php:218-240`) and writes no order meta; Yandex keeps every `_yandex_delivery_*` key byte for byte. | New code, §14.3. | No (plugin) |
| Contract tests | No Russian Post contract test exists in `tests/unit/Contract` today. | Add the data-contract tests of §13 in the plugin repo. | No |

### Gaps (candidate cards)

- **G1** viewport source reports "this viewport needs more than the budget; zoom in" (a `truncated` flag in the points response and a map hint), §5.3. Optional for release 1: without it the source returns a nearest-first partial list and logs.
- **G2** lift the zone-method store, option reader, journal and lock into the framework (third consumer). Optional; the plugin copies them to ship (decision 13: speed).

Not gaps: bulk point storage (not used here), polling (carrier responsibility), the document download route (exists), the admin-only checkout hint (done).

## 4. Methods and tariffs (D3, D4)

### 4.1 Method ids and classes

The plugin keeps plugin id `wc_russian_post` (it derives the licence options, the updater and the main settings option; changing it would need the licence-carry migration CDEK/Yandex had). Method ids are chosen by `get_method_id()`, and **must differ from v1's** `wc_russian_post_courier` / `wc_russian_post_postal` (D8).

| Method | Id (interpretation) | Base class | Delivery kind | Products offered (release 1) |
|---|---|---|---|---|
| Courier | `russian_post_courier` | `Shipping_Method_Courier` | to the door | `ONLINE_COURIER` (24030, only where the destination has a courier unit), `EMS` (7030) |
| Post office | `russian_post_pickup` | `Shipping_Method_Pickup` | to an ordinary post office (ОПС) chosen on the map | `ONLINE_PARCEL` (23030 family), `PARCEL_CLASS_1` (47030), `POSTAL_PARCEL` (27030) |
| Postamat | `russian_post_postamat` | `Shipping_Method_Pickup` | to a postamat chosen on the map | `ONLINE_PARCEL` with `COMBINED_*` categories (23080 / 23090), postamat-only |
| International | `russian_post_international` | `Shipping_Method_Postal` (the base meaning "needs a customer postcode"; fit **UNVERIFIED**, check the base contract in card P5) | abroad | `POSTAL_PARCEL`, `EMS`, `SMALL_PACKET` (§9) |

The ids are an **interpretation**: they follow CDEK's `edostavka_courier|pickup|postamat` shape and avoid the `wc_` prefix v1 used. A collision with a third-party plugin that already uses `russian_post_*` is **UNVERIFIED** (card R7 greps the wp.org plugin source). The ids are one constant each, cheap to change before release.

Which products belong to which method is **measured** (G §4.2, §8.1, V): ONLINE_PARCEL is refused for a postamat or partner point; `COMBINED_*` works for a postamat only and errors for ППВЗ and for ordinary OPS; `ECOM_MARKETPLACE` / `DELIVERY_PLUS` (54020) prices all three but order create is switched off on the test account. The mapping of POSTAL_PARCEL, PARCEL_CLASS_1 and ONLINE_PARCEL to "post office" is an **interpretation** from the facts that these ship to any destination index, the recipient collecting at the office; order create for them was measured to an address (G §8.1), not with a point flag, because an ordinary-OPS order carries only the index.

### 4.2 One tariff per instance

Each instance has one setting «Тариф» (a select of the products the account can use, §4.3). On checkout the instance is one rate; the merchant offers a choice by adding several instances of the same method to a zone — as in CDEK (D3). The tariff catalogue is one class, `Russian_Post_Tariff_Catalog`, keyed by a stable plugin-side `product_key` (for example `online_parcel`, `parcel_class_1`, `postal_parcel`, `online_courier`, `ems`, `combined_parcel`):

| Field | Meaning |
|---|---|
| `mail-type`, `mail-category` variants | Ordinary / declared value / COD (`WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY`) / combined, as measured |
| delivery kind | courier / office / postamat / international |
| COD capable | **read from the account**, not hard-coded: the category must appear in `user-available-products` for the intake office (§4.3) |
| mass limit | `ONLINE_PARCEL` 20 000 g is measured (`ILLEGAL_MASS_EXCESS`); the others are **UNVERIFIED** (card R2) |
| public object code | 23030, 47030, 7030, 27030 (priced only by Otpravka), 24030, 23080, 23090; used by the public fallback |

The catalogue **does not** list `ECOM` 53030/53070 (tariffing retired 01.01.2025: HTTP 200 + error 1204 on Otpravka, HTTP 400 on the public calculator) and does not rely on the public documentation's Appendix 1, which still lists them (L: "documentation lags the service").

### 4.3 What the merchant may choose: account data plus a live check

Two inputs, both required (decision 4 and V contradiction 1):

1. **`GET /1.0/settings`**: per intake office `available-shipping-points[].user-available-products` and `available-mail-types` (G §1: 109012 Moscow has 58 product types, Novorossiysk 1), plus account flags `deliveryPlusAllowed`, `deliveryToMarketplaceAllowed`, `deliveryToDoorAllowed`, `postpaymentAllowed`, `partialRedemptionAllowed`, `espp-code` presence (COD is blocked without an ЕСПП code, B), `api_enabled`, `blocked`, `debt-sum`. The answer is 238 KB on the test account. **The plugin stores a compact extract (flags, per-office product triples, ИНН, return address) of a few KB, never the raw body** (a 238 KB blob in a transient or option is the failure the framework's own gotcha `per-viewport-cache-is-unbounded-by-construction` and Yandex's forced-off cache, "MySQL server has gone away", already record). Refresh: at most every 6 hours, on key change, and by an admin button.
2. **A live "price AND no business error" check** per candidate product, run once when the merchant opens the instance's tariff select and cached with the extract. The probe fixture is **per kind**, because one universal city probe would reject a product that is valid on the account: the postamat-only `COMBINED` products price only to a **postamat** destination (23080 to an ordinary city-office index returns business error 2006, G §4.2, L §2), and an international product needs `mail-direct` and a transport type. The fixtures: *courier* and *office* products — one `POST /1.0/tariff` from the sender point to a fixed office index of a large city, 1 kg; *postamat* products — to a known postamat with the point fields the request requires (`delivery-point-index`, `dimension-type`; a constant taken from F's measured postamat sample); *international* products — to an open country of the dictionary (§9.1) with the `mail-direct` and `transport-type` the dictionary allows. A kind is probed only when the merchant opens a select of that kind. A probe has **three** outcomes, not two: *usable* (a positive `total-rate` **and** an empty `errors[]`); *not available to this account* (a business error naming the product or the account flag); and *probe route unavailable* (the fixture destination itself is refused — a postamat that vanished, a closed country, a destination-side 2006). The third is shown as «не удалось проверить — повторите позже» and never disables the product on its own. HTTP 200 proves nothing (53030/53070 return 200 with business error 1204; `ECOM_MARKETPLACE`, `DELIVERY_PLUS` and `BUSINESS_COURIER` price fine but order create returns `UNAVAILABLE_MAIL_TYPE` when the account flag is off, G §8.1). Probe cost: at most one request per candidate product (≤ 8) per refresh, so ≤ 8 × 4 = 32 per day.

The select shows disabled products with the reason in plain text (decision 12): «Доставка плюс: не включена в вашем договоре — обратитесь к личному менеджеру», «Курьер онлайн: в вашем пункте приёма такой услуги нет». A product that prices but whose account flag is off is shown disabled with that reason, not offered.

### 4.4 Partner ПВЗ and «Доставка плюс» (54020): not in release 1

Decision 3 leaves them to measurement. Facts: 54020 is «Доставка плюс с объявленной ценностью» (L: the live name; news 24.08.2026: from 16.09.2026 the service «Доставка Плюс», new `mail-type: DELIVERY_PLUS`, «ЕКОМ Маркетплейс» order types temporarily disabled); it prices a postamat, a partner ППВЗ and an ordinary office at the door price of the route (V); order create is rejected on the test account (flag off). **Release 1 builds nothing for them**: no catalogue entry, no partner type code, no `ecom-data` payload branch. The real `Point_Source` interface and the catalogue boundary are the extension points, and the pickup methods never ask the widget backend for `additional_pvz` (an explicit `pvzType` per method, §5.2). *Deferred-design note:* a later release adds a `delivery_plus` catalogue entry, requests `additional_pvz` through the `pvzType` filter, maps those points to a type code `partner` with `accepts_cod = false` (v1 refuses COD for `ECOM_MARKETPLACE`; V/F R7) and adds the `ecom-data` branch to the order builder. Unblocking needs an account with `deliveryPlusAllowed` (card R6, waits on the operator/Russian Post).

### 4.5 A method never disappears silently

Every "no rate" case calls `report_hidden( $reason_code, $message )` (admin-only on the checkout; the buyer sees nothing) and writes one line to the WooCommerce log, *not* gated by debug (the Yandex D8 rule; merchant-visible diagnostics stay behind debug, the always-on line is for the operator). Closed list of reason codes: `no_credentials`, `credentials_rejected` (401), `account_blocked`, `product_not_enabled`, `no_destination_index`, `courier_unavailable_here`, `weight_over_limit`, `quota_reserved`, `api_error`, `country_closed`, `points_unavailable`. A transport error or 5xx hides the rate for that request only and is never cached as a negative answer.

## 5. Points (D2)

### 5.1 Source and why it is behind `Point_Source`

`Russian_Post_Widget_Point_Source implements Point_Source`, strategy `STRATEGY_VIEWPORT`. Upstream is the **non-public widget backend** `widget.pochta.ru` (F): `POST /api/pvz` with a bounding box and `GET /api/pvz/{id}`. It is anonymous (no `settings_id`, no account), server-side only (never browser-direct, although CORS would allow it, F §6), through a plugin-side client with a hard 8 s timeout (F: 0.35 % of requests hung 30–60 s) and a single retry. Risks the operator accepted (D2) and this spec records: unofficial, no SLA, no documented rate limit above 2 req/s, third-party use of the data not licensed (F R1, R3, R4; UNVERIFIED legal status). Hence the interface: when the source dies or is closed, a replacement implements the same interface (candidate replacement: the official `nearby`, or `pochta.ru` find-from-rectangle, card R4). **No fallback source is built now** (D2; the residual risk is accepted by the operator, D16); when the backend is unreachable the point list throws `Woodev_API_Exception` (never an empty list — the interface contract), the REST layer shows a retry, and `report_hidden( 'points_unavailable' )` fires for the admin.

### 5.2 Request shape (measured, F)

`POST /api/pvz` body: `{ pageSize ≤ 200, page, currentTopRightPoint: [lng, lat], currentBottomLeftPoint: [lng, lat], pvzType: [...] }`.

- **Coordinates are `[lng, lat]`**; `[lat, lng]` returns HTTP 200 with zero points (F TRAP 1, reconfirmed). A unit test pins the order.
- `pvzType` values `russian_post` (ГОПС/СОПС), `postamat`, `additional_pvz`. Omitting it returns **all three**. Release 1 always sends it explicitly: the post-office method sends `["russian_post"]`, the postamat method `["postamat"]`, so the 812 partner points are never fetched. The method comes from `Point_Query::get_method_id()`; the framework type filter `Point_Query::get_types()` is honoured as well (the viewport contract, `interface-point-source.php`).
- `pageSize` is capped at 200 server-side; `page: 0` behaves as page 1; a non-numeric `settings_id` is rejected with 422 — **the plugin sends no `settings_id` at all**.

### 5.3 Tiling, not paging (F §3)

Offset paging over a large box is **lossy**: one pass over the country at `pageSize 200` returned 36 143 rows but only 32 527 distinct ids (≈ 10 % never seen); two walks of a 1 791-point box disagreed. Only a tile with `totalEntries ≤ 200` is complete in one page. The source therefore:

1. Requests the viewport with `pageSize 200`; if `totalEntries ≤ 200`, takes the rows (one upstream call).
2. Otherwise splits the box into four quadrants and recurses, until every leaf has ≤ 200 rows (F's quad-tree: 630 calls and 443 leaves cover the whole country, two passes 30 minutes apart identical).
3. De-duplicates by `id` across tiles (a point on a tile boundary).
4. Applies a **per-fetch budget of 6 upstream calls** (interpretation). A framework viewport can be up to 10°×10° (`class-point-query.php:68`), far more than a city view; a Moscow-region box alone holds 3 118 points. When the budget is exhausted the source returns what it has fetched, **nearest-to-centre tiles first**, and logs `viewport_truncated`. This is the one place the source is knowingly partial; gap **G1** (a truncation flag in the framework response) turns it into an honest "zoom in" hint. The checkout viewports a shopper actually uses are city-sized (typically one to four leaves).

Coincident coordinates exist (1 362 shared pairs, up to 22 points on one pair, F R9): the map layer must cluster/spiderfy; this is framework behaviour and is checked at acceptance, not built here.

### 5.4 Caching: bound the key space, then the lifetime

Decision 2 says "a short (minutes) cache". The framework's own gotcha [a per-viewport cache is unbounded by construction](../gotchas/per-viewport-cache-is-unbounded-by-construction.md) (s58, measured on this exact source: 823 KB across 14 `wp_options` rows in 30 minutes from one browser; the operator's words «ни один клиентский сайт не выдержит такого количества данных в транзиентах») forbids caching per arbitrary bbox. **Interpretation that satisfies both:**

- **Cache per quad-tree cell, not per viewport.** The grid is fixed (the world box split by the same deterministic rule), so the key space is finite: at most the ~630 nodes F measured for the whole country, and in practice the handful of cells over the shop's own delivery area. The key is `woodev_rp_tile_<types>_<level>_<ix>_<iy>`, where `<types>` is the normalized (sorted, lower-cased) upstream `pvzType` set of the request: `office` = `[russian_post]`, `postamat` = `[postamat]` (§5.2). The same set is part of the single-flight lock name, because an office query and a postamat query of one cell return different rows and must never share an entry. A viewport is answered by assembling cells of one type set.
- **Slim records only** (id, type, lng, lat, index, address line, name; ≈ 200 B each, ≤ 40 KB per leaf of 200 rows). Details (schedule, flags) are *not* in the tile.
- **TTL 10 minutes** for tiles (minutes, as decided), single-flight lock per tile so a burst of shoppers causes one upstream call.
- **A global cap of 150 stored tiles, counted across all type sets** (≈ 6 MB worst case) tracked in one index option; the least-recently-used tile is evicted past the cap. With an object cache the same keys go to the object cache and the cap is moot.
- **Per-point details cache**, the bounded thing in the gotcha: the **raw, instance-independent** `GET /api/pvz/{id}` record keyed by id (schedule, `closed` / `temporaryClosed`, `boxSize`; no COD verdict, which depends on the instance's product and is applied by the mapper *after* the cache read, §5.6), ≈ 2 KB, TTL **1 hour** for display data (confirmation never trusts it, §5.5) (the data is rewritten nightly, F §6), negative answers (HTTP 200 body `null` for an unknown id, F §4) are **not** cached.

### 5.5 Details, closed points and the selection check

The list rows carry no schedule and no closed flag; `closed` and `temporaryClosed` exist **only** in `GET /api/pvz/{id}` (F R6). The verdict cannot travel through the `woodev_shipping_pickup_point_selectable` filter: `Pickup_Point::from_array()` is a whitelist that drops both flags (`pickup/class-pickup-point.php:226-367`), and `Constraint_Checker::check()` hands the filter only the normalized point, the payment method, the weight and the method context (`pickup/class-constraint-checker.php:203-209`) — there is nothing to read. It travels through the null contract the framework already has instead:

- `fetch_details( $id )` calls `GET /api/pvz/{id}`, maps the record (schedule from `workTime`, structured `holidays` for offices, `boxSize` for postamats) and **returns `null` for a `null` body and for a point whose `closed` or `temporaryClosed` is true**. `Pickup_Handler` treats `null` from `fetch_details()` as an authoritative negative (class docblock, `pickup/class-pickup-handler.php:51-53`; `store_api_point_errors()` → `point_unavailable_message()`), so the checkout re-check refuses the point with the framework's own «point unavailable» text, and the map's details card for such a point shows the same state. It **never throws** for a closed point: a throw goes to `woodev_shipping_pickup_recheck_outage_allows_checkout`, whose default allows checkout.
- The details cache stores the **raw** record including both flags (§5.4); the closed verdict is derived from it at read time, never stored as a verdict.
- **Fresh closed check at confirmation.** Cached flags are good for drawing a card for up to an hour, never for confirming a choice. The checkout re-check runs `fetch_details()` under `Woodev_API_Request_Purpose::run_reference( true, … )`, i.e. purpose `rates` (`woodev/api/class-api-request-purpose.php:115-136`); when `Woodev_API_Request_Purpose::current()` is `rates`, the source **bypasses the cached record** and issues a fresh `GET /api/pvz/{id}` (at most one per checkout confirmation, counted against the reserved confirmation slice of the widget budget, §5.7), then refreshes the cache. If that fresh call fails (transport, 5xx, budget or breaker open), the source uses the cached record only when it is no older than 10 minutes, otherwise it throws `Woodev_API_Exception` and the framework's outage policy applies (allow by default, as accepted).
- List rows stay permissive by design (`Constraint_Checker` treats unknown data as selectable), so a closed point may be *drawn* but cannot be *confirmed*. Measured incidence of closed points in the widget feed is unknown (all 30 sampled were open; all 6 widget-only points checked via Otpravka were temporarily closed, G §3.3) — UNVERIFIED (U18).

### 5.6 Mapping and COD per point type

`Russian_Post_Pickup_Point_Mapper::map()` → `Pickup_Point::from_array()`:

| Widget type | `type.code` | `accepts_cod` | `max_weight` | `cells` |
|---|---|---|---|---|
| `russian_post` | `post_office` | `true` only if the instance's product has a COD category on the account (§4.3), else `false`; decided by the mapper per active instance **after** the product-independent details cache (§5.4) | per product (§4.2) | none |
| `postamat` | `postamat` | `false` (measured: COMBINED + COD → `ILLEGAL_MAIL_CATEGORY`, G §8.1; v1 forbids it too) | 20 000 g (dictionary `weight-max`, F §4) | from `boxSize` S/M/L/XL using v1's table (v1:`includes/functions.php:444`), which is in **millimetres** (S 260×170×80, M 300×200×150, L 400×270×180, XL 530×260×220) and is **converted to centimetres at the mapper boundary**, because `Pickup_Point::sanitize_cells()` and `Constraint_Checker` work in cm (`pickup/class-pickup-point.php:370-394`): S 26×17×8, M 30×20×15, L 40×27×18, XL 53×26×22 cm. The unit is known; the official status of the table stays **UNVERIFIED** (card R2) |

The fixture rule `accepts_cod = ('postamat' !== type)` (F §9.3 item 5) is wrong for partners and is not copied. The schedule mapper must not read `перерыв` as a second opening interval (F §9.3 item 6) and must treat `holidays` as an array of objects, not a string (item 1). The existing opt-in fixture `tests/_fixtures/woodev-test-shipping-method/class-test-live-pochta-point-source.php` is the starting point and its six known defects (F §9.3) are fixed in the plugin source, not in the fixture.

### 5.7 Worst case on the widget backend

The operator accepted the risk of an unofficial backend with no second source (D16); this section is the whole defence, so it counts **every** automatic path.

- **One budget for every widget HTTP attempt.** Tile fetches, the single retry of a tile, detail fetches (cache misses, unknown ids, confirmation re-checks) and the representative-index lookups of §6.2 all pass one gate *before* being sent: an atomic daily counter (the mechanism of §6.5, row `woodev_wc_russian_post_widget_quota`) incremented before the request — a retry is its own attempt — with a hard cap of **2 000 attempts per shop per day**. **100** of them are reserved for confirmation re-checks (§5.5), so a busy map cannot starve checkout. A shared burst limiter allows at most **2 attempts per second per shop** (F: no documented limit above 2 req/s), and one viewport fetch is capped at 6 attempts retries included (§5.3).
- **Circuit breaker with recovery.** Three consecutive failures (transport or 5xx) open it for 2 minutes; then exactly one probe attempt is allowed (half-open); success closes it, a failure re-opens it with the cooldown doubled (2 → 4 → 8 → 15 minutes, reset on success). While it is open no request is sent except the half-open probe, and the probe is counted against the same daily budget before it is sent; when the daily cap is spent nothing is sent at all, probe included, until the counter resets; the source serves stale tiles (TTL ignored) and, with none, throws (`points_unavailable`). A few transient errors can therefore never leave the plugin permanently broken.
- **Detail route.** The framework's public detail route allows 60 requests/minute per IP (`rest-api/class-pickup-controller.php:115`), which does not bound a shop (many ids, many IPs, and the negative answer for an unknown id is not cached). The daily cap does: a cache hit costs nothing, every miss — including an unknown numeric id — costs one attempt from the shared budget, and past the cap details come from the cache or fail.
- **Worst case:** ≤ **2 000 attempts per shop per day in total**, whatever the number of visitors, ids or IPs. A realistic shop (one delivery region, about 10 cells) is 10 × 144 = 1 440 tile refreshes at the very worst and far less in practice, because a tile is only fetched when someone looks at it. The framework's per-IP REST limits (240 point requests/min) bound a single visitor, not the shop.

## 6. Prices (D4)

### 6.1 Sequence

For each instance: `POST /1.0/tariff` with the merchant's keys (their account category is applied inside Otpravka, so this is the only path that *can* carry a contract price). Body fields (measured, G §4, K §1): `index-from`, `index-to`, `mail-type`, `mail-category`, `mass`, `declared-value` (kopecks), `delivery-point-index` and `dimension-type` for postamat, `mail-direct` + `transport-type` for international. `index-from` is **mandatory despite the spec** (omitting it returns a 200 `TARIFF_ERROR`). Response: kopecks; `total-rate` is **without** VAT, `total-vat` separate (22 % since 01.01.2026); `delivery-time{min-days,max-days}` where `min-days` is absent when equal to max. The rate shown to the buyer is `total-rate + total-vat`.

Fallback chain, in order: Otpravka → the public calculator `GET https://tariff.pochta.ru/v2/calculate/tariff/delivery` (anonymous; one product per call; `object=<code>`; kopecks; **business errors arrive as HTTP 400** because `errorcode=1` is the default, with codes such as 1204 and 2006, so the client parses a 400 JSON body as a refusal, not a transport failure). The public calculator equals Otpravka to the kopeck on the 9 + 15 domestic pairs measured (V, G), prices `POSTAL_PARCEL` **not at all** when the origin is outside its acceptance list, and for **international it equals Otpravka only with `service=28`** (33 of 35 pairs, K §3b); without it public prices are 0–16 % higher. Small packet cannot be priced publicly (error 1202). Contract prices through the public `dogovor=<ИНН>-<№>` are **UNVERIFIED** and silently ignored for a wrong contract (V) — the plugin never sends `dogovor`.

### 6.2 The destination index (interpretation)

A rate is needed before an address exists. Priority:

1. the customer's postcode if present and six digits;
2. the `postal_code` of the framework `Location_Record` (DaData) for the settlement;
3. a **representative index** from `Russian_Post_Location_Adapter::resolve()`: the nearest public office to the record's coordinates found through one widget tile (the same source as §5), cached 7 days (HIT) / 1 hour (MISS) like the Yandex adapter (`yandex:includes/location/class-yandex-location-adapter.php`), key from folded name, region and coordinates rounded to 2 decimals. The adapter returns `null` ("the carrier does not serve it") only when no public point exists within the settlement; it throws only on a transient failure.

`clean/address` is **not** used to find a destination index: a city-only input returns an index — a wrong one (Novosibirsk → a Tyumen-region index) — together with a "not usable" code (G §5). After a point is chosen the point's own index replaces the representative one, and the rate is recomputed (the price to a pickup point equals the door price of the route, V, G §4.2, so the recomputation changes the figure only when the index changes tariff zone). Whether a price varies between indexes of one city is **UNVERIFIED**.

### 6.3 Cache key and the rate cache

`FEATURE_RATE_CACHE` is opted in, with the framework default 600 s. The plugin context adds: credentials fingerprint (never the secret), product key, `index-from`, `index-to`, `mass`, `declared-value` in kopecks, COD flag, `dimension-type`, country and transport type for international. A change of credentials, sender point or product therefore produces a different key.

### 6.4 Payment dependence

`declares_payment_dependent_price()` returns `true`: under COD the category becomes `WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY` with `insr-value` (1 % insurance, G §4.3) and the framework recalculates on a payment change. The COD *transfer* fee is not in either calculator (where it is charged is UNVERIFIED, card R5) — the quote shows what Otpravka returns and the docs say so. Postamat/partner points never accept COD (§5.6).

### 6.5 Own quota counter, reserve and limit endpoint

The account quota is **10 000 requests/day on the test account**; every request counts one unit — GET, POST, 2xx, 4xx, the passport download and the limit poll itself (G §2, V). Other accounts may differ (an InSales note says 200 for new clients — secondary source, UNVERIFIED): the merchant's manager can raise it (D4, said in the docs and the notice). The displayed `current-count` **lags by minutes and updates in jumps**, so it cannot throttle. The plugin keeps:

- a **local counter** `woodev_wc_russian_post_quota` {`day` (Europe/Moscow date — the reset time is UNVERIFIED), `used`, `allowed`, `last_sync`}, incremented *atomically* (one `INSERT … ON DUPLICATE KEY UPDATE used = used + 1` row, or an option updated under the existing site lock) before every Otpravka request;
- `allowed` read from `GET /1.0/settings/limit` (`allowed-count`) at most **once per 30 minutes** while the counter runs and on key change (≤ 48/day), also used to *raise* the local `used` to `current-count` when the server's figure is higher (other clients of the same account);
- **tiering**: automatic rate calls stop when `used ≥ 70 % × allowed` (the `quota_reserved` state) and the plugin falls back to the public calculator; exports, edits, cancels, documents and the connection check use the remaining 30 %; at 100 % everything Otpravka refuses locally with a clear message instead of burning failing requests;
- a hard stop for an Otpravka rate fallback loop: after any HTTP 429, 401 or a quota-shaped error (shape UNVERIFIED) rates use the public calculator for the next 15 minutes (exponential backoff 15 → 30 → 60 min, reset on success).
- the **public calculator** has its own failure policy; the Otpravka backoff above does not apply to it. Its daily counter is incremented before each request; three consecutive transport failures or 5xx open its breaker for 2 minutes, then one probe request is allowed and the cooldown doubles on each failed probe (2 → 4 → 8 → 15 min, reset on success). While it is open and Otpravka is also unavailable the method is hidden with `api_error` for that request, and nothing is cached as a negative answer.

### 6.6 Worst case per shop per day (stated, as the operator rule demands)

| Channel | Worst case | Why bounded |
|---|---|---|
| Otpravka (all) | ≤ 70 % of `allowed` for rates, ≤ 100 % total; with the test account's 10 000: ≤ 7 000 rates | local counter, §6.5 |
| of which `GET /1.0/settings` + user-shipping-points | ≤ 8 | 6-hour refresh + manual |
| of which `GET /1.0/settings/limit` | ≤ 48 | 30-minute floor |
| of which tariff probes for the select | ≤ 32 | §4.3 |
| of which `clean/address` | one per export (12 addresses batch into one unit) | export count |
| Public calculator | ≤ 5 000 | own atomic counter incremented before each request and its own breaker (§6.5); past the cap the method is hidden with `quota_reserved` |
| Widget backend | ≤ 2 000 attempts in total — tiles, retries, details, index lookups, confirmation re-checks | one atomic counter before sending, burst limiter, breaker with recovery, §5.7 |
| Tracking | ≤ the configured cap (§10.3), default 100 | §10.3 |

## 7. Address (D5)

**Checkout.** Only the framework Location layer: `Location_Service`, the shared DaData provider (key entered once in the framework location settings, `woodev_location_token` / `woodev_location_clean_secret`), the locality fields and the cascade, in classic and block checkout. v1's own jQuery-suggestions bundle, the DaData token printed into page JavaScript and the postcode override `woocommerce_validate_postcode` for the whole site (v1 entry:307-312) are **not** carried.

**Export.** `POST /1.0/clean/address` runs once per export (an array of up to 12 addresses is one quota unit; the plugin sends one). Usability follows **only the codes** (v1's `is_usable()` rule is consistent with G's 12 samples): `quality-code ∈ {GOOD, POSTAL_BOX, ON_DEMAND, UNDEF_05}` **and** `validation-code ∈ {VALIDATED, OVERRIDDEN, CONFIRMED_MANUALLY}`. The presence of `index` is never a signal.

- Usable: the `*-to` fields and `index-to` come from the clean result.
- Not usable, or the call failed (the v1 uncaught `TypeError` is the cautionary bug: **one** `Russian_Post_Address_Cleaner` returns a typed value object and never `null`): the order body is built from the structured WooCommerce/Location parts (region, settlement, street, house), `index-to` from the customer, and the order is flagged `_wc_russian_post_address_doubtful = yes` with a private order note naming the codes. The orders page shows the flag.
- **Auto-export skips a doubtful order** (leaves the note, no failure state) while a manual export always proceeds — "admin trusts the merchant" (interpretation of D5; the operator should confirm in Q4).

For international orders `clean/address` is not used (Cyrillic/Latin rules in §9.3).

## 8. Export (D6)

### 8.1 Flow

`Russian_Post_Shipment_Handler extends Abstract_Shipment_Handler`. `api->create_order( $order )` → address cleaner (§7) → body → `PUT /2.0/user/backlog` (array body). A response with `orders[]` is success; `errors[]` inside HTTP 200 is a *partial-success* shape: `{ orders: [...], errors: [ { position, error-codes: [ { code, description, details } ] } ] }` where `position` indexes the input array. The plugin sends one order per request, so any entry in `errors[]` is a refusal for that order (non-transport exception → never retried automatically). The **barcode (ШПИ) is returned at create**; it is stored as the tracking number (`_wc_russian_post_tracking_number`) and `extract_carrier_order_id()` returns the `result-id` (stored in `_wc_russian_post_russian_post_id`, the v1 key).

### 8.2 Body by method (measured fields only)

| Field | Source |
|---|---|
| `order-num` | `$order->get_order_number()` (not unique on the carrier side, §8.5) |
| `mass` | integer grams; `ceil( max( packed weight, volumetric ) )` follows v1 (the divisor and whether the API wants the volumetric figure are **UNVERIFIED**, card R2) |
| `mail-type`, `mail-category` | from the instance's product; `mail-category` is **always** sent (a missing one returns HTTP 500 with a leaked Java NPE, G §7) |
| `mail-direct` | `643` domestic |
| `postoffice-code` | the instance's sender point `operator-postcode` |
| `index-to`, `*-to` address parts | §7 |
| `recipient-name`, `given-name`, `surname`, `tel-address` | order billing/shipping |
| `insr-value`, `payment` | kopecks; COD needs both |
| `fragile`, `sms-notice-recipient` | instance options (v1 read them from the cabinet; now plugin settings) |
| courier | plain address; **no** `courier` / `delivery-to-door` flags (rejected `UNEXPECTED_COURIER_FLAG`, `ILLEGAL_DELIVERY_TO_DOOR_POSTMARK`, G §8.1) |
| post office | the office index as `index-to` with its address parts (as v1: `export-order/otpravka.php`) |
| postamat | `ecom-data{ delivery-point-index, identity-methods: ["PIN"] }` **and** `dimension-type` (S/M/L/XL) — `delivery-data` alone fails with `EMPTY_DELIVERY_POINT_INDEX` (G §8.1, V contradiction 3); this recipe is *specific to ONLINE_PARCEL COMBINED → postamat* and is not generalised |

Money is built in minor units from `Carrier_Order_Line(s)`, never `intval(...) * 100` (v1 bug: 199.99 ₽ → 19 900 kopecks); a zero (0 % VAT, zero fragile) is **sent**, never stripped by `array_filter` (v1 bug). Contract tests pin both. The item/goods list (description, quantity, value, `vat-rate`, `item-number`, `goods-type`, `lineattr`, `payattr`) that v1 builds for COD/ECOM orders: the v2 body key and whether ONLINE_PARCEL COD requires it were **not measured** (G created COD orders without one, accepted) — UNVERIFIED, card R6/R7.

### 8.3 Refresh, and why there is no outbound edit

In the framework `supports_update()` / `update()` mean **«pull the carrier's current state»**: the «Обновить» action on an exported order, offered without confirmation as «Запросить у перевозчика текущий статус заказа» (`order/abstract-shipment-handler.php:887-920`, `admin/orders/class-order-actions.php:257`). The plugin therefore implements them **read-only**. `supports_update()` is `true` when a tracking token is configured; `update()` polls the order's barcode once through the same code as the poller (§10; it counts against the tracking quota, §10.3) and returns a failure with a message when tracking is off or the daily cap is spent. It never sends an order body, so a refresh cannot change the mass, address or price of a carrier order, and it works for an order that is already in a batch.

The outbound edit (`PUT /1.0/backlog/{id}` with the full body re-prices the order and keeps the barcode, G §8.3) is **not offered in release 1**; the framework has no edit seam and mapping it onto the refresh seam would resend the WooCommerce payload on a routine refresh. When an order changed after export, `Shipment_Fingerprint::is_outdated()` marks it and a private note says so; the merchant's way to change the carrier order is to cancel (only while it is in «Новые», §8.4) and export again. If the operator later wants an explicit «Отправить изменения» action, it is a separate action, never the refresh seam. A search by barcode or `order-num` uses `GET /1.0/backlog/search?query=…` (exact match only).

### 8.4 Cancel and the "remove from a batch deletes the order" trap

Measured (G §8.6): `DELETE /1.0/backlog [id]` works for an order in «Новые»; for an order **in a batch** it returns HTTP 200 with `errors:[{error-code:"NOT_FOUND"}]`; **`DELETE /1.0/shipment [id]` — documented as "remove orders from a batch" — deletes the order**; the safe route to un-batch is `POST /1.0/user/backlog [ids]` (same barcode kept). Release 1 has no batches of its own, but merchants will create them in the Russian Post cabinet (the plugin cannot hand a parcel over without one). Therefore:

- `cancel()` calls **only** `DELETE /1.0/backlog` and treats `NOT_FOUND` as "the order is in a batch or already gone": it refuses with «Заказ уже в партии — отмените его в кабинете «Отправка»», it never calls `DELETE /1.0/shipment`, and it never un-batches on its own.
- A static test fails the build if the string `/1.0/shipment` is used with the `DELETE` method anywhere in the plugin.
- After a successful cancel the barcode meta is **deleted** (v1 left it because `update_order_meta()` returned early on empty).

### 8.5 Duplicate guard

`order-num` is **not unique** on Russian Post's side (G §8.2: a second create with the same `order-num` succeeds with a new id and barcode; search then returns two hits). Defence in depth, all framework mechanisms:

1. `Order_Lock` + the idempotent `export()`; the stored `result-id` short-circuits a repeat export.
2. `Shipment_Fingerprint` marks an order whose content changed since the last export as outdated (§8.3: shown to the merchant, never auto-resent).
3. An export that ends in **timeout / 5xx** is the framework's «unknown» state (`EXPORT_UNKNOWN_META`). **Release 1 keeps `supports_reconcile()` at `false`** (the framework default): automatic exports and retry runs stop on an unknown outcome and leave a private note asking the merchant to check the «Отправка» cabinet; manual exports keep their existing behaviour (`abstract-shipment-handler.php:522-538`). Reconciliation by `GET /1.0/backlog/search?query=<order-num>` is not safe: it cannot positively identify *this* export — `order-num` is the WooCommerce display number and two shops on one Otpravka account can both have order 123 — and it cannot establish *absence*, because it sees only «Новые» while the merchant may have moved the created order into a batch in the cabinet (§§8.4, 8.6), so a miss does not prove the order was never created. Turning it on later needs both: a persisted, site-scoped export reference that the search can match with the candidate's identifying data verified (recipient, index, mass), and a lookup across every store an order can move to (`backlog/search` and `shipment/search`). The reference is unmeasured (U25, card R7).
4. Automatic multi-parcel grouping: orders to the same recipient within about a minute are silently grouped (`group-name` in the create response) and batching one alone later fails with `NOT_ALL_ORDERS_FROM_GROUP` (G §8.2). The plugin stores `group-name` when present and the note says so; no code can avoid it.

### 8.6 The Ф7 label (D6) — the risk

`GET /1.0/forms/{orderId}/f7pdf?sending-date=YYYY-MM-DD[&print-type=THERMO|PAPER]` returned a PDF (94 842 B thermo, 99 912 B paper) for an order **in a batch** (G §8.5); it is a domestic-only form (400 code 1019 for international, K). Whether a Ф7 exists for an order that is only in «Новые» was **not measured** for a domestic order; the endpoint `GET /1.0/forms/backlog/{id}/forms` exists and returned 500 `Forbidden mail type` for international parcels and small packets before a batch (K), which says nothing about domestic. **Decision 6's "label without a batch" is therefore unproven.** Design:

1. **Card R1 measures it first**: create a domestic ONLINE_PARCEL on the test account, call `forms/backlog/{id}/forms`, delete the order (no batch, no `checkin`).
2. If it works: `Russian_Post_Document_Source` offers `f7` straight from «Новые» (`get_document_types()` answers from local data only — one per row on the orders page — and `get_document()` returns a `Document_Result`, pending when the carrier is slow).
3. If it does not: the label button is offered **only when the order is already in a batch**, detected with `GET /1.0/shipment/search?query=<barcode>` (returns the shipment when batched) and the merchant batches in the cabinet. No implicit single-order batch is created in release 1 (it would pollute the cabinet with one batch per parcel, trigger the MMO trap, and make later edit/cancel need an un-batch). The operator settled this branching (D14): measure first, both paths designed.

`print-type`: a plugin setting (THERMO / PAPER). `F112` returns HTTP 200 with an **empty body** for ordinary parcels (Ф112ЭК applies to COD / declared value) — an empty body is "no such form", never a blank PDF. `F103`, the all-documents zip and `checkin` are out of release 1 (D6; `checkin` e-mails a real post office and the same endpoint returns 403 or 400 depending on `Accept`).

## 9. International (D11)

Measured in full on the test account (K): international is a **payload variant of `POSTAL_PARCEL` / `EMS` / `SMALL_PACKET` through the same `PUT /2.0/user/backlog`** — no separate path or mail type. 29 orders in 12 destinations were created, priced, edited, batched, documented and deleted; nothing was left behind.

### 9.1 Method and availability

`russian_post_international` (§4.1), one tariff per instance (parcel, EMS or small packet; `transport-type` AVIA or SURFACE as an instance option). It is offered per destination country using the official dictionary and the account:

- **Country dictionary:** `GET https://tariff.pochta.ru/v2/dictionary/country` (anonymous, 256 countries, 277 KB, free, no Otpravka quota). Per country and product: `block` (not by surface), `avia.block`, `wmax`, `sizemax`, `oc` (declared value allowed), `np` (COD), `serviceoff`. Cached with a compact extract (country code → products open, weight cap) refreshed daily with jitter, ≤ 2 calls/day. **84 countries have every product closed** (Poland, Ukraine, Finland, Australia, …); an order to them returns `TARIFF_ERROR … запрещена доставка`. A closed country hides the method with `country_closed`. The USA and UAE parcels are surface-blocked but open by air; the server silently turns a `SURFACE` request into `AVIA` there, so the plugin sends the transport type the dictionary allows.
- **Account:** international products depend on the intake office (7 of 27 offices on the test account carry any `INTERNATIONAL_*` product); the method is hidden when the chosen sender point has none (K §6 item 9).
- **Country codes** are the ISO 3166-1 numeric codes from that dictionary, not v1's 248-row table, which gives 8 numeric codes to two countries each (784 AE/OM, 410 KP/KR, 534 SX/MF, 662 LC/RS, 760 SY/SK, 729 SD/SR, 258 PF/HR, 140 CF/TD) and sends Slovak, Croatian and Serbian parcels to Syria, French Polynesia and Saint Lucia (K measured it). It also lacks 17 of the spec's codes (Oman 512, Serbia 688, Slovakia 703, Croatia 191, Chad 148, Suriname 740, Åland 248, …). The mapping WooCommerce country code → numeric code is derived from the dictionary's two-letter-aware data plus an ISO table in the plugin, with a test that every WooCommerce country the method serves resolves to exactly one code and that no code is used twice.

### 9.2 Customs declaration (the rule table)

`customs-declaration{ currency, entries-type, customs-entries[]{ amount, description, tnved-code, value, weight, country-code }, optional ioss-code, invoice-number, … }`. Rules measured (K §3):

| Rule | Consequence |
|---|---|
| **Forbidden for Kazakhstan, Armenia, Kyrgyzstan** — a declaration returns `UNEXPECTED_CUSTOMS_ENTRIES`; Belarus and Uzbekistan accept entries; the dictionary flag `declaration` does **not** predict it | A plugin-side list `no_declaration = [398 KZ, 051 AM, 417 KG]` plus a one-time retry: on `UNEXPECTED_CUSTOMS_ENTRIES` resend without the declaration; on `EMPTY_CUSTOMS_ENTRIES` resend with it. The list is a setting-free constant, extendable by a filter. Armenia/Kyrgyzstan plain parcels without a declaration are UNVERIFIED (only KZ created). |
| Required elsewhere | `EMPTY_CUSTOMS_ENTRIES`, `EMPTY_CUSTOMS_ENTRY_TNVED_CODE`, `EMPTY_CURRENCY`, `EMPTY_ENTRIES_TYPE` are the error codes if missing |
| `entries-type` | `SALE_OF_GOODS` default; `GIFT` is refused for a DE parcel (`ILLEGAL_ENTRIES_TYPE`); `COMMERCIAL_SAMPLE` and `DOCUMENT` accepted |
| `description` | at least 11–13 characters (`DESCRIPTION_TOO_SHORT` below that: «Books», «Red books» fail, «Cotton t-shirt» passes); short product names are padded, not truncated to 57 as v1 did |
| entries' weights | their sum must not exceed `mass` (`ENTRIES_SUM_WEIGHT_EXCEEDS_ORDER_MASS`) |
| ТН ВЭД | ten digits per product (`_tnved_code` product meta, v1 key kept); a malformed code is `ILLEGAL_CUSTOMS_ENTRY_TNVED_CODE`; whether a well-formed non-existing code is rejected is UNVERIFIED |
| origin | `country-code` per product (v1 fixed it to 643); a product-level origin field is new, default 643 |
| `ioss-code`, `invoice-number` | accepted and stored without format validation; the setting «IOSS-номер» is optional |

### 9.3 Latin-only and mandatory fields

Recipient name, region, city, street and house must be **Latin** (`ILLEGAL_RECIPIENT_NAME_INTL`, `ILLEGAL_STREET_TO_INTL`, `ILLEGAL_PLACE_TO_INTL`): the plugin transliterates the fields v1 listed (`region-to, place-to, street-to, given-name, surname, recipient-name, area-to, house-to, room-to`) and the checkout shows a one-line "Latin letters only" hint for foreign destinations. `str-index-to` (alphanumeric foreign postcode) and `mail-direct` (numeric code) are required (`EMPTY_INDEX_TO`, `EMPTY_MAIL_DIRECT`); Russian code 643 with a foreign postcode is `ILLEGAL_INDEX_TO`. `transport-type` ∈ SURFACE / AVIA / COMBINED / EXPRESS / STANDARD. The sender's INN, КПП and the Latin organisation name (`org-name-international`) come from the account settings and appear on the forms; the wizard checks them (§12).

### 9.4 Limits we enforce ourselves

Order create enforces **mass only** (`ILLEGAL_MASS_EXCESS` at 20 001 g parcel, 2 001 g packet, 30 001 g EMS; dimensions are not enforced, a 110 cm side was accepted). The plugin enforces per-country `wmax` and the size rules (parcel 105 cm side / 200 cm sum, packet 60×30×30) itself, and hides the rate when the cart exceeds them.

### 9.5 Prices, documents, COD

- **Prices** through `POST /1.0/tariff` (carries the account category and a *full* door-to-door `delivery-time`: DE parcel by air 25–31 days, EMS 12–17, KZ EMS 9–14). The public fallback is allowed only with `service=28`, never for small packet (error 1202), and its `delivery` figure is the **domestic leg only** (4–5 days) so it is never shown as an estimate.
- **Documents** CN22 / CN23 / E-1 come from `GET /1.0/forms/{id}/forms?sending-date=…` **only after the order is in a batch** (parcel 5 pages with CN23 on page 1, EMS 3 pages E-1, small packet 1 page CN22 + address label); Ф7 does not exist for international (400 code 1019). Before a batch the same family returns 500 `Forbidden mail type` / code 1021. With no batches in release 1 the international forms follow the same "available once the merchant has batched it" rule as §8.6, and the order note says so.
- **COD is off by default** for international: only some countries (KZ yes; DE `ILLEGAL_MAIL_CATEGORY`; AM `TARIFF_ERROR`; BY needs `payment` ≤ declared value in a converted currency, inferred).
- Delivery estimate shown to the buyer: the range from `delivery-time`, not the domestic 4–5 days.

### 9.6 International and the migration

Old international orders keep every key (§13). v1's country table is **read-only history**: the migration does not translate stored numeric codes in order meta, because they were the codes actually sent.

## 10. Tracking (D7; see §0 note 1)

### 10.1 Protocol (L, MEASURED on the test account)

`GET https://postapi.pochta.ru/tracking/api/external/v1/info?barcodeList=<barcode>[&language=RU|EN]`, header `Authorization: ApiKey <token>` (scheme word `ApiKey`, not Otpravka's `AccessToken`), `Content-Type: application/json`. The token is a **separate** credential (~1 050 characters, JWT-like): the 32-character Otpravka token sent here gets HTTP 403, so there is no single token for everything, and the quick-start page never mentions it. It is issued in the legal-entity cabinet settings (exact menu path UNVERIFIED; news 12.06.2026 and 09.10.2026). **One barcode per request** (the parameter is named `barcodeList` but two valid barcodes return the first only). Accepts 14-digit domestic and 13-character S10 international codes. Response: one object with `trackingItemOperationGroupStatuses[]` (high-level groups `{ title, code, date, direction, future, originTrackingHistoryItems[] }`) — the codes seen are `RECEPTION_EXPECTED` («Оформлено»), `IN_WAY`, `READY_FOR_DELIVERY`, `GIVING` («Вручено»); `future: true` groups are the predicted path. The full group-code list is **not published** (UNVERIFIED). Errors: bad or foreign token → **403 with an empty body**; an invalid barcode → 400 `{"code":"1003"…}`; no error table is documented.

SOAP is not used (decision 7, amended): the old `rtm34` is being replaced by this service (news 09.10.2026), and batch tracking over SOAP (`/fc`, login + password by support request) is not in release 1.

### 10.2 Mapping

`Russian_Post_Tracking_Handler extends Abstract_Tracking_Handler`. The group array is a *path*, not a chronology: the measured response (`l-raw/live/track-two-valid.txt`) carries `RECEPTION_EXPECTED` with two real operations, then `IN_WAY`, `READY_FOR_DELIVERY` and `GIVING` all with `future: true`, `date: null` and no events, for a parcel that was never delivered. So `map_events()` selects the state explicitly:

1. **Discard predicted groups**: a group with `future: true` or without history items contributes nothing.
2. Flatten `originTrackingHistoryItems[]` of the remaining groups (each has `date` ISO +03:00, `humanStatus`, `humanOperationStatus`, `operationType`, `operationAttr`, `cityName`, `index`) and **sort them chronologically by `date`** (ties keep source order). These are the framework events.
3. Apply the **measured operation overrides** to the newest event first: the table `Russian_Post_Operation_Overrides`, keyed by `operationType` (and `operationAttr` where measured). Its first entry is `46` («Отмена присвоения идентификатора», measured inside a `RECEPTION_EXPECTED` group) → `cancelled`. Entries are added only from recorded fixtures, never from the vendor page, whose `operationType` / `operationAttr` dictionary is inconsistent for codes 53–55 (L).
4. Otherwise the **coarse group fallback** on the group of the newest actual event: `RECEPTION_EXPECTED` → `created`, `IN_WAY` → `in_transit`, `READY_FOR_DELIVERY` → `ready_for_pickup`, `GIVING` → `delivered`; `direction = TO_SENDER` → `returning`, and `returned` when that group is `GIVING`; anything unmapped → `unknown` with the raw label preserved (the same rule as Yandex).

`delivered` is therefore only ever derived from an *actual, dated* `GIVING` event, never from a predicted one. The two recorded fixtures (the predicted-`GIVING` response and its operation-46 event) are copied, trimmed, into the plugin's committed test fixtures by card P14b. The raw v1 internal statuses (`new, exported, accepted, dispatched, failed, delivered, canceled`, `_wc_russian_post_status`) stay stored and are mapped for display. `delivered` and cancelled go to the framework `Export_Settings::SETTING_STATUS_DELIVERED` / `SETTING_STATUS_CANCELLED` (v1 `tracking_complete` / `tracking_canceled` migrate there).

### 10.3 Poller with a hard daily cap (the operator rule)

Model: `yandex:includes/tracking/class-yandex-status-poller.php` (recurring Action Scheduler action, one-order action right after export, bounded pages, orders completed by the merchant still polled for 60 days). Russian Post specifics:

- **One request per barcode**, so the poller is a **queue**: every active shipment (exported, not final, with a barcode) gets a per-barcode `next_poll_at`; each run takes the barcodes with the oldest `last_polled_at`, at most **25 per run** and at most **5 requests per minute**.
- **Daily cap (setting «Лимит запросов трекинга в сутки»), default 100** (interpretation). The two vendor pages disagree: the FAQ beside the new spec says single access is limited to **5 000/day** (more on request), while the older Terms page still says **100/day** without a contract (L). Until card R3 measures the real figure the default is the conservative one; the setting accepts up to 5 000 and the docs explain it. With 100 requests/day and N active orders each order is polled about every `N / 100` days at the extreme, so a small shop (≤ 20 active) polls each order several times per day and a large one converges slowly — "statuses simply update slower" (D7).
- **Adaptive interval per barcode:** never polled twice within 2 hours; 6 hours once `READY_FOR_DELIVERY`; stop at a final state (`delivered`, `returned`, `cancelled`). An age cap of 60 days applies **only to orders the merchant has already completed** in WooCommerce — the donor rule (`yandex:includes/tracking/class-yandex-status-poller.php::completed_too_long_ago()` caps completed orders only); a genuinely active order stays in the queue until a real final state, however long an international shipment or return takes. When active barcodes outnumber the daily cap the queue is ordered never-polled first, then oldest `last_polled_at`, so every barcode gets a first poll before any gets a second.
- **Counter** `woodev_wc_russian_post_tracking_quota` {`day`, `used`} incremented before the request; at the cap the run ends and the next one is scheduled after midnight Moscow time. A 403 does **not** stop the whole queue as "token wrong" if only one request failed: three consecutive 403s set `tracking_state = token_rejected` (admin notice, polling paused until the token is saved again). A 429 or a body that names a limit pauses polling until the next day. 4xx counting against the vendor's quota is UNVERIFIED.
- **Token optional.** No token means the **«tracking off»** state, shown on the orders page and in the wizard — never a silent drop — and the buyer-facing tracking link still works (§10.4). Order export does not depend on tracking.
- **Worst case per shop:** ≤ the cap (default 100) plus ≤ 1 check request per token save.

### 10.4 Tracking link and e-mail

The framework resolves a link from a template and the tracking number (`get_tracking_url_template()`); Russian Post's public page `https://www.pochta.ru/tracking#{tracking}` is the template (interpretation; confirm the exact pattern when the plugin is wired).

**E-mail.** The framework's delivery-status e-mails (`email/class-delivery-status-emails.php:80`) have *shared* ids (`customer_shipment_created`, …), their own options (`body`), their own placeholders and template (`emails/shipment-status.php`), apply to every carrier on the site, and keep their sent flag as `_woodev_delivery_email_<id>`. Copying Russian Post's customised text into them would change the CDEK and Yandex messages and lose the merchant's template. Release 1 therefore **keeps a Russian Post-only e-mail**: the WooCommerce e-mail id `wc_russian_post_tracking_email`, its option `woocommerce_wc_russian_post_tracking_email_settings` (`message_text`, subject, heading, enabled) and its template path `emails/tracking-number.php` (+ plain) are reimplemented on v2, scoped to this carrier, with the v1 placeholders `{order_date}` / `{status}` (v1: `includes/classes/tracking-email.php`). Theme overrides keep working because the template path and its variables are unchanged (a test renders an override). Nothing is written into the framework's shared e-mail options. The sent state honours v1's `_wc_russian_post_tracking_number_email_sent` (it stores the barcode that was mailed, `tracking-email.php:73-79`): the e-mail is not sent again when it equals the current barcode, so an upgrade never re-sends. The framework's own `customer_shipment_created` e-mail (enabled by default) can fire for the same order; whether both fire and whether a plugin can exclude its provider is **UNVERIFIED** (U24) and is measured in card P14b, which proposes the outcome to the operator because it changes what a customer receives. The v1 webhooks are gone with the cabinet.

## 11. Credentials and the API client (D9)

### 11.1 Fields

On `woodev-settings` (never a WooCommerce Integrations tab), one group «Подключение»:

| Field | Type | Notes |
|---|---|---|
| «Токен приложения» | `Woodev_Control::TYPE_PASSWORD`, `Woodev_Setting` with `sensitive => true` | Otpravka `AccessToken` (32 characters); cabinet «Настройки → API → Токен Авторизации» (L, `LKUL-api`) |
| «Логин» | text | passport.pochta.ru login |
| «Пароль» | password, sensitive | stored as entered; the header value is `Basic base64(login:password)` built at request time (v1 stored the same data in clear) |
| «Токен трекинга» | password, sensitive, **no `maxlength`** | optional, long (~1 050 characters); a placeholder tells where to get it |
| «DaData» | framework location settings | shared, one place |

Rules: never an e-mail with a credential link (v1 e-mailed the cabinet link carrying `guid_id` + `guid_key`); **no encryption at rest** (D9: the framework's masking is enough, no card); a field renders as a mask when a value exists and a blank save keeps the stored value. The wizard-contract note that the first real secret field in a wizard is the trigger for `#110` item 7 (masking secrets in the wizard bootstrap) **fires here**: card P3 must verify the wizard's initial state and REST responses never echo a stored secret (UNVERIFIED today).

### 11.2 API client (four transports, one façade)

`Russian_Post_Api_Client implements Shipping_API` composes four sub-clients, each a `Woodev_API_Base` subclass with its own id so logs and caches stay separate:

| Client | Host | Auth | Purpose |
|---|---|---|---|
| Otpravka | `otpravka-api.pochta.ru` | `Authorization: AccessToken <app token>` + `X-User-Authorization: Basic …` | settings, tariff, clean, backlog, forms, search, shipment search, limit |
| Public tariff | `tariff.pochta.ru/v2` | none | fallback prices, country dictionary, `dictionary/postoffice/{index}` (≤ 10 ids) |
| Widget | `widget.pochta.ru` | none | points, details |
| Tracking | `postapi.pochta.ru/tracking/api/external/v1` | `Authorization: ApiKey <token>` | tracking |

- **Secret masking.** Override `get_secret_header_names()` to add `X-User-Authorization` (the framework's default list masks `Authorization`, which already covers both `AccessToken …` and `ApiKey …`, but **not** `X-User-Authorization`; the v1 bug logged the base64 of login:password in clear under debug). Override `get_secret_param_names()` for fields seen in `GET /1.0/settings` responses: `apig_access_token`, `hid`, `legal-hid`; and do not write the 238 KB settings body to the debug log at all (status and size only). A contract test feeds a request through the real sanitizer and asserts neither header value survives.
- **No cache on writes.** The cacheable trait is used only on reads, each with an explicit TTL (settings 6 h, dictionaries 24 h, tariff by the rate cache); a unit test asserts a `PUT`, `POST /1.0/user/*` and `DELETE` request never carries the trait (the v1 bug cached two identical PUTs and DELETEs for 24 h).
- **Error shapes** (G §7): 401 has two bodies (no/invalid app token: `{"status":"ERROR","message":"Error token value not found"}`; valid token with a bad user key: `{"code":"1011","sub-code":"UNAUTHORIZED","desc":"ILLEGAL_CREDENTIALS"}`) → credentials rejected; **407 means unknown route or wrong method** (gateway) → a programming error, never "proxy"; 400 `BAD_REQUEST` and field-level `{"code":"1003","sub-code":…}`; 404 with code `1001`/`1004`; 406 on the passport-style endpoints with the wrong `Accept`; HTTP 500 leaking a Java null-pointer text; **business errors arrive inside HTTP 200** (`errors[]`) for tariff, order create and batch calls and are parsed in `do_post_parse_response_validation()` as refusals; 403 on a form call means a precondition is missing (`checkin`), depending on `Accept`. HTTP 429 is mapped to `Woodev_API_Rate_Limit_Exception` with `Retry-After` (the shape is UNVERIFIED).
- **Request purposes:** rates under `Woodev_API_Request_Purpose::RATES`, point details under `REFERENCE`, export under `EXPORT`; the widget client has a hard 8 s timeout.
- **User-Agent:** product token `woodev-wc-russian-post` (ASCII only; a non-ASCII product name once earned HTTP 400 from CDEK, framework gotcha).

## 12. Settings and the wizard (D12)

### 12.1 Settings (`woodev-settings`)

Groups: «Подключение» (§11.1, connection test through `Woodev_Settings_Connection_Test`: `GET /1.0/settings`, shows organisation, ИНН, contract kind, `api_enabled`, `blocked`, quota `allowed-count`), «Отправитель» (sender point and default return address from `user-shipping-points`), «Габариты по умолчанию» (framework default-dimensions settings), «Выгрузка» (auto-export statuses, `print-type`, VAT rate — v1 `vat_rate` `-1/0/10/20/22`, default `-1`, the wizard offers 22), «Отслеживание» (token, daily cap, delivered/cancelled statuses), «Логирование» (the framework's debug switch; merchant-facing diagnostics only here). Settings that the Russian Post cabinet held in v1 (`senderZipCode`, `useFragile`, `useSmsAlertByDefault`, `useCartValue`, shipment priorities) become plugin settings; the merchant no longer keeps two configurations in sync.

### 12.2 Wizard (D12; contract `2026-10-08-setup-wizard-v2-contract.md`)

A `Woocommerce_Setup_Wizard` subclass in the Yandex manner (`yandex:includes/setup/class-yandex-migration-wizard.php`, server-rendered steps). Steps, each skippable, state monotonic (D1 of the contract: "completed" means the merchant reached the end, not that the plugin is ready):

1. **Ключи «Отправки»** — three fields and the action «Проверить ключи» (a `Step_Action`, non-persisting, `GET /1.0/settings`). Per-field server-side errors: token missing; 401 → «Проверьте токен и логин/пароль»; `api_enabled = false` → «Доступ к API не подключён — напишите личному менеджеру»; a private-person account → the D1 sentence about legal entities, ИП and самозанятые.
2. **Пункт приёма** — a select from `user-shipping-points` (name, address, `operator-postcode`); the first point is preselected; international availability is flagged per point.
3. **Вес и габариты по умолчанию** — prefilled; a populated shared value is never overwritten (§14.1 step 4).
4. **Какие тарифы вам доступны** — the product list of §4.3 with the reason for each unavailable one; the step writes nothing.
5. **Отслеживание (необязательно)** — the token; the action «Проверить токен» sends **one** request for an *invalid* barcode: 403 = bad token, 400/`1003` = token works (L, MEASURED); the text explains that tracking needs a legal-entity cabinet and that skipping turns tracking off, not export.

**The v1 migration screen** (D1) is a branch shown first when a v1 install is detected (the lifecycle sets a flag): the migration census of §14.1, the "start fresh" choice that hides the mapping steps (graph recomputed on the server after each save, D3 of the contract), what the old options held (sender index, default dimensions/weight) and an explanation of the Otpravka keys for a merchant who has only a widget cabinet. Progress of the background migration (§14) is shown here and on an admin notice. Whether the migration screen is server-rendered or a plugin React component (D4 of the contract) is an **interpretation**: server-rendered with a polled status endpoint, because the progress is the only dynamic part and it avoids a plugin JS build.

## 13. Installed-site data contract

ADR-005 lets the platform break APIs but not installed data. The v1 contract list is in the fact base ([README §3.1](../research/2026-10-21-russian-post-v2/README.md), [a-v1-inventory.md §2](../research/2026-10-21-russian-post-v2/a-v1-inventory.md)); the table below gives the **fate of every key** (a checklist file `docs-internal/migration/russian-post-data-preservation-checklist.md` is proposed as a card, P2, so the table is not lost; CDEK and Yandex have theirs).

| Kind | v1 value | v2 fate |
|---|---|---|
| Plugin id / EDD / text domain | `wc_russian_post`; 8772; `woocommerce-russian-post` | **Keep** |
| Main settings option | `woocommerce_wc_russian_post_settings` | **Keep the option and its key names**; values read and re-mapped in §14.2 (the secret fields stay where they are) |
| Instance options | `woocommerce_wc_russian_post_courier_{instance}_settings`, `…_postal_{instance}_settings` | **Copied** into `woocommerce_russian_post_courier_{instance}_settings` / `…_pickup_…` / `…_postamat_…` (CDEK `instance_option()` pattern); the old option stays for rollback |
| Method ids | `wc_russian_post_courier`, `wc_russian_post_postal` | **Changed (D8)**: zone rows rewritten, instance ids unchanged (§14.2); old order lines rewritten (§14.3) |
| Site options | `wc_russian_post_guid_id`, `wc_russian_post_guid_key` | **Not read, not deleted** (the widget cabinet is gone, D1); left in place so a downgrade works; documented |
| Upgrade flag | `woocommerce_wc_russian_post_upgraded_to_1_2_0` | Untouched |
| `wc_russian_post_user_options` | read helper, no writer | Ignored |
| Framework options | `woodev_wc_russian_post_license_key`, `…_license`, `…_version`, `…_is_active`, `…_milestone_*` | **Keep** (derived from the plugin id) |
| Order meta | `_wc_russian_post_is_russian_post` | **Keep** — the orders-page marker |
| | `_wc_russian_post_status` | **Keep**; internal values mapped to canonical states for display (§10.2) |
| | `_wc_russian_post_export_type` (`otpravka` \| `widget`) | **Keep** (provenance). Whether a `widget` order was mirrored into «Отправка» is **UNVERIFIED** (U23; README §6 contradiction 4), so the relationship is treated as *unknown*, not as absent: edit-free actions that need a carrier id (cancel, label, refresh by id) are refused by default with «заказ выгружен через кабинет «Виджет» — управляйте им там», tracking by barcode still works (it needs only the barcode), and **no Otpravka id is ever assumed or manufactured**. If an authoritative id is established (a lookup by barcode that the merchant confirms), a bounded manual «Привязать к «Отправке»» action may store `_wc_russian_post_russian_post_id`; it is a candidate card, not release 1 |
| | `_wc_russian_post_tracking_number` | **Keep** — the barcode |
| | `_wc_russian_post_tracking_number_email_sent` | **Keep** |
| | `_wc_russian_post_russian_post_id` | **Keep** — Otpravka `result-id`, the carrier order id |
| | `_wc_russian_post_latest_order_update_time` | **Keep** |
| | `_wc_russian_post_tracking_history` | **Keep** |
| | new: `_wc_russian_post_address_doubtful`, `_wc_russian_post_group_name` | New, written only by v2 |
| Shipping-item meta | `wc_russian_post_rate` (array with `method_type`, `rate` (API `stdClass`), `normalize_address` (**serialised `Address_Normalize`**), `delivery_point` (array incl. a serialised `Address_Normalize`)) | **Converted to a plain array in place** (§14.3); item meta `wc_russian_post_v1_method_id` added to each rewritten line for rollback |
| Shipping-item `method_id` | `wc_russian_post_courier`, `wc_russian_post_postal` | **Rewritten** to the new ids (§14.3) |
| User meta | `wc_russian_post_customer_delivery_point` (array incl. a serialised `Address_Normalize`) | **Converted to a plain array**; v2 does not read it (the framework stores the selection) — kept so no data is lost |
| New orders (v2) | the point a buyer chose | **New plugin-declared key map** for `Shipping_Order_Handler::set()` / `store_pickup_point()` (`order/class-shipping-order-handler.php:98-120`): `_wc_russian_post_pickup_point` (the full `Pickup_Point::to_array()` incl. `type`, `cells`), `_wc_russian_post_pickup_point_id`, `_wc_russian_post_pickup_point_address`, `_wc_russian_post_product_key`, `_wc_russian_post_dimension_type`. Export, display and documents read an order **only** through `Russian_Post_Order_Reader` |
| Old orders (v1) | `wc_russian_post_rate` (v1 serialised graph, or the plain array once converted) | Converted by the Tier B batch into the v2 keys of §13; the runtime reader reads **only** v2 keys. An order the batch has not reached yet is migrated on touch: opening it or any plugin action on it first runs the same Tier B writer for that one order, then proceeds (§14.3). No read-only legacy path (D15) |
| Product meta | `_tnved_code` | **Keep** (ten digits, used by §9.2) |
| Transients | `wc_russian_post_site_settings` (24 h), `wc_russian_post_delivery_point_data_{id}`, `wc_russian_post_tracking_history_{barcode}`, `wc_russian_post_setup_wizard_redirect`, `woodev_wc_russian_post_api_response_{md5}` | **Not carried.** The migration reads `wc_russian_post_site_settings` once (if present) for `senderZipCode`, then deletes all five families |
| Action Scheduler / cron | `wc_russian_post_orders_update` (recurring, 12 h) | **Cancelled** by the migration; replaced by §10.3 |
| `wc-api` endpoints | `?wc-api=russian_post_barcode`, `…_status` | **Removed** with the cabinet (D1); a request now returns 410 with a one-line body |
| AJAX | `get_post_office`, `set_russian_post_point`, `russian_post_order_action`, `russian_post_set_customer_location` | **Removed** (the first was an open unauthenticated Otpravka proxy burning the merchant's quota). Third-party callers break; ADR-005 allows it |
| Admin page | `admin.php?page=wc_russian_post_orders`, screen option `wc_russian_post_orders_edit_per_page` | **Keep the slug** (bookmarks) on the framework orders page |
| E-mail | WC email id `wc_russian_post_tracking_email`, option `woocommerce_wc_russian_post_tracking_email_settings` (+ `message_text`), theme overrides under the plugin template path | **Kept as a Russian Post-only e-mail** (§10.4): same id, option and template path, reimplemented on v2 with the v1 placeholders; the framework's shared status e-mail options are never written; the v1 sent flag `_wc_russian_post_tracking_number_email_sent` is honoured so an upgrade never re-sends |
| Public hooks | ~30 filters `wc_russian_post_*` and actions (two baked-in typos: `wc_russian_mail_category`, `…_changed_from_{a}_to{b}`) | Preserve those whose event still exists (tracking number changed, status changed, export success); alias only where a consumer is known; **never silently drop** — the dropped list goes into the release notes |
| Core filter | `woocommerce_validate_postcode` override for RU | **Removed** (a site-wide override from a shipping plugin) |
| Log source | `wc_russian_post` | Keep |
| Uninstall | `uninstall.php` called `WC()` (fatal by reading) and removed neither settings nor order meta | New `uninstall.php` that never loads WooCommerce and removes only plugin-owned options and transients; order meta stays |
| Retired code | the CDEK bridge, the DaData-in-browser script, `cms.pochta.ru` registration/uninstall | Not ported |

Three tests pin the contract in the plugin repo, in the manner of the Yandex `*ContractTest`s: option keys, order meta keys, and the method-id mapping table (old → new).

## 14. Migration v1 → v2

Mirrors `cdek:includes/lifecycle/class-cdek-v1-migration.php` and `yandex:includes/lifecycle/class-yandex-v1-migration.php`: every step idempotent and decided from the data found, a journal option (not autoloaded) recording what a manual rollback needs and written **before** rows change, a site-wide lock, every write read back, a failure throws and the stored version does not advance. Oldest supported v1: `1.2.0.0` (the `upgraded_to_1_2_0` flag and the 1.2.x lifecycle exist; earlier shops keep their keys and nothing else, as in CDEK's unsupported-origin branch) — a decision for card P16, **UNVERIFIED** which versions are in the field (U17).

The migration has **three tiers** because order data can be huge and the other two cannot:

### 14.1 Tier A — synchronous, on the first admin load after the update (small, bounded)

0. **Census** (dry-run report, as `cdek:includes/setup/class-cdek-v1-census.php`): instances by type and `allowed_shipment_type`; orders with the v1 marker (a count query, exact); the exact row count of each Tier B phase (§14.2); orders with `_wc_russian_post_export_type = widget`; orders still `new` or with an error status; settings that hold values; whether `wc_russian_post_site_settings` is present. Shown on the migration screen (§12.2) and stored in the journal.
1. **Zone instances** (D8). Journal the plan, then through the zone-method store seam (WooCommerce has no API to change a row's `method_id`): `wc_russian_post_courier` → `russian_post_courier`. `wc_russian_post_postal` → `russian_post_pickup`, **one-to-one**, because a zone row cannot become two: v1's postal instance served office and postamat together. For each such instance the journal records whether `allowed_shipment_type` was one of the postamat-capable types (`COMBINED`, `ECOM_MARKETPLACE`, `ECOM`) or `any`; the migration screen then offers an action «Добавить способ «Постамат» в эти зоны» (the merchant's click, not automatic). Losing a zone method is acceptable (D8); each loss is listed. Instance ids and zone membership are unchanged.
2. **Instance settings** copied to the new option names: `title` (with `%parcel_type%` replaced by the product name), `min_price`/`max_price` → `min_cost`/`max_cost`, `free_cost`, `coupon_free_shipping`, `show_delivery_time`, `description_rate` → framework `description`, `shipping_class_id` (v1's comparison of a string to an int never matched a specific class, v1 flag #14 — so a stored specific class may now start to apply; the journal lists instances where it does), `allowed_payments`, `allowed_shipment_type` → `tariff` (see the mapping below). `allowed_shipment_type = any` has no single equivalent: the new instance gets the first usable product for its kind with a journal entry «выберите тариф», shown on the migration screen and as an admin notice until the merchant saves a choice (never silently wrong).
3. **WooCommerce settings that hold method ids** (gateway `enable_for_methods`) rewritten, originals in the journal (`cdek:` step 2 pattern).
4. **Global settings.** The main option stays; `minimum_weight`/`minimum_height`/`minimum_width`/`minimum_length` (500 g and 150 mm each) → framework default dimensions (grams and mm → store units, once, journalled) and `dadata_api_key` → the framework location setting, **both only where the shared destination is still empty**. The expected upgrade is a shop already on CDEK/Yandex v2, whose store-wide DaData token and default dimensions must not be overwritten (Russian Post's old 500 g / 150 mm would change parcel calculations for every carrier). The rule is CDEK's exactly: `cdek:includes/lifecycle/class-cdek-v1-migration.php::copy_dadata_keys()` and `copy_default_dimensions()` skip a populated destination, journal the step once, and `is_settled()` makes a replay a no-op. Units are converted only when a value is actually written; a different non-empty value is **shown on the migration screen as information** («в магазине уже задан ключ DaData / габариты — оставлены как есть») and never replaced. The wizard's steps obey the same rule (they prefill, they do not overwrite); `export_type` — if it was `widget`, the migration screen says export now needs Otpravka keys; `auto_export_orders` + `export_statuses` → `Order_Automation` (on only when the list was non-empty and keys are present); `vat_rate`; `enable_tracking` + `tracking_login`/`tracking_password` → **not carried** (SOAP credentials; the screen asks for the new tracking token); `tracking_complete` / `tracking_delivering` / `tracking_canceled` → framework statuses; `enable_debug_mode` → `Advanced_Settings::SETTING_ENABLE_DEBUG`; `hide_fields`, `map_button_place`, `auto_close_map`, the four suggestion toggles → dropped (the framework layer owns them); `senderZipCode` from the cached site settings, if present → the sender point picker preselect.
5. **Queue.** Cancel `wc_russian_post_orders_update`; the new poller schedules itself. An order whose v1 export left it `new` with an error is reported, not changed.
6. **Licence and flags.** The licence options are derived from the unchanged plugin id, so nothing is copied; the journal verifies they are present. Informational flag `wc_russian_post_upgraded_to_2_0_0` (never read as a guard), journal `wc_russian_post_upgrade_2_0_0`.
7. **Not touched:** order meta (Tier B), the v1 instance options (rollback), the guid options.

### 14.2 Tier B — the background order migration (D8): batched, restartable, visible

Runs on Action Scheduler (group `wc-russian-post-migration`), started by the merchant's «Начать перенос заказов» on the migration screen or automatically one hour after Tier A on a shop with fewer than 500 v1 orders. One recurring-until-done action `…_migrate_orders_batch`, **resumable by cursor**. The full rewrite of every old order is the operator's decision (D15).

- **Three phases, three cursor domains, no single `done/total`.** The shipping-item tables are shared by HPOS and posts storage, as `cdek:includes/lifecycle/class-cdek-lifecycle.php` already relies on. (1) `method_ids`: `wp_woocommerce_order_items` joined to `…_itemmeta` where `order_item_type = 'shipping'`, `meta_key = 'method_id'` and the value is a v1 id — ascending by `order_item_id`; (2) `rate_meta`: `…_itemmeta` rows with `meta_key = 'wc_russian_post_rate'` — ascending by `meta_id`; (3) `user_meta`: `usermeta` rows with `meta_key = 'wc_russian_post_customer_delivery_point'` — ascending by `umeta_id`. One order normally contributes a `method_ids` row *and* a `rate_meta` row, and customer rows belong to no order, so the phases are counted separately.
- **State** in one non-autoloaded option `woodev_wc_russian_post_order_migration`: `{ phases: { method_ids|rate_meta|user_meta: { cursor, total, scanned, converted, skipped, unconverted, scan_done, retry_done } }, errors_sample[], started_at, updated_at, lock }`. `total` per phase is the exact `COUNT(*)` of that phase's query, taken in the Tier A census (§14.1 step 0). A site-wide lock with a TTL (the CDEK lock pattern); a second run while locked exits.
- **A batch handles 200 rows or 10 seconds, whichever is first**, then reschedules itself 30 seconds later (100 rows per second is never needed; a slow host just takes longer).
- **Idempotent:** a row already converted (a plain array with no serialised object, or a `method_id` already new) is *skipped*, not failed.
- **A failure never stalls the scan.** A row that cannot be converted (a corrupt serialised value, a read-back mismatch) is left **untouched** in the database; the scan cursor advances past it *after* the failure is counted (`unconverted`), so one bad row cannot block the rest. Failed rows are not kept in a capped list: the **retry pass** re-scans the same phase from the start with the same "already converted → skip" predicate, which selects exactly the rows still in the v1 shape, so every failure is found again deterministically however many there are. `errors_sample[]` holds at most 100 reasons for display only.
- **Completion** of a phase = `scan_done` **and** its retry pass done with `unconverted = 0`. A row still unconverted after three retry passes is reported as «не удалось перенести: N» with a «Повторить» action that runs the retry pass again; the migration is complete when all three phases are. The notice shows «Перенесено X из Y» **per phase** (rows, not orders) and disappears only at completion with nothing unconverted.
- **Pause / resume** from the screen; deactivating the plugin leaves the cursors, reactivating resumes.
- **Removal:** the Tier A/B code is removed "a few versions later" (D8); its removal is a card filed with P18 and checks that `woodev_wc_russian_post_order_migration` reports every phase complete on the shops the telemetry can see — otherwise the code stays.

### 14.3 Tier B — what a row conversion does

**Shipping-item `method_id`** (D8): `wc_russian_post_courier` → `russian_post_courier`; `wc_russian_post_postal` → `russian_post_postamat` when the same item's `wc_russian_post_rate.delivery_point` says it was a postamat (point type `postamat` / a box size), else `russian_post_pickup`; a foreign destination (`delivery_point` empty and the order's shipping country ≠ `RU`) → `russian_post_international`. The rule is deterministic except for the postal split, so each rewritten line gets item meta `wc_russian_post_v1_method_id` with the original value (rollback and audit).

**Serialised objects read without loading the class.** `wc_russian_post_rate` and `wc_russian_post_customer_delivery_point` hold a PHP-serialised `Woodev\Russian_Post\Classes\Address_Normalize` (nineteen **private** properties: `id, area, building, corpus, hotel, house, index, letter, location, place, quality_code, region, room, street, validation_code, address_guid, place_guid, region_guid, street_guid`) and, in the rate, an API `stdClass`. After the update the class is gone, so `maybe_unserialize()` would give `__PHP_Incomplete_Class`. The migration therefore: (1) reads the **raw** `meta_value` string with `$wpdb` (never through `get_metadata`, which unserialises); (2) calls `unserialize( $raw, [ 'allowed_classes' => false ] )` — every object becomes `__PHP_Incomplete_Class`, which is also the object-injection defence: **no class is ever instantiated, and no `__wakeup`/`__destruct` can run**; (3) a recursive converter turns each incomplete object (including the `stdClass`) into an array by casting it, drops `__PHP_Incomplete_Class_Name`, and strips the mangled-name prefix of private properties (`"\0Woodev\Russian_Post\Classes\Address_Normalize\0place"` → `place`); (4) writes the result back as a plain array through `update_metadata_by_mid()` (item meta) / `update_user_meta()` (user meta), reads it back and compares the converted structure, and only then advances the cursor; (5) a value that is not valid serialised data (a corrupt row) is **left untouched** and recorded in `failed[]`, never replaced. Tests: a fixture of real v1 serialised strings (including a multibyte address and a null private property), a malformed string, an already-converted array, and an assertion that `allowed_classes => false` is always passed.

**What the v2 reader consumes** (finding: converting an object to an array does not by itself create the normalized point). New orders carry the v2 keys of §13, and **Tier B writes the same v2 keys for every old order**: it runs the raw-string converter above, then `Russian_Post_Legacy_Point_Normalizer` builds the v2 point (id, address, type postamat/office, box size → `dimension-type`, product) from the converted array and stores it under the §13 keys, with read-back before the cursor advances. `Russian_Post_Order_Reader` reads **only** the v2 keys; it has no v1 branch (D15). **Migrate on touch:** an old order the batch has not reached yet (a shop with 500+ orders where Tier B has not started) is converted the moment it is opened in the admin or a plugin action (export, label, cancel, refresh) is run on it — the action first calls the Tier B writer for that single order under the migration lock, then proceeds on v2 data; if the conversion fails the action is refused with the reason and the order goes to `failed[]`. This is the migration writer applied early, not a second read path, and it is removed together with the migration code. Tests: an HPOS **and** a posts-storage fixture of an unexported v1 locker order — migrate-on-touch then export, and batch-then-export, give identical v2 keys and export bodies.

**Old international orders** keep the stored numeric country codes verbatim (§9.6). **Widget-mode orders** (`_wc_russian_post_export_type = widget`) keep all their data and their provenance; whether they have a mirrored Otpravka record is unknown (U23), so cancel, label and refresh-by-id are refused by default and tracking by barcode still works (§13).

### 14.4 Precedent and what is new

Zone, gateway, settings, licence, queue, lock and journal logic are copies of CDEK/Yandex patterns and their tests are the model. **Rewriting old orders is new**: CDEK's migration only detects v1 shipping items (and writes no order meta) and Yandex keeps every `_yandex_delivery_*` key unchanged — decision 8's instruction "check how the CDEK migration handled old orders and reuse it" has the answer *it did not*, so Tier B has no reusable implementation, only the lifecycle scaffolding around it (lock, journal, read-back). The operator confirmed the full rewrite on 21.10.2026 (D15): no read-only legacy path, whatever the size.

## 15. Coexistence (D13)

Nothing is added to the plugin. Facts for the release notes (D, V): with CDEK v2 or Yandex v2 loaded first (the folder name `woodev-russian-post` sorts after `woocommerce-edostavka` and `woocommerce-yandex-delivery`), Russian Post v1 is quarantined — its methods vanish from checkout, tracking and auto-export stop, a red notice shows on admin screens only; Russian Post first, CDEK stays dormant. The notice says "update the old plugin" although no newer Russian Post exists until this release (card #1389 is about that notice). The v2 plugin brings the same framework bootstrap as CDEK/Yandex, so the three coexist under the framework's "newer version wins" rule and the five Yandex D9 scenarios apply unchanged. Acceptance (§16) runs the **three-plugin** variant of scenarios 1–5 once, because the Yandex acceptance only covered two carriers.

## 16. Testing and acceptance

### 16.1 Unit tests (Brain Monkey / Mockery, no network)

- **Points:** `[lng, lat]` order; quad-tree split and `totalEntries ≤ 200` termination; de-dup by id across tiles; the 6-attempt per-fetch budget and nearest-first truncation; the tile cache keyed by upstream type set (an office tile and a postamat tile of one cell never collide and never share a lock), bounded by the 150-tile cap across all type sets with LRU eviction; the details cache stores the raw record and the COD verdict is applied per instance after the cache (two instances with different products get different verdicts from one cached record); negative details not cached; a real closed detail payload → `fetch_details()` returns `null` and never throws; a cached open record followed by a checkout-purpose call that answers closed → `null` and the cache refreshed; a failing fresh call with a ≤ 10-minute-old cached record uses it, with an older one throws; the unified widget budget (tile, retry, detail miss, index lookup and confirmation each counted *before* sending; cap, reserved confirmation slice, burst limiter) and the breaker (open → half-open probe → close, doubled cooldown on a failed probe); COD per type; cells: S = 26×17×8 cm after the mm → cm conversion, and a parcel larger than that is refused by `Constraint_Checker`; schedule mapper (lunch break is not a second interval, `holidays` array); `pvzType` explicit per method; the existing fixture's six defects as regressions.
- **Prices:** tariff body per method and product; `total-rate + total-vat`; `delivery-time` with absent `min-days`; HTTP 400 business error on the public calculator parsed as a refusal; `service=28` on the international fallback only; no `dogovor`; cache key variation (credentials fingerprint, sender, product, index, mass, declared value, COD); quota counter atomicity, 70 % tier, reset, backoff; the public calculator's own counter and breaker with recovery; worst-case table asserted from constants.
- **Catalogue:** per-kind probe fixtures (office index, a postamat with its required point fields, an open country); a postamat-only product (23080) is usable when the postamat probe passes even though a city-office probe returns 2006; "probe route unavailable" never disables a product; a product usable only with a positive price **and** empty `errors[]` (53030 → 200 + 1204 must read as unusable); the account flag off disables a product that prices.
- **Export:** body parity per method against recorded fixtures; `mail-category` always present; 0 % VAT and zero amounts sent; kopecks without float loss (19.99 ₽); postamat uses `ecom-data` + `dimension-type`; courier carries no `courier` flag; partial-success `errors[]` parsed; barcode and `result-id` extracted; `supports_reconcile()` is `false` and a timed-out export stays unknown with the automatic export stopped; `update()` sends no request body and makes no `PUT` / `POST` (a source scan plus a behaviour test); cancel never calls `DELETE /1.0/shipment` (a source scan) and clears the barcode; no cache trait on writes.
- **Address:** usable/unusable codes (all 12 G samples); a failed `clean/address` yields the doubtful flag and never an exception; auto-export skips a doubtful order.
- **International:** country-code table has no duplicates and resolves every served country; the KZ/AM/KG no-declaration rule and the one-retry on `UNEXPECTED_CUSTOMS_ENTRIES`; description padding; transliteration; mass and size limits; closed country hides the method.
- **Tracking:** state selection from the two recorded fixtures — the response whose predicted `GIVING` (`future: true`, no date, no events) must **not** map to `delivered`, and its operation-46 event, which must map to `cancelled`; chronological sort independent of group order; the queue ordering; the daily cap; the age cap applies only to orders the merchant has completed, and a 61-day-old *active* order is still polled; with 6 000 active barcodes and a cap of 100 every barcode gets a first poll before any gets a second; 403 ×3 pauses; 429 pauses; no token → tracking off.
- **E-mail:** the Russian Post e-mail keeps its id, option and template path, renders a theme override, never writes the shared framework e-mail options, and does not re-send when the v1 sent flag equals the current barcode.
- **Secrets:** the sanitizer masks `Authorization` (both prefixes) and `X-User-Authorization`; the `/1.0/settings` body is not logged.
- **Data contract and migration:** the option/meta/method-id tests of §13 including the new v2 order keys; the serialised-object converter (fixture strings, corruption, idempotence, `allowed_classes => false`); an unexported v1 locker order in HPOS and posts storage through both supported routes — migrate-on-touch then export, and batch migration then export — asserting identical v2 keys and export bodies; the v2-only reader returns nothing for an unconverted order (no v1 branch, D15); the phase cursors with several shipping items per order, user-only rows, an early corrupt row followed by good rows (the cursor advances, the row is untouched and counted), more than 100 failures all re-found by the retry pass, and completion = scan done + retry done per phase; cursor resume after a simulated timeout mid-batch; zone rewrite with journal and rollback; `any` tariff → "выберите тариф"; a shop whose shared DaData token and default dimensions are already set keeps them (no overwrite, journalled once, conflict shown).

### 16.2 Integration (WordPress + WooCommerce, single-flight DB, never beside another suite)

Real plugin entry load through `Woodev_Loader` (the loader-definition gotcha: no framework class constant before load); zone-method persistence and the full v1 → v2 migration over a database fixture of a v1 shop (HPOS **and** posts storage); order-item rewrite on a few thousand synthetic orders to check batch time; classic and block checkout rate/pick/save with changed and unchanged packages and cities.

### 16.3 Stub server in CI

An in-repo HTTP stub that answers every endpoint the plugin uses (`/1.0/settings`, `/settings/limit`, `/user-shipping-points`, `/1.0/tariff`, `/1.0/clean/address`, `PUT /2.0/user/backlog`, `PUT|GET|DELETE /1.0/backlog…`, `/backlog/search`, `/shipment/search`, `/forms/…`, the public calculator and dictionaries, the widget `POST /api/pvz` and `GET /api/pvz/{id}`, the tracking endpoint) from recorded fixtures **including every refusal shape seen** (the 200+`errors[]`, 401 ×2, 407, 400, 500 NPE, 403 empty body, the 400 business error). **No live credentials in CI** (the CDEK rule).

### 16.4 On the test «Отправка» account (live, small load)

Allowed by the operator's standing rule for CDEK/Yandex/Russian Post test accounts: production-style checks and small volumes, no 500/100-volume runs. Never courier call, `checkin`, payment, or a physical hand-over. Allowed: `settings`, `limit`, `user-shipping-points`, `tariff`, `clean/address`, order create / edit / search / delete (created orders are deleted in the same run), a batch only when a card says so and then undone, Ф7 and international forms from a batched order, tracking with the operator's token (invalid-barcode probe and one real barcode). The account is Russian Post's own demo cabinet («ТЕСТОВЫЙ ЛК»): nothing proves real contract prices, `DELIVERY_PLUS`, COD fee or partner-point orders.

### 16.5 Rig acceptance

Buyer-visible rates and point selection in classic and block checkout (D10: whatever the framework gives); the postamat cell fit; the closed-point refusal; COD hidden for postamat; an international order to Germany and one to Kazakhstan; the doubtful-address flag; migration of a copy of a real v1 shop (or the fixture) with the progress notice; the three-plugin coexistence scenarios (§15).

### 16.6 CI gates

Plugin unit and integration, PHP style and static analysis, JS build and lint; `npm run lint:docs` in the framework repo for this spec.

**Acceptance of the release** = the above green, the migration run on a v1 shop copy with zero failed rows or each failure explained, and the unverified items marked "blocking" in §18.1 resolved or consciously accepted by the operator.

## 17. Out of scope for release 1

- Batches, Ф103, `checkin`, the all-documents zip, Export.xls/csv, easy-return label, courier call (D6).
- Partner ПВЗ and «Доставка плюс» (D3) — a deferred-design note only (§4.4), no code.
- An outbound edit of a carrier order (`PUT /1.0/backlog/{id}` is measured but not exposed; the merchant cancels and exports again, §8.3) and automatic reconcile of an unknown export (§8.5).
- Batch tracking (SOAP `/fc`), the SOAP single `rtm34` (§10), webhooks (none exist for Otpravka/tracking).
- Interval delivery / time slots, «Доставка по клику», business courier, easy return, letters and banderoles.
- The widget cabinet in any form, `cms.pochta.ru` registration, the `guid` pair (D1).
- Encryption of credentials at rest (D9), a fallback point source (D2), full passport download (D2).
- A buyer e-mail when the delivery date changes; the contract-price (`dogovor=`) path (unmeasured).
- Generalising the migration scaffolding into the framework (candidate card F2/G2, optional).

## 18. UNVERIFIED register, interpretations, questions

### 18.1 UNVERIFIED (not read in code or measured), with how to measure

| # | Item | How to measure | Blocks release? |
|---|---|---|---|
| U1 | Ф7 for a domestic order **not** in a batch (`forms/backlog/{id}/forms`) | create → call → delete on the test account (card R1) | Yes — decides §8.6 |
| U2 | Contract prices through `POST /1.0/tariff` and public `dogovor=` | a merchant with a real contract; compare with an invoice line | No — documented as unmeasured |
| U3 | `DELIVERY_PLUS` / ППВЗ / ordinary-OPS order create (`ecom-data`, `delivery-data`, `services`) | an account with `deliveryPlusAllowed` (card R6) | No — out of release 1 |
| U4 | Real tracking quota (5 000 vs 100), whether 4xx count, per-minute limit, whether a no-contract legal-entity cabinet differs | the operator's token, ≤ 20 calls (card R3) | No — default cap 100 |
| U5 | *(withdrawn: SOAP is not used, decision 7 amended 21.10.2026)* | — | — |
| U6 | Full list of high-level status group codes; `Language` header vs query param | a moving shipment, token (card R3) | No — unknown → `unknown` |
| U7 | `pochta.ru` find-from-rectangle as a source with closed flags in the listing | card R4 | No — D2 defers it |
| U8 | Otpravka quota reset time and the exhaustion HTTP code/shape | cross midnight MSK; exhaustion only on a throw-away account | No — handled by backoff on any quota-shaped error |
| U9 | Widget backend: real limit above 2 req/s, terms of use, redistribution | ask Russian Post | Accepted risk (D2) |
| U10 | Mass limits of the other domestic products and the volumetric rule/divisor | create orders one gram over the limit (card R2) | No — v1 rule carried |
| U11 | Official postamat cell table (S/M/L/XL) | the dictionary or a support answer | No — `Constraint_Checker` is permissive on unknowns |
| U12 | Goods/items list key and whether COD needs it; `payment-method` for COD | official order-v2 page via the L JSON API + test create (card R7) | Yes for COD — verify before the export card |
| U13 | `dimension` unit and fields in the order body (v1 sent `ceil` of packer output; the unit is not established) | spec page + test | No |
| U14 | ТН ВЭД well-formed non-existing code; Armenia/Kyrgyzstan plain parcels; the full set of countries forbidding a declaration; Belarus COD currency; `ioss-code` rules | card R2 (cheap order creates) | No — retry rule covers |
| U15 | Whether a price varies between indexes of one city (representative index, §6.2) | tariff to 3 indexes of Moscow and Novosibirsk | No |
| U16 | Whether a method-id collision exists (`russian_post_*`) | grep wp.org plugin source (card R7) | No — rename a constant |
| U17 | Which v1 versions are in the field; the real number of widget-only shops | EDD data / support | No — decision 1 says unknown |
| U18 | Incidence of closed/temporarily-closed points in the widget feed | sample 300 ids over a day | No |
| U19 | Does the wizard bootstrap echo stored secrets (`#110` item 7) | card P3 test | Yes for the wizard card |
| U20 | Whether `Shipping_Method_Postal` fits the international method | read the base contract in card P5 | No |
| U21 | Public tracking page URL pattern | open a real barcode | No |
| U22 | Real surviving size of the order tables to migrate | the census count | No — batches handle any size |
| U23 | Whether an order exported through the widget cabinet is mirrored into «Отправка» (README §6 contradiction 4) | one widget-exported order on a real account, looked up by barcode | No — treated as unknown, safe refusal (§13) |
| U24 | Whether the Russian Post e-mail and the framework's shared `customer_shipment_created` both fire for one order, and whether a plugin can exclude its provider | rig, one order when the barcode appears (card P14b) | No — measured in P14b, proposed to the operator |
| U25 | A site-scoped export reference that `backlog/search` can match (e.g. a prefixed `order-num`), and whether a batched order stays findable by it | create / search / batch / delete on the test account (card R7) | No — reconcile stays off |

### 18.2 Interpretations the spec had to make (not new decisions)

- **I1** Method ids `russian_post_courier|pickup|postamat|international` (§4.1).
- **I2** v1 `postal` → `russian_post_pickup` one-to-one with an offered "add postamat" action (§14.1) — a zone row cannot split.
- **I3** The tile cache design (fixed grid, the upstream type set in the key, slim records, 10-minute TTL, 150-tile cap across type sets) reads decision 2's "short cache" together with the s58 gotcha (§5.4).
- **I4** Hard budgets: 6 widget attempts per viewport fetch, 2 000 widget attempts/day covering every automatic path (tiles, retries, details, index lookups, confirmation), 70 % quota tier for rates, public calculator 5 000/day, tracking default 100/day (§§5.3, 5.7, 6.5, 10.3).
- **I5** Representative destination index through the widget source (§6.2).
- **I6** Auto-export skips a doubtful address; manual proceeds (§7).
- **I7** `supports_reconcile()` stays `false` in release 1; an unknown export waits for the merchant (§8.5).
- **I8** `cancel()` never un-batches; it refuses with a message (§8.4).
- **I9** Migration screen server-rendered with a polled status (§12.2).
- **I10** Tracking link template `https://www.pochta.ru/tracking#{tracking}` (§10.4).
- **I11** The international method is a fourth method class (§9.1) although decision 3 names three kinds; decision 11 puts international in release 1.
- **I12** The closed-point verdict is `null` from `fetch_details()` plus a fresh check at checkout (§5.5).
- **I13** `update()` is a read-only tracking pull; no outbound edit in release 1 (§8.3).
- **I14** Per-kind tariff probe fixtures and a three-outcome probe (§4.3).
- **I15** A Russian Post-only tracking e-mail instead of the framework's shared ones (§10.4).
- **I16** Migration progress is counted per phase over rows with a re-scan retry pass (§14.2).

### 18.3 Questions only the operator can answer

None open. Decided by the coordinator on 21.10.2026 (reversible, no operator input needed):

- **Q2** Method ids → `russian_post_courier`, `russian_post_pickup`, `russian_post_postamat`, `russian_post_international`. They
  carry the plugin's own prefix and differ from v1's `wc_russian_post_courier` / `wc_russian_post_postal`, which D8 requires.
- **Q4** Doubtful address → auto-export **holds** the order and shows the reason on the order; the manager can still send it by
  hand (admin trusts the merchant). Nothing doubtful leaves automatically.

**Closed since the first draft (no longer questions):** Q1 tracking transport → REST by token, decision 7 amended (§0); Q3 label outside a batch → measure first, design both paths (D14); Q5 old-order rewrite → full migration, no read-only legacy (D15); Q6 widget backend as the only source → risk accepted (D16). The numbers are kept so earlier references stay readable.

## 19. Card decomposition (proposed; not filed)

Repo codes: **Ф** = framework (`kalbac/woodev-plugin-framework`); **П** = the Russian Post plugin repository: the EXISTING private repo `kalbac/woodev-russian-post` (default branch `master`, last push 16.07.2022, older than v1 1.2.6.0). v2 is built on a new `v2` branch there, as CDEK does (`--base-branch v2` for plugin worktrees); P1 first commits v1 1.2.6.0 from `plugins-reference/` to `master` so history starts from the shipped code. The plugin folder and main file stay `woodev-russian-post` / `woodev-russian-post.php`, or EDD updates break. «≤400» = one worker, about 400 changed lines or fewer. Titles are Russian (card titles go to the backlog). R-cards are measurement only, cheap, safe for Haiku with a detailed brief; they never exceed the quota rule of §16.4.

| # | Repo | Title | Scope | Depends on |
|---|---|---|---|---|
| R1 | П | Замер: Ф7 для заказа вне партии | Создать заказ на тестовом ЛК, вызвать `forms/backlog/{id}/forms` (внутренний заказ), удалить; результат в `docs-internal/research`. Решает §8.6. | — |
| R2 | П | Замер: пределы масс и ТН ВЭД | Пределы массы у PARCEL_CLASS_1, POSTAL_PARCEL, ONLINE_COURIER; делитель объёмного веса; ТН ВЭД с несуществующим кодом; посылка в Армению и Киргизию без декларации. Заказы удалять в том же запуске. | — |
| R3 | П | Замер: трекинг по токену | ≤ 20 запросов: фактический лимит, считаются ли 4xx, коды групп статусов, `Language`. Нужен токен оператора. | — |
| R4 | П | Замер: `find-from-rectangle` как источник точек | Сравнить с виджетом по Москве и области: закрытые точки в списке, полнота, куки, лимиты. Только чтение. | — |
| R5 | П | Замер: где берётся комиссия наложенного платежа | Только если появится аккаунт с договором; иначе остаётся в реестре. | реальный договор |
| R6 | П | Замер: «Доставка плюс» и ПВЗ партнёров | Создание заказа на аккаунте с `deliveryPlusAllowed`. | аккаунт с флагом |
| R7 | П | Замер: тело заказа v2 и коллизия id | Ключ списка товаров, `payment-method` при наложенном платеже, единицы `dimension` (читать страницу описания через JSON API из отчёта L и пробный заказ); поиск `russian_post_` в коде плагинов wp.org; находится ли заказ по префиксованному `order-num` в «Новых» и после партии (U25). | — |
| F1 | Ф | Источник точек сообщает, что вид слишком крупный | Поле `truncated` в ответе точек и подсказка на карте «приблизьте карту» (G1). Не блокирует релиз. ≤400 | — |
| F2 | Ф | Общая основа миграции v1→v2 | Вынести хранилище строк зон, чтение опций, журнал и блокировку из плагинов (G2). Необязательная: только после выхода плагина и если третья копия покажет пользу; в первую волну не входит. ≤400 | выход плагина |
| P1 | П | Каркас плагина 2.0.0 в существующем репозитории (ветка v2) | Репозиторий, загрузчик, пространство имён, закрепление фреймворка, id `wc_russian_post`, EDD 8772, лицензия, CI, пустая группа настроек. ≤400 | — |
| P2 | П | Контракт данных: тесты и чек-лист | Три контрактных теста (опции, мета заказа включая новые ключи v2, таблица старых → новых id) и файл `docs-internal/migration/russian-post-data-preservation-checklist.md` из §13. ≤300 | P1 |
| P3 | П | Подключение: поля, маскирование, проверка | Группа «Подключение» (пароли, `sensitive`), `Woodev_Settings_Connection_Test`, `X-User-Authorization` в списке секретов, тест санитайзера, проверка `#110` п.7 для мастера. ≤400 | P1 |
| P4 | П | API-клиент «Отправки» и формы ошибок | Клиенты четырёх сервисов §11.2, формы ошибок, маскирование секретов, без кэша на записи. ≤400 | P3 |
| P4b | П | Счётчики квот, единый бюджет виджета, предохранитель | Счётчик квоты «Отправки» и ярусы §6.5, суточный счётчик и предохранитель публичного калькулятора, единый бюджет всех обращений к виджету с резервом под подтверждение, ограничитель всплесков и предохранитель с возвратом (§5.7). ≤400 | P4 |
| P4c | П | HTTP-заглушка для CI | Заглушка всех конечных точек из записанных фикстур, включая каждую форму отказа §16.3. ≤300 | P4 |
| P5 | П | Каталог тарифов и способы доставки | `Russian_Post_Tariff_Catalog`, четыре класса способов, поле «Тариф», извлечение `/1.0/settings` (компактное), проверка «цена и нет ошибки» по видам (§4.3: офис, постамат, страна; три исхода), причины недоступности, `report_hidden`. ≤400 | P4 |
| P6 | П | Цена: тариф, запасной калькулятор, кэш | `POST /1.0/tariff`, публичный запасной путь с разбором HTTP 400, ключ кэша, зависимость от оплаты, «показать только то, что можно». ≤400 | P5 |
| P7 | П | Адаптер места и индекс назначения | `Russian_Post_Location_Adapter`, репрезентативный индекс через плитку точек (контракт источника из P8, обращения идут через единый бюджет P4b), кэш 7 суток / 1 час. ≤300 | P8, P4b |
| P8 | П | Источник точек: плитки и кэш | `Russian_Post_Widget_Point_Source`, квадродерево, дедупликация, бюджет вызовов, кэш по ячейкам с потолком (ключ включает набор типов), все обращения только через единый бюджет P4b. ≤400 | P4, P4b |
| P9 | П | Карточка точки: закрыта, налож. платёж, ячейки | `fetch_details` (сырая запись в кэше, `null` для закрытой точки, свежая проверка при подтверждении), налож. платёж по типу после кэша, ячейки постамата в сантиметрах, расписание без ошибок фикстуры. ≤400 | P8 |
| P10 | П | Адрес при выгрузке и флаг «адрес сомнительный» | Один очиститель адреса с типизированным результатом, коды, флаг и заметка, пропуск при автовыгрузке. ≤300 | P4 |
| P11 | П | Выгрузка заказа | Обработчик отправления, тело по способам, деньги в копейках, штрихкод в метаданные, частичный успех, защита от дублей (замок и отпечаток, без автосверки §8.5), новые ключи заказа и `Russian_Post_Order_Reader` — читает только ключи v2, без ветки v1, отмена только из «Новых». ≤400; делить на тело и жизненный цикл, если больше | P5, P10, R7 |
| P12 | П | Документы: Ф7 | Источник документов, печать по результату R1 (ветка «из Новых» или «только в партии»). ≤300 | P11, R1 |
| P13a | П | Международные отправления: метод, словарь, цены | Метод, словарь стран, ISO-коды, доступность по стране и аккаунту, цены (запасной путь только с `service=28`), пределы масс и размеров, подсказка «латиницей». ≤400 | P6, R2 |
| P13b | П | Международные отправления: заказ, декларация, формы | Тело заказа, таможенная декларация и правило KZ/AM/KG, транслитерация, формы CN22/CN23 после партии. ≤400 | P13a, P11, R2 |
| P14 | П | Отслеживание по токену: клиент и очередь | Клиент, очередь и суточный предел, `update()` как чтение, режим «отслеживание выключено», страница заказов. ≤400 | P11, R3 |
| P14b | П | Отслеживание: выбор состояния и письмо | Выбор состояния §10.2 (фактические события, переопределения операций, две записанные фикстуры в плагине), письмо «Отслеживание» только для этого перевозчика §10.4 и замер U24. ≤400 | P14 |
| P15 | П | Мастер настройки | Пять шагов D12, действия проверки, ветка переноса (экран v1). ≤400 | P3, P5 |
| P16 | П | Перенос v1→v2, часть A: зоны, настройки, очередь | Перепись, зоны, копирование опций, шлюзы, отмена планировщика, журнал и блокировка. ≤400; делить как миграцию СДЭК | P5, P2 |
| P17a | П | Перенос v1→v2, часть B1: конвертер заказов | Чтение сериализованных объектов без классов, рекурсивное преобразование, нормализатор старой точки, запись ключей v2 пакетом и «перенос при обращении» к одному заказу (§14.3); читатель — в P11, фикстуры HPOS и posts. ≤400 | P16 |
| P17b | П | Перенос v1→v2, часть B2: исполнитель | Три фазы с курсорами, пакеты 200 строк / 10 с, повторный проход, прогресс по фазам, переписывание id и мета. ≤400 | P17a |
| P18 | П | Экран переноса и удаление кода переноса | Экран переноса с прогрессом и подсказкой «добавить постамат»; карточка на удаление кода через несколько версий. ≤300 | P15, P17b |
| P19 | П | Приёмка на тестовом аккаунте и в заглушке | Прогон §16.4 и §16.3: заказ, Ф7 по результату R1, международные формы, отслеживание, записи результатов. ≤300 | P11, P12, P13b, P14b, P4c |
| P19b | П | Приёмка на риге: перенос, точки, международные | Владелец проверок §16.5: перенос копии v1-магазина с прогрессом (P17b, P18), закрытая точка, наложенный платёж и ячейки (P9), международные заказы в Германию и Казахстан (P13b), метки и письмо (P14b). ≤300 | P9, P13b, P14b, P15, P18, P19 |
| P20 | П | Приёмка совместной работы с СДЭК и Яндексом | Пять сценариев D9 Яндекса для трёх плагинов на риге. | P19b, оба плагина на v2 |

### Parallelism (disjoint files)

- **Wave 0, all at once:** R1, R2, R3, R4, R7 (read-only measurements), P1. F1 may run alongside. F2 is not in the first wave: it runs after the plugin ships, if at all.
- **Wave 1:** P2 ∥ P3 (after P1).
- **Wave 2:** P4 alone (the client everything else uses), then P4b ∥ P4c.
- **Wave 3:** P5, P8, P10 (disjoint: catalogue/methods, points, address) — name file ownership in each brief; P5 and P8 both touch the plugin class wiring and must be serialised there.
- **Wave 4:** P6 (after P5), P7 (after P8 and P4b — it consumes the tile source), P9 (after P8), P15 (after P3 and P5).
- **Wave 5:** P11 (after P5, P10, R7), P16 (after P5, P2).
- **Wave 6:** P12, P13a then P13b, P14 then P14b (after P11 and their R-cards), P17a then P17b (after P16).
- **Wave 7:** P18, then P19 (join over P11, P12, P13b, P14b, P4c) and P19b (join over P9, P13b, P14b, P15, P18, P19), then P20.
- **Release gate:** the plugin is not called accepted until P19, P19b and P20 are all green; P19b owns the rig migration, the closed-point and COD checks and the international acceptance, so no release-1 area is accepted untested.

Rounds: at most 2–3 per card; a card that needs a fourth is decomposed (CLAUDE.md). The riskiest cards are P8 (point source: tiling, cache bound, budget) and P17b (the order migration at scale); both start with a spike allowed to change the card's scope before code. Size the wave by machine (the Mac has no RAM cap; the 5-hour rate limit is the brake).

## Related

- [Yandex v2 plugin design](2026-10-12-yandex-v2-plugin-design.md) — the format and depth model, and the closest sibling (points, offers, migration, poller).
- [CDEK v2 plugin design](2026-10-06-cdek-v2-plugin-design.md) — the first v2 carrier and the migration pattern this spec reuses.
- [Setup wizard v2 contract](2026-10-08-setup-wizard-v2-contract.md) — the wizard engine the short wizard and the migration screen run on.
- [Russian Post v2 fact base](../research/2026-10-21-russian-post-v2/README.md) — the measurements every section cites; [decisions](../research/2026-10-21-russian-post-v2/decisions.md) are the binding input.
- [International measurement (K)](../research/2026-10-21-russian-post-v2/k-international.md), [live Otpravka measurement (G)](../research/2026-10-21-russian-post-v2/g-otpravka-live.md), [widget backend (F)](../research/2026-10-21-russian-post-v2/f-widget-api.md), [passport import (I)](../research/2026-10-21-russian-post-v2/i-php-import.md), [verification (V)](../research/2026-10-21-russian-post-v2/v-verify.md) — the reports behind the facts.
- [Clean-break policy](../adr/005-platform-v2-clean-break-policy.md) — code may break, installed-site data may not.
- [A per-viewport cache is unbounded by construction](../gotchas/per-viewport-cache-is-unbounded-by-construction.md) — the rule §5.4 follows.
- [Framework architecture](../wiki/architecture.md) — where each shipping responsibility lives.
