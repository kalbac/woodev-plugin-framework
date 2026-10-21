# Task B — Russian Post APIs and products, state as of 21.10.2026

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Read on 21.10.2026 (MSK). Official pages were fetched directly with curl and parsed; the SPAs (otpravka spec, tracking spec,
widget) were read from their own JS/HTML view files. Raw copies: `<local scratch path, not committed>`
(`views/*.txt` = otpravka spec sections, `calc.txt` = tariff PDF, `trk_main.js`, `w_main.js`).
Conventions: **[OFF]** official page read directly; **[LIVE]** my own read-only GET against production; **[NEWS]** press/secondary;
**UNVERIFIED** = could not confirm. Web search was thin (general search returned mostly old/irrelevant hits), so most facts below
come from [OFF]/[LIVE], and the "developer pain points" section is the weakest-sourced part.

## 0. TL;DR

- Five distinct surfaces, five distinct auth schemes. Only the tariff calculator is open to everyone.
- «Отправка» REST API (`otpravka-api.pochta.ru`) is the only way to create shipments/labels/batches/documents programmatically.
  It needs two credentials (app token + user key) and an account activated for API; it has a **daily request quota**; **no sandbox**.
- Tracking is still **SOAP 1.2** (`tracking.russianpost.ru/rtm34`, batch `…/fc`); no REST successor found. Without a contract: 100 requests/day.
- Pickup widget (`widget.pochta.ru/map/widget/widget.js`, global `ecomStartWidget`) is configured per site in a Pochta cabinet; returns the chosen point + price to a JS callback.
- The product list grew a lot (ЕКОМ, ЕКОМ Маркетплейс, Курьер онлайн, Доставка Плюс, Легкий возврат, 1st-class family). VAT is 22% since 01.01.2026 and already in the APIs.

## 1. Every current API / surface

| # | Surface | Base URL | Auth | Limits | Sandbox | Status |
|---|---|---|---|---|---|---|
| 1 | «Отправка» B2B REST API | `https://otpravka-api.pochta.ru` (host in spec JS; unauthenticated GET returns **401** [LIVE]) | headers `Authorization: AccessToken <app token>` + `X-User-Authorization: Basic base64(login:password)` (passport.pochta.ru login). Token is emailed after API access is activated; also in cabinet; "Обновить токен" rotates it | token «выделяет квоты»; **requests/day** per user, readable via `GET /1.0/settings/limit` → `allowed-count`, `current-count`. Number not published. Rate-per-second UNVERIFIED | **None documented.** "Тестирование API" = SoapUI / Chrome REST extension against prod [OFF]; Infostart article also says no test env (secondary) | current; REST+JSON since ~2016–17 [NEWS r3] |
| 2 | Public tariff + delivery-time calculator | `https://tariff.pochta.ru/v2/calculate/tariff[/delivery]?object=…`; reference: `/v2/dictionary/{category,object/{code},service,country,…}` | **none** — GET with no credentials works [LIVE]; contract tariffs via `dogovor=<ИНН>-<№ договора>` param | none documented in PDF v3.21; response sets a `session-cookie` (HttpOnly, 24 h) [LIVE]. Rate limit UNVERIFIED | n/a (public) | current, doc **v3.21 dated 12.10.2026**; engine version `3.21.12.872` [LIVE] |
| 3 | Tariff inside «Отправка» | `POST /1.0/tariff` on #1 | as #1 | counts toward #1 quota | – | current; takes `mail-type`, `mail-category`, `mass`, `index-from/to`, `declared-value`, `delivery-point-index`, `dimension(-type)`, notice flags; returns kopecks incl. `delivery-time.{min,max}-days` |
| 4 | Tracking (single) | SOAP 1.2 `https://tracking.russianpost.ru/rtm34` (WSDL `?wsdl`, `OperationHistory12`; also resolves on `tracking.pochta.ru/rtm34?wsdl`) [LIVE]. Methods `getOperationHistory`, `PostalOrderEventsForMail` (COD operations) | SOAP header `AuthorizationHeader{login,password}` with `mustUnderstand=1`; creds from tracking.pochta.ru → «Настройки доступа» | **100 requests/day without contract; unlimited only under contract** [OFF tracking portal strings] | none | current, unchanged since ~2015 |
| 5 | Tracking (batch) | SOAP `https://tracking.russianpost.ru/fc?wsdl` (`ItemDataService`, ticket flow `getTicket` → `getResponseByTicket`) | same creds | **contract only**; up to **3000 shipments per request**; first poll ≥15 min after ticket, then ≤1 per 15 min; ticket result kept **32 h** [OFF] | none | current |
| 6 | Tracking inside «Отправка» | `GET /1.0/shipment/search?query=` etc. (batch/order lookups with `barcode`, last-oper fields) | as #1 | quota #1 | – | current |
| 7 | OPS / postamat search | `GET /postoffice/1.0/…` on #1: by index, by address (`by-address`), `nearby` + `nearby.details` (lat/long, `top`, `search-radius`, `filter`, `current-date-time`, `filter-by-office-type` default true = ГОПС/СОПС/ПОЧТОМАТ), `settlement.offices.codes`, service groups, `unloading-passport/zip` (full OPS passport dump) | as #1 | quota #1 | – | current |
| 8 | Address / FIO / phone normalisation | `POST /1.0/clean/address`, `/clean/physical`, `/clean/phone` on #1 | as #1 | quota #1 | – | current |
| 9 | Address suggest + validate («Сервисы проверки и подсказки адресов») | separate product, `pochta.ru/business/adressapi` ("Стать клиентом"); old plugin references `passport.pochta.ru/suggestions/api/4_1/rs` (commented out, `includes/classes/checkout.php:123`) | own contract/keys; up to 10 suggestions/query; GAR/FIAS structured output | UNVERIFIED | 1-month free test for new clients [NEWS r2, 27.10.2022] | launched 27.10.2022; current |
| 10 | Pickup widget | `https://widget.pochta.ru/map/widget/widget.js` → `ecomStartWidget({id, callbackFunction, containerId})`; constructor/cabinet at `https://widget.pochta.ru/` (login via passport.pochta.ru OAuth) | no per-request auth: a numeric widget `id` created under the merchant's Pochta account. Cabinet requires `senderZipCode`, `averageInvestmentsCost`, `defaultWeight` to be set before the widget works (`w_main` chunks 832/844) | UNVERIFIED | demo `id` 6561 on the landing | current. Official CMS modules exist for 1C-Bitrix/InSales/amoCRM/Shop-Script/CS-Cart/1C; WooCommerce is listed only under "решения сторонних разработчиков" and links **woodev.ru's own Russian Post docs page** [OFF pochta.ru/support/business/modules] |
| 11 | Webhooks / callbacks | **None found.** No webhook section in the otpravka spec menu, none in tariff or tracking docs | – | – | – | tracking is poll-only (ticket-based batch or single SOAP) |

Source URLs (read 21.10.2026): `https://otpravka.pochta.ru/specification` (+ `/static/views/specification/*.html`), `https://tariff.pochta.ru/post-calculator-api.pdf`,
`https://tracking.pochta.ru/specification` (JS bundle), `https://widget.pochta.ru/` (+ `/main.*.js`, lazy chunks), `https://www.pochta.ru/support/business/api`,
`https://www.pochta.ru/support/business/modules`, `https://www.pochta.ru/business/adressapi`, `https://www.pochta.ru/business/ecommerce-shipping`.

### «Отправка» REST — endpoint inventory (from spec menu, method + path)

| Group | Calls |
|---|---|
| Orders ("backlog") | `PUT /1.0/user/backlog` create (v1), **`PUT /2.0/user/backlog` create (v2, preferred: has `delivery-data`, `fiscal-data`, `goods`, `ecom-data`, `time-slot-id`)**, `GET /1.0/backlog/search?query=`, `GET/PUT/DELETE /1.0/backlog/{id}`, `POST /1.0/user/backlog` (return to "new"), `GET /1.0/backlog/by-group-name/…` |
| Batches | `POST /1.0/user/shipment` (batch from N orders), `PUT/POST /1.0/batch/{name}/…` (add/move orders, change sending date), `GET /1.0/batch`, `/1.0/shipment/…`, `/1.0/shipment/search?query=` (by ШПИ), `DELETE /1.0/shipment` |
| Documents | `GET /1.0/forms/…`: Ф7п, Ф112ЭК, Ф103, all-docs package, per-order forms (before batch: `/1.0/forms/backlog/…`), act of inspection, easy-return label; `POST /1.0/batch/{name}/checkin` electronic Ф103 to the OPS |
| Archive / long-term storage | `/1.0/archive…`, `/1.0/long-term-archive/shipment/search?query=` |
| Returns | `PUT /1.0/returns` (return for an existing shipment), `PUT /1.0/returns/return-without-direct`, `POST /1.0/returns/…`, `DELETE /1.0/returns/delete-separate-return?barcode=` |
| Settings | `GET /1.0/settings` (accounts, ИНН, return address, e-signature/declaration flags), `GET /1.0/user-shipping-points`, `GET /1.0/settings/limit` |
| Data | `POST /1.0/clean/{address,physical,phone}`, `POST /1.0/tariff` |
| Claims | `PUT /1.0/claims/create` («заявление на дополнительную услугу») |
| Time slots | spec menu «Временные интервалы»: list free slots, book, form order, rebook, cancel (JS routes `timeslots`, `timeslots_by_postindex`, `timeslots_for_rebooking`); no view files were served for these, so exact paths/semantics are **UNVERIFIED** — order v2 has `time-slot-id` |
| User sessions (`/1.0/user-session…`) | present in spec JS but the menu block is HTML-commented out → treat as **hidden/legacy** |

## 2. Products and what each needs

Product enum (`enums-product`) and mail types/categories come from the otpravka spec; tariff `object` codes from the calculator PDF Appendix 1 (codes verified live: 23030, 27030, 53030 return 200).

| Product | otpravka `mail-type` / sample `product` values | Tariff `object` | Notes from pochta.ru product cards [OFF 21.10.2026] |
|---|---|---|---|
| Посылка стандарт/нестандартная («посылка») | `POSTAL_PARCEL`; `POSTAL_PARCEL_ORDINARY / _WITH_DECLARED_VALUE / _…_AND_CASH_ON_DELIVERY` | 27030 стандарт, 28030 EMS-курьер, 29030 экспресс; нестандартная 5xxxx-range per PDF | нестандартная ≤50 kg |
| Посылка 1-го класса | `PARCEL_CLASS_1`, also `LETTER_CLASS_1`, `BANDEROL_CLASS_1`, `VGPO_CLASS_1` | 47xxx (e.g. 47060) | 5 kg, sum of dims ≤70 cm |
| **Посылка онлайн** | `ONLINE_PARCEL` (+`_WITH_DECLARED_VALUE`, `_…CASH_ON_DELIVERY`) | 23030 / 23020 / 23040 / 23060 | 20 kg; accepted at dedicated OPS (or batch intake), delivered to OPS **and postamats** (postamat ≤56×43×25 cm, storage 3 days) |
| **Курьер онлайн** | `ONLINE_COURIER`; `courier` flag, `delivery-to-door` | 24xxx | 31.5 kg, delivery to 13 881 localities (card); also `BUSINESS_COURIER`, `BUSINESS_COURIER_ES` (30030/31030) |
| **ЕКОМ** / **ЕКОМ Маркетплейс** | `ECOM_ORDINARY`, `ECOM_WITH_COMPULSORY_PAYMENT`, `ECOM_MARKETPLACE_WITH_DECLARED_VALUE` (mail type `ECOM_MARKETPLACE`) | 53030 / 53070 (ЕКОМ), 54020 / 54060 (Маркетплейс) | ЕКОМ tariff needs `pack` (10 S / 20 M / 30 L / 40 XL / 99) and `sumin`; services: SMS pack, simple check of contents, fitting, working-order check, partial return. In order v2: `ecom-data` and/or `delivery-data` with `delivery-point-index`, `identity-methods`, `services` (enum `WITHOUT_OPENING, CONTENTS_CHECKING, WITH_FITTING, COURIER_DELIVERY, PARTIAL_REDEMPTION, FUNCTIONALITY_CHECKING`); `tender` flag for Маркетплейс; `seller-id` |
| **Доставка Плюс** | `DELIVERY_PLUS` (mail type exists in enum) | UNVERIFIED | marked **New** on the e-commerce page: all delivery ways + extras, 31.5 kg, storage 7–14 days (postamat 3) |
| EMS (обыкновенное / оптимальное / РТ / Тендер) | `EMS`, `EMS_OPTIMAL`, `EMS_RT`, `EMS_TENDER` | 7030 etc. | 31.5 kg, express, can take from courier |
| Письмо / бандероль / мелкий пакет | `LETTER`, `BANDEROL`, `SMALL_PACKET`, `SINGLE_*` | 2000, 11000, … | not parcel business, but in enum |
| Международные | `INTERNATIONAL_*`, `mail-direct` = country code, `customs-declaration`, `entries-type`, `transport-type` | outgoing intl objects (4xxx/7xxx) | 20 kg, >100 countries |
| **Легкий возврат** | `EASY_RETURN`; order flags `easy-return`, `client-return`; separate `/1.0/returns` flow; label via `forms/…easy-return` | – | buyer returns at any OPS; 165 ₽/0.5 kg then 15 ₽ per 0.5 kg |
| Hyper-local ("день в день") | `HYPER_CARGO`, hyper-local statuses enum | – | UNVERIFIED how it is sold |

Which calls each needs (common path for all domestic products):
`POST /1.0/clean/address` (+physical, phone) → tariff (public `/v2/calculate/tariff/delivery` or `POST /1.0/tariff`) → `PUT /2.0/user/backlog`
(ШПИ comes back immediately in `orders[].barcode`) → `POST /1.0/user/shipment` (batch) → `GET /1.0/forms/…` (labels, Ф7п/Ф103/Ф112ЭК) → `POST …/checkin` → poll tracking.
Product-specific:
- **Pickup in OPS / postamat (Посылка онлайн, ЕКОМ, Доставка Плюс):** widget or `/postoffice/1.0/nearby` to pick a point; send it via `delivery-data.delivery-point-index` (spec says: for shipments addressed to a postamat/ПВЗ use `delivery-data`, not `index-to`). `delivery-point-type`: `DELIVERY_POINT` (ПВЗ) or `PICKUP_POINT` (почтамат).
- **Наложенный платёж (COD):** `mail-category` `WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY`, fields `payment` (kopecks) + `insr-value`; error `RESTRICTED_MAIL_CATEGORY` says the account needs an **ЕСПП number** in service settings (ask personal manager / support.otpravka@russianpost.ru); optional `payment-details` (bank BIK/account of COD recipient). ЕКОМ uses `compulsory-payment` (К оплате с получателя) instead. `delivery-with-cod` = pay on receipt.
- **Объявленная ценность:** `insr-value` (kopecks); error `INSR_VALUE_EXCEEDS_MAX` has a per-product ceiling.
- **Курьер:** `courier=true`, `delivery-to-door`, optional `time-slot-id`; courier-call statuses enum (`AVAILABLE, ORDER_IN_PROGRESS, …`).
- **Receipt/fiscal data in the order (v2):** `fiscal-data` (`customer-inn`, `customer-name`, `customer-email/phone`, `payment-amount`), per-item `goods.items[]` with `vat-rate` (**values −1, 0, 10, 110, 22, 122**), `lineattr`, `payattr`, `supplier-inn/name/phone`, `goods-type`, `excise`. Marking (Честный знак) fields: none found in the order schema — UNVERIFIED whether required.

## 3. What changed 2022 → 2026 (dated)

| Date | Change | Source |
|---|---|---|
| 19.02.2021 (context) | «Курьер онлайн» opened to e-shops in all regions | comnews.ru (via search) [NEWS] |
| 2022 (no day) | ~50 000 parcels collected from postamats in Moscow region since Jan 2022 — postamat network was already a mass channel | search summary of retailer.ru [NEWS, UNVERIFIED date] |
| **27.10.2022** | Pochta launches address service (suggest + validation, GAR-based, weekly updates, 99% claimed accuracy, 1-month free test) | `retail.ru/news/pochta-rossii-zapustila-servis-po-rabote-s-adresnymi-dannymi-27-oktyabrya-2022-221820/` [NEWS] |
| 2023 | «Почта.Покупки» (own SberPay-like checkout with delivery, Почта ID login) | oborot.ru / habr news 747486 via search [NEWS, UNVERIFIED day] |
| 2022–2025 | Tariff API doc v2.14 (13.10.2022) → v3.19 (25.05.2025): added `barcode`, `full`, `weightmax/sizemax/coupon`, `retnreason`, `paymode` rename (paytype→paymode), `client-level`; Appendix 2 service codes 141–190 added | tariff PDF Appendix 19 [OFF] |
| 28.11.2025 law 425-ФЗ → **01.01.2026** | VAT 22%: tariff API returns `ndsrate 22`; otpravka `vat-rate` accepts 22 and 122 | search [NEWS] + tariff response [LIVE] + order v2 spec [OFF] |
| 2026 (v3.20, dates in the doc’s change log are internally inconsistent — one row reads `01.12.2026`, a future date, probably typo for 2025) | removed "SMS pack" elements (codes 41–44 → use 21/20); added section 2.8 (info on mail types); services 191–199, 201, 202, 210 added; service 49 removed; `date-discount` (tariff grid on a date); `eku`; `forward` | tariff PDF [OFF] |
| **12.10.2026** | Tariff API **v3.21** published | tariff PDF cover/history [OFF] |
| **01.07.2026** (URL date) | «Доставка по клику»: parcel goes to nearest OPS, recipient orders door delivery (courier within 120 min) in cabinet/app; **120+ cities**; created in the business cabinet **or via API** | `retail.ru/news/pochta-rossii-zapustila-dostavku-po-kliku-1-iyulya-2026-279429/` [NEWS]; how it maps to API fields: UNVERIFIED (possibly `COURIER_DELIVERY` service or `courier-order-statuses` enum) |
| by 21.10.2026 | pochta.ru e-commerce page shows **7 500 postamats** (2019 launch: ~1.5 k) and new product **«Доставка Плюс»** | `pochta.ru/business/ecommerce-shipping` [OFF] |
| 2026 (planned) | State reform of Pochta (bill passed first reading; marketplaces’ PVZ in OPS, trade licence; effective 01.09.2026 per one source) — final law status UNVERIFIED | sertcity.ru, themoscowtimes.com via search [NEWS] |
| tracking | No visible change: SOAP/rtm34 same as in 2015-era examples | [OFF] |

Retired endpoints: none found officially. `user-session` spec section is commented out of the menu; v1 order create still documented beside v2.

## 4. Who can use what

| Capability | No contract / anonymous | Registered (account, no signed contract) | Contract / оферта |
|---|---|---|---|
| Tariff + delivery time (`tariff.pochta.ru/v2`) | **Yes, open** [LIVE]. Public rates only; `dogovor=` unlocks contract rates | – | contract rates via `dogovor` |
| Tracking single (SOAP) | – | **100 req/day** | unlimited |
| Tracking batch (≤3000/req) | no | no | yes |
| «Отправка» API (orders, labels, batches, normalisation, OPS search, tariff) | no | needs registration **+ application for API connection + activation** (retail.ru, Infostart) | older release text: "корпоративные клиенты с действующим договором" [NEWS r3, undated ~2016–17]; the current pochta.ru modules page instead says "заключите **договор оферты за пару минут**" in the business cabinet [OFF]. So in practice: a legal entity/IP accepts the online offer, then requests API activation. Individuals (физлица) — UNVERIFIED, tracking page says "зарегистрируйтесь как юридическое лицо" |
| COD | – | – | needs ЕСПП number in settings |
| Pickup widget | – | Pochta passport login to configure | UNVERIFIED if offer is required |
| Address suggest/validate | – | – | separate "Стать клиентом" application |

Answer to "can a merchant without a contract do anything?": get **public tariffs** (price + deadline) and nothing that creates a shipment; a no-API-activation merchant can still use the web cabinet manually. Pickup-point search without the widget requires the authenticated `/postoffice/1.0/*` endpoints (no public OPS list found; `geo.pochta.ru` / `export.pochta.ru` are other products, not examined).

## 5. Pain points developers report (with sources)

Evidence is thin — community sources found were old; items marked (spec) are derived from the official specification itself.

1. **Credentials are a password in disguise.** The user key is `base64(login:password)` of the passport account; the spec itself warns against storing it in files. Token can be rotated in cabinet (invalidates integrations). (spec, `authorization-key`, `authorization-token`)
2. **Hard daily quota, undocumented size.** Only discovery path is `GET /1.0/settings/limit`; exhausted-quota behaviour/HTTP code not documented — UNVERIFIED. A NuGet wrapper mentions the same limit endpoint (search result, `PochtaSdk`).
3. **No sandbox.** Every test creates real orders in the merchant cabinet (`DELETE /1.0/backlog` only works while "new"). (spec "Тестирование API"; Infostart text via search)
4. **Tracking is SOAP, per-ID, 100/day unauthenticated tier**; batch needs a contract; batch results are asynchronous (ticket) with a 15-minute poll rule and 32 h retention. A Habr Q&A answer (`qna.habr.com/q/23557`, old, undated) says scraping seemed more reliable than the official SOAP; older SDKs repeat "batch/otpravka only with contract" (`packagist appwilio/russianpost-sdk`).
5. **Tariff calculator returns itemised `items[]`** whose meaning depends on `serviceon` codes; total/delivery/oversize/surcharge must be summed per section 1.6 rules. Errors: with default `errorcode=1` you get HTTP 4xx, with 0 you get 200 + error body (PDF 1.4). It sets a session cookie. (spec) A Habr thread says COD amounts had to be calculated by hand with the public calculator (`qna.habr.com/q/21019`, old).
6. **Order v2 is large and strict:** `index-to` vs `delivery-data` for pickup points; required `mail-category`+`mail-type`+`mass`+`postoffice-code`; many `ILLEGAL_*` / `EMPTY_*` codes (`enums-errors`); `ILLEGAL_POSTCODE` when the intake OPS in settings differs from the batch; `PAST_DUE_DATE`, `READONLY_STATE`, `NO_AVAILABLE_POSTOFFICES`. (spec)
7. **Normalisation outcome is a quality/validation code, not pass/fail** (`enums-clean-address-quality`, `-validation`, `-fio-quality`, `-fio-phone-quality`), so integrators must decide thresholds. (spec; I did not read the code tables in detail)
8. **Widget is a black box with price computed on Pochta’s side:** settings (sender ZIP, average declared value, default weight) are mandatory in the cabinet; the callback delivers point + cost to the browser. (my inference: that cost is client-supplied data and must be re-verified server-side) (`w_main` chunks 832/844)
9. **Doc/blog inconsistency risk:** an Aug-2026 blog (`lifemoneyblog.ru/2026/08/03/…`, search result) claims a REST tracking API at `tracking.pochta.ru`; the official spec shows SOAP only. Do not trust such blogs.
10. **Outages / rate-limit incidents:** no concrete public incident reports found — UNVERIFIED.

## 6. Cross-check with the v1 plugin (facts only)

`plugins-reference/woodev-russian-post/includes/api/api.php:16,110,131,143,147` uses otpravka-api (`/1.0/clean/address`, `/1.0/backlog…`); `includes/classes/checkout.php:71` loads `widget.pochta.ru/map/widget/widget.js`; `includes/classes/checkout.php:123` has the passport suggestions URL commented out; grep also shows `tracking.russianpost.ru/rtm34?wsdl` and `/1.0/settings/limit`, `/postoffice/1.0/settlement.offices.codes` references. I did not find use of the public `tariff.pochta.ru` calculator (UNVERIFIED — URL could be built dynamically).

## 7. Not checked / gaps

- `widget.pochta.ru/docs` full parameter list (only `id/callbackFunction/containerId` confirmed from Pochta’s own bundle; `weight, sumoc, startZip, start_location, dimensions, order_lines` and `courierStartWidget` come from third-party posts) — UNVERIFIED.
- Real quota number, 429/exhaustion error shape, per-minute limits for #1; sandbox existence beyond "none documented".
- Exact time-slot endpoints and the API shape of «Доставка по клику» and «Доставка Плюс».
- Whether a private person (non-IP) can obtain API access; whether the widget requires a signed offer.
- Marking / Честный знак requirements for shipments (no field found in order schema).
- Final status of the 2026 Pochta reform law.
- I did not read the individual tariff `object` parameter tables for every product, nor `enums-clean-*` code tables, nor `ApiShip`/other aggregator docs (apiship.ru lists Pochta; not opened).

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
