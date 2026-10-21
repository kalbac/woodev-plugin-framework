# Task G — live measurements on the operator's «Отправка» account (otpravka-api.pochta.ru), 21.10.2026

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Measured 21.10.2026 03:15–03:50 MSK with the account's three credentials (never written anywhere; raw samples have no tokens/logins/keys — checked by a string search over all files).
Tags: **[LIVE]** my own request; **[V1]** v1 plugin source; **[E]/[F]/[B]** sibling reports; **UNVERIFIED** = not measured, reason given.
Raw samples: `g-raw/` next to this file (142 files). Working scripts and `calls.log` (206 logged requests, no secrets): `<local scratch path, not committed>`.

Mid-task the coordinator widened the rules: **the cabinet is a TEST account, writes allowed** (orders, batch, documents, cancel, one `cms install`; still forbidden: courier call, physical hand-over, payment). Section 8 and 9 are the result. Quota stayed at **338 of 10 000** at the end.

## 0. TL;DR (the things that change the design)

1. **The cabinet is Russian Post's own demo cabinet**: org `ФГУП «Почта России»`, agreement «ТЕСТОВЫЙ ЛК» (kind CONTRACT, 27.05.2019). Consequence: *no price here can show a real merchant contract discount* — and none appeared (all 15 compared tariffs equal the public calculator to the kopeck). `WOODEV_POCHTA_PROD_CONTRACT` is empty, so the `dogovor=` step was skipped as instructed.
2. **`GET /1.0/unloading-passport/zip` is the answer to the «widget-free point source» question.** One request, ~1 s: `ALL` = **35 971 points with coordinates, schedule, holidays, address (+ГАР/ФИАС guids), ecom/payment/limit options**, including **all 812 partner ППВЗ** («Почта Еком» in Ситилинк/Яндекс Маркет/Ozon/Wildberries). The 812 partner points are *identical* to the widget feed (same 812 indexes); overall overlap with the widget feed is 35 915 of 36 163 (99.3 %). No widget cabinet, no account id.
3. **`/postoffice/1.0/nearby` also returns ППВЗ and ПОЧТОМАТ with the default filter** (docs say only ГОПС/СОПС/ПОЧТОМАТ); `nearby.details` does *not* (ГОПС/ПОПС only).
4. **The quota counts every request equally** (GET/POST, 2xx/4xx, passport zip, postoffice, tariff, clean, even `settings/limit`); allowed **10 000/day**; the counter displayed lags by minutes (flushes in jumps).
5. **Errors mostly come as HTTP 200** (tariff `errors[]`, order create `errors[].error-codes[]`, batch `errors[]`); the same bad document call returns 403 or 400 depending on the `Accept` header.
6. **Order create is much more lenient than the spec** and **one product (ONLINE_PARCEL COMBINED → postamat) takes the point in `ecom-data`, not `delivery-data`** (spec/report B say `delivery-data`). Barcode is returned at create time.
7. **ECOM_MARKETPLACE / DELIVERY_PLUS / BUSINESS_COURIER are priced by the calculator but rejected on order create** (`UNAVAILABLE_MAIL_TYPE`: not enabled for this account; account flags `deliveryPlusAllowed=false`, `deliveryToMarketplaceAllowed=false`, `deliveryToDoorAllowed=false`). Old batches (2022–23) prove ECOM_MARKETPLACE worked on this account before, so it is a per-account switch — a plugin must read these flags from `GET /1.0/settings`.
8. **The widget/CMS «account id» (guid) is NOT derivable from the three otpravka credentials.** `cms install` takes no credentials at all and creates an anonymous registration; the cabinet is bound to a person only through an interactive Pochta-ID OAuth login. Details §9.
9. **Tracking has no REST endpoint on this account**; `/1.0/shipment*` does not carry last-operation fields for never-moved test shipments; SOAP needs a separate tracking login that is not among the three credentials.

## 1. The account (item 1)

`GET /1.0/settings` (238 KB!), `GET /1.0/user-shipping-points` [LIVE; masked copies `settings-masked.json`, `shipping-points-masked.json`].

| Fact | Value |
|---|---|
| Org / agreement | `ФГУП «Почта России»` (the operator itself); agreement number «ТЕСТОВЫЙ ЛК», kind `CONTRACT`, date 2019-05-27, not expired; `api_enabled: true`; `blocked: false`; `debt-sum: 0` |
| Category | `client-category REGIONAL`, `sender-category CORPORATE_CLIENT`, `earning-category VIP_A_B`, `planned-monthly-number 200`, `mail-rank WO_RANK`; ЕСПП code present (`espp-code`) → COD calls are not blocked by the missing-ЕСПП error from report B |
| Shipping points («точки сдачи») | **27** in `available-shipping-points` (2 «top-level» `shipping-points`: 109012 Москва, 344961 Ростов-на-Дону). Each carries `operator-postcode` (intake OPS index, e.g. 109012, 117452, 140961, 190880, 197101, 344000 …), `ops-address`, `ops-region-code`, `available-mail-types`, `user-available-products`, `pre-postal-preparation`, `courier-call` (false everywhere), `vsd-enabled`, `temporary`, `return-address-type`. Which products exist **depends on the intake OPS** (109012: 58 product types; 353090 Новороссийск: 1) |
| Mail types (union over points) | 17: BANDEROL, BANDEROL_CLASS_1, BANDEROL_KIT, BUSINESS_COURIER, BUSINESS_COURIER_ES, ECOM, EMS, EMS_OPTIMAL, EMS_RT, EMS_TENDER, LETTER, LETTER_CLASS_1, ONLINE_COURIER, ONLINE_PARCEL, PARCEL_CLASS_1, POSTAL_PARCEL, SMALL_PACKET. **Not present:** ECOM_MARKETPLACE, DELIVERY_PLUS, COMBINED |
| Product types | 62 `{mail-type, mail-category, product-type}` triples (`g-raw/products.txt`). Categories seen: ORDINARY, ORDERED, SIMPLE, WITH_DECLARED_VALUE, WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY, WITH_COMPULSORY_PAYMENT, WITH_DECLARED_VALUE_AND_COMPULSORY_PAYMENT, **COMBINED_ORDINARY, COMBINED_WITH_DECLARED_VALUE, COMBINED_WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY** (the three COMBINED ones are listed under `ONLINE_PARCEL`) |
| Feature flags (what a plugin should read) | `deliveryPlusAllowed false`, `deliveryToMarketplaceAllowed false`, `deliveryToDoorAllowed false`, `postpaymentAllowed false`, `partialRedemptionAllowed false`, `codLending false`, `delivery_with-cod_enabled true`, `easy-return true`, `client-return false`, `hyper-local-delivery true`, `mmo-enabled true`, `fulfillment-enabled false`, `fz140-enabled false`, `judicialCashless false`, `account-esignature-enabled false`, `show-returns true`, `thermal-mode-forbidden`, `report-version 1` |
| Existing data | 13 historical batches (2021-08 … 2023-07): 10 × mail-type `COMBINED`, 3 × `ECOM_MARKETPLACE`; shipment barcodes of the 14-digit `80…` form. No real orders. |

Notes: `settings/limit` is the quota endpoint (§2). `GET /1.0/settings` is heavy (238 KB) — a plugin should cache it.

## 2. Quota (item 2)

- `GET /1.0/settings/limit` → `{"allowed-count":10000,"current-count":N}`. **10 000 requests/day** [LIVE]. Reset moment UNVERIFIED (would need to cross midnight; the field name is «в сутки» in the spec).
- **Everything counts, 1 per request.** Controlled experiment (flush F2 detected at 03:31:17 with 183; then exactly 58 requests: 3 tariff, 5 clean/address, 7 nearby, 11 `postoffice/{index}`, 2 passport zip (PVZ), 13 `settlement.offices.codes`, 17 deliberately **failing 400** nearby; then 3 limit polls): next flush at 03:33:23 → **244 = 183 + 61 = 58 + 3 polls** [LIVE `qexp.out`]. So passport zips, failing 4xx calls and the limit poll itself are each 1.
- **The displayed counter lags**: it stays flat for 2–4 minutes and then jumps (observed 1 → 44 → 117 → 172 → 179 → 183 → 244 → 338; early calls looked «free» only because of this lag — an early read of 1 after ~30 requests was a wrong signal). A plugin cannot use `current-count` for real-time throttling; it must keep its own counter.
- Total spent by this whole task: **338 at the end** (≈ 3 %). No 429 or throttling was ever observed (rate ≤ 1.6 req/s). 429 shape: UNVERIFIED (would need to exhaust the quota).
- Cost model for a shop: passport dump = 1 request (4 × `type` = 4); `settings` = 1; one tariff = 1; one `clean/address` call = 1 even with 12 addresses in the array (§5).

## 3. Point lists (item 3)

### 3.1 `GET /1.0/unloading-passport/zip?type=…` [LIVE, samples `passport-samples-3rows.json`]

**Must send `Accept: application/octet-stream`**; with `Accept: application/json` it returns `406 {"timestamp","status":406,"error":"Not Acceptable","path"}` (and the 406 still counts). Each type = 1 request, 0.8–1.7 s. The zip holds one `TYPE21_October_2026.txt`, which is **UTF-8 JSON on a single line**: `{"passportElements":[…],"unloadingDate":"2026-10-20T09:18:51.000Z","vsnapshot":"21_October_2026"}` — i.e. generated nightly (snapshot of 20.10 09:18 UTC, file stamp 01:15 MSK), not live.

| `type` | zip | JSON | rows | what is in it |
|---|---|---|---|---|
| `OPS` | 5.28 MB | 49.6 MB | **32 575** | ГОПС 9 610, СОПС 22 134, ППС 19, **ППВЗ 812** (partner points are inside OPS too) |
| `APS` | 474 KB | 4.4 MB | **3 396** | all `Почтомат`, brand «Почта России» |
| `PVZ` | 107 KB | 1.0 MB | **812** | all `ППВЗ`, brand «Почта Еком» (= the partner subset of OPS) |
| `ALL` | 5.86 MB | 54 MB | **35 971** | = OPS ∪ APS (32 575 + 3 396); PVZ ⊂ OPS |

Row schema (identical in all types): `address{addressType, index, region, place, street, house, corpus, building, room, office, location, area, manualInput}`, `addressFias{addGarCode, locationGarCode, regGarId, ads}` (ГАР guids), `latitude`/`longitude` (**strings**), `type`, `workTime[]` (7 Russian strings «пн, открыто: 09:00 - 18:00, перерыв: 13:00 - 14:00» / «пн, выходной»), `holidays[]` (per-date overrides, only ГОПС/СОПС), `ecom` («1»/«0»), `onlineParcel` («1»/«0»), `ecomOptions{cardPayment, cashPayment, contentsChecking, functionalityChecking, partialRedemption, returnAvailable, withFitting, weightLimit (kg), brandName, getto, typesizeId, typesizeVal}`, `brandName`.

Measured facts:
- **Key = 6-digit `address.index`, unique over the whole file** (0 duplicates); there is no separate object id. Index ranges: ОПС by region (1xxxxx–6xxxxx), postamats 9[0-9]xxxx (all 3 396 start with 9), ППВЗ 98xxxx (812).
- Coordinates present for 35 958 of 35 971 (13 ОПС without; all ГОПС/СОПС). Schedule present for 35 970. Holidays for 31 292 (ОПС only; APS/PVZ none).
- **No «closed» / «temporarily closed» flag** in the passport; points that are temporarily closed are simply *absent* (see 3.3).
- `ecom=1` exactly for ГОПС+СОПС (31 744); `onlineParcel=1` for only **11 postamats**. Postamat: `weightLimit` 20 kg (3 395 of 3 396), `getto` «Почтомат расположен в отделении почтовой связи» (2 946) or «Расположен в отделении…» (437); box sizes `typesizeVal` only on 34 rows («Коробка L», «Негабарит»). So a postamat dimension limit per cell is NOT in this file.
- **ППВЗ** (812): `weightLimit` 31.5 kg ×413, 10 ×280, 3 ×79, 30 ×40; `contentsChecking` true 806, `returnAvailable` true 809, `withFitting` 0, `cardPayment`/`cashPayment` 0; host named in `getto` (Ситилинк 353, Яндекс Маркет 207, Ozon 148, Wildberries 40 …). Payment options for ППВЗ are all false → COD at partner points is not signalled here.
- ГОПС/СОПС: `weightLimit` 0 for 31 712 of 31 744 (no limit given); `withFitting` 7 422; `cardPayment` 11.

### 3.2 `GET /postoffice/1.0/nearby` and `nearby.details` [LIVE, `nearby-*.json`]

- `filter` is **required** (`400 Required WorkTimeMode parameter 'filter' is not present` — Spring-style body). Valid: `ALL`, `ROUND_THE_CLOCK` (others I tried → bare 400). `current-date-time` only matters for open-now filtering.
- `distance` is in **metres** (max 2 943 for `search-radius=3`, the radius is in **km**).
- `top=100` is honoured (Moscow, 3 km radius returned exactly 100 → caller must page by shrinking the radius; no `offset` on `nearby`, `nearby.details` has `offset` per report E).

| City (3 km) | `nearby` (default `filter-by-office-type=true`) | `nearby` (`=false`) | `nearby.details` (either) |
|---|---|---|---|
| Москва | 100: ГОПС 53, **ППВЗ 18, ПОЧТОМАТ 29** | 100: ГОПС 50, ППВЗ 16, ПОЧТОМАТ 23, УДПП 3, ТИ 4, ГСП 2, УЧАСТОК 1, ЦЕХ 1 | 55: ГОПС only |
| Новосибирск | 77: ГОПС 23, ПОЧТОМАТ 39, ППВЗ 15 | 94: + ТИ, УФПС, АРХИВ, МСЦ, РЦ, ЦЕХ, УДПП, АТП … (service objects) | 23: ГОПС only |
| Тосно (small town) | 7: ГОПС 2, ПОПС 1, ПОЧТОМАТ 3, ППВЗ 1 | 15 (+ТИ, УООП, АТП …) | 3: ГОПС 2, ПОПС 1 |

- `nearby` row = 16 keys: `address-source, distance, holidays, is-closed, is-private-category, is-temporary-closed, latitude, longitude, postal-code, region, settlement, type-code, type-id, working-hours (7 objects with lunches), works-on-saturdays, works-on-sundays`. `nearby.details` adds `phones`, `service-groups` (18 keys).
- **Closure data is real here**: in the Moscow 100, `is-temporary-closed` = **41**, `is-private-category` = 18, `is-closed` = 0 — a plugin MUST filter these; the passport silently omits temporarily-closed points (3.3).
- `type-id` 46 = ППВЗ, 33 = ПОЧТОМАТ, 20 = «П» (почтамт).
- `GET /postoffice/1.0/{index}` (single) returns the same row incl. `temporary-closed-reason`; unknown index → `404 {"code":"1004","desc":"Post office not found for '00000'"}`.
- `settlement.offices.codes?settlement=Новосибирск&region=Новосибирская` → 558 indexes, **only 201 of them are public points in the passport** (all 201 passport points of the city: 88 ГОПС + 98 почтомат + 15 ППВЗ are inside); the other 357 are non-public service objects. Useless as a point list, fine as «all indexes of a city».

### 3.3 Comparison with the widget feed (report E 36 143; report F final 36 163 = 31 912 `russian_post` + 3 439 `postamat` + 812 `additional_pvz`)

Set comparison by index against F's `full-list-quad2.json.gz` [LIVE+F]:

| | widget (F) | passport `ALL` |
|---|---|---|
| ОПС | 31 912 | 31 763 (ГОПС 9 610 + СОПС 22 134 + ППС 19) |
| Postamats | 3 439 | 3 396 |
| Partner ППВЗ | 812 | 812 (**same 812 indexes**) |
| Total | 36 163 | 35 971 |

Intersection **35 915**; widget-only **248** (200 ОПС + 48 postamats); passport-only **56** (41 СОПС, 10 ГОПС, 5 почтоматов). Type mapping is 1:1 (`russian_post`↔ГОПС/СОПС/ППС, `postamat`↔Почтомат, `additional_pvz`↔ППВЗ).
Sampling (6 + 6 via `GET /postoffice/1.0/{index}`): **all 6 widget-only points are `is-temporary-closed: true`** («Отпуск», «Некомплект штата», postamats without reason) — the widget still shows temporarily closed points, the passport drops them; the 6 passport-only points are open, public, not temporary (why the widget lacks them: UNVERIFIED — probably its own nightly snapshot or the ecom/online-parcel capability filter).
So **the passport dump is at least as good as the widget feed for a map** and has the ГАР guids and options the widget list rows lack; the widget feed has the live-ish closure state only through the per-point detail.

### 3.4 Refresh cost

One `ALL` request (5.9 MB zip, 54 MB JSON, ~1.7 s) per day replaces the widget's 630-request tile crawl. Memory: a PHP `json_decode` of 54 MB is heavy — a plugin would stream/convert it to a table at sync time (no measurement made).

## 4. Tariffs (item 4)

`POST /1.0/tariff` needs **`index-from` in the body** (the spec says «из профиля клиента», but without it: `200 {"errors":[{"code":"TARIFF_ERROR","description":"…Для расчета доставки отправления необходмо указать индекс места приема…"}]}`). Response in kopecks: `total-rate` is **without VAT**, `total-vat` separate (`total-rate + total-vat` = public `paynds`), `ground-rate/insurance-rate/…` `{rate,vat}`, `delivery-time{min-days,max-days}` (**`min-days` is absent when min = max**, e.g. «None-2d» for 190000). VAT 22 %.

### 4.1 Our account vs public `tariff.pochta.ru/v2/calculate/tariff/delivery` (same minute, 1 kg, from 109012; public re-probed from 109012 and 101000 — identical)

| Route | Product (otpravka `mail-type`/`category` → public object) | Our account ₽ (no VAT / with VAT) | Public ₽ | Δ | Days |
|---|---|---|---|---|---|
| 109012→630000 НСК | ONLINE_PARCEL ORDINARY → 23030 | 399.00 / 486.78 | 399.00 / 486.78 | 0 | 6–7 |
| | PARCEL_CLASS_1 → 47030 | 580.33 / 708.00 | same | 0 | 2–3 |
| | EMS → 7030 | 1200.00 / 1464.00 | same | 0 | 2–3 |
| | POSTAL_PARCEL ORDINARY → 27030 | **441.80 / 539.00** (from 344961: 445.08 / 543.00) | **error** (public refuses 27030: origin not in «общий список мест приема») | n/a — **otpravka prices it, public cannot** | 6–7 |
| | ECOM ORDINARY → 53030 | error «услуга тарификации не осуществляется после 01.01.2025» | same error | – | – |
| | ONLINE_COURIER → 24030 | error (НСК has no УКД) | same | – | – |
| 109012→190000 СПб | ONLINE_PARCEL | 206.00 / 251.32 | same | 0 | 2 |
| | PARCEL_CLASS_1 | 440.16 / 537.00 | same | 0 | 1–2 |
| | EMS | 850.00 / 1037.00 | same | 0 | 1–2 |
| | ONLINE_COURIER ORDINARY (no `courier` flag needed) | 407.00 / 496.54 | same | 0 | 2 |
| | POSTAL_PARCEL | 367.21 / 447.00 | public error | n/a | 2 |
| 109012→690000 Владивосток | ONLINE_PARCEL | 606.00 / 739.32 | same | 0 | 12–13 |
| | PARCEL_CLASS_1 | 850.00 / 1037.00 | same | 0 | 3–4 |
| | EMS | 1550.00 / 1891.00 | same | 0 | 3–4 |
| | POSTAL_PARCEL | 602.46 / 735.00 | public error | n/a | 12–13 |

**No difference anywhere (`isdogovor` is 0, `with-discount: false`).** Reasons it proves nothing about a real contract: test cabinet, and `WOODEV_POCHTA_PROD_CONTRACT` is empty → the `dogovor=` comparison step was **not run**. The contract-discount question stays open exactly as in report E §7.
Other tariffs: `BUSINESS_COURIER` 590.16 / 720.00 (=public 30030, 2 d), `EMS_OPTIMAL` error (109012 not in the general list), COMBINED/COMBINED mail-type → `400 BAD_REQUEST Invalid request body`.

### 4.2 Pickup points (NSK postamat 917825, NSK ППВЗ 987793, from 109012, 1 kg)

| Request | Result |
|---|---|
| ONLINE_PARCEL ORDINARY → postamat (via `index-to` or `delivery-point-index`) | error «Доставка в 917825 … не осуществляется» (same as public 23030) |
| ONLINE_PARCEL ORDINARY → ППВЗ | error «Отправление нельзя доставить в ПВЗ/ППВЗ» |
| **ONLINE_PARCEL `COMBINED_ORDINARY` → postamat** | **399.00 / 486.78, 6–7 d** (= public object 23080) |
| ONLINE_PARCEL `COMBINED_WITH_DECLARED_VALUE` (1000 ₽) → postamat | 409.00 / 498.98 (= public 23090) |
| COMBINED → ППВЗ or → ordinary OPS 630000 | error (not deliverable) — COMBINED is **postamat-only** |
| COMBINED + COD → postamat | error «Проверка на возможность наложенного платежа (2465)»; order create: `ILLEGAL_MAIL_CATEGORY` |
| **ECOM_MARKETPLACE / DELIVERY_PLUS, `WITH_DECLARED_VALUE`** → postamat, ППВЗ **and** ordinary OPS | **409.00 / 498.98, 6–7 d, same price to all three** (= public 54020 «Доставка плюс с объявленной ценностью») — **but see §8: order create rejects them on this account** |
| ECOM_MARKETPLACE with category `ORDINARY` | `400 {"code":"1003","sub-code":"ILLEGAL_MAIL_CATEGORY"}` (only `WITH_DECLARED_VALUE` exists) |
| ECOM_MARKETPLACE + COD / `WITH_COMPULSORY_PAYMENT` | `400 ILLEGAL_MAIL_CATEGORY` (calculator); public 54060 (compulsory payment) prices 409.00 for the same route |
| ECOM `WITH_COMPULSORY_PAYMENT` (53070) | error «после 01.01.2025»; order create: `DISABLED_MAIL_TYPE «Услуга временно недоступна»` |

So the **pickup product matrix** is: ONLINE_PARCEL COMBINED_* → postamats only; ECOM_MARKETPLACE/DELIVERY_PLUS (+declared value) → postamat + ППВЗ + OPS (tariff only; order creation needs the account flag). Price equals the door price of the same route (no pickup-point discount).

### 4.3 COD and declared value

Neither calculator expresses a COD fee: ONLINE_PARCEL `WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY` (declared 1000 ₽, payment 1000 ₽) = `ground 399.00 + insurance-rate 10.00` (= 1 % of declared value) = **409.00 no-VAT / 498.98**, identical to `WITH_DECLARED_VALUE` and to public 23040 (`items`: 3062 delivery + 3068 declared value). The payer-side fee for money transfer is outside both calculators (UNVERIFIED where it is charged). COD requires `payment` + `insr-value` in the order (§8).

## 5. Address / name / phone normalisation (item 5) [LIVE, `clean-address-12.json`]

`POST /1.0/clean/address` takes an array `[{id, original-address}]`; **12 addresses in one request = 1 quota unit** (batching is free). Output per address: `quality-code`, `validation-code`, `index`, `place`, `region`, `street`, `house`, `room`, `address-type`, GUIDs (`address-guid/place-guid/region-guid/street-guid`).

v1 `Address_Normalize::is_usable()` [V1 `includes/classes/address-normalize.php:82`]: usable iff `quality-code ∈ {GOOD, POSTAL_BOX, ON_DEMAND, UNDEF_05}` AND `validation-code ∈ {VALIDATED, OVERRIDDEN, CONFIRMED_MANUALLY}`.

| # | Input | quality / validation | index | v1 usable? |
|---|---|---|---|---|
| 1 | «г Новосибирск» (city only) | UNDEF_06 / NOT_VALIDATED_HAS_ASSUMPTION | **625519 (a Tyumen-region guess!)** | no |
| 2 | «Москва, ул. Тверская, д. 7, кв. 12» | GOOD / VALIDATED | 125009 | yes |
| 3 | rural «Ростовская обл, Сальский р-н, х. Потапов, ул. Садовая 5» | GOOD / VALIDATED | 347352 | yes |
| 4 | «Московская область, г. Подольск, ул. Кирова 12 корп 2 кв 45» | GOOD / VALIDATED | 142100 | yes |
| 5 | typo «г. Санкт-Петербруг, Невский пр-т 28, кв 3» | GOOD / VALIDATED | 191186 | yes (typo corrected) |
| 6 | «630099, Ленина 1» (postcode, no city) | GOOD / VALIDATED | 630099 | yes (city inferred) |
| 7 | «Екатеринбург, улица Малышева» (no house) | UNDEF_06 / NOT_VALIDATED_HAS_ASSUMPTION | 620014 | no |
| 8 | «10 Downing Street, London» | UNDEF_01 / NOT_VALIDATED_HAS_NO_MAIN_POINTS | – | no |
| 9 | «спб московский пр 100 кв 7» (abbreviations) | GOOD / VALIDATED | 196084 | yes |
| 10 | «Красноярский край, с. Ермаковское, 15» (village, no street) | UNDEF_03 / NOT_VALIDATED_HOUSE_WITHOUT_STREET_OR_NP | – | no |
| 11 | «г Москва, а/я 123» | UNDEF_06 / NOT_VALIDATED_HAS_ASSUMPTION | – | no (the API did not recognise the PO box) |
| 12 | «Москва, Каширское шоссе 14» | GOOD / VALIDATED | 115230 | yes |

Reading: 7 of 12 usable. The two traps: (1) city-only input still returns an `index` — **a wrong one** — together with a «not usable» code, so a plugin must key on codes, never on the presence of `index`; (2) the quality code `UNDEF_06` covers «assumption» results (#1, #7, #11) which v1 rejects, but #7 returned a plausible index for the right street. v1's rule is consistent with these samples.
`clean/physical`: «иванов иван иваныч» → surname Иванов / name Иван / middle Иванович, quality `NOT_SURE`; «Мария» → name only; «ООО Ромашка» → surname «Ромашка» (organisations are not detected) — `NOT_SURE` came back for all three, so the code carries little signal. `clean/phone`: «8 (913) 123-45-67» and «+7 495 1234567» → `GOOD` split into country/city/number; «12345» and «89131234567 доб 5» → `INCORRECT_DATA` (extensions are not parsed). All three clean calls return HTTP 200 with a per-item quality code.

## 6. Tracking (item 6)

- **No REST tracking on this account** [LIVE]: `/1.0/tracking`, `/1.0/tracking/{barcode}` → `407 {"status":"ERROR","message":"Error namespace or mask or method not found"}`; `/1.0/shipment/{barcode}` and `/shipment/tracking?barcode=` → `400 BAD_REQUEST Invalid request parameter`.
- `GET /1.0/shipment/search?query={barcode}` works for shipments in a batch (returns the full shipment object by ШПИ; 14-digit and `EF…RU` barcodes) but, for the test shipments I looked at (2022–23 batches and new ones), the object has **no `last-oper-*` fields** (they appear only after postal operations; the spec lists `last-oper-type/attr`) — whether they populate for real shipments: UNVERIFIED (no moving shipment on a test account).
- SOAP `tracking.russianpost.ru/rtm34` needs a separate login/password created at tracking.pochta.ru («Настройки доступа»), **not** the otpravka login. `WOODEV_POCHTA_*` has no such key, so I did not call SOAP. Needed to measure it: tracking login + password (and a real, moving barcode).

## 7. Error shapes (item 7)

| Case | HTTP | Body |
|---|---|---|
| No app token / bad token | **401** | `{"status":"ERROR","message":"Error token value not found"}` |
| Valid token, bad/missing user key | **401** | `{"code":"1011","sub-code":"UNAUTHORIZED","desc":"ILLEGAL_CREDENTIALS"}` |
| Unknown route **or wrong method** (`GET /1.0/tariff`, `/1.0/nonexistent`) | **407** (sic) | `{"status":"ERROR","message":"Error namespace or mask or method not found"}` — gateway-level |
| Malformed JSON / unknown enum / body not an array on order create | 400 | `{"code":"BAD_REQUEST","desc":"Invalid request body"}` |
| Field-level validation (negative mass) | 400 | `{"code":"1003","desc":"Масса 'null' некорректна","sub-code":"ILLEGAL_MASS"}` |
| Unknown postoffice index | 404 | `{"code":"1004","desc":"Post office not found for '00000'"}` |
| Unknown backlog/batch/form id | 404 | `{"code":"1001","desc":"Instance … not found …","sub-code":"RESOURCE_NOT_FOUND"}` (or an **empty body** on `GET /1.0/shipment/{id}`) |
| `postoffice/*` bad parameters | 400 | Spring body `{"timestamp","status":400,"error":"Bad Request","message":…,"path"}` (different framework than the gateway) |
| Wrong `Accept` on the zip endpoint | 406 | Spring `Not Acceptable` body |
| Order create with no `mail-category` | **500** | `{"code":"1002","sub-code":"INTERNAL_ERROR","desc":"Cannot invoke \"…MailCategory.name()\" because …getMailCategory() is null"}` (Java NPE leaked) |
| Document endpoint denied | **403** | `{"code":"1007","desc":"Access Denied","sub-code":"UNAUTHORIZED"}` — **but the real cause is a precondition**; see below |
| 429 | – | not provoked (UNVERIFIED) |
| Business errors, **HTTP 200** | 200 | tariff: `{"errors":[{"code":"TARIFF_ERROR","description":"…"}],"total-rate":0,…}`; order create/batch: `{"errors":[{"error-codes":[{"code":"…","description":"…","details":"…"}],"position":N}]}` and for batch ops `{"errors":[{"error-code","error-description","position"}],"result-ids":[…]}`; unknown destination index in a tariff also 200 + `TARIFF_ERROR` |

**Accept-header trap:** `GET /1.0/forms/{batch}/f103pdf` with `Accept: application/pdf` → 403 `Access Denied`; with `Accept: application/json` → 400 `{"code":"1013","desc":"Не выполнена операция \"checkin\".","sub-code":"EMPTY_LIST_NUMBER"}` — the real reason is that batch documents need `checkin` first (§8). So 403 here means «precondition missing», not permissions.

## 8. Order lifecycle on the test account (new scope) [LIVE]

All via `PUT /2.0/user/backlog` (array body), account intake OPS 109012, fake recipient (Тестов Тест Тестович, 79009009090), destination 630099 Новосибирск (or 190000 СПб). 13 orders were created successfully over the run (plus ~20 rejected attempts), **all deleted, 0 leftovers** (`ledger.json`; verified by `GET /1.0/backlog/{id}` = 404 for each; the temporary batch 813 vanished when emptied; the 13 historical batches are untouched).

### 8.1 Create results

| Case | Result |
|---|---|
| ONLINE_PARCEL ORDINARY → address | **OK**; response `{orders:[{barcode:"80222126658248", order-num, result-id:2420736572}]}` — the **barcode (ШПИ) is returned immediately at create** (the spec text says it is assigned on batch creation; measured: at create, and unchanged after batch → new) |
| POSTAL_PARCEL, PARCEL_CLASS_1, EMS ORDINARY | OK; barcodes `80222126658255`, `…262` (14 digits, ЕКОМ-style numbering) and `EF555789357RU` (EMS: classic S10 format) |
| ONLINE_PARCEL `WITH_DECLARED_VALUE` (`insr-value` 100000) | OK |
| ONLINE_PARCEL COD (`WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY`, `insr-value`+`payment` 100000) | OK |
| ONLINE_COURIER ORDINARY, plain address | OK. With `courier:true` + `delivery-to-door:true`: errors `UNEXPECTED_COURIER_FLAG`, `ILLEGAL_DELIVERY_TO_DOOR_POSTMARK` («Доставка до двери отключена в настройках пользователя» and «недоступна для ONLINE_COURIER ORDINARY»), `ILLEGAL_COURIER_DELIVERY` — **the `courier` flag/delivery-to-door are NOT needed for ONLINE_COURIER**; they are rejected |
| **ONLINE_PARCEL `COMBINED_ORDINARY` → postamat 917825** | **OK only with `ecom-data:{delivery-point-index}` + `dimension-type:"S"`**. With `delivery-data:{delivery-point-index}` alone → `EMPTY_DELIVERY_POINT_INDEX` ("Не указан Идентификатор пункта выдачи заказов") — contradicts the spec's «use `delivery-data` for pickup points»; sending both also works. `EMPTY_DIMENSION_TYPE` is required |
| COMBINED_WITH_DECLARED_VALUE → postamat | OK |
| COMBINED → partner ППВЗ | `ILLEGAL_DELIVERY_POINT` «Данный Пункт выдачи заказов не поддерживает указанный тип отправления» |
| COMBINED + COD | `ILLEGAL_MAIL_CATEGORY` ("…не поддерживается … на данном направлении") |
| ECOM_MARKETPLACE (postamat / ППВЗ / OPS+goods list), DELIVERY_PLUS (ППВЗ), BUSINESS_COURIER | `UNAVAILABLE_MAIL_TYPE` «Тип отправления '…' для данного направления не подключен в настройках пользователя» — **account switch**, not a payload error; priced fine by the calculators (§4.2) |
| ECOM `WITH_COMPULSORY_PAYMENT` | `DISABLED_MAIL_TYPE` «Услуга временно недоступна» |
| International | not tried (needs customs data; out of cheap scope) |

So on this account the **door products work (ONLINE_PARCEL, POSTAL_PARCEL, PARCEL_CLASS_1, EMS, ONLINE_COURIER)** and **only postamat delivery works for pickup (COMBINED)**; ППВЗ/OPS pickup via ECOM_MARKETPLACE/DELIVERY_PLUS was unreachable → its `ecom-data`/`delivery-data`/`services`/`identity-methods` contract stays **UNVERIFIED** (the account lacks the product). The 2022 historical batch shipment shows what it looked like: `mail-type ECOM_MARKETPLACE`, `mail-category WITH_DECLARED_VALUE`, `ecom-data:{services:["WITHOUT_SERVICE"]}`, `delivery-method TO_POSTOFFICE`, `delivery-to-door false`, `index-to` (int), `str-index-to` (string).

### 8.2 Validation behaviour (a plugin cannot rely on it)

- **Partial success is normal**: a 3-order request (1 good, 1 with `mass:0`, 1 missing `recipient-name`+`postoffice-code`) returned HTTP 200 with `orders` (2 created) **and** `errors:[{position:1, EMPTY_MASS}]`. `position` indexes the input array.
- **The order with no `recipient-name` and no `postoffice-code` was accepted** (lenient server) — the spec's «required» fields are not enforced; the plugin must validate itself.
- **`order-num` is not unique**: creating a second order with an existing `order-num` succeeds (new id/barcode); search by `order-num` then returns 2 hits (`GET /1.0/backlog/search?query=<exact order-num>`; a prefix search returns 0 — search is exact).
- Other messages: `TARIFF_ERROR` (bad `index-to`: «Доставка в 999999 «ХИМКИ ПВЗ» не осуществляется» — 999999 is a Химки ПВЗ object), `TELADDRESS_RECIPIENT_TOO_SHORT`, `ILLEGAL_MASS_EXCESS` («Превышение максимальной массы 20000» for ONLINE_PARCEL), empty array `[]` → `200 {}`.
- **Automatic multi-parcel grouping (MMO):** orders created within the same minute to the same recipient/address were silently put in one group (`group-name:"M0000030802469"` in the create response; `mmo-auto-enabled` account behaviour). Batching such an order alone fails with `NOT_ALL_ORDERS_FROM_GROUP` («Включены не все отправления из многоместного отправления»). Two orders to one address can therefore become one MMO without the caller asking.
- Rates are stored on the order at create time and match the calculator: the first order shows `ground-rate 39900`, `total-rate-wo-vat 39900`, `total-vat 8778`, `delivery-time 6–7`, `shelf-life-days 15`, `weight-pay 1000`, `tariff-type MASS`.

### 8.3 Read / edit / search

`GET /1.0/backlog/{id}` (full order incl. rates), `GET /1.0/backlog/search?query=<barcode|order-num|…>` (exact), **`PUT /1.0/backlog/{id}`** with the full order body (mass 1000 → 2000) returns `200` with an empty body and **re-prices** the order (ground 39900 → 47200 wo VAT), barcode unchanged.

### 8.4 Batch

`POST /1.0/user/shipment?sending-date=2026-10-23` with the id array → `{"batches":[…],"errors":[…],"result-ids":[…]}`. I put 8 orders (ONLINE_PARCEL, POSTAL_PARCEL, PARCEL_CLASS_1, EMS, ONLINE_COURIER, COMBINED) in: **one batch `813` with `mail-type: COMBINED`, `mail-category: COMBINED`, `combined-batch-mail-types:["ONLINE_PARCEL"]`, 6 shipments** (heterogeneous products merge into a COMBINED batch, as the 10 historical «COMBINED» batches show — contrary to the spec's «different types → several batches»); the two MMO-grouped orders were refused (`errors[].position` 4,5 `NOT_ALL_ORDERS_FROM_GROUP`). Batch carries `batch-name` (sequential number string, last historical 612), `batch-status CREATED`, `list-number-date` (= `sending-date`), `form-document-id`, `postoffice-code`, the per-rate sums. `GET /1.0/batch/{name}/shipment?page=0&size=N` lists the shipments with ids/barcodes. `GET /1.0/batch?page=0&size=100` lists 13 batches (all `CREATED`).

### 8.5 Documents

- `GET /1.0/forms/{orderId}/f7pdf?sending-date=YYYY-MM-DD[&print-type=THERMO|PAPER]` → **200 PDF** (`%PDF-1.4`, ~94–95 KB; THERMO 94 842 B, PAPER 99 912 B) for ONLINE_PARCEL, POSTAL_PARCEL, PARCEL_CLASS_1, EMS, ONLINE_COURIER, COMBINED/postamat — works on an order **in a batch** (samples: `doc-f7-a-thermo.pdf`, `doc-f7-a-paper.pdf`, fake recipient). Works with `Accept: */*` too.
- `GET /1.0/forms/{orderId}/f112pdf` → **200 with an empty body** (Content-Length 0) for ONLINE_PARCEL and EMS (Ф112ЭК applies to COD/declared-value parcels; no error is raised for others).
- **`f103pdf` and `zip-all` for a batch need `POST /1.0/batch/{name}/checkin` first** (`EMPTY_LIST_NUMBER`, see §7). `checkin` «sends the electronic Ф103 to the OPS by e-mail» → an outward-facing message to a real post office; **I did NOT call it** (outside the permitted scope). Therefore Ф103, the all-docs zip, Export.xls/csv are **UNVERIFIED**. The same 403 appears on historical batch 573.
- No label endpoint other than Ф7п was exercised (easy-return label, Ф22 attachment: UNVERIFIED).

### 8.6 Cancel / undo (the surprising part)

| Call | Result |
|---|---|
| `DELETE /1.0/backlog` `[id]` for an order that is **in a batch** | 200 `{"errors":[{"error-code":"NOT_FOUND","position":0}]}` |
| **`DELETE /1.0/shipment` `[id]`** (documented as «удаление заказов из партии») | 200 `{"result-ids":[id]}` — **the order is gone afterwards** (`GET backlog/{id}` 404, barcode search empty everywhere incl. long-term archive): it **deletes the order**, it does not return it to «Новые» |
| `POST /1.0/user/backlog` `[ids]` («batch → Новые») | 200 `{"result-ids":[…]}`; orders return to backlog with the **same barcode**; the emptied batch disappears (`GET /1.0/batch/813` → 404 `ShipmentBatchMetrics not found`) |
| `DELETE /1.0/backlog` `[ids]` (orders in «Новые») | 200 `{"result-ids":[…]}` (12 orders in one call) |

Cancel flow a plugin needs: order in «Новые» → `DELETE /1.0/backlog`; order in a batch → `POST /1.0/user/backlog` first, then delete (never `DELETE /1.0/shipment` unless deletion is intended). Returns (`PUT /1.0/returns`), claims, courier call and `checkin` were not touched.

### 8.7 Quota cost of the lifecycle

About 90 requests in total for the lifecycle block (create attempts, reads, edit, batch, 12 document calls, undo/delete) — each 1 unit (§2).

## 9. Widget / CMS account id from the three credentials (coordinator addendum) [V1 + LIVE]

**Question:** can `WOODEV_POCHTA_WIDGET_ACCOUNT_ID` (the widget/CMS account id, v1's `guid_id`) be derived from the otpravka token/login/password?
**Answer: no.**

- v1 gets the id at **plugin activation** in `Lifecycle::install_module()` [V1 `includes/classes/lifecycle.php:60-100`]: `POST https://cms.pochta.ru/api/cms/install` with `{subdomain, cms_version, cms_type:"wordpress", admin_index, barcode_link, status_link}` (+ `guid_id` when re-activating) and stores the response `guidId`/`guidKey` in options `wc_russian_post_guid_id/_key`. **The request carries no otpravka credentials** — `Api::get_new_request()` adds the `AccessToken`/`X-User-Authorization` headers only for the `otpravka-api` type [V1 `includes/api/api.php:100-104`], not for `cms`/`widget`.
- I made the call **once**, as allowed, with a non-resolvable test host (`pochta-research.woodev.invalid`): **HTTP 200** `{adminIndex, barcodeLink, cmsVersion, guidId, guidKey, statusLink, subdomain, userId}`. It **created a CMS registration: `userId <redacted>`**, a fresh `guidId` (`<redacted>`, truncated; the key is kept only in `g/cms-install-response.private.json`, mode 600, never in the report/raw) bound to that fake subdomain and callback URLs. Never called `uninstall`, never changed widget settings. **This registration cannot be removed without `uninstall`** (left as a leftover; it is anonymous and has no link to the Pochta account).
- The guid alone is **not enough to be an account**: with it, `POST widget.pochta.ru/api/sites/public_show {accountId, accountType:"wordpress"}` → **404 `{"errors":{"detail":"Not Found"}}`; `/api/pvz/pick_up_tariff_public` and `courier_tariff_public` → HTTP 500 `{"errors_detailed":{"tariff":["account not found"]},"errors":["Произвести расчёт не удалось, свяжитесь с менеджером"]}` (same as F's bogus-guid result). So **the widget tariff endpoints cannot reflect this account's settings** — there are none.
- What activates it: v1's `get_account_url()` [V1 `woodev-russian-post.php:598`] is `https://cms.pochta.ru/authorization/cms?guid_id=…&guid_key=…`. That page is the cabinet SPA; its bundle calls `GET https://cms.pochta.ru/api/pc/cms/authorize?guid_id&guid_key`, which (measured, no cookie) returns `{"url":"https://passport.pochta.ru/oauth2/authorize?client_id=…&redirect_uri=https://cms.pochta.ru/api/pc/callback&response_type=code&scope=openid&state=…"}` — a **Pochta-ID OAuth login in a browser** (the merchant signs in with their Pochta passport; `GET /api/cms/session/show` is `401 unauthenticated` otherwise). The person who logs in becomes the account owner; the cabinet's own site/«settings id» (the one F calls <redacted>) is created there (the site settings/priorities/markups). This needs a human with a passport.pochta.ru login (the same kind of login as the otpravka user, but the OAuth step is interactive); I did not automate it.
- No GET anywhere returns the guid from otpravka credentials (`settings` carries `apig_access_token`, `hid`, `legal-hid` but nothing named guid/widget/cms). `WOODEV_POCHTA_WIDGET_ACCOUNT_ID` therefore stays an operator-supplied value if a feature ever needs it.
- **Does a v2 plugin need it?** Not for points (report F: `settings_id` is irrelevant for `/api/pvz`; §3 shows the official passport dump replaces it entirely) and not for tariffs (the public calculator and `POST /1.0/tariff` give identical prices with no account, §4). It was only needed for v1's multi-rate widget tariff, the cabinet order export and the dashboard link.

## 10. Question → measured answer → evidence

| Question | Answer | Evidence |
|---|---|---|
| What is the account? | Pochta's own test cabinet («ТЕСТОВЫЙ ЛК»), API on, 27 shipping points, 17 mail types, 62 products; ECOM_MARKETPLACE/Delivery Plus/door delivery switched off | §1 |
| Daily quota? | 10 000 requests/day; every request counts 1 incl. 4xx and passport zips; displayed counter lags minutes | §2, `qexp.out` |
| Does `postoffice/*`, tariff, clean/address, passport consume quota? | Yes, 1 each | §2 |
| Is there an official bulk list incl. partner ПВЗ and postamats? | Yes: `unloading-passport/zip?type=ALL`, 35 971 points, 812 partner ППВЗ, 1 request | §3.1 |
| Does it equal the widget feed? | 99.3 % overlap; partner ППВЗ identical (812); widget-only = temporarily closed points; passport-only = 56 open points | §3.3 |
| Does `nearby` include ППВЗ/postamats? | Yes by default; `nearby.details` no; `filter=ALL` required; distance in metres | §3.2 |
| Is there a schedule / closure state? | Schedule+holidays in passport; closure flags only in `nearby`/`{index}` (41 % of a Moscow sample temporarily closed) | §3.1–3.2 |
| Does our account get a different price than the public calculator? | No (test account); real contract discount **not measurable** here | §4.1 |
| Which pickup products exist for postamat/ППВЗ? | COMBINED → postamat only (works); ECOM_MARKETPLACE/DELIVERY_PLUS → postamat+ППВЗ+OPS (calculator ok, order rejected on this account) | §4.2, §8.1 |
| How is COD priced? | No fee in either calculator; COD = declared value + `payment` in the order | §4.3 |
| Are messy addresses handled? | 7/12 usable by v1's rule; city-only yields a wrong index with a «not usable» code | §5 |
| Is there REST tracking? | No (407/400); SOAP needs a separate login | §6 |
| Error shapes? | 401 ×2 shapes, 407 for unknown routes, 403 masks missing `checkin`, 500 NPE leak, business errors at 200 | §7 |
| Can a plugin run the order lifecycle? | create/edit/search/batch/F7/cancel all OK; Ф103/zip need `checkin` (not run); `DELETE /1.0/shipment` deletes the order | §8 |
| Can the widget account id be derived from the three credentials? | No; `cms install` is anonymous, binding is an interactive Pochta-ID OAuth | §9 |

## 11. Still unverified (with the exact reason)

- **Contract discount** via `dogovor=` / `POST /1.0/tariff`: `WOODEV_POCHTA_PROD_CONTRACT` empty (operator: no contract) and the cabinet is a demo — cannot be measured with these credentials.
- **ECOM_MARKETPLACE / DELIVERY_PLUS order create** (`ecom-data` vs `delivery-data`, `services`, `identity-methods`, `goods`, `fiscal-data`, COD via `compulsory-payment`): the account has the mail type disabled. Needs an account with `deliveryPlusAllowed`.
- **ППВЗ / ordinary-OPS pickup order**, courier-to-door (`delivery-to-door`), time slots, international orders: disabled on the account or out of scope.
- **Ф103, all-docs zip, Export.xls/csv, easy-return label**: need `checkin` (e-mail to an OPS) — not run by decision; also never ran any returns/claims.
- **429 / quota-exhaustion behaviour and the quota reset time**: would need to burn 10 000 requests / cross midnight.
- **Real tracking** (SOAP login absent; REST `last-oper-*` on moving shipments).
- **Why the widget omits 56 open passport points**, and the exact meaning of `onlineParcel=1` (only 11 postamats) and `ecom` for ГОПС/СОПС — no documentation.
- **Memory/CPU cost of parsing the 54 MB passport JSON in PHP** — not measured.
- Leftover on the Pochta side: **CMS registration `userId <redacted>`** (§9), no other leftovers; no courier call, no physical hand-over, no payment, no `checkin`.

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
