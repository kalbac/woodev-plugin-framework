# Avito 1.0 — input pack for the specification (28.11.2026)

For the author of the specification. Facts only. **Binding:** [decisions.md](decisions.md). **Also available, not
authoritative:** [spec-draft.md](spec-draft.md). Research: [API map](api-map.md), [gap map](gap-map.md).

Framework code read at `cd2495ce` (main: 2.1.27 plus #2005), through Serena and grep; nothing was executed and no
request was sent to Avito. Paths are relative to `woodev/marketplace/`; line numbers are those of `cd2495ce`. The gap
map was read earlier (`157c4bd9`, 2.1.20), so its line numbers have moved.

## 1. Wildberries work since the gap map

| Card | PR | State | What it added |
|---|---|---|---|
| WF1 prices decided later | #2033 | merged, 2.1.25 | `price/interface-price-pusher.php:59` `settle()` has a caller |
| WF2 export without a task number | #2012 | merged, 2.1.21 | article-keyed lifecycle, grouping, deadlines |
| #2040 export right after a reset | #2041 | merged, 2.1.26 | `Releases_Reset_Imports` |
| WF3 size as the unit | #2024 | merged, 2.1.23 | sellable units, match keys, barcodes |
| WF4 price groups | #2031 | merged, 2.1.26 | one price for several links |
| WF5 token-only account | #2011 | merged, 2.1.21 | derived external id, 4xx surcharge |
| WF6a seller actions | #2046 | merged, 2.1.27 | actions with input, reasonless cancel |
| WF7 sticker documents | #2049 | merged, planned 2.1.28 | immediate labels, selectable format |
| WF11 wording, catalogue | #2026 | merged, 2.1.23 | `Capability::CATALOG`, `Posting_Wording` |
| WF12 note at the product | #2032 | merged, 2.1.24 | `Link_Notes` |
| #2034 / #2042 warehouse changes | #2037 / #2043 | merged, 2.1.25 / 2.1.26 | zero for a left warehouse |
| WF6b order units, partial cancel (#2004) | #2051 | **open** | unit rows; touches the orders table, details box, `sync/class-stock-intents.php` |
| WF8 shipments page (#2006) | #2052 | **open** | new page; touches `data/class-schema.php`, `class-marketplace-plugin.php`, `class-flow-wiring.php` |
| #2054 export claim to the gateway | #2061 | **open** | touches `flows/interface-export-gateway.php`, `jobs/class-export-batch-runner.php` |
| #2058, #2025 / #2063 / #2065 fixes | #2059 / #2064 / #2066 | **open** | marketplace polish and refusals |
| WF9 (#2007), WF10 (#2008), WD1 (#2010) | — | cards open | seller-delivered orders and the carrier bridge |

## 2. Gap map row by row

Status: **exists** (usable as it is), **exists/WB** (built by a Wildberries card), **in flight**, **new** (no seam
found), **plugin** (Avito-only code), **none** (nothing to build or impossible).

| G | Topic | Status | Evidence | Still missing |
|---|---|---|---|---|
| 1 | Pages, jobs, licence, diagnostics, bulk | exists | `class-marketplace-plugin.php:203`; `jobs/class-license-gate.php:84`; `jobs/class-job-gate.php:93-100`; id length `data/class-schema.php:42` | — |
| 2 | Accounts, secrets | exists | `account/class-credential-fields.php:22`; `data/class-schema.php:252` (`seller_id`), `:265` (unique `external_id`) | — |
| 3 | Token, errors, limits | plugin | `governor/class-bucket-spec.php:80` | token fetch and cache |
| 4 | Categories, characteristics | **new** part | `catalog/interface-catalog-schema.php:25,35,46`; `catalog/class-field-definition.php:91` | conditional visible / required / hidden |
| 5 | Listing-level values | partly; **new** part | source kinds `catalog/class-source.php:19-22`; per-link `attribute_overrides` read at `flows/class-link-context-resolver.php:205`; the product form takes only `admin/product/class-link-form-input.php:35-39`; `settings/interface-settings-extension.php:33-50` with `select` / `toggle` fields (`settings/class-extension-field.php:104,135`) | no screen writes the overrides; extension values are shop-wide or product meta, no text field, nothing per account |
| 6 | Publication as a file | **new** | `flows/interface-export-gateway.php:39,54`; batches `jobs/class-export-scheduler.php:27`; public-route precedent `intake/class-notifications-controller.php:118`; #2061 in flight | item store, document writer, URL, rebuild, fetch log; no feed or XML code found |
| 7 | Result without a task number | **exists/WB** (WF2, #2040) | `flows/interface-article-keyed-lifecycle.php:33,47,62`; `flows/interface-import-deadlines.php:21`; `flows/interface-releases-reset-imports.php:24`; empty `submit()` answer accepted `jobs/class-export-batch-runner.php:546` | poll cadence is fixed: `jobs/class-poll-task-runner.php:57-61`, `:1048` (60 s for 10 min, then 300 s until the deadline); 10 000 ads = 100 tasks |
| 8 | Link states, late ids, text | exists; **exists/WB** (WF12) | `data/class-link-record.php:35-45`; `admin/shared/interface-link-notes.php:22` | — |
| 9 | One link per product | exists | `data/class-schema.php:309-310` | several cities per product impossible (D8 does not ask) |
| 10 | Linking existing listings | exists | automatic `flows/class-card-matcher.php:42` (normalised SKU = article), merchant-started; manual `admin/pages/products/class-remote-cards-actions.php:616,759` → `flows/class-card-linker.php:149` | not tried with an Avito-like adapter |
| 10a | Adoption into the feed | **new** | export of a created card is allowed: `flows/class-export-service.php:136`, `data/class-wpdb-link-store.php:621` | no adapter hook to warn or refuse at link or export |
| 11 | Listing → product import | none | `flows/interface-card-importer.php:27` | the API lacks the data |
| 12 | Export and linking without import | **exists/WB** (WF11) | `capabilities/class-capability.php:51,79`; `settings/class-shared-fields.php:87` | — |
| 13 | Stock without a warehouse | **new** | `stock/interface-stock-pusher.php:34,46` (takes a warehouse id); `sync/class-stock-intents.php:228` (none → nothing sent); `flows/class-card-created-seeder.php:153`; `sync/class-stock-readback.php:215`; `diagnostics/class-why-not-syncing.php:57`; column already `DEFAULT 0` `data/class-schema.php:449`; vacating is for ids > 0 `sync/class-stock-flusher.php:483` | the send path and every warehouse control |
| 14 | Stock rules, batches | exists | `sync/class-sync-limits.php:19`; `sync/interface-stock-rules.php:23,52,66`; 10-per-read covered by `stock/interface-reports-read-requests.php:24` | formula to measure |
| 15 | Price send | exists | `price/interface-price-pusher.php:22,32`; markup and rounding `price/class-price-rule.php:30-37` | feed must carry the price (row 6) |
| 16 | Price read-back | exists | `capabilities/interface-marketplace-adapter.php:249` | — |
| 17 | Order ingest | exists | `jobs/interface-posting-handler.php:32,42`; every 10 min `jobs/class-posting-jobs.php:45` | — |
| 18 | Order data | exists | `orders/class-order-dto.php:121`; buyer copy `flows/class-wc-order-store.php:452` | — |
| 19 | Cancel without reasons | **exists/WB** (WF6a) | `admin/order/interface-reasonless-canceller.php:22` | — |
| 20 | Seller actions | **exists/WB** (WF6a); **in flight** (WF6b); **new** part | `admin/order/interface-seller-actions.php:26-102`; field types `admin/order/class-seller-action-field.php:25-41`; 10 fields at most `admin/order/class-seller-action.php:44` | list options get no order and come from stored data (`admin/order/interface-seller-action-options.php:33`); no deadline in the table (fixed columns `admin/pages/orders/class-site-orders-definition.php:119`); unit actions reach the table only with #2051; a scanned code keeps the GS separator on the order screen only ([wiki](../../wiki/marketplace-admin.md#seller-actions-on-an-order-2003)) |
| 21 | Labels | exists; **exists/WB** (WF7) | `flows/interface-label-provider.php:24-56`; `flows/interface-selectable-label-format.php:21` | — |
| 22 | Notifications | none | nullable `capabilities/interface-marketplace-adapter.php:139` | — |
| 23 | Messenger | none (D10) | — | — |
| 24 | Money safety | plugin | nothing in the module | — |
| 25 | Autoload profile | plugin | nothing in the module | — |
| 26 | Merchant wording | partly **exists/WB** (WF11); **new** part | `settings/interface-posting-wording.php:19`; marketplace name `settings/interface-settings-descriptor.php:30` | no seam for the card noun or warehouse words: 162 «карточка» and 35 «склад» occurrences in translation calls |
| 27 | Dead contracts | **exists/WB** (WF11) | deprecated `capabilities/interface-marketplace-adapter.php:105-130` | — |

### Found in this re-read, not in the gap map

| Fact | Evidence | Bears on |
|---|---|---|
| Product events watched: stock change, trash, delete, untrash, price props. No hook for a change to draft or private was found; a product edit queues no export | `sync/class-stock-intents.php:105-109`; `sync/class-price-intents.php:130`; `flows/class-deleted-product-releaser.php:66` | D6, feed freshness |
| Archiving goes through the stock queue and a warehouse | `sync/class-stock-intents.php:159`; `flows/class-deleted-product-releaser.php:102` | D6 for accounts without stock |
| An `archived` link cannot be queued for export again | `data/class-wpdb-link-store.php:621` | a listing that returns |
| No per-account «this part is unavailable»: `role_missing` pauses the whole account | `account/class-health-result.php:21`; `jobs/class-account-pause.php:29` | D1 |
| Order details accept adapter rows; the shown order number comes from the canceller | `admin/order/class-order-details-box.php:195,266` | deadlines, `marketplaceId` |
| The posting column is headed by the posting noun beside the column «Заказ» | `admin/pages/orders/class-site-orders-definition.php:119` | Avito calls a posting «заказ» |
| Hooks that exist: first remote write, account data cleaner | `jobs/class-first-remote-write.php:22`; `deletion/interface-account-data-cleaner.php:23` | D9, deletion |

## 3. API facts each part depends on

Everything here is from the [API map](api-map.md); the section links carry the source labels.

| Part | Facts | Section |
|---|---|---|
| Connection | Of the two flows only `client_credentials` covers stocks and orders; token 24 h, no refresh; `accounts/self` gives `user_id` | [§1.1](api-map.md#11-authorization) |
| Access | Keys need a tariff. Goods autoload needs «Расширенная» or «Максимальная». Stock needs that, company status and autoload. Orders need a company or sole trader with delivery for business. No API tells a goods seller's tariff; the refusal answers are unverified | [§1.2](api-map.md#12-what-needs-a-paid-tariff-a-subscription-or-a-business-account) |
| Terms | 1.1 registration through support; 1.3 every key action is the user's, spending included; 2.7 no Avito marks or name in an application | [§1.3](api-map.md#13-api-terms-page-p3) |
| Feed delivery | XML / XLSX / CSV by link; profile with schedule and up to three links; `upload` once an hour, ignoring schedule limits; daily processing, up to 24 h late; ≤ 500 MB; ports 80/443, no login, no self-signed certificate | [§3.1](api-map.md#31-connection-and-delivery-of-the-file) |
| Feed fields | `Id` ≤ 100 and permanent; `AvitoId` adopts; address, title ≤ 50, description ≤ 7500, ≤ 10 JPEG/PNG images (unchanged URL not re-downloaded), integer price, `CountryExpedition` from 15.11.2026; `ListingFee`, `AdStatus`, `Promo…` can spend | [§3.2](api-map.md#32-format-and-common-fields) |
| Categories | Tree and per-field description with dependencies; 3086 leaf categories | [§3.3](api-map.md#33-categories-and-fields--machine-readable) |
| Create, close, link | The file is the full list (absence archives, the same `Id` reactivates); an empty file is not processed (past `DateEnd` or `<Id>STOP</Id>` closes); `ad_ids` / `avito_ids` map ids; auto-match on a first load; a similar closed ad blocks a new one | [§3.4](api-map.md#34-create-update-close-link-existing) |
| Load report | v4; per-ad detail only for the current and last successful upload; `avito_status` and messages; v2/v3 end 08.03.2027 | [§3.5](api-map.md#35-report-of-a-load-v4) |
| Listing list, price | No create, edit or archive call; the list has no `Id`, SKU, stock or photos; one price per call | [§4](api-map.md#4-item-management-api) |
| Stocks | By listing number, no warehouse; write 200 lines, read 10 (C4); read is «quantity less reserved», reserved not returned; «Несколько штук» off = one order then archive; not offered in some categories | [§5](api-map.md#5-stocks-остатки) |
| Orders | Poll only, 20 a page, date filter by creation; statuses; delivery types (enum incomplete, C8); buyer only for DBS / RDBS; `availableActions`, deadlines | [§6.1](api-map.md#61-what-can-be-read) |
| Actions, labels | confirm / reject without reasons; markings before confirm, ≤ 10 per item; courier range sets confirmation; `cncSetDetails` listed for courier too; labels pickup-point orders only (C3); a missed required action cancels the order | [§6.2](api-map.md#62-actions) |
| Delivery setup | Company data, wallet, tariff, cabinet section | [§6.3](api-map.md#63-enabling-delivery-for-business-page-p8) |
| Limits | Per minute, per operation; autoload reads and token not stated | [§2](api-map.md#2-rate-limits) |
| Testing | No public seller sandbox found; self-order unverified | [§8](api-map.md#8-sandbox-and-test-accounts) |
| Conflicts | C1–C8, C7 (API edit against the next load) included | [§9](api-map.md#9-official-sources-that-disagree) |

## 4. Open questions and tensions

Not answered by D1–D13. The draft proposes a reading for most; those readings bind nobody.

| # | Question | Why it is open |
|---|---|---|
| Q-1 | D6 «out of stock → stock 0» for a seller who may publish but may not send stock | stock needs company status ([§1.2](api-map.md#12-what-needs-a-paid-tariff-a-subscription-or-a-business-account)); D1 switches the part off, so nothing sets a sold-out listing to 0 |
| Q-2 | Can D1 be implemented: how does the plugin learn what a seller lacks? | no tariff call for goods; refusals unverified, possibly silent |
| Q-3 | D5 automatic linking without autoload access | `Id` comes only from the autoload id lookups; whether they answer without the subscription is not established |
| Q-4 | What «never spends» (D9) covers | a view-based tariff charges for published listings; `upload` ignores limits; whether adoption changes an ad's paid options is unverified |
| Q-5 | Closing the last listing, and the reach of `STOP` with several feeds | absence cannot do it (empty file); `STOP`'s scope unstated |
| Q-6 | Two feeds with one `Id`; one feed of several failing to download; removing an old generator's link | behaviour unverified; each can archive or double ads |
| Q-7 | D6 «unpublished»: which statuses; a mass unpublish | the research card plan proposed holding a suddenly shrunk file; no decision says either way |
| Q-8 | The file on unlink, account switch-off or deletion, lapsed licence, plugin deactivation | not decided; absence or a dead URL has marketplace-side effects (partly unverified) |
| Q-9 | D7 «API at once and in the feed» against C7 | an older file may overwrite an API price |
| Q-10 | Stock and price before a linked listing is taken under management | linking may send them at once when «sync on link» is on; the research card plan proposed no send before adoption |
| Q-11 | D2 and terms 2.7: may screens and menus name Avito; who registers the application (1.1) | support letter, not yet answered |
| Q-12 | D3: view-only orders still have cancelling deadlines; courier orders and `cncSetDetails`; courier returns (`acceptReturnOrder`) | D3 names neither call |
| Q-13 | D3 courier time: choices per order | the seller-action option contract has no order (row 20) |
| Q-14 | D8 «one product» for a variable product | each variation is its own ad ([gap map §1](gap-map.md#1-where-avito-differs-from-ozon-and-wildberries)) |
| Q-15 | Result deadlines and polling | module defaults 24 h and 3 h; schedules may run on few days and processing lags |
| Q-16 | Several accounts | a bot measurement on #1986 left them out of v1; no owner decision does; the module offers them |
| Q-17 | What stays unproven without a permitted order | actions, labels, marking, stock formula; no sandbox |

The measurement list is [access-needed](access-needed.ru.md) plus the UNVERIFIED lines of the API map and the research
index («Not verified»); the draft regroups them as items M1–M16.

## Related

- [Owner decisions](decisions.md)
- [Draft design](spec-draft.md)
- [Gap map](gap-map.md)
- [API map](api-map.md)
- [Research index](README.md)
