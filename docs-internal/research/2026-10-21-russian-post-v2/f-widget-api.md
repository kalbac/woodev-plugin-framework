# Task F — Russian Post widget backend (`widget.pochta.ru`) as the point source for our own map

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Measured **21.10.2026, 03:10–03:35 MSK**, anonymous, read-only, `User-Agent: woodev-research/1.0 (read-only point-source study; contact via github.com/kalbac)`.
Tags: **[LIVE]** my own request (all logged in `f-raw/requests.jsonl`), **[SRC]** bundle / code read, **[V1]** v1 plugin in `plugins-reference/woodev-russian-post/`, **UNVERIFIED** = not measured.
Budget: **1 710 requests of 3 000**, global throttle 0.55 s between requests (≤ 2 req/s), one writer at a time. No write endpoint called (NOT called: `PUT /api/sites/{id}/ping`, `cms.pochta.ru/*`). The account-scoped `settings_id` <redacted> from `.wp-env.override.json` was read as a number only.
Raw material: `f-raw/` (`requests.jsonl` = every request with status/bytes/seconds/CORS+cache headers; `samples/` = response excerpts and the two full point lists; `bundle/` = downloaded widget bundle; `scripts/` = the helper and the quad-tree crawler). Paths below are relative to `f-raw/`.
Builds on reports B and E (not redone). Card #226 body and `tests/_fixtures/woodev-test-shipping-method/class-test-live-pochta-point-source.php` read first.

## 0. TL;DR (what is new vs. #226 of 08.08.2026 and report E)

1. **`settings_id` is not needed at all and filters nothing.** Our <redacted>, the demo 6561, `0`, `999999999` and *no field at all* return byte-identical results (75 519 B / 117 points on the same bbox). Only a non-numeric string is rejected (422). The whole `public_show`/account machinery in the fixture is dead weight for a point source.
2. **Omitting `pvzType` (or sending `[]`) returns ALL THREE types, including `additional_pvz`.** The fixture's belief "no evidence that omission means all" is refuted; the fixture also sends only 2 of 3 types, so it never sees the 812 partner points («Почта Еком» ППВЗ in Ozon/Wildberries premises).
3. **Pagination over a large bbox is UNSTABLE — a naive page-through loses points.** One pass over the whole country at `pageSize 200` (181 pages) returned 36 143 rows but only **32 527 distinct ids: 3 616 duplicates and, necessarily, 3 616 points never seen (10 %)**. A 1 791-point Moscow-region box walked at pageSize 50: 1 768 unique of 1 791 (and two walks disagree). `pageSize` is **capped at 200** server-side. The fixture's `request_points_paginated()` neither dedupes nor protects against this.
4. **Reliable full coverage = adaptive bbox tiling so every tile has ≤ 200 rows (single page, no pagination).** My quad-tree crawl: **630 requests, 443 leaf tiles, 30 empty, 0 oversize tiles → 36 163 distinct points = 31 912 `russian_post` + 3 439 `postamat` + 812 `additional_pvz`.** A second full pass 30 minutes later: identical (0 added, 0 removed, 0 changed records). 36 163 = `totalEntries` of the world-wide bbox, i.e. nothing is missed. Report E's 36 143 was Russia-east-of-19°E only (the other 20 points are 19 in Chukotka at negative longitude + 1 south of 41°N).
5. **Full list = 24.4 MB JSON (1.8 MB gzip); a slim form (id, type, lng, lat, index) = 1.49 MB (536 KB gzip).** Sync time at 2 req/s: **630 requests ≈ 5.3 min (≈ 3.7 min if the previous run's 443 leaf tiles are reused)**; observed 422 s/508 s while I was probing in parallel.
6. **Browser can call it directly:** both `POST /api/pvz` and `GET /api/pvz/{id}` answer with `access-control-allow-origin: <reflected Origin>` + `allow-credentials: true`, preflight 200 with `allow-headers: content-type`. (Possible, but see risks — not advisable for a shop.)
7. **No rate limiting, 429, captcha or challenge seen** in 1 710 requests at ≤ 2 req/s; 6 client-side timeouts (0.35 %) of 30–60 s on otherwise normal tiles, every one succeeded on retry. No ETag, `cache-control: max-age=0, private, must-revalidate` → no HTTP caching help.
8. **Bundle unchanged since #226:** `main.a7d147fb5267ec1f0932.js` is the same hash the fixture docblock cites; `Last-Modified` of widget.js/map assets is **18.03.2026** (7 months stable).
9. **Tariff:** `GET /api/data/free_tariff_by_settings` needs only an existing site id (<redacted> works, bogus id → 404, missing → 422), returns **one tariff per call** (the `type` parameter *is* honoured: POSTAL_PARCEL, ONLINE_PARCEL, PARCEL_CLASS_1, ECOM_MARKETPLACE; EMS_OPTIMAL/SMALL_PACKET give calculation errors), and equals tariff.pochta.ru from origin 101000 within 10–37 kopecks. The multi-rate `pick_up_tariff_public`/`courier_tariff_public` need an account **guid** (cabinet install) — 422/500 without it.
10. **Fixture issues found while comparing** (not edited — read-only): six, see §9.3.

## 1. Every backend endpoint the current bundle calls (item 1)

Bundle: `https://widget.pochta.ru/map/widget/widget.js` (1 204 B) is only an iframe loader (`ecomStartWidget` → `iframe src=https://widget.pochta.ru/map/` and a `postMessage` of `{siteId, accountId, accountType, weight, sumoc, startZip, startAddress, url, dimensions, orderLines}`). The app is `map/index.html` (880 B) → `vendors.399fd3e14c6ce6678414.js` (26.9 KB, no endpoints) + `main.a7d147fb5267ec1f0932.js` (72.9 KB). **No further lazy chunks.** [LIVE+SRC; files in `bundle/`]

| # | Method + path | Payload | Response shape | Status of my probe |
|---|---|---|---|---|
| 1 | `POST /api/sites/public_show` | `{accountId, accountType}` | site settings JSON incl. `id` = settings_id | #226 measured; **not needed** (see §2). Not re-called by me (needs a real accountId) |
| 2 | `GET /api/sites/{id}/settings?url=<site url>` | path id, query `url` | full widget config (`shipmentPriorities`, `useBestTariff`, `senderZipCode`, `showPostamat`, `showPartnerOffices`, `individualTariff`, markup fields, …) | **`url` must be a URL registered for that site**: demo 6561 + `https://widget.pochta.ru/` → 200 (`samples/i5-settings-6561-ok.json`); same id + foreign url → 401; our <redacted> + foreign url → 500; no `url` → 400. (Report E saw it open for the demo id — still true only with the right `url`.) Not useful for a point source |
| 3 | `PUT /api/sites/{id}/ping?url=` | – | `{…lastPinged}` | **WRITE — not called**. Widget fires it when `lastPinged` is >3 h old |
| 4 | `POST /api/pvz` | `{settings_id?, pageSize≤200, page, currentTopRightPoint:[lng,lat], currentBottomLeftPoint:[lng,lat], pvzType[], prevTopRightPoint?, prevBottomLeftPoint?, dimensions?, orderLines?}` | `{boundingBox(GeoJSON polygon), data[], pageNumber, totalEntries, totalPages}` | measured (§2–3). `prev*Point`, `dimensions`, `orderLines` are sent by the bundle but I did not test their effect (UNVERIFIED; `prev*` looks like "only what is new vs. the previous viewport") |
| 5 | `GET /api/pvz/{id}` | numeric id | full record; `null` (HTTP 200) for an unknown/wrong key; 404 for a non-numeric key | measured (§4) |
| 6 | `POST /api/pvz/find_center_by_zip` | `{zip}` | `{centerPoint:{coordinates:[lng,lat],…}}`; 404 for an unknown zip | measured: 190000 → [30.299968, 59.930773]; **918594 (a postamat index) → [37.626688, 55.723222]** (`samples/i1-find-center-*.json`). Handy as "search by postcode → map centre" without our own geocoder |
| 7 | `GET /api/data/free_tariff_by_settings?id&weight&sumoc&indexTo&type&orderLines` | query | `{type, tariff{pay,nds,total,isDogovor}, delivery{deliveryMin,deliveryMax,description,extraTimeInHours,processingDays,route,routename}, errors{tariff,delivery}}` | measured (§5) |
| – | v1-only (not in bundle): `POST /api/pvz/pick_up_tariff_public`, `courier_tariff_public`, `index_public`, `show_public`, `POST /api/cms/{install,uninstall,orders_public}` | see E / [V1 `includes/api/api.php:26-92`] | – | tariff_public measured (needs guid); `index_public`/`show_public` not probed; `cms/*` are writes, not called |

Other hosts used by the map: Yandex Maps JS API (`api-maps.yandex.ru/2.1`, key embedded in map.html), jQuery from `yandex.st`, Google Fonts. Nothing else.

**Diff vs #226 (08.08.2026):** same bundle hash → no client change. What #226 did not know or got wrong: (a) `settings_id` irrelevant; (b) `pvzType` omission = all types; (c) third type `additional_pvz` is returned and is real (812 points); (d) `pageSize` cap 200 and **unstable offset pagination**; (e) `find_center_by_zip`; (f) `type` parameter of `free_tariff_by_settings` works. Data churn: #226 central-Moscow box gave 113 (79+34); today the same box gives 117 (79+38) + 6 partner = 123.

## 2. Does `POST /api/pvz` need a `settings_id`? (item 2)

Same bbox (central Moscow, `[37.55,55.72]`–`[37.70,55.80]`), `pageSize 200`, `pvzType ["russian_post","postamat"]` unless noted. [LIVE; `samples/i2-*.json`]

| Variant | HTTP | bytes | totalEntries | by type |
|---|---|---|---|---|
| `settings_id: <redacted>` (ours) | 200 | 75 519 | 117 | 79 russian_post + 38 postamat |
| field absent | 200 | 75 519 | 117 | identical |
| `999999999` (bogus) | 200 | 75 519 | 117 | identical |
| `0` | 200 | 75 519 | 117 | identical |
| `6561` (demo) | 200 | 75 519 | 117 | identical |
| `"abc"` (string) | **422** `{"error":"Bad arguments."}` | 26 | – | – |
| <redacted> + 3 types explicit | 200 | 79 405 | 123 | +6 `additional_pvz` |
| <redacted>, **no `pvzType`** | 200 | 79 405 | 123 | all three types |
| no settings_id, no `pvzType` | 200 | 79 405 | 123 | identical |
| `pvzType: []` | 200 | 79 405 | 123 | all three types |
| `pvzType: ["nope"]` or `"postamat"` (string) | **422** | 26 | – | – |
| only `["additional_pvz"]` / `["postamat"]` | 200 | 4 115 / 24 559 | 6 / 38 | filter works |

**Answer:** no settings_id needed; it neither filters by the account's allowed types nor by anything else — the widget's own filtering (`showPostamat`, `showPartnerOffices`, `showRussianPostofficeBranches`, shipment-type priorities) is done **client-side in the bundle by building `pvzType`** (`P()` in main.js: ОПС→`russian_post`, partner→`additional_pvz`, postamat only if ONLINE_PARCEL/ECOM_MARKETPLACE enabled). Server-side `pvzType` is the only filter. Consequence for the framework: no account, no constant, no `public_show` call — a Pochta point source is a pure anonymous reader.

Other parameter behaviour [LIVE]: `[lat,lng]` instead of `[lng,lat]` → HTTP 200, 0 points (TRAP 1 reconfirmed); `pageSize` 0 → default 10 rows; `pageSize` 201…5000 → silently capped at **200** (181 pages for 36 143); `page: 0` behaves as page 1. The response `boundingBox` just echoes the request polygon.

## 3. Full coverage (item 3)

### 3.1 Naive paging is lossy

Whole-country bbox `[19,41]`–`[180,82]`, `pageSize 200`, pages 1…181 [LIVE, `samples/full-list-pass1.json.gz`]: 36 143 rows, **32 527 unique ids, 3 616 ids returned twice, 0 in-page duplicates**, so 3 616 points are invisible. Ordering is not a stable sort key (first-page ids `22946, 27098, 20523…` are neither id- nor lng-sorted) and the same bbox called twice returned the same *page 1* in the same order but later pages diverge between runs (Moscow-region box, pageSize 50: 1 791 rows → 1 768 unique first walk, 1 764 unique second walk, sets differ). Small single-page boxes are complete (a box with `totalEntries ≤ pageSize`).

### 3.2 Adaptive tiling — correct and repeatable

Algorithm (`scripts/tile.py`): query a tile with `pageSize 200`; if `totalEntries ≤ 200` take `data` (complete, one page), if 0 drop, else split into 4 and recurse. Start boxes `[19,40]–[180,83]` and `[-180,40]–[-100,83]` (Chukotka sits at negative longitudes). No `pvzType` (all types). Retries on timeout.

| Metric | Pass 1 (03:17–03:25) | Pass 2 (03:28–03:35) |
|---|---|---|
| requests | 630 (443 leaf + 30 empty + 157 split nodes) | 630 |
| distinct points | 36 163 | 36 163 |
| `russian_post` / `postamat` / `additional_pvz` | 31 912 / 3 439 / 812 | same |
| wall time | 508 s (with my parallel probes) | 422 s (≈ 1.5 req/s) |
| wire bytes | 45.8 MB (parent tiles return a discarded 200-row page) | 45.8 MB |
| diff pass 1 vs pass 2 | 0 added, 0 removed, 0 changed (geo, index, type, address) | |
| oversize leaves | 0 (densest leaves are well below 200 at 1e-4° minimum span) | |

World-wide `totalEntries` = **36 163** (= the sum above): +19 points at lng < -168 (Chukotka) and +1 at lat 40.82/lng 57.67 relative to the Russia-box 36 143. Nothing lies north of 82°N or elsewhere on the planet.

### 3.3 Composition and size

| Type | Count | Index range | Notes |
|---|---|---|---|
| `russian_post` (ГОПС/СОПС) | 31 912 (88.2 %) | 6xxxxx–3xxxxx (all regions) | in the 10 sampled ОПС: 6 СОПС + 4 ГОПС |
| `postamat` | 3 439 (9.5 %) | 91xxxx (2 560 of the 3 023 in pass 1), 99, 94, 96, 93 | |
| `additional_pvz` | 812 (2.2 %) | 98xxxx | brand «Почта Еком»; premises of Ozon/Wildberries (`getto`) |

Comparison with report E: E's 31 892 / 3 439 / 812 vs today 31 912 / 3 439 / 812 (+20 ОПС in ~2.5 h — normal churn, or E's number was taken a bit earlier; no discrepancy in postamats/partners). The pochta.ru map backend (G) counts 61 371 objects in the same Russia bbox (44 527 with `NOT_CLOSED, NOT_PRIVATE, NOT_TEMPORARY_CLOSED`) — it includes УДПП/ОПП/ТИ/ГСП, closed and private objects the widget does not show.

Record shape, listing (`address` has 17 slots, mostly null; `addressString`/`deletedAt` always null in all 36 163): avg **675 B/record** on the wire. 34 193 have `street`, 35 455 `house`, all have `place`+`region`. 1 362 coordinate pairs are shared by >1 point (max 22 points on one coordinate — e.g. several postamats in one building), so a map layer must tolerate coincident markers.

| Representation | Bytes | gzip |
|---|---|---|
| full listing JSON, 36 163 records | 24 444 177 | 1 795 925 |
| slim `[id, type-initial, lng, lat, index]` | 1 490 172 (41 B/record) | 535 946 |

Sync cost at the 2 req/s ceiling: 630 requests = **315 s**; with reuse of last run's leaf tiles (re-split a leaf only when its `totalEntries` exceeds 200, re-merge never needed for correctness) ≈ 443 requests = **222 s**, ~30 MB wire. A *detail* sync of everything is 36 163 GETs ≈ **5 h at 2 req/s** (≈ 72 MB at ~2 KB) — not something a shop should do; details stay lazy.

## 4. `GET /api/pvz/{id}` (item 4)

30 random points, 10 per type, [LIVE, `samples/i4-details-30.json`]. Record ≈ 1.7–2.2 KB. All 30 carry exactly these fields: `acceptEcom, address{…}, addressString(null), boxSize, brandId, brandName, cardPayment, cashPayment, closed, contentsChecking, deletedAt, deliveryPointIndex, deliveryPointType, functionalityChecking, geo, getto, holidays, id, insertedAt, legalName, legalShortName, partialRedemption, pochtaId, returnAvailable, temporaryClosed, type, typesizeId, typesizeVal, updatedAt, withFitting, workTime`.

| Field | `russian_post` ×10 | `postamat` ×10 | `additional_pvz` ×10 |
|---|---|---|---|
| `deliveryPointType` | СОПС ×6, ГОПС ×4 | Почтомат | ППВЗ |
| `brandName` | Почта России | Почта России | **Почта Еком** |
| `cardPayment` / `cashPayment` | **false / false** | false / false | false / false |
| `acceptEcom` | true | false | false |
| `boxSize` | null | **XL** (all 10; S/M/L expected elsewhere — UNVERIFIED) | null |
| `contentsChecking` | false | false | **true** |
| `returnAvailable` | false | false | **true** |
| `withFitting` | 2 of 10 true (ГОПС) | false | false |
| `getto` | null | "Почтомат расположен в отделении почтовой связи" | "Пункт выдачи отправлений расположен в помещении Wildberries." |
| `workTime` | 7 lines "пн, открыто: 09:00 - 21:00" / "пн, выходной" / "…, перерыв: 13:00 - 14:00" | 7 lines | 7 lines |
| `holidays` | **array of objects** `{df, ds, work:[{dt, st, fn, nm, rst:[{st,fn}]}]}` (10/10) | null | null |
| `closed` / `temporaryClosed` | false / false (all 30) | false / false | false / false |
| `insertedAt`/`updatedAt` | e.g. `2026-10-20T03:00:11` / `03:02:00` — nightly rewrite | same | same |

What matters for checkout:
- **COD/card: `cashPayment`/`cardPayment` are `false` for every point sampled, including offices** (and 12/12 in #233) — no information; keep the type-based rule. **New nuance:** the widget bundle maps `additional_pvz` to shipment type `ECOM_MARKETPLACE`, and the v1 checkout refuses COD for `ECOM_MARKETPLACE` (`plugins-reference/woodev-russian-post/includes/classes/checkout.php`, quoted in the fixture docblock). The fixture decides `accepts_cod = ('postamat' !== type)` → it would say **true for a partner ППВЗ**, contradicting v1 (moot today because the fixture drops `additional_pvz`, but it matters the moment partners are included).
- **Box size/weight limits:** only `boxSize` for postamats; **no weight limit in the widget record.** Weight comes from `tariff.pochta.ru/v2/dictionary/postoffice/{index}` (anonymous, ≤10 ids per call, wrapper key `postoffice[]`): 918594 postamat → `weight-max 20000` g; 980660 ППВЗ → `weight-max 31500`; ordinary ОПС → null; `pay-card`/`pay-money` = 0 for all four probed (`samples/i4-dict-batch.json`). Confirms E.
- **Hours:** `workTime` is already human text per weekday (`открыто`/`выходной`, optional `перерыв`); the fixture's `map_schedule()` uses `preg_match_all` for `HH:MM - HH:MM` after `открыто:`, so a line like `вт, открыто: 10:00 - 18:00, перерыв: 13:00 - 14:00` yields **two open intervals** — the lunch break is read as opening hours (2 of the 30 sampled points have such lines; reasoned from the code, not run). Holidays are structured (`df/ds` date range, `work[].st/fn` hours, `nm` = day-type code, `rst` = break) for ОПС only.
- **Temporarily closed / closed:** present (`closed`, `temporaryClosed`) but all 30 false; real incidence not measured (would need ~36 k GETs) — UNVERIFIED. These are the only two fields that should make a point non-selectable and they are **only in the detail call**, never in the listing.
- **Wrong key:** `/api/pvz/111033` (a postal index) and `/api/pvz/99999999` → HTTP 200 body `null`; `/api/pvz/abc` → 404 JSON. TRAP 2 reconfirmed.

## 5. Tariff endpoints (item 5)

### 5.1 `GET /api/data/free_tariff_by_settings` [LIVE; `samples/i5-free-tariff-127220.json`]

- Inputs: `id` (site/settings id, **required**: missing → 422 `{"errors":{"id":["can't be blank"]}}`; non-existent → 404; **<redacted> and 6561 both work**), `weight` (g), `sumoc` (declared value, ₽), `indexTo` (destination index), `type` (shipment type), `orderLines` (JSON string, `[]` fine). **Origin is not a parameter** — it is the site's `senderZipCode` (null for the demo; behaves exactly like Moscow 101000, see table). Needs **no account guid**.
- Returns **one tariff per call**. `type` is honoured for `POSTAL_PARCEL`, `ONLINE_PARCEL`, `PARCEL_CLASS_1`, `ECOM_MARKETPLACE`; `ECOM`, `COMBINED`, unknown values fall back to `ONLINE_PARCEL` (the response `type` field tells which was used — check it!); `EMS_OPTIMAL` and `SMALL_PACKET` return HTTP 200 with `tariff:null` and `errors.tariff` (codes 2004 / 2009, "…с объявленной ценностью и наложенным платежом…" / "…Плата за…"). Errors come as HTTP 200 — inspect `errors`.
- Several mail types for one shopper therefore = N calls (one per `type`), exactly like tariff.pochta.ru's one-`object`-per-call.
- Weight/value sensitivity [SPb, ONLINE_PARCEL]: 1 kg/1 000 ₽ → 206.10; 5 kg → 312.10; 1 kg/50 000 ₽ → 211.00; 0/0 → 206.00.
- Pickup points: Moscow ОПС 127220, postamat 918594 and (with `type=ECOM_MARKETPLACE`) ППВЗ 980660 all price **160.10 ₽ (pay) / 195.32 ₽ (total, VAT 22 %)**, 1–2 days; i.e. the tariff depends on the route, not on the point type. With `type=ONLINE_PARCEL` the ППВЗ returns error 2006 ("Посылка онлайн комбинированная с объявленной ценностью…" — ONLINE_PARCEL cannot go to a partner point; only `ECOM_MARKETPLACE`).

### 5.2 Three routes vs the public tariff (origin 101000, 1 kg, 1 000 ₽) [LIVE; both back-ends called minutes apart]

| Route | Product | tariff.pochta.ru `pay` / `paynds` (kop) | widget `pay` / `total` (kop) | Δ pay | days (public = widget) |
|---|---|---|---|---|---|
| 101000 → 190000 | Посылка онлайн (23030) | 20 600 / 25 132 | 20 610 / 25 144 | +10 | 2 |
| 101000 → 190000 | 1-й класс (47030) | 44 016 / 53 700 | 44 046 / 53 737 | +30 | 1–2 |
| 101000 → 630000 | Посылка онлайн | 39 900 / 48 678 | 39 910 / 48 690 | +10 | 6–7 |
| 101000 → 630000 | 1-й класс | 58 033 / 70 800 | 58 063 / 70 837 | +30 | 2–3 |
| 101000 → 690000 | Посылка онлайн | 60 600 / 73 932 | 60 610 / 73 944 | +10 | 12–13 |
| 101000 → 690000 | 1-й класс | 85 000 / 103 700 | 85 030 / 103 737 | +30 | 3–4 |

Same engine (`routename`: "алгоритм по плечам версия 2022-08-17"); the constant +10/+30 kopecks is a tiny difference in how `sumoc` is applied (not reconciled; ≤ 0.07 %). The unauthenticated widget numbers also reproduce E's earlier public figures (SPb 23030: E 206.00 / widget 206.10; 47030: 440.16 / 440.46).

### 5.3 `POST /api/pvz/pick_up_tariff_public`, `courier_tariff_public` (the v1 endpoints)

Body `{order:{account_id, account_type, shipping_address{full_locality_name, location{country, region_zip}}, items_price, total_weight}}` [V1 `abstract-shipping-methods.php:343-490`]. [LIVE, `samples/i5-*_tariff_public-*.json`]: without `account_id` → **422** `order.accountId can't be blank`; with a random guid or the demo id 6561 → **HTTP 500** `{"tariff_id":"UNDEFINED","shipment_type":"UNDEFINED","price":0.0,"errors_detailed":{"tariff":["account not found"]}}`. **Needs a cabinet guid created by `cms.pochta.ru/api/cms/install` (a write) — not a free public calculator.** I could not observe a successful multi-rate response; its shape (array with `shipment_type`, `price`, `errors`) is [V1] only.

**Conclusion for item 5:** for a plugin without the cabinet the usable tariff sources are (a) `free_tariff_by_settings` with any valid site id (one tariff/call, product chosen by `type`, origin = site's zip — i.e. **the origin cannot be set per merchant** with a borrowed id), or (b) tariff.pochta.ru `/v2/calculate/tariff/delivery` (E), which takes `from` and returns the same numbers with ~10–30 kop. difference. (b) is the better fit; (a) adds nothing except a hidden dependency on somebody's site id.

## 6. CORS, caching, rate limiting (items 6–7)

CORS [LIVE, `requests.jsonl` tags `item6:*`, sent `Origin: https://shop.example.org`]:

| Request | Result |
|---|---|
| `POST /api/pvz` + Origin | 200, `access-control-allow-origin: https://shop.example.org`, `allow-credentials: true`, `vary: origin` |
| `OPTIONS /api/pvz` (preflight, `Access-Control-Request-Method: POST`, `…-Headers: content-type`) | 200, same `allow-origin`, `allow-headers: content-type`, `allow-methods: PUT, PATCH, DELETE` (POST is CORS-safelisted so needs no listing) |
| `GET /api/pvz/26600` + Origin | 200, reflected origin + credentials |
| `Origin: null` | 200, `allow-origin: null` |

→ **Any web origin may call `/api/pvz` from a visitor's browser; no proxy is technically required.** Reflecting arbitrary Origin with credentials is an access-control misconfiguration, not an offer: it can be tightened any day (UNVERIFIED how likely). Direct browser use also makes the shopper's IP — not the shop's — the rate-limit subject and would break if any challenge cookie were introduced.

Caching: responses carry `cache-control: max-age=0, private, must-revalidate` and **no ETag / Last-Modified** on the API → no conditional requests; a detail GET is always a full 1.7–2.2 KB. Data is rewritten nightly (`insertedAt 03:00:11`, `updatedAt 03:01–03:02` on the day of the check), so one sync per night is the natural cadence.

Rate limiting / blocking: **none observed** over 1 710 requests, ~1.5–2 req/s sustained for ~13 minutes of crawling; statuses: 1 687×200, 6 client timeouts (30–60 s on tiles that normally take 0.2 s; each succeeded on retry), 6×422/5×500/3×404/1×401/1×400 (all deliberate probes), no 429/403/captcha/testcookie. Latency of a 200-row tile: median 0.18 s, p95 0.26 s, max 1.23 s (own machine, Moscow). I did **not** test faster than 2 req/s (instruction), so the real limit is unknown (UNVERIFIED).

## 7. The pochta.ru site's own office-map backend (G in report E) vs the widget backend (item 8)

`POST https://www.pochta.ru/suggestions/v2/postoffices.find-from-rectangle`, body `{topLeftPoint{latitude,longitude}, bottomRightPoint{…}, precision:0, onlyCoordinate, extFilters[], offset, limit}`. [LIVE, `samples/i8-G-moscow.json`]

| | Widget backend `widget.pochta.ru` (F) | pochta.ru map backend (G) |
|---|---|---|
| Moscow box (same as §2) | **123** points (117 + 6 partner) | **395** objects (37 ПОЧТОМАТ, 35 ГОПС, 22 ППВЗ, 3 УДПП, 1 ОПП, 1 ТИ, 1 ГСП in the first 100; includes closed) |
| Russia total | **36 163** | **61 371** (44 527 with NOT_CLOSED+NOT_PRIVATE+NOT_TEMPORARY_CLOSED) |
| Record weight | 675 B list / 2 KB detail | **~5 KB/record** (100 rows = 515 KB; 395 rows = 1.74 MB). `onlyCoordinate:true` → `[{latitude,longitude}]` only, 6.3 KB per 100 — **no ids/indexes**, useless for selection |
| Schedule in list | no (detail only) | **yes** (`workingHours`, `holidays`, `isClosed`, `isTemporaryClosed`, phones, serviceGroups, `corporateClients[Озон, Вайлдберриз]`) |
| `limit` | pageSize ≤ 200 | `limit` 400/1000 both return all 395 (cap not found) |
| Pagination | **unstable** (dups + gaps) | 395-box walked twice at limit 100: **all 395 unique, same set**, order differs between walks — stable as a set in this test only (UNVERIFIED for large boxes) |
| Key for order creation | `deliveryPointIndex` | `postalCode` (same) |
| Cookie/session | none | sets `session-cookie` (Max-Age 3600) on every response |
| CORS for a browser | reflects any Origin + credentials | `allow-credentials: true`, `allow-methods: GET, POST, OPTIONS`, **no `access-control-allow-origin` for a foreign origin → a visitor's browser cannot read it; server proxy mandatory** |
| Provenance | cabinet-internal backend of the ECOM widget (bundle unchanged, assets dated 18.03.2026) | backend of the public site's `/offices` page (Next.js app; changes with site releases — history UNVERIFIED) |

**Which is more stable?** Measured: the widget contract is bit-identical to what #226 recorded 74 days ago (bundle hash unchanged, assets dated 18.03.2026), the payload is 7× lighter, deterministic when tiled, and does not set cookies. G is the product page of the carrier itself (probably better maintained, richer), but heavier by ~8×, changes with the site's own releases and sets a session cookie (a typical precursor of bot protection). **Neither is documented or permitted for third parties**; for a *point list* the widget backend is the better source, G is the better *fallback and the better schedule source for a single point* (no per-id GET needed).

## 8. Risks

| # | Risk | Evidence / size |
|---|---|---|
| R1 | **Unofficial, undocumented, cabinet-internal.** Can change shape, get auth, or be closed at any time; no SLA; ToS for third-party use unknown | no docs (E §6); only `widget.js` is public |
| R2 | **Silent data loss from unstable pagination** if the implementation pages instead of tiling (≈ 10 % country-wide, ≈ 1.3 % in a dense city box) | §3.1 |
| R3 | Dependence on a shop-side crawler: a full sync is 630 requests ≈ 45.8 MB, 5 min. N shops × daily sync hits an unofficial endpoint with N×630 req/day (10 000 shops = 6.3 M/day) — likely to trigger blocking. A shared snapshot (1.5 MB slim / 0.54 MB gzip) distributed from our side would cut that to one crawl per day, but then **we** become the single scraper and the redistribution of Pochta's data is a licensing question (UNVERIFIED) | §3.3 |
| R4 | Open CORS + credentials is almost certainly an oversight; direct browser calls would stop working the day it is fixed | §6 |
| R5 | 0.35 % of requests hung 30–60 s; a live-per-viewport design needs a hard client timeout (the fixture's 8 s is right) and retry | §6 |
| R6 | `closed`/`temporaryClosed`, `holidays` and `workTime` exist **only in the detail call** (36 k GETs) → a synced list cannot show or exclude closed points; they must be fetched lazily at selection, cached per id (~2 KB) with a TTL ≤ 1 day (data rewritten nightly) | §4 |
| R7 | **COD rule for partners**: `additional_pvz` = ECOM_MARKETPLACE → v1 refuses COD; the current fixture rule would allow it | §4 |
| R8 | Postamat/partner product constraints: ONLINE_PARCEL cannot go to a partner ППВЗ (error 2006); price tables E §3.2: 53030 dead since 01.01.2025, 54020 is the live pickup product — the point type decides which product may be offered | §5.1, E |
| R9 | 1 362 coordinate pairs are shared by up to 22 points → map must cluster/spiderfy | §3.3 |
| R10 | Weight/size limits are not in the widget data (`boxSize` only for postamats; weight from `tariff.pochta.ru` dictionary, 10 ids/call) | §4 |
| R11 | `free_tariff_by_settings` uses the site's origin zip and is bound to a stranger's site id — unusable as the shop's price source | §5 |
| R12 | Throughput limit above 2 req/s unknown; I stayed under it. A synchronised sync at midnight from many shops would be the first thing to trip one (jitter required) | §6 |

## 9. Fit with the framework v2 (item 9)

### 9.1 What the interface expects (read via Serena, `<local framework checkout>`, symbols found under `woodev/shipping-method/…`)

- `Woodev\Framework\Shipping\Pickup\Point_Source` (`pickup/interface-point-source.php:18-111`): `get_strategy(): STRATEGY_BULK|STRATEGY_VIEWPORT`, `fetch_points(Point_Query): Pickup_Point[]`, `fetch_details(string $id): ?Pickup_Point`. A viewport source **must honour `Point_Query::get_types()`** (empty = all), is always handed non-null bounds, must throw `Woodev_API_Exception` (not return empty) on failure, and skips a malformed entry rather than failing the fetch.
- `Abstract_Bulk_Point_Source` is for locality-scoped carriers; `pickup/class-point-query.php`: **`MAX_BBOX_SPAN = 10.0` degrees** per side.
- `Pickup_Controller` (`rest-api/class-pickup-controller.php`): per-IP rate limits `POINTS_RATE_LIMIT_MAX = 240`, `DETAILS_RATE_LIMIT_MAX = 60`, `SELECT_RATE_LIMIT_MAX = 15`; `query_matches_strategy()` enforces bbox for VIEWPORT.
- `Pickup_Point::from_array` has no key for `closed`/`temporaryClosed`/`boxSize`/`holidays` (as the fixture docblock states); `selectable` is recomputed per request by `Constraint_Checker`.
- Pochta is already named as the VIEWPORT example in the interface docblock ("OZON, Pochta").

### 9.2 Sync-and-cache vs live-per-viewport — numbers

**Important:** `get_strategy()` describes how the *map* queries (by bbox), not where the data physically lives. A Pochta source can declare `STRATEGY_VIEWPORT` and answer `fetch_points()` from a **local table**, with `fetch_details()` lazily going to `GET /api/pvz/{id}` (transient per id). No interface change is needed for either option.

| | Live per viewport (what the #226 fixture does) | Sync nightly into a local table + lazy detail |
|---|---|---|
| Upstream load per shop | every pan = ≥1 request (tile ≤ 200 rows, 0.13–0.26 s, 25–135 KB each); a city-level view of Moscow ≈ 4–10 rows·pages; capped by our 240/min/IP limit → up to 240 upstream calls/min per visitor IP | **630 requests/day** (or ~443 with tile reuse) ≈ 45.8 MB/day, 4–5 min at 2 req/s, plus 1 detail GET per opened point |
| Latency to shopper | 0.2 s upstream + your server hop (the #226 rig measured 7–13 s — a rig artefact); 0.35 % hung requests | DB range query on (lat,lng) + type: ms; only the lazy detail hits the network |
| Completeness | **Lossy above 200 rows unless the implementation tiles/dedupes** (§3.1); the fixture's MAX_PAGES×PAGE_SIZE = 2 000 pages-walk would drop ~1.3 % in dense boxes and doesn't dedupe by id | complete: 36 163 distinct, identical across 2 passes |
| Storage | none (details ≈ 2 KB per opened point) | ~36 k rows; slim record 41 B JSON ≈ 1.5 MB, with address/index ≈ 10–25 MB MySQL; recommended own table with composite index on (lat, lng), not `wp_options` (the #226 lesson: 823 KB over 14 rows in 30 min) |
| Resilience | any upstream outage/CORS/auth change = empty or erroring map immediately | map keeps working on yesterday's data; the sync can fail soft and keep the last good snapshot |
| Footprint on unofficial endpoint | scales with shopper traffic (unbounded, bot-able) | scales with number of shops × 630/day (bounded; jitter) or 630/day total if a central snapshot is shared |
| Closed/temporarily-closed | known only after the detail call, per opened point | same (not in list); nightly detail for a whole region impractical (5 h/country) |
| Type filter (D-10) | pass `pvzType` upstream | SQL `WHERE type IN (…)` |

**Assessment (as asked, numbers only):** live-per-viewport is simplest and costs nothing at rest, but with this API it needs bbox tiling + id dedupe to be correct and its load scales with traffic against an undocumented endpoint; sync-to-local costs 630 requests a day per installation (≈ 5 min), makes the map fast, complete and independent of the endpoint's availability at shopping time, and fits the existing interface without change. The data volume (24 MB raw / 1.5 MB slim) is small enough for either; the deciding factor is who is allowed to hit the endpoint and how often (R3, R12). A hybrid — nightly list sync, lazy detail + `closed`/`temporaryClosed` check at selection — matches the data (list is sparse and stable; the dynamic part is per-id).

### 9.3 Things in the existing fixture that this measurement contradicts (for whoever turns it into the production source)

1. `holidays` handled as `is_string()` (`class-test-live-pochta-point-source.php:996`) but it is an **array of objects** for every ОПС → it is silently dropped (all 10 sampled ОПС have it).
2. Sends explicit `pvzType ["russian_post","postamat"]` and maps only those two types → never returns the 812 `additional_pvz` (the docblock says "outside the scope"); omission would be valid.
3. Pagination loop (`request_points_paginated`, lines 498-520) has no dedupe and trusts offset paging (R2).
4. `settings_id` resolution/constants (`resolve_settings_id`, `request_settings_id`, lines 595-679) are unnecessary — the listing works with no id.
5. `accepts_cod = 'postamat' !== type` would be `true` for `additional_pvz` (R7).
6. `map_schedule()` turns `перерыв` into a second open interval (see §4 Hours).
7. Listing record lacks a name; `brandName` is present in the detail only — consistent with the fixture's synthesised name. No contradiction.

## 10. Final table: question → measured answer → evidence file

| # | Question | Measured answer | Evidence (under `f-raw/`) |
|---|---|---|---|
| 1 | Endpoints in the current widget bundle | 7 endpoints (§1); widget.js is only an iframe loader; main.js hash unchanged since #226; assets Last-Modified 18.03.2026; no lazy chunks | `bundle/widget.js`, `bundle/map.html`, `bundle/main.a7d147fb5267ec1f0932.js`, `bundle/*.hdr` |
| 2 | Does `/api/pvz` need `settings_id`? Does it filter? | No; absent/bogus/0/demo/ours identical; only a non-numeric string is rejected (422); no filtering by account | `samples/i2-*.json`, `requests.jsonl` tags `item2:*` |
| 2b | `pvzType` omitted? | = all three types (123 vs 117 in the test box); `[]` same; unknown value 422 | `samples/i2b-*.json` |
| 3 | Full coverage | 36 163 points = 31 912 / 3 439 / 812; naive paging loses 10 % (3 616 dups); tiling = 630 req, 443 leaves, repeatable (0 diff over 2 passes) | `samples/full-list-pass1.json.gz`, `samples/full-list-quad1.json.gz`, `samples/full-list-quad2.json.gz`, `quad2.out`, `scripts/tile.py` |
| 3b | Size / time | 24.4 MB (1.8 MB gz) full, 1.49 MB (536 KB gz) slim; 630 req ≈ 5.3 min at 2 req/s (observed 422 s) | same + `requests.jsonl` tags `item3:quad*` |
| 3c | vs report E | E 31 892 / 3 439 / 812 (36 143); now 31 912 / 3 439 / 812 (+19 Chukotka +1 south); pochta.ru map says 61 371 total objects | `requests.jsonl` tags `item3:extent-*`, `item8:G-total` |
| 4 | Detail record, checkout fields, 30 samples | §4; cash/cardPayment false everywhere; `closed`/`temporaryClosed` false for all 30; weight limit absent (comes from tariff dictionary); `holidays` structured array | `samples/i4-details-30.json`, `samples/i4-dict-batch.json` |
| 5 | Tariff endpoints | `free_tariff_by_settings`: 1 tariff/call, needs existing site id, `type` honoured, matches public tariff within 10–30 kop. on 3 routes × 2 products; `*_tariff_public`: need guid → 422/500 | `samples/i5-free-tariff-127220.json`, `samples/i5-*_tariff_public-*.json`, `requests.jsonl` tags `item5:*` |
| 6 | CORS | Reflected Origin + credentials on `/api/pvz` and `/api/pvz/{id}`; preflight 200 → browser can call directly; pochta.ru backend has no ACAO → proxy needed | `requests.jsonl` tags `item6:*`, `item8:G-cors`, `item8:G-preflight` |
| 7 | Rate limit / blocking / caching | none seen at ≤ 2 req/s over 1 710 req; 6 hangs (0.35 %); no ETag; `max-age=0, private, must-revalidate`; nightly refresh | `requests.jsonl` (status histogram), `samples/i4-details-30.json` timestamps |
| 8 | pochta.ru own map backend | richer (schedule in list), 61 371 objects, ~5 KB/record, session cookie, no foreign-origin CORS; set-stable pagination in a 395 test; widget backend is the lighter, measured-stable one | `samples/i8-G-moscow.json`, `requests.jsonl` tags `item8:*` |
| 9 | Framework fit; sync vs live | Interface fits unchanged (VIEWPORT + local table + lazy detail); numbers in §9.2; 6 contradictions with the fixture in §9.3 | Serena reads: `woodev/shipping-method/pickup/interface-point-source.php`, `abstract-bulk-point-source.php`, `class-point-query.php`, `rest-api/class-pickup-controller.php` |

## 11. Not checked

- Effect of `prevTopRightPoint`/`prevBottomLeftPoint`, `dimensions`, `orderLines` on `POST /api/pvz` (sent by the bundle; ignored in my probes).
- Real rate limit above 2 req/s; behaviour from a datacenter IP (my tests are from a residential Moscow connection) or with many parallel callers.
- Incidence of `closed`/`temporaryClosed`/non-trivial `cashPayment` across the 36 163 points (needs one detail GET per point; sampled 30 only).
- `index_public`, `show_public`, `cms/*` (writes), `PUT /api/sites/{id}/ping` (write).
- Successful response of `pick_up_tariff_public` (needs a cabinet guid).
- Whether `free_tariff_by_settings` honours any origin override (no parameter exists in the bundle; I did not try undocumented ones).
- Large-box pagination stability of the pochta.ru backend; `limit` cap there.
- Legal/ToS status of third-party use of either backend.

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
