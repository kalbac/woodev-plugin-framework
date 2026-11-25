
# Wildberries Seller API map (as of 26.11.2026)

## Evidence labels

- **SOURCED** — read in the official OpenAPI files that `dev.wildberries.ru` serves at
  `https://dev.wildberries.ru/api/swagger/yaml/en/<file>.yaml`, downloaded 26.11.2026. A bare section header such as
  "`02-items`" names the file; operations are written `METHOD /path`. Every row of a table is SOURCED unless it says
  otherwise.
- **SECONDARY** — a third-party page found through web search (summaries only), 26.11.2026.
- **UNVERIFIED** — not established; the reason is given.

Nothing here was measured against a live or sandbox cabinet: no request was sent to any WB API host.

## Spec files read

All 13 category files were downloaded (English and Russian). They are **not committed** (4.7 MB, third-party);
the first 16 hex characters of each English file's SHA-256 let a later reader see whether WB changed it.

| File | Title | Operations | SHA-256 (prefix) |
|---|---|---|---|
| `01-general` | General (auth, limits, seller info, users) | 11 | `ce780f658bba2438` |
| `02-items` | Cards, categories, media, prices, warehouses, stocks | 55 | `9fc743e41898af67` |
| `03-orders-fbs` | FBS assembly orders, supplies, passes | 43 | `2fa63335999d966e` |
| `04-orders-dbw` | DBW orders | 16 | `20bff4587d40d798` |
| `05-dbs` | DBS orders | 21 | `1ea469c7ebe90ad0` |
| `06-in-store-pickup` | In-store pickup (click and collect) | 18 | `1ac42ad6deb9ab25` |
| `07-orders-fbw` | FBW supplies | 14 | `18460456ae361d4d` |
| `08-promotion` | Advertising, promo calendar | 44 | `01ed38d9984f8d14` |
| `09-communications` | Questions, reviews, buyer chat, return claims | 25 | `f991cdc46d66be6b` |
| `10-rates` | Commissions and logistics tariffs | 5 | `3508ae490a687938` |
| `11-analytics` | Sales funnel, search, stock reports, order feed | 20 | `4cf2d7ce1eb56ad5` |
| `12-reports` | Statistics reports, returns report | 22 | `6a9a2967e7e14af9` |
| `13-finances` | Balance, financial reports, documents | 11 | `55446334c25e5231` |

The portal's HTML pages (release notes, sandbox guide, business-solutions catalogue, knowledge base) answered an
automated fetch with HTTP 498 and **were not read**.

## 1. Auth, tokens, scopes

| Fact | Where |
|---|---|
| One JWT in the `Authorization` header. No client id. Valid **180 days** from creation. Created in the seller cabinet → «API Integrations»; shown once. | `01-general`, tag Authorization |
| Four token types: **Personal** (own programs, "including … on-premise systems hosted on your own or third-party infrastructure"; "must not be shared with third parties or used in cloud services"), **Service** (only for a service listed in WB's business-solutions catalogue), **Base** (limited data and much lower limits), **Test** (sandbox only). | same |
| Integration with the seller portal without the WB API is prohibited (offer clause 9.9.6). | same |
| Personal and Base tokens are created per **category** (Content, Analytics, Prices and Discounts, Marketplace, Statistics, Promotion, Feedbacks, Buyers Chat, Supplies, Buyers Returns, Documents, Finance, Users) and as «Read and Write» or «Read Only». | same, «Token categories» |
| JWT payload: `sid` (seller UUID), `exp`, `acc`/`for` (token type), `s` bitmask — bit 1 Content, 3 Prices, 4 Marketplace, 5 Statistics, 10 Supplies, 11 Returns, 13 Finance, **30 = read-only**. | same, «About the token» |
| `GET /ping` on each host checks reachability, token validity and category (3 requests per 30 s). `GET /api/v1/seller-info` returns the seller. | `01-general` |
| Some methods are closed to Base tokens (`x-token-types`): auto-return settings, DBS stickers (personal / service / base with secret); FBW drafts, finance reports, seller-warehouse stock analytics (personal / service). Cards, prices, stocks and the order flows carry no restriction. | per operation |

**UNVERIFIED:** whether WB treats a paid WordPress plugin installed on the merchant's own hosting as an "own /
on-premise" system (Personal token) or expects the vendor to register as a catalogue service (Service token, or
"Base with secret"). The wording above fits Personal; the platform access agreement was not read. Owner question Q5.

## 2. Rate limits

| Fact | Where |
|---|---|
| Token bucket per **seller account** and method group. Headers `X-Ratelimit-Remaining`; on 429 `X-Ratelimit-Retry`, `-Limit`, `-Reset`. | `01-general`, «Rate Limits» |
| In the Marketplace category **one 4xx answer counts as 10 requests**. | same; repeated in `02-items`, `03-orders-fbs` |
| A service's limit is shared by all tokens of that service. | same |

Personal-token limits of the methods a plugin needs (Base in brackets where it differs materially):

| Method group | Limit | Burst |
|---|---|---|
| Content directories, categories, characteristics, media, barcodes | 100 / min (Base: often 2 / h) | 5 |
| `POST /content/v2/get/cards/list` (100 cards a page) | 80 / min | 5 |
| `POST /content/v2/cards/upload`, `…/upload/add`, `…/update` | 10 / min | 5 |
| `POST /content/v2/cards/error/list` | 8 / min | 5 |
| Prices and discounts (all) | 10 / 6 s (Base: 4 / h) | 5 |
| Stocks, seller warehouses | 300 / min (delete stocks 10 / min) | 20 (2) |
| FBS orders, supplies, stickers | 300 / min; cancel 100 / min; order metadata 1000 / min | 20 |
| `POST /api/marketplace/v3/dbs/orders/status/confirm` | 1 / s | 10 |
| `POST /api/analytics/v1/order-feed` | 1 / min (Base: 1 / 3 h) | 1 |
| `GET /api/v1/supplier/orders`, `…/sales` | 1 / min (Base: 1 / 3 h, 1 / 2 h) | 10 / 1 |
| Return claims | 20 / min (Base: 1 / h) | 10 |
| Financial reports, balance | 1 / min | 1 |
| `POST /api/v3/passes` | 1 / 10 min | — |

**Consequence:** a Base token cannot run a shop integration (hours between calls). The plugin must require a Personal
(or Service) token and say so on the settings screen.

## 3. Sandbox

SOURCED: separate hosts with test data for Content, Prices and Discounts, Marketplace and Feedbacks
(`*-sandbox.wildberries.ru`), reached only with a **Test** token; "special methods … for emulating user actions"
exist for items, FBS, DBS and pickup; Content methods are limited to 1 request/s; a card is created immediately;
price quarantine clears itself after 3 days. The FBS, DBS, pickup and item specs name the sandbox; DBW, FBW,
analytics, statistics and finance list no sandbox host.

**Marking exemption (SOURCED):** «Specifying label identifiers for the assembly order in the sandbox is optional»
(label-identifier overview in `03-orders-fbs`, `05-dbs`, `06-in-store-pickup`). Production delivers a supply only when
every required identifier is filled and has passed validation. A sandbox supply delivered without a code therefore
proves nothing about the production refusal, and a sandbox run cannot validate marking blocking.

**UNVERIFIED:** the sandbox guide (HTML, not read); whether a Test token needs a registered seller account; how close
sandbox behaviour is to production.

## 4. Product cards (`02-items`, Content category)

Model: an **`nmID`** card (one colour / variant) with a seller article `vendorCode`, holding **sizes** (`chrtID`),
each with one or more `skus` — WB's barcodes. Up to 30 `nmID` merge into one group `imtID` (same subcategory only).

**A size `sku` is not necessarily a GTIN.** `skus` is an array of free strings; the cards-list schema's own example is
`12345Ejf5` (`02-items`, `POST /content/v2/get/cards/list`), while `POST /content/v2/barcodes` shows a 13-digit one
(`5032781145187`). A card or variant also has a separate `gtin` field («Additional GTIN», Russian sellers): used when
the same GTIN already sits in another `skus`; one GTIN cannot be put in `skus` of two cards or sizes.
**UNVERIFIED:** whether the barcodes WB generates are registered GTINs.

WooCommerce's GTIN field cannot hold an arbitrary string: `set_global_unique_id()` removes every character except
digits, `-` and `X` and refuses a value another product has
([WooCommerce 11.2.1, `abstract-wc-product.php` lines 891–916](https://github.com/woocommerce/woocommerce/blob/11.2.1/plugins/woocommerce/includes/abstracts/abstract-wc-product.php#L891),
read 26.11.2026). `12345Ejf5` would be stored as `123455`.

| Operation | Facts |
|---|---|
| `GET /content/v2/object/parent/all`, `GET /content/v2/object/all` | Two levels: parent category → subcategory (`subjectID`); search, `limit` ≤ 1000. |
| `GET /content/v2/object/charcs/{subjectId}` | `charcID`, `name`, `required`, `unitName`, `maxCount` (0 = unlimited), `charcType` 1 = array of strings, 4 = number, 0 = unused; `isVariable` (variants differ by it); `existNamedField` (sent as a named parameter instead). |
| `GET /content/v2/directory/{colors,kinds,countries,seasons,vat,tnved}`; OKPD2, brands | Closed directories exist only for these; other characteristics are values of the declared type. |
| `POST /content/v2/cards/upload` | Create. ≤ 100 groups × 30 variants, ≤ 10 MB. Body: `subjectID`, `variants[]`: `vendorCode` (required, ≤ 72), `title` (≤ 60), `description` (limit 1000–5000 by category), `brand`, `dimensions` (**cm** integers, `weightBrutto` **kg**), `characteristics[{id,value}]`, `sizes[{techSize,wbSize,price,skus}]`, `documents`, `kizMarked`, `gtin`. Empty `sizes` / `skus` are generated. |
| ↳ result | `{data, error, errorText}` — **no task id**. Creation is asynchronous, "up to 30 minutes", and stock and price cannot be set before it finishes. Failures appear in `cards/error/list`. |
| `POST /content/v2/cards/upload/add` | Add variants to an existing `imtID`. |
| `POST /content/v2/cards/error/list` | Batches of failed cards keyed by `vendorCode`, cursor `updatedAt`+`batchUUID`; an entry goes away when a corrected request is repeated. |
| `POST /content/v2/cards/update` | **Overwrites the whole card** (send everything, `documents` too). ≤ 3000 `nmID`. Cannot change or delete existing barcodes (only add), photos, video, labels, or prices (a new size takes `price`). New sizes are added here without `chrtID`. |
| `POST /content/v2/get/cards/list` | Cursor `updatedAt`+`nmID` (incremental sync is possible); filters by text (article, `nmID`, barcode), subject, brand, label, photo. Returns characteristics, sizes with `chrtID` and barcodes, photos, dimensions with `isValid`, `needKiz`, document verdicts. Trash is separate. |
| `POST /content/v2/cards/moveNm` | Merge / split groups. |
| `POST …/delete/trash`, `…/recover`, `…/get/cards/trash` | Trash, auto-deleted after 30 days; a trashed card **keeps selling while it has stock**. |
| `GET /content/v2/cards/limits` | Free and paid creation quota. |
| `POST /content/v2/barcodes` | Generate ≤ 5000 barcodes. |
| Duplicate article | Create answers «Seller item number used in another card» — `vendorCode` is unique per cabinet. |
| Documents (certificates) | Validated asynchronously, **up to 3 days**; result per document and `overallVerdict`. |

**UNVERIFIED:** whether `vendorCode` may be changed by `cards/update` (the field is required there and is not on the
"cannot change" list; `Capability::RENAME_SKU` assumes it is fixed); required characteristics and documents for real
subcategories.

### Media

| Operation | Facts |
|---|---|
| `POST /content/v3/media/save` | By public direct URL (must end with the file name); **replaces all** media of one `nmID`; ≤ 30 images, ≥ 700×900, ≤ 32 MB, quality ≥ 65 %, JPG/PNG/BMP/GIF/WebP; one video ≤ 50 MB MOV/MP4. If any file fails the requirements **nothing is uploaded, even on 200**. |
| `POST /content/v3/media/file` | One file upload. |

Media needs an existing `nmID`, so it is a step **after** creation.

## 5. Prices and discounts (Prices and Discounts category)

| Operation | Facts |
|---|---|
| `POST /api/v2/upload/task` | ≤ 1000 of `{nmID, price (integer), discount (%)}`. Returns upload `id`, `alreadyExists`. **Asynchronous.** |
| `POST /api/v2/upload/task/size` | `{nmID, sizeID (= chrtID), price}`, only for categories with `editableSizePrice: true`. |
| `GET /api/v2/history/tasks`, `…/history/goods/task` | Upload status 3 done, 4 cancelled, 5 partly failed, 6 all failed; per item status 2 ok / 3 error with `errorText`. |
| `GET /api/v2/buffer/tasks`, `…/buffer/goods/task` | Unprocessed uploads (promo-calendar prices wait here, status 1). |
| `GET /api/v2/quarantine/goods` | A new discounted price far below the current one is **quarantined** and not applied; released by a new price or in the cabinet. Not for size pricing. |
| `GET/POST /api/v2/list/goods/filter`, `GET /api/v2/list/goods/size/nm` | Current `price`, `discountedPrice`, `discount`, `clubDiscount`, `editableSizePrice`, `currencyIsoCode4217`. |

## 6. Warehouses and stocks (Marketplace category)

| Operation | Facts |
|---|---|
| `GET /api/v3/offices` | WB offices a seller warehouse is bound to. |
| `GET/POST/PUT/DELETE /api/v3/warehouses` | Seller warehouse: `id`, `officeId`, `cargoType` 1 small / 2 oversize / 3 CD+, `deliveryType` 1 FBS, 2 DBS, 3 DBW, 5 pickup, 6 express DBS; `isDeleting`, `isProcessing` (stock calls unavailable meanwhile). **One warehouse = one delivery scheme.** |
| `PUT /api/v3/stocks/{warehouseId}` | ≤ 1000 `{chrtId, amount ≤ 100000}`, absolute; 204. The spec warns that parameter names "are not validated": a wrong name answers 204 and updates nothing. |
| `POST /api/v3/stocks/{warehouseId}` | Read by `chrtIds` → `amount` only (no reserved quantity). |
| `DELETE /api/v3/stocks/{warehouseId}` | Remove stock records. |

**UNVERIFIED:** whether WB lowers the seller-warehouse amount by itself when an order arrives (not stated in the
specs; one web search found nothing). It decides the stock formula and must be measured before the stock card.

## 7. Orders

### Common to the order schemes

- An **assembly order is one unit** of one size (`chrtId`): no quantity field. A buyer cart shares `orderUid`
  (DBS/DBW also `groupId` — one warehouse within a cart).
- Fields: `id`, `rid` (= `srid` elsewhere), `nmId`, `chrtId`, `article`, `skus`, `warehouseId`, `officeId`,
  `cargoType`, prices ×100 with numeric currency code (`salePrice`, `price`, `finalPrice`, converted ones), `comment`,
  `createdAt`, `requiredMeta` / `optionalMeta`, `isZeroOrder`.
- Two status axes: `supplierStatus` (moved by the seller) and `wbStatus` (moved by WB), read separately by ids
  (≤ 1000). No status in the order list.
- Label identifiers (Chestny ZNAK code, UIN, IMEI, GTIN, expiration date, customs declaration) are written per order.
  For goods under mandatory marking, every needed identifier must be filled whether it arrives in `requiredMeta` **or**
  `optionalMeta` (`03-orders-fbs`, `GET /api/v3/orders/new`).
- The buyer may cancel within the first hour while the order is not in `confirm` (`declined_by_client`).

### FBS (`03-orders-fbs`)

| Step | Operation and facts |
|---|---|
| Poll | `GET /api/v3/orders/new`; `GET /api/v3/orders` (≤ 3 months, ≤ 30 days a request, `next` cursor); archive endpoint for older. |
| Status | `POST /api/v3/orders/status`: `new` → `confirm` → `complete`, or `cancel`; `wbStatus` waiting, sorted, sold, canceled, canceled_by_client, declined_by_client, defect, ready_for_pickup, postponed_delivery and cross-border values; `isCancellable`, `isTransferable`. |
| Cancel | `PATCH /api/v3/orders/{orderId}/cancel` — one order, **no reason**. |
| Buyer | `POST /api/v3/orders/client` only for cross-border orders from Turkey; **no buyer data for a Russian seller**. |
| Supply | `POST /api/v3/supplies` (`WB-GI-…`); `PATCH /api/marketplace/v3/supplies/{id}/orders` (≤ 100) **moves them to `confirm`**; one supply holds one `cargoType` / B2B / cross-border combination. |
| Shipping | `GET …/supplies/{id}/shipping-points` (city, point type, date); `PATCH …/fbs/supplies/shipping-method` (date, point, self or transport company) — Russian sellers only, **required before delivery** (409 otherwise). |
| Boxes | `…/supplies/{id}/trbx` — the section says to add them only to supplies shipped to a pickup point; the delivery operation also documents a refusal for a "fulfilment" supply without a box (409 `FulfillmentSupplyBoxesRequired`). Stickers per box. |
| Deliver | `PATCH /api/v3/supplies/{id}/deliver` — closes the supply, orders → `complete`; needs all required identifiers filled and validated (409 `MetaValidationFail` with per-order details; also `SupplyShippingRequired`, `SupplyHasZeroOrders`). Required UIN also needs a delivery contract specification uploaded beforehand. Scanning at acceptance closes the supply too. |
| Documents | `POST /api/v3/orders/stickers` — ≤ 100, **only in `confirm` / `complete`**, SVG / ZPLV / ZPLH / PNG, 58×40 or 40×30, base64, one per order. **A required customs declaration number must be entered first** (409 `CustomsDeclarationIsRequired`). Supply QR (`…/barcode`) only after delivery. Cross-border stickers are PDF and may be delayed. |
| Re-shipment | `GET /api/v3/supplies/orders/reshipment`. |
| Passes | `…/passes` — car passes, valid 48 h. |
| Auto-returns | `…/fbs/settings/autoreturns` (account and item level). |

### DBS (`05-dbs`) — the seller delivers

`new` → `confirm` → `deliver` → `receive` / `reject`; `cancel`; WB sets `cancel_missed_call`. Batch status calls
(≤ 1000) with per-item errors. Buyer name and a replacement phone with extension: `POST /api/v3/dbs/orders/client`.
Chosen date and time: `…/delivery-date`. `address` on the order (a pickup point address for delivery to a point);
`deliveryType` dbs / edbs / dbsPickupPoint. `receive` may need the buyer's confirmation code. The documented work
order warns that order data not saved before the transfer to assembly is unavailable until the order is sold or
cancelled.

### DBW (`04-orders-dbw`) — WB courier collects from the seller

`new` → `confirm` → courier data (`…/dbw/orders/courier`) → stickers → `deliver`; the courier sets `receive` /
`reject`. Buyer delivery address on the order. Warehouse contacts are required for the courier.

### In-store pickup (`06-in-store-pickup`)

`new` → `confirm` (then buyer name and phone) → `prepare` (ready) → identity check → `receive` / `reject`.

### FBW (`07-orders-fbw`, plus reports)

Stock lives in WB warehouses; there are **no assembly orders**. API: warehouses, transit tariffs, supplies list /
details / items / discrepancies, and supply **drafts** (Personal or Service token; items by barcode). «Acceptance
options» is marked temporarily disabled. No operation that books a delivery slot was found. FBW sales are visible
only through the order feed and reports.

## 8. Returns

| Operation | Facts |
|---|---|
| `GET /api/v1/claims`, `PATCH /api/v1/claim` | Buyer return applications of the last 14 days (plus archive), photos / video, offered `actions`; approve or reject with a comment. Buyers Returns category. |
| `GET /api/analytics/v1/item-returns` | Goods going back to the seller: office, ready and expiry dates, reason, `orderId`, `srid`. |
| `POST /api/v1/feedbacks/order/return` | Start a return from a review. |
| FBS auto-return settings | See above. |

## 9. Finance and reports

| Operation | Facts |
|---|---|
| `GET /api/v1/account/balance`; `POST /api/finance/v1/sales-reports/{list,detailed,detailed/{reportId}}`; acquiring reports; documents | Settlement data. Reports need Personal / Service. |
| `GET /api/v1/supplier/orders`, `…/sales` | One row per unit; updated every 30 minutes; kept 90 days; "preliminary". |
| `POST /api/analytics/v1/order-feed` | Orders and sales "in real time", ≤ 31 days, `created / buyout / cancel / return / returnDefective`, `isMp` (seller or WB warehouse). |
| Analytics | Sales funnel, search texts, stock reports (WB and seller warehouses), paid storage, acceptance, penalties. |
| `10-rates` | Commission by category; box, pallet, return and acceptance tariffs. |

## 10. Promotion and communications

Advertising campaigns, the promo calendar, questions, reviews and buyer chat have full API sections (`08-promotion`,
`09-communications`). Out of scope for a v1 of a stock / price / order integration.

## 11. Webhooks or polling

**SOURCED (negative):** none of the 26 downloaded files contains "webhook", "вебхук" or "callback", the token
category list has no such category, and the host list of `GET /ping` has none. Polling is the only path.
**SECONDARY, contradicting:** a web-search summary of 26.11.2026 reports one third-party overview that lists a
webhook subscription service, without details; the summary did not say which of its results claims it.
**UNVERIFIED** until the release notes are read in a browser.

## 12. Change watch

The release-notes page was not readable. **SECONDARY** items from search summaries, to be checked in a browser
before the design spec: stock and statistics methods being replaced (`/api/v1/supplier/stocks` switched off in June
2026; an order feed meant to replace `supplier/orders` and `supplier/sales`, date not announced) and limits split by
token type during 2026
([release notes](https://dev.wildberries.ru/en/release-notes),
[community change log](https://salacoste.github.io/daytona-wildberries-typescript-sdk/guides/wb-api-changes-2026-09.html)).

## Related

- [Research index](README.md)
- [Gap map against the marketplace module](gap-map.md)
- [Ozon and Wildberries differences](ozon-vs-wb.md)
