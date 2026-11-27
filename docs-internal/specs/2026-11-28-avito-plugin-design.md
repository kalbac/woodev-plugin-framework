# Avito Plugin 1.0 Design (working name)

**Date:** 2026-11-28  
**Status:** draft — written before the phase-0 measurement (§3); card S0 amends it and puts the 1.0 scope back to the owner (D13).  
**Target:** the first release, **1.0.0**, of a NEW plugin for WooCommerce shops that sell goods on Avito, built on the
framework module `woodev/marketplace/`. Card [#1986](https://github.com/kalbac/woodev-plugin-framework/issues/1986).  
**Evidence:** the fact base is [the research folder](../research/2026-11-26-avito/README.md): **D** =
[decisions.md](../research/2026-11-26-avito/decisions.md), **API** =
[the API map](../research/2026-11-26-avito/api-map.md) (its §§, page ids P1–P21 and conflicts C1–C8), **G n** = row n of
[the gap map](../research/2026-11-26-avito/gap-map.md) (framework read at `157c4bd9`, 2.1.20). For this spec the module
was read again at `cd2495ce` (main: 2.1.27 plus the sticker documents of #2005) through Serena on the worktree and grep.
Labels: **SOURCED** (Avito's OpenAPI text or an official page, through the API map), **READ** (framework code read for
this spec), **M n** (to be measured, §3), **I n** (interpretation: a gap this spec closed, listed in §20.2), **T n**
(tension, §20.3). **Nothing was measured: no request was sent to Avito for the research or for this spec.**

## 0. How to read this spec

§1 binding decisions. §2 product boundary and what access each part needs. §3 phase-0 measurement, which comes before
the implementation cards. §4 capability → seam map, with what the Wildberries work already delivered. §5 publication by
feed, the central new part. §6 parts switched off by access. §7 stock, §8 prices, §9 load report, §10 existing
listings, §11 fields, §12 autoload profile and money, §13 orders. §14 framework extensions, §15 plugin parts, §16 admin
pages, §17 data model, §18 errors and limits, §19 tests, §20 registers, §21 implementation card list, revision log.

**Four facts the reader must know.**

1. **Goods listings are created and edited only through a file Avito downloads** (API §3–4). The file is the whole list:
   a listing that is missing from it is **archived**.
2. **Access is paid and split by part** (API §1.2), and **no API tells a goods seller's tariff or subscription**.
3. **The same key can spend the seller's money** — paid placement, promotion, a load that ignores publication limits
   (API §1.3, §3.1, §3.2).
4. **No public seller sandbox was found** (API §8). Until support answers, measurement and acceptance use a real cabinet.

## 1. Binding decisions

Source: [decisions.md](../research/2026-11-26-avito/decisions.md) (owner, 27.11.2026). Where any research file
disagrees, that file wins.

| Id | Decision (short) | Where it lands |
|---|---|---|
| D1 | Feed + load report and listing link + stocks + Avito Delivery orders + prices; a part without the seller's access is switched off with a clear reason; no chats, promotion, reviews, statistics | §2, §6 |
| D2 | Name, slug, icon only after support answers; neutral working name | §2, T6 |
| D3 | Orders of Avito partners (pickup points, parcel lockers, Yandex courier): list, confirm, cancel, labels (pickup points only), courier time. Other delivery methods are visible, acted on in the cabinet | §13 |
| D4 | Goods sections only; fields from Avito's directory; checked on 2–3 categories and said so | §11 |
| D5 | Existing listings linked (by `Id` = SKU automatically, else by hand); taken under management only with all required fields; own feed link beside others; replacement of description and photos warned | §10 |
| D6 | Out of stock → stock 0, listing stays; product deleted or unpublished → archived, with a warning; an incomplete build serves the previous file | §5, §7 |
| D7 | Shop price with markup rules: by API at once and the same in the feed | §8 |
| D8 | One address per account, replaced per category and per product; one product → one listing | §5.1, §11 |
| D9 | The plugin never spends money; placement only from bought packages; the schedule is the seller's, or set with confirmation; «Загрузить сейчас» warns | §12 |
| D10 | No chats | §2 |
| D11 | ТН ВЭД, ОКПД 2, document fields from product properties; «Честный знак» entered in the order | §11, §13.4 |
| D12 | Wildberries first; shared extensions designed for both; the measurement may be earlier | §4, §21 |
| D13 | Measurement and spec now; the 1.0 scope confirmed after them | §3, §21 |
| R1–R6 | Non-domain logic in `woodev/marketplace/`; PHP-driven React; the «Заказы доставки» table mechanisms; adapter contracts change only by new optional interfaces; licence gate by default; merchant-visible conveniences ship now | every section |

Project rules that also bind: the admin trusts the merchant and no protection is invented without a real case (KISS);
merchant diagnostics only behind «Логирование»; technical knobs are constants and filters, not settings; merchant texts
never say «чекаут» or «фреймворк» and never name a framework version; delivery code is not touched; no secret, seller
data or buyer data in this public repository or its tracker.

## 2. Product boundary and identity

**Working name: «Listings Seller».** Every identifier below is a **placeholder** until the name is decided (D2). They
may be used during development; they are frozen — renamed everywhere if need be — **before the first release**
(card AP10, I1). The marketplace id sets the table prefix, so no site outside the project may install the plugin before
the freeze.

| Item | Placeholder | Note |
|---|---|---|
| Repository | `kalbac/woocommerce-listings-seller`, private | not created by this spec |
| Version | `1.0.0` | |
| Marketplace id | `listings` | within `Schema::MAX_ID_LENGTH` 16 (READ `data/class-schema.php`); table prefix `woodev_listings_` |
| Plugin id, text domain, namespace | `listings_seller`, `woocommerce-listings-seller`, `Woodev\Listings_Seller` | Ozon Seller's shape |
| Menu | own top-level entry, own icon (full square) | R2 |
| Base class | `Woodev\Framework\Marketplace\Marketplace_Plugin` | R1 |
| Supported | HPOS and legacy order storage; the PHP, WordPress and WooCommerce floors of Ozon Seller 2.0.0 | |
| Installed-site data | none of ours exists | every key this spec names becomes a contract at 1.0.0 ([ADR-005](../adr/005-platform-v2-clean-break-policy.md)) |
| Licence | the default gate (R5) | what it does to the feed: §5.7, T4 |

**In 1.0.** Connection by the seller's own keys · goods categories and fields, mappings and profiles · the feed and the
autoload profile · load report at the product · existing listings: list, link, take under management · stock · prices ·
Avito Delivery orders of partner delivery, courier time, labels, «Честный знак» · diagnostics, tools, shared analytics.

**Not in 1.0.** Chats (D10) · promotion, paid services, reviews, statistics (D1, D9) · cars, real estate, jobs,
services (D4) · actions on orders of the seller's own delivery, own courier and self-pickup: tracking number, pickup
preparation, handover codes (D3) · accepting a returned courier parcel (`acceptReturnOrder`; ignored, it is accepted by
itself after two weeks — SOURCED, API §6.2) · importing a listing into a product: the API gives title, price, category
and address only (G 11) · one product in several cities (D8) · the `authorization_code` application flow: it has no
scope for stocks or orders (API §1.1).

**What each part needs from the seller** (SOURCED, API §1.2):

| Part | Needs |
|---|---|
| Keys, listing list, price by API, linking by hand | a tariff |
| Publication by feed, load report, **listing ids** (`ad_ids`, so linking by article) | + «Расширенная» or «Максимальная» subscription; some categories also a verified business |
| Stock | + subscription, company status, autoload connected |
| Delivery orders, labels, marking | a company or sole trader with delivery for business, on any tariff |

**What the merchant does in the cabinet** (settings help and merchant docs say so): confirm company data, pay the
tariff and the subscription, accept the autoload rules, enable delivery for business, create the keys
([access list](../research/2026-11-26-avito/access-needed.ru.md)).

## 3. Phase 0 — measure before design is final

No live call is made by this spec. The owner first sends the **support letter** (before paying for a tariff): may a paid
plugin work with the seller's keys and who registers the application (terms 1.1); what terms 2.7 allows in a name and a
menu; is there a test cabinet; may a test order be placed on one's own listing and cancelled; which subscription the
stock methods need and what the refusal looks like; which tariffs open the API.

**What the owner prepares** (access list, in Russian): a verified company or sole trader profile; a tariff in goods; the
«Расширенная» subscription; money on the wallet; `client_id` and `client_secret` in a file outside the repository; 2–3
cheap real goods with photos, address, weight, dimensions and, for new goods, codes; delivery for business; a public
HTTPS address for the feed (recommended: a wootest.ru subdomain).

**Rules every measurement brief repeats, word for word:**

1. **Spend nothing on purpose:** no paid service, no promotion field, no single paid placement. Every write is listed in
   the measurement file **before** it is sent, with what it may cost and what it leaves behind; money spent is reported.
2. Existing listings the owner did not name are never changed, closed or adopted. Listings are closed, never «STOP»-ed.
3. An existing feed link and schedule are read, kept, and restored; nothing of theirs is removed.
4. `autoload/v1/upload` only on the owner's word for that run: it publishes everything publishable.
5. An order only with the owner's separate permission, after support answered.
6. Keys are never printed; no token, header dump, option dump, seller name or buyer data goes into a transcript, a log or
   the measurement file.
7. On a client's cabinet (written consent) only read calls are made; no scoped key is documented, so this is discipline.

| # | Measure | Settles |
|---|---|---|
| M1 | What every call answers **without** the subscription, without company status, without delivery: status and body, an empty list, or silent success | §6 — whether D1 can be done at all |
| M2 | Category tree and the fields of 2–3 chosen categories: value lists, `dependencies`; which sections D4's list maps to (spare parts included) | §11, AF7 |
| M3 | Autoload profile: read, add a second link beside an existing one, whether `agreement` is demanded, meaning of `rate`; whether Avito downloads a URL served by PHP (query string, REST path, `.xml` ending); fetch times, user agent, compression | §5.6, §12 |
| M4 | A feed of 2–3 ads: time from fetch to report, report v4 shapes, `avito_status` values, message codes, id mapping both ways, batch size of `ad_ids` | §9 |
| M5 | Schedule against «upload now»; what a publication costs on the tariff; what `ListingFee: Package` does without a bought package (C2) | §12 |
| M6 | Absence from the file → archive, and how fast; the same `Id` back → reactivation and its cost; a past `DateEnd` closes one ad | §5.5 |
| M7 | The feed link answers 404, 500 or times out — **alone, and beside a second working feed**: are our ads archived? (test ads only) | §5.7, T3, T4, T14 |
| M8 | The same `Id` in two feeds of one profile (an old generator still running) | §10 |
| M9 | Adopting a hand-published ad by `AvitoId`: which fields are replaced; what happens to paid options it had | §10, T8 |
| M10 | Stock write and read: reserved units, the «Несколько штук» switch, a listing not published by autoload, restore after an order | §7 |
| M11 | Price by API, then a load with an older price (C7); which categories accept it | §8 |
| M12 | Photo limits in the chosen categories; an unchanged URL is not downloaded again | §11 |
| M13 | Orders (read, then one permitted order): statuses, `items[].id` against the feed `Id`, `availableActions`, deadlines, `serviceType` values (C8), a line with count above 1 | §13 |
| M14 | Courier order: the shape of the time ranges; whether `cncSetDetails` is also required; markings before confirm | §13.3, §13.4 |
| M15 | Labels: task, download before readiness, formats, ids per request (C3) | §13.5 |
| M16 | Rate-limit headers on ordinary calls; limits of the autoload reads. **No 429 is provoked** | §18 |

Output: `docs-internal/research/2026-11-26-avito/measurement.md` with fixtures; every item **proven**, **refuted** or
**still open**. M0a (read only) = M1 (read calls), M2, M3 (read), M13 (read), M16. M0b (feed, stock, price) = M1
(writes), M3–M12. M0c (one order) = M13–M15. If the permission of rule 5 is not given, M13–M15 stay open and become the
residual risks of §20.4.

## 4. Capability → seam map

**fits** — the plugin implements existing contracts; **delivered** — a Wildberries card built what Avito needs
(release that carries it); **AF n** — a new framework card of §14; **plugin** — Avito-only code. All READ at `cd2495ce`.

| Capability (G) | Seam today | Verdict |
|---|---|---|
| Menu, pages, accounts, secrets, jobs, governor, bulk runs, licence gate, diagnostics, deletion (1–3) | module as released | fits |
| Categories, fields (4) | `Catalog_Schema`; `Field_Definition` has no conditional rules | **AF7** |
| Values per account and per product (5) | mapping and profile sources (fixed, attribute, meta, product field). Per-link `attribute_overrides` are honoured (`flows/class-link-context-resolver.php`) but **no screen writes them** (`admin/product/class-link-form-input.php` takes the article, sync flags, warehouse, mapping and prices only). `Settings_Extension` values are shop-wide or product meta, selects and switches only | **AF8** |
| Publication as a file (6) | nothing builds or serves a feed | **AF1, AF2, AF3** (new) |
| Result without a task number (7) | **delivered** by WF2 (2.1.21): `Article_Keyed_Lifecycle`, `Import_Deadlines`; with #2040 (2.1.26) `Releases_Reset_Imports` | delivered; poll cadence **AF4** |
| Link states, late ids, report text at the product (8) | link records; **delivered** WF12 (2.1.24) `Link_Notes` | fits / delivered |
| One link per product (9) | unique keys of the links table | fits; WF3 (size units) not used |
| Linking existing listings (10) | `Card_Matcher` (exact normalised SKU = article, merchant-started run), manual link on the cabinet list | fits |
| Taking a linked listing under management (10a) | export of a linked card re-sends it; no marketplace warning, no hook for one | **AF9** |
| Export and linking without import (12) | **delivered** WF11 (2.1.23): `Capability::CATALOG` | delivered |
| Stock without a warehouse (13) | no warehouse → nothing sent (`sync/class-stock-intents.php`, `diagnostics/class-why-not-syncing.php`) | **AF5** (new) |
| Stock rules and read limit (14) | `Stock_Rules`, `Reports_Read_Requests` | fits |
| Price send and read-back, markup and rounding (15–16) | `Price_Pusher`, `Price_Rule`, `price_reader()`; price batch 1 | fits; WF1 and WF4 not needed unless M11 shows otherwise |
| Order ingest and data (17–18) | `Posting_Handler`, polling every 10 minutes, nullable buyer | fits |
| Cancel without reasons (19) | **delivered** WF6a (2.1.27) `Reasonless_Canceller` | delivered |
| Seller actions with input (20) | **delivered** WF6a: `Seller_Actions`, text / code / select fields, per-unit answers, send-once claims. Options of a list field get no order (`Seller_Action_Options::seller_field_options( account, action, field, search )`); the orders table has fixed columns and no deadline | delivered; **AF10** |
| Unit rows in the orders table | WF6b (#2004, PR #2051 **open** on 28.11.2026) | in flight; needed for marking from the table only |
| Labels (21) | `Label_Provider` task → file; **delivered** WF7 (#2005, on main, planned 2.1.28) `Selectable_Label_Format` | fits / delivered |
| Notifications (22) | nullable decoder | not used |
| Parts unavailable per account | only whole-account health states (`jobs/class-account-pause.php` pauses on `ROLE_MISSING`) | **AF6** (new) |
| Money safety (24), autoload profile (25) | — | plugin |
| Merchant wording (26) | **delivered** WF11 `Posting_Wording`, neutral mapping sentence; «карточка» (about 160 translation calls) and «склад» (35) are fixed | **AF11** |
| Dead adapter methods (27) | **delivered** WF11 (deprecated) | delivered |

Not used by 1.0: WF5 (typed `client_id` fits), WF8 «Поставки», WF9 seller-delivered orders, WF10 and WD1 carrier
bridge. WF8, WF9, WF10 and WD1 were not merged on 28.11.2026.

## 5. Publication by feed

### 5.1 The unit

**One sellable unit — a simple product or one variation — is one link and one listing** (D8, I2). Variations of a
product are separate listings; Avito's `MultiItem` only groups them (SOURCED).

- The link's article (`offer_id`) is the file **`Id`**: at most 100 characters and never changed (SOURCED) — the
  module's frozen article. A new listing takes it from the profile's article source; an adopted one keeps the `Id` it
  already has; a hand-published listing with none gets a made-up stable one, `ad-<listing number>` (I3).
- The Avito listing number is the remote identifier `listing_id`, learned late from the report or the id lookup. Every
  inbound reference is resolved **by `listing_id` first**, then by `Id`: a made-up `Id` must not be replaced when the
  ids become readable later.

### 5.2 Export means «put into the file»

The export flow is reused unchanged (I4): the merchant exports, the validator checks, the batch job builds items, and
**`submit()` stores them in the feed store** and answers «accepted, no task number». The result is read by articles from
the load report (§9). The framework provides the gateway base class and the store; the adapter builds one item and, by
the new optional interface of AF1, writes the document around them. No remote request happens in `submit()`.

### 5.3 When a stored item changes

An item is rebuilt on export, and **by itself** when its product or parent, mapping, profile, account default or
per-product value changes (I5, R6). A rebuild that fails validation **keeps the last good item** and shows the reason at
the product — dropping it would archive the listing (D6). The **price is laid over the item while the file is
assembled**, from the same calculation as the API send (D7).

### 5.4 Which listings are in the file

Only listings put there by an export. A listing that is linked but not under management is not in the file, and the
plugin cannot archive it: no API call exists (SOURCED, API §4). Its product says «Это объявление ведётся не из
магазина: снять его можно в кабинете Авито».

### 5.5 Leaving and coming back (D6)

| In the shop | The file | Avito |
|---|---|---|
| Out of stock | item stays | stock 0 by API (§7) |
| Product or variation moved to the trash, deleted, or its status is no longer «опубликован» (I6) | item removed; warning | archived at the next load |
| Restored or published again | item returns with the same `Id` | reactivated (SOURCED; cost M6) |
| «Снять связь» | item removed; the confirmation says the listing goes to the archive (T3) | archived |
| «Сбросить состояние», account switched off, licence not in force | unchanged (§5.7) | — |

Today the module acts only on trash and permanent deletion, and **through the stock queue** (READ
`sync/class-stock-intents.php`, `flows/class-deleted-product-releaser.php`). Leaving the feed must not depend on stock
access or stock sync, and a status change to draft or private is not watched at all: AF2 adds both. A link in the
module's `archived` state is refused by the export queue (READ `data/class-wpdb-link-store.php`, `queue()`), so a
listing the shop can bring back must come back **by itself** when the product returns (AF2). `Stock_Rules::archive()`
answers true: the feed does the archiving.

**The warning of D6** cannot block a deletion: it is an admin notice after the change and a line on the «Автозагрузка»
page, «При следующей загрузке уйдут в архив: …» (I7).

**The empty file.** An empty file is **not processed** (SOURCED, P13, P17), so absence cannot close the last listing.
When no item remains included, the removed ones are written with a **past `DateEnd`**, which closes each by itself.
`<Id>STOP</Id>` is never used: how far it reaches across several feeds is unknown (I8). Removed items therefore stay in
the store until a file with an included item has been served.

**A mass change** — an import tool unpublishing the catalogue — archives everything in the next load, as D6 says. The
research card plan proposed holding a suddenly shrunk file for the merchant; it is **not** built unless the owner asks
(T5).

### 5.6 Assembly and the URL

- A resumable job streams the file to a temporary path and **renames it into place only when complete**. A build that
  failed, was cut short or exceeds a limit leaves the previous file served (D6), and the page says since when and why.
- Triggered by item changes, delayed by a short pause so that a bulk edit builds once, and once a day (the pause is a
  constant with a filter).
- Limits: 500 MB, and 50 000 ads per file (SOURCED, P13, P14; the report sample of the autoload specification speaks of
  100 000 across files). One file per account. Acceptance: 10 000 ads without exhausting memory.
- Served by PHP at an unguessable per-account URL, without a login, `no-store`; every fetch is recorded (time, user
  agent, answer) in a capped log. The exact URL shape follows M3. Before the first complete file the URL answers 404,
  never an empty file.
- Avito needs ports 80 or 443 and no self-signed certificate (SOURCED, P13): the connection check says so. A new token
  or a changed site address needs a profile update, which the daily profile check reports (§12).

### 5.7 When the shop stops

| Event | Behaviour | Open |
|---|---|---|
| Licence not in force | no rebuild, no remote write; **the last file stays served** (I9) | T4 |
| Account switched off | the same | |
| Account deleted | the deletion preview says the file link will stop working and asks the merchant to remove it in the cabinet; the plugin does not touch the profile (I10) | T3, M7 |
| Plugin deactivated, site down | the URL fails; what Avito does is M7 | T14 |

## 6. Parts switched off by access (D1)

**New seam, AF6.** An optional adapter interface answers, per account, for each part — publication, article ids, stock,
prices, orders — `available`, `unavailable` with a merchant reason, or `unknown`. Jobs skip an unavailable part; its
buttons and settings stay visible, off, with the reason (the «Заказы доставки» mechanism); diagnostics list it. An
adapter without the interface behaves as today.

**How the plugin knows.** No API tells a goods seller's tariff or subscription (SOURCED, `tariff-composition` covers
other sections). A part becomes unavailable only from an answer **M1 showed to mean «no access»**, never from a guess
and never from a write made to probe. It is read again by the button «Проверить доступ» and at most once a day, with a
read call. If M1 shows that Avito answers success and does nothing, that part **cannot be detected**: its settings then
state the requirement in words and leave the switch to the merchant, and the owner is told that D1 holds only in part
(T2).

## 7. Stock (D6)

- **No warehouses (AF5).** A new capability, `Capability::NO_WAREHOUSES`: the pair's warehouse is `0`, «the listing
  itself». Today a missing warehouse stops the send in the intents, the created-card seeder, the read-back grouping and
  the reasons, and asks for a choice in the account settings, the product tab and the bulk actions (READ
  `sync/**`, `flows/class-card-created-seeder.php`, `diagnostics/**`, `settings/**`, `admin/**`). All of it must treat
  `0` as a real destination and show no warehouse control. `Stock_Pusher` keeps its signature and receives `0`;
  `sync_state.warehouse_id` is already `NOT NULL DEFAULT 0` (READ `data/class-schema.php`), so no schema change. The
  vacate rules of 2.1.25–2.1.26 apply to positive ids only and stay.
- **Write** `PUT /stock-management/1/stocks`, ≤ 200 lines, by `listing_id` (0…999 999). Without one yet, the line waits
  (`NOT_READY`). **Read** `…/info`, 10 ids per request (conflict C4), through `Reports_Read_Requests`.
- **Formula `wc_only`** (I11): the WooCommerce order created for an Avito order already takes the units from shop stock.
  The read returns «quantity less reserved» and no reserved figure (SOURCED), so the other formula has no input. M10
  confirms or changes it.
- «Несколько штук» off means one order per listing, then archive after payout (SOURCED, P18); whether an API write
  turns it on is M10. Categories without quantities (animals, water transport, ready business; phones only new) show
  Avito's refusal at the product.
- A seller with publication but **no stock access** cannot send 0: **T1**. Reading taken: the listing stays, and the
  product and the page say «Остатки не передаются: …; проданный товар снимите сами».

## 8. Prices (D7)

The module's price rule gives markup (none, fixed, percent) and rounding. Avito takes whole rubles: the adapter rounds
**up** (I12). API: `update_price`, one listing per call, 150 a minute, for any linked listing whose price sync is on.
Feed: the same figure (§5.3). No old price is sent. Sources disagree whether an API edit survives the next load (C7),
and a file downloaded before a change may still be processed after it: after each processed upload the plugin compares
the read-back with the shop price and queues the ones that differ (I13; M11 decides whether this stays).

Whether stock and price go to a linked listing **before** it is under management follows the module's existing switches,
including «sync on link»; the confirmation of linking already names the effect (T9).

## 9. Load report and listing states

- A job asks the upload history (I14: every 15 minutes). For each newly processed upload it reads the ads of
  `current` / `last_successful` (100 per page) and stores, per link: report section, `avito_status`, messages, public
  URL, listing number, end date. Per-ad detail exists **only** for those two uploads (SOURCED), so each new one is read
  when it appears.
- `read_import_by_articles()` answers **from that store**, not by a request per task. An entry counts for an export only
  if its upload started after the item's current version first appeared in the served file (I15).
- **Deadlines.** The schedule may run on few weekdays and processing may take 24 hours longer (SOURCED): the adapter
  declares `Import_Deadlines`, 8 days for both (I16, S0 fixes the value), and `Releases_Reset_Imports`.
- **Poll cadence (AF4).** Today a task is polled every minute for ten minutes, then every five, until its end (READ
  `jobs/class-poll-task-runner.php`): 10 000 ads are 100 tasks and about 28 800 idle runs a day. A new optional
  interface beside the lifecycle names a slower cadence.
- **States.** `active` → created; `blocked`, `rejected` → an error with Avito's text; `old`, `archived`, `removed` for
  an ad **still in the file** → a note with that status, not the module's `archived` state (§5.5). A report read after a
  task's deadline still moves the link to its real state (AF2). The exact table follows M4.
- Messages show at the product (`Link_Notes`) and in the products table; a warning does not hide that the ad is live.

## 10. Existing listings (D5)

1. The cabinet list reads `GET /core/v1/items` page by page (25 a minute, under 100 each; it has no SKU or `Id`), and
   asks `ad_ids` for the file `Id`s — **only with publication access**.
2. **Automatic linking** is the module's «Связать по артикулу», started by the merchant (I17): an `Id` equal to a
   product's SKU links. Without publication access no `Id` is known and **only manual linking works** — D5's automatic
   half needs the subscription.
3. **Manual linking** is the existing screen (G 10; read in code, not tried).
4. **Taking under management** = «Экспортировать» on a linked listing: the validator requires every required field; a
   confirmation from the new seam (AF9) says that description, photos and fields will be replaced by the shop's; the
   item carries `AvitoId`; the profile gets our link beside the others (§12).
5. The merchant is told to **remove the adopted ad from the earlier generator's file**, and that removing that whole
   link before every ad is adopted archives the rest. What two files with one `Id` do is M8.
6. Before an account's first publication, if the cabinet list holds active listings without a link, the page warns that
   exporting the same goods may create duplicates, which Avito blocks (I18).

Import into products is not possible (G 11); the adapter declares `CATALOG` without `IMPORT_PRODUCTS`.

## 11. Categories and fields (D4, D8, D11)

- Tree and fields from `autoload/v1/user-docs/…`, cached by the catalogue job. Only the goods sections of D4 are offered
  (the list comes from M2). The category screen and the merchant docs say which 2–3 categories were checked.
- **Conditional fields (AF7):** an optional interface beside `Catalog_Schema` gives the rules «visible / required /
  hidden when field A has value B» (and/or pairs, SOURCED); the mapping screen hides what must stay empty and the
  validator asks for what became required.
- **Where a value comes from (AF8):** product's own value → parent product's → the category mapping → the account
  default. AF8 adds the account default for adapter-declared fields (text and choice) and an editor of per-product
  values on the product tab, writing the overrides the module already honours. Address, contact, `AdType`, condition,
  delivery options use it; **the address is required** and one per account is the default (D8).
- Category fields use the tree's value lists; **ТН ВЭД, ОКПД 2, GTIN and document fields** map to product attributes or
  meta with existing sources (D11).
- **Rules of validation:** title ≤ 50, no contacts; description ≤ 7500 with the allowed HTML; at most 10 images, JPEG or
  PNG, ≤ 25 MB; integer price; `CountryExpedition` and the category's required fields; weight and dimensions where
  delivery is on.
- **Photos:** WebP and other formats are converted to JPEG files served from the site; a changed picture gets a new URL,
  because Avito does not download an unchanged URL again (SOURCED).
- **Never offered and never written:** `AdStatus`, every `Promo…` field and any other paid option (D9). `ListingFee` is
  written as `Package` (conflict C2, M5).

## 12. Autoload profile and money (D9)

- The profile is **read, merged and written back**: our link is added or updated, no other link or setting of the
  merchant is removed. Three links already taken → nothing changes and the page says so.
- The schedule is shown as it is. It is written only after the merchant confirms the exact days, hours and number per
  slot; the plugin never changes an existing schedule by itself.
- No profile yet → the merchant creates it and accepts Avito's rules in the cabinet; the plugin does not send
  `agreement` on their behalf (I19).
- A daily read reports: our link missing or pointing elsewhere, autoload off.
- **«Загрузить сейчас»** (≤ once an hour) confirms first: the schedule's publication limits do not apply, so everything
  publishable is published and may use up the placement package or the view budget.
- A profile write is a remote write: it is recorded by `First_Remote_Write` like every other and sits behind the licence
  gate.
- **What «never spends» cannot cover** (T8): on a tariff paid by views, a published listing costs money through the
  tariff itself. The plugin says so where publication is switched on.

## 13. Orders (D3, D11)

### 13.1 Ingest

Polling only (no order webhook — SOURCED): the posting job, every 10 minutes, reads `GET /order-management/1/orders`
(20 a page) for new orders by status and creation date, and re-reads open ones by id (≤ 20), because the date filter is
the creation date. **One Avito order is one WooCommerce order**; the API `id` is the external id, `marketplaceId` the
number shown to people. A line is matched by `items[].id` (expected to be the file `Id`, M13), else by `listing_id`.
Money: line totals are the goods' prices; commission, discount and «to receive» are kept in order meta and shown in the
details box (I20).

| `delivery` type | In 1.0 |
|---|---|
| `pvz`, `postamat`, `courier` | ingested with the actions of §13.3 |
| `dbs`, `rdbs`, `cnc` | ingested, stock reduced, shown with «Действия с этим заказом — в кабинете Авито» and its deadlines (D3, T11); buyer data written where Avito gives it |
| unknown | treated as the second row |

The type enum in the specification is incomplete (C8): M13 fixes the reading. A partner-delivery order has **no buyer**
and is created without one.

### 13.2 Statuses

| Avito | Module status |
|---|---|
| `on_confirmation` | new |
| `ready_to_ship` | awaiting shipment |
| `in_transit` | delivering |
| `delivered`, `closed` | delivered |
| `canceled` | cancelled |
| `in_dispute`, `on_return` | arbitration |

The adapter keeps Avito's own names through the module's status-name seam; `on_return` and the return statuses are
**interpretation** I21 until M13.

### 13.3 Actions and deadlines

| Action | Call | When offered |
|---|---|---|
| «Подтвердить» (row, bulk) | `applyTransition` `confirm` | pickup point, parcel locker, while Avito lists it in `availableActions` |
| «Выбрать время курьера» | `getCourierDeliveryRange` → `setCourierDeliveryRange` (which confirms) | courier |
| «Отменить» | `applyTransition` `reject`, no reason (`Reasonless_Canceller`) | partner types, while available |
| «Честный знак», «Отозвать коды» | `markings` | before confirmation (§13.4) |

- **AF10:** a list field whose choices depend on the order (the courier ranges). The poll stores the ranges of courier
  orders awaiting confirmation (refreshed at most hourly, I22), so opening the dialog calls nothing, as today. A range
  Avito refuses as stale is reported and re-read.
- Missing a required action's deadline **cancels the order** (SOURCED). `confirmTill` and `shipTill` show in the details
  box (the existing filter `woodev_marketplace_{id}_order_details`) and, with AF10, in the orders table. The plugin
  **never confirms by itself** (I23).
- The orders table's second column is headed by the posting noun; «заказ» would repeat the first column «Заказ». AF11
  settles the noun.

### 13.4 «Честный знак» (D11)

A unit action on an order line (count N), with up to ten code fields — the declared maximum of an action equals Avito's
ten codes per item (READ `admin/order/class-seller-action.php`; SOURCED). Offered **before** confirmation; for a courier
order therefore before the time is chosen. An action Avito marks `required` and not yet done keeps «Подтвердить» off
with the reason. «Отозвать коды» sends the empty list. The code fields keep the GS separator **only on the order screen**
(READ the wiki's note on #2003), so entry is there; the table follows only if the records engine learns it. Codes are
never logged and are kept only while the order is open.

### 13.5 Labels

`Label_Provider` as it is: create the task, download the PDF (pending until it exists, M15). **Pickup-point orders
only**; other rows show the button off with that reason. Format A6 or thermal 58×40 is an account setting
(`Selectable_Label_Format`). 50 orders per request (C3: the smaller figure); the once-a-minute extended method is not
used.

## 14. Framework extensions

Every card: keeps Ozon Seller 2.0.x and the Wildberries paths behaving (their tests stay green; a **fake feed
marketplace** proves the new path); adds only **new optional interfaces**, capability constants and classes — no new
argument on an existing adapter method
([gotcha](../gotchas/a-method-added-to-an-adapter-interface-fatals-released-plugins.md)); changes the schema only
additively with a `Schema::REVISION` step (7 on main; the open WF6b and WF8 pull requests may take the next numbers);
touches no delivery code. Interface names below are proposals the card may refine.

| Card | Problem | Design | For Wildberries and Ozon |
|---|---|---|---|
| **AF1 — Feed publication** | G 6 | Feed gateway base (`submit()` stores items); table `feed_items`; optional `Feed_Document` (document head and tail, one item with the live price, a closed item, limits); resumable streamed assembly with atomic swap; tokenised route; fetch log | unused |
| **AF2 — Feed lifecycle** | §5.3–5.5, §5.7, §9 | Rebuild on changes; leave and return on trash, deletion, status, unlink, independent of stock; closed items for an emptied file; return of a listing without the `archived` dead end; a service by which a report moves a link's state | unused |
| **AF3 — Page «Автозагрузка»** | no screen for any of this | A records and settings page offered to a feed marketplace: file state and URL, last build and its errors, fetches, last upload summary, «уйдут в архив», actions the plugin declares (profile, schedule, upload) | unused |
| **AF4 — Poll cadence** | §9 | Optional interface beside the lifecycle: intervals of the `poll_task` job | open to both; default unchanged |
| **AF5 — No warehouses** | G 13 | §7 | none |
| **AF6 — Parts per account** | D1 | §6 | open to Wildberries' «missing category» case |
| **AF7 — Conditional fields** | G 4 | §11 | open to both |
| **AF8 — Account defaults, per-product values** | G 5 | §11 | open to both |
| **AF9 — Warning before taking a listing over** | G 10a | Optional adapter interface: a sentence, or a refusal, for exporting a link the plugin did not create; shown by the product tab, the table and bulk runs | none |
| **AF10 — Order additions** | §13.3 | Optional interface for list choices of given orders (beside `Seller_Action_Options`); optional order deadline shown as a table column and in the details box | open to both |
| **AF11 — Card and warehouse wording** | G 26 | Optional descriptor interface, as `Posting_Wording`, for the card noun («объявление») and its cases; no card or warehouse word left where the descriptor decides; the name of the marketplace only from the descriptor (T6) | Ozon and Wildberries keep «карточка» |

D12 «designed for both»: the changes Wildberries and Avito shared in the research — results without a task number,
catalogue gating, cancel and actions, wording of postings, dead contracts — are **already built**. What is left is
Avito's; where a seam is general it is written for any marketplace.

## 15. Plugin parts

Namespace as in §2, include-based autoloader as in Ozon Seller.

- **Connection.** `client_id` (typed identifier) and `client_secret`; `POST /token`, valid 24 hours, no refresh token —
  cached, renewed once on 401, never logged. `GET /core/v1/accounts/self` gives `user_id` → `seller_id`; a second
  account of the same `user_id` is refused (I24: several keys may exist for one cabinet, and two accounts would fight
  over one profile). Health, buckets, licence, diagnostics without secrets.
- **Catalogue and mappings** (§11) · **items, photos, document writer** (§5, §11) · **profile, schedule, upload, money
  safety** (§12) · **report reader and notes** (§9) · **cabinet list, matching, adoption** (§10) · **stock and price
  writers and readers** (§7–8) · **order handler, status map, actions, deadlines, markings, labels** (§13).

## 16. Admin pages

PHP-driven React pages of the module; every table follows the «Заказы доставки» mechanisms (R3), and each UI brief
names its counterpart there and requires a real-browser look. Words for the merchant: «объявление», «остаток», «файл для
Авито» rather than «фид».

| Page | Content |
|---|---|
| **Товары** | «Товары сайта» and «Объявления на Авито»: state, load messages, link to the listing, reasons a part is off |
| **Заказы** | Rows with delivery type, deadline, actions of §13.3, labels |
| **Автозагрузка** (AF3, I25) | The file, its URL and state, Avito's downloads, the profile and schedule, last load, «Загрузить сейчас», listings about to be archived |
| **Сопоставление** | Categories and conditional fields, profiles |
| **Инструменты**, **Диагностика** | The module's, plus «Проверить доступ», per-part availability, bucket and queue state |
| **Настройки** | Accounts (keys, default address and contact, label format), sync |
| **Аналитика** | The shared page, unchanged |

## 17. Data model

The module's per-plugin table set, plus (additive, empty for other marketplaces):

| Addition | Card | Holds |
|---|---|---|
| `feed_items` | AF1 | account, article, link, the built item, state (included, removed, why), last build error, when this version was first served. Outlives its link while §5.5 needs it |
| feed state and fetch log | AF1 | per account: token, current file, build time and error, capped list of fetches |
| per-account part availability | AF6 | state, reason, when learned |

**Plugin-owned:** a report table (per link: upload, section, status, messages, URL, end date); remote id `listing_id`;
order meta (delivery type, shown number, deadlines, available actions, courier ranges, money breakdown, marking states);
account settings (defaults of AF8, label format). The secret is encrypted in the accounts table; no option holds a
secret. Files live in an uploads folder of the plugin under unguessable names and are removed on uninstall.

## 18. Errors and limits

| Answer | Class | Merchant sees |
|---|---|---|
| 401 | renew the token once, then auth failed → account paused | the keys are not accepted |
| A «no access» answer as M1 defines it | that part unavailable (§6) | the reason at the part |
| 403 otherwise | permanent for the call | Avito's text |
| 429 | throttled; wait by the headers where given | nothing unless it lasts |
| 200 with a per-line stock error | verdict per line | the reason at the product |
| Order action refused (`incorrect_number`, `already_set`, …) | business refusal, not retried | the reason at the order |
| Report messages | stored per link | at the product |
| 5xx, timeout | retry with the module's backoff | nothing unless it lasts |

Buckets (SOURCED, per minute; the specs do not say per what): stock write 100, stock read 100, listing list 25, listing
info 500, price 150, order list 500, order actions and courier ranges 100, courier set 100 (C5: the lower), labels 1000,
upload 1 per hour. Token and autoload reads have no stated limit: 60 a minute (I26, M16).

**Worst case per account per day** (10 000 listings, 100 open orders): token about 1 · orders 144 polls × (new pages + 5
id reads) ≈ 900 · courier ranges at most 24 per waiting courier order · upload history 96 · report pages 100 per newly
processed upload, up to 200 when `current` and `last_successful` differ · listing list about 100 per refresh, at most
daily plus on demand · profile 1 · stock reconcile about 1 000 reads, writes by change (a full resend is 50) · prices one
per change (a full resend 10 000, about 67 minutes). Every automatic call backs off and stops at a cap; nothing here goes
to woodev.ru.

## 19. Tests

- **Unit and contract** (PHPUnit with Brain Monkey, no network): a fake feed marketplace with positive and negative
  controls — an interrupted build serves the old file; a failed item keeps its last version; trash, draft, restore,
  delete and unlink in every order; the emptied file carries closed items and never `STOP`; no paid tag can be written;
  a report older than the served item finishes nothing; a missing part skips its jobs; a pair with warehouse `0` is sent
  and Ozon's warehouse paths are unchanged; conditional rules; value precedence; a stale courier range.
- **Golden documents** for the checked categories, checked by hand once with Avito's XML checker (M4). Phase-0 pairs are
  the fixtures; where the documented shape and an observed one differ, S0 states which wins.
- **Load:** 10 000 items assembled within memory limits.
- Jest, phpcs, phpstan, `lint:i18n`, `lint:docs`; delivery tests untouched.
- **Live acceptance** only on the owner's cabinet under the rules of §3, repeated in every brief; the acceptance report
  lists what was not proven. The coordinator looks at every admin screen in a real browser before «ready».

## 20. Registers

### 20.1 Unverified facts

All of API §1–9's UNVERIFIED lines that 1.0 depends on are items M1–M16. Outside them: the legal reading of terms 1.1,
2.2 and 2.7 (support letter); category specifics beyond the checked ones; the manual-linking path with an Avito adapter
(AP6, on the rig).

### 20.2 Interpretations (the coordinator may revisit)

| # | Interpretation | Where |
|---|---|---|
| I1 | Working name and placeholder identifiers, frozen before release | §2 |
| I2 | «One product» in D8 is one sellable unit | §5.1 |
| I3 | A made-up `Id` `ad-<number>`; resolve by listing number first | §5.1 |
| I4 | The feed is built on the export flow; `submit()` stores | §5.2 |
| I5 | Items under management follow shop edits by themselves | §5.3 |
| I6 | «Unpublished» is any status but «published», a disabled variation included | §5.5 |
| I7 | D6's warning is a notice after the change, not a block | §5.5 |
| I8 | An emptied file closes by past `DateEnd`; `STOP` is never used | §5.5 |
| I9 | Without a licence the last file stays served | §5.7 |
| I10 | Account deletion does not edit the autoload profile | §5.7 |
| I11 | Stock formula `wc_only` | §7 |
| I12 | Price rounded up to a whole ruble | §8 |
| I13 | Prices re-compared after each upload | §8 |
| I14 | Upload history every 15 minutes | §9 |
| I15 | Only an upload started after the served version answers an export | §9 |
| I16 | Import deadlines 8 days | §9 |
| I17 | D5's «automatically» is the merchant-started linking run | §10 |
| I18 | A duplicate warning before the first publication | §10 |
| I19 | The plugin does not accept Avito's autoload rules for the merchant | §12 |
| I20 | Commission and discounts shown, not turned into order lines | §13.1 |
| I21 | Mapping of dispute and return statuses | §13.2 |
| I22 | Courier ranges stored by the poll, at most hourly | §13.3 |
| I23 | No automatic confirmation | §13.3 |
| I24 | One account per cabinet | §15 |
| I25 | A page «Автозагрузка» in the framework; several accounts stay as the module offers them (a bot's note on #1986 left them out of v1; no owner decision does) | §16 |
| I26 | 60 requests a minute where Avito states no limit | §18 |

### 20.3 Tensions the coordinator must hear about

Each has the reading this spec took and what would settle it. **T1 and T8 are places where a decision cannot be kept to
the letter; T2 may become a third.**

| # | Tension | Reading taken | Needs |
|---|---|---|---|
| T1 | **D6 «out of stock → stock 0» against D1 «parts without access are off».** A seller who may publish but may not send stock (no company status) cannot write 0, so a sold-out listing stays orderable | The listing stays, with a plain warning. The alternative — drop it from the file when out of stock — breaks D6 and archives and re-publishes on every restock | owner |
| T2 | **D1 needs knowing the access; no API states it**, and the refusals are unmeasured; they may be silent | Learn only from measured refusals; where Avito stays silent, state the requirement and leave the switch to the merchant | M1, then owner if silent |
| T3 | Unlinking and deleting an account end the file's hold on listings: absence archives them | Unlink removes and warns; deletion leaves the profile alone and tells the merchant | M7, owner |
| T4 | Licence gate against the feed: serving it is how listings stay alive, yet a frozen file ignores later deletions and may bring old prices back at the next load | Serve the last file, change nothing | owner |
| T5 | A mass unpublish archives the catalogue — correct by D6, costly by mistake. The research card plan proposed holding a shrunk file | Not built (no invented safeguards; D6 is clear) | owner |
| T6 | API terms 2.7 forbid Avito's name and marks «in an application»; that may reach menu titles and screen texts, which must still say where the goods go | The name of the marketplace lives in one place (the descriptor); texts are final after the support answer | support letter |
| T7 | D2 holds the name until support answers; a release cannot happen without it, and answers can be slow | Build under placeholders; AP10 blocks the release only | owner if support stays silent |
| T8 | **D9 «never spends money»** against publication itself costing on a view tariff, a package that runs out, «Загрузить сейчас», and an adopted ad whose paid options the shop's item does not carry | The plugin writes no paid field, warns, and orders nothing; M5 and M9 show what adoption and publication charge or switch off | M5, M9, owner |
| T9 | The research card plan kept stock and price off a listing until it is under management; the module may send them at linking («sync on link»). Sellers without publication access never adopt | The module's switches decide | coordinator |
| T10 | D7 «by API and in the feed» against C7: a load may overwrite an API price with an older file | Rebuild promptly, re-compare after uploads | M11 |
| T11 | D3 leaves some orders view-only, yet their deadlines cancel them; if a courier order needs `cncSetDetails` (M14) it joins 1.0 | Shown with deadlines and «в кабинете Авито» | M14 |
| T12 | D4 «2–3 categories checked» against thousands of leaf categories offered | Offer the goods sections, say what was checked | — |
| T13 | Terms 1.1 speak of registering the application through support | A release gate on the support answer | support letter |
| T14 | A site outage or deactivation may, with a second feed present, archive our listings | Stated in the merchant docs; behaviour measured | M7 |

### 20.4 Residual risks if phase 0 leaves them open

| # | Unproven | What the design does | Merchant told |
|---|---|---|---|
| RR1 | Order actions, courier ranges and deadlines on a real order (no permitted order) | Built from the specification, fixtures; a refusal shows Avito's words; the cabinet remains the way | release notes, docs |
| RR2 | Labels | The same | the same |
| RR3 | Marking before confirmation | The same | the same |
| RR4 | The stock formula against real reservations | One adapter value to change; reconcile shows drift | — |
| RR5 | Behaviour of absence, outages and two feeds beyond what M6–M8 could show | Closed items, warnings, atomic file | docs |

## 21. Implementation card list

Not filed (the coordinator files them). S / M / L are relative size. Model tiers follow the project rule (Haiku 5.5 for
bounded work with a detailed brief, Sonnet 5.5 for routine work against this plan, Fable 5.2 for the hardest
correctness work, one at a time); every card gets a critic of another lineage. **Implementation starts after
Wildberries 1.0 (D12); phase 0 may run before it.**

### Phase 0

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| M0a — Read-only measurement | S · Sonnet 5.5 | keys; the support letter sent | §3 read items. Accept: each proven, refuted or open; no write in the request log; no secret or personal data in any file |
| M0b — Feed, stock, price | M · Sonnet 5.5 | M0a; access list; public URL | §3 M1, M3–M12. Accept: every write listed before it was sent; money reported; the profile restored; test ads closed |
| M0c — One order | S · Sonnet 5.5 | M0b; separate permission | M13–M15; or the items declared open |
| S0 — Amend the spec, confirm scope | S · coordinator | M0a, M0b, (M0c) | Measurements folded in; limits and deadlines fixed; T1–T8 put to the owner; **the 1.0 scope confirmed (D13)**; cards re-estimated |

### Framework (`woodev-plugin-framework`)

«Files» are under `woodev/marketplace/`; each card may also touch its tests, `woodev/class-map.php` and, for screens,
the marketplace admin client with its built bundle.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| AF1 — Feed publication | L · Fable 5.2 | S0 | new `feed/**`, `data/**`, `jobs/**`, `class-flow-wiring.php` | §5.2, §5.6. Accept: fake marketplace exports into a file at a URL; interrupted build serves the old one; 10 000 items; API-push marketplaces get no table writes, route or job |
| AF2 — Feed lifecycle | L · Fable 5.2 | AF1 | `feed/**`, `flows/**`, `sync/**` (archive intents) | §5.3–5.5, §5.7, §9 states. Accept: the table of §5.5 on HPOS-independent product events, the emptied file, return after trash and draft, a report after the deadline |
| AF3 — Page «Автозагрузка» | M · Sonnet 5.5 | AF1, AF2 | new `admin/pages/feed/**`, `class-marketplace-plugin.php` | §16. Accept: no page without the feed; a read makes no remote call; the «Заказы доставки» counterpart matched |
| AF4 — Poll cadence | S · Haiku 5.5 | S0 | `flows/`, `jobs/class-poll-task-runner.php` | §9. Accept: a slow fake polls by its cadence; others unchanged |
| AF5 — No warehouses | M · Sonnet 5.5 | — | `capabilities/**`, `sync/**`, `stock/**`, `diagnostics/**`, `flows/class-card-created-seeder.php`, `settings/**`, `admin/**` | §7. Accept: stock reaches the fake pusher with `0`, read-back and reconcile work, no warehouse control or reason; Ozon warehouse tests unchanged |
| AF6 — Parts per account | M · Sonnet 5.5 | S0 (M1) | `account/**`, `jobs/**` (gate), `diagnostics/**`, `admin/**`, `settings/**` | §6. Accept: each part off skips its jobs and shows its reason; an adapter without the interface unchanged |
| AF7 — Conditional fields | M · Sonnet 5.5 | M0a (M2) | `catalog/**`, `admin/pages/mappings/**` | §11. Accept: and/or rules in the form, validator and builder input |
| AF8 — Account defaults, per-product values | M · Sonnet 5.5 | AF7 | `settings/**`, `admin/product/**`, `flows/class-link-context-resolver.php`, `data/**` | §11. Accept: the four-level precedence; text and choice; variations follow the parent |
| AF9 — Take-over warning | S · Haiku 5.5 | AF2 | `flows/**`, `admin/product/**`, `admin/pages/products/**` | §10. Accept: sentence or refusal in tab, row and bulk; none without the interface |
| AF10 — Order additions | M · Sonnet 5.5 | WF6b (#2004) merged — shared orders-table files | `admin/order/**`, `admin/pages/orders/**` | §13.3. Accept: choices differ per order, no remote call on open; deadline column only for an adapter that reports it |
| AF11 — Card and warehouse wording | M · Sonnet 5.5 | AF3, AF5, AF10 | strings in `admin/**`, `settings/**`, `diagnostics/**`, `deletion/**` | §14. Accept: a fake with its own nouns shows no «карточка» or «склад»; defaults unchanged; both order columns distinct |

### Plugin (working repository `woocommerce-listings-seller`)

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| AP1 — Skeleton and connection | M · Sonnet 5.5 | S0, AF6 | §15, §6. Accept: wrong keys, each missing access in plain words; no token in a log or report |
| AP2 — Categories, mappings, validation | L · Sonnet 5.5 | AP1, AF7, AF8 | §11. Accept: checked categories end to end; no paid field offered |
| AP3 — Items, photos, document | L · Sonnet 5.5 | AP2, AF1 | §5, §11. Accept: golden documents; a live load of the test ads; conversion and stable URLs |
| AP4 — Profile, schedule, upload | M · Sonnet 5.5 | AP3, AF3 | §12. Accept: a foreign link and schedule survive every operation; confirmations |
| AP5 — Load report and states | M · Sonnet 5.5 | AP3, AF2, AF4 | §9. Accept: late, partial, replaced reports from fixtures; messages at the product |
| AP6 — Existing listings | M · Sonnet 5.5 | AP5, AF9 | §10. Accept: automatic and manual link on the rig; adoption of a hand-published test ad without a duplicate |
| AP7 — Stock and prices | M · Sonnet 5.5 | AP5, AF5 | §7–8. Accept: live write and read-back; wait before the number; formula per M10 |
| AP8 — Delivery orders | L · Sonnet 5.5 | AP6, AP7, AF10 | §13.1–13.3. Accept: both storages, every type, actions, deadlines; fixtures and, if permitted, one order |
| AP9 — Labels and marking | M · Sonnet 5.5 | AP8 | §13.4–13.5. Accept: pickup-point only; codes before confirm; withdrawal |
| AP10 — Name, identifiers, artwork | S · coordinator + Codex for images | support answer, owner | §2, T6–T7. Accept: every identifier and text renamed to the final ones before any release |
| AP11 — Acceptance and release materials | M · Haiku 5.5 | all | §19, §20.4; merchant docs for a WordPress novice; release package |

### Order and parallelism

| Wave | Cards | The merchant gets |
|---|---|---|
| 0 | M0a, M0b, M0c, S0 | — |
| 1 — publication | AF1, AF2, AF3, AF4, AF6, AF7, AF8, AF9 → AP1–AP6 | connection, categories, publication, load report, existing listings |
| 2 — stock and price | AF5 → AP7 | stock and prices by API |
| 3 — orders | AF10, AF11 → AP8, AP9 | orders, courier time, labels, marking |
| 4 | AP10, AP11 | the release |

```text
M0a ─► M0b ─► (M0c) ─► S0
S0 ─► AF1 ─► AF2 ─► AF3          AF2 ─► AF9
S0 ─► AF4, AF6                   M0a ─► AF7 ─► AF8
AF6 ─► AP1 ─┐
AF7, AF8 ───┴► AP2 ─(AF1)─► AP3 ─(AF3)─► AP4
                            AP3 ─(AF2, AF4)─► AP5 ─(AF9)─► AP6
                            AP5 ─(AF5)─► AP7
WF6b ─► AF10 ─(with AP6, AP7)─► AP8 ─► AP9
AF3, AF5, AF10 ─► AF11
support answer ─► AP10 ;  all ─► AP11
```

Wave 1 is not a release by itself: out-of-stock goods stay orderable without wave 2. Framework cards that share files
(AF5, AF6 and AF8 in `settings/**` and `admin/**`) are taken one after another.

| | L | M | S | Cards |
|---|---|---|---|---|
| Phase 0 | — | 1 | 3 | 4 |
| Framework (AF1–AF11) | 2 | 7 | 2 | 11 |
| Plugin (AP1–AP11) | 3 | 7 | 1 | 11 |
| **Total** | 5 | 15 | 6 | 26 |

## Revision log

| Date | Change |
|---|---|
| 28.11.2026 | First draft from the research and the owner's answers D1–D13; framework re-read at `cd2495ce` |

## Related

- [Owner decisions](../research/2026-11-26-avito/decisions.md) — D1–D13, final
- [Research index](../research/2026-11-26-avito/README.md) — API map, gap map, access list
- [Wildberries Seller 1.0 plugin design](2026-11-26-wildberries-seller-plugin-design.md) — the shape followed and the extensions reused
- [Marketplace module design](../wiki/marketplace-admin.md)
- [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md) — the marketplace layer in the framework
- [ADR-005](../adr/005-platform-v2-clean-break-policy.md) — installed-site data contracts
