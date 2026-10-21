# Task E — can a Russian Post plugin drop the «Виджет» cabinet?

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Read and probed on **21.10.2026** (MSK, ~03:00). Tags: **[LIVE]** my own read-only unauthenticated request; **[OFF]** official page/spec read directly;
**[SRC]** third-party source code/repo; **[V1]** the v1 plugin in `plugins-reference/woodev-russian-post/`; **UNVERIFIED** = needs credentials or could not confirm.
No authenticated call was made. No write endpoint of any service was called (I did NOT call `cms.pochta.ru/api/cms/install|uninstall|orders_public`).
Raw material: `<local scratch path, not committed>` (probe scripts, JSON, decoded bundles; the ~1 MB open-data zip was deleted after inspection).
Builds on `report-b-api.md` (not redone).

## 0. Verdict on the hypothesis (TL;DR)

| Claim of the operator | Result |
|---|---|
| "Russian Post has no public API listing offices, so the widget is the only map source" | **Refuted.** At least four ways to get point lists exist without the widget cabinet (otpravka `postoffice/*` + `unloading-passport/zip` [OFF, auth], public `tariff.pochta.ru` per-index dictionary [LIVE, no auth, no list], the widget's own backend `widget.pochta.ru/api/pvz` [LIVE, no auth, unofficial], pochta.ru's own map backend [LIVE, no auth, unofficial]). Only the otpravka ones are official **and** usable for a bulk list; their partner-ПВЗ coverage is **UNVERIFIED**. |
| "Widget is used for the native map and its price" | **Confirmed, and more.** v1 also uses the widget/CMS backend for (a) **server-side checkout tariffs** (`/api/pvz/pick_up_tariff_public`, `courier_tariff_public`), (b) installing the shop as a "CMS module" (`cms.pochta.ru/api/cms/install` → `guid_id/guid_key`, dashboard link), (c) exporting orders into the widget cabinet (`/api/cms/orders_public`), (d) point details (`/api/pvz/{id}`), (e) site settings (`/api/sites/public_show`, `accountType=wordpress`). [V1 `includes/api/api.php:26-75`, `includes/classes/lifecycle.php:60-90`] |
| "The widget tariff returns ONE tariff and the customer cannot choose" | **Confirmed for the widget UI**: cabinet has *«Тип и приоритет доставки»* (ordered list of shipment types) and *«Использовать наиболее выгодный тариф»* (`shipmentPriorities`, `useBestTariff` in `GET /api/sites/{id}/settings`). **But** v1 consumes a server-side endpoint that returns an **array of rates with `shipment_type`** and loops over it (`abstract-shipping-methods.php:507-540`), so the backend can return several; the one-tariff behaviour is a map-UI behaviour. |
| "Whatever is done in Виджет is exported into Отправка anyway" | **UNVERIFIED.** The widget cabinet itself says its modules give "бесшовную интеграцию с сервисом «Отправка»" and require a contract with Pochta (decoded chunk `wch/682.js`), which supports the idea, but I found no endpoint/doc describing the widget→otpravka export, and I cannot log in. |
| "Drop the widget cabinet entirely" | **Technically feasible for map + tariff + order creation** (otpravka v2 order create takes `delivery-data.delivery-point-index`, [OFF] B-report). Open items: partner-ПВЗ list from an official source, and whether contract prices come back (see §3, §7). |

## 1. Office / postamat / ПВЗ list without the widget cabinet

### 1.1 Matrix

| # | Source | Official? | Auth | Quota | Coverage | Fields | Bulk / cacheable? |
|---|---|---|---|---|---|---|---|
| A | otpravka `GET /postoffice/1.0/nearby` | [OFF] spec | token + user key | **UNVERIFIED** whether `postoffice/*` counts toward the daily limit (spec says token "выделяет квоты"; no per-endpoint note) | `filter-by-office-type=true` (default) → ГОПС, СОПС, ПОЧТОМАТ; `false` → "все" (does "все" include ППВЗ? UNVERIFIED) | `address-source, latitude, longitude, postal-code, type-code, type-id, region, settlement, district, working-hours[], current-day-working-hours, next-day-working-hours, holidays[], works-on-saturdays/sundays, is-closed, is-temporary-closed(+reason), is-private-category, prescribed, distance` | radius search only (`top`, `search-radius`, `current-date-time`, `hide-private`). Not a dump. |
| B | otpravka `GET /postoffice/1.0/nearby.details` | [OFF] | same | same | same + `filter` (ROUND_THE_CLOCK, WORK_ON_SUNDAY, RPO_CENTER, BIG_PARCELS, EMS, FORSAGE, WESTERN_UNION), `hide-temporary-closed`, `hide-not-available`, `full-address-only` | same as A | has **`offset`** → pageable around a centre point; still radius-bound |
| C | otpravka `GET /postoffice/1.0/{index}` / `/brief` / `/{index}/settlement` / `by-address` / `settlement.offices.codes` / `{index}/services[/group]` | [OFF] | same | UNVERIFIED | one index at a time; `by-address` default top 3, `is-matched` flag | A's fields + `nearest-postoffice`, phones (brief), services | per-index: usable as lazy validator, not for a list |
| D | **otpravka `GET /1.0/unloading-passport/zip?type=OPS\|PVZ\|APS\|ALL`** | [OFF] spec: "Выгружает данные ОПС, ПВЗ, Почтоматов из Паспорта ОПС"; returns zip with one `TYPEdd_MMMM_yyyy.txt` | same | one call per refresh (quota effect UNVERIFIED) | enum has **PVZ** and **APS** separately from OPS → the only official list that *names* ПВЗ. Whether PVZ = partner ППВЗ (Ozon-type) or ECOM ПВЗ is **UNVERIFIED** | **UNVERIFIED** (txt column layout not documented; no sample available without credentials). A third-party PHP SDK wraps it (`LapayGroup/RussianPost` `getPostOfficeFromPassport`, [SRC]) but documents no columns either | **Best candidate for "download once a day, cache locally"**. How to measure: one authenticated call per type; record rows, columns, presence of lat/long, schedule, partner brand, delivery-point-index (9xxxxx), size, and whether `GET /1.0/settings/limit` `current-count` moves by 1 |
| E | `tariff.pochta.ru/v2/dictionary/postoffice/{index}` (and `?id=a,b,c`, **max 10**) | [OFF] doc §2.7 of `post-calculator-api.pdf` v3.21 | **none** [LIVE] | none documented | **any index incl. 9xxxxx postamats and 98xxxx ППВЗ**: 918594 → "МОСКВА-ПОЧТОМАТ (АПС)", 980660 → "МОСКВА ППВЗ" (`partner:1`), 127220 → ГОПС [LIVE] | `index, type, typei, name, address, geo.lat, geo.long, region ids, parent, root, utc, cutoff, weight-max, pay-card, pay-money, partner, pvz, closed, item-check-*, courier…`. **No schedule, no brand.** | no list endpoint (no-index call → 400 "Не указан код почтового объекта"). Useful to *enrich/validate* a point by index (e.g. `weight-max`, `pay-card`), not to discover |
| F | **Widget backend** `POST https://widget.pochta.ru/api/pvz` + `GET /api/pvz/{id}` | **No** (cabinet-internal; widget.js only iframes `widget.pochta.ru/map/`) | **none** except a numeric widget `settings_id`; the landing's demo id 6561 worked [LIVE] | none seen | whole country in one bbox: **31 892 `russian_post` + 3 439 `postamat` + 812 `additional_pvz` (partner) = 36 143** [LIVE 21.10.2026] | list row: `id, type, deliveryPointIndex, geo{Point lon,lat}, address{index,region,place,street,house,corpus…}`. Detail `/api/pvz/{id}`: `brandName` ("Почта России", "Почта Еком"), `deliveryPointType` (ГОПС/Почтомат/ППВЗ), `getto` (e.g. "…расположен в помещении Ozon"), `workTime[]`, `holidays[]` (df/ds/work[…]), `cardPayment, cashPayment, contentsChecking, withFitting, partialRedemption, returnAvailable, functionalityChecking, boxSize (postamat S/M/L/XL), closed, temporaryClosed, acceptEcom, insertedAt, updatedAt` | records are rewritten nightly (`insertedAt 2026-10-20T03:00:11`, `updatedAt` 03:01 for same day) → a daily refresh is natural. **But** a full detail dump = 36 143 GETs (list rows carry no schedule); list pages ≤200 rows (my bbox call: pageSize 200 accepted) ≈ 181 pages. ToS/stability risk: **high** (internal API, CORS reflects any Origin with credentials, no documentation, can change with the cabinet SPA). I did **not** bulk-download |
| G | **pochta.ru public map backend** `POST https://www.pochta.ru/suggestions/v2/postoffices.find-from-rectangle` (+ `postoffice.find-by-postalcode?postalCode=`, `postoffice.find-nearest-by-postalcode-vacancies`) | **No** (the site's own Next.js app, `/offices`) | **none** [LIVE] | none seen | ОПС (ГОПС…), **ПОЧТОМАТ**, **ППВЗ**, УДПП, ОПП, ТИ, ГСП… In a 0.10°×0.15° Moscow box: 446 objects total (37 postamats, 22 ППВЗ in the first 100) vs 146 in the widget (widget hides some types/closed) [LIVE]. `find-by-postalcode` resolves 918594 and 980660 too [LIVE] | rich: `postalCode, typeCode (ГОПС/ПОЧТОМАТ/ППВЗ), typeId, latitude, longitude, address{fullAddress, addressGuid(ФИАС), …}, workingHours[]+lunches, holidays[], isClosed, isTemporaryClosed, windowsCount, phones, serviceGroups, opsService filters, availability(disability), corporateClients[{Озон,Вайлдберриз}], online, eas4`; body takes `topLeftPoint/bottomRightPoint{latitude,longitude}, precision, onlyCoordinate, extFilters[NOT_CLOSED,NOT_PRIVATE,NOT_TEMPORARY_CLOSED,ONLY_ATI], offset, limit` | `totalCount` + `offset/limit` → pageable; richest schedule data of all free sources. Same risk class as F (unofficial; site app can change; bot-protection can appear) |
| H | Open reference of indexes: `https://www.pochta.ru/support/database/ops` → `P_Indx…zip` (DBF) | **Yes**, "Эталонный справочник почтовых индексов объектов почтовой связи" [OFF page + download] | none | – | **61 334 records** (page says generated **19.10.2026**; I downloaded and parsed the file: 26.8 MB DBF in a 1.0 MB zip, cp866) incl. 5 008 `ПОЧТОМАТ`, 41 169 `О`; **no coordinates, no street address, no schedule, no ППВЗ** (980660 absent; 918594 present as ПОЧТОМАТ). | `INDEX, OPSNAME, OPSTYPE, OPSSUBM, REGION, AUTONOM, AREA, CITY, CITY_1, ACTDATE, INDEXOLD`. Incremental `NPIndx[K].dbf` update files + a second archive family `Dlv_Lim*.zip` (delivery limits per UFPS, not examined) | cacheable, but cannot feed a map (no coordinates). Good as an offline index→name/region validator |
| I | data.gov.ru | – | – | – | No Russian Post dataset found. `data.gov.ru` did not answer from this machine (curl code 000 at 02:57); a web search found **no** open-data set of ОПС there (UNVERIFIED either way); the pochta.ru "База данных" page (row H) is Pochta's own open reference | – | – |
| J | OpenStreetMap `amenity=post_office` | community | none | – | uneven quality; no postamat/ППВЗ identity (search snippet only) | – | not considered further |

### 1.2 What a map needs vs what each source gives

Map layer needs: id, lat/long, type, address, schedule, limits (weight/size/payment).

| Need | A/B (otpravka nearby) | D (passport zip) | E (tariff dict) | F (widget backend) | G (pochta.ru map) |
|---|---|---|---|---|---|
| lat/long | yes | UNVERIFIED | yes | yes | yes |
| type (ОПС/почтомат/ППВЗ) | ОПС+почтомат (default); ППВЗ UNVERIFIED | `type` param OPS/PVZ/APS; per-row type UNVERIFIED | yes (`type`, `partner`) | yes (`type`, `deliveryPointType`) | yes (`typeCode`) |
| schedule | yes (`working-hours`, `holidays`) | UNVERIFIED | no | yes (detail only) | yes (in list) |
| limits (weight / pay) | no | UNVERIFIED | `weight-max`, `pay-card`, `pay-money` | `boxSize`, `cardPayment`, `cashPayment`, `contentsChecking`, `withFitting`… (detail only) | filters/serviceGroups |
| `delivery-point-index` for order create | `postal-code` = index | UNVERIFIED | yes | yes (`deliveryPointIndex`) | yes (`postalCode`) |
| bulk list | no (radius) | **yes (one call)** | no | yes but 36k calls for details | paged, 100+/page |
| needs a Pochta account | yes | yes | no | no | no |

## 2. Does the widget show points no API returns?

- Widget backend (F) shows **812 `additional_pvz`** (partner ПВЗ, "Почта Еком", e.g. "…в помещении Ozon"), index range 98xxxx, plus 3 439 postamats (9xxxxx) and 31 892 ОПС [LIVE 21.10.2026, demo widget with partner/postamat toggles on].
- Are those 812 returned by an **official** API? **UNVERIFIED** (needs credentials). Evidence both ways:
  - for: otpravka has enums `DELIVERY_POINT` = "ПВЗ" vs `PICKUP_POINT` = "АПС (почтомат)" and the passport dump has a separate `PVZ` type [OFF]; order v2 accepts `delivery-data.delivery-point-index` [OFF, B-report];
  - against / unknown: `nearby` says the default filter returns "ГОПС, СОПС, ПОЧТОМАТ" and `false` returns "все" without defining it [OFF]; the open index (H) contains **no ППВЗ** (980660 absent) but does contain postamats.
- ППВЗ are visible without any login in **two** unofficial places (F, G) and are resolvable by index in the public tariff dictionary (E, `partner:1`).
- Postamat counts disagree: F = 3 439, H = 5 008 `ПОЧТОМАТ` rows (includes closed/inactive history), pochta.ru e-commerce page = 7 500 (B-report). I did not reconcile; a map should use a source that carries `closed/temporaryClosed`.
- Practical reading: **ЕКОМ-only/partner points exist in the widget feed; whether the official otpravka feed carries them is the single biggest open question for dropping the widget** — one authenticated `unloading-passport/zip?type=PVZ` call answers it.

## 3. Tariffs

### 3.1 The three calculators

| | tariff.pochta.ru `/v2/calculate/tariff[/delivery]` | otpravka `POST /1.0/tariff` | Widget (`/api/data/free_tariff_by_settings`, `/api/pvz/*_tariff_public`) |
|---|---|---|---|
| Auth | none [LIVE] | token+key | widget id (free_tariff) / `account_id=guid` (v1 `*_tariff_public`) |
| Several tariffs per call? | **No** — one `object` code per call (23030, 23020, 27030, 47030, 7030, 54020, 24030…); the caller fans out N calls and shows N options | **No** — one `mail-type`+`mail-category` per call; same fan-out. Origin index comes from the user profile [OFF "Индекс ОПС точки отправления берется из профиля клиента"] | `free_tariff_by_settings` returned **one** (`type` e.g. ONLINE_PARCEL) [LIVE]; the *map UI* picks one by priority list or "best tariff"; v1's `pick_up_tariff_public` returns an **array** with `shipment_type` that v1 turns into several WC rates [V1] |
| Contract price | param `dogovor=<ИНН>-<№ договора>` [OFF §1]. **I tested a fake contract: HTTP 200, `isdogovor:0`, public price unchanged → silently ignored; real-contract behaviour UNVERIFIED** | spec of `/1.0/tariff` mentions **no** contract/discount [OFF]; whether it applies contract terms is **UNVERIFIED** | cabinet *«Индивидуальный тариф»* = toggle + «Номер Договора» + «ИНН» (decoded chunk `wch/879.js`) → same `dogovor` mechanism on Pochta's side; `free_tariff` response carries `tariff.isDogovor` (false for the demo) [LIVE] |
| Output | `pay/paynds` kopecks, `ndsrate 22`, `delivery{min,max,deadline}`, and the intake/destination `postoffice[]` with geo | `total-rate`, `ground-rate`, `avia-rate`, `notice-rate`, `insurance-rate`, `fragile-rate`, … `delivery-time{min,max-days}` kopecks | `tariff{pay,nds,total,isDogovor}`, `delivery{deliveryMin,deliveryMax,description}` |
| Merchant markup | none (client adds) | none | cabinet-side: *«Стоимость + наценка / скидка / фиксированная / по тарификатору»*, rounding, free-shipping thresholds, processing hours — **all replicable in plugin settings** |
| Quota | none documented; session cookie | counts toward otpravka daily limit (assumed, UNVERIFIED) | none seen |

### 3.2 Live numbers [LIVE 21.10.2026 ~03:00, `tariff.pochta.ru/v2/calculate/tariff/delivery`, engine 3.21.12.872, 1 kg, sumoc 1000 ₽ where the object needs it, `from=101000`]

Prices are `pay` (without VAT) / `paynds` (with VAT 22%) in ₽; days = `delivery.min–max`.

| Route | Посылка онлайн 23030 | Посылка 1 класса 47030 | EMS 7030 | Доставка плюс + ОЦ 54020 | Курьер онлайн 24030 | Посылка стандарт 27030 | ЕКОМ 53030 / 53070 |
|---|---|---|---|---|---|---|---|
| 101000 → 630000 (Новосибирск) | 399.00 / 486.78, 6–7 d | 580.33 / 708.00, 2–3 d | 1200.00 / 1464.00, 2–3 d | 409.00 / 498.98, 6–7 d | **error**: "доставка курьером … не осуществляется, т.к. у него нет УКД" | **error**: "нельзя принять в 101000, т.к. его нет в общем списке мест приема" | **error**: "услуга тарификации не осуществляется после 01.01.2025" (delivery days still returned: 3–5) |
| 101000 → 190000 (Санкт-Петербург) | 206.00 / 251.32, 2 d | 440.16 / 537.00, 1–2 d | 850.00 / 1037.00, 1–2 d | 216.00 / 263.52, 2 d | 407.00 / 496.54, 2 d | same error | same error |
| 101000 → 690000 (Владивосток) | 606.00 / 739.32, 12–13 d | 850.00 / 1037.00, 3–4 d | 1550.00 / 1891.00, 3–4 d | 616.00 / 751.52, 12–13 d | courier error (no УКД) | same error | same error |

To pickup points (101000 →):

| Destination | 23030 / 23020 Посылка онлайн | 54020 Доставка плюс |
|---|---|---|
| 918594 postamat (Москва) | **error**: "Доставка в 918594 «МОСКВА-ПОЧТОМАТ (АПС)» не осуществляется" | 170.00 ₽ (no VAT), 1–2 d |
| 980660 ППВЗ (Москва) | **error** ×2: "Отправление нельзя доставить в ПВЗ/ППВЗ" and "Доставка в 980660 … не осуществляется" | 170.00 ₽, 1–2 d |

Observations that change the plan:
1. **ЕКОМ 53030/53070 can no longer be tariffed** on the public calculator (since 01.01.2025). The live pickup/postamat product is **54020/54060**, which the PDF Appendix calls "ЕКОМ Маркетплейс с объявленной ценностью" and the live response names **"Доставка плюс с объявленной ценностью"** — one product, two names (matches the "Доставка Плюс" row of the B-report; mapping to otpravka `ECOM_MARKETPLACE`/`DELIVERY_PLUS` enums still UNVERIFIED).
2. **Posylka onlayn did not accept postamat/ППВЗ destinations in the public calculator on this route**, while the widget cabinet text says postamats are for "Посылка Онлайн" and "ЕКОМ Маркетплейс" (decoded `wch/879.js`). Not reconciled (could be route/date/origin specific); probe more routes before relying on either.
3. The widget demo's `free_tariff_by_settings` for `indexTo=918594`, 1 kg returned `pay 17000 / total 20740` — **numerically equal to 54020's 17000 kopecks** above, though labelled `ONLINE_PARCEL`. Origin used by the demo widget is unknown (its `senderZipCode` is null), so equality may be coincidence.
4. So the public calculator can give a customer **several options per route** (e.g. Посылка онлайн vs 1-го класса vs EMS vs Доставка плюс) with prices and deadlines in ~5 GETs; `postoffice[]` in the response also hands back destination coordinates.
5. Not available from the public calculator: **COD fees** (needs `sumnp`/objects 23040/54060 — returned 200 with the same `pay`, i.e. fee not obviously added; not examined), contract discounts (see 3.1), and origin restriction (27030 requires an acceptance OPS as `from`).

## 4. Without the widget: what is lost?

From v1 code + decoded cabinet bundles.

| Widget/CMS-cabinet function | Used by v1 | Replacement / risk |
|---|---|---|
| Map (Yandex Maps in iframe, `ecomStartWidget`) | `assets/js/frontend/map.js:19`, `checkout.php:71` | own framework map layer + a point feed (§1) |
| Point types & visibility toggles (ОПС / партнёрские / почтоматы, "только ЕКОМ", box size for postamats) | cabinet setting | plugin settings; needs ЕКОМ-capable flag per point (`acceptEcom` in widget detail; unknown elsewhere) |
| Checkout tariff incl. best-tariff/priorities, markups, rounding, free-shipping thresholds, processing days, default weight/box, `averageInvestmentsCost` | `get_pick_up_tariff_public`, `get_courier_tariff_public`; `wc_russian_post_get_site_setting('useCartValue')` | tariff.pochta.ru fan-out + plugin-side markup rules |
| **Individual (contract) tariff** (ИНН + № договора) | cabinet only | `dogovor=` param — unverified that it yields contract rates; **must be measured with a real contract** |
| CMS-module registration: `cms.pochta.ru/api/cms/install` → `guid_id/guid_key`, e-mail "Your dashboard link", `barcode_link`, `status_link` callbacks | `lifecycle.php:60-100` | not needed if no cabinet; `barcode_link/status_link` suggest Pochta calls back into the shop (what for: UNVERIFIED) |
| Order export into widget cabinet `…/orders_public` ("Widget" export type vs "Otpravka") | `order.php:238,327`, `export-order/widget.php` | replaced by otpravka `PUT /2.0/user/backlog` (already v1's other export type) |
| Address autocomplete / geocode inside the widget | widget | own geocoder / `clean/address` |

Migration of existing v1 widget users (what can be said):
- They hold `wc_russian_post_guid_id/key` options and a widget-cabinet account created automatically by the plugin [V1 `lifecycle.php:79-89`]. Their **otpravka** credentials (`account_token/name/password`) are separate settings [V1 `api.php:196-211`], so dropping the widget does **not** require any change in the otpravka cabinet by itself.
- Orders that were exported with the "Widget" type live in the widget cabinet; whether they were ever mirrored to otpravka is **UNVERIFIED** (operator says yes; I found no confirming doc). Merchants who used only the widget export have **no** otpravka batch history for them.
- Widget-side settings (priorities, markup, individual tariff, sender zip, default weight) would have to be re-entered in plugin settings — a one-time import is impossible without the cabinet's authenticated API (`GET /api/sites/{id}/settings?url=` returned the full settings JSON for the demo id without auth [LIVE], so a v2 **migration step could read the merchant's own settings by their site id** if the id was stored; v1 stores `guid_id`, whether that maps to a site id is UNVERIFIED).
- To uninstall cleanly, v1 calls `cms.pochta.ru/api/cms/uninstall` with the guid pair [V1 `lifecycle.php:49-56`].
- ЕКОМ/«Доставка плюс» orders in otpravka need no widget: v2 order has `delivery-data` / `ecom-data` with `delivery-point-index` (B-report §2).

## 5. Unofficial SDK `Virenbar/PochtaAPI` [SRC, read via GitHub API 21.10.2026]

| | |
|---|---|
| Language / licence | C# (netstandard2.0, net452), **MIT** |
| Activity | repo created 2021-06-18; releases v1.0.1 (2021-06-22), v2.0.0 (2021-07-13); last push **2025-07-09** (docs/workflow chore); real code changes end 2022; 1 star; 0 open issues |
| Wraps | **Tracking SOAP** (single + batch ticket flow); **otpravka** (`SendingClient.cs`): `backlog/{id}`, `backlog/search`, `batch`, `batch/{name}`, `batch/{name}/shipment`, `user-shipping-points`, `settings`, `settings/limit`, `clean/address\|physical\|phone`, **`POST tariff`** |
| Does NOT wrap | any `postoffice/*`, `unloading-passport`, `tariff.pochta.ru`, widget or cms endpoints |
| Value for this task | none for offices/tariffs; confirms the otpravka tariff takes one `MailInfo` per call. |
| Better reference | `LapayGroup/RussianPost` (PHP, MIT, last push 2026-08-01, "SDK 2.0 / PSR-18"): has `searchPostOfficeByIndex/Address/Coordinates` (nearby), `getPostOfficeServices`, `getPostOfficeFromPassport($type)` with `OpsObjectType ALL/OPS/PVZ/APS`, plus the public tariff calculator provider. It documents **no** column layout of the passport file either. |

## 6. `cms.pochta.ru/docs`

- `https://cms.pochta.ru/docs` and `https://widget.pochta.ru/` / `/docs` all return the **same Angular SPA shell** (HTTP 200, nginx/1.22.0; `Last-Modified` 18.03.2026 16:16–16:19 for `cms.pochta.ru/docs`, `widget.pochta.ru/docs` and `widget.js`, i.e. one deploy; different bundle hashes per host). So it is **not** an ECOM API reference and not a separate document set; it is the widget cabinet's documentation/UI. [LIVE]
- Contents I could extract from the bundles: widget constructor/settings (callback function, shipment types and priorities, cost calculation, pickup-point toggles, individual tariff), a "Готовые модули для интеграции" block saying the free modules integrate with «Отправка» and **require a contract with Pochta**, and an "для разработчиков" link. No OpenAPI/endpoint reference for `/api/cms/*` or `/api/pvz/*` was found, i.e. those backends are **undocumented**. [decoded `wch/682.js`, `wch/879.js`]
- Maintained? Served and rebuilt as recently as **18.03.2026** (≈7 months before this read); the operator's "unmaintained" is plausible for documentation, not for the service (data rows updated nightly, `lastPinged` 2026-10-05 on the demo site).
- `cms.pochta.ru` as an API host: v1 posts to `https://cms.pochta.ru/api/cms/{install,uninstall,orders_public}` [V1 `api.php:62-92`, URL built as `https://{type}.pochta.ru`]. I did not probe it (writes).

## 7. Final table: need → source without widget → confidence → risk

| Need | Source without the widget cabinet | Confidence | Risk |
|---|---|---|---|
| Point list: ОПС + почтоматы with coordinates, type, address | otpravka `unloading-passport/zip?type=OPS` and `APS` (D) cached daily; fallback `nearby.details` (B) | **Medium** (endpoint documented; column set UNVERIFIED) | needs credentials in every shop; quota effect UNVERIFIED; one-shot answer by a measurement call |
| Point list incl. **partner ПВЗ (ППВЗ / "Почта Еком")** | official: `unloading-passport?type=PVZ` (D) — UNVERIFIED; unofficial: widget backend (F), pochta.ru map backend (G) | **Low–Medium** officially; **High** that the data exists unofficially (36 143 points counted, 812 partner) | unofficial = ToS and breakage risk; official may not carry partners |
| Schedule / holidays per point | otpravka A/B/C (`working-hours`, `holidays`); G (`workingHours`, `holidays`); F detail (`workTime`, `holidays`) | High (A/B documented) | F needs per-id GET; E has none |
| Limits per point (weight, card/cash, postamat box size, contents check) | E (`weight-max`, `pay-card`, `pay-money`) public; F detail (`boxSize`, `cardPayment`…) | Medium | E is per-index (≤10/request); F unofficial |
| Validate a chosen point index at checkout / pre-order | E `dictionary/postoffice/{index}` (public, no auth) or otpravka `{index}` | **High** | none beyond the calculator's normal availability |
| Several shipping options for the customer to choose | tariff.pochta.ru fan-out over `object` codes (§3.2) | **High** (live-verified for 6 products × 3 routes) | ЕКОМ 53xxx dead since 01.01.2025; Посылка стандарт needs an acceptance OPS as origin; per-product destination limits (postamat/ППВЗ → only 54020 in my probe) |
| Price shown = price charged | same engine for display and for `PUT backlog`; final amount is invoiced by Pochta under the contract | Medium | contract discount not proven to be applied by `/v2` or `POST /1.0/tariff` — **UNVERIFIED**, must be measured |
| Contract (individual) tariff | `dogovor=<ИНН>-<№>` on tariff.pochta.ru (documented); possibly `POST /1.0/tariff` | **Low** (fake contract ignored silently, no way to tell success) | wrong price if contract rates do not apply; measure with a real contract: compare `/v2` with and without `dogovor`, and `POST /1.0/tariff`, against an actual invoice line |
| Merchant markup / rounding / free shipping / default weight | plugin settings (already the plugin's job) | High | none |
| Create order to a pickup point | otpravka `PUT /2.0/user/backlog` with `delivery-data.delivery-point-index` (+`delivery-point-type`) | High (spec) | needs the index of the point from the chosen feed; `index-to` must not be used for ПВЗ/postamat (spec) |
| ЕКОМ/«Доставка плюс» order flows | otpravka v2 `ecom-data`/`delivery-data` | Medium (B-report; product naming mapped only by tariff dictionary evidence) | enum naming `ECOM_MARKETPLACE` vs `DELIVERY_PLUS` unreconciled |
| Widget-cabinet order history for existing merchants | none (stays in the cabinet) | – | Merchants lose that view; mirroring into otpravka UNVERIFIED |
| Import of old widget settings on upgrade | read `GET widget.pochta.ru/api/sites/{id}/settings` (worked unauthenticated for demo id) | Low | unofficial; id may not be stored by v1 |
| Quota pressure from points + tariffs | points: daily zip (D) + local cache = ~1 call/day; tariffs: public calculator off-quota | Medium | quota counting of `postoffice/*` UNVERIFIED |

## 8. Not checked / how to measure

- **Everything behind otpravka credentials**: whether `nearby` (`filter-by-office-type=false`) and `unloading-passport?type=PVZ|APS|OPS|ALL` return ППВЗ/ЕКОМ partner points, their columns/size/encoding, and whether those calls move `GET /1.0/settings/limit` `current-count`. *Measure:* one call each with a real account, diff counts against the 36 143 / 812 / 3 439 / 31 892 widget totals above (same day), and read the limit before/after.
- **Contract pricing** through `dogovor` and through `POST /1.0/tariff` (needs a real contract; compare to an invoiced shipment).
- Posylka онлайн → postamat/ППВЗ refusal on the public calculator vs the widget text (probe other origins/routes, a Moscow-intra route, and `delivery-variant`).
- COD fees in the public calculator (objects 23040/54060 gave the same `pay`; not analysed).
- Whether widget-cabinet orders are really exported into «Отправка» (operator claim) and what `barcode_link/status_link` callbacks do.
- Rate limits / bot protection on F and G under sustained use (I made roughly 15 requests to the widget backend and under 10 to the pochta.ru map backend; no throttling seen). I deliberately did not bulk-download.
- data.gov.ru reachability (000 from this host) and the `Dlv_Lim*.zip` delivery-limit reference.
- Reconciling postamat counts (3 439 / 5 008 / 7 500).

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
