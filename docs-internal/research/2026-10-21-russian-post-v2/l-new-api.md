# Task L — Russian Post's NEW business API documentation (tracking by token, tariff API, news, quick start), 21.10.2026 (s191)

Raw files (outside the repo): `.../scratchpad/pochta/l-raw/` — `articles/` (209 raw JSON), `text/` (209 converted pages), `toc.txt`, `live/` (masked dumps of the 15 live calls).
Nothing in any repo was edited or committed. No credential value was printed or written; dumps were masked (checked with grep).

Convention: **READ** = from the official page, nothing executed. **MEASURED** = I called it live today on the test account. **UNVERIFIED** = neither.

## 0. How the SPA content was obtained (reusable)

The pages are a module-federation SPA (`/static/index_new-*.js` → `/apps/iplatform-client-support-article/…`). The article text is served by a **public, unauthenticated JSON API** (Strapi):

- index of all articles of the section: `GET https://otpravka.pochta.ru/api/support/links/business?locale=ru` → 209 entries;
- one article: `GET https://otpravka.pochta.ru/api/support/articles/business?locale=ru&name=support-article-business-<slug>` (the `<slug>` is the last path segment of the page URL). Body is in `content[].content` as JSX-like markup (tables as `<table>`, code samples inside `<Code code={...}/>`);
- `…/support/articles/business/search?locale=ru&query=<q>` searches headers.

I downloaded all 209 articles (one GET each, documentation traffic only). Sibling pages found: the full tracking spec set (single, batch, 10 dictionaries, FAQ, terms, check-access), the whole Otpravka API reference (~120 pages), the tariff API, news, quick start. None of the four pages the operator named is empty. **`updatedAt`/`publishedAt` are not exposed**, so a page's own edit date is unknown; only the news dates are dated.

## 1. Tracking

| Fact | Value | Source / status |
|---|---|---|
| New protocol, "single" access | **REST**, `GET`, JSON | `/new/support/business/tracking-api-specification-single` READ + MEASURED |
| Base URL / method | `https://postapi.pochta.ru/tracking/api/external/v1/info?barcodeList=<barcode>[&language=RU\/EN]` | same page; MEASURED 200 |
| Auth | header `Authorization: ApiKey <token>` (prefix **`ApiKey <token>`**, not Otpravka's `AccessToken <token>`). Token is **~1 050 characters** (JWT-like, length measured on the operator's token) | spec READ; MEASURED |
| Where a merchant gets the token | cabinet «Настройки» (link in the spec); news 09.10.2026: «получить актуальную ссылку и активировать доступ можно в настройках личного кабинета»; news 12.06.2026: authorisation is available **through the legal-entity cabinet service**. Exact menu path for the tracking token is **not written** (for the Otpravka token it is «Настройки → API → Токен Авторизации», page `LKUL-api`) | news READ; menu path UNVERIFIED |
| Is it the same token as Otpravka's? | **No.** The 32-char Otpravka AccessToken sent as `ApiKey` to the tracking URL → **403** (empty body). A separate cabinet token | MEASURED (`live/track-otpravka-token-instead.txt`) |
| Barcodes per call | **One.** Parameter is named `barcodeList` but two valid barcodes (comma-separated AND repeated param) returned the record of the **first only**, HTTP 200 | MEASURED (`track-two-valid*.txt`). The spec itself says «по одному РПО» |
| Barcode formats | 14-digit domestic or 13-char S10 international (`RA644000001RU`). A 14-digit string with an invalid checksum is rejected as «should be in S10 format» | spec READ; MEASURED |
| Request headers | `Content-Type: application/json` (required by spec), `Language` header listed in the spec table but also a `language` query param in the sample — which one is honoured is UNVERIFIED | spec READ |
| Response shape | one object: `barcode`, `mailTypeCode/mailType/mailTypeText`, `mailCtg…`, `mailRank…`, `insuranceMoney`, `cashOnDelivery`, `weight/length/width/height`, `sender`, `recipient`, `indexTo/indexFrom`, `recipientAddress`, `origin/destination City/Country Name`, and **`trackingItemOperationGroupStatuses[]`** = high-level status groups `{title, code, date, direction (TO_RECIPIENT/TO_SENDER), future (bool), originTrackingHistoryItems[]}`; each history item `{kind, date (ISO+03:00), humanStatus, humanOperationStatus, operationType, operationAttr, countryId, index, indexTo, cityName, countryName, isInInternationalTracking, description, weight, rate, ukd, mozgr}` | spec READ; MEASURED on a 2023 ЕКОМ Маркетплейс barcode (200, 1 889 bytes) |
| High-level status codes | seen: `RECEPTION_EXPECTED` («Оформлено»), `IN_WAY` («В пути»), `READY_FOR_DELIVERY` («Готово к вручению»), `GIVING` («Вручено»); `future:true` groups are the predicted path. **No full list of group codes is published** | MEASURED; full list UNVERIFIED |
| Operation / attribute codes | numeric `operationType` + `operationAttr`, dictionary page `tracking-api-dictionaries-operations`; news 21.06.2026 added **53, 54, 55**. The dictionary page names them **twice, inconsistently**: the type list says 53 «Вручение не разрешено», 54 «Бронирование ячейки почтомата, отправление в очереди», 55 «Ячейка почтомата забронирована»; the attribute table says 53 «Бронирование интервала доставки», 54 «Изменение (перебронирование) интервала», 55 «Отказ от бронирования интервала» (the latter matches the news: interval delivery). Which table is current: UNVERIFIED — map by `operationType` only from a measured response | READ; page inconsistent |
| Empty history | «пустой список элементов» per the spec text; for an S10 code `RA644000001RU` the service returned a populated record (so the spec text about an empty list was not observed) | READ vs MEASURED mismatch |
| Errors | bad/foreign token → **403, empty body**; invalid barcode → **400** `{"code":"1003","desc":"Validation error: [… Barcode … should be in S10 format …]"}`. The spec page has no error table | MEASURED; full error list UNVERIFIED |
| **Limit, single access** | FAQ (`tracking-api-faq`): single access is **«ограничен 5000 запросами в сутки»**; increase on request via «Помощь» for clients with an active cabinet. The older Terms page (`tracking-api-about-terms`, §3.1.1) still says **100 single requests/day**, unlimited only for legal entities with a contract. **The two pages contradict**; 5 000 is the FAQ that sits beside the new spec. Per-minute limit: not published. No rate-limit headers in 200 responses (only `x-envoy-upstream-service-time`). I did **not** probe the limit | READ; CONTRADICTION; per-minute UNVERIFIED |
| Statistics | usage stats updated once a day (FAQ) | READ |
| **Batch access** | still **SOAP 1.1**, `https://postapi.pochta.ru/fc` (`getTicket` / `getResponseByTicket`), **login + password** («Предоставляются по запросу в поддержку»), ≤3 000 barcodes, first poll after ≥15 min, answer kept 32 h. Page `tracking-api-specification-batch`. This is the **old** protocol, unchanged | READ; not called (no login) |
| Old SOAP single (`tracking.russianpost.ru/rtm34`, login/password) | **No sunset date is written anywhere in the 209 pages.** Evidence of migration: news **09.10.2026** «система отслеживания **переходит** на работу через обновленный сервис «API Трекинга»»; the single spec is REST+token; but `tracking-api-about-check-access` still describes SOAP/rtm34 with login+password and the Terms page still says login/password. Whether rtm34 still answers today: **UNVERIFIED** (we have no tracking login). Treat it as «being replaced, date unknown» | READ; deprecation date UNVERIFIED |
| Webhooks / push | none in the tracking pages or anywhere in the 209 articles (grep) | READ |

Free connection check for the wizard (MEASURED): with a valid token an **invalid** barcode gives 400/1003; with a bad token → 403. That separates «token works» from «token wrong» without needing a real shipment (still counts as a request; whether 4xx count against the 5 000 is UNVERIFIED).

## 2. Tariff API

`/new/support/business/tariff-api` = «Описание API Тарификации», **v2.8** (the doc text is ~120 KB; request examples are dated 23.10.2020). It documents **the same service as the public calculator**: base `https://tariff.pochta.ru/v2/…`. It is **not** the Otpravka `POST /1.0/tariff` (that page, `otpravka-api-calculate-shipment-rate`, is separate and unchanged).

| Fact | Value | Source / status |
|---|---|---|
| Base URL, method | `GET https://tariff.pochta.ru/v2/calculate/tariff[/delivery][/check]?…`; dictionaries `GET /v2/dictionary/…` (categories, object details, services, countries `/country`, post office by index `/postoffice/{index}` ≤10 ids, mail types) | READ; MEASURED (4 calls, 200/400 as below) |
| Auth | **none** documented, none sent, none needed; no key, no token | READ + MEASURED |
| Limits | **none published** (no quota, no rate wording in the whole page). Not probed | READ; real limits UNVERIFIED |
| Response format | `json`/`jsontext`/`html`/`text`; automation must use JSON. `errorcode=1` is the **default** → business errors come back as HTTP **400** (measured: 53030 → 400 with code 1204 «услуга тарификации не осуществляется после 01.01.2025»; 23080 to an office-only index → 400 code 2006). `errorcode=0` forces 200 | READ + MEASURED |
| Products per call | **One.** `object=<code>` is a single product; «несколько видов расчетов» in one call means `tariff` + `delivery` (+ `check`) for the same object, not several products. For several tariffs the caller makes several calls | READ + MEASURED |
| Contract pricing | parameter **`dogovor=<ИНН>-<номер договора>`** (INN 10/12 digits with leading zeros, dash, contract number); «обязательно при расчёте для корпоративных клиентов для внутренних отправлений»; `isdogovor` in the response says whether the contract directories were used. Earlier V found a **fake** contract is silently ignored → so a wrong contract number gives public prices, not an error. Real contract prices: UNVERIFIED (no contract) | READ; UNVERIFIED |
| Prices | money in **kopecks** (`sumoc`, `sumnp` in kopecks); `weight` grams; `size=100x50x25`; `from`/`to` postcodes; `date=YYYYMMDD`; `service=` modifiers list (Appendix 2); `closed=0/1` | READ |
| Product catalogue (Appendix 1) | lists codes incl. 23030, 23020, 23040, 23060, 23080, 23090, 23100, **54020**, 54060 and still **53030/53070** (stale: the live service returns error 1204 for 53030). **The documentation lags the service** | READ vs MEASURED |
| Live: 54020 | name now returned as **«Доставка плюс с объявленной ценностью»** (mailtype 54, mailctg 2), price 216.00 RUB + VAT for 500 g, 100 000-kopeck declared value, 101000→190005, 200 | MEASURED (`live/tariff-public-54020.txt`) |
| Live: 23030 / 23080 | 23030 → 200 («Посылка онлайн обыкновенная»); 23080 delivery to postcode 190005 → 400 code 2006 «отправление можно доставить только в почтомат» (consistent with 23080 = postamat-only) | MEASURED |

## 3. News (page `news`, «Журнал изменений») — complete list, 7 items

| Date | Item | Relevance |
|---|---|---|
| **09.10.2026** | Tracking system «переходит на работу через обновленный сервис «API Трекинга»»; get the link and activate access in cabinet settings | tracking moved to token REST |
| 24.08.2026 | From 01.09.2026 option «Фармацевтическая продукция» is enabled for all offer-contract (офертные) clients | none |
| **24.08.2026** | From **16.09.2026** new service **«Доставка Плюс»** (комплексное решение: delivery to buyer, deliveries to marketplaces, returns; for e-commerce). **«ЕКОМ Маркетплейс» and «ЕКОМ Маркетплейс Тендер» order types are temporarily disabled.** New `mail-type` value **`DELIVERY_PLUS`** | products; matches the measured 54020 name change and G's «ECOM_MARKETPLACE order create unverified, flag off» |
| 21.06.2026 | «Интервальная доставка» — choose the delivery day and time interval when creating orders (Otpravka timeslot API pages exist: `otpravka-api-timeslots-by-postindex`, pre-booking, booking, rebooking); tracking operation codes **53, 54, 55** added | courier-only feature, not core |
| **12.06.2026** | **Tracking authorisation** now available through the **legal-entity cabinet** service | first announcement of the token model |
| 15.05.2026 | New additional service «ПРР» (`unloading: true`) for ЕКОМ Маркетплейс Стандарт/Экспресс/Тендер/«на маркетплейс», EMS РТ | none |
| 13.03.2026 | «Возврат сопроводительных документов» (`vsd: true`) for ЕКОМ Тендер | none |

**Not in the news at all:** international, webhooks, any public office/postamat list API, any Otpravka v2 or token rotation, any ЕКОМ (53xxx) retirement notice (that is a 01.01.2025 fact known from the live service), any change of API quotas.

Points/offices API reference (all READ, not changed by the news; all need the Otpravka token + user key and count against the Otpravka daily quota): `GET /postoffice/1.0/nearby` (by coordinates; has `filter-by-office-type`, `hide-private`, `is-closed`/`is-temporary-closed` in the response), search by index, by address, services of an office, settlement postcodes, and `GET /1.0/unloading-passport/zip?type=OPS/PVZ/APS/ALL` (the passport dump we already know). The page «Полный справочник отделений» (`ops-dictionary`) is a UI component with no data in the article. `GET /1.0/settings/limit` → `allowed-count`, `current-count` is documented (page `otpravka-api-api-request-count`).

## 4. Quick start (page `quick-start`, «API Отправка → Быстрый старт»)

- It is the **Otpravka** order flow, not tracking. A merchant needs **two keys**: **(1)** application authorisation **token** → `Authorization: AccessToken <token>`; **(2)** user authorisation **key** = `base64(login:password)` of the passport.pochta.ru account → `X-User-Authorization: Basic <key>`. Both go in every request, with `Content-Type`. Where: `otpravka-api-authorization-token` (token is e-mailed after API access activation and shown in cabinet settings; «Обновить токен» rotates it) and `otpravka-api-authorization-key`.
- Steps: create order (V1 or V2; **V2 returns barcode and order id in one response**), create a batch from ids, send electronic Ф103 (`checkin`) then fetch the documents zip, hand the batch over at the post office. Same as G measured.
- **Tracking is a third, separate credential** (the ApiKey token above), not mentioned in the quick start. There is **no single token for everything** (MEASURED: the Otpravka token is 403 on the tracking host). Batch tracking would be a fourth (SOAP login/password, by support request).
- A tracking token needs a cabinet registered as a legal entity («через сервис личного кабинета юридического лица»); private persons are excluded already by decision 1.

## 5. What changes in `decisions.md`

Note: **`decisions.md` has no item 7** (numbering jumps 6 → 8). If item 7 was meant to be tracking, the decision is simply not recorded; proposal below.

| # | Decision today | Effect of the new documentation | Proposed change |
|---|---|---|---|
| 2 | Points: widget backend, no passport dump, no fallback | **No new points API.** The only official sources are the Otpravka ones already known (`nearby`, by-index/address, passport zip). No news item on points. The widget-backend choice is unaffected | **Keep.** Optional: record `GET /postoffice/1.0/nearby` as the official live alternative for the "measure later" slot — it needs Otpravka keys and spends quota, so it is not a drop-in for a keyless map. No wizard impact |
| 3 | One method per destination kind, one tariff per instance; partner ПВЗ / «Доставка плюс» (54020): "not committed, decide by measurement" | **New knowledge:** 54020 **is** «Доставка плюс с объявленной ценностью» (live name, mailtype 54) = the product behind the new `mail-type: DELIVERY_PLUS` (from 16.09.2026). ЕКОМ Маркетплейс/Тендер order types are **temporarily disabled** by Russian Post. The public tariff doc's catalogue is stale (still lists 53030/53070) | **Keep the structure.** Rename the open item to «Доставка плюс (54020 / DELIVERY_PLUS)»; show only products where the Otpravka `/1.0/settings` flags allow them (already in decision 4); never build on the doc's Appendix 1 — build the product list from `/1.0/settings` + a live "price AND no business error" check. Order create for DELIVERY_PLUS stays UNVERIFIED (account flag off) |
| 4 | Prices via Otpravka `POST /1.0/tariff`, fallback to public calculator, own quota counter | `tariff.pochta.ru/v2` is now **officially documented** with `dogovor=<ИНН>-<номер>` for contract pricing, `errorcode`, `tariff+delivery` in one call, no auth, no published limit. `POST /1.0/tariff` page is unchanged. Neither returns several products per call | **Keep** Otpravka-first. Strengthen the fallback: it is documented, not a reverse-engineered page. New option for merchants whose contract is known: public `dogovor=` as a **second source with contract prices** that does not spend Otpravka quota — but an unknown/wrong contract is silently ignored, so it needs a visible "contract applied" check via `isdogovor` in the response. Handle `errorcode=1` default (HTTP 400 for business errors, e.g. 1204, 2006) in the HTTP client. One call per tariff-instance; cache by route/weight/value/tariff as planned. Daily-limit endpoint `GET /1.0/settings/limit` can feed the quota counter instead of guessing |
| 7 (absent) | — | Tracking moved to **token REST**, one barcode per call, ~5 000 req/day (FAQ) vs 100 (old Terms) | **Add a decision:** tracking = new REST `…/tracking/api/external/v1/info`, header `Authorization: ApiKey <token>`; a separate optional field «Токен API трекинга» (long string, ~1 050 chars: no short maxlength; secret, declare for log masking like `Authorization` / `X-User-Authorization`); no SOAP, no login/password, no batch SOAP in release 1; poll with backoff and a hard daily cap well under 5 000 (see the licence-server rule: state worst-case requests/day); one barcode per request, so the poller needs a queue and a per-barcode interval. Fallback when no token: a merchant-visible "tracking off" state, or the public pochta.ru tracking link, not a silent drop |
| 11 | International by measurement (account refuses → release without) | Tracking REST accepts S10 international codes (MEASURED, `RA644000001RU` → 200). Russian Post publishes its own reference lists: Otpravka `otpravka-api-dictionary-countries`, `…-currencies`, and the tariff service `GET /v2/dictionary/country` | **No change to the scope rule.** Supports "rebuild the country table from Russian Post's own reference" — use the Otpravka country dictionary (READ only; not called today) |
| 12 | Wizard: Otpravka keys with check → sender point → defaults → usable tariffs | There is a **third credential (tracking token)** and it is different from the two Otpravka ones | Keep the wizard short: the tracking token is an **optional** step after the Otpravka check (skip = tracking off), placed last; check by one call with an invalid barcode (403 = bad token, 400/1003 = token OK). Explain in a sentence where to get it («Настройки» of the cabinet; legal-entity cabinets only). Do not make the tracking token block order export |

## 6. Live calls made (15 requests, all read-only)

Otpravka (3): `GET /1.0/batch?page=0&size=5`, `GET /1.0/batch/573/shipment?page=0&size=5`, `GET /1.0/batch/574/shipment?page=0&size=3` → two historical test barcodes. Tracking (8): single OK; bad token (403); invalid barcode (400/1003); invalid + valid; Otpravka token on tracking (403); two valid comma-separated; two valid repeated param; S10 `RA644000001RU` (200). Public tariff (4): 23030 (200), 23080+delivery (400/2006), 54020 (200), 53030 (400/1204). No order created, nothing modified. The batch dumps in `live/` hold test-account recipient data; they are outside the repo.

## 7. Unverified / gaps

- Whether the old SOAP single (`rtm34`, login/password) still answers, and its sunset date — not written anywhere; no tracking login to try.
- Real quota of the token tracking (5 000/day per FAQ vs 100/day per old Terms), per-minute limit, whether 4xx count, whether a legal-entity cabinet without a contract differs. Not probed.
- Full list of high-level status group codes and of tracking error codes; which of `Language` header / `language` query param is honoured.
- Exact cabinet path of the tracking token (screenshots are not in the article text); whether activation needs a manual step beyond the settings page.
- Contract pricing (`dogovor`) with a real contract; `DELIVERY_PLUS` order create; the `unloading-passport`, `nearby`, `/v2/dictionary/country` calls (READ only).
- The page edit dates (API does not expose them), so I cannot say when the tracking spec was last changed beyond the news dates.

## 8. Not checked

The ~100 Otpravka reference pages beyond the ones named were skimmed by title only (order create V2 field changes for DELIVERY_PLUS, timeslot API, claims, returns); the operations dictionary was read only for codes 53–55; `custom-components`, ЭДО, subaccount and direct-mail pages were not read.

## Related

- [README](README.md) — the consolidated fact base
- [decisions.md](decisions.md) — items 7 and 12
