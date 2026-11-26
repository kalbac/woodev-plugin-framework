# Wildberries Seller 1.0 Plugin Design

**Date:** 2026-11-26  
**Status:** draft — written before the phase-0 measurement (§3); §7 (DBS) is conditional on its two gate checks.  
**Target:** the first release, **1.0.0**, of a NEW plugin for WooCommerce shops that sell on Wildberries, built on the
framework module `woodev/marketplace/` the way Ozon Seller 2.0.0 is. Card
[#1670](https://github.com/kalbac/woodev-plugin-framework/issues/1670).  
**Evidence:** the fact base is [the research folder](../research/2026-11-26-wb-seller/README.md): **D** =
[decisions.md](../research/2026-11-26-wb-seller/decisions.md), **G n** = row n of
[the gap map](../research/2026-11-26-wb-seller/gap-map.md) (framework read at `59a0ecf9`), **API** =
[the API map](../research/2026-11-26-wb-seller/api-map.md). For this spec four of Wildberries' OpenAPI files were
downloaded again on 26.11.2026 (`01-general`, `02-items`, `03-orders-fbs`, `05-dbs`; their SHA-256 prefixes equal the ones
in the API map) and the framework was read at `157c4bd9` (2.1.20) through Serena on this worktree. Labels: **SOURCED**
(Wildberries' OpenAPI text), **READ** (framework code read for this spec), **G n** (read by the research, not re-read),
**UNVERIFIED** (with the card that settles it), **interpretation** (a gap this spec closed; listed again in §13.2).
Nothing in this spec was measured: no request was sent to any Wildberries host.

## 0. How to read this spec

§1 lists the binding decisions; nothing below reopens them. §2 is the product boundary. §3 is the phase-0 measurement
card, which runs **before** any implementation card. §4 maps every capability to its seam. §5 is the unit model (a size
inside a colour card), §6 the FBS flow with supplies and marking, §7 the DBS flow, §8 the framework extensions, §9 the
plugin parts, §10 the admin pages, §11 the data model, §12 errors, limits and the test plan, §13 the registers
(unverified facts, interpretations, **tensions between decisions that the coordinator must hear about**), §14 the
implementation card list.

**Three facts the reader must know.**

1. **A Wildberries order is one unit of one size.** There is no quantity; a buyer cart is several assembly orders that
   share `orderUid` (API §7). Everything the module does per «posting» is done here per unit, and one WooCommerce order
   holds several units (D10).
2. **Nothing leaves an FBS warehouse without a supply.** Stickers exist only for an order that is in a supply, a
   «Честный знак» code can be written only then, and the supply cannot be handed over while a required code is missing
   (§6).
3. **A request Wildberries refuses costs ten.** In the Marketplace category one 4xx answer is counted as ten requests
   (API §2). The plugin validates locally and reads state before it writes; it never probes with a call it expects to
   fail (§12.2).

## 1. Binding decisions

Source: [decisions.md](../research/2026-11-26-wb-seller/decisions.md) (owner, 26.11.2026). Where any research file
disagrees, that file wins.

| Id | Decision (short) | Where it lands |
|---|---|---|
| — | New plugin, first release **1.0.0** | §2 |
| D1 | FBS **and DBS** in 1.0; a DBS order is an ordinary WooCommerce order with a shipping address that CDEK / Yandex can ship; two phase-0 gate checks (relay phone, confirmation code); if either fails DBS moves to the next release. No DBW, in-store pickup, FBW | §3.3, §7 |
| D2 | Link, import Wildberries → WooCommerce, create cards from products; an edit changes only the plugin's own fields (read first); no delete, no trash | §5, §9.3 |
| D3 | Page «Поставки»: create, add orders, stickers, pickup point and date, cargo places, hand over, QR. No passes, no auto-returns | §6, §8 WF8 |
| D4 | «Честный знак» code field in the order (typed or scanned) and a «what is missing» list in the supply; other code kinds named, not entered; phase 0 checks the cabinet route | §3.2, §6.4 |
| D5 | The seller's personal token; no question to Wildberries support | §9.1 |
| D6 | The live cabinet is the test ground, writes included; never delete anything; stocks stay 0 except short checks on the owner's word; the tokens live in the plugin folder's `.env` (§3 rule 3) and are never printed | §3, §12.4 |
| D7 | Per-size prices where Wildberries allows; otherwise, when the sizes' shop prices differ — do not send, show the reason at the product | §8 WF4, §9.5 |
| D8 | Default warehouse per account, own warehouse per product, one product → one warehouse; a warehouse serves one scheme | §9.4 |
| D9 | Menu: Товары, Заказы (FBS and DBS, filter by scheme), Поставки, Сопоставление, Инструменты, Диагностика, Настройки; shared «Аналитика» as it is | §10 |
| D10 | One WooCommerce order per buyer cart per warehouse; a cancelled unit is its own line | §6.1, §8 WF6 |
| D11 | Returns not in 1.0 ([#1991](https://github.com/kalbac/woodev-plugin-framework/issues/1991)) | §2 |
| R1–R5 | Non-domain logic in `woodev/marketplace/`; PHP-driven React pages; the «Заказы доставки» table mechanisms; licence gate by default; merchant-visible conveniences ship now | every section |

Project rules that also bind: the admin trusts the merchant and no protection is invented without a real case (KISS);
merchant diagnostics only behind «Логирование»; technical knobs are constants and filters, not settings; merchant texts
never say «чекаут» or «фреймворк», and never name a framework version; nothing about the test cabinet or its owner is
written into this public repository or its tracker.

## 2. Product boundary and identity

| Item | Value | Note |
|---|---|---|
| Repository | `kalbac/woocommerce-wildberries-seller`, new, private; local folder `/Users/maksimmartirosov/Projects/woocommerce-wildberries-seller` (exists, holds the gitignored `.env` with the tokens) | the Ozon repository is `woocommerce-ozon-seller` |
| Version | `1.0.0` | D |
| Marketplace id | `wildberries` | 11 characters, within `Schema::MAX_ID_LENGTH` 16 (READ `data/class-schema.php:42`); table prefix `woodev_wildberries_` |
| Plugin id, text domain, namespace | `wildberries_seller`, `woocommerce-wildberries-seller`, `Woodev\Wildberries_Seller` | **interpretation**, Ozon Seller's shape |
| Menu | id `wildberries-seller`, title «Wildberries Seller», own top-level entry, own icon (full square, no rounded corners) | R2; the base class registers it |
| Base class | `Woodev\Framework\Marketplace\Marketplace_Plugin` | R1 |
| Supported | HPOS and legacy order storage; PHP, WordPress and WooCommerce floors as Ozon Seller 2.0.0 | |
| Installed-site data | none to preserve: no earlier Wildberries plugin of ours exists (UNVERIFIED against EDD, G §4) | every key this spec names becomes a contract at 1.0.0 ([ADR-005](../adr/005-platform-v2-clean-break-policy.md)) |
| Licence | the default gate: no licence in force → no new remote write; reading and status polling keep running as in the module today | R4 |

**In 1.0.** Connection by personal token · catalogue (subjects, characteristics, directories), mappings and profiles ·
cabinet cards: list, link, import into WooCommerce, create from products, update of own fields · stock per size per
seller warehouse · prices with discount, per size where allowed, with asynchronous results and quarantine shown · FBS
orders, supplies and stickers · «Честный знак» codes · DBS orders with the carrier bridge (behind the gate of §3.3) ·
diagnostics, tools, the shared analytics.

**Not in 1.0.** DBW, in-store pickup, FBW (D1) · returns and buyer claims (D11, #1991) · reviews, questions, buyer
chat, advertising, promo calendar, finance (D9) · warehouse passes, auto-return settings (D3) · label identifiers other
than «Честный знак»: UIN, IMEI, GTIN, expiration date, customs declaration — named as missing, not entered (D4) ·
deleting or trashing cards, deleting supplies (D2, D6) · cross-border orders and their PDF stickers · B2B specifics
beyond showing the flag · express DBS warehouses (`deliveryType` 6) — **interpretation**, see §7.1.

**What the merchant must do in the cabinet** (settings help and merchant docs say so): create the personal token with
the categories of §9.1; create the seller warehouses, one per scheme he sells by; for DBS, set up delivery zones and
terms in the cabinet.

## 3. Phase 0 — measure before design is final

One measurement card in two halves. It runs against the live cabinet under D6. It produces
`docs-internal/research/2026-11-26-wb-seller/measurement.md`, in which every UNVERIFIED item of this spec and of the
research is marked **proven**, **refuted** or **still open**, each with the request shape and the answer (no token, no
seller name, no buyer data in the file).

**Rules every brief of this card repeats, word for word:**

1. Never delete anything in the cabinet: no `DELETE` request of any kind, no «move to trash», no supply removal, no
   stock-record removal, no label-identifier removal.
2. Stocks on the live cabinet stay 0. A non-zero stock is set only for a short check, only after the owner's explicit
   word for that check, and is returned to 0 in the same session.
3. The tokens live in `/Users/maksimmartirosov/Projects/woocommerce-wildberries-seller/.env` (the plugin's
   folder; `.env` is gitignored there): `WB_LIVE_TOKEN` is the live personal token, `WB_TEST_TOKEN` the sandbox one
   (coordinator, 26.11.2026 — this replaces the earlier place, the owner's project `open-warehouse-saas`). A brief names
   the file and the variable only. The worker loads the value into the process environment without echoing it; the file
   is never printed, and no token, header dump or option dump goes into a transcript, a log file or the measurement
   file.
4. Every write is listed in the measurement file **before** it is sent, with what it leaves behind in the cabinet
   (a card, a supply, a price) — because nothing can be deleted afterwards.

### 3.1 M0a — everything that needs no real order (no stock, no buyer)

| # | Measure | Settles |
|---|---|---|
| 1 | Decode the token locally: type, categories, read-only bit, expiry. `GET /ping` per host, `seller-info` | §9.1; whether the stored token can write to Content, Prices and Marketplace at all |
| 2 | Read the release notes, the sandbox guide and the knowledge-base pages on DBS and marking in a browser (the portal refused the automated fetch) | webhooks (API §11), replaced methods (API §12), assembly deadlines |
| 2a | **Sandbox pass** with `WB_TEST_TOKEN` and the sandbox's buyer-emulation methods: FBS order → supply → stickers → hand over → QR; DBS order → client, date, paid delivery → `confirm` → `deliver` → `receive` with and without `code` → `reject`; the label-identifier calls | the request and answer shapes for §6 and §7 and fixtures for the plugin's tests. **Proves plumbing only:** marking is optional in the sandbox and its phone and code behaviour is not the production one — nothing of the DBS gate or of marking blocking is marked proven from it |
| 3 | `cards/list` over the dormant catalogue: real `vendorCode`, sizes, `skus`, `imtID` groups, `needKiz`, `editableSizePrice` | §5; how many cards are single-size |
| 4 | Create **at most three** test cards (one single-size, one two colours × two sizes, one in a subject with size prices if the token's catalogue has one), named so that a human sees they are tests; time to `nmID` / `chrtID`; `cards/error/list` after a deliberately invalid field **validated locally first where possible** | G 11, G 10; barcode formats generated |
| 5 | `cards/update` on a test card: change one own field, confirm by read-back that cabinet-only fields survive; try `vendorCode` change | D2; `RENAME_SKU` (API §4 UNVERIFIED) |
| 6 | `media/save` on a test card; read back | §9.3 media job |
| 7 | Price task on a test card: status history, time to «done», integer and percent rounding of `discountedPrice`, a sharp drop → quarantine, per-size price where allowed | G 18–19, D7, §9.5 |
| 8 | `PUT /stocks` with amount **0** and read-back; the «unvalidated parameter name» trap | G 15; rule 2 keeps this the only stock write |
| 9 | Warehouses and offices list; which `deliveryType` values exist in this cabinet | D8; whether a DBS warehouse exists for M0b |
| 10 | Create one supply, read its shipping points, leave it empty | D3; whether an empty supply expires by itself (it cannot be deleted — rule 1) |
| 11 | 429 and 4xx accounting: read `X-Ratelimit-*` on ordinary calls; **do not** provoke a 429 | §12.2 |

### 3.2 M0b — what needs a real order (needs the owner's word)

A dormant shop with zero stock receives no orders, so every order fact needs a short stock window (rule 2) **and a real
purchase**. Whether Wildberries allows a seller, or someone asked by him, to buy his own item for a test is UNVERIFIED
(Ozon forbids it; operator, 24.11.2026) — the owner decides how the test order is made before this half starts. See
§13.3 T1.

| # | Measure | Settles |
|---|---|---|
| 12 | One FBS order: fields, `requiredMeta` / `optionalMeta`, statuses on both axes, whether the seller-warehouse stock went down by itself | §6.1; the stock formula (API §6 UNVERIFIED) |
| 13 | Order → supply → sticker (formats) → shipping method → hand over → QR | §6.2–6.3 |
| 14 | **Marking (D4):** on an order of a marked item — write a «Честный знак» code from the shop side, read its validation state; try to hand the supply over with a code missing and record the refusal; then open the same order in the cabinet and record **whether the cabinet lets a human enter each code kind** (sgtin, UIN, IMEI, GTIN, expiration, customs declaration) | §6.4; the sentence for the plugin description |
| 15 | **DBS gate 1 — relay phone (D1):** one DBS order; record which of `replacementPhone`, `phone` + `phoneCode`, `additionalPhones` come back and when; create a real CDEK and a real Yandex shipment for it with that number as the recipient; record whether each carrier accepts the number, and whether its SMS and its courier's call reach the buyer's handset | §7.3 |
| 16 | **DBS gate 2 — confirmation code (D1):** hand the DBS parcel to the buyer through the carrier; record whether `receive` succeeds without `code`, what it answers when the code is absent or wrong, where the buyer sees the code, and whether the carrier's delivered status can stand in for it | §7.4 |
| 17 | The first-hour buyer cancellation on an unconfirmed order, if the test buyer agrees | §6.1 |

### 3.3 The DBS gate

After M0b the coordinator records one line in the measurement file: **DBS in 1.0: yes / no**.

- **Both checks pass** → §7 is implemented as written, amended by the measured facts.
- **Either check fails, or the owner decides that no real DBS order can be made** → **DBS moves to the next release**
  (the fallback stated in D1). Cards WF9, WF10, WD1 and WP10 leave the 1.0 list; nothing else changes, because WF6
  (order actions) is needed by FBS anyway and is designed so that DBS adds actions, not a mechanism.

The FBS half of 1.0 does not wait for the gate: M0a alone unblocks the catalogue, price and stock cards.

## 4. Capability → seam map

`fits` — the module provides it and the plugin implements contracts; `extend` — a framework card of §8; `plugin` —
Wildberries-only code. Rows follow the gap map, re-read for DBS being in scope.

| Capability | Framework seam | Verdict | Card |
|---|---|---|---|
| Menu, pages, records engine, settings placement | `Marketplace_Plugin::get_marketplace_pages()` (G 1) | fits | WP1 |
| Account, encrypted secret, health, expiry notices | `Credential_Fields`, `Health_Result::expires_at()`, `Key_Expiry` (G 2) | fits | WP1 |
| Account identity from a token alone | one required typed identifier today (G 3) | extend (S) | WF5 |
| Rate limits with a 4xx surcharge | `Bucket_Spec::cost_4xx` declared, never consumed (G 4) | extend (S) | WF5 |
| Catalogue schema, mappings, validation rules | `Catalog_Schema`, `Export_Validator` rule list (G 6) | fits | WP2 |
| A size inside a colour card as the sellable unit | one link = one card = one article (G 7) | extend | WF3 |
| Matching by barcodes, article + size | exact SKU = article only (G 8) | extend (M) | WF3 |
| Import of a colour × size group | one card = one variation (G 9) | extend (M) | WF3, WP3 |
| Export batches that keep sibling sizes together | 100 link ids per batch, one item per link (G 10) | extend | WF2 |
| Export result without a task number | an empty task id is a failure and a retry (G 11) | extend (blocker) | WF2 |
| Photos after creation | hook `woodev_{plugin id}_card_created` exists (G 12) | plugin | WP4 |
| Update that overwrites the whole card | — | plugin (high risk) | WP4 |
| Stock push and read per size | `Stock_Pusher` (G 15) | fits | WP5 |
| Warehouse per link, default per account | `Stock_Warehouse` (G 16) | fits | WP5 |
| Asynchronous price result | `Price_Pusher::settle()` has no caller (G 18; READ: the only `->settle(` calls under `woodev/marketplace/` are private methods of other classes) | extend (blocker, M) | WF1 |
| One price per colour card | intents and lines are per link (G 19) | extend (M) | WF4 |
| Order ingest by polling, grouped orders | `Posting_Handler`, `Order_Group`, `upsert_member` (G 21–22) | fits (verify in WP7) | WP7 |
| Two status axes | adapter maps to `Status_Map`; `status_raw` ≤ 32 characters (READ `data/class-schema.php:452`) | fits | WP7 |
| Cancel without reasons, per unit | reasons are mandatory (G 24) | extend | WF6 |
| Seller-side status actions (DBS: confirm, deliver, receive, reject) | only cancel and label exist (G 25) | extend (L) | WF6 |
| Per-unit input on the order (marking code) | none (G 28) | extend | WF6 |
| Supplies: the FBS hand-over | `Capability::CONFIRM_SHIPMENT` declared, nothing consumes it (G 26) | extend (L) | WF8 |
| Stickers: one PNG / SVG / ZPL per unit | one PDF per task (G 27) | extend (M) | WF7 |
| Buyer and address on a DBS order | `Wc_Order_Store::set_buyer()` copies name, phone, city, region only (READ `flows/class-wc-order-store.php:444-477`) | extend (M) | WF9 |
| A marketplace order that the merchant must be able to ship | `Order_Screen` makes every marketplace order read-only (READ `admin/order/class-order-screen.php`) | extend | WF9 |
| Handing an existing order to a carrier | the delivery editor answers 404 for an order that is not a row of «Заказы доставки» (READ `woodev/shipping-method/admin/orders/class-order-editor.php:500-512`) | **extend in the shipping module** — §13.3 T2 | WD1 |
| Carrier events → seller actions | actions `woodev_shipping_order_exported`, `woodev_shipping_delivery_status_changed` exist (READ) | extend (S) | WF10 |
| «Ozon» and «отправление» in merchant strings | A1, A2 of the gap map | extend (S) | WF11 |
| Licence gate, diagnostics, deletion, bulk runs, tools, analytics | G 33 | fits | — |

## 5. The unit model: a size inside a colour card

Wildberries: a card `nmID` is one colour with one seller article `vendorCode`; it holds sizes `chrtID`, each with one or
more barcodes `skus`; up to 30 `nmID` of one subject merge into a group `imtID` (API §4). Stock is per `chrtID`, price
per `nmID` (per `chrtID` only where `editableSizePrice` is true), an order names both.

### 5.1 One link = one size

**Decision of this spec (interpretation, closes G 7): a link is one size.** Its `remote_ids` bag carries `nmID`,
`imtID` and `chrtID` (the names are already reserved, G §3). The links table keeps its unique key
`(account_id, offer_id)`, so the link's `offer_id` is a **unit key** the adapter builds:

- a card with a single size without a size name: `offer_id` = `vendorCode`;
- otherwise: `offer_id` = `vendorCode` + `|` + `techSize`.

`vendorCode` is at most 72 characters and the column is `varchar(191)` (READ `data/class-schema.php:277`). The unit key
is never shown: WF3 lets the adapter say what the «Артикул» column shows (`vendorCode`) and adds the size beside it. The
unit key is frozen once sent, like every article (`Article_Policy`); `|` cannot be typed into a Wildberries size by the
plugin's own exporter, and for imported sizes that contain it the adapter escapes it (WP3 pins this with a test).

No schema change follows from the unit: one colour card with five sizes is five links that share `nmID`. What changes
is every place that assumed «one link = one request item» — export batches (WF2), prices (WF4) and the card list
(one cabinet card shows as a parent row with its sizes as children, the way a variable product already does).

### 5.2 Barcodes

A Wildberries barcode is a free string and a size may have several (API §4). WooCommerce's GTIN setter strips every
character but digits, `-` and `X` and refuses duplicates, so `12345Ejf5` would be stored as `123455`.

- **Storage (WF3):** a new table `link_barcodes` (`link_id`, `barcode varchar(191)`, `position`), unique per
  `(account_id, barcode)`; the full list, byte for byte. Not a `link_ids` row, whose key is `(link_id, id_name)` with
  scalar values (G 8).
- **WooCommerce GTIN:** written only on import, only when the product has none, only for a value that is digits only,
  of length 8, 12, 13 or 14, with a valid GS1 check digit, and only the first such value of the size.
- **Export:** the profile's existing barcode source (none, GTIN, SKU, custom field — G 6) gives at most one barcode per
  size; with «none» Wildberries generates one, and the poll stores what it generated.

### 5.3 Matching existing cards to products (D2 «link»)

`Card_Matcher` compares the normalised WooCommerce SKU with the article, exactly (G 8). WF3 turns this into an ordered
list of **match keys** the adapter declares; the first key that gives exactly one product wins, an ambiguous key is a
reported conflict and never a guess:

1. any barcode of the size equals the product's GTIN, SKU or the profile's barcode field, compared as raw strings;
2. the product's SKU equals `vendorCode` + separator + `techSize` for the separators `-`, `_`, `/`, space and none;
3. the product's SKU equals `vendorCode` (single-size cards only).

### 5.4 Import Wildberries → WooCommerce (D2 «import»)

The importer builds one variable product from a group (`Group_Assembler`, importer spec §2.6.1). The Wildberries adapter
presents **each size as a card** with group key `imtID` and two aspects, colour and size; a group with one colour and one
unnamed size becomes a simple product. The variation SKU is `vendorCode` for a single-size card and
`vendorCode-techSize` otherwise (unique in WooCommerce; the unit key with `|` is never written as a SKU — WF3 lets the
adapter say what the import writes). Photos are per `nmID`: the colour's first photo becomes the image of each of its
variations, the first colour's gallery the product gallery.

### 5.5 Create and update (D2 «create», «own fields only»)

**Create.** A simple product → one `nmID` with one size. A variable product → the mapping names which attribute is the
**size**; every combination of the remaining attributes is one colour card (`nmID`), all sent as variants of one group
so that they share `imtID`. `vendorCode` per colour card comes from the profile's article source: the parent SKU for a
product with one colour, the parent SKU + the colour value otherwise (**interpretation**; the mapping form shows the
resulting articles in its preview before anything is sent). A variable product without an attribute marked as size is
refused by an export rule with a merchant reason. Wildberries answers the create with no task number; the result is read
from the card list and the error list by `vendorCode` (WF2).

**Update.** `cards/update` overwrites the whole card and cannot change existing barcodes, photos or prices (API §4).
The plugin therefore never sends a card built from the shop alone:

1. read the current card;
2. replace **only the fields the plugin owns** — the ones the mapping and profile fill (title, description, brand,
   dimensions, the mapped characteristics) and new sizes — and copy every other field of the read card back unchanged,
   `documents` included;
3. write, then read back and compare; a difference in a field the plugin does not own is logged as an error and shown
   in diagnostics.

A characteristic the merchant filled in the cabinet and did not map stays the cabinet's. Photos are replaced only by
the merchant's explicit action («Обновить фото»), because `media/save` replaces all media of the card. No operation of
the plugin deletes or trashes a card.

## 6. FBS: orders, supplies, marking

### 6.1 Orders

- **Ingest.** Polling only (API §11): `GET /api/v3/orders/new` and the statuses of open units by id, on the module's
  posting job (every 10 minutes, G 21). No buyer data exists for a Russian seller on FBS.
- **Grouping (D10).** Group key = `orderUid` + `warehouseId`. One WooCommerce order per group; **each unit is its own
  line item with quantity 1**, even when two units are the same size, so that one unit can be cancelled, marked and
  stickered on its own (**interpretation**; WF6 settles the line representation against
  `Registry_Order_Writer::upsert_member()`). A cancelled unit keeps its line, marked «Отменено», and leaves the order
  total; the order becomes cancelled only when every unit is.
- **Statuses.** `status_raw` = `supplierStatus/wbStatus` (the longest pair, `confirm/canceled_by_missed_call`, is 31
  characters). The adapter maps the pair to the module's canonical statuses and supplies its own status names and
  groups; the WooCommerce status follows real transitions only (module rule, #1920).
- **Cancel.** Per unit, no reason, `PATCH /api/v3/orders/{id}/cancel`, only while `isCancellable` (WF6).
- **Stock.** Whether Wildberries lowers the seller-warehouse amount on an order is UNVERIFIED (M0b 12); it picks the
  adapter's stock formula (`wc_only` or `wc_plus_reserved`).

### 6.2 Supplies — the page «Поставки» (D3)

The merchant's day: orders appear → he selects them in «Заказы» → «Добавить в поставку» (a new or an open supply) →
enters the codes the supply lists as missing → prints stickers → chooses the pickup point and date → adds cargo places
where the supply needs them → «Передать в доставку» → prints the supply QR.

| Step | Wildberries (SOURCED, `03-orders-fbs`) | Notes |
|---|---|---|
| Create | `POST /api/v3/supplies` | One supply holds one cargo type; the page refuses a mixed selection **before** the request, with the reason |
| Add orders | `PATCH /api/marketplace/v3/supplies/{id}/orders`, ≤ 100 | Moves the units to `confirm`; from here stickers and codes are possible |
| Move a unit | `PATCH /api/marketplace/v3/fbs/supplies/{id}/orders/transfer` | Out of a closed supply into an open one; offered as «Перенести» |
| Pickup point and date | `GET …/shipping-points`, `PATCH …/fbs/supplies/shipping-method` | Required before hand-over (409 `SupplyShippingRequired`) |
| Cargo places | `…/supplies/{id}/trbx`, stickers per place | Add / list only; removing a place is a `DELETE` — a product function, never used on the test cabinet (D6) |
| Hand over | `PATCH /api/v3/supplies/{id}/deliver` | Refused while a required identifier is missing or unvalidated (409 `MetaValidationFail`); the page checks the «what is missing» list first and keeps the button off with the reason |
| QR | `GET /api/v3/supplies/{id}/barcode` | Only after hand-over |
| Re-shipment | `GET /api/v3/supplies/orders/reshipment` | Shown as a filter «Требуют повторной отгрузки» |

Not built: deleting a supply, passes, auto-return settings (D3). The page is a framework page (R1) — WF8.

### 6.3 Stickers

`POST /api/v3/orders/stickers`: ≤ 100 units, only in `confirm` / `complete`, PNG / SVG / ZPL, 58×40 or 40×30, one per
unit (API §7). The merchant gets **one printable sheet** (PNG stickers laid out on a page the browser prints) or a ZPL
file for a label printer, chosen in the account settings (sticker size and format — a business choice, not a technical
knob). WF7 carries several files per request and their real file names. A unit whose customs declaration number is
required and absent has no sticker (409 `CustomsDeclarationIsRequired`): the button is off with that reason.

### 6.4 Marking (D4)

- **What is required** comes with the order: `requiredMeta`, and for goods under mandatory marking `optionalMeta` too
  (API §7). The current values and their validation states come from
  `POST /api/marketplace/v3/orders/meta`.
- **Input.** A «Честный знак» field per unit: in the order details box and in the supply's unit list. It is **active
  only while the unit is in `confirm`** — Wildberries accepts the code only then (SOURCED, `PUT
  /api/v3/orders/{orderId}/meta/sgtin`), i.e. after the unit is in a supply; before that the field says so. Typed or
  scanned: the code must keep its GS separators (`\u001D`), which a scanner in keyboard mode often drops — the field
  accepts the common visible substitutes a scanner can be set to send and the plugin restores the separator; a code
  without one is refused locally with an instruction (Wildberries would answer `sgtinNoGS`, at the price of ten
  requests).
- **«Чего не хватает».** The supply shows, per unit, each required identifier with its state: filled and valid,
  waiting for validation (`pending`), refused with Wildberries' reason translated (`sgtinNotFound`, `sgtinRetired`, …),
  or **missing**. For the kinds the plugin does not enter in 1.0 (UIN, IMEI, GTIN, expiration date, customs
  declaration) the line names the kind and says where to enter it — the cabinet, if M0b 14 shows the cabinet allows it.
- **If the cabinet does not allow it** (M0b 14): such goods cannot be shipped through the plugin in 1.0, and the plugin
  description and the merchant docs say so in plain words (D4).

## 7. DBS: orders the seller delivers (conditional on §3.3)

### 7.1 What arrives

SOURCED (`05-dbs`): units with `orderUid` and **`groupId`** (one warehouse within one cart — exactly D10's group, used
as the group key); `address` (`fullAddress` as one string, latitude, longitude; for delivery to a pickup point, the
point's address); `deliveryType` `dbs`, `dbsPickupPoint` or `edbs`; the buyer's comment; `requiredMeta`. Separately:
the buyer's name and phones (`POST /api/v3/dbs/orders/client`), the date and time the buyer chose (`…/delivery-date`),
the price of paid delivery for the group (`POST /api/v3/dbs/groups/info`).

Wildberries warns that order data not saved before the transfer to assembly is unavailable until the order is sold or
cancelled. The plugin therefore **stores the full new-order payload at first sight**, before any action. An order the
merchant confirmed in the cabinet before the plugin saw it arrives without its address; it is created with what exists
and says «адрес недоступен: заказ принят в работу в кабинете Wildberries».

**Interpretation.** `dbs` and `dbsPickupPoint` are both handled (a pickup-point order is the same flow with the point's
address and a PDF sticker from `POST /api/marketplace/v3/dbs/orders/stickers`, which needs a personal token — D5 gives
it). Express DBS has its own warehouse type (6); 1.0 does not list those warehouses, so the plugin sends no stock
there; an `edbs` unit that still arrives is shown and can be worked like `dbs`.

### 7.2 The WooCommerce order (D1 «ordinary order with a shipping address»)

| Field | Source |
|---|---|
| Lines | one per unit (§6.1) |
| Shipping line | «Доставка Wildberries» with the paid-delivery amount of the group; total = units + delivery |
| Shipping address | `fullAddress` into `shipping_address_1` as given, latitude and longitude in order meta; city, region and postcode are **not parsed from the string** — the carrier step of §7.5 resolves them through the delivery module's own address step |
| Name | `firstName` / `fullName` from the client call |
| Phone | §7.3 |
| Payment | paid through Wildberries: payment title «Wildberries», the order is marked paid, so a carrier never plans cash on delivery |
| Delivery date and time | order meta, shown in the details box and passed to the carrier step as a hint |
| Customer e-mail | none exists; WooCommerce customer e-mails have no address to go to |

A DBS order is the seller's to deliver, so it is not the read-only marketplace order of today: WF9 gives the module a
**seller-delivered** order policy — the shipping address, the phone and the shipping method are editable, the items,
prices and totals stay locked (they are Wildberries'). Buyer data is personal data: it is covered by WordPress' export
and erase tools and removed by the module's account deletion.

### 7.3 The phone (gate 1)

`replacementPhone` is a relay number for the buyer; `phone` is a backup relay number that needs the extension
`phoneCode`; there are additional numbers and codes; an empty value means «not assigned yet» (SOURCED). So a relay
number **without** an extension may exist. The design:

- the order's phone is `replacementPhone` when present; the backup number and its extension are shown in the details
  box as «запасной номер, добавочный …» and never concatenated into the phone field;
- the client call is repeated on the status poll while the number is empty;
- **M0b 15 decides** whether this number works for CDEK and Yandex (accepted as the recipient; SMS and calls reach the
  buyer). If only the number with an extension is ever given, or a carrier's messages do not arrive, gate 1 fails.

### 7.4 Statuses and actions (gate 2)

`new` → `confirm` → `deliver` → `receive` or `reject`; `cancel` from `new` or `confirm` only; Wildberries itself sets
`cancel_missed_call` (SOURCED). The status calls are batch calls with per-unit results and a limit of 1 request per
second.

| Action in the admin | Wildberries call | When offered |
|---|---|---|
| «Принять в работу» (row and bulk) | `status/confirm` | `new`. Until then the buyer may still cancel within the first hour; the plugin does not confirm by itself (**interpretation**, §13.2 I6) |
| «Оформить доставку» | — (§7.5) | `confirm`, no carrier shipment yet |
| «Передать в доставку» | `status/deliver` | `confirm`; sent automatically when a carrier export of the order succeeds |
| «Вручён» | `status/receive`, with `code` when given | `deliver`; the dialog has a field «Код подтверждения от покупателя», optional in the request schema |
| «Отказ при получении» | `status/reject` | `deliver` |
| «Отменить» | `status/cancel` | `new`, `confirm`; no reason |

**M0b 16 decides** the last mile: if `receive` works without a code, the carrier's «delivered» status sends it
automatically; if a code is always required and only the buyer knows it, a carrier cannot supply it, and unless the
measurement shows a workable route (the buyer tells the code to the seller; Wildberries closes the order by itself),
gate 2 fails.

### 7.5 Shipping a DBS order with CDEK or Yandex

The delivery plugins work on orders that carry their shipping method; today nothing can give an existing order one:
«Редактировать» on «Заказы доставки» is for that page's rows only, and the WooCommerce editor cannot set a tariff or a
pickup point ([#710 design](2026-09-27-710-create-edit-order-design.md), O3, O12).

- **WD1 (shipping module).** A third entry into the same wizard: **«Оформить доставку» for an existing order**. It
  opens on the Address step with the order's address text, phone and name prefilled, lets the manager resolve the
  settlement and pick the carrier's tariff or pickup point, and saves the carrier's shipping line onto **the same
  order**. The Customer, Items and Payment steps are skipped; item prices and totals are not touched; the delivery price
  the carrier quotes is stored as the seller's cost and does not change what the buyer paid. From then on the order is a
  row of «Заказы доставки» and is exported, tracked and documented like any other. The entry is offered through a small
  registry in the shipping module, so that the marketplace module asks for it without the shipping module knowing any
  marketplace.
- **WF10 (marketplace module).** Listens to `woodev_shipping_order_exported` → sends `deliver`;
  `woodev_shipping_delivery_status_changed` → on «delivered» sends `receive` (if gate 2 allows) or raises «подтвердите
  вручение»; on «returned / refused» offers `reject`. A failed call is retried by the module's job, never by the
  carrier's code. The carrier's tracking number is shown in the order details box.
- A merchant who delivers by his own courier, or with a carrier that is not ours, uses the buttons of §7.4 alone.

WD1 changes delivery code, which the marketplace round ruled out (B3). It needs the owner's word — §13.3 T2.

## 8. Framework extensions

Ozon Seller 2.0.0 is released on the module. Every card below: keeps Ozon's behaviour (its tests stay green, a fake
second marketplace proves the new path); changes the schema only additively with a `Schema::REVISION` step (now 4,
READ); names the Ozon adapter change it forces, which ships in the same framework release window; touches no file of
`woodev/shipping-method/**` or `src/shipping-orders-page/**` — WD1 is the single stated exception.

| Card | Problem | Design | Ozon |
|---|---|---|---|
| **WF1 — asynchronous price settlement** | A pending receipt is logged and counted as unanswered; nothing calls `Price_Pusher::settle()` (G 18) | A pending or duplicate receipt opens a **price task** in the ledger (upload id, the link ids, the intents' revisions). The poll job settles open price tasks: accepted → the intent is accepted; refused per item → rejected with the marketplace's text; **quarantine** → an item error with its own reason code and a merchant sentence («цена ниже текущей более чем на …, Wildberries держит её в карантине»); deadline passed → expired and re-queued once. Deadline and poll cadence are adapter values | Immediate path untouched |
| **WF2 — export of grouped cards without a task number** | Batches split sibling sizes; an empty task id is a failure and a retry, which would create the card twice (G 10–11) | (a) The adapter names an **export group** for a link (`vendorCode`'s card, and the `imtID`-to-be group); the scheduler never splits a group across batches and the gateway receives whole groups. (b) `submit()` may answer «accepted, no task id»: the ledger opens a task keyed by the batch's articles; `read_import()` receives the task's articles as well as the id. (c) A retry of an accepted create first reads the card list by article — never a blind re-send. (d) Poll deadlines become adapter values (document checks take up to 3 days, G 12) | `read_import()` signature gains the articles argument |
| **WF3 — size as the unit: match keys, barcodes, presentation** | G 7–9 | §5.1–5.4: adapter-declared match keys with conflict reporting; table `link_barcodes`; the GTIN rule; adapter answers «what the article column shows» and «what the import writes as SKU»; the cards list groups unit rows under their card | Ozon declares one key (SKU = article) and shows the article as today |
| **WF4 — price groups** | One price per colour card unless size prices are allowed (G 19) | The adapter names a **price group** per link and whether the group allows per-member prices. The flusher hands the pusher whole groups (absent siblings are loaded). A group that does not allow per-member prices and whose members' computed prices differ is **not sent**; each member gets the reason code «у размеров разные цены, маркетплейс принимает одну» shown at the product and in diagnostics (D7) | Ozon: every link is its own group |
| **WF5 — token-only account and the 4xx surcharge** | The account needs a typed identifier; nothing consumes `cost_4xx` (G 3–4) | The adapter may derive the external id from the secrets (Wildberries: `sid` of the token), the settings form then shows no identifier field. The governor gets a way to report a 4xx answer, which draws `cost_4xx − 1` more from the bucket | none |
| **WF6 — order unit actions** | Only cancel (with mandatory reasons) and label exist (G 24–25, 28) | (a) The adapter declares **seller actions**: id, label, the statuses it is offered in, whether it is per unit, per order or bulk, an optional input schema (a code, a supply choice), a confirmation text. The orders table, the order details box and the server-side gate (`Posting_Action_Gate`) read one declaration; a refused action stays visible, off, with its reason — the «Заказы доставки» mechanism. (b) Cancel accepts an empty reason list. (c) The details box lists the units of a grouped order with their own status and actions; a cancelled unit is its own line (D10). (d) A per-unit input seam (marking code) with adapter validation before any remote call | `Order_Canceller` contract: reasons become optional |
| **WF7 — sticker documents** | One PDF per task (G 27) | A label answer may hold several files with their media types; PNG stickers are laid out on a printable sheet, ZPL / SVG are downloaded under real names; a synchronous answer needs no task | none |
| **WF8 — shipments: the page «Поставки»** | `CONFIRM_SHIPMENT` is declared, nothing consumes it (G 26) | A domain contract `Shipment_Batches` (list, create, add / move units, options to choose — pickup point and date, cargo places, close, documents, the «what is missing» list) and a records page `shipments` that the base class offers when the adapter supports `CONFIRM_SHIPMENT`. Framework owns the table (a batch row with its units as children — the variable-product mechanism), the row and bulk actions through WF6's declarations, a local snapshot table refreshed by a job (a table read never calls the marketplace), progress and locks. The nouns («Поставки», «поставка», «грузовое место») come from the settings descriptor | Ozon does not declare the capability; no page appears |
| **WF9 — seller-delivered orders** | The module knows only orders the marketplace delivers | `Order_DTO` carries a delivery scheme and a full address (text, coordinates, pickup-point flag); `Wc_Order_Store` writes the shipping address, the shipping line with the paid-delivery amount and the paid mark; the order screen applies the seller-delivered policy of §7.2; the «Заказы» table gets the scheme filter and column (D9) when the adapter declares more than one scheme; personal-data exporter and eraser | none (one scheme) |
| **WF10 — carrier bridge** | — | §7.5: listeners on the two shipping actions, mapped to WF6 seller actions by an adapter-declared table; the button «Оформить доставку» shown when WD1's entry exists and a delivery plugin is active | none |
| **WF11 — neutral wording and contract cleanup** | «Ozon» in a mapping sentence; «отправление» in 35 strings; export gated on `IMPORT_PRODUCTS`; dead `order_source()` / `card_exporter()` (A1, A2, A10) | The descriptor supplies the nouns; a test pins «no marketplace name in a framework msgid»; the catalogue gate gets its own capability name; the two dead contract methods are removed with the Ozon change | Ozon supplies its nouns; drops two stubs |
| **WD1 — delivery for an existing order** (shipping module) | §7.5 | The third wizard entry; the editable-state policy and the export path of existing rows are not changed | — |

## 9. Plugin parts

Namespace `Woodev\Wildberries_Seller`, include-based autoloader as in Ozon Seller.

### 9.1 Connection

- **One secret**: the personal token (JWT, 180 days). Decoded **locally** on save: type (Base → refused with «нужен
  персональный токен»; Test → works only against the sandbox hosts, which a development constant switches on; refused otherwise), categories
  (bitmask `s`), the read-only bit, expiry, seller id `sid` (the account's external id, WF5).
- **Required categories:** Content, Prices and Discounts, Marketplace, each with write. A missing category switches off
  the flows that need it and the account status names it in plain words; a read-only token puts the account under the
  existing write gate (G 2).
- **Health:** `GET /ping` per host used. **Expiry:** the module's key-expiry notices, from the token's `exp`.
- **Clients**, one per host on `Woodev_API_Base`: `content-api`, `discounts-prices-api`, `marketplace-api`,
  `common-api` (`.wildberries.ru`); the token header is masked in logs.

### 9.2 Catalogue and mappings

Parent categories → subjects; characteristics per subject with `required`, `maxCount`, type, unit; six closed
directories (colours, kinds, countries, seasons, VAT, TNVED). Cached and refreshed by the module's catalogue job.
Export rules (adapter list): title ≤ 60, description limit, images ≥ 700×900 and ≤ 30, dimensions present (converted
to cm and kg by the card builder), required characteristics, `maxCount`, a size attribute for variable products,
creation quota (`GET /content/v2/cards/limits`).

### 9.3 Cards

List (`cards/list`, cursor `updatedAt` + `nmID`) into the module's snapshot; link (§5.3); import (§5.4); create and
update (§5.5). **Media job:** queued from `woodev_wildberries_seller_card_created`, once per `nmID` (the hook fires per
link, i.e. per size), with its own retry, a read-back of the card's photos and a sweep for cards the hook missed
(G 12). New cabinet cards are not announced, so the «auto-import new cards» option is not offered (WF11).

### 9.4 Stock and warehouses (D8)

`PUT /api/v3/stocks/{warehouseId}`, ≤ 1000 `{chrtId, amount}`, absolute. The adapter lists the seller warehouses of
`deliveryType` FBS and DBS with the scheme in the name («Склад — FBS»); the merchant picks the account's default
warehouse and may override it per product — **the warehouse chosen is the scheme the product sells by**. One default
per account, as in Ozon Seller (**interpretation** of D8's «FBS and DBS have their own»: each scheme has its own
warehouses to choose from; a second default would have no consumer while one product feeds one warehouse — §13.2 I4).
A warehouse that is being processed or deleted (`isProcessing`, `isDeleting`) defers the push.

### 9.5 Prices (D7)

`{nmID, price, discount}`: integer price and a whole-percent discount. From the module's price line: `price` = the old
price when there is one, else the price; `discount` = the whole percent that brings it to the selling price. A whole
percent cannot hit every price: the adapter sends the nearest percent that does not go **below** the shop's selling
price, and the read-back (`price_reader`) shows the real `discountedPrice` beside the shop price (**interpretation**;
M0a 7 measures Wildberries' rounding). Size prices through `upload/task/size` where `editableSizePrice` is true (WF4).
Results through WF1; quarantine is shown, never silently waited out.

### 9.6 Orders, supplies, marking, DBS

The adapter halves of §6 and §7: posting handler for both schemes, status composition and names, the seller-action
declarations, the `Shipment_Batches` implementation, sticker provider (FBS PNG / ZPL, DBS pickup-point PDF), the
marking validator and reason texts, the DBS client / date / paid-delivery readers, the carrier-event table of WF10.

## 10. Admin pages (D9)

All pages are the module's PHP-driven React pages; the plugin adds declarations, not screens.

| Page | Content | Wildberries specifics |
|---|---|---|
| **Товары** | «Товары сайта» and «Карточки на маркетплейсе» | A colour card is a parent row with its sizes as children; «Артикул» shows `vendorCode`, the size beside it; reasons at the product: differing size prices (D7), quarantine, missing size attribute |
| **Заказы** | FBS and DBS in one table | Filter and column «Схема»; a row is a WooCommerce order, its units are children; row and bulk actions from WF6: «Добавить в поставку», «Стикеры», «Принять в работу», «Оформить доставку», «Передать в доставку», «Вручён», «Отказ», «Отменить» |
| **Поставки** | New (WF8) | Supply rows with units as children; state, pickup point and date, cargo places, «чего не хватает», stickers, «Передать в доставку», QR; filter «Требуют повторной отгрузки» |
| **Сопоставление** | Mappings and profiles | Subjects and characteristics; the size-attribute choice; the article preview of §5.5 |
| **Инструменты** | Account tools | The module's tools; one-time price pull; «Обновить фото» in bulk |
| **Диагностика** | Report | Token facts (type, categories, expiry — never the token), bucket state, open price and export tasks, quarantine list; debug-only detail behind «Логирование» |
| **Настройки** | Accounts, sync, sticker format | One field for the token; no notifications section (no push) |

«Аналитика» is the shared marketplace page, unchanged. Every table follows the «Заказы доставки» mechanisms (R3); every
UI brief names its counterpart there and requires a real-browser look at every screen before «ready».

## 11. Data model

The module's per-plugin table set under `woodev_wildberries_` (accounts, links, link ids, mappings, profiles, snapshots,
catalogue cache, order registry, documents, ledgers, throttle, API errors). Additions, all additive and for every
marketplace (they arrive with the framework cards and reach Ozon Seller as empty tables):

| Addition | Card | Holds |
|---|---|---|
| table `link_barcodes` | WF3 | the full barcode list of a unit |
| price tasks in the task ledger | WF1 | upload id, link ids, revisions, deadline |
| export tasks without a remote id | WF2 | the batch's articles |
| tables `shipments`, `shipment_units` | WF8 | supply id, state, pickup point and date, cargo places; unit → supply; the «what is missing» snapshot |
| order registry: scheme | WF9 | `fbs` / `dbs` per order |

Link `remote_ids`: `nmID`, `imtID`, `chrtID`. Order meta (through the adapter's `Order_Meta_Contract`): the group key,
the unit ids per line, per unit `rid`, statuses and label-identifier states; for DBS the delivery date and time,
coordinates, backup phone and extension, the raw first payload. Marking codes are stored only as long as the order is
open and are never logged. No option holds a secret; the token is encrypted in the accounts table.

## 12. Errors, limits, testing

### 12.1 Error classification

| Answer | Class | Merchant sees |
|---|---|---|
| 401 | auth refused → account paused | «Токен не принят Wildberries» with the expiry date if it has passed |
| 402 | permanent for the account | Wildberries' text (payment required on the seller's side) |
| 403, token category | permanent for the flow | which category the token lacks |
| 403 «base token is not allowed» | permanent | «нужен персональный токен» |
| 409 with a named reason (`MetaValidationFail`, `SupplyShippingRequired`, `SupplyHasZeroOrders`, `CustomsDeclarationIsRequired`, `FulfillmentSupplyBoxesRequired`) | business refusal, not retried | the reason in Russian, per unit where Wildberries gives units |
| 429 | throttled; wait `X-Ratelimit-Retry` | nothing unless it lasts |
| 5xx, timeout | retryable with the module's backoff | nothing unless it lasts |
| 204 on a stock write with a wrong field name | silent no-op (API §6) | prevented by a read-back after the first write of a session and by a contract test on the payload |

### 12.2 Rate limits

Token bucket per seller account and method group; personal-token numbers (API §2) become the adapter's `bucket_specs()`:

| Group | Limit | Burst | 4xx cost |
|---|---|---|---|
| Content read (directories, characteristics) | 100 / min | 5 | 1 |
| Cards list | 80 / min | 5 | 1 |
| Cards create / update | 10 / min | 5 | 1 |
| Cards error list | 8 / min | 5 | 1 |
| Prices and discounts | 10 / 6 s | 5 | 1 |
| Stocks, warehouses | 300 / min | 20 | 10 |
| FBS orders, supplies, stickers, label identifiers (read) | 300 / min | 20 | 10 |
| Label identifiers (write) | 1000 / min | 20 | 10 |
| FBS cancel | 100 / min | — | 10 |
| DBS orders (read) | 300 / min | 20 | 10 |
| DBS status changes | 1 / s | 10 | 10 |

The surcharge is stated by Wildberries for the Marketplace category; for Content and Prices the table assumes 1 until
M0a 11 shows otherwise. Because a refusal costs ten (WF5): validate locally (marking format, mixed cargo types, status preconditions), read
before write (the «what is missing» list before hand-over), and never retry a business refusal. Sandbox, if used, is
1 request per second for all Marketplace methods.

**Worst case per account per day** (10-minute poll, G 21): new FBS orders 144 · FBS statuses 144 × ⌈open units /
1000⌉ · the same two for DBS · per new DBS group three reads (client, date, paid delivery), the client read repeated
at most once per poll while the phone is empty and for at most 24 hours · price-task polls: at most one per open task
per poll, tasks expire at their deadline · card list refresh: on demand and at most once an hour by job. All of it is
two to three orders of magnitude under the limits; the constraint that binds is export (10 create requests a minute)
and prices (100 a minute), both paced by the governor. Every automatic call backs off on failure and stops at a cap;
none of this traffic goes to woodev.ru.

### 12.3 Unit and contract tests

PHPUnit with Brain Monkey, no network. Framework cards: the fake second marketplace exercises each new path with
positive and negative controls (pending → accepted / refused / quarantined / expired price; create without a task id,
retried, never doubled; a group never split across batches; conflicting group prices not sent; a 4xx draws ten; a unit
action refused server-side; a batch that cannot be closed lists what is missing). Plugin: card builder and
read-merge-write (a cabinet-only field survives), unit key and escaping, barcode round trip (`12345Ejf5`, two barcodes,
GTIN left empty), match keys with conflicts, percent rounding table, status composition for every documented pair,
grouping in every arrival order, marking code normalisation and every documented `sgtin*` state, the documented 409
refusals **from fixtures**, DBS address and phone mapping. Jest for the React changes; phpcs, phpstan, `lint:i18n`,
`lint:docs`.

### 12.4 The live cabinet (D6)

Acceptance and measurement run on the live cabinet under the four rules of §3, repeated in every brief. In addition:

- **What a test leaves behind is decided before the test.** Cards cannot be deleted (D2, D6): the whole 1.0 programme
  creates a small, counted number of test cards (M0a 4 creates at most three; later cards reuse them), named as tests,
  with stock 0, so they are never on sale. Supplies cannot be deleted: at most one empty supply is created without an
  order.
- **Existing cards of the cabinet are read, linked and imported freely** (that changes only the rig). A **write** to an
  existing card — an update, a price, photos — is made only on the test cards, unless the owner names a card.
- **Prices** are written on test cards; a price changed on any other card is restored in the same session from the
  value read first.
- **Stock**: the only standing write is 0. The link, stock and order acceptance that needs a sellable item is batched
  into the short windows the owner allows (rule 2) — M0b, then one acceptance window near the end (WP11).
- **Orders, supplies with units, marking, DBS** can be accepted on the live cabinet only inside such a window and with
  a real purchase (§3.2). Outside it they are covered by fixtures and by the sandbox (`WB_TEST_TOKEN`) with emulated
  buyer actions — which proves the request plumbing only: marking is optional in the sandbox and a sandbox delivery is
  never evidence of the production refusal (API §3). The rules «never delete» and «stock 0» are the live cabinet's; the
  sandbox holds test data only.
- The rig that talks to the live cabinet keeps the module's first-remote-write confirmation on, and no agent runs
  «все по фильтру» against it.

Rig acceptance: the coordinator looks at every admin screen in a real browser before «ready»; the owner sees the UI
near the end.

## 13. Registers

### 13.1 UNVERIFIED (settled by)

| # | Item | Card |
|---|---|---|
| U1 | The stored token's type, categories and write access | M0a 1 |
| U2 | Webhooks do not exist; which methods are being replaced; how faithful the sandbox is | M0a 2, 2a |
| U3 | Card creation: time to ids, error-list behaviour, generated barcode format, `vendorCode` change | M0a 4–5 |
| U4 | Price task timing, rounding of the discounted price, quarantine threshold, size-price categories | M0a 7 |
| U5 | An empty supply's lifetime | M0a 10 |
| U6 | Wildberries lowers seller-warehouse stock on an order | M0b 12 |
| U7 | The production refusal for a missing code; code validation timing; the cabinet's manual entry per code kind | M0b 14 |
| U8 | DBS relay phone: what is given, when, and whether CDEK / Yandex messages reach the buyer | M0b 15 |
| U9 | DBS `receive`: whether the confirmation code is required and who can know it | M0b 16 |
| U10 | Whether a seller's own test purchase is allowed by Wildberries | owner, before M0b |
| U11 | `refresh()` of a grouped order can find its member ids (G 22) | WP7 |
| U12 | Which separator substitutes real scanners send for GS | WP9 |
| U13 | Assembly deadlines and penalties (not in the OpenAPI files) | M0a 2 (knowledge base) |

### 13.2 Interpretations (the coordinator may revisit)

| # | Interpretation | Where |
|---|---|---|
| I1 | Identity values: plugin id, text domain, namespace, menu id | §2 |
| I2 | A link is one size; the unit key is `vendorCode` or `vendorCode\|techSize`; never shown | §5.1 |
| I3 | Card articles for a multi-colour variable product = parent SKU + colour value; the size attribute is named in the mapping | §5.5 |
| I4 | One default warehouse per account (not one per scheme) | §9.4 |
| I5 | One line item per unit, quantity 1 | §6.1 |
| I6 | No automatic «Принять в работу» in 1.0: it would end the buyer's free-cancel hour and commit stock the merchant has not checked; the bulk action covers the routine | §7.4 |
| I7 | `dbsPickupPoint` is handled with `dbs`; express DBS warehouses are not listed | §7.1 |
| I8 | Discount percent rounded so that the price never goes below the shop's | §9.5 |
| I9 | The supplies page is a framework page behind `CONFIRM_SHIPMENT` (R1), not a plugin page as the research card plan had it | §8 WF8 |
| I10 | Sticker format and size are an account setting | §6.3 |

### 13.3 Tensions the coordinator must hear about

Nothing in the decisions was changed. These are the places where two decisions, or a decision and a standing rule,
pull against each other; each has the reading this spec took and what would settle it.

| # | Tension | This spec's reading | Needs |
|---|---|---|---|
| T1 | **D1's gate checks need a real DBS order; D6 keeps stock at 0.** A dormant shop receives no orders, so M0b needs a short stock window, a DBS warehouse in that cabinet, a real buyer, and real CDEK / Yandex shipments to that buyer. Whether a seller-arranged test purchase is allowed on Wildberries is unverified (U10) | M0 is split: M0a runs at once and unblocks FBS-independent work; M0b waits for the owner's word. If no real DBS order can be made, the gate cannot be measured and D1's own fallback applies (DBS → next release) | the owner's word on how the test order is made |
| T2 | **D1 «our delivery plugins ship DBS orders» against the rule «delivery code is not touched» (B3).** An existing order cannot be given a carrier's method without a new entry in the delivery wizard; the marketplace order screen is read-only | Card WD1 changes the shipping module, as one stated exception, without changing how existing rows behave | the owner's word that WD1 may touch the shipping module; without it DBS has only the manual buttons and D1's purpose is not met |
| T3 | **D4 «a code field in the order» against Wildberries' rule** that a code is accepted only in `confirm`, i.e. for FBS only after the unit is in a supply | The field exists in the order and in the supply, and is active from `confirm`; before that it says why | nothing; stated so nobody builds an always-active field |
| T4 | **D6 «never delete» against test leftovers.** Test cards and an empty test supply stay in the friend's cabinet for good | Counted and named in advance (§12.4) | the owner knows the cabinet keeps up to three test cards and one empty supply |
| T5 | **D6 «never delete» and product functions that are deletions** (remove a cargo place, clear a wrong label identifier) | They may exist in the product if a card needs them, but are never exercised on the test cabinet; they are covered by fixtures | nothing |

## 14. Implementation card plan

Filing briefs for the coordinator, **not filed cards**. S / M / L are relative size. Model tiers follow the project rule
(Haiku 5.5 for bounded work with a detailed brief, Sonnet 5.5 for routine work against this plan, Fable 5.2 for the
hardest correctness work, one at a time); every card gets a critic of another lineage. Every framework card carries the
delivery-untouched invariant except WD1. Every card that touches the live cabinet carries the four rules of §3.

### Phase 0

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| M0a — Live measurement without orders, sandbox pass | M · Sonnet 5.5 | — (tokens are in place, §3 rule 3) | §3.1. Accept: `measurement.md` with items 1–11 and 2a proven, refuted or open; sandbox request / answer pairs saved as fixtures with buyer data removed; every write listed before it was sent; no `DELETE` in the request log; stock never above 0; no token in any file |
| M0b — Live measurement with orders, the DBS gate | M · Sonnet 5.5 | M0a; the owner's word for the stock window and the test purchase (T1) | §3.2. Accept: items 12–17; the gate line «DBS in 1.0: yes / no» with its evidence; stock back at 0 |
| S0 — Spec amendment | S · the coordinator | M0a (first pass), M0b (second) | Fold the measurements into this spec; drop or keep the DBS cards; re-estimate |

### Framework (`woodev-plugin-framework`)

«Files» are under `woodev/marketplace/` unless stated; every card may touch its own tests, `woodev/class-map.php` and,
for client cards, `src/marketplace-admin/**` with its built bundle.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| WF1 — Asynchronous price settlement | M · Sonnet 5.5 | — | `jobs/**` (price), `price/**`, `ledger/**`, `diagnostics/**` | §8. Accept: fake adapter pending → accepted / refused / quarantined / expired; Ozon's immediate path unchanged |
| WF2 — Export of grouped cards without a task number | L · Fable 5.2 | M0a 4 | `jobs/class-export-*`, `jobs/class-poll-task-runner.php`, `flows/**` | §8. Accept: a group never split; «accepted, no task id» polled by article; a retried accepted create never doubles; adapter deadlines; Ozon tests green |
| WF3 — Size as the unit | L · Sonnet 5.5 | M0a 3 | `flows/class-card-{matcher,linker,import}.php`, `flows/class-product-writer.php`, `flows/class-group-assembler.php`, `data/**`, `admin/product/**`, `admin/pages/products/**` | §5.1–5.4. Accept: `12345Ejf5` and a two-barcode size survive link and import byte for byte with GTIN empty; an ambiguous key is a reported conflict; the cards list groups sizes under their card |
| WF4 — Price groups | M · Sonnet 5.5 | WF1 | `price/**`, `sync/**`, `diagnostics/**`, `admin/shared/**` | §8, D7. Accept: a whole group reaches the pusher; differing prices in a single-price group are not sent and each member shows the reason |
| WF5 — Token-only account, 4xx surcharge | S · Haiku 5.5 | — | `account/**`, `settings/class-accounts-sections.php`, `governor/**` | §8. Accept: an account with one secret saves and is «configured»; a reported 4xx draws `cost_4xx` |
| WF6 — Order unit actions | L · Sonnet 5.5 | — | `admin/order/**`, `admin/pages/orders/**`, `admin/shared/**`, `orders/**` | §8. Accept: an adapter-declared action appears in the table, the details box and bulk, and is refused server-side when its status does not allow it; cancel without reasons; a cancelled unit is its own line; Ozon cancel with reasons unchanged; HPOS and legacy storage |
| WF7 — Sticker documents | M · Sonnet 5.5 | WF6 | `flows/class-label-*`, `admin/order/class-label-button.php` | §8. Accept: several PNG → one printable sheet; ZPL and SVG downloaded under real names; Ozon PDF unchanged |
| WF8 — Shipments page «Поставки» | L · Sonnet 5.5 | WF6 | new `shipments/**`, `admin/pages/shipments/**`, `data/**`, `jobs/**`, `class-marketplace-plugin.php` (page offer) | §6.2, §8. Accept: fake adapter runs create → add → options → missing list → close → document; a table read makes no remote call; no page without the capability; the «Заказы доставки» counterpart named and matched |
| WF9 — Seller-delivered orders | M · Sonnet 5.5 | WF6; the DBS gate | `orders/**`, `flows/class-wc-order-store.php`, `admin/order/**`, `admin/pages/orders/**`, `deletion/**` | §7.2, §8. Accept: address, shipping line and paid mark written; address and phone editable, items locked; scheme filter; personal data exported and erased |
| WF10 — Carrier bridge | S · Sonnet 5.5 | WF6, WF9, WD1 | new `flows/class-carrier-bridge.php`, `class-flow-wiring.php` | §7.5. Accept: an exported carrier shipment triggers the mapped seller action once; a failed call is retried by the module's job; nothing happens for an order of another marketplace or a shop order |
| WF11 — Neutral wording, contract cleanup | S · Haiku 5.5 | WF6, WF8 (shared files) | strings in `admin/**`, `deletion/**`, `settings/**`, `capabilities/**` | §8. Accept: a test pins «no marketplace name in a framework msgid»; nouns from the descriptor; Ozon change named |
| WD1 — Delivery for an existing order | L · Sonnet 5.5 | the owner's word (T2); the DBS gate | `woodev/shipping-method/admin/orders/**`, `src/shipping-orders-page/order-wizard/**` | §7.5. Accept: a foreign order gets a carrier line through the wizard and becomes a row; its items and totals are untouched; existing create and edit behave as before (their tests unchanged); classic and HPOS |

### Plugin (`woocommerce-wildberries-seller`)

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| WP1 — Skeleton and connection | M · Sonnet 5.5 | WF5 | §9.1. Accept: a Base token, a read-only token and each missing category explained in plain words; the token never in a log or the diagnostics report |
| WP2 — Catalogue, mappings, validation | M · Sonnet 5.5 | WP1 | §9.2. Accept: subject search, required and limited characteristics, every rule with its merchant reason |
| WP3 — Cabinet cards: list, link, import | L · Sonnet 5.5 | WP1, WF3 | §5.3–5.4, §9.3. Accept on the live cabinet (read-only): the dormant catalogue lists, links and imports; a colour × size group → one variable product |
| WP4 — Create and update cards, media | L · Fable 5.2 | WP2, WP3, WF2 | §5.5, §9.3. Accept: create on the test cards; photos confirmed by read-back, a lost job retried; an update keeps every cabinet-only field (read-back diff empty); no delete call exists in the client |
| WP5 — Stock and warehouses | M · Sonnet 5.5 | WP3 | §9.4. Accept: the payload contract test; on the live cabinet a push of 0 and its read-back; formula from M0b 12 |
| WP6 — Prices | M · Sonnet 5.5 | WP3, WF1, WF4 | §9.5. Accept: price and discount on a test card settle through WF1; quarantine shown; the size-price path where allowed |
| WP7 — FBS orders | L · Sonnet 5.5 | WP3, WF6 | §6.1. Accept: grouping in every arrival order, both status axes, unit cancel, both order storages; fixtures from M0b 12 |
| WP8 — Supplies and stickers | M · Sonnet 5.5 | WP7, WF7, WF8 | §6.2–6.3. Accept: the `Shipment_Batches` half against fixtures; mixed cargo types refused locally; sticker sheet printed in a real browser |
| WP9 — Marking codes | M · Sonnet 5.5 | WP7, WP8 | §6.4. Accept: scanner input normalised; every `sgtin*` state has a Russian sentence; the «чего не хватает» list names the kinds 1.0 does not enter; documented refusals from fixtures — a sandbox delivery is not evidence |
| WP10 — DBS orders | L · Sonnet 5.5 | WP7, WF9, WF10; the DBS gate | §7. Accept: the first payload stored before any action; phone rule of §7.3; every action of §7.4 with its status gate; a carrier export moves the order to `deliver` |
| WP11 — Acceptance and release materials | M · Haiku 5.5 | all | Rig and live-cabinet acceptance in the owner's window; wiki; merchant docs for a WordPress novice; the release materials package. The plugin description states what marking kinds are not entered (D4) and anything still unproven |

### Order and parallelism

**Demand orders the work (coordinator, 26.11.2026).** Support counted direct requests in all 4444 tickets: Wildberries
was asked for by 4 customers in 5 requests, and every one of them asked for **product export and stock
synchronisation**; nobody asked for orders or prices. One of them offered his live Wildberries account for tests. This
changes no owner decision and no scope — it sets the order in which cards are taken:

| Wave | Cards | The merchant gets |
|---|---|---|
| 1 — products and stock | M0a, S0, WF5, WF3, WF2 → WP1, WP2, WP3, WP5, WP4 | connection, link and import of existing cards, stock sync, creation of cards from products |
| 2 — prices | WF1, WF4 → WP6 | prices with honest results |
| 3 — FBS orders | WF6, WF7, WF8, WF11 → WP7, WP8, WP9 | orders, supplies, stickers, marking |
| 4 — DBS (behind the gate) | M0b, WF9, WD1, WF10 → WP10 | orders the seller delivers |
| 5 | WP11 | acceptance and the release |

Within wave 1 the first usable state is **WP1 + WP3 + WP5** (link existing cards and sync stock); WF2 and WP4, the two
hardest cards, follow on the one Fable 5.2 slot. Waves 2 and 3 have no dependency on wave 1's framework cards and start
as soon as workers are free, but never ahead of a wave-1 card that is ready to be taken. M0b is asked of the owner
early, because its answer needs his time, not ours. Whether wave 1 alone is released first is the owner's call; the
plan only makes it possible.

```text
M0a ──► S0 ──► WF5 ─► WP1 ─► WP2 ─────────────┐
   │           WF3 ─► WP3 ─► WP5              ├─► WP4 (after WF2)
   │           WF1 ─► WF4 ─► WP6              │
   │           WF2 ───────────────────────────┘
   │           WF6 ─► WF7 ─► WP7 ─► WP8 ─► WP9
   │              └─► WF8 ───────────┘
   │              └─► WF11
   └─► M0b ─► S0 ─► gate ─► WF9 ─► WD1 ─► WF10 ─► WP10
                                                    all ─► WP11
```

Framework releases at the seams: after WF5 (the plugin boots), after WF1–WF4 (catalogue and sync), after WF6–WF8 and
WF11 (FBS orders), after WF9–WF10 and WD1 (DBS). **Totals:**

| | L | M | S | Cards |
|---|---|---|---|---|
| Phase 0 | — | 2 | 1 | 3 |
| Framework (WF1–WF11, WD1) | 5 | 4 | 3 | 12 |
| Plugin (WP1–WP11) | 4 | 7 | — | 11 |

If the DBS gate closes: WF9, WF10, WD1 and WP10 leave (2 L, 1 M, 1 S), and 1.0 ships as FBS with 9 framework and 10
plugin cards.

## Related

- [Owner decisions](../research/2026-11-26-wb-seller/decisions.md) — D1–D11, final
- [Research index](../research/2026-11-26-wb-seller/README.md) — API map, gap map, Ozon differences
- [Marketplace module design](../wiki/marketplace-admin.md) — the module this plugin is built on
- [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md) — the marketplace layer in the framework
- [ADR-005](../adr/005-platform-v2-clean-break-policy.md) — installed-site data contracts
- [Marketplace card importer spec](2026-11-14-marketplace-card-importer.md) — groups into variable products
- [Marketplace admin UI spec](2026-11-15-marketplace-admin-ui.md) — the look of the pages
- [Marketplace bulk operations spec](2026-11-16-marketplace-bulk-operations.md) — «все по фильтру»
- [Admin order wizard design (#710)](2026-09-27-710-create-edit-order-design.md) — the wizard WD1 extends
- [Ozon Delivery v2 plugin design](2026-10-30-ozon-delivery-v2-plugin-design.md) — the shape of this spec
