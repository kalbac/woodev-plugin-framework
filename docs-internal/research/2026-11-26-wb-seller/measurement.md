# Wildberries Seller — measurement log (M0a #1995, M0b #1996)

The spec's phase-0 measurement cards produce this file together
([spec §3](../../specs/2026-11-26-wildberries-seller-plugin-design.md#3-phase-0--measure-before-design-is-final)):
**M0b** (sandbox, this file's first section, done 26.11.2026) and **M0a** (live cabinet, section at the end,
card [#1995](https://github.com/kalbac/woodev-plugin-framework/issues/1995)). S0 folds the results into the spec.

Evidence labels as in the [API map](api-map.md): **SOURCED** (official WB OpenAPI or portal page),
**MEASURED** (observed in a call, fixture cited), **SECONDARY**, **UNVERIFIED**.
Fixtures are request/response pairs with `Authorization` redacted in every file: [fixtures/m0b/](fixtures/m0b/)
(sandbox test data) and [fixtures/m0a/](fixtures/m0a/) (live cabinet, our own test objects only, cabinet ids
replaced by placeholders). No token value, seller name or real buyer data appears here.

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
while the numbered category yamls download fine — the four fetched for this card (01-general, 02-items,
03-orders-fbs, 05-dbs) match the API map's sha256 prefixes exactly, i.e. the same file versions.
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
- **Sandbox server listed AND called here:** FBS `orders/new`, `orders/status`, `orders/stickers` (PNG),
  `orders/{id}/cancel`, `orders/{id}/meta/sgtin`, `orders/meta` read, supplies create/list/detail/add/
  deliver/barcode, `supplies/{id}/order-ids`, `supplies/orders/reshipment`, all `test/fbs/*` emulation
  used below; DBS `orders/new`, `orders/client`, `orders/delivery-date`, `groups/info`,
  `status/{confirm,deliver,receive,reject,cancel,info}`, `test/dbs/*` make/decline; content cards
  list/upload, subjects/charcs; prices list/upload-task; stocks/warehouses/offices.
- **Sandbox server listed but NOT exercised** (no claim of «worked» for these): all `…/supplies/{id}/trbx*`
  (cargo places — a step of spec §6.2), FBS `orders/status/history`, FBS `orders/client`,
  `GET /api/v3/orders`, `GET /api/v3/dbs/orders`, every identifier write except `sgtin`
  (uin/imei/gtin/expiration/customsDeclaration), meta delete, and all DBS `…/dbs/orders/meta/*` writes.

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
- DBS `orders/new` payload carries `address`, `groupId`, `orderUid`, `requiredMeta:null`,
  `options:{isB2b:false}`; **no `optionalMeta` key** (production `OrderNewDBS` has none either) and
  **no `wbStickerId` key** although the production schema requires it (fidelity gap).

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
  - Design note for §7.3: both carrier APIs have an extension field, so `phoneCode` has a home
    (`additional` / `phone_ext`) — but **neither delivery plugin reads an extension today, and neither has
    a recipient filter** (only the generic `…_http_request_args` rewrite exists). Passing the extension
    through therefore needs a CDEK/Yandex plugin change that is **not in the current card plan** (spec T2
    allows no delivery change beyond WD1); do not assume it travels.

**Verdict.** CDEK normalises every documented WB relay format to `+7…` and accepts it (10–15 digits,
≤24 chars, international format). **Yandex does not normalise:** its sanitiser keeps digits and `+`
as-is, so the documented `replacementPhone` shape `79871234567` — the number spec §7.3 puts in the
order — reaches the Yandex API **without `+`**, unlike the API's only documented example
(`+79529999999`); whether the API accepts that shape is **UNVERIFIED**. «Passes Yandex validation»
means little: the plugin validates nothing (even `''` goes through; the API marks `phone` required,
so an empty value would be the API's refusal, not the plugin's). **Recommendation for WF9/WP10:**
normalise the relay number to `+7…` before writing it to the WooCommerce order, so every carrier
export receives the international shape regardless of which WB field it came from.

**What remains unproven.** No call was placed to a relay number and no carrier order was created, so
(a) whether the relay actually connects with the extension (R1), and (b) whether the carrier APIs accept
the number **at runtime** — in particular Yandex receiving the no-`+` `replacementPhone` shape — are
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

**Verdict.** The 400 for a missing code is **sandbox-only evidence** (measured, `w26`); the
**production** `api.OrderCodeRequest` has **no `required` list — `code` is optional there**
(SOURCED, `05-dbs.yaml`; spec §7.4 says the same). The documented per-unit errors of
`status/receive` are `NotFound`, `StatusMismatch` and `SGTINIsNotFilled` (a required marking code missing).
Nothing here proves production demands the code, so the owner-accepted R2 design stands: one automatic
`receive` attempt without a code, then handle whichever outcome comes back (refusal → merchant-facing
error and a code-entry path; success → closed order).

**What remains unproven.** Production behaviour for a missing or wrong code (R2): the sandbox cannot show a
wrong-code refusal because it validates no values. M0a does not create orders, so this stays UNVERIFIED until
a real DBS order exists; the design already handles both outcomes (refusal → merchant-facing error, success →
closed order).

### Marking — «Честный знак» codes (spec §13.4, item 14)

**What was shown.**

- A test card in a marked subject (Кроссовки, subjectID 105) was created in the content sandbox
  (`w1`, immediate, no task id; barcode `5032779493634` generated). **`needKiz:false` anyway** — the sandbox
  sets it false for every card (MEASURED, `cards-list-kiz-readback.json`), and every sandbox FBS order has
  `requiredMeta:null` with `optionalMeta:["imei","uin","gtin","sgtin","customsDeclaration"]`; DBS orders
  carry `requiredMeta:null` and **no `optionalMeta` key at all**. **The sandbox never requires a code** —
  the SOURCED exclusion «in the sandbox marking is optional» is now also MEASURED.
- Code write in `confirm` (MEASURED, `w17`): `PUT /api/v3/orders/{id}/meta/sgtin` `{sgtins:[code]}` → 204;
  the read-back (`fbs-meta-after-code.json`) returns the code **with GS separators `\u001d` preserved**.
- A code **without GS** was accepted too (`w17b`, 204) — the sandbox runs **no format validation**;
  production documents `sgtinNoGS` and the rest of the decision vocabulary (below). The plugin must validate
  locally before sending, as designed.
- Hand-over with a **truly codeless** unit (MEASURED, fix round 26.11): cart D, one KIZ unit 192407
  (`w34`), supply C `WB-GI-SAND-192408` (`w35`/`w36`), **no meta write of any kind** — the meta read
  before deliver proves `sgtin.value:null` (`fbs-meta-codeless-before-deliver.json`) — then `deliver`
  (`w37`): **204, supply closed**, unit `complete/waiting`, no `MetaValidationFail`.
  ⚠ The first round's `w18` was **not** this test: both supply B units already had codes by then
  (`w17` at 01:23:32, `w17b` at 01:23:58, deliver at 01:24:00); the original «codeless» wording was
  wrong and is corrected here. Supply A (T-shirts, no required identifier) proves nothing about marking.
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
- **DBS marking refusals differ from FBS** (SOURCED, `05-dbs.yaml`; no DBS code write was sent, and no
  fixture has `isError:true`): `status/deliver` answers **200** with per-unit results that can carry
  `isError:true`, `code:409`, `detail:MetaValidationFail` **plus `metaDetails`** (`api.StatusSetDeliverResponses`
  example shows `decision:sgtinInvalidFormat`) — a refusal inside a 200 must not read as success;
  `status/receive` documents the per-unit error `SGTINIsNotFilled` («required labeling code Chestny ZNAK
  is not specified»). DBS code writes go to `POST /api/marketplace/v3/dbs/orders/meta/sgtin` (sandbox
  server listed, **not exercised**); the DBS `meta/details` read is prod-only. WP9/WP10 must handle both
  the FBS whole-supply 409 and the DBS per-unit-in-200 shapes.
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
  automation (repeated attempts, including direct clicks on the `collapsible-container` control), so the
  exact cabinet steps for a shop-handled order remain unread. SECONDARY sources describe them («open the
  assembly order, press «Добавить КИЗ», scan the code» — getmark.ru, 12.10.2026; mertech.ru, 15.11.2024).
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
(`stocks-dbs-after-makes.json`). Cancels did **not** restore it: the FBS read 2 s after the cancels still
showed tee-1 at 4 (`stocks-fbs-after-cancels.json`), and the stronger evidence is the DBS read at 01:33:08 —
**~7.5 min after** the DBS reject, cancel and decline (01:25:27–28) — tee-1 still 4
(`stocks-dbs-after-makes.json`, taken late in the session despite its name).
This is a sandbox hint, not production proof (R4/U6): it supports the spec's `wc_only` formula (push the
shop's own number after ingest; WB's absolute value already reflects the order), and it warns that a
cancel may leave WB's number lowered until something restores it.

### Sandbox fidelity gaps (MEASURED — do not build production assumptions on these)

1. FBS `orders/new` carries `address` and `ddate` for a Russian test seller; production documents address as
   Turkey-sellers-only (`null` otherwise) and `ddate` for oversized cargo only (SOURCED `OrderNew`).
2. `address.longitude`/`latitude` are **swapped** in every sandbox payload (55.627058 sits in `longitude`).
3. `needKiz:false` everywhere; `requiredMeta` never set; meta read has no `decision`; no code validation.
4. DBS client omits the two `additionalPhones` arrays; names are `"empty"`; `phoneCode:0`.
5. The `dbsPickupPoint` make **omitted the address** and got the documented default (the sandbox spec says
   an omitted address gets that default) — what a real pickup-point order carries was **not observed**.
6. DBS `orders/new` lacks `wbStickerId` (required in the production schema).
7. `deliver` does not enforce shipping method (supply A, `w11`) or marking (truly codeless supply C,
   `w37`); stock is not restored on cancel.
8. One explicit DBS address (region Москва / street Сумская улица, re-split into fields, with the
   abbreviation «к» the spec asks callers to avoid) was refused `400 IncorrectAddress`
   (`w22-dbs-make-cart.json`) while the documented default passed (`w22b`) — **cause unknown**; this
   does not show an address dictionary.

### Write ledger (every write listed before it was sent)

Planned writes and leftovers: W1 one sandbox card `M0B-TEST-KIZ`; W2 one DBS warehouse (192391, office 2);
W3/W4/W23a sandbox stock records; W5 price task (refused — price already set at creation); W6/W15/W19/W34
four FBS carts (7 units); W7/W16/W35 three supplies; W8/W16/W36 supply adds; W9 stickers; W11/W18/W37
hand-overs; W12–W14 WB-side emulation; W17/W17b two sgtin writes; W20/W21 buyer decline + seller cancel;
W22b/W23b two DBS carts (7 units); W24–W31 DBS status calls; W26b/W33 cleanup closes; W32 one DBS-sticker
probe (404). W10 was **not sent** (prod-only method). Nothing was deleted; all leftovers are sandbox test
data. **Fix round (critic Must 1):** W34–W37 re-ran the codeless hand-over for real — see the corrected
marking bullet; the W18 line below is annotated.

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
01:24:00 W18 SEND PATCH /api/v3/supplies/WB-GI-SAND-192397/deliver — CORRECTION: both units already had codes (W17, W17b); NOT a codeless test
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
--- fix round (critic Must 1), same rules, sandbox only ---
02:13:20 W34 SEND POST /api/v3/test/fbs/orders/make — cart D: KIZ sku 5032779493634 x1
02:13:22 W35 SEND POST /api/v3/supplies — M0b test supply C (codeless)
02:13:23 W36 SEND PATCH /api/marketplace/v3/supplies/WB-GI-SAND-192408/orders — add 192407
02:13:34 W37 SEND PATCH /api/v3/supplies/WB-GI-SAND-192408/deliver — unit 192407 truly codeless (meta read proves sgtin.value null) → 204
```

### Fixtures

93 pairs in [fixtures/m0b/](fixtures/m0b/), named by the probe label (`w*` = writes, others reads).
Each file: `{label, recorded_at, request{method,url,headers(redacted),body}, response{status,headers,body}}`.
The official sandbox spec snapshot is [sandbox-environment-2026-11-26.json](sandbox-environment-2026-11-26.json).
Key pairs for the plugin's tests: `fbs-orders-new-cartA.json` (full FBS unit), `dbs-orders-new2.json` (DBS unit
with address/group), `dbs-client-phones.json`, `w9-stickers-png-cartA.json`, `supplyA-barcode-qr.json`,
`w26-dbs-receive-nocode.json` (400), `fbs-meta-after-code.json`, `fbs-reshipment-list.json`,
`w20-fbs-buyer-decline.json` + `fbs-status-cartC-cancelled.json`, `supplyC-order-ids.json`,
`fbs-meta-codeless-before-deliver.json` + `w37-supplyC-deliver-codeless.json` (the truly codeless hand-over).

### What remains unproven after M0b (matches spec §13.4)

- R2 production confirmation-code behaviour (missing/wrong) — the sandbox requires presence and accepts any
  value; the production schema leaves `code` optional. The automatic-attempt design stands.
- R3 production marking refusal and `decision` states — sandbox validates nothing and never requires a code
  (the truly codeless hand-over, `w37`, succeeded); the DBS per-unit-in-200 refusal shape is SOURCED only.
- R4/U6 production stock behaviour — the sandbox hint (lower on order, no restore on cancel) is not proof.
- D4 cabinet entry of unsupported identifiers — the official article confirms the assembly-order route and
  the WB Partners app section exists, but its collapsed body (the exact steps) did not expand under
  automation; whether uin/imei/gtin/expiration/customsDeclaration can be entered there for a shop-handled
  order stays UNVERIFIED.
- Prod-only methods (shipping points/method, DBS stickers/final-price/b2b/meta-details, auto-returns,
  cross-border stickers, archive) — no sandbox route; OpenAPI text only. Sandbox-listed but unexercised
  methods (trbx cargo places, order history/client, identifier writes other than sgtin, DBS meta writes)
  are listed in the coverage map — no fixtures exist for them.
- Relay-number dialability and carrier API acceptance at runtime — in particular Yandex receiving the
  documented `replacementPhone` shape without `+`; no calls, no carrier orders.
- Sandbox fidelity gaps above — production payloads may differ in every listed point.

---

## M0a — live cabinet (card #1995, 26.11.2026)

Items 1–11a of [spec §3.1](../../specs/2026-11-26-wildberries-seller-plugin-design.md), on a live seller cabinet,
with no orders. Status of each claim: **proven**, **refuted** (the spec or the research expected otherwise) or
**open**. Times are UTC. Fixtures are named below without their `.json` ending. No order
exists on this cabinet, so what M0b leaves open about production orders (phone formats, the confirmation code, the
marking refusal) is not observed here either.

### Method

- Token: `WB_LIVE_TOKEN` from the plugin folder's `.env`, loaded into the probe process only; never printed, logged
  or committed. Redirects were not followed.
- The probe refused by itself: any `DELETE`, any delete or trash path, a stock `amount` other than 0, a fourth test
  card, a second supply, a card or price write naming a card that is not a test card, and sending the same write twice.
- 116 requests: 48 `GET`, 65 `POST`, 3 `PUT`; answers 111 × 200, 3 × 204, 1 × 201, 1 × 409, no 429. Every request is
  one line of [request-log.tsv](fixtures/m0a/request-log.tsv).
- Placeholders in the fixtures: cards `100000001…`, groups `110000001…`, sizes `120000001…`, uploads `130000001…`,
  warehouses `700001…`, their offices `800001…` and stores `900001…`, supply `WB-GI-000000001`, barcodes
  `20600000000…`, the media task id `…0000000000aa`, the error batch id `…0000000000bb`, the supplies cursor
  `140000001`.
- Two groups of answers have no fixture because they describe the cabinet itself: the token payload with
  `seller-info`, and the reads of existing cards, prices and offices. Their requests are in the log.
- The pre-send list below was committed before the first write; W15 and W16 were added and committed before each was
  sent (separate commits of this pull request).

### Writes: listed before sending, and what happened

The table is grouped by topic. Send order: W1–W7, W14, W8, W9, W15, W10, W12, W13, W16
([request-log.tsv](fixtures/m0a/request-log.tsv) is chronological).

| # | Request | What it was expected to leave | Result |
|---|---|---|---|
| W1 | `POST /content/v2/barcodes` `{count: 4}` | four reserved barcodes | 200; all four used on cards B |
| W2 | `POST /content/v2/cards/upload` — card A (`WOODEV-TEST-A`), six colour values where five are allowed | an error-list entry, or card A | 200 `error: false`; **no card**, an error-list entry |
| W3 | the same call, card A corrected | test card A | 200; card created |
| W4 | the same call, group B: `WOODEV-TEST-B-BLACK`, `WOODEV-TEST-B-WHITE`, two sizes each | two cards in one group | 200; both created, one `imtID` |
| W5 | `POST /content/v3/media/save` — card A, two 900×1200 placeholder pictures (`dummyimage.com`) | photos | 200 with a `taskId`; both photos appeared |
| W6 | `POST /content/v2/cards/update` — card A, description only | a changed description | 200; applied — **then undone by W7** |
| W7 | the same call — `vendorCode` → `WOODEV-TEST-A2` | a renamed card | 200; **renamed** |
| W8 | `POST /api/v2/upload/task` — prices and discounts of the three cards | prices | 200; 3 of 3 applied |
| W9 | the same call — B-WHITE 1000 → 100 | quarantine or the low price | 200, then **refused per item**; price unchanged, no quarantine |
| W15 | the same call — B-WHITE 1000 → 600 (added after W9) | quarantine | 200, then item moved to **price quarantine** |
| W10 | the same call — B-WHITE 950 | normal price, quarantine released | 200; applied, quarantine empty |
| W11 | `POST /api/v2/upload/task/size` | per-size prices | **not sent**: no card has `editableSizePrice: true` |
| W12 | `PUT /api/v3/stocks/{warehouseId}` — five test sizes, `amount: 0`, warehouse 1 | records with 0 | 204; read back as 0 |
| W13 | the same call — `chrtID` instead of `chrtId`, one size | nothing beyond W12 | 204; proves nothing by itself (the record already held 0) |
| W16 | the same call on warehouse 2, which had no record (added after W13) | a record with 0, if the name is accepted | 204; **a record with 0 appeared** |
| W14 | `POST /api/v3/supplies` — «WOODEV TEST — empty, do not use» | one empty supply | 201; still empty and open |

**Left in the cabinet for good:** three test cards (stock 0; A holds two photos; prices 999 −33 %, 1001 −15 %,
950), five stock records of 0 in warehouse 1 and one in warehouse 2, four price uploads in the history, one empty
supply, one error-list entry (it did not disappear — item 4). **Later cards reuse these cards**: `WOODEV-TEST-A2`,
`WOODEV-TEST-B-BLACK`, `WOODEV-TEST-B-WHITE`. Find them by listing cards, or by `textSearch` with the full article —
a search for the prefix `WOODEV-TEST` returned nothing
(`textsearch-article-prefix`).

### Refused writes (item 11a)

**No write was refused because of the cabinet's standing**: all 15 write requests were answered 2xx, and card
creation, card update, media, prices, zero stock and supply creation all took effect. So the live acceptance steps
of WP4, WP5 (push of 0) and WP6 (except size prices) are possible on this cabinet.

| What was refused | How | Text | Retried |
|---|---|---|---|
| W2 (invalid on purpose) | 200, then an error-list entry | «Поле Цвет имеет слишком много значений. Разрешено не более 5» | the corrected card was sent (W3) |
| W9 (price more than twice lower) | 200; upload status 6, item status 3 | `Changes weren't saved: New prices are more than twice lower than the current ones. Please lower them gradually` | no |
| W15 (quarantine, the aim of the write) | 200; upload status 6, item status 3 | `New price is several times lower than the current price. Item has been moved to Price Quarantine` | no (W10 released it) |
| A **read**: shipping points of the empty supply | 409 `SupplyHasZeroOrders` | `Failed to process the supply. Make sure it has at least one assembly task assigned to it.` | no |

### 1. Token and connectivity — proven

Observed; fixtures withheld (they identify the seller).

| Claim | Status | Answer |
|---|---|---|
| The stored token is a personal one | proven | payload `acc: 3`, `for: "self"`, `t: false` |
| It can write Content, Prices and Marketplace | proven | bitmask `s` has bits 1, 3, 4 only; bit 30 (read-only) not set; writes took effect |
| Lifetime 180 days | refuted as an exact figure | `exp` was 182 days ahead — read `exp`, never compute it |
| `GET /ping` per host | proven | 200 `{TS, Status: "OK"}` on common, content, discounts-prices, marketplace; `X-Ratelimit-Limit: 99` |
| `sid` of the token is the seller's id | proven | `GET /api/v1/seller-info` returns `name, sid, tin, tradeMark, country`; its `sid` equals the token's |

The payload also carries `c, ent, id, iid, oid, uid`.

### 2. Portal pages read in a browser

Orca's embedded browser stayed on the anti-bot page; the public pages were read in desktop Chrome through the
Claude in Chrome extension (the M0b note on portal access describes a second route, a fresh temporary profile).
Read: release notes (entries of
02.10–25.11.2026, as far as the page had loaded), the sandbox page, knowledge-base articles on webhooks, items,
orders, FBS, DBS, labelling, rate limits and error codes, and the help-centre article on price quarantine. All
**SOURCED** unless marked.

| Claim | Status | What the pages say |
|---|---|---|
| U2: webhooks do not exist | **refuted** | `webhooks-api.wildberries.ru` (article of 16.09.2026): `card_creation_error`, `card_changed`, `report_generation_complete`, `feedback_updated` |
| …and a plugin could use them | refuted | only a **Service or Base** token with a service secret; no order events. A personal-token plugin keeps polling |
| Methods being replaced (U2) | proven for the read window | old FBS shipping-points route announced off on 20.11 (the spec uses the new one); Statistics, FBW and Promotion sandboxes off from 05.11. Additions: DBS client `additionalPhones` (06.10), DBS sandbox `deliveryType` (08.10), moving an order between supplies with `isTransferable` (14.10), `gtin` on cards (02.10) |
| Sandbox limits | proven | Content, Prices, FBS, DBS, pickup and feedback hosts; 1 request/s; label identifiers optional |
| U13: FBS assembly deadline | **open** | not stated in the articles read; oversize FBS cargo has a per-order `sellerDate`. SECONDARY (web-search summary): 120 hours under the offer, clause 4.3.1 |
| DBS terms | proven | delivery 5 hours to 35 days (express: 4 hours); an unanswered return request for a defective item is approved after 5 days |
| Seller cancellation | proven | penalised, except for an order with `isZeroOrder: true` (FBS and DBS articles) |
| Marking | partly | identifiers are attached in `confirm`; a supply or DBS order lacking a **required** one cannot move on; with an **optional** one missing an FBS supply can be handed over, but a returned item is refused at the warehouse and sent back. The API text says marked goods need both — S0 should word WP9 to cover this |
| Marking: entering codes in the cabinet (D4) | open here | see M0b |
| 4xx cost | refines §12.2 | a 409 counts as **5 or 10** requests depending on the method group |
| Waiting after create or edit | proven (text) | stock and price cannot be set for up to 30 minutes after a create, or after an edit of the card |
| Price quarantine | proven (text) | default threshold: a drop of 33.3 % (1.5 times), configurable in the cabinet; goods with size prices and drops caused by promotions are exempt |
| Card documents | new | from 01.10.2026 moderation **may block** a card that fails validation; checks are rolled out in stages |
| `cards/update` with a retired characteristic | new | may be dropped silently: 200 and no error-list entry |

### 3. The existing catalogue — proven

Read with `POST /content/v2/get/cards/list` (existing cards only, aggregates). Observed; fixtures withheld: none of
their data is kept here.

| Claim | Observation |
|---|---|
| How many cards are single-size | **none**: every existing card had three or more sizes |
| `vendorCode` | unique; short; Cyrillic and other non-ASCII articles occur; no `/`, `\|` or outer spaces |
| `skus` | one per size, unique, all 13 digits, prefix `20`, valid EAN-13 check digit (a GS1 restricted-circulation range, so presumably not registered GTINs — interpretation) |
| `imtID` | both single cards and groups of four occur |
| `needKiz` | present on every card; `true` occurs |
| `editableSizePrice` (from `list/goods/filter`) | `false` on every card |
| Other | `dimensions.isValid` was `false` on every existing card; every card has a `documents` object; the cursor ends when `cursor.total < limit` |

### 4. Test cards — proven (U3, creation)

| Claim | Status | Observation (fixtures `w02-*` … `cards-list-after-create`) |
|---|---|---|
| Time to `nmID` / `chrtID` | proven | `createdAt` 1–7 s after the request; first seen in the cards list 25–64 s after it (polls 15–20 s apart: `w03-poll-*`, `w04-poll-*`). Two cards of one request were first seen 39 s apart |
| Up to 30 minutes | not observed | about a minute here |
| Error list | proven | the entry carries `updatedAt` of the request and was there at the first poll (10 s): `{batchUUID, subjects, brands, vendorCodes, errors: {article: [text]}, updatedAt}` |
| A corrected request removes the entry | **refuted** (within 37 min) | the entry stayed after W3 created the card, and after two updates |
| Sizeless card | proven | omit `sizes`: one size `techSize: "0"`, `wbSize: ""`, a generated barcode |
| Barcodes | proven | `POST /content/v2/barcodes` and auto-generation give 13 digits, prefix `206`, valid EAN-13 check digit; sent `skus` are kept |
| Required characteristics | proven for two subjects | subjects 297 and 256 require none; colour allows 5 values (`charcs-297`, `charcs-256`) |
| Documents | new | within 6–11 s all three cards got `overallVerdict {status: 2, reason: "documents_missing"}`, `issues: [{code: "document_missing", documentTypes: [1]}]`. Cards, prices and stock were still accepted |
| `needKiz` | proven | set by Wildberries from the subject: `true` for 256, `false` for 297; `kizMarked: false` |
| A card in a size-price subject | open | the catalogue has none (item 3), so none was created |

### 5. Card update — own field only (D2)

| Claim | Status | Observation |
|---|---|---|
| Read-merge-write keeps every other field | proven for what the card held | after W6 only `description` and `updatedAt` differed: brand, title, dimensions, characteristics, sizes, documents and **photos** (not part of the request) survived (`w06-cards-list-before`, `w06-cards-list-new-version`) |
| Tags, video, attached documents, hand-set cabinet fields survive | open | the test card had none |
| `vendorCode` can be changed (`RENAME_SKU`) | **refuted as fixed**: it changes | W7: new article, same `nmID`, `imtID`, `chrtID`, barcodes |
| Time to visibility | proven | 15 s (W6), 61 s (W7) |
| The cards list shows the latest version after a write | **refuted** | see below |

⚠ **A read after a write can return an older version, even after a newer one was already seen.**

| Time | Event | `updatedAt` returned | Description returned |
|---|---|---|---|
| 01:19:14 | W6 changes the description | — | — |
| 01:19:29 | read | 01:19:17 | new |
| 01:19:33 | read, four seconds later | 01:17:46 | **old** |
| 01:19:33 | W7 built from that read, changing only the article | — | sends the old one |
| 01:20:34 | read | 01:19:39 | old — **W6 is lost** |

Two reads in one second at 01:17:42 also returned two different `updatedAt` values (`cards-list-after-create`,
`cards-list-same-second-older`). The old version was still returned at 01:19:48, 01:20:04 and 01:20:19
(`w07-cards-list-still-old-*`). A plugin that reads, merges and
writes can silently undo its own (or the merchant's) recent edit. For WP4: serialise writes per `nmID`; remember
what was last sent and its time; refuse to build an update from a read whose `updatedAt` is older than that write;
one read that shows the change does not prove the next one will. Fixtures `w06-cards-list-new-version`,
`w07-cards-list-stale-before-rename`, `w07-card-update-rename`. Gotcha:
[wb-cards-list-serves-an-older-version-after-a-write](../../gotchas/wb-cards-list-serves-an-older-version-after-a-write.md).
Card A's `updatedAt` also carries times at which we wrote nothing: 01:17:09 (`cards-list-after-create`) and
01:32:41 (first seen in the next read of the card, at 01:51:02: `drift-cards-list-015102`).

### 6. Media — proven

`media/save` answered 200 with `data.taskId` (the OpenAPI example shows an empty `data`); the card showed no photo
62 s later (`w05-cards-list-no-photo-011844`) and both at 77 s (`w05-cards-list-with-photos`), converted to `.webp`, each in six sizes under `photos[]`. Order of the two pictures was
not compared by content. The refusal of a whole set for one bad file was not tried (open).

### 7. Prices — proven (U4, in part)

| Claim | Status | Observation |
|---|---|---|
| New cards reach the prices service | proven | listed with price 0 at the first poll, about 5 min after creation |
| Time to «done» | proven | every upload final by the first poll, 5–6 s after it was sent (`w08-`, `w09-`, `w15-`, `w10-price-history`); `activationDate` equal to `uploadDate`. Sent 31 min or more after the last card edit, so an earlier send is untested |
| Rounding of `discountedPrice` | proven | **not rounded to roubles**: 999 −33 % → `669.33`, 1001 −15 % → `850.85`; existing goods also carry kopecks. `price` is an integer (`w08-goods-prices-after`; existing goods: observed, fixture withheld) |
| A sharp drop goes to quarantine | **refined** | 1000 → 600: quarantine. 1000 → 100: **not** quarantine but an item error «more than twice lower». WF1 must handle both |
| Exact boundaries | open | one value on each side: 1.67 times (quarantine), 10 times (refused) |
| Quarantine shape | proven | `quarantineGoods[{nmID, sizeID: null, newPrice, oldPrice, newDiscount, oldDiscount, priceDiff}]`; **`data: null` when empty**; the old price stays in force |
| Release by a new price | proven | item status 2 (`w10-price-items`), list empty (`w10-quarantine-list-empty`), price 950 in force (`w10-goods-prices-after`) |
| A refused or quarantined item | proven | HTTP 200 on upload; upload `status: 6` (`w09-price-history`, `w15-price-history`), item `status: 3` with `errorText` (success: 3 and 2) |
| Size prices where allowed | open | no card with `editableSizePrice: true` |

### 8. Zero stock — proven, one claim open

| Claim | Status | Observation |
|---|---|---|
| `PUT` of `amount: 0` and read-back | proven | 204; read returns `{sku, chrtId, amount: 0}` (`sku` is marked «Not used» in the OpenAPI text, and is filled) |
| A size never set | proven | not listed at all: `{"stocks": []}` — absent, not 0 |
| Field names are matched exactly | refuted for letter case | `chrtID` was accepted and created the record (W16) |
| An unknown field name answers 204 and does nothing | **open** | proving it needs a non-zero stock, which is forbidden here. The documented warning stands; keep the read-back and the payload contract test |
| Wait after creation | partly | accepted 36 min after creation; earlier untested |

No request ever carried an amount other than 0, and every read returned 0 or nothing.

### 9. Warehouses and offices — proven

The cabinet's seller warehouses are all `deliveryType: 1` (FBS), `cargoType: 1`, not processing and not being
deleted; each row also carries `storeId`. `GET /api/v3/offices` returned only `deliveryType: 1` offices, of cargo
types 1 and 3 (observed; fixture withheld: the answer marks the cabinet's own offices). No DBS warehouse exists, so
DBS values are open on the live cabinet.

### 10. The empty supply — proven, lifetime open

| Claim | Status | Observation |
|---|---|---|
| A supply can be created | proven | 201 `{id}`; `cargoType: 0`, `done: false`, all shipping fields `null`; listed by `GET /api/v3/supplies` (`w14-supplies-after`) |
| Its shipping points can be read while empty | **refuted** | 409 `SupplyHasZeroOrders` (M0b: the sandbox has no such route at all, so the shape of a successful answer stays unmeasured) |
| U5: an empty supply expires | **open** | unchanged 32 minutes after creation (`final-supply`); the cabinet had no earlier supply to judge by (`w14-supplies-before`). Look again in a later card |

### 11. Rate-limit headers and 4xx accounting

| Claim | Status | Observation |
|---|---|---|
| Headers on ordinary calls | proven | `X-Ratelimit-Limit` and `X-Ratelimit-Remaining` on every answer, the 409 included; `Limit` is the burst. No `-Retry` or `-Reset` seen (documented for 429) |
| Bursts | proven | ping 99, seller-info 10, content 5, prices 5, marketplace 20 |
| What a Marketplace 4xx costs | **open** (ambiguous) | one sample: the 409 reported **9** of 20. Against the last call of the FBS supplies group (`order-ids`, 10 s earlier: 17) that reads as 17 → 9, a cost of 8; against the warehouses call just before it (19) as 19 → 9, a cost of 10. Which bucket it drew from is unknown, and either bucket may have refilled in between. It is a surcharge of some size; the documented 5 or 10 is not confirmed by this sample (`w14-control-warehouses-*`, `w14-shipping-points-409`) |
| Buckets are per method group | **open** | the warehouses call right after the 409 reported 18, which fits both readings |
| 4xx cost for Content and Prices | open | none occurred, none was provoked |
| 429 | open | not provoked |

### Registers touched (spec §13.1)

| Id | Status after M0a |
|---|---|
| U1 token | proven |
| U2 webhooks, replaced methods | webhooks exist but not for a personal token; replacements listed (item 2); sandbox fidelity — M0b |
| U3 creation, error list, barcodes, rename | proven; the error entry is not removed by a corrected request |
| U4 prices | timing, rounding and both refusals proven; threshold from the help centre; size-price categories open |
| U5 empty supply lifetime | open |
| U10 refused writes | proven: none refused for the cabinet's standing |
| U13 assembly deadlines | open (secondary figure only) |

### For S0 (spec amendment)

Folded into the design on 26.11.2026 (#1997):
[spec §3.4](../../specs/2026-11-26-wildberries-seller-plugin-design.md#34-what-phase-0-changed-in-this-design) lists
each change and §13.1 the status of every unverified item. The list below is the record of what was asked.

1. WP4: protect read-merge-write against stale reads (item 5) — the highest risk found.
2. WF1 / WP6: «more than twice lower» is an item error, not quarantine; `data: null` is an empty quarantine.
3. `vendorCode` is changeable: revisit `RENAME_SKU` and I2 (the unit key is frozen at linking).
4. WF2: an error-list entry outlives the corrected request — match it by `updatedAt` after the submit, not by article alone.
5. WP2 / WP4: `documents_missing` arrives within seconds; show the verdict at the product.
6. WF5: the 4xx surcharge differs by method group (5 or 10).

## Related

- [Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md) — §3 the two phase-0 cards, §13 the registers
- [API map](api-map.md)
- [Owner decisions](decisions.md)
