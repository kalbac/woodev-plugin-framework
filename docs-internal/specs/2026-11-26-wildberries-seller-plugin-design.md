# Wildberries Seller 1.0 Plugin Design

**Date:** 2026-11-26  
**Status:** amended after the phase-0 measurement (§3), card S0 [#1997](https://github.com/kalbac/woodev-plugin-framework/issues/1997), 26.11.2026. Earlier amendments of the same day: the critic review of PR #1992 and the owner's answers on T1 and T2 (revision log at the end).  
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
**UNVERIFIED** (with the card that settles it), **interpretation** (a gap this spec closed; listed again in §13.2),
**MEASURED** (observed in a phase-0 call, live cabinet or sandbox:
[measurement.md](../research/2026-11-26-wb-seller/measurement.md), where the fixtures are named). The first draft held
no measurement; S0 folded phase 0 in and read the framework again at `bf19abeb` (2.1.21).

## 0. How to read this spec

§1 lists the binding decisions; nothing below reopens them. §2 is the product boundary. §3 is the phase-0 measurement
(two cards, run side by side on the first day), which comes **before** the implementation cards. §4 maps every capability to its seam. §5 is the unit model (a size
inside a colour card), §6 the FBS flow with supplies and marking, §7 the DBS flow, §8 the framework extensions, §9 the
plugin parts, §10 the admin pages, §11 the data model, §12 errors, limits and the test plan, §13 the registers
(unverified facts, interpretations, tensions between decisions and the owner's answers to them, known residual risks), §14 the
implementation card list, then the revision log.

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
| D1 | FBS **and DBS** in 1.0; a DBS order is an ordinary WooCommerce order with a shipping address that CDEK / Yandex can ship. **Owner, 26.11.2026 (answer to T1): DBS stays in 1.0.** No real order can be made, so the two checks (relay phone, confirmation code) are done from the API documentation, simulated requests, fixtures and the sandbox, and what stays unproven is a stated residual risk — not the move-to-the-next-release fallback. No DBW, in-store pickup, FBW | §3.2–3.3, §7, §13.4 |
| D2 | Link, import Wildberries → WooCommerce, create cards from products; an edit changes only the plugin's own fields (read first); no delete, no trash | §5, §9.3 |
| D3 | Page «Поставки»: create, add orders, stickers, pickup point and date, cargo places, hand over, QR. No passes, no auto-returns | §6, §8 WF8 |
| D4 | «Честный знак» code field in the order (typed or scanned) and a «what is missing» list in the supply; other code kinds named, not entered; phase 0 checks the cabinet route | §3.2, §6.4 |
| D5 | The seller's personal token; no question to Wildberries support | §9.1 |
| D6 | The live cabinet is the test ground, writes included; never delete anything; stocks stay 0; the tokens live in the plugin folder's `.env` (§3 rule 3) and are never printed. **Owner, 26.11.2026: no real orders on the live cabinet at all** — order and supply flows, FBS and DBS, are exercised with the sandbox token; the cabinet's standing with Wildberries may make it refuse some writes, and phase 0 measures which | §3, §12.4 |
| D7 | Per-size prices where Wildberries allows; otherwise, when the sizes' shop prices differ — do not send, show the reason at the product | §8 WF4, §9.5 |
| D8 | Default warehouse per account, own warehouse per product, one product → one warehouse; a warehouse serves one scheme | §9.4 |
| D9 | Menu: Товары, Заказы (FBS and DBS, filter by scheme), Поставки, Сопоставление, Инструменты, Диагностика, Настройки; shared «Аналитика» as it is | §10 |
| D10 | One WooCommerce order per buyer cart per warehouse; a cancelled unit is its own line | §6.1, §8 WF6b |
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
orders, supplies and stickers · «Честный знак» codes · DBS orders with the carrier bridge ·
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

**Done on 26.11.2026** ([#1995](https://github.com/kalbac/woodev-plugin-framework/issues/1995),
[#1996](https://github.com/kalbac/woodev-plugin-framework/issues/1996)): the results are in
[measurement.md](../research/2026-11-26-wb-seller/measurement.md), §13.1 gives the status of every unverified item, and
§3.4 lists what changed in this design. §3.1 and §3.2 stay as the record of what was asked; the rules 1–4 below and
§12.4 keep binding every later card that touches the live cabinet.

Two measurement cards: **M0a** on the live cabinet, without orders; **M0b** on the sandbox and from documentation, for everything that involves an order. No real order is made anywhere (owner, 26.11.2026). Together they produce
`docs-internal/research/2026-11-26-wb-seller/measurement.md`, in which every UNVERIFIED item of this spec and of the
research is marked **proven**, **refuted** or **still open**, each with the request shape and the answer (no token, no
seller name, no buyer data in the file).

**Rules every brief of this card repeats, word for word:**

1. Never delete anything in the cabinet: no `DELETE` request of any kind, no «move to trash», no supply removal, no
   stock-record removal, no label-identifier removal.
2. Stocks on the live cabinet stay 0. A non-zero stock is set only for a short check, only after the owner's explicit
   word for that check, and is returned to 0 in the same session. No such check is planned: no real order is possible
   on this cabinet (§3.2).
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
| 3 | `cards/list` over the dormant catalogue: real `vendorCode`, sizes, `skus`, `imtID` groups, `needKiz`, `editableSizePrice` | §5; how many cards are single-size |
| 4 | Create **at most three** test cards (one single-size, one two colours × two sizes, one in a subject with size prices if the token's catalogue has one), named so that a human sees they are tests; time to `nmID` / `chrtID`; `cards/error/list` after a deliberately invalid field **validated locally first where possible** | G 11, G 10; barcode formats generated |
| 5 | `cards/update` on a test card: change one own field, confirm by read-back that cabinet-only fields survive; try `vendorCode` change | D2; `RENAME_SKU` (API §4 UNVERIFIED) |
| 6 | `media/save` on a test card; read back | §9.3 media job |
| 7 | Price task on a test card: status history, time to «done», integer and percent rounding of `discountedPrice`, a sharp drop → quarantine, per-size price where allowed | G 18–19, D7, §9.5 |
| 8 | `PUT /stocks` with amount **0** and read-back; the «unvalidated parameter name» trap | G 15; rule 2 keeps this the only stock write |
| 9 | Warehouses and offices list; which `deliveryType` values exist in this cabinet | D8 |
| 10 | Create one supply, read its shipping points, leave it empty | D3; whether an empty supply expires by itself (it cannot be deleted — rule 1) |
| 11 | 429 and 4xx accounting: read `X-Ratelimit-*` on ordinary calls; **do not** provoke a 429 | §12.2 |
| 11a | **Refused writes.** The test cabinet may not be in good standing with Wildberries (owner, 26.11.2026), so a write may be refused (402 or a business refusal). Each write of items 4–10 is tried once; a refusal is recorded with its status and text and **not retried** (a refusal costs ten requests). The measurement file lists which writes the cabinet accepts | which of WP4–WP6's live acceptance steps are possible on this cabinet; the rest move to the sandbox |

### 3.2 M0b — orders, without a real order

Self-purchases are forbidden by Wildberries and the test cabinet cannot receive orders at all (owner, 26.11.2026). So
nothing below is a live order. The sources, in the order of trust: Wildberries' OpenAPI text and knowledge base;
the **sandbox** with `WB_TEST_TOKEN` and its buyer-emulation methods; the carriers' own API documentation and our
delivery plugins' code; fixtures built from those. The sandbox proves request and answer shapes only — marking is
optional there, and its phones and codes are test data (API §3).

| # | Measure | How | Settles |
|---|---|---|---|
| 12 | An FBS order: fields, `requiredMeta` / `optionalMeta`, both status axes; whether the seller-warehouse stock goes down by itself | sandbox: set stock on a sandbox card, emulate an order, read the stock back | §6.1; a hint for the stock formula (§9.4) — the sandbox's answer is not proof of production |
| 13 | Order → supply → sticker (formats) → shipping method → hand over → QR | sandbox | §6.2–6.3; fixtures for WP7, WP8 |
| 14 | **Marking (D4):** write a «Честный знак» code, read its validation state; the refusal of a hand-over with a code missing; whether the cabinet lets a human enter each code kind | sandbox for the calls; documentation and the knowledge base for the refusal and for the cabinet route | §6.4; fixtures for WP9. The production refusal and the cabinet route stay unproven (§13.4 R3) |
| 15 | **DBS check 1 — relay phone (D1):** which of `replacementPhone`, `phone` + `phoneCode`, `additionalPhones` come back and when; whether CDEK and Yandex accept such a number as the recipient | sandbox DBS order for the shapes; the carriers' API documentation and the phone validation in our CDEK and Yandex plugins, run against the documented number formats | §7.3. Whether a carrier's SMS and courier call reach the buyer through the relay cannot be shown without a real order (§13.4 R1) |
| 16 | **DBS check 2 — confirmation code (D1):** `receive` with a code, without one, with a wrong one; where the buyer sees the code | sandbox emulation; documentation | §7.4. Whether production requires the code stays unproven (§13.4 R2) |
| 17 | The first-hour buyer cancellation of an unconfirmed order | sandbox emulation, if it offers one | §6.1 |
| 18 | Sandbox request / answer pairs saved as fixtures, test data only | — | the plugin's tests |

### 3.3 DBS stays in 1.0; what the checks are for

The owner's first decision made DBS conditional on two checks with a real order. His answer of 26.11.2026 replaces
that: **DBS is in 1.0 whatever M0b shows**; no card leaves the plan. The checks are still made (items 15–16), because
their result shapes §7.3 and §7.4, and what they cannot show is carried as a stated residual risk (§13.4) and said in
the plugin description and the merchant docs. The design of §7 is therefore written to work in **both** outcomes of
each check, not to depend on one.

M0a unblocks the catalogue, price and stock cards; M0b unblocks the order cards' acceptance. Neither waits for the
owner.

### 3.4 What phase 0 changed in this design

| Finding (measurement.md) | Source | Change | Where | Card |
|---|---|---|---|---|
| A read after a write can return an older version of a card, even after a newer one was seen; an update built from it silently undid an earlier write | live, item 5 | A freshness gate before every update | §5.6 | WP4 |
| An error-list entry outlives the corrected request | live, item 4 | No entry finalizes a send; the card list decides | §5.6 | WP4 |
| `vendorCode` can be changed; `nmID`, `chrtID` and barcodes stay | live, item 5 | Resolve by `chrtID` first; a collision component in the unit key; no rename from the shop in 1.0 | §5.1 | WF3, WP3 |
| A sizeless card carries one size `techSize: "0"` | live, item 4 | That pair is the unnamed size | §5.1 | WP3 |
| Wildberries' own barcodes are 13-digit restricted-circulation codes with a valid check digit | live, items 3–4 | They are never written to the GTIN field | §5.2 | WF3 |
| New cards got a `documents_missing` verdict within seconds | live, item 4 | The verdict is shown at the product | §9.3, §8 | WF12 (new), WP4 |
| A discounted price keeps kopecks; a sharp drop is either quarantined or refused | live, item 7 | Rounding rule; two distinct outcomes | §9.5, §8 | WF1, WP6 |
| The sandbox lowers stock on an order and does not give it back on cancel | sandbox, item 12 | `wc_only` confirmed; a cancelled unit makes the stock due again | §9.4, §8 | WF6b |
| A relay number may come without `+`; one carrier plugin passes it on as is | documentation and our plugins' code, item 15 | The order gets the `+7…` form | §7.3 | WP10 |
| DBS statuses answer per unit, a refusal inside HTTP 200; `reject` takes the code too | sandbox and documentation | Results are read per unit | §7.4 | WF6a, WP10 |
| Marking: «optional» identifiers still matter for marked goods | documentation | Two levels in «чего не хватает» | §6.4 | WP9 |
| Shipping points and shipping method have no sandbox route and refuse an empty supply | live, item 10; sandbox | New residual risk R8 | §6.2, §13.4 | WP8 |
| No live write was refused | live, item 11a | R6 closed; WP4–WP6 live acceptance is possible (not size prices) | §12.4, §14 | — |

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
| Cancel without reasons, per unit | reasons are mandatory (G 24) | extend | WF6a |
| A cancelled unit shown as its own line without holding stock | a cancelled member is dropped from the order's lines and nothing shows it afterwards (critic's read of `orders/class-registry-order-writer.php:337-352`) | extend | WF6b |
| Seller-side status actions (DBS: confirm, deliver, receive, reject) | only cancel and label exist (G 25) | extend (L) | WF6a |
| Per-unit input on the order (marking code) | none (G 28) | extend | WF6a |
| Supplies: the FBS hand-over | `Capability::CONFIRM_SHIPMENT` declared, nothing consumes it (G 26) | extend (L) | WF8 |
| Stickers: one PNG / SVG / ZPL per unit | one PDF per task (G 27) | extend (M) | WF7 |
| Buyer and address on a DBS order | `Wc_Order_Store::set_buyer()` copies name, phone, city, region only (READ `flows/class-wc-order-store.php:444-477`) | extend (M) | WF9 |
| A marketplace order that the merchant must be able to ship | `Order_Screen` makes every marketplace order read-only (READ `admin/order/class-order-screen.php`) | extend | WF9 |
| Handing an existing order to a carrier | the delivery editor answers 404 for an order that is not a row of «Заказы доставки» (READ `woodev/shipping-method/admin/orders/class-order-editor.php:500-512`) | **extend in the shipping module** — owner's exception, §13.3 T2 | WD1 |
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

`offer_id` = `esc(vendorCode)` + `|` + `esc(techSize)` — **always both components and always the separator**.

- `esc()` writes `\` as `\\` and `|` as `\|` in **both** components, so the one unescaped `|` of a key is the
  separator and the key decodes back to exactly one pair.
- A size without a name is the empty second component: the article `coat` with an unnamed size is `coat|`.
- So the single-size article `coat|M` is `coat\|M|` and size M of the article `coat` is `coat|M` — two different keys.
  `vendorCode` is a free string of up to 72 characters and Wildberries does not forbid `|` in it (SOURCED), so this
  case is a valid cabinet, not a theory.
- **The key never depends on how many sizes the card has.** It is built from the unit's own article and size name when
  the link is made and is frozen from then on, like every article (`Article_Policy`). A card that gains a second size
  keeps its first unit's key; the new size gets its own. After creation a unit is found by `chrtID` (`remote_ids`), so
  a size renamed in the cabinet keeps its link and its old key.
- The column is `varchar(191)` (READ `data/class-schema.php:277`); the unique key is `(account_id, offer_id)` (READ
  `:300`). An escaped key longer than 191 characters is refused before any write, with a reason at the product.
- **A card without sizes** carries one size with `techSize: "0"` and an empty `wbSize` (MEASURED). Exactly that pair is
  the unnamed size, so its key is `coat|` (**interpretation**, §13.2 I11).

**The article can change under a link.** `cards/update` accepted a new `vendorCode` and kept `nmID`, `imtID`, `chrtID`
and the barcodes (MEASURED), so a merchant can rename in the cabinet. The module joins the cabinet card list to links by
`offer_id` (READ `data/class-wpdb-card-store.php:579`). The adapter therefore resolves **every inbound reference** — a
listed card, an order unit, a stock or price read — by `chrtID` first and answers with the link's stored key; a key is
built from the current article only for a `chrtID` no link carries. If that built key already belongs to a link with
another `chrtID` (a new card took a freed article), a third component is appended — `esc(vendorCode)|esc(techSize)|chrtID`
— so that the new card can never pass for the old link. «Артикул» always shows the current `vendorCode`. **1.0 does not
change an article from the shop** (the adapter does not report `Capability::RENAME_SKU`): the module renames one link at
a time, while a Wildberries article is shared by all sizes of a colour (**interpretation**, §13.2 I12; tension T6).

The unit key is never shown: WF3 lets the adapter say what the «Артикул» column shows (`vendorCode`) and adds the size
beside it. WF3 pins the encoding with a fake adapter and WP3 with Wildberries data: the collision pair above, a
separator and a backslash inside each component, the unnamed size, a second size added to a single-size card, and the
round trip key → pair → key. WP3 adds the rename cases: a renamed card stays linked, and a freed article taken by a new
card gets its own key.

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
  of length 8, 12, 13 or 14, with a valid GS1 check digit, **and not a restricted-circulation number**, and only the
  first such value of the size. A restricted-circulation number identifies nothing outside one company or region and is
  not a GTIN: a 12-, 13- or 14-digit value whose 13-digit form (a 12-digit value gets a leading `0`, a 14-digit value
  loses its first digit) starts with `0000000`, `02`, `04` or `2`, or an 8-digit value starting with `0` or `2`
  ([GS1 prefixes](https://www.gs1.org/prefixes)). Such a value is still kept whole in `link_barcodes`. This is not an
  edge case: every barcode of the existing cards was 13
  digits starting `20`, generated ones start `206`, and both have a valid check digit (MEASURED).
- **Export:** the profile's existing barcode source (none, GTIN, SKU, custom field — G 6) gives at most one barcode per
  size; with «none» Wildberries generates one, and the poll stores what it generated. Sent `skus` are kept as sent
  (MEASURED). Wildberries' separate `gtin` field («additional GTIN») is not one of the plugin's fields in 1.0 and is
  copied back unchanged by an update. Since 01.10.2026 Wildberries checks the GTIN of marked goods, blocks a card whose
  GTIN is missing or invalid, and does not let one GTIN sit in the barcodes of two cards (SOURCED); how such a block or
  refusal comes back was not observed.

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
single size — named or not — becomes a simple product. The unit key is never written as a SKU; WF3 lets the adapter
say what the import writes.

**The SKU the import writes, and what happens when it is taken.** The candidate is `vendorCode` for a card with one
size (named or unnamed) and `vendorCode-techSize` for a size of a multi-size card. The candidate is readable, **not
unique**: the article `coat` with size `M` and the single-size article `coat-M` both give `coat-M`, and so do `coat` +
`M-L` and `coat-M` + `L`. The module writes SKUs without a duplicate check, and current WooCommerce accepts a second
identical SKU at that layer, so the rule is explicit (WF3): before writing, look the candidate up
(`wc_get_product_id_by_sku()`); if another product that is not this unit's holds it, **the product is imported with an
empty SKU and a conflict is reported at it** («артикул … уже занят товаром …») — the reporting of §5.3's ambiguous
key. No suffix is invented: the merchant sees the clash and names the product himself. The link is unaffected, because
it rests on the unit key and `chrtID`, not on the SKU. WF3 and WP3 pin both collision pairs and the
single-named-size shape. Photos are per `nmID`: the colour's first photo becomes the image of each of its
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

1. read the current card and refuse a stale version (§5.6);
2. replace **only the fields the plugin owns** — the ones the mapping and profile fill (title, description, brand,
   dimensions, the mapped characteristics) and new sizes — and copy every other field of the read card back unchanged,
   `documents` and `vendorCode` included;
3. write, then read back and compare, ignoring a read older than this write; a difference in a field the plugin does
   not own is logged as an error and shown in diagnostics.

A characteristic the merchant filled in the cabinet and did not map stays the cabinet's. In the test a description-only
change left every other field the card held unchanged, photos included (MEASURED); tags, video, attached documents and
hand-set cabinet fields were not on that card (open). A characteristic Wildberries has retired may be dropped silently,
with 200 and no error entry (SOURCED) — the read-back of step 3 is what shows it. Photos are replaced only by the
merchant's explicit action («Обновить фото»), because `media/save` replaces all media of the card. No operation of the
plugin deletes or trashes a card.

### 5.6 Reading results: a read may be older than a write

MEASURED on the live cabinet: the card list showed a change, four seconds later returned the previous version with the
older `updatedAt`, and an update built from that read put the old description back. Both requests answered 200, and old
versions were still served 45 seconds after the second write
([gotcha](../gotchas/wb-cards-list-serves-an-older-version-after-a-write.md)). The adapter therefore keeps one **write
record per card** (§11): the newest `updatedAt` any read has returned, what was last sent and when, and the latest
error-list text for the article (**interpretation**, §13.2 I13).

**The record exists before the card has an `nmID`.** A card to be created gets an `nmID` only when Wildberries lists it,
and a refused one never does: the measured refusal was HTTP 200 with empty `data`, followed by an error-list entry that
names the `vendorCode` and a batch id and no `nmID` (`w02-card-upload-invalid`, `w02-error-list`). So:

- the record is created **before the first request**, keyed by the account and the `vendorCode` as sent, `nmID` empty;
- before every request it stores the send time — of the latest request, and of the first one not yet answered by a
  card or by a refusal of the request itself;
- when the card list shows this create's card, its `nmID` is attached to the same record. From then on the record is
  found by account and `nmID`, and a cabinet rename (§5.1) changes its `vendorCode`;
- a card listed under that article can be this create's only when its `createdAt` is not older than that first send,
  less a few minutes' allowance for the difference between the site's clock and Wildberries' (measured creation:
  1–7 seconds after the request). The test only rejects — passing it does not prove that the plugin created the card.
  An older card means the article was already taken in the cabinet: it is never attached, by the poll or by
  `find_cards()`, and the product says that its article belongs to another card (**interpretation**);
- a lookup goes by `nmID` when the shop knows it — from the link, or, for a new size of an existing card, from the link
  of a sibling size — and otherwise by `vendorCode` among the records without `nmID`. There is at most one such record
  per account and article, so a corrected re-send reuses it.

The module needs no change for this (READ `flows/class-export-service.php`, `jobs/class-export-batch-runner.php`,
`jobs/class-poll-task-runner.php`). Before the first request each size already has its link — the unit key from
`Export_Facts::offer_id_for()`, no remote identifier — and the task lists link id → unit key; an empty answer of
`submit()` marks the task accepted, to be read by those keys (#1999). `read_import_by_articles()` gets the account, the
unit keys and a context only: for each key the adapter finds the record by the lookup above (the article is decoded from
the key) and matches Wildberries' cards and error entries on the record's `vendorCode`. A final answer for a listed card
carries `nmID`, `imtID` and `chrtID`, which the poll writes onto the link; the card is then followed by `chrtID`
(`card_id_name()`, one per size). A refusal of the request itself (`submit()` fails) fails the link with Wildberries'
text; a refusal that shows only in the error list does not (below). Either way the record of a refused create stays
without `nmID`.

The rules of an update:

- writes to one card are serialised;
- an update is never built from a read older than the newest `updatedAt` seen for the card;
- while the last write is pending — shown by no read and not retired (below) — the next update of that card waits;
- one read that shows a change does not prove that the next read will;
- «wait» is answered through the existing contract: `submit()` fails the batch with a retryable `Remote_Error` marked as
  not sent, so the export job retries it on its ladder (1, 3, 7, 15 minutes) and gives up at its fifth attempt with a
  reason at the product. The whole batch waits; no framework change.

**A write nobody waits for any more is retired.** An update Wildberries refuses, or silently drops (a retired
characteristic, §5.5), is never shown; left pending, it would block every later update of the card. So a write is
pending only while a size that carried it is still waiting for its result:

- a size stops waiting, and the record drops it for good, when its task fails it (at the deadline, or after the polls
  keep failing), when the merchant resets it («Сбросить состояние»), or when it reaches `submit()` again — the module
  queues no link that is still `submitted`;
- neither the deadline nor the reset calls the adapter (READ `jobs/class-poll-task-runner.php`,
  `data/class-wpdb-link-store.php`), so the adapter judges this at its next `submit()`: a carried link that is in the new
  batch, whose row is no longer `submitted`, or that is gone, is released; when none is left, the write is retired;
- retiring keeps the newest `updatedAt` seen: the next update is still never built from an older read;
- while another size of the card is still waiting, the write stays pending until that size is released too.

The gate protects against what the plugin has seen. A cabinet edit that no read has returned yet — made before or after
a retired write — can still be undone, residual risk R7; the plugin's own fields are sent again from the shop.

**Results.** A new card was listed 25–64 seconds after the request (MEASURED; Wildberries says up to 30 minutes). A
refusal appears in the error list within seconds, with the request's time as `updatedAt` — and **stays there after a
corrected request succeeded** (MEASURED, against the OpenAPI text).

**No error-list entry finalizes a send as failed** (**interpretation**, §13.2 I13). The upload answers with no batch or
task id, so nothing ties an entry to a request: one the plugin never read may be an old one, and comparing its time with
the site's clock cannot settle that. Measured: an entry of 01:15:51, the corrected create sent at 01:16:09, no card yet
at 01:16:17, the card listed at 01:17:00. So:

- a create is imported when its own card is listed (above), whatever the error list says;
- an update is imported only when a read shows what was sent — the card existing proves nothing;
- until then the task stays open. The newest entry's text for the article is shown at the product (WF12) as what
  Wildberries' error list says, not as the verdict on this send, and is cleared when the send is imported;
- what is still open at the adapter's `Import_Deadlines::task_deadline()` fails with the module's «no result» sentence
  (READ `jobs/class-poll-task-runner.php`); the note stays. WP4 picks the value: well above the 30 minutes Wildberries
  speaks of, and short enough that a refused create does not hang for a day;
- the module does not queue a submitted link again (READ `data/class-wpdb-link-store.php`), so a merchant who has
  corrected the product before that uses the «Товары сайта» action «Сбросить состояние» and exports again; a re-send of
  a create looks for the card first, and a corrected update goes through because the unshown write is retired. The
  products table lets the reset size be exported at once only when the plugin's lifecycle declares the marker
  `Releases_Reset_Imports` (#2040); without it the old task holds the size until it ends.

A card is found by its full article: a `textSearch` for a prefix returned nothing (MEASURED).

## 6. FBS: orders, supplies, marking

### 6.1 Orders

- **Ingest.** Polling only (API §11): `GET /api/v3/orders/new` and the statuses of open units by id, on the module's
  posting job (every 10 minutes, G 21). No buyer data exists for a Russian seller on FBS.
- **Grouping (D10).** Group key = `orderUid` + `warehouseId`. One WooCommerce order per group; **each live unit is its own
  line item with quantity 1**, even when two units are the same size, so that one unit can be cancelled, marked and
  stickered on its own (**interpretation**).
- **A cancelled unit (D10) is its own line, but not a WooCommerce line item.** WooCommerce reduces stock by a line's
  quantity, not by its price, so a cancelled unit left as an ordinary quantity-one item would keep its stock taken and
  would look shippable to a carrier. The module's existing rule therefore stays: a cancelled member contributes no
  live line (READ by the critic: `orders/class-registry-order-writer.php:337-352`), and the order store restores stock
  before it rewrites the remaining lines (`flows/class-wc-order-store.php:319-340`). What is added (WF6b): the unit's
  **snapshot** — product, size, article, price, unit id, the cancelling status and its time — is kept with the order,
  and the order details box and the «Заказы» table show it as a separate row «Отменено» beside the live items. The
  order total is the live units'; the order becomes cancelled only when every unit is.
- **Statuses.** `status_raw` = `supplierStatus/wbStatus` (the longest pair, `confirm/canceled_by_missed_call`, is 31
  characters). The adapter maps the pair to the module's canonical statuses and supplies its own status names and
  groups; the WooCommerce status follows real transitions only (module rule, #1920).
- **Cancel.** Per unit, no reason, `PATCH /api/v3/orders/{id}/cancel`, only while `isCancellable` (WF6a); what the order then shows and what happens to stock is WF6b. Wildberries penalises a seller's cancellation except for an order
  marked `isZeroOrder` (SOURCED, knowledge base): the confirmation text of the action says so. Measured statuses
  (sandbox): a buyer's cancel in the first hour gives `new/declined_by_client`, the seller's cancel `cancel/canceled`.
- **Money** arrives multiplied by 100 with a numeric currency code (MEASURED, sandbox). The sandbox's `address` and
  `ddate` of an FBS order are not production behaviour (§13.4 R5).
- **Stock.** Whether Wildberries lowers the seller-warehouse amount on an order cannot be measured on a real order
  (§3.2); the sandbox does, and does not give it back on cancel. The formula and why it is safe either way are in §9.4.

### 6.2 Supplies — the page «Поставки» (D3)

The merchant's day: orders appear → he selects them in «Заказы» → «Добавить в поставку» (a new or an open supply) →
enters the codes the supply lists as missing → prints stickers → chooses the pickup point and date → adds cargo places
where the supply needs them → «Передать в доставку» → prints the supply QR.

| Step | Wildberries (SOURCED, `03-orders-fbs`) | Notes |
|---|---|---|
| Create | `POST /api/v3/supplies` | One supply holds one cargo type; the page refuses a mixed selection **before** the request, with the reason |
| Add orders | `PATCH /api/marketplace/v3/supplies/{id}/orders`, ≤ 100 | Moves the units to `confirm`; from here stickers and codes are possible |
| Move a unit | `PATCH /api/marketplace/v3/fbs/supplies/{id}/orders/transfer` | Out of a closed supply into an open one; offered as «Перенести» where the status answer says `isTransferable`. No sandbox route |
| Pickup point and date | `GET …/shipping-points`, `PATCH …/fbs/supplies/shipping-method` | Required before hand-over (409 `SupplyShippingRequired`). No sandbox route; an empty live supply answers 409 `SupplyHasZeroOrders` (MEASURED), so the successful answer is unmeasured — §13.4 R8 |
| Cargo places | `…/supplies/{id}/trbx`, stickers per place | Add / list only; removing a place is a `DELETE` — a product function, never used on the test cabinet (D6) |
| Hand over | `PATCH /api/v3/supplies/{id}/deliver` | Refused while a required identifier is missing or unvalidated (409 `MetaValidationFail`); the page checks the «what is missing» list first and keeps the button off with the reason |
| QR | `GET /api/v3/supplies/{id}/barcode` | Only after hand-over |
| Re-shipment | `GET /api/v3/supplies/orders/reshipment` | Shown as a filter «Требуют повторной отгрузки» |

Not built: deleting a supply, passes, auto-return settings (D3). The page is a framework page (R1) — WF8.

Measured in the sandbox: create → add → stickers → hand over → QR, the supply's detail and list shapes, and the
re-shipment list (a unit left out of Wildberries' acceptance stays `complete/waiting` and is listed there). The sandbox
hands a supply over **without** a shipping method, so it proves nothing about the production refusal; cargo places were
not exercised. On the live cabinet a supply is created with all shipping fields empty; how long an empty supply lives is
still open.

### 6.3 Stickers

`POST /api/v3/orders/stickers`: ≤ 100 units, only in `confirm` / `complete`, PNG / SVG / ZPL, 58×40 or 40×30, one per
unit (API §7). The merchant gets **one printable sheet** (PNG stickers laid out on a page the browser prints) or a ZPL
file for a label printer, chosen in the account settings (sticker size and format — a business choice, not a technical
knob). WF7 carries several files per request and their real file names. A unit whose customs declaration number is
required and absent has no sticker (409 `CustomsDeclarationIsRequired`): the button is off with that reason. A
PNG 58×40 answer holds, per unit, `partA`, `partB`, a `barcode` and the picture in base64 (MEASURED, sandbox); the
supply QR is a barcode and a PNG.

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
- **«Чего не хватает».** The supply shows, per unit, each identifier that is missing or not yet accepted, with its
  state: filled and valid, waiting for validation (`pending`), refused with Wildberries' reason translated
  (`sgtinNotFound`, `sgtinRetired`, …), or **missing**. For the kinds the plugin does not enter in 1.0 (UIN, IMEI, GTIN,
  expiration date, customs declaration) the line names the kind and says where to enter it — the cabinet.
- **Two levels** (SOURCED; **interpretation** §13.2 I14 for how marked goods are recognised). *Required*
  (`requiredMeta`): the supply cannot be handed over without it, and the button stays off with the reason. *Needed for
  marked goods* — the card has `needKiz` and the order lists `sgtin` in `optionalMeta`: Wildberries lets the supply go,
  but says marked goods need every identifier from either list, and a returned unit without a code is refused at the
  warehouse or comes back at the seller's cost; the line warns and does not block. `optionalMeta` alone marks nothing
  (the sandbox lists every kind there for a T-shirt), and a DBS order has no `optionalMeta` at all, so there the level
  rests on `requiredMeta`.
- **Decisions** (SOURCED, `MetaDetails`). Passing: `filled`, `deadlineExceeded`, `optional`, `sgtinIntroduced`,
  `sgtinSoldB2B`. Blocking: `pending`, `required` and the `sgtin*` refusals listed in
  [measurement.md](../research/2026-11-26-wb-seller/measurement.md#marking--честный-знак-codes-spec-134-item-14). The
  sandbox keeps the GS separators on read-back, validates nothing and returns no `decision`, so the production read is
  built from the documented shape, not the sandbox's.
- **The merchant withdraws the code from circulation himself** in «Честный знак» when the goods leave, and returns it on
  a return (SOURCED, seller instruction); the merchant docs say so.
- **Whether the cabinet accepts those codes for an order run from the shop is not proven.** The official instruction
  confirms that a «Честный знак» code is added to the assembly order in the cabinet or the WB Partners app (M0b 14); its
  exact steps and the other kinds stay unread, and no real order exists to try them (§13.4 R3). If it turns out that the
  cabinet does not accept them, such goods cannot be shipped through the plugin in 1.0, and the plugin description and
  the merchant docs say so in plain words (D4).

## 7. DBS: orders the seller delivers

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

Measured in the sandbox: `orders/new` carries `address`, `groupId`, `orderUid` and `requiredMeta`, and no
`optionalMeta`; the paid delivery of a group is a ×100 amount with a currency code; the delivery date comes per unit as
a day with a from–to time. What a real pickup-point order carries was not observed (the sandbox filled a default
address). The seller delivers within 5 hours to 35 days, express within 4 hours (SOURCED, knowledge base).

**Interpretation.** `dbs` and `dbsPickupPoint` are both handled (a pickup-point order is the same flow with the point's
address and a PDF sticker from `POST /api/marketplace/v3/dbs/orders/stickers`, which needs a personal token — D5 gives
it). Express DBS has its own warehouse type (6); 1.0 does not list those warehouses, so the plugin sends no stock
there; an `edbs` unit that still arrives is shown and can be worked like `dbs`.

### 7.2 The WooCommerce order (D1 «ordinary order with a shipping address»)

| Field | Source |
|---|---|
| Lines | one per live unit; cancelled units as snapshots (§6.1) |
| Shipping line | «Доставка Wildberries» with the paid-delivery amount of the group; total = units + delivery |
| Shipping address | `fullAddress` into `shipping_address_1` as given, latitude and longitude in order meta; city, region and postcode are **not parsed from the string** — the carrier step of §7.5 resolves them through the delivery module's own address step |
| Name | `firstName` / `fullName` from the client call |
| Phone | §7.3 |
| Payment | paid through Wildberries: payment title «Wildberries», the order is marked paid, so a carrier never plans cash on delivery |
| Delivery date and time | order meta, shown in the details box and passed to the carrier step as a hint |
| Customer e-mail | none exists; WooCommerce customer e-mails have no address to go to |

A DBS order is the seller's to deliver, so it is not the read-only marketplace order of today: WF9 gives the module a
**seller-delivered** order policy — the shipping address, the phone and the shipping method are editable, the items,
prices and totals stay locked (they are Wildberries').

**Who owns a field after the first write (WF9).** Today every refresh copies the buyer fields again
(`Wc_Order_Store::save_changes()` → `set_buyer()`), which would undo a manager's correction. For a seller-delivered
order the rule is **seed, then leave alone**:

- The Wildberries payload is the source data and is kept whole in order meta; it is refreshed there on every poll.
- The order's own name, phone and shipping address are **seeded**: written when they are first known and the order's
  field is still empty — a field that already holds a value the writer did not write (a phone the manager typed before
  Wildberries assigned the relay number) is never seeded over (the name and
  phone arrive later than the address, §7.3). With each seeded value the writer stores what it wrote. A later poll
  writes a field again only if the order still holds exactly that stored value — i.e. nobody edited it — and
  Wildberries now gives a different one. A field a manager changed, or the delivery wizard resolved, is the
  merchant's from then on and no poll touches it; the details box still shows Wildberries' value beside it.
- **Two shipping lines, two owners.** «Доставка Wildberries» is the writer's line (marked in its meta): it carries what
  the buyer paid for delivery and only the writer changes it. The carrier's line that WD1 adds is the delivery
  module's: the marketplace writer never removes, replaces or re-prices a shipping line it did not create, and WD1
  never touches the writer's. The carrier line's total is 0 for the order — its quoted price is kept in its meta as
  the seller's cost — so the order total stays what the buyer paid through Wildberries.
- Items, prices, totals and statuses stay Wildberries' on every poll, as for any marketplace order.

Buyer data is personal data: it is covered by WordPress' export
and erase tools and removed by the module's account deletion.

### 7.3 The phone (check 1)

`replacementPhone` is a relay number for the buyer; `phone` is a backup relay number that needs the extension
`phoneCode`; there are additional numbers and codes; an empty value means «not assigned yet» (SOURCED). So a relay
number **without** an extension may exist. The documented examples differ in shape — `replacementPhone` is
`79871234567`, `phone` is `+79871234567` — `phoneCode` is an integer where `0` means «not assigned», and the sandbox
omits the arrays of additional numbers although the schema requires them. The design:

- **a relay number is written to the order as `+7…`**: an 11-digit number starting `7` gets the `+`, any other shape is
  written as it came. The plugin does this before the seed of §7.2, because the carriers differ: our CDEK plugin
  normalises every documented shape itself, our Yandex plugin passes the number on as it is, and Yandex's only
  documented example carries the `+` (M0b 15: the CDEK method was run, the Yandex sanitiser read; SOURCED);
- the order's phone is `replacementPhone` when present; the backup number and its extension are shown in the details
  box as «запасной номер, добавочный …» and never concatenated into the phone field; an absent or empty field is a
  number not assigned yet;
- the client call is repeated on the status poll while the number is empty;
- when only the backup number with an extension exists, the phone field gets that number and the order shows «для
  связи нужен добавочный …» — a carrier cannot dial an extension from an SMS gateway, and the manager sees that before
  he hands the order over;
- M0b 15 showed that CDEK accepts every documented shape; for Yandex it showed only that nothing in our plugin
  refuses one. Both carrier APIs have a field for an extension (`additional`, `phone_ext`), **but neither delivery
  plugin reads one**, so 1.0 does not pass the extension to a carrier (tension T7). **Not provable without a real
  order:** that the carrier's API accepts the number at run time and that its SMS and the courier's call reach the buyer
  through the relay (§13.4 R1). The merchant docs say it, and the order's delivery date, address and comment are passed
  to the carrier so that a delivery does not hang on one call.

### 7.4 Statuses and actions (check 2)

`new` → `confirm` → `deliver` → `receive` or `reject`; `cancel` from `new` or `confirm` only; Wildberries itself sets
`cancel_missed_call` (SOURCED). The status calls are batch calls under `/api/marketplace/v3/dbs/orders/status/` with a
limit of 1 request per second. `confirm`, `deliver` and `cancel` take `{ordersIds}`; `receive` and `reject` take
`{orders: [{orderId, code}]}` (SOURCED and MEASURED). The answer is **per unit** (`results[]` with `isError`): HTTP 200
may carry a refusal — `deliver` refuses a missing marking code that way, with `MetaValidationFail` and the decisions
(SOURCED) — so no action reads 200 as success, and a seller action of WF6a reports each unit's own outcome. Measured
end states (sandbox): `receive/sold`, `reject/canceled_by_client`, `cancel/canceled`, buyer's decline
`new/declined_by_client`.

| Action in the admin | Wildberries call | When offered |
|---|---|---|
| «Принять в работу» (row and bulk) | `status/confirm` | `new`. Until then the buyer may still cancel within the first hour; the plugin does not confirm by itself (**interpretation**, §13.2 I6) |
| «Оформить доставку» | — (§7.5) | `confirm`, no carrier shipment yet |
| «Передать в доставку» | `status/deliver` | `confirm`; sent automatically when a carrier export of the order succeeds |
| «Вручён» | `status/receive`, with `code` when given | `deliver`; the dialog has a field «Код подтверждения от покупателя», optional in the request schema |
| «Отказ при получении» | `status/reject`, with `code` when given | `deliver`; the same field — the knowledge base asks for the buyer's code on both |
| «Отменить» | `status/cancel` | `new`, `confirm`; no reason. The confirmation says a seller's cancellation is penalised (§6.1) |

**The last mile works in both outcomes of check 2** (whether production demands the buyer's code is not provable
without a real order — §13.4 R2):

- When the carrier reports «delivered» (WF10), the module sends `receive` without a code, **once**.
- If Wildberries accepts it, the order is closed with no work for the merchant.
- If it refuses, the order shows «Подтвердите вручение: нужен код покупателя» and waits for the manager's «Вручён»
  with the code, which the buyer sees on the Wildberries site and in the app and tells the seller or the courier. The
  refused attempt is not repeated (a refusal costs ten requests); the account remembers that this cabinet needs codes
  and stops the automatic attempt for later orders.
- **Not every refusal means «a code is needed».** The documented per-unit errors of `receive` — `NotFound`,
  `StatusMismatch`, `SGTINIsNotFilled` — show their own reason and teach the account nothing. How production words a
  missing code is unknown (R2); the sandbox answers 400 `IncorrectRequest` for the whole request, accepts any value
  as a code, and so cannot show a wrong one.
- Wildberries' own closing of the order (`sold` on the WB status axis) is picked up by the status poll in either case.

### 7.5 Shipping a DBS order with CDEK or Yandex

The delivery plugins work on orders that carry their shipping method; today nothing can give an existing order one:
«Редактировать» on «Заказы доставки» is for that page's rows only, and the WooCommerce editor cannot set a tariff or a
pickup point ([#710 design](2026-09-27-710-create-edit-order-design.md), O3, O12).

- **WD1 (shipping module).** A third entry into the same wizard: **«Оформить доставку» for an existing order**. It
  opens on the Address step with the order's address text, phone and name prefilled, lets the manager resolve the
  settlement and pick the carrier's tariff or pickup point, and saves the carrier's shipping line onto **the same
  order**. The Customer, Items and Payment steps are skipped; item prices and totals are not touched; the delivery price
  the carrier quotes is stored on the carrier's line as the seller's cost, the line adds 0 to the order, and the
  «Доставка Wildberries» line is left as it is (§7.2). From then on the order is a
  row of «Заказы доставки» and is exported, tracked and documented like any other. The entry is offered through a small
  registry in the shipping module, so that the marketplace module asks for it without the shipping module knowing any
  marketplace.
- **WF10 (marketplace module).** Listens to `woodev_shipping_order_exported` → sends `deliver`;
  `woodev_shipping_delivery_status_changed` → on «delivered» sends `receive` once and, if refused, raises «подтвердите
  вручение» (§7.4); on «returned / refused» offers `reject`. A failed call is retried by the module's job, never by the
  carrier's code. The carrier's tracking number is shown in the order details box.
- A merchant who delivers by his own courier, or with a carrier that is not ours, uses the buttons of §7.4 alone.

WD1 changes delivery code, which the marketplace round ruled out (B3; the s224 rule «delivery code is not touched»).
**The owner approved it on 26.11.2026 as an exception to that rule, for this card only** (§13.3 T2): the entry is added;
how existing rows are created, edited and exported does not change.

## 8. Framework extensions

Ozon Seller 2.0.0 is released on the module. Every card below: keeps Ozon's behaviour (its tests stay green, a fake
second marketplace proves the new path); changes the schema only additively with a `Schema::REVISION` step (now 4,
READ); names the Ozon adapter change it forces, which ships in the same framework release window; touches no file of
`woodev/shipping-method/**` or `src/shipping-orders-page/**` — WD1 is the single stated exception.

| Card | Problem | Design | Ozon |
|---|---|---|---|
| **WF1 — asynchronous price settlement** | A pending receipt is logged and counted as unanswered; nothing calls `Price_Pusher::settle()` (G 18) | As built (#1998, [wiki](../wiki/marketplace-admin.md#prices-the-marketplace-decides-on-later-1998)). A pending or duplicate receipt opens a **price task** in the ledger (upload id, the link ids, the intents' revisions). The poll job settles open price tasks: accepted → the intent is accepted; refused per item → rejected with the marketplace's text; **quarantine** → an item error with its own reason code and a merchant sentence («Wildberries держит новую цену в карантине: она намного ниже текущей» — no percentage, the threshold is set in the cabinet); deadline passed → expired and re-queued once. Deadline and poll cadence are adapter values. Quarantine and plain refusal are two outcomes of the same shape (§9.5); which is which is the adapter's reading. Neither is re-sent until the intent changes (**interpretation**, §13.2 I15) | Immediate path untouched |
| **WF2 — export of grouped cards without a task number** | Batches split sibling sizes; an empty task id is a failure and a retry, which would create the card twice (G 10–11) | As built (#1999), three optional interfaces ([wiki](../wiki/marketplace-admin.md#export-of-grouped-cards-and-marketplaces-without-a-task-number-1999)): (a) `Export_Grouping` on the export facts names an **export group** (offers of one product); the scheduler never splits it and the gateway receives it whole. (b) With `Article_Keyed_Lifecycle` an empty `submit()` answer is «accepted, no task id»: the task is read by `read_import_by_articles()`. (c) A re-send of a card with no known identifier first reads the card list (`find_cards()`); when the list lags and the re-send is refused because the article is taken — the classifier answers `Remote_Error::ARTICLE_TAKEN` (WP4 maps Wildberries' refusal to it) — the task waits for the card instead of failing. (d) `Import_Deadlines` makes both poll deadlines adapter values (document checks take up to 3 days, G 12) | None: `read_import()` is unchanged. A new argument on it would fatal the released Ozon Seller on a site where another plugin ships a newer framework ([gotcha](../gotchas/a-method-added-to-an-adapter-interface-fatals-released-plugins.md)) |
| **WF3 — size as the unit: match keys, barcodes, presentation** | G 7–9 | §5.1–5.4: adapter-declared match keys with conflict reporting; table `link_barcodes`; the GTIN rule, restricted-circulation numbers excluded; adapter answers «what the article column shows» (the current article, which may differ from the key) and «what the import writes as SKU»; the cards list groups unit rows under their card | Ozon declares one key (SKU = article) and shows the article as today |
| **WF4 — price groups** | One price per colour card unless size prices are allowed (G 19) | As built (#2001, [wiki](../wiki/marketplace-admin.md#one-price-for-several-links-price-groups-2001)): the optional interface `Price_Grouping` on the **price pusher** names the remote identifier that ties a group and whether a group allows per-member prices. The flusher hands the pusher whole groups (absent siblings are loaded). A group that does not allow per-member prices and whose members' computed prices differ is **not sent**; each member gets the reason code «у размеров разные цены, маркетплейс принимает одну» (`price_group_differs`) shown at the product and in diagnostics (D7). A group waits while one of its links waits for a price task; a refused or quarantined price is not re-sent, and in a one-price group it turns its siblings' same price down too | Ozon: every link is its own group; no change |
| **WF5 — token-only account and the 4xx surcharge** | The account needs a typed identifier; nothing consumes `cost_4xx` (G 3–4) | **Built (#2011, 2.1.21).** The adapter may derive the external id from the secrets (Wildberries: `sid` of the token), the settings form then shows no identifier field. `Request_Governor::report_client_error()` draws `cost_4xx − 1` more from the bucket; the adapter's client calls it | none |
| **WF6a — order actions** | Only cancel (with mandatory reasons) and label exist (G 24–25, 28) | (a) The adapter declares **seller actions**: id, label, the statuses it is offered in, whether it is per unit, per order or bulk, an optional input schema (a code, a supply choice), a confirmation text. The orders table, the order details box and the server-side gate (`Posting_Action_Gate`) read one declaration; a refused action stays visible, off, with its reason — the «Заказы доставки» mechanism. An action answers **per unit**, with each refusal's text (§7.4). (b) Cancel accepts an empty reason list. (c) A per-unit input seam (marking code) with adapter validation before any remote call. No change to how an order is stored | `Order_Canceller` contract: reasons become optional — through a new optional interface if its signature would change |
| **WF6b — order units and partial cancellation** | A grouped order's cancelled member vanishes from the order; nothing lists the units of an order (§6.1) | (a) One line item per live unit, each tied to its unit id. (b) A cancelled unit leaves the WooCommerce items through the existing path — stock restored for that unit only — and its snapshot is stored with the order (order meta through the adapter's meta contract, the same on both order storages). (c) The details box and the «Заказы» table children list every unit, live and cancelled, with its own status. (d) A repeated poll of an already cancelled unit changes nothing: stock is restored once. (e) A unit that turns cancelled — one first seen already cancelled included — makes its stock pair due again even when the number did not change (§9.4) | none: an Ozon posting is cancelled whole |
| **WF7 — sticker documents** | One PDF per task (G 27) | A label answer may hold several files with their media types; PNG stickers are laid out on a printable sheet, ZPL / SVG are downloaded under real names; a synchronous answer needs no task | none |
| **WF8 — shipments: the page «Поставки»** | `CONFIRM_SHIPMENT` is declared, nothing consumes it (G 26) | A domain contract `Shipment_Batches` (list, create, add / move units, options to choose — pickup point and date, cargo places, close, documents, the «what is missing» list) and a records page `shipments` that the base class offers when the adapter supports `CONFIRM_SHIPMENT`. Framework owns the table (a batch row with its units as children — the variable-product mechanism), the row and bulk actions through WF6a's declarations, a local snapshot table refreshed by a job (a table read never calls the marketplace), progress and locks. The nouns («Поставки», «поставка», «грузовое место») come from the settings descriptor | Ozon does not declare the capability; no page appears |
| **WF9 — seller-delivered orders** | The module knows only orders the marketplace delivers | `Order_DTO` carries a delivery scheme and a full address (text, coordinates, pickup-point flag); `Wc_Order_Store` writes the shipping address, the shipping line with the paid-delivery amount and the paid mark; the order screen applies the seller-delivered policy of §7.2, and the writer its seed-then-leave-alone rule (seeded values remembered, a foreign shipping line never touched); the «Заказы» table gets the scheme filter and column (D9) when the adapter declares more than one scheme; personal-data exporter and eraser | none (one scheme) |
| **WF10 — carrier bridge** | — | §7.5: listeners on the two shipping actions, mapped to WF6a seller actions by an adapter-declared table; the button «Оформить доставку» shown when WD1's entry exists and a delivery plugin is active | none |
| **WF11 — neutral wording and contract cleanup** | «Ozon» in a mapping sentence; «отправление» in 35 strings; export gated on `IMPORT_PRODUCTS`; dead `order_source()` / `card_exporter()` (A1, A2, A10) | As built (#2009, [wiki](../wiki/marketplace-admin.md#wording-and-the-catalogue-of-a-second-marketplace-2009)): an optional descriptor interface `Posting_Wording` supplies the noun; a test pins «no marketplace name in a framework string»; the catalogue gate has its own `Capability::CATALOG`; the two dead contract methods are deprecated, not removed | None: «отправление» stays the default and `IMPORT_PRODUCTS` still means a catalogue |
| **WF12 — a note on a card, at the product** ([#2017](https://github.com/kalbac/woodev-plugin-framework/issues/2017)) | A card the marketplace accepted may still carry a verdict the merchant must act on (§9.3). A card's status text shows only on the cabinet-card list; the product shows the link's error, which an accepted card does not have and later jobs overwrite (READ `jobs/class-poll-task-runner.php`, `admin/pages/products/class-site-products-reader.php`) | As built (#2017, [wiki](../wiki/marketplace-admin.md#a-note-on-a-card-at-the-product-2017)): an **optional** `Link_Notes` beside the adapter — a note per link (text, tone), read from what the plugin stored, asked once per screen read, shown on the product tab, in «Товары сайта» and in the cabinet-card badge tooltip; a warning or an error also colours the badge. No remote call on a table read | none |
| **WD1 — delivery for an existing order** (shipping module) | §7.5 | The third wizard entry; the editable-state policy and the export path of existing rows are not changed | — |

## 9. Plugin parts

Namespace `Woodev\Wildberries_Seller`, include-based autoloader as in Ozon Seller.

### 9.1 Connection

- **One secret**: the personal token (JWT, about 180 days). Decoded **locally** on save: type from `acc` — 3 personal
  (`for: "self"`), 1 base, 2 test, 4 service (SOURCED; 2 and 3 MEASURED) — Base → refused with «нужен
  персональный токен»; Test → works only against the sandbox hosts, which a development constant switches on; refused
  otherwise; categories (bitmask `s`), the read-only bit, expiry — always read from `exp`, never computed (the measured
  token lived 182 days) — and the seller id `sid` (the account's external id, WF5; MEASURED equal to `seller-info`).
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
(G 12). `media/save` answers with a task id; both test photos appeared after 62–77 seconds (MEASURED), so a read-back
that finds none waits before it retries.

**Verdicts and waits.** New cards got `overallVerdict: documents_missing` within 6–11 seconds; cards, prices and stock
were still accepted (MEASURED), and since 01.10.2026 moderation **may block** a card that fails validation (SOURCED).
The adapter stores the verdict with the card and shows it at the product (WF12) with a merchant sentence that says what
to add in the cabinet. Wildberries says a price or stock cannot be set for up to 30 minutes after a card is created or
edited (SOURCED). The accepted live writes were sent 31 minutes or more after the last edit, so an earlier one is
untested; such a refusal is a wait, not a failure (§9.4, §9.5).

New cabinet cards are not announced to a personal token — webhooks exist (knowledge-base article of 16.09.2026), card
events among them, but only for a Service or Base token with a service secret, and none for orders (SOURCED) — so the
plugin polls and the «auto-import new cards» option is not offered (WF11).

### 9.4 Stock and warehouses (D8)

`PUT /api/v3/stocks/{warehouseId}`, ≤ 1000 `{chrtId, amount}`, absolute. The adapter lists the seller warehouses of
`deliveryType` FBS and DBS with the scheme in the name («Склад — FBS»); the merchant picks the account's default
warehouse and may override it per product — **the warehouse chosen is the scheme the product sells by**. One default
per account, as in Ozon Seller (**interpretation** of D8's «FBS and DBS have their own»: each scheme has its own
warehouses to choose from; a second default would have no consumer while one product feeds one warehouse — §13.2 I4).
A warehouse that is being processed or deleted (`isProcessing`, `isDeleting`) defers the push.

**The stock formula is `wc_only` — confirmed after phase 0 — and it does not depend on the unmeasurable fact.** The
push is absolute and the WooCommerce order that the plugin creates for a Wildberries order takes the unit from
WooCommerce stock. So after the order is ingested the plugin pushes «shop stock, already without that unit» — the right
number whether Wildberries lowered its own figure at the order or not. `wc_plus_reserved` would be right only if
Wildberries subtracted open orders from the pushed amount again; nothing in its OpenAPI text says so, the stock read
returns `amount` only, and the sandbox showed the opposite behaviour: an emulated order lowered the seller-warehouse
amount itself, with no reserved figure (MEASURED, sandbox). The window that remains is the time between the order at
Wildberries and its ingest (up to the 10-minute poll), the same as for Ozon Seller, which also runs `wc_only`. This is
residual risk R4 (§13.4): if a real shop shows double subtraction, the formula is one adapter value to change. WP5 is
therefore **not blocked by any measurement that needs an order**; its live acceptance is a push of 0 (D6).

**A cancellation must be pushed even when the number did not change.** In the sandbox a cancel did **not** give the unit
back: Wildberries' figure was still lowered about 7.5 minutes later. The module sends a pair only when its value differs
from the last accepted one (READ `stock/class-sync-state-queue.php`), so a unit cancelled before it was ingested — or
ordered and cancelled between two sends — would leave Wildberries one short until the daily reconcile finds the drift
and re-sends (READ `sync/class-stock-readback.php`). WF6b makes such a pair due at once. The error is on the safe side
(under-selling), and the write is harmless if production restores the figure itself.

**Reading and refusals** (MEASURED). A size never set is absent from the stock read, which is not the same as 0. A
`PUT` of 0 answers 204 and reads back 0. `chrtID` was accepted for `chrtId`, so the case of a name is not checked;
whether an unknown name is silently ignored stays open and the payload contract test and read-back stay. A refusal
because the card is not ready yet maps to the module's `NOT_READY` stock verdict (waits, not counted); its shape was
not observed.

### 9.5 Prices (D7)

`{nmID, price, discount}`: integer price and a whole-percent discount. From the module's price line: the base is the old
price when there is one, else the price; `price` = the base rounded **up** to a whole number; `discount` = the largest
whole percent for which `price` less the discount is not **below** the shop's selling price. Wildberries does not round
the discounted price to roubles — 999 less 33 % is 669.33, 1001 less 15 % is 850.85 (MEASURED) — so the comparison is
made in kopecks, and the read-back (`price_reader`) shows the real `discountedPrice` beside the shop price
(**interpretation**, §13.2 I8). Size prices go through `upload/task/size` where `editableSizePrice` is true (WF4); no
card of the live cabinet has it, so that path is accepted on fixtures.

Results through WF1. An upload was final 5–6 seconds after it was sent (MEASURED). A refused item arrives behind HTTP
200: upload `status: 6` (all failed; 5 partly), item `status: 3` with `errorText` (success: 3 and 2). **Two different
sharp-drop outcomes** exist: a moderate one goes to **quarantine** (measured at 1.67 times lower; the item is listed in
`quarantine/goods`, the old price stays), and a drop of more than twice is **refused** as an item error «New prices are
more than twice lower…» and is not in quarantine. An empty quarantine answers `data: null`. A new price releases a
quarantined one. The threshold is 33.3 % by default and set in the cabinet; size prices and promotion drops are exempt
(SOURCED). Quarantine is shown, never silently waited out; neither outcome is retried until the shop price changes
(§13.2 I15). A price for a card the prices service does not know yet (it was listed about 5 minutes after creation) is
answered with a wait (`retry_after`), not a failure.

### 9.6 Orders, supplies, marking, DBS

The adapter halves of §6 and §7: posting handler for both schemes, status composition and names, the seller-action
declarations, the `Shipment_Batches` implementation, sticker provider (FBS PNG / ZPL, DBS pickup-point PDF), the
marking validator and reason texts, the DBS client / date / paid-delivery readers, the carrier-event table of WF10.

## 10. Admin pages (D9)

All pages are the module's PHP-driven React pages; the plugin adds declarations, not screens.

| Page | Content | Wildberries specifics |
|---|---|---|
| **Товары** | «Товары сайта» and «Карточки на маркетплейсе» | A colour card is a parent row with its sizes as children; «Артикул» shows `vendorCode`, the size beside it; reasons at the product: differing size prices (D7), quarantine, missing size attribute |
| **Заказы** | FBS and DBS in one table | Filter and column «Схема»; a row is a WooCommerce order, its units are children; row and bulk actions from WF6a: «Добавить в поставку», «Стикеры», «Принять в работу», «Оформить доставку», «Передать в доставку», «Вручён», «Отказ», «Отменить» |
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

**Plugin-owned storage** (not the module's tables): the card write record of §5.6, one row per card, in the table
`woodev_wildberries_card_writes` — the account, the `vendorCode` as last sent, the `nmID` (empty until the card is
listed), the two send times of §5.6, what was last sent and the links still waiting for its result, the newest
`updatedAt` seen, the latest error-list text and the current verdict. An `nmID` is unique per account; the adapter keeps
at most one row without an `nmID` per account and article (**interpretation**; removed with its account, WP4).

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
| 200 with a per-item refusal (price upload item `status: 3`; DBS status `isError`) | business refusal, per item | the reason at the item |
| An error-list entry for an article being sent (§5.6) | not final: the task stays open, the card list decides | the entry's text as a note at the product |
| A stale card read (§5.6) | local, retryable, not sent | nothing; after the fifth attempt a reason at the product |
| A price or stock refusal because the card is not ready (shape not observed) | wait, not counted | the waiting text |
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
| FBS orders, supplies, stickers | 300 / min | 20 | 10 |
| FBS label identifiers — read | 300 / min | 20 | 10 |
| FBS label identifiers — write | 1000 / min | 20 | 10 |
| DBS label identifiers — read | 300 / min | 20 | 10 |
| DBS label identifiers — write | **500 / min** | 20 | 10 |
| FBS cancel | 100 / min | 20 | 10 |
| DBS orders (read) | 300 / min | 20 | 10 |
| DBS status changes | 1 / s | 10 | 10 |

FBS and DBS label identifiers are **separate method groups with different write limits** (SOURCED: FBS `PUT
/api/v3/orders/{orderId}/meta/*` 1000 / min; DBS `POST /api/marketplace/v3/dbs/orders/meta/*` 500 / min), each with
its own bucket; the DBS write is a batch call, so one request can carry many units. Removing an identifier has lower
limits still (DBS 150 / min) and is not used by the 1.0 flows.

The surcharge is stated by Wildberries for the Marketplace category: the OpenAPI text of every FBS and DBS order method
this plugin calls says one 4xx counts as 10. The knowledge base adds that a 409 counts as 5 **or** 10 depending on the
method group, without a split this spec could take over, and the one live sample (a 409) fits either 8 or 10 (open). The
table keeps 10: charging too much only slows the plugin. For Content and Prices it assumes 1; no 4xx occurred there
(open). Measured bursts in `X-Ratelimit-Limit` — content 5, prices 5, marketplace 20 — match the table. Because a
refusal costs up to ten (WF5): validate locally (marking format, mixed cargo types, status preconditions), read
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
refusals **from fixtures**, DBS address and phone mapping, the freshness gate against an older read, an error-list
entry that finalizes nothing, a refused create that has no `nmID`, an unshown update retired after a reset and after
the deadline, restricted-circulation barcodes kept out of GTIN, a renamed card and a reused article, the two price
refusals, a refusal inside a 200. The phase-0 pairs in `fixtures/m0a/` and `fixtures/m0b/` are the samples; where the
sandbox differs from the documented shape (measurement.md, fidelity gaps), the documented shape wins. Jest for the React changes; phpcs, phpstan, `lint:i18n`,
`lint:docs`.

### 12.4 The live cabinet (D6)

Acceptance and measurement run on the live cabinet under the four rules of §3, repeated in every brief. In addition:

- **What a test leaves behind is decided before the test.** Cards cannot be deleted (D2, D6): M0a created the three
  test cards — `WOODEV-TEST-A2`, `WOODEV-TEST-B-BLACK`, `WOODEV-TEST-B-WHITE`, stock 0, never on sale — and **the one
  empty supply**; every later card reuses them and creates neither. The cards are found by their full article.
- **Existing cards of the cabinet are read, linked and imported freely** (that changes only the rig). A **write** to an
  existing card — an update, a price, photos — is made only on the test cards, unless the owner names a card.
- **Prices** are written on test cards; a price changed on any other card is restored in the same session from the
  value read first.
- **Stock**: the only write is 0. No stock window is planned: no real order is possible on this cabinet (owner,
  26.11.2026).
- **Refused writes.** M0a 11a found none: card create and update, media, prices, zero stock and the supply were all
  accepted. If a later write is refused it is recorded, not retried, accepted on the sandbox instead, and the acceptance
  report says so.
- **Orders, supplies with units, stickers, marking, DBS are never exercised on the live cabinet.** They are accepted
  on the sandbox (`WB_TEST_TOKEN`) with emulated buyer actions and on fixtures. That proves the request plumbing
  only: marking is optional in the sandbox and a sandbox delivery is never evidence of the production refusal
  (API §3). What this leaves unproven is listed in §13.4 and goes into the release notes for the owner. The rules
  «never delete» and «stock 0» are the live cabinet's; the sandbox holds test data only.
- The rig that talks to the live cabinet keeps the module's first-remote-write confirmation on, and no agent runs
  «все по фильтру» against it.

Rig acceptance: the coordinator looks at every admin screen in a real browser before «ready»; the owner sees the UI
near the end.

## 13. Registers

### 13.1 Unverified items and their results

Every item the first draft left unverified, with what phase 0 answered. **proven** / **refuted** / **open**; the
evidence is in [measurement.md](../research/2026-11-26-wb-seller/measurement.md).

| # | Item | Asked by | Result after phase 0 | Still open → card |
|---|---|---|---|---|
| U1 | The stored token's type, categories and write access | M0a 1 | **proven**: personal; writes Content, Prices and Marketplace; not read-only; `exp` 182 days ahead (180 is not exact) | — |
| U2 | Webhooks do not exist; which methods are being replaced; how faithful the sandbox is | M0a 2, M0b | **refuted** for webhooks (they exist, not for a personal token, none for orders — polling stays); replaced methods **proven** for the entries read (02.10–25.11.2026): none the design uses; sandbox fidelity gaps **measured** | — |
| U3 | Card creation: time to ids, error-list behaviour, generated barcode format, `vendorCode` change | M0a 4–5 | **proven**: listed in 25–64 s; barcodes 13 digits, prefix `206`; `vendorCode` changes. **Refuted**: a corrected request does not remove the error entry; a read does not always show the latest write | whether a second failed request refreshes an entry → WP4 |
| U4 | Price task timing, rounding of the discounted price, quarantine threshold, size-price categories | M0a 7 | **proven**: final in 5–6 s; kopecks, not rounded; quarantine and a separate refusal; threshold 33.3 % by default (SOURCED) | exact boundaries; size prices — no such card → WP6, on fixtures |
| U5 | An empty supply's lifetime | M0a 10 | **open**: unchanged after 32 minutes | read-only look at the existing supply → WP8 |
| U6 | Wildberries lowers seller-warehouse stock on an order | M0b 12 | sandbox: **proven** — lowered at the order, not restored on cancel | production → §13.4 R4 |
| U7 | The production refusal for a missing code; code validation timing; the cabinet's manual entry per code kind | M0b 14 | documented decisions and 409 body (SOURCED); the sandbox validates nothing; the cabinet route for «Честный знак» is **proven** by the official instruction, its steps unread | production and the other kinds → §13.4 R3 |
| U8 | DBS relay phone: what is given, when, and whether CDEK / Yandex messages reach the buyer | M0b 15 | shapes **measured**; CDEK accepts every documented form; Yandex gets a number without `+` unchanged | delivery of calls and messages, run-time acceptance → §13.4 R1 |
| U9 | DBS `receive`: whether the confirmation code is required and who can know it | M0b 16 | the buyer sees it (SOURCED); the sandbox demands a code and accepts any value; the production schema leaves it optional | production → §13.4 R2 |
| U10 | Which live writes the test cabinet refuses | M0a 11a | **proven**: none | — |
| U11 | `refresh()` of a grouped order can find its member ids (G 22) | — | not part of phase 0, **open** | WP7 |
| U12 | Which separator substitutes real scanners send for GS | — | not part of phase 0, **open** | WP9 |
| U13 | Assembly deadlines and penalties (not in the OpenAPI files) | M0a 2 | **open**: not in the articles read (a secondary source says 120 hours); no 1.0 feature depends on it. A seller's cancellation is penalised (SOURCED) | — |
| U14 | The successful answers of the shipping-points and shipping-method calls | found in phase 0 | **open**: no sandbox route, 409 on an empty live supply | §13.4 R8 → WP8 |
| U15 | How a price or stock for a card that is not ready is refused | found in phase 0 | **open** | WP5, WP6 |
| U16 | An unknown stock field name answers 204 and does nothing | M0a 8 | **open** (proving it needs a non-zero stock); the contract test stays | — |
| U17 | What a Marketplace 4xx really draws; whether Content and Prices charge for one | M0a 11 | **open**: documented 5 or 10, one ambiguous sample; no Content or Prices 4xx occurred | the table keeps its values (§12.2) |

### 13.2 Interpretations (the coordinator may revisit)

| # | Interpretation | Where |
|---|---|---|
| I1 | Identity values: plugin id, text domain, namespace, menu id | §2 |
| I2 | A link is one size; the unit key is `esc(vendorCode)\|esc(techSize)`, frozen at linking; never shown | §5.1 |
| I3 | Card articles for a multi-colour variable product = parent SKU + colour value; the size attribute is named in the mapping | §5.5 |
| I4 | One default warehouse per account (not one per scheme) | §9.4 |
| I5 | One line item per live unit, quantity 1; a cancelled unit is a stored snapshot shown as its own row, not a line item | §6.1 |
| I6 | No automatic «Принять в работу» in 1.0: it would end the buyer's free-cancel hour and commit stock the merchant has not checked; the bulk action covers the routine | §7.4 |
| I7 | `dbsPickupPoint` is handled with `dbs`; express DBS warehouses are not listed | §7.1 |
| I8 | The price is rounded up to a whole number and the discount down to a whole percent, so that the price never goes below the shop's | §9.5 |
| I9 | The supplies page is a framework page behind `CONFIRM_SHIPMENT` (R1), not a plugin page as the research card plan had it | §8 WF8 |
| I10 | Sticker format and size are an account setting | §6.3 |
| I11 | `techSize: "0"` with an empty `wbSize` is the unnamed size | §5.1 |
| I12 | The article follows the cabinet (resolve by `chrtID`, third key component on a reused article); 1.0 does not change it from the shop | §5.1 |
| I13 | The freshness gate and the plugin-owned write record, keyed by the article until an `nmID` exists; a waiting update rides the export job's retry ladder. No error-list entry finalizes a send — chosen over a pre-send read of the list or a clock offset, at the price of a late failure. A write no read shows is retired once no size is waiting for it | §5.6, §11 |
| I14 | A unit counts as marked goods when its card has `needKiz` and the order lists `sgtin`; for those an optional code warns without blocking | §6.4 |
| I15 | A refused or quarantined price is final until the shop price changes: sending it again cannot succeed by itself | §8 WF1, §9.5 |

### 13.3 Tensions the coordinator must hear about

These are the places where two decisions, or a decision and a standing rule, pull against each other; each has the
reading this spec took and what would settle it. T1 and T2 were answered by the owner on 26.11.2026 and are recorded
as settled; T3–T5 stand as stated; **T6 and T7 came out of phase 0 and have not been put to the owner**.

| # | Tension | This spec's reading | Needs |
|---|---|---|---|
| T1 | **D1's checks needed a real DBS order; D6 keeps stock at 0.** | **Settled by the owner, 26.11.2026.** No real test order is possible: Wildberries forbids self-purchases and the test cabinet cannot take orders. **DBS stays in 1.0.** The two checks are made from the API documentation, simulated requests, fixtures and the sandbox; the same holds for the FBS order and supply flows. What cannot be shown that way is known residual risk (§13.4), not a reason to move DBS | — |
| T2 | **D1 «our delivery plugins ship DBS orders» against the rule «delivery code is not touched» (B3, s224).** An existing order cannot be given a carrier's method without a new entry in the delivery wizard | **Settled by the owner, 26.11.2026: approved.** Card WD1 changes the shipping module — the «Оформить доставку» entry for an existing order — as an owner's exception to that rule. The exception is this card's only; every other card keeps the delivery-untouched invariant | — |
| T3 | **D4 «a code field in the order» against Wildberries' rule** that a code is accepted only in `confirm`, i.e. for FBS only after the unit is in a supply | The field exists in the order and in the supply, and is active from `confirm`; before that it says why | nothing; stated so nobody builds an always-active field |
| T4 | **D6 «never delete» against test leftovers.** Test cards and an empty test supply stay in the friend's cabinet for good | Counted and named in advance (§12.4); created by M0a | the owner knows the cabinet keeps three test cards and one empty supply |
| T5 | **D6 «never delete» and product functions that are deletions** (remove a cargo place, clear a wrong label identifier) | They may exist in the product if a card needs them, but are never exercised on the test cabinet; they are covered by fixtures | nothing |
| T6 | **R5 «a convenience the merchant sees ships now» against the article rename.** Wildberries lets an article change, and Ozon Seller offers it from the product; the module renames one link at a time, a Wildberries article belongs to all sizes of a colour | Not in 1.0: a rename made in the cabinet is followed (§5.1), the shop offers none | the owner, if he wants it in 1.0 — it adds a framework card for a grouped rename |
| T7 | **D1 «our delivery plugins ship DBS orders» against the delivery-untouched rule, again:** a relay number may need an extension, both carrier APIs have a field for it, neither plugin reads one | Not passed in 1.0: the manager sees the warning (§7.3) | the owner: allow a CDEK and a Yandex change, or accept the warning |

### 13.4 Known residual risks

No real order can be made before the release. These are the facts that only a real order — or a call phase 0 had no
safe way to make — would prove; 1.0 ships with them stated, and the first real merchant orders are watched for them.
**R1–R5 were accepted by the owner on 26.11.2026; R7 and R8 came out of phase 0 and have not been put to him.** R6 is
closed. Rows are updated with what phase 0 showed.

| # | Unproven | What phase 0 showed | What the design does about it | Where the merchant is told |
|---|---|---|---|---|
| R1 | A carrier's API accepts Wildberries' relay number at run time, and its SMS and courier call reach the DBS buyer | CDEK normalises every documented form; Yandex receives what it is given; no call, no carrier order | The order gets the `+7…` form; a number that needs an extension is flagged before hand-over; address, date and comment go to the carrier (§7.3) | merchant docs, DBS section |
| R2 | Production accepts «received» without the buyer's confirmation code when a carrier delivers | Sandbox: 400 without a code, any value accepted; the production schema leaves `code` optional | One automatic attempt without a code; on refusal the manager enters the code; the account remembers; unrelated refusals teach it nothing (§7.4) | merchant docs, DBS section |
| R3 | Production refuses a hand-over with a required code missing exactly as documented; the cabinet accepts the code kinds 1.0 does not enter, for an order run from the shop | Sandbox handed over a codeless unit and never requires a code; the cabinet route for «Честный знак» is documented | The «what is missing» list is built from Wildberries' own per-unit states, and both documented refusal shapes (FBS 409, DBS inside 200) are handled from fixtures (§6.4, §7.4) | plugin description (D4) |
| R4 | Production lowers stock like the sandbox, or does not, and does not subtract open orders from the pushed number a second time | Sandbox: lowered at the order, not restored on cancel, no reserved figure | `wc_only`, right in both cases the documentation allows; one adapter value to change; a cancellation re-sends the number (§9.4) | — |
| R5 | The sandbox behaves like production for orders, supplies and stickers | **It does not in several known ways** (measurement.md, fidelity gaps), and shipping points, shipping method, supply transfer and DBS stickers have no sandbox route | Every order flow is also covered by fixtures from the OpenAPI text, which win over the sandbox; refusals are never inferred from a sandbox success | — |
| R6 | The live test cabinet accepts the writes the acceptance needs | **Closed**: no write was refused | — | — |
| R7 | A cabinet edit made just before an update is not undone by the plugin | A read can be older than a write already seen; nothing says how old | The freshness gate of §5.6 covers every version the plugin has seen; an edit no read has returned yet can be lost | merchant docs: do not edit a card in the cabinet while the plugin is updating it |
| R8 | Choosing a pickup point and date — required before an FBS hand-over — works against production; so do supply transfer, cargo places and the DBS pickup-point sticker | Never exercised: no sandbox route (cargo places have one, unused); an empty live supply answers 409 | Built from the OpenAPI text with hand-made fixtures; a refusal shows Wildberries' words, and the cabinet remains the way to hand over | release notes for the owner; merchant docs |

## 14. Implementation card plan

The cards are filed: the framework ones in this repository ([#1998](https://github.com/kalbac/woodev-plugin-framework/issues/1998)–[#2010](https://github.com/kalbac/woodev-plugin-framework/issues/2010), WF12 as
[#2017](https://github.com/kalbac/woodev-plugin-framework/issues/2017)), WP1–WP11 as issues 1–11 of the plugin
repository. S0 re-estimated them after phase 0 ([below](#re-estimate-after-phase-0-s0)); where a row here says more
than its filed card, this table is the newer one. S / M / L are relative size. Model tiers follow the project rule
(Haiku 5.5 for bounded work with a detailed brief, Sonnet 5.5 for routine work against this plan, Fable 5.2 for the
hardest correctness work, one at a time); every card gets a critic of another lineage. Every framework card carries the
delivery-untouched invariant except WD1. Every card that touches the live cabinet carries the four rules of §3.

### Phase 0

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| M0a — Live measurement without orders | M · Sonnet 5.5 | — (tokens are in place, §3 rule 3) | §3.1. Accept: `measurement.md` with items 1–11a proven, refuted or open; the list of writes the cabinet refused; every write listed before it was sent; no `DELETE` in the request log; stock never above 0; no token in any file |
| M0b — Order flows on the sandbox and from documentation | M · Sonnet 5.5 | — (phase 0, runs beside M0a from the first day; `WB_TEST_TOKEN`) | §3.2 items 12–18. Accept: FBS and DBS flows run end to end on the sandbox; fixtures saved; for each of the two DBS checks and for marking — what was shown, from which source, and what stays unproven (matching §13.4); the carriers' phone validation run against the documented number formats; no live-cabinet call in this card |
| S0 — Spec amendment | S · the coordinator | M0a, M0b (both phase 0, both finish before it) | Fold the measurements into this spec; confirm or change the stock formula; update §13.4; re-estimate |

All three are done: M0a (#2014), M0b (#2013), S0 (this amendment).

### Re-estimate after phase 0 (S0)

| Card | Size | State | What phase 0 changed |
|---|---|---|---|
| WF1 | M → M | ready | quarantine and a plain refusal are told apart; both are final until the price changes |
| WF2 | L | **done**, #2012 | nothing more in the framework: the send times and the error notes are the plugin's |
| WF3 | L → L | ready | restricted-circulation barcodes stay out of GTIN (small) |
| WF4 | M → M | after WF1 | none |
| WF5 | S | **done**, #2011 | the adapter keeps the cost of 10 |
| WF6a | L → L | ready | actions answer per unit |
| WF6b | M → M | after WF6a | adds the re-send of a cancelled unit's stock; touches `sync/**` |
| WF7–WF11, WD1 | unchanged | | none |
| **WF12** | — → **S** | **new**, after WF3 | the card verdict at the product |
| WP1 | M → M | ready (needs 2.1.21, released) | token type from `acc` |
| WP2, WP7, WP11 | unchanged | | none beyond the sections named in their rows |
| WP3 | L → L | after WF3 | renamed and sizeless cards; every existing card measured had three or more sizes, so groups are the normal path |
| WP4 | L → L, **highest risk** | after WP2, WP3, WF12 | freshness gate, error-list notes, verdicts; take it as two pull requests — create and media, then update |
| WP5 | M → M | after WP3 | absent is not zero; the not-ready wait; live acceptance confirmed possible |
| WP6 | M → M | after WP3, WF1, WF4 | two refusal outcomes, kopeck rounding; size prices on fixtures only |
| WP8 | M → M, **highest risk of wave 3** | after WP7, WF7, WF8 | the hand-over's shipping step has no sandbox and no live read (R8) |
| WP9 | M → M | after WP7, WP8 | two levels of «what is missing» |
| WP10 | L → L | after WP7, WF9, WF10 | `+7` relay form, per-unit results, a code on `reject` |

### Framework (`woodev-plugin-framework`)

«Files» are under `woodev/marketplace/` unless stated; every card may touch its own tests, `woodev/class-map.php` and,
for client cards, `src/marketplace-admin/**` with its built bundle.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| WF1 — Asynchronous price settlement | M · Sonnet 5.5 | — | `jobs/**` (price), `price/**`, `ledger/**`, `diagnostics/**` | §8. Accept: fake adapter pending → accepted / refused / quarantined / expired; refused and quarantined carry their own reason codes and are not re-sent until the price changes; Ozon's immediate path unchanged |
| WF2 — Export of grouped cards without a task number (**done**, #2012) | L · Fable 5.2 | M0a 4 | `jobs/class-export-*`, `jobs/class-poll-task-runner.php`, `flows/**` | §8. Accept: a group never split; «accepted, no task id» polled by article; a retried accepted create never doubles; adapter deadlines; Ozon tests green |
| WF3 — Size as the unit | L · Sonnet 5.5 | M0a 3 | `flows/class-card-{matcher,linker,import}.php`, `flows/class-product-writer.php`, `flows/class-group-assembler.php`, `data/**`, `admin/product/**`, `admin/pages/products/**` | §5.1–5.4. Accept: `12345Ejf5` and a two-barcode size survive link and import byte for byte with GTIN empty; a 13-digit code starting `20` with a valid check digit leaves GTIN empty, and so do `0000000123457` and its 12-digit form `000000123457`, both kept in `link_barcodes`; an ambiguous key is a reported conflict; the cards list groups sizes under their card |
| WF4 — Price groups | M · Sonnet 5.5 | WF1 | `price/**`, `sync/**`, `diagnostics/**`, `admin/shared/**` | §8, D7. Accept: a whole group reaches the pusher; differing prices in a single-price group are not sent and each member shows the reason |
| WF5 — Token-only account, 4xx surcharge (**done**, #2011) | S · Haiku 5.5 | — | `account/**`, `settings/class-accounts-sections.php`, `governor/**` | §8. Accept: an account with one secret saves and is «configured»; a reported 4xx draws `cost_4xx` |
| WF6a — Order actions | L · Sonnet 5.5 | — | `admin/order/**` (actions, cancel, gate), `admin/pages/orders/**` (actions), `admin/shared/**` | §8. Accept: an adapter-declared action appears in the table, the details box and bulk, and is refused server-side when its status does not allow it; a batch with one refused unit shows that unit's text and applies the others; an input schema is validated before any remote call; cancel without reasons; Ozon cancel with reasons unchanged. No file of `orders/**` or `flows/class-wc-order-store.php` changes |
| WF6b — Order units and partial cancellation | M · Sonnet 5.5 | **WF6a** — the two cards share `admin/order/class-order-details-box.php` and `admin/pages/orders/class-site-orders-rows.php` (row actions are built there, the unit children are added there), so they are taken one after the other, never in parallel | `orders/class-registry-order-writer.php`, `orders/class-order-group.php`, `flows/class-wc-order-store.php`, `flows/interface-order-meta-contract.php`, `admin/order/class-order-details-box.php` (unit list), `admin/pages/orders/class-site-orders-rows.php` (children), `sync/**` (re-send) | §6.1, §8. Accept, on a fake grouped marketplace with stock-managed products, HPOS and legacy storage: order of units A and B → cancel A → **A's stock is back, B's is still taken**, A is shown as its own row «Отменено», the total is B's; three more polls with A still cancelled → stock and order unchanged; then cancel B → the order is cancelled and B's stock is back once; two units of the same size, one cancelled → exactly one unit restored; a unit cancelled before it was ingested, and one cancelled before the next stock send, make the pair due although the number equals the last sent one; Ozon's whole-posting cancel unchanged |
| WF7 — Sticker documents | M · Sonnet 5.5 | WF6a | `flows/class-label-*`, `admin/order/class-label-button.php` | §8. Accept: several PNG → one printable sheet; ZPL and SVG downloaded under real names; Ozon PDF unchanged |
| WF8 — Shipments page «Поставки» | L · Sonnet 5.5 | WF6a | new `shipments/**`, `admin/pages/shipments/**`, `data/**`, `jobs/**`, `class-marketplace-plugin.php` (page offer) | §6.2, §8. Accept: fake adapter runs create → add → options → missing list → close → document; a table read makes no remote call; no page without the capability; the «Заказы доставки» counterpart named and matched |
| WF9 — Seller-delivered orders | M · Sonnet 5.5 | WF6a, WF6b (same order-store file) | `orders/**`, `flows/class-wc-order-store.php`, `admin/order/**`, `admin/pages/orders/**`, `deletion/**` | §7.2, §8. Accept: address, shipping line and paid mark written; address and phone editable, items locked; scheme filter; personal data exported and erased. **Ownership sequence:** import → the manager edits the phone and the address and a second shipping line is added by another writer → three marketplace polls, one of them with a changed Wildberries phone → the edited phone and address, the foreign shipping line and the order total are unchanged, the payload meta shows the new Wildberries value; an unedited field does follow a changed Wildberries value |
| WF10 — Carrier bridge | S · Sonnet 5.5 | WF6a, WF9, WD1 | new `flows/class-carrier-bridge.php`, `class-flow-wiring.php` | §7.5. Accept: an exported carrier shipment triggers the mapped seller action once; a failed call is retried by the module's job; nothing happens for an order of another marketplace or a shop order |
| WF11 — Neutral wording, contract cleanup | S · Haiku 5.5 | WF6a, WF6b, WF8 (shared files) | strings in `admin/**`, `deletion/**`, `settings/**`, `capabilities/**` | §8. Accept: a test pins «no marketplace name in a framework msgid»; nouns from the descriptor; Ozon change named |
| WD1 — Delivery for an existing order | L · Sonnet 5.5 | WF9 (its acceptance runs on a seller-delivered order; owner's exception to «delivery code is not touched», 26.11.2026 — T2) | `woodev/shipping-method/admin/orders/**`, `src/shipping-orders-page/order-wizard/**` | §7.5. Accept: a foreign order gets a carrier line through the wizard and becomes a row; its items and totals are untouched; the marketplace's own shipping line is untouched and the carrier line adds 0 to the total; after a hand-over and three marketplace polls (run with WF9) the carrier line, the resolved address and the phone are still there; existing create and edit behave as before (their tests unchanged); classic and HPOS |
| WF12 — A note on a card, at the product | S · Haiku 5.5 | WF3 (shared files) | `admin/shared/**` (the optional interface), `admin/product/**`, `admin/pages/products/**` | §8, §9.3. Accept: a fake adapter's note shows on the product tab, in «Товары сайта» and in the cabinet-card badge tooltip; a table read makes no remote call; Ozon unchanged |

### Plugin (`woocommerce-wildberries-seller`)

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| WP1 — Skeleton and connection | M · Sonnet 5.5 | WF5 | §9.1. Accept: a Base token, a read-only token and each missing category explained in plain words; the token never in a log or the diagnostics report |
| WP2 — Catalogue, mappings, validation | M · Sonnet 5.5 | WP1 | §9.2. Accept: subject search, required and limited characteristics, every rule with its merchant reason |
| WP3 — Cabinet cards: list, link, import | L · Sonnet 5.5 | WP1, WF3 | §5.1, §5.3–5.4, §9.3. Accept on the live cabinet (read-only): the dormant catalogue lists, links and imports; a colour × size group → one variable product. On fixtures: a sizeless card → a simple product; a renamed card stays linked; a freed article on a new card gets its own key |
| WP4 — Create and update cards, media | L · Fable 5.2 | WP2, WP3, WF2, WF12 | §5.5–5.6, §9.3. Two pull requests: create and media, then update. Accept: create on the test cards; photos confirmed by read-back, a lost job retried; an update keeps every cabinet-only field (read-back diff empty); an update from an older read waits and gives up at the cap (replayed from the M0a pairs); no error-list entry finalizes a send — from an empty record, with the retained entry listed and the card appearing later (replayed `w02-error-list`, `w03-card-upload`, `w03-poll-a-miss-011617`, `w03-poll-a-first-seen-011700`) the create stays open and ends imported; a refused create that never gets an `nmID` (replayed `w02-card-upload-invalid` and `w02-error-list`) has its record before the request, shows Wildberries' text at the product and fails at the task deadline; **recovery of an update that is never shown** — Wildberries refuses it (an error-list entry, shown as the note) or silently drops a retired characteristic (200, no entry): the task stays open and a corrected update waits; then, once after «Сбросить состояние» and once after the deadline, the next export retires the unshown write, still refuses a read older than the newest `updatedAt` seen, reaches Wildberries and settles as imported; with a second size of the card still waiting the write stays pending until it is released; a listed card attaches its `nmID` to that same record, and a card older than the first send is not attached; an update is imported only when a read shows it; the verdict shows at the product; no delete call exists in the client |
| WP5 — Stock and warehouses | M · Sonnet 5.5 | WP3 | §9.4. Accept: the payload contract test; both formulas covered by unit tests, `wc_only` in force; absent is not read as 0; a not-ready refusal waits; on the live cabinet a push of 0 and its read-back; on the sandbox a non-zero push, an emulated order and the stock after ingest |
| WP6 — Prices | M · Sonnet 5.5 | WP3, WF1, WF4 | §9.5. Accept: price and discount on a test card settle through WF1; the rounding table in kopecks; quarantine and the plain refusal shown with their own sentences; a not-yet-listed card waits; size prices on fixtures (no live card allows them) |
| WP7 — FBS orders | L · Sonnet 5.5 | WP3, WF6a, WF6b; M0b for its fixtures | §6.1. Accept: grouping in every arrival order, both status axes, unit cancel with the stock check of WF6b, both order storages; fixtures from M0b 12–13 and an end-to-end run on the sandbox; no live-cabinet order |
| WP8 — Supplies and stickers | M · Sonnet 5.5 | WP7, WF7, WF8 | §6.2–6.3. Accept: the `Shipment_Batches` half against fixtures — the shipping-point, shipping-method and transfer ones written from the OpenAPI text (R8); mixed cargo types refused locally; sticker sheet printed in a real browser; one read-only look at the existing empty live supply (U5) |
| WP9 — Marking codes | M · Sonnet 5.5 | WP7, WP8 | §6.4. Accept: scanner input normalised; every `sgtin*` state has a Russian sentence; both levels — a missing required code keeps the hand-over off, a missing code of marked goods warns; the «чего не хватает» list names the kinds 1.0 does not enter; documented refusals from fixtures — a sandbox delivery is not evidence |
| WP10 — DBS orders | L · Sonnet 5.5 | WP7, WF9, WF10; M0b for its fixtures | §7. Accept: the first payload stored before any action; phone rule of §7.3 incl. the `+7` form and the extension-only case; every action of §7.4 with its status gate; a refusal inside a 200 shown per unit; both outcomes of the automatic `receive` (accepted; refused → code asked, no second attempt) and an unrelated refusal that sets no «needs code» mark; a carrier export moves the order to `deliver`; end to end on the sandbox |
| WP11 — Acceptance and release materials | M · Haiku 5.5 | all | Rig acceptance; live-cabinet acceptance of the card, price and zero-stock paths; sandbox acceptance of every order flow; the residual risks of §13.4 written into the release notes for the owner; wiki; merchant docs for a WordPress novice; the release materials package. The plugin description states what marking kinds are not entered (D4) and anything still unproven |

### Order and parallelism

**Demand orders the work (coordinator, 26.11.2026).** Support counted direct requests in all 4444 tickets: Wildberries
was asked for by 4 customers in 5 requests, and every one of them asked for **product export and stock
synchronisation**; nobody asked for orders or prices. One of them offered his live Wildberries account for tests. This
changes no owner decision and no scope — it sets the order in which cards are taken:

| Wave | Cards | The merchant gets |
|---|---|---|
| 1 — products and stock | M0a, M0b, S0 (phase 0), WF5, WF3, WF2, WF12 → WP1, WP2, WP3, WP5, WP4 | connection, link and import of existing cards, stock sync, creation of cards from products |
| 2 — prices | WF1, WF4 → WP6 | prices with honest results |
| 3 — FBS orders | WF6a, WF6b, WF7, WF8, WF11 → WP7, WP8, WP9 | orders, supplies, stickers, marking |
| 4 — DBS | WF9, WD1, WF10 → WP10 | orders the seller delivers |
| 5 | WP11 | acceptance and the release |

After S0 the cards ready to be taken are **WF3** and **WP1** (wave 1), then WF1 and WF6a.

Within wave 1 the first usable state is **WP1 + WP3 + WP5** (connect, link existing cards, sync stock). WP5 waits for
no order measurement: its formula is settled in §9.4. M0b is a phase-0 card, not a wave-3 one: it runs beside M0a on the first day, S0 follows both, and WP7–WP10 take its
sandbox fixtures as their acceptance input. WP4, the hardest card left, takes the one Fable 5.2 slot (WF2 is done). Waves 2–4 have no dependency
on wave 1's framework cards and start as soon as workers are free, but never ahead of a wave-1 card that is ready to
be taken. No card waits for the owner; T6 and T7 and the two new risks R7 and R8 (§13) are for him to hear, and the
plan goes on with the reading stated there. Whether wave 1 alone is released first is the owner's call; the plan only
makes it possible.

```text
M0a ─┐
M0b ─┴─► S0 ──► WF5 ─► WP1 ─► WP2 ─────────────┐
                WF3 ─► WP3 ─► WP5              ├─► WP4 (after WF2, WF12)
                WF3 ─► WF12                    │
                WF1 ─► WF4 ─► WP6              │
                WF2 ───────────────────────────┘
                WF6a ─► WF7 ─┐
                WF6a ─► WF8 ─┼─► WP7 ─► WP8 ─► WP9
                WF6a ─► WF6b ┘
                WF6a, WF6b, WF8 ─► WF11
                WF6b ─► WF9 ─► WD1 ─► WF10 ─► WP10 (also after WP7)
M0b's fixtures are the acceptance input of WP7, WP8, WP9 and WP10
                                                    all ─► WP11
```

Framework releases at the seams: after WF5 (the plugin boots — released as 2.1.21), after WF3 and WF12 (cards), after WF1–WF4 (catalogue and sync), after WF6a–WF8 and
WF11 (FBS orders), after WF9–WF10 and WD1 (DBS). **Totals:**

| | L | M | S | Cards |
|---|---|---|---|---|
| Phase 0 (M0a, M0b, S0) | — | 2 | 1 | 3 |
| Framework (WF1–WF12 with WF6a and WF6b, WD1) | 5 | 5 | 4 | 14 |
| Plugin (WP1–WP11) | 4 | 7 | — | 11 |
| **Left after S0** | 8 | 12 | 3 | 23 |

No card is conditional: DBS stays in 1.0 (owner, 26.11.2026).

## Revision log

| Date | Change |
|---|---|
| 26.11.2026 | After the critic review of PR #1992 (no owner decision and neither open tension T1 / T2 changed): (1) a cancelled unit is a stored snapshot shown as its own row, not a stock-holding line item — §6.1; (2) the unit key is one escaped two-component encoding, frozen at linking — §5.1; (3) the stock formula's dependency on an order measurement was made explicit (superseded the same day by the owner's answer, next row) — §3.2, §9.4, §14; (4) seed-then-leave-alone ownership of DBS order fields and two shipping lines with two owners — §7.2; (5) separate FBS and DBS label-identifier buckets, DBS writes 500 / min — §12.2; (6) WF6 split into WF6a (actions) and WF6b (units and partial cancellation) |
| 26.11.2026 | After the second critic review (Qwen; no Must fix): the import SKU is a candidate checked before writing, a taken one leaves the SKU empty with a reported conflict — §5.4; M0b is stated once as a phase-0 card beside M0a, removed from wave 3, with the M0b → S0 edge drawn; WF6b depends on WF6a (two shared files named) and WD1 on WF9; FBS cancel burst 20; a field the writer did not write is never seeded over — §7.2 |
| 26.11.2026 | The owner's answers. **T1:** no real order is possible; DBS stays in 1.0; the DBS checks and all FBS order flows are done from documentation, fixtures and the sandbox, with the unproven facts as stated residual risk (§13.4); the two real-order measurement cards became one sandbox card M0b; the stock formula is settled as `wc_only` without an order measurement (§9.4), which is how critic finding 3 is closed now; refused live writes are measured (M0a 11a). **T2:** approved — WD1 changes the shipping module as an owner's exception to «delivery code is not touched» |
| 26.11.2026 | WF2 built (#1999): `read_import()` keeps its signature; article reads, grouping and deadlines are optional interfaces, so Ozon needs no change — §8. The same rule binds the other framework cards that add to an adapter contract |
| 26.11.2026 | **S0 (#1997): phase 0 folded in** (§3.4). No owner decision changed; `wc_only` confirmed. New §5.6 (freshness gate, result matching); unit key follows a cabinet rename (§5.1); GTIN excludes restricted-circulation codes (§5.2); stock re-sent after a cancellation (§9.4); price rounding and two refusal outcomes (§9.5); `+7` relay form and per-unit DBS results (§7); two marking levels (§6.4); §13.1 carries a result for every item, with U14–U17 new; interpretations I11–I15, tensions T6–T7, risks R7–R8 (not yet put to the owner), R6 closed; new card WF12; re-estimate (§14) |
| 26.11.2026 | After the critic review of PR #2018 (no owner decision, T6–T7 or R7–R8 changed): the card write record exists before an `nmID` does, keyed by account and article, so a refused create is matched to its send — §5.6, §11, WP4; the error-entry rule allows a clock margin — §5.6; the GTIN exclusion adds the GS1 prefix `0000000` — §5.2, WF3 |
| 26.11.2026 | Second critic round of PR #2018: the clock-margin rule for error-list entries (previous row) is withdrawn — it could fail a successful create on a retained entry the plugin had never read. No entry finalizes a send; the card list decides, and what is still open fails at the task deadline — §5.6, §12.1, WP4 |
| 26.11.2026 | Third critic round of PR #2018: a write no read ever shows is retired once none of its sizes waits for it (deadline, reset or a new send), keeping the newest `updatedAt` baseline, so a corrected update is not blocked — §5.6, §11, WP4; no framework change |

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
