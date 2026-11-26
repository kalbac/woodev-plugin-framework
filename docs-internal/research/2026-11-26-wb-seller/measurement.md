# Wildberries Seller — measurement log (M0a #1995, M0b #1996)

The spec's phase-0 measurement cards produce this file together
([spec §3](../../specs/2026-11-26-wildberries-seller-plugin-design.md#3-phase-0--measure-before-design-is-final)):
**M0b** (sandbox, this file's first section, done 26.11.2026) and **M0a** (live cabinet, section at the end,
card [#1995](https://github.com/kalbac/woodev-plugin-framework/issues/1995)). S0 folds the results into the spec.

Evidence labels as in the [API map](api-map.md): **SOURCED** (official WB OpenAPI or portal page),
**MEASURED** (observed in a call, fixture cited), **SECONDARY**, **UNVERIFIED**.
Fixtures are request/response pairs under [fixtures/m0b/](fixtures/m0b/) — sandbox test data only,
`Authorization` redacted in every file. No token value, seller name or real buyer data appears here.

---

## M0b — sandbox (card #1996, 26.11.2026)

### Method and access

- Token: `WB_TEST_TOKEN` from the plugin `.env`, loaded into the probe process only; decoded locally —
  `acc: 2` (test), category bitmask `s: 0`, expires 2027-05-27. Never printed, logged or committed.
- Hosts called: `marketplace-api-sandbox`, `content-api-sandbox`, `discounts-prices-api-sandbox.wildberries.ru`.
  **No live-cabinet host was called by this card** (the probe refuses any non-sandbox host and any DELETE).
  `common-api-sandbox.wildberries.ru` does not resolve (MEASURED, `ping-common.json`).
- Pacing: ≥1.05 s between Marketplace calls (sandbox: 1 request/second for all Marketplace methods, SOURCED).
  The sandbox `ping` answers `X-Ratelimit-Limit: 99` (MEASURED, `ping-marketplace.json`).
- Every write was listed in the ledger below **before** it was sent; refusals were recorded once and not
  retried in a loop (two corrected-input retries are logged with their root cause).
- The sandbox already held test data from the owner's earlier project (cards `OWS-TEE-1/2/MS`, FBS warehouse
  179667, offices 1–3+). M0b added one card, one DBS warehouse, stocks, and the orders/supplies below.
  Nothing was deleted.

**Portal access (how the official sandbox spec was read).** `dev.wildberries.ru` HTML answers HTTP 498 to
curl, to the `web_fetch` tool and to Orca's embedded browser. A **fresh headed full Chrome with a temporary
profile** (Playwright `launchPersistentContext`, `channel: 'chrome'`) passes the challenge bootstrap — the
sandbox page rendered on the first 5-second poll. The page embeds the full OpenAPI spec in its Next.js
payload (`__redoc_state.spec.data`, `x-file-name: sandbox-environment`); it was extracted verbatim to
[sandbox-environment-2026-11-26.json](sandbox-environment-2026-11-26.json)
(sha256 `94558ff8798574b2109b04e1c1fbc471ed1ebdde9c970367b5a0af46732730c2`). There is **no downloadable
yaml** for this spec: `/api/swagger/yaml/en/sandbox-environment.yaml` and every probed variant answer 404,
while the 13 numbered category yamls download fine (their sha256 prefixes match the API map exactly).
`seller.wildberries.ru` instruction pages load the same way (the official FBS-marking instruction was read
there); only the article's collapsed accordion body resisted automation. Gotcha:
[wb-portal-498-passes-in-a-fresh-headed-chrome](../../gotchas/wb-portal-498-passes-in-a-fresh-headed-chrome.md).

### The emulation surface (SOURCED + MEASURED)

The sandbox spec (snapshot above) defines, all on `marketplace-api-sandbox` unless noted:

| Method | Purpose | Measured |
|---|---|---|
| `POST /api/v3/test/fbs/orders/make` | buyer cart → FBS units; `{orders:[{sku, amount≤10}]}` 1–10 items; needs FBS stock ≥ amount and price ≠ 0 | ✅ 204, units in `orders/new` |
| `POST /api/v3/test/dbs/orders/make` | buyer cart → DBS units; adds `deliveryType` (`dbs` default, `dbsPickupPoint`, `edbs`) and `address` (omitted → documented Moscow default) | ✅ 204 |
| `PATCH /api/v3/test/fbs/orders/{id}/decline` | first-hour buyer cancellation (FBS) | ✅ 204 |
| `PATCH /api/v3/test/dbs/orders/{id}/decline` | first-hour buyer cancellation (DBS) | ✅ 204 |
| `PATCH /api/v3/test/fbs/supplies/{supplyId}/close` | WB acceptance: listed `orderIds` → reshipment, the rest → `sorted` | ✅ 204 |
| `PATCH /api/v3/test/fbs/orders/{id}/deliver` | arrival at pickup point → `ready_for_pickup` | ✅ 204 |
| `PATCH /api/v3/test/fbs/orders/{id}/receive` `{code}` | buyer receipt → `sold`; any code string | ✅ 204 |
| `PATCH /api/v3/test/fbs/orders/{id}/reject` `{code}` | decline on receipt → `canceled_by_client` | not sent (FBS receipt path covered by receive) |
| `PATCH /api/v3/test/fbs/orders/{id}/defect` | defect cancellation | not sent |
| `POST /api/v3/test/click-collect/orders/make`, `…/decline` | in-store pickup emulation | out of 1.0 scope |
| feedbacks/questions test methods (`feedbacks-api-sandbox`) | out of 1.0 scope | not sent |
| `POST /content/v1/cards/delete` (content sandbox) | delete from trash | **never sent** (no-delete rule) |

Supply/box ids in the sandbox match `^WB-GI-SAND-[0-9]+$` / `^WB-TRBX-SAND-[0-9]+$` (SOURCED; measured
`WB-GI-SAND-192394`, `WB-GI-SAND-192397`).

### Sandbox coverage map — methods with NO sandbox server (SOURCED, two measured 404s)

Parsed from the per-operation `servers` blocks of `03-orders-fbs.yaml` / `05-dbs.yaml` (26.11 versions):

- **FBS prod-only:** `GET …/fbs/supplies/{supplyId}/shipping-points` (measured 404 `path not found`,
  `supplyA-shipping-points.json`), `PATCH …/fbs/supplies/shipping-method`,
  `PATCH …/supplies/{supplyId}/orders/transfer`, `POST /api/v3/orders/stickers/cross-border`,
  `GET /api/marketplace/v3/fbs/orders/archive`, all five auto-return settings methods.
- **DBS prod-only:** `POST …/dbs/orders/stickers` (measured 404, `w32-dbs-stickers-probe.json`),
  `POST …/dbs/orders/final-price`, `POST …/dbs/orders/b2b/info`, `POST …/dbs/orders/meta/details`.
- Everything else in the FBS/DBS flow lists the sandbox server and worked (below).

Consequence for the plugin: the supply screen's shipping-point picker and the DBS PDF sticker cannot be
exercised against the sandbox; their fixtures must come from the OpenAPI text, and their live behaviour is
M0a/S1 territory. The sandbox `deliver` **does not enforce** `SupplyShippingRequired` — supply A handed over
with no shipping method set (MEASURED, `w11-supplyA-deliver.json` 204; production documents the 409).

### FBS flow, end to end (MEASURED)

Cart A (`w6`, units 192392/192393, one `orderUid`): `orders/new` → `orders/status` `new/waiting` →
supply created (`w7`, 201 `{id}`) → units added (`w8`, 204) → `confirm/waiting` → stickers PNG 58×40
(`w9`, 200: `partA`, `partB`, `barcode` `sand-…`, `file` base64 ≈12.5 KB each) → `deliver` (`w11`, 204) →
`complete/waiting` → supply QR (`supplyA-barcode-qr.json`, 200 `{barcode, file}` PNG; available only after
deliver, SOURCED) → WB-side `close` with `orderIds:[192393]` (`w12`) → 192392 `complete/sorted`,
192393 listed in `GET /api/v3/supplies/orders/reshipment` as `{supplyID, orderID}` and its status stays
`complete/waiting` → `deliver` emulation (`w13`) → `receive {code:"1234"}` (`w14`) → `complete/sold`.

Supply detail/list shapes (MEASURED, `supplyA-detail.json`, `supplies-list.json`):
`{id, isPickupPointShipmentAllowed, done, createdAt, closedAt, scanDt, name, cargoType, destinationOfficeId,
spotAvailable}`; list `{next, supplies[]}`. `done:true` after deliver; `scanDt` set after the WB-side close.

Cancellation (cart C, `w19`–`w21`): buyer `decline` → `supplierStatus:"new"`, `wbStatus:"declined_by_client"`;
seller `PATCH /api/v3/orders/{id}/cancel` (no reason field) → `supplierStatus:"cancel"`, `wbStatus:"canceled"`.

Prices ×100 confirmed: `salePrice:100000` for a 1000 ₽ card; `currencyCode:643`; `converted*` present on FBS
too (MEASURED, `fbs-orders-new-cartA.json`).

### DBS flow, end to end (MEASURED)

Cart of 6 tee-1 units (`w22b`, 192400–192405, one `groupId` `01a21574-7068-…`) + one `dbsPickupPoint` unit
(`w23b`, 192406, own `groupId`). Status calls are **`/api/marketplace/v3/dbs/orders/status/*`** (not
`/api/v3/dbs/…` — the spec's §7.4 shorthand hides the prefix; MEASURED). Bodies: `confirm/deliver/cancel`
take `{ordersIds:[…]}`, `receive/reject` take `{orders:[{orderId, code}]}` (SOURCED + MEASURED, and the
official KB article states the same split).

- `confirm` (`w24`) and `deliver` (`w25`): 200 `{requestId, results:[{orderId, isError}]}` — per-unit results.
- `status/info`: `{orders:[{orderId, supplierStatus, wbStatus, errors}]}`.
- Final axes (`dbs-status-info-final.json`): receive → `receive/sold`; reject → `reject/canceled_by_client`;
  cancel from `new` → `cancel/canceled`; buyer decline → `new/declined_by_client`.
- `groups/info` (`dbs-groups-info.json`): `[{groupID, deliveryCost:100, convertedDeliveryCost:100,
  currencyCode:643, …}]` — paid delivery 1 ₽ ×100, per group.
- `delivery-date` (`dbs-delivery-date.json`): `{dDate:"2026-11-29", dTimeFrom:"02:43", dTimeTo:"04:58",
  dDateOld:"", …}` per unit.
- DBS `orders/new` payload carries `address`, `groupId`, `orderUid`, `requiredMeta:null`, `optionalMeta:null`,
  `options:{isB2b:false}`; **no `wbStickerId` key** although the production schema requires it (fidelity gap).

### Check 1 — DBS buyer phone (spec §13.4)

**What was shown.**

- Documented formats (SOURCED, `05-dbs.yaml` `DbsOnlyClientInfo`): `replacementPhone` example `79871234567`
  (no `+`), `phone` example `+79871234567`, `additionalPhones[]`/`replacementAdditionalPhones[]` of the same
  shape; `phoneCode` is a separate **integer extension** (example `1234567`, `0` = not assigned),
  `additionalPhoneCodes[]` string extensions (`'12345'`, `'65498'`). The docs say: dial `phone`, then the
  extension from `phoneCode`; empty string = number not assigned yet.
- Sandbox shapes (MEASURED, `dbs-client-phones.json`): `replacementPhone:"+79992222222"`,
  `phone:"+79991111111"`, `phoneCode:0`, `additionalPhoneCodes:[]`, `firstName:"empty"`,
  `fullName:"empty empty empty"`. **The sandbox omits `additionalPhones` and `replacementAdditionalPhones`
  entirely** although the schema marks them required — a fidelity gap; the plugin must tolerate absence.
- Carrier validation, run against the documented formats (MEASURED, code executed 26.11):

| input | CDEK `Cdek_Order_Request::phone()` | Yandex (`wc_sanitize_phone_number`) | source of the format |
|---|---|---|---|
| `79871234567` | `+79871234567` | `79871234567` | yaml `replacementPhone` example |
| `+79871234567` | `+79871234567` | `+79871234567` | yaml `phone` example |
| `+79992222222` | `+79992222222` | `+79992222222` | sandbox `replacementPhone` |
| `+79991111111` | `+79991111111` | `+79991111111` | sandbox `phone` |
| `''` (not assigned) | `null` → export refused with a merchant message | `''` sent as-is | yaml description |

  CDEK: the real static method was executed (`woocommerce-edostavka/includes/orders/class-cdek-order-request.php:485`
  — strips non-digits, requires 10–15 digits, normalises an 11-digit `7…`/`8…` to `+7…`). Yandex: the plugin
  calls `wc_sanitize_phone_number` (`woocommerce-yandex-delivery/includes/orders/class-yandex-order-request.php:199`),
  whose body is `preg_replace('/[^\d+]/', '', $phone ?? '')` (WooCommerce `includes/wc-formatting-functions.php:1117`,
  local latest-stable copy) — digits and `+` only, no length or country gate.
- Carrier API documentation (SOURCED, read 26.11.2026):
  - **CDEK** (the official OpenAPI bundled in the plugin, `woocommerce-edostavka/docs/cdek-api/openapi.json`):
    `PhoneDto.number` required, `maxLength: 24`; **`PhoneDto.additional`** — «Дополнительная информация
    (добавочный номер)», `maxLength: 255`; the reverse-validate schema states the number «должен передаваться
    в международном формате: код страны и сам номер». A WB relay number (`+79991111111`, 12 chars) fits;
    WB's `phoneCode` extension maps to `additional`.
  - **Yandex Delivery** ([offers/create reference](https://yandex.ru/support/delivery-profile/ru/api/other-day/ref/3.-Osnovnye-zaprosy/apib2bplatformofferscreate-post)):
    `recipient_info.phone` **required**, type string, example `+79529999999`, no explicit regex on the page;
    **`phone_ext`** optional — «Добавочный номер телефона получателя», example `001234`. WB's `phoneCode`
    maps to `phone_ext`.
  - Design note for §7.3: both carriers have an extension field, so the WB plugin can pass the relay number
    as the recipient phone and `phoneCode` as the extension; today neither delivery plugin maps an extension
    (CDEK sends `phones[0].number` only, Yandex `recipient_info.phone` only).

**Verdict.** Every documented WB relay format passes both plugins' recipient-phone validation unchanged or
normalised to `+7…`, and matches both carrier APIs' documented expectations (CDEK: international format,
≤24 chars; Yandex: the `+7…` example shape); the extension is a separate field with a home in both APIs
(`additional` / `phone_ext`). An unassigned (empty) number is refused by CDEK and passed through by Yandex
(whose API marks `phone` required — an empty value would be the API's refusal, not the plugin's).

**What remains unproven.** No call was placed to a relay number and no carrier order was created, so
(a) whether the relay actually connects with the extension (R1), and (b) whether the carrier APIs accept the
number **at runtime** (documentation only; the Yandex plugin does not validate beyond sanitising) are
UNVERIFIED. Production may also return formats beyond the documented examples (other countries, `8…`
prefixes) — M0a observes the real shapes.

### Check 2 — DBS confirmation code (spec §13.4)

**What was shown.**

- MEASURED (`w26`–`w28`): `receive` **without** `code` → **400 `IncorrectRequest`** (whole request refused,
  unit stays `deliver`); with `code:"1234"` → 200; with a **wrong** code `"0000"` → **200, unit sold**.
  The sandbox requires the code's *presence* but accepts *any value* — the sandbox section of the official
  page says exactly that: «you can use any combination of characters» (SOURCED).
- Where the buyer sees it (SOURCED): `api.OrderCodeRequest.code` description — «Confirmation code.
  Displayed to the buyer on the website and in the Wildberries app». The official KB article
  [«Модель продаж DBS (Витрина) — заказы и доставка»](https://dev.wildberries.ru/knowledge-base/articles/019d49a3-ff7d-70ba-a872-828430b24ea1/dbs)
  (read 26.11.2026, headed Chrome) says both `receive` and `reject` need «the confirmation code that the
  buyer gives the courier when receiving the order».
- `reject` also takes `{orders:[{orderId, code}]}` (MEASURED `w29`, 200 with a code).

**Verdict.** The design's «code may be required» branch is real at least in the sandbox: a missing code is a
400, not a per-unit error. The plugin must collect the code from the courier/buyer and send it; whether an
empty code can ever succeed is a production question.

**What remains unproven.** Production behaviour for a missing or wrong code (R2): the sandbox cannot show a
wrong-code refusal because it validates no values. M0a does not create orders, so this stays UNVERIFIED until
a real DBS order exists; the design already handles both outcomes (refusal → merchant-facing error, success →
closed order).

### Marking — «Честный знак» codes (spec §13.4, item 14)

**What was shown.**

- A test card in a marked subject (Кроссовки, subjectID 105) was created in the content sandbox
  (`w1`, immediate, no task id; barcode `5032779493634` generated). **`needKiz:false` anyway** — the sandbox
  sets it false for every card (MEASURED, `cards-list-kiz-readback.json`), and every sandbox order has
  `requiredMeta:null` with `optionalMeta:["imei","uin","gtin","sgtin","customsDeclaration"]` (FBS) or both
  null (DBS). **The sandbox never requires a code** — the SOURCED exclusion «in the sandbox marking is
  optional» is now also MEASURED.
- Code write in `confirm` (MEASURED, `w17`): `PUT /api/v3/orders/{id}/meta/sgtin` `{sgtins:[code]}` → 204;
  the read-back (`fbs-meta-after-code.json`) returns the code **with GS separators `\u001d` preserved**.
- A code **without GS** was accepted too (`w17b`, 204) — the sandbox runs **no format validation**;
  production documents `sgtinNoGS` and the rest of the decision vocabulary (below). The plugin must validate
  locally before sending, as designed.
- Hand-over with a codeless unit in the supply (`w18`): **204, supply closed** — no `MetaValidationFail`.
- The sandbox meta read shape **differs from the documented one**: measured `{orders:[{id, meta:{sgtin:{value:[…]}}}]}`
  vs the documented `MetaDetails` array of `{key, value, decision}` (SOURCED). The sandbox has **no `decision`
  field at all**, so validation states cannot be observed there.
- Production decision vocabulary (SOURCED, `03-orders-fbs.yaml` `MetaDetails`): pass — `filled`,
  `deadlineExceeded`, `optional`, `sgtinIntroduced`, `sgtinSoldB2B`; block — `pending`, `required`,
  `sgtinInvalidFormat`, `sgtinNoGS`, `sgtinHasInvalidSymbols`, `sgtinHasNonLatinSymbols`, `sgtinInvalidPattern`,
  `sgtinNotFound`, `sgtinEmitted`, `sgtinApplied`, `sgtinWrittenOff`, `sgtinRetired`, `sgtinWithdrawn`,
  `sgtinDisaggregation`, `sgtinDisaggregated`, `sgtinAppliedNotPaid`. The 409 `MetaValidationFail` body
  (SOURCED example): `{code, message:"Fix them to dispatch items", data:{orders:[{id, metaDetails:[{key,
  value, decision}]}]}}`.
- Cabinet route (D4): the official seller instruction
  [«Маркировка товаров для модели «Маркетплейс» (FBS)»](https://seller.wildberries.ru/instructions/ru/ru/material/items-labeling-in-fbs)
  (updated 02.10.2026, read 26.11.2026 in headed Chrome) states: the KIZ is added **to the assembly order**
  («Если не добавить КИЗ в сборочном задании, заказы, от которых отказался покупатель, вернутся вам в ПВЗ.
  Такой возврат будет платным» — a missing code turns buyer refusals into **paid** returns); at assembly
  stage the code must be «В обороте»; **the seller withdraws the code from circulation himself** in
  «Честный знак» at actual shipment, and returns it on a return; a damaged code counts as missing.
  IMEI: «система не даст создать отправление без IMEI»; UIN: «Без УИН отгрузить ювелирные изделия не
  получится». The article has a section «Как добавить маркировку в приложении WB Partners» — the
  cabinet/app route exists officially, but its body is a collapsed accordion that did not expand under
  automation (four attempts, including direct clicks on the `collapsible-container` control), so the exact
  cabinet steps for a shop-handled order remain unread. SECONDARY sources describe them («open the assembly
  order, press «Добавить КИЗ», scan the code» — getmark.ru, 12.10.2026; mertech.ru, 15.11.2024).
- GTIN side-finding (SOURCED, same article): since 01.10.2026 WB checks GTIN in cards of marked goods via
  ГИС МТ and blocks cards with a missing/invalid GTIN; one GTIN cannot sit in the «Баркоды» field of several
  cards — the others use «Дополнительный GTIN». Relevant to the card-export cards (M0a/S1), not to orders.

**What remains unproven.** Production refusal for a missing/invalid code and the real `decision` states
(R3) — a sandbox run cannot show them, and M0a creates no orders. Whether the unsupported identifiers
(uin/imei/gtin/expiration/customsDeclaration) can be entered in the cabinet for a shop-handled order (D4)
stays UNVERIFIED from official sources; WP9's gate design does not depend on the answer.

### Stock behaviour hint (item 12, sandbox only)

MEASURED: the emulated order **lowers** seller-warehouse stock immediately — FBS tee-1 7→6 and tee-2 4→3
after cart A (`stocks-fbs-after-make.json`), KIZ 10→8 after cart B, DBS tee-1 10→4 (6 units) and tee-2 10→9
after the DBS makes (`stocks-dbs-after-makes.json`). Cancels did **not** restore it within the session
(tee-1 stayed 4 after a buyer decline and a seller cancel, `stocks-fbs-after-cancels.json`).
This is a sandbox hint, not production proof (R4/U6): it supports the spec's `wc_only` formula (push the
shop's own number after ingest; WB's absolute value already reflects the order), and it warns that a
cancel may leave WB's number lowered until something restores it.

### Sandbox fidelity gaps (MEASURED — do not build production assumptions on these)

1. FBS `orders/new` carries `address` and `ddate` for a Russian test seller; production documents address as
   Turkey-sellers-only (`null` otherwise) and `ddate` for oversized cargo only (SOURCED `OrderNew`).
2. `address.longitude`/`latitude` are **swapped** in every sandbox payload (55.627058 sits in `longitude`).
3. `needKiz:false` everywhere; `requiredMeta` never set; meta read has no `decision`; no code validation.
4. DBS client omits the two `additionalPhones` arrays; names are `"empty"`; `phoneCode:0`.
5. `dbsPickupPoint` orders get the default **home** address, not a pickup-point address.
6. DBS `orders/new` lacks `wbStickerId` (required in the production schema).
7. `deliver` does not enforce shipping method or marking; stock is not restored on cancel.
8. The DBS make validates the address against its own dictionary: a plausible explicit Moscow address was
   refused `400 IncorrectAddress` (`w22-dbs-make-cart.json`) while the documented default passed (`w22b`).

### Write ledger (every write listed before it was sent)

Planned writes and leftovers: W1 one sandbox card `M0B-TEST-KIZ`; W2 one DBS warehouse (192391, office 2);
W3/W4/W23a sandbox stock records; W5 price task (refused — price already set at creation); W6/W15/W19 three
FBS carts (6 units); W7/W16 two supplies; W8/W16 supply adds; W9 stickers; W11/W18 hand-overs; W12–W14 WB-side
emulation; W17/W17b two sgtin writes; W20/W21 buyer decline + seller cancel; W22b/W23b two DBS carts (7 units);
W24–W31 DBS status calls; W26b/W33 cleanup closes; W32 one DBS-sticker probe (404). W10 was **not sent**
(prod-only method). Nothing was deleted; all leftovers are sandbox test data.

Sent log (UTC, from the probe):

```text
01:20:30 W1 SEND POST content /content/v2/cards/upload — test card M0B-TEST-KIZ (subject 105)
01:20:31 W2 SEND POST marketplace /api/v3/warehouses — M0b Test DBS, officeId 2
01:20:52 W3 SEND PUT /api/v3/stocks/192391 (DBS) — chrtId 416209 amount 10, chrtId 440889 amount 10
01:20:53 W4 SEND PUT /api/v3/stocks/179667 (FBS) — chrtId 440889 amount 10
01:20:54 W5 SEND POST /api/v2/upload/task — nmID 440890 price 2500 discount 0
01:21:02 W5 REFUSED 400 'all item Nos. are specified incorrectly, or the specified prices and discounts are already set' — not retried
01:21:12 W6 SEND POST /api/v3/test/fbs/orders/make — cart A: sku 2000000285011 x1, sku 2000000285028 x1
01:21:43 W7 SEND POST /api/v3/supplies — name M0b test supply A
01:21:54 W8 SEND PATCH /api/marketplace/v3/supplies/WB-GI-SAND-192394/orders — add 192392, 192393
01:21:56 W9 SEND POST /api/v3/orders/stickers?type=png&width=58&height=40 — 192392, 192393
01:22:36 W10 NOT SENT — shipping-points/shipping-method have no sandbox server; measured 404 on shipping-points
01:22:36 W11 SEND PATCH /api/v3/supplies/WB-GI-SAND-192394/deliver — no shipping method set
01:22:50 W12 SEND PATCH /api/v3/test/fbs/supplies/WB-GI-SAND-192394/close — orderIds [192393]
01:22:53 W13 SEND PATCH /api/v3/test/fbs/orders/192392/deliver
01:22:54 W14 SEND PATCH /api/v3/test/fbs/orders/192392/receive {code:'1234'}
01:23:07 W15 SEND POST /api/v3/test/fbs/orders/make — cart B: KIZ sku 5032779493634 x2
01:23:28 W16 SEND POST /api/v3/supplies — M0b test supply B; PATCH add 192395, 192396
01:23:31 W17 SEND PUT /api/v3/orders/192395/meta/sgtin — one test code with GS separators
01:23:57 W17b SEND PUT /api/v3/orders/192396/meta/sgtin — code WITHOUT GS separator (format probe, once)
01:24:00 W18 SEND PATCH /api/v3/supplies/WB-GI-SAND-192397/deliver — f4 has no code
01:24:11 W19 SEND POST /api/v3/test/fbs/orders/make — cart C: tee-1 x2
01:24:13 W20 SEND PATCH /api/v3/test/fbs/orders/192398/decline
01:24:14 W21 SEND PATCH /api/v3/orders/192399/cancel — no reason
01:24:26 W22 SEND POST /api/v3/test/dbs/orders/make — tee-1 x6, deliveryType dbs, explicit address
01:24:27 W23 SEND POST /api/v3/test/dbs/orders/make — tee-2 x1, deliveryType dbsPickupPoint
01:24:40 W22 REFUSED 400 IncorrectAddress — corrected retry W22b omits the address (documented default)
01:24:40 W23 REFUSED 409 NotFound — sku has no DBS stock; W23a adds it
01:24:40 W23a SEND PUT /api/v3/stocks/192391 (DBS) — chrtId 416208 amount 10
01:24:41 W22b SEND POST /api/v3/test/dbs/orders/make — tee-1 x6, no address
01:24:42 W23b SEND POST /api/v3/test/dbs/orders/make — tee-2 x1, dbsPickupPoint, no address
01:25:09 W24 SEND POST /api/marketplace/v3/dbs/orders/status/confirm — 192400..192403
01:25:10 W25 SEND POST /api/marketplace/v3/dbs/orders/status/deliver — 192400..192403
01:25:22 W26 SEND POST dbs/status/receive — 192400 WITHOUT code → 400 IncorrectRequest
01:25:23 W27 SEND POST dbs/status/receive — 192401 code 1234 → 200
01:25:24 W28 SEND POST dbs/status/receive — 192402 wrong code 0000 → 200
01:25:25 W29 SEND POST dbs/status/reject — 192403
01:25:26 W30 SEND POST dbs/status/cancel — 192404 from new
01:25:27 W31 SEND PATCH /api/v3/test/dbs/orders/192405/decline
01:25:42 W26b SEND POST dbs/status/receive — 192400 with code 1234 (close the unit left open by W26)
01:25:43 W32 SEND POST /api/marketplace/v3/dbs/orders/stickers — 192406 → 404 (prod-only)
01:25:44 W33 SEND POST dbs/status/cancel — 192406 from new (cleanup)
```

### Fixtures

85 pairs in [fixtures/m0b/](fixtures/m0b/), named by the probe label (`w*` = writes, others reads).
Each file: `{label, recorded_at, request{method,url,headers(redacted),body}, response{status,headers,body}}`.
The official sandbox spec snapshot is [sandbox-environment-2026-11-26.json](sandbox-environment-2026-11-26.json).
Key pairs for the plugin's tests: `fbs-orders-new-cartA.json` (full FBS unit), `dbs-orders-new2.json` (DBS unit
with address/group), `dbs-client-phones.json`, `w9-stickers-png-cartA.json`, `supplyA-barcode-qr.json`,
`w26-dbs-receive-nocode.json` (400), `fbs-meta-after-code.json`, `fbs-reshipment-list.json`,
`w20-fbs-buyer-decline.json` + `fbs-status-cartC-cancelled.json`.

### What remains unproven after M0b (matches spec §13.4)

- R2 production confirmation-code behaviour (missing/wrong) — sandbox accepts any value.
- R3 production marking refusal and `decision` states — sandbox validates nothing and never requires a code.
- R4/U6 production stock behaviour — the sandbox hint (lower on order, no restore on cancel) is not proof.
- D4 cabinet entry of unsupported identifiers — the official article confirms the assembly-order route and
  the WB Partners app section exists, but its collapsed body (the exact steps) did not expand under
  automation; whether uin/imei/gtin/expiration/customsDeclaration can be entered there for a shop-handled
  order stays UNVERIFIED.
- Prod-only methods (shipping points/method, DBS stickers/final-price/b2b/meta-details, auto-returns,
  cross-border stickers, archive) — no sandbox route; OpenAPI text only.
- Relay-number dialability and Yandex API acceptance of WB phone formats — no calls, no carrier orders.
- Sandbox fidelity gaps above — production payloads may differ in every listed point.

---

## M0a — live cabinet (card #1995)

Not yet written — owned by [#1995](https://github.com/kalbac/woodev-plugin-framework/issues/1995)
(reads, ≤3 test cards at stock 0, one empty supply, refusals recorded once). This section is a placeholder
so the two phase-0 PRs do not collide; M0a appends its results here.
