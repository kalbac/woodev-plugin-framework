# Russian Post on framework v2 — consolidated fact base

> **Measured 21.10.2026 (s191, overnight).** Eight reports (A–G plus an independent verification V) feed this file. It is the
> single place to read what Russian Post offers today, what the v1 plugin does, what was measured live and how, what is still
> unverified, and which questions are open. **Where V contradicts a report, V wins** (it re-measured); every correction is carried
> here. The operator-facing brainstorm built on it is [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) (Russian).

**Context.** The next plugin is Russian Post on framework v2, possibly a full redesign rather than a port. The operator's hypotheses
were: drop the «Виджет» cabinet (two cabinets confuse merchants; the widget tariff gives ONE tariff, the customer cannot choose); build
a point list for our own map from a source without the widget; `tariff.pochta.ru` is Russian Post's reference calculator. The
«Отправка» account used for live measurement is a **test cabinet Russian Post staff created for the operator** (agreement
«ТЕСТОВЫЙ ЛК»), so nothing measured on it says anything about a real merchant contract.

**Conventions.** Amounts are kopecks where stated. **CONFIRMED** = re-measured independently by V. **MEASURED** = measured once by
the original researcher. **READ** = from code or official pages, nothing executed. **UNVERIFIED** = not measured; the reason is given.
Credentials, account ids and local paths are replaced by `<redacted>` in the copied reports; raw response directories are not in the
repo.

## Reports

| File | Task | One line |
|---|---|---|
| [a-v1-inventory.md](a-v1-inventory.md) | A | v1 1.2.6.0 feature map, installed-site data contracts, 30 code red flags, mapping onto framework v2 (READ) |
| [b-api.md](b-api.md) | B | every Russian Post API surface, auth, limits, products, 2022–2026 changes (official pages, READ) |
| [c-market.md](c-market.md) | C | competing integrations, feature matrix, merchant complaints |
| [d-coexistence.md](d-coexistence.md) | D | CDEK v2 beside Russian Post v1: bootstrap guards, collisions |
| [e-no-widget.md](e-no-widget.md) | E | can the plugin drop the widget cabinet — point sources, tariffs, what is lost |
| [f-widget-api.md](f-widget-api.md) | F | the widget backend as a point source, 1 710 anonymous requests |
| [g-otpravka-live.md](g-otpravka-live.md) | G | live run on the test «Отправка» account: quota, passport, tariffs, order lifecycle |
| [v-verify.md](v-verify.md) | V | independent re-measurement of the key claims, 251 requests, nine contradictions |

## 1. Summary

1. **The widget cabinet can be dropped for map, price and order creation.** Points come from the official passport dump (one request,
   35 971 points incl. all 812 partner points), prices from the public calculator or `POST /1.0/tariff` (equal to the kopeck on every
   pair V tested), orders from `PUT /2.0/user/backlog`. The widget account id (`guid`) cannot be derived from the Otpravka credentials.
2. **The widget backend is a usable but unofficial second source** (36 163 points, 99.314 % overlap with the passport). Naive
   pagination loses about 10 % of the points; only tiling into ≤200-row boxes is complete.
3. **The product list moved.** ЕКОМ 53030/53070 cannot be tariffed since 01.01.2025 (the call returns HTTP 200 with business error
   1204). Pickup goes through 54020 («Доставка плюс с объявленной ценностью») for postamats AND partner points, and through the
   postamat-only «Посылка онлайн комбинированная» 23080/23090. 54020 is **not** the only postamat product.
4. **Quota is 10 000 requests/day on the test account and every request counts one unit**, including 4xx, the passport download and
   the limit poll itself. The displayed counter lags by minutes.
5. **Contract pricing is not measured.** The test cabinet gives public prices; `dogovor=` with a fake contract is silently ignored.
6. **v1 has two confirmed live-site bugs** (24 h cache on PUT/DELETE; uncaught `TypeError` when `clean/address` fails) and several
   security defects (see §3).
7. **A real CDEK 2.3.0.4 (framework 2.0.7) beside Russian Post 1.2.6.0 (framework 1.3.3) never fatals**, but exactly one of them
   runs; no newer Russian Post exists, so the notice sends the merchant into a dead end until Russian Post v2 ships.

## 2. What Russian Post offers today

Read on 21.10.2026 from the official pages and specifications (B), plus V's live checks.

### 2.1 Surfaces and who can use them

| Surface | Base | Auth | Limits | Notes |
|---|---|---|---|---|
| «Отправка» REST API | `otpravka-api.pochta.ru` | header `Authorization: AccessToken <app token>` + `X-User-Authorization: Basic base64(login:password)` of the passport account | **10 000 requests/day** on the test account (`GET /1.0/settings/limit`); other accounts may differ (an InSales app documents 200 for new clients — C, secondary) | the only way to create shipments, batches, documents programmatically; **no sandbox**; needs an offer/contract and API activation |
| Public tariff + delivery-time calculator | `tariff.pochta.ru/v2/calculate/tariff[/delivery]` | none | none documented; sets a session cookie | doc v3.21 of 12.10.2026; contract rates via `dogovor=<ИНН>-<№>` (**unverified**, §5) |
| Tariff inside Otpravka | `POST /1.0/tariff` | as Otpravka | counts toward quota | needs `index-from` in the body despite the spec; kopecks, `total-rate` without VAT, `total-vat` separate |
| Office/postamat search in Otpravka | `/postoffice/1.0/…` | as Otpravka | counts toward quota | `nearby` (radius), `nearby.details`, per-index, `unloading-passport/zip` (bulk) |
| Address / name / phone normalisation | `POST /1.0/clean/{address,physical,phone}` | as Otpravka | 12 addresses in one request = one quota unit | quality and validation codes, not pass/fail |
| Tracking | SOAP 1.2 `tracking.russianpost.ru/rtm34` (single) and `/fc` (batch ≤3 000, contract only) | separate login/password from tracking.pochta.ru | **100 requests/day without contract** | no REST successor found; REST tracking returns 407/400 on the test account |
| Pickup widget + CMS cabinet | `widget.pochta.ru`, `cms.pochta.ru` | numeric widget id, or a `guid` from `cms install`; the cabinet binds to a person through an interactive Pochta-ID OAuth login | none seen | backends undocumented; `widget.js` is only an iframe loader |
| Address suggest + validate (paid service) | `pochta.ru/business/adressapi` | own contract | unknown | separate product, 1-month free test for new clients |
| Webhooks | none for Otpravka/tracking | – | – | **scope matters** (contradiction 9): the CMS cabinet does call back into v1 (`wc-api=russian_post_barcode`, `russian_post_status`) |

### 2.2 Products that are alive (V and G measured; E for the rest)

| Product | Otpravka `mail-type` | Public object | Status |
|---|---|---|---|
| Посылка онлайн | `ONLINE_PARCEL` ORDINARY / WITH_DECLARED_VALUE / …CASH_ON_DELIVERY | 23030 | alive; to an address; **not** to postamat or partner point (error) |
| Посылка 1-го класса | `PARCEL_CLASS_1` | 47030 | alive |
| EMS | `EMS` | 7030 | alive |
| Посылка стандарт | `POSTAL_PARCEL` | 27030 | priced **only by Otpravka**; the public calculator refuses it when the origin is not in the acceptance list |
| Курьер онлайн | `ONLINE_COURIER` | 24030 | alive only where the destination has a courier unit (Novosibirsk: error; Saint Petersburg: 407 ₽); `courier`/`delivery-to-door` flags are rejected on order create |
| Посылка онлайн комбинированная | `ONLINE_PARCEL` with `COMBINED_*` categories | 23080 / 23090 | **postamat-only**; V: 23080 priced for a postamat, errors for a partner point |
| Доставка плюс с объявленной ценностью (= ЕКОМ Маркетплейс with declared value) | `ECOM_MARKETPLACE` / `DELIVERY_PLUS`, WITH_DECLARED_VALUE | 54020 | priced for postamat, partner point and ordinary office at the door price; V: works for postamat 918594 and partner 980660; **order create is rejected on the test account** (account flags off) |
| ЕКОМ | `ECOM` | 53030 / 53070 | **retired for tariffing since 01.01.2025** (HTTP 200 + business error 1204); order create with 53070: `DISABLED_MAIL_TYPE` |
| Business courier, international, easy return, letters | various | – | not measured live |

Other dated facts (B, official or press): VAT is **22 % since 01.01.2026**; the tariff API doc is **v3.21 of 12.10.2026**;
«Доставка по клику» launched **01.07.2026** in 120+ cities (API mapping unverified); the pochta.ru e-commerce page claims 7 500
postamats (the passport lists 3 396, the widget 3 439).

### 2.3 The market (C, with V's correction)

Among the products checked **no maintained Russian-Post-first WooCommerce plugin was verified**; this is not a market-wide negative
(contradiction 5). Facts: the free wp.org plugin is at 1.3.8 / 25.10.2021 / 1 000 installs; its COD companion 1.4 / 24.12.2017 / 70;
the Dokan niche plugin 1.0.4 / 14.12.2022 / 0; the aggregator `eshoplogisticru` 3.1.64 / 20.10.2026 / 100 installs is actively
maintained (SaaS 2 000–5 000 ₽/month, claims block-checkout support). Saphali's update date and every commercial plugin outside
wp.org were **not** established. Merchant complaints cluster on price mismatch against pochta.ru, a missing pickup map, COD priced
badly, abandoned plugins and SaaS quotas. Other platforms treat as table stakes: several tariff sources, a pickup map, declared-value
and COD handling, markup rules, order export with batches and forms, tracking with status mapping.

## 3. What v1 (1.2.6.0, framework 1.3.3) does

READ in A, nothing run there; V re-ran the two items marked CONFIRMED.

- **Three Russian Post services, three auth schemes, one client class.** Rates come from the widget service
  (`POST /api/pvz/courier_tariff_public`, `/pick_up_tariff_public`) keyed by the cabinet `guid`, **not** from Otpravka; those
  endpoints return HTTP 422/500 without a real guid. Address cleaning, order export and the limit probe use Otpravka. Tracking is SOAP.
- **Two export modes** («Otpravka» → `PUT /2.0/user/backlog`; «Widget» → `cms.pochta.ru/api/cms/orders_public`). v1 has **no**
  label, document, batch or courier-call code at all; the merchant goes to the Russian Post dashboard.
- **Confirmed defects.** (a) **24 h cache on PUT/DELETE/GET order calls** (CONFIRMED): two identical PUTs → one network call, two
  identical DELETEs → one call, TTL 86 400 s. The CMS bypass skips cache **reads** only; the response is still stored (contradiction 6).
  (b) **A failed `clean/address` becomes an uncaught `TypeError`** (CONFIRMED): `get_objects()` swallows the API exception and
  returns `null`, the constructor requires an array, the `Error` escapes `catch (Exception)`. Five copies of that block exist.
- **Other defects by reading:** money truncated with `intval(...) * 100`; `array_filter` strips legitimate zeros (0 % VAT);
  barcode not cleared on cancel; the shipping-class filter never matches a specific class; variation weight from the parent;
  eight duplicated numeric country codes; reflected XSS in the orders page; an open `nopriv` AJAX proxy to Otpravka with the
  merchant's credentials; webhook secret compared with `!==`; `uninstall.php` calls `WC()`; the browser trusts point id/type from
  `$_POST`.
- **Secrets.** Password, token and tracking password are plain `text` inputs; with debug on, `X-User-Authorization`
  (base64 of login:password) is logged in clear; the DaData token is printed into page JavaScript; the dashboard link carrying
  `guid_id` + `guid_key` is e-mailed to the admin.
- **Pickup map.** Russian Post's own widget in a modal via `ecomStartWidget`; only the classic checkout (`blocks` false).
- **CDEK bridge is dead on CDEK v2** (constant and every `wc_edostavka_*` symbol it needs are gone; the location work now belongs to
  the framework's shared layer), so a v2 plugin needs no CDEK-specific code.
- **Settings in two places.** Some values live in the Russian Post cabinet (`senderZipCode`, fragile, SMS, `useCartValue`,
  shipment priorities), cached 24 h; the merchant keeps two configurations in sync.

### 3.1 Installed-site contracts (keys to keep or migrate)

ADR-005 lets the platform break APIs but not installed data. Plugin id `wc_russian_post`, text domain `woocommerce-russian-post`,
EDD download id `8772`.

| Kind | Value |
|---|---|
| Options | `woocommerce_wc_russian_post_settings`; `woocommerce_wc_russian_post_courier_{instance}_settings`, `…_postal_{instance}_settings`; `wc_russian_post_guid_id`, `wc_russian_post_guid_key`; framework licence options `woodev_wc_russian_post_*` |
| Shipping method ids | `wc_russian_post_courier`, `wc_russian_post_postal`; rate ids `…:{instance}` |
| Order meta | `_wc_russian_post_is_russian_post`, `_status`, `_export_type`, `_tracking_number`, `_tracking_number_email_sent`, `_russian_post_id`, `_latest_order_update_time`, `_tracking_history` |
| Shipping-item meta | `wc_russian_post_rate` — array holding a **serialised `Address_Normalize`** and an API `stdClass` |
| User meta | `wc_russian_post_customer_delivery_point` — also a serialised `Address_Normalize` |
| Product meta | `_tnved_code` |
| Cron / endpoints | Action Scheduler `wc_russian_post_orders_update` (12 h); `?wc-api=russian_post_barcode`, `?wc-api=russian_post_status`; admin page `wc_russian_post_orders` |
| E-mail | WC email id `wc_russian_post_tracking_email` (option `woocommerce_wc_russian_post_tracking_email_settings`) |
| Public hooks | ~30 filters `wc_russian_post_*` and actions, including two typos baked into the API (`wc_russian_mail_category`, `…_changed_from_{a}_to{b}`) |

Reading a serialised object whose class is gone yields `__PHP_Incomplete_Class`; either keep the class loadable or migrate those
values to plain arrays. Details and file:line in [a-v1-inventory.md](a-v1-inventory.md) §2.

## 4. What was measured live, and how

All on 21.10.2026 (MSK). F and V anonymous; G and V's authenticated part on the test «Отправка» account. Writes were allowed on the
test account only (13 orders created and deleted, one batch created and emptied, **one `cms install`** that left an anonymous
registration on Russian Post's side — see §7; no courier call, no hand-over, no payment, no `checkin`).

### 4.1 The test account (G)

Russian Post's own demo cabinet: organisation ФГУП «Почта России», agreement «ТЕСТОВЫЙ ЛК» (CONTRACT, 27.05.2019), `api_enabled`,
27 shipping points (intake offices), 17 mail types, 62 product triples. Which products exist **depends on the intake office** (109012
Moscow: 58 product types; Novorossiysk: 1). Account flags off: `deliveryPlusAllowed`, `deliveryToMarketplaceAllowed`,
`deliveryToDoorAllowed`, `postpaymentAllowed`, `partialRedemptionAllowed`. ЕСПП code present, so COD is not blocked by the missing-ЕСПП
error. `GET /1.0/settings` is 238 KB — cache it.

### 4.2 Quota (G, CONFIRMED by V)

`GET /1.0/settings/limit` → `allowed-count` **10 000**, `current-count`. One unit per request for GET and POST, 2xx and 4xx, passport
zip, postoffice, tariff, clean, and the limit poll itself. G's controlled experiment: 58 requests plus 3 polls moved the counter by
exactly 61. V: counter 352 → 374 (+22 = its 22 prior authenticated requests) → 379 (+5 further authenticated calls). The counter
**lags by minutes and updates in jumps**, so a plugin needs its own counter. Hundreds of anonymous widget and public-tariff requests
produced no jump. **Not measured:** the daily reset time, the exhaustion behaviour and the HTTP code of the quota error.

### 4.3 Points (G, F, V)

| Source | Rows | Detail |
|---|---|---|
| Passport `GET /1.0/unloading-passport/zip?type=ALL` (CONFIRMED) | **35 971** = 22 134 СОПС + 9 610 ГОПС + 19 ППС + 3 396 postamats + **812 ППВЗ**; 35 958 with coordinates (13 offices without) | one request, ~1.7 s, 5.9 MB zip / 54 MB single-line JSON; must send `Accept: application/octet-stream` (`application/json` → 406, still counted); nightly snapshot (20.10.2026 09:18 UTC); key = 6-digit `address.index`, unique |
| Widget backend `POST widget.pochta.ru/api/pvz` (CONFIRMED) | **36 163** = 31 912 offices + 3 439 postamats + 812 partners | anonymous; `settings_id` irrelevant; omitting `pvzType` returns all three types; `pageSize` capped at 200 |
| Sets compared (CONFIRMED) | intersection **35 915**, widget-only **248**, passport-only **56**, overlap 99.314 % | partner indexes identical (symmetric difference zero) |

- **Naive pagination is lossy** (F): one pass over the country at `pageSize 200` returned 36 143 rows but only 32 527 distinct ids
  (3 616 duplicates, so about 10 % never seen). **Correct coverage** is adaptive tiling so every tile has ≤200 rows: F's quad-tree
  took 630 requests (443 leaf tiles), two passes 30 minutes apart were identical; V independently obtained exactly 36 163 unique ids
  from 196 disjoint single-page longitude bands.
- **Why E said 36 143 and F 36 163:** the extra 20 points are **geographic coverage**, not churn — 19 points at negative longitude
  (Chukotka) and one below 41° N (contradiction 7).
- **The passport has no closed flag.** Temporarily closed points are simply absent; all six sampled widget-only points were
  `is-temporary-closed`. Closure data exists in `nearby` and `GET /postoffice/1.0/{index}` (a Moscow `nearby` sample: 41 of 100
  temporarily closed) and in the widget detail call.
- **Passport fields:** address with ГАР/ФИАС guids, coordinates (strings), 7 weekday strings, per-date holidays (offices only), `ecom`,
  `onlineParcel`, `ecomOptions` (weight limit, payment flags, brand, `getto` host text). Postamat box size per cell is **not** in the
  file (34 rows). ППВЗ payment flags are all false.
- **`nearby` returns ППВЗ and postamats with the default filter** (docs say only ГОПС/СОПС/ПОЧТОМАТ); `nearby.details` returns offices
  only; `filter` is required (`ALL`); `distance` is in metres, `search-radius` in km; `top=100` honoured, no `offset` on `nearby`.
- **Widget details** (`GET /api/pvz/{id}`, 30 samples): `brandName` «Почта Еком» for partners, `boxSize` XL for sampled postamats,
  `closed`/`temporaryClosed` false in all 30, structured `holidays` for offices only; unknown id returns HTTP 200 body `null`.
- **Risks of the widget backend (F):** unofficial and cabinet-internal, no SLA, CORS reflects any origin with credentials (almost
  certainly an oversight), no ETag, records rewritten nightly. No 429/captcha seen over 1 710 requests at ≤2 req/s; 0.35 % requests
  hung 30–60 s. The real limit above 2 req/s and the legal status of third-party use are unknown.
- **Sync cost:** passport — 1 request/day; widget tiling — 630 requests ≈ 5 minutes ≈ 45.8 MB per pass.

### 4.4 Tariffs (G, V)

V's nine independent pairs (origin 101000, 1 250 g, no declared value or COD), kopecks without / with VAT; Otpravka and the public
calculator are identical in every row:

| Destination | Product / object | Otpravka = public |
|---|---|---|
| 620000 Ekaterinburg | ONLINE_PARCEL / 23030 | 28 900 / 35 258 |
| 620000 | PARCEL_CLASS_1 / 47030 | 59 016 / 72 000 |
| 620000 | EMS / 7030 | 129 016 / 157 400 |
| 420000 Kazan | ONLINE_PARCEL / 23030 | 28 400 / 34 648 |
| 420000 | PARCEL_CLASS_1 / 47030 | 50 819 / 61 999 |
| 420000 | EMS / 7030 | 106 558 / 130 001 |
| 680000 Khabarovsk | ONLINE_PARCEL / 23030 | 71 100 / 86 742 |
| 680000 | PARCEL_CLASS_1 / 47030 | 99 508 / 121 400 |
| 680000 | EMS / 7030 | 183 525 / 223 901 |

G independently compared 15 tariffs from 109012 (1 kg): zero difference everywhere. This does **not** establish contract discounts, and
not equality for products outside the tested set (POSTAL_PARCEL is priced only by Otpravka).

- **Widget `free_tariff_by_settings`** (F): one tariff per call, `type` honoured for four types, origin = the site's own sender zip
  (cannot be set per merchant), +10/+30 kopecks against the public calculator. Not usable as a price source.
- **One tariff per call everywhere:** the public calculator and `POST /1.0/tariff` both price one product per call, so several options
  for the customer mean several calls (the public ones do not spend Otpravka quota).
- **Pickup products (V, CONFIRMED part).** For a 1 250 g probe with declared value 100 000 kopecks: **54020 works for postamat 918594
  and partner 980660** (17 900 kopecks before VAT); **23080 works for the postamat** (16 900) and errors for the partner with an explicit
  postamat-only message. 53030/53070 return HTTP 200 with error 1204. Price to a pickup point equals the door price of the route.
- **COD and declared value (G):** neither calculator expresses a COD fee — ONLINE_PARCEL COD = ground rate + insurance (1 % of declared
  value), identical to WITH_DECLARED_VALUE. Where the transfer fee is charged is unverified. COMBINED + COD fails
  (`ILLEGAL_MAIL_CATEGORY`).
- **Delivery time:** `delivery-time.min-days` is absent when min equals max.

### 4.5 Address normalisation (G)

Twelve test addresses in one request cost one unit. Seven were usable under v1's rule (quality in GOOD / POSTAL_BOX / ON_DEMAND /
UNDEF_05 and validation in VALIDATED / OVERRIDDEN / CONFIRMED_MANUALLY): a typo («Санкт-Петербруг»), abbreviations and postcode-only input
were corrected. **City-only input returns an `index` — a wrong one (Novosibirsk → a Tyumen-region index) — together with a «not
usable» code**, so a plugin must key on the codes, never on the presence of an index. `clean/physical` returned `NOT_SURE` for
everything tested; `clean/phone` parses «8 (913) …» and «+7 495 …» but not extensions.

### 4.6 Order lifecycle (G, test account)

| Step | Result |
|---|---|
| `PUT /2.0/user/backlog` (array body) | OK for ONLINE_PARCEL, POSTAL_PARCEL, PARCEL_CLASS_1, EMS (barcode formats `80…` 14 digits, `EF…RU` for EMS), ONLINE_COURIER (plain address), COD and declared value. **The barcode is returned at create time** |
| Postamat order | ONLINE_PARCEL COMBINED works **only with `ecom-data.delivery-point-index` + `dimension-type`**; `delivery-data` alone → `EMPTY_DELIVERY_POINT_INDEX` (contradiction 3). Partner point → `ILLEGAL_DELIVERY_POINT` |
| ECOM_MARKETPLACE / DELIVERY_PLUS / BUSINESS_COURIER | `UNAVAILABLE_MAIL_TYPE` — an **account switch**, not a payload error; old 2022–23 batches prove ECOM_MARKETPLACE worked on this account before. Read the flags from `GET /1.0/settings` |
| Server leniency | an order without recipient name and intake office was accepted; `order-num` is not unique; partial success is normal (HTTP 200 with `orders` and `errors[].position`) |
| Automatic multi-parcel grouping | orders to the same recipient within about a minute are silently grouped; batching one alone fails with `NOT_ALL_ORDERS_FROM_GROUP` |
| Edit | `PUT /1.0/backlog/{id}` with the full body re-prices; barcode unchanged |
| Batch | `POST /1.0/user/shipment?sending-date=…`: heterogeneous products merge into one `COMBINED` batch |
| Documents | F7 PDF OK (thermo 94 842 B / paper 99 912 B), F112 → HTTP 200 with an **empty body**; **F103 and the all-documents zip need `POST /1.0/batch/{name}/checkin` first** (it e-mails the electronic F103 to the real intake office — **not run**). The same call returns 403 with `Accept: application/pdf` and 400 `EMPTY_LIST_NUMBER` with `Accept: application/json` |
| Cancel | order in «Новые»: `DELETE /1.0/backlog`; order in a batch: `POST /1.0/user/backlog` first (same barcode), then delete. **`DELETE /1.0/shipment` deletes the order** |
| Cost | about 90 requests for the whole block |

Error shapes: 401 (two bodies: no token / bad user key), 407 for unknown route or wrong method, 400 `BAD_REQUEST`, 404 with code
`1001`/`1004`, 500 leaking a Java null-pointer text when `mail-category` is missing, and business errors as **HTTP 200**
(`errors[]`). The 429 shape is unknown.

### 4.7 Tracking (G, B)

No REST tracking on the test account. `GET /1.0/shipment/search?query=<barcode>` returns the shipment but, for never-moved test
shipments, without `last-oper-*` fields. SOAP was **not** called (no tracking login). Documented limits: 100 requests/day single
without contract; batch up to 3 000 per request, contract only, first poll ≥15 minutes, results kept 32 hours.

## 5. Coexistence with CDEK v2 (D, CONFIRMED by V)

V ran both actual plugin entry files in two fresh PHP processes with WordPress stubs. The first plugin file WordPress loads decides
(`active_plugins` sorts by `dir/file`):

| Order | Result |
|---|---|
| CDEK first | CDEK v2 registers; Russian Post v1 is quarantined — its methods disappear from checkout, tracking and auto-export stop; red notice on admin screens only |
| Russian Post first | Russian Post v1 runs; CDEK 2.3.0.4 stays dormant with a red notice naming Russian Post |

Either way no fatal, and the notice says «update the old plugin» although **no newer Russian Post exists**. This was a registration and
notice execution, **not** a full WooCommerce run. A literal Russian Post v1 on the v2 framework would fatal at class declaration
(`init_setup_wizard_handler()` without `: void`, missing `Woodev_Plugin_Setup_Wizard`) — which is why the loader never lets it. The
RP folder name on real shops is **unverified**, so which direction merchants hit is unknown. The operator decided the CDEK/Yandex EDD
rollout stays as is; the unactionable notice is card #1389.

## 6. Contradictions resolved (V wins)

| # | Topic | Resolution carried here |
|---|---|---|
| 1 | B called 53030 «verified live» on HTTP 200 | HTTP 200 carries business error 1204; 53030/53070 are **retired for tariffing since 01.01.2025**. Require a price **and** no business errors |
| 2 | E: postamat pickup only through 54020 | 54020 is the shared postamat + partner product; **23080 is a separate postamat-only product**, cheaper in V's probe. Keep both branches. This also explains E's observation that «Посылка онлайн» 23030 refuses postamats — the postamat variant is the COMBINED one |
| 3 | B/E: pickup order uses `delivery-data.delivery-point-index` | for ONLINE_PARCEL COMBINED → postamat the successful order used `ecom-data.delivery-point-index` + `dimension-type`; **every other pickup order payload is unverified** — do not flatten into one recipe |
| 4 | E: widget → Otpravka mirroring UNVERIFIED, yet «no Otpravka batch history» | history loss is **conditional** on an actual mirrored order being inspected; design the migration for both outcomes |
| 5 | C: «only the aggregator is maintained / none exists» | «no maintained dedicated alternative was verified among the checked products»; Saphali and commercial products unknown |
| 6 | A: CMS calls bypass the cache | the bypass skips cache **reads**; writes still happen (two calls, one persisted entry, TTL 86 400); the PUT/DELETE finding stands |
| 7 | F: +20 points are «normal churn» | they are **geographic coverage** (19 negative-longitude, 1 below 41° N); drop the churn speculation, keep the global bounding boxes |
| 8 | F: settings-id variants are byte-identical | identical 123-record maps and metadata but **different array order**; the invariant is ID-normalised equality |
| 9 | B: «Webhooks: none found» | true only for **Otpravka/tracking**; the CMS barcode/status callbacks exist separately |

Not contradictions but unreconciled: postamat counts 3 439 (widget) / 3 396 (passport) / 5 008 (open index, includes closed and
history) / 7 500 (marketing page); the exact meaning of `onlineParcel=1` (only 11 postamats) and why the widget lacks 56 open passport
points.

## 7. Verified and unverified, at a glance

| Statement | Status |
|---|---|
| Passport `ALL` = 35 971 points incl. 812 partners, one request | CONFIRMED (V) |
| Widget feed = 36 163, overlap 99.314 %, partners identical | CONFIRMED (V) |
| Otpravka tariff = public calculator on this account | CONFIRMED for 9 pairs (V) and 15 (G) |
| Quota 10 000/day, every request one unit | CONFIRMED for the sampled classes; reset not measured |
| CDEK v2 beside RP v1: one runs, no fatal | CONFIRMED (V), registration level |
| v1: PUT/DELETE cached 24 h; `TypeError` on failed `clean/address` | CONFIRMED (V) |
| 54020 valid for postamat + partner; 23080 postamat-only; 53xxx retired | CONFIRMED / PARTLY (V) |
| Order lifecycle (create, edit, batch, F7, cancel) | MEASURED once (G), test account |
| Contract (`dogovor`) pricing | UNVERIFIED — test cabinet; fake contract ignored silently |
| ECOM_MARKETPLACE / DELIVERY_PLUS order create | UNVERIFIED — account flag off |
| ППВЗ and ordinary-office pickup order payloads, time slots, courier-to-door, international | UNVERIFIED |
| F103, all-documents zip, Export.xls/csv, easy-return label | UNVERIFIED — need `checkin` (real e-mail) |
| SOAP tracking, `last-oper-*` on a moving shipment | UNVERIFIED — no tracking login |
| Quota reset time, 429 behaviour | UNVERIFIED |
| Widget → Otpravka mirroring | UNVERIFIED |
| Russian Post folder name on shops | UNVERIFIED |
| Memory/CPU of parsing the 54 MB passport in PHP | UNVERIFIED |
| Licensing/ToS of the widget backend and of redistributing a snapshot | UNVERIFIED |
| A full WooCommerce rig run with v1 beside v2; block checkout behaviour | UNVERIFIED |

**Leftover on the Russian Post side.** G made one `cms install` with a non-resolvable test host; it created an anonymous CMS
registration (user id kept only in the local scratch directory) that cannot be removed without `uninstall`. It is not linked to the
account and has no settings.

## 8. Open questions and how to measure each

| # | Question | How to measure | Needs |
|---|---|---|---|
| 1 | Do contract prices come back from `dogovor=` or `POST /1.0/tariff`? | on a real merchant account compare `/v2` with and without `dogovor=<ИНН>-<№>`, `POST /1.0/tariff`, and an invoice line for a real shipment | a merchant with a contract |
| 2 | What does an ECOM_MARKETPLACE / DELIVERY_PLUS order look like (`ecom-data`, `delivery-data`, `services`, `identity-methods`, COD via `compulsory-payment`)? | create orders on an account with `deliveryPlusAllowed`; same for ППВЗ and ordinary-office pickup | an account with the flags on |
| 3 | When does the daily quota reset and what does exhaustion return? | read `settings/limit` after midnight MSK; exhaustion only if ever acceptable on a throw-away account | – |
| 4 | Do F103, the all-documents zip and exports work, and what does `checkin` do to the account? | `checkin` on a batch whose intake office is acceptable to e-mail | explicit permission |
| 5 | Does SOAP tracking work with a fresh login, and do `last-oper-*` fields appear on moving shipments? | one `getOperationHistory` call with a real tracking login and barcode | a tracking login |
| 6 | Are widget-mode orders mirrored into Otpravka? | inspect a real widget cabinet that has exported orders | cabinet access |
| 7 | What is the Russian Post folder name on shops? | the EDD zip or a shop's plugin list | – |
| 8 | Real v1 + v2 behaviour beside each other | rig, throw-away site, both folder orders, and an in-place CDEK update (D §7) | rig time |
| 9 | Cost of parsing the passport | stream the 54 MB single-line JSON into a table in PHP, measure memory and time | – |
| 10 | Is a nightly sync of the widget backend or a shared snapshot allowed? | Russian Post terms; ask | a contact at Russian Post |
| 11 | What do 56 passport-only points and `onlineParcel=1` mean? | ask Russian Post or compare with `GET /postoffice/1.0/{index}` over a larger sample | – |
| 12 | Where is the COD transfer fee charged? | an invoiced COD shipment | a real account |
| 13 | «Доставка по клику», time slots, courier-to-door, international orders | account with the features, customs data | a capable account |

## Related

- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the same facts walked item by item for the operator (Russian).
- [a-v1-inventory.md](a-v1-inventory.md), [b-api.md](b-api.md), [c-market.md](c-market.md), [d-coexistence.md](d-coexistence.md),
  [e-no-widget.md](e-no-widget.md), [f-widget-api.md](f-widget-api.md), [g-otpravka-live.md](g-otpravka-live.md),
  [v-verify.md](v-verify.md) — the source reports.
- [../../adr/005-platform-v2-clean-break-policy.md](../../adr/005-platform-v2-clean-break-policy.md) — what a v2 plugin may break and what installed sites keep.
- [../../wiki/architecture.md](../../wiki/architecture.md) — where each shipping responsibility lives in framework v2.
- [../2026-10-12-yandex-geo-measurement/README.md](../2026-10-12-yandex-geo-measurement/README.md) — the sibling carrier measurement this one follows.
