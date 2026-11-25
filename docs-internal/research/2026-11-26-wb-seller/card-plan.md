
# WB Seller: implementation card plan (estimate)

Filing briefs for the coordinator, **not filed cards**. Written against the recommendations of
[the owner questions](owner-questions.ru.md) (FBS only; link, import and create cards; supplies page; Chestny ZNAK
input). Different answers change the list. S/M/L are relative size. Model tiers follow the project rule (Haiku 5.5
for bounded work with a detailed brief, Sonnet 5.5 for routine work against a plan, Fable 5.2 for the hardest
correctness work, one at a time); every card gets a critic of another lineage. Delivery code is not touched.
Row numbers "G n" point into [the gap map](gap-map.md).

## Phase 0 — measure, then design

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| M0 — Sandbox measurement | M · Sonnet 5.5 | Q6 (token) | Scripted run with a Test token: create a colour × size card, error list, time to `chrtID`, barcode formats accepted and generated, media, price task and quarantine, stock set/read, does an order lower the stock, emulated FBS order → supply → stickers (formats) → deliver, **marking request/response plumbing only** (identifiers are optional in the sandbox: missing-code blocking and production validation stay unproven), 429 headers; read the release notes and the sandbox guide in a browser. Accept: a measurement file with every UNVERIFIED item of this research marked proven or still open; nothing about marking blocking marked proven from a sandbox run. |
| S0 — Design spec | M · Fable 5.2 | M0, owner answers | Unit of linking (G 7), article display, barcode storage and the rule for «this value is a GTIN» (G 8–9), group key, export lifecycle, price unit, supplies page, status composition. Accept: spec with a critic pass; card list re-estimated. |

## Framework (`woodev-plugin-framework`)

Ozon Seller is released on the module: each card keeps Ozon's behaviour, adds schema changes by revision, and names
the Ozon adapter change it forces.

| Id | Size · tier | Depends on | Files (under `woodev/marketplace/`) | Scope · acceptance |
|---|---|---|---|---|
| WF1 — Asynchronous price settlement | M · Sonnet 5.5 | S0 | `jobs/**` (price), `price/**`, `ledger/**` | G 18. Pending and duplicate receipts open a price task; a poll job calls `settle()`; deadline, quarantine as item error. Accept: fake adapter pending → accepted / refused / expired; Ozon immediate path unchanged. |
| WF2 — Export for grouped cards without a task number | L · Fable 5.2 | S0 | `jobs/class-export-*`, `jobs/class-poll-task-runner.php`, `flows/**` | G 10–12. Siblings stay in one batch; a "no task id" answer is accepted; `read_import()` gets the task's articles; per-adapter deadlines. Media is not here: the `card_created` hook exists (WP4). Accept: no double create on retry; Ozon tests green. |
| WF3 — Matching keys, barcodes and article presentation | M · Sonnet 5.5 | S0 | `flows/class-card-{matcher,linker,import}.php`, `flows/class-product-writer.php`, `flows/class-group-assembler.php`, `catalog/class-card-content.php`, `data/**` (barcode storage), `admin/product/**`, `admin/pages/products/**` | G 7–9. Match by the full barcode list (raw strings), article+size or SKU; several barcodes per link kept unchanged; the GTIN field written only for qualifying values; adapter says what the article column shows and what the import writes to SKU. Accept: `12345Ejf5` and a two-barcode size survive link and import byte for byte, GTIN left empty; two different codes never collapse into one. |
| WF4 — Card-level price unit | M · Sonnet 5.5 | WF1 | `price/**`, `sync/**`, `diagnostics/**` | G 19. Adapter names a price group; the sender receives the whole group; conflicting prices become a merchant reason. |
| WF5 — Derived identifier and bucket 4xx cost | S · Haiku 5.5 | — | `account/**`, `settings/class-accounts-sections.php`, `governor/**` | G 3, G 4. Identifier from the secret; a 4xx consumes `cost_4xx`. |
| WF6 — Order actions | L · Sonnet 5.5 | S0 | `admin/order/**`, `admin/pages/orders/**`, `admin/shared/**` | G 24, G 26. Cancel without reasons; adapter-declared row and bulk actions («Добавить в поставку»); units of a grouped order visible and cancellable one by one; a seam for per-unit input (marking codes). DBS actions later reuse it. |
| WF7 — Sticker documents | M · Sonnet 5.5 | WF6 | `flows/class-label-*`, `admin/order/class-label-button.php` | G 27. Several files per request, PNG/SVG/ZPL, printable sheet; right file names. |
| WF8 — Neutral wording and contract cleanup | S · Haiku 5.5 | WF6 (shared files) | strings in `admin/**`, `deletion/**`, `settings/**`, `capabilities/**` | A1, A2, A10, dead `order_source()` / `card_exporter()`. Descriptor supplies the nouns; no «Ozon» in a merchant string (a test pins it); auto-import offered only when card events exist. |

## Plugin (`woocommerce-wildberries-seller`, new repository — name to confirm)

| Id | Size · tier | Depends on | Scope · acceptance |
|---|---|---|---|
| WP1 — Skeleton and connection | M · Sonnet 5.5 | WF5 | Plugin on `Marketplace_Plugin`, adapter, API client per host, error classifier, bucket specs, token decoding (type, categories, expiry, read-only), settings, diagnostics, licence. Accept: Base token and missing categories explained in plain words. |
| WP2 — Catalogue, mappings, validation | M · Sonnet 5.5 | WP1 | Subjects, characteristics, directories, cache and refresh, rules (title, images, dimensions, required, counts, quota). |
| WP3 — Cabinet cards: list, link, import | L · Sonnet 5.5 | WP1, WF3 | `cards/list` cursor, sizes as units, matching, importer into variable products. Accept: colour × size card → one variable product; non-GTIN and multiple barcodes preserved (WF3). |
| WP4 — Create and update cards | L · Fable 5.2 | WP2, WP3, WF2 | Card builder, groups and sizes, barcodes, error list, read-merge-write update. **Media job** queued from `woodev_{plugin id}_card_created`: once per `nmID`, own retry, read-back of the card's photos, a sweep for cards the hook missed. Accept: sandbox create; photos confirmed by read-back, a failed or lost job retried; an update keeps cabinet-only fields. |
| WP5 — Stock and warehouses | M · Sonnet 5.5 | WP3 | `chrtID` pusher and reader, FBS warehouses, formula from M0. |
| WP6 — Prices | M · Sonnet 5.5 | WP3, WF1, WF4 | Price + discount, size prices where allowed, quarantine, read-back. |
| WP7 — FBS orders | L · Sonnet 5.5 | WP3, WF6 | Poll, statuses, grouping, accounting, cancel; HPOS and legacy storage. |
| WP8 — Supplies and stickers | L · Sonnet 5.5 | WP7, WF6, WF7 | «Поставки» page, shipping point and date, boxes, deliver, QR, sticker printing, re-shipment. |
| WP9 — Marking codes | M · Sonnet 5.5 | WP7, WP8 | Chestny ZNAK input, `requiredMeta` and `optionalMeta`, validation errors; other identifiers (customs declaration, UIN, …) stay required and are named as work still to do. Accept: documented refusals (`MetaValidationFail`, `CustomsDeclarationIsRequired`) exercised with fixtures; a sandbox delivery is not evidence; any production observation only with separately authorised access. |
| WP10 — Acceptance and release materials | M · Haiku 5.5 | all | Sandbox and rig acceptance, wiki, merchant docs, the release materials package. The acceptance report lists marking blocking as unproven unless a real cabinet showed it. |

## After 2.0 (not estimated in detail)

DBS actions and plugin flow (L + L) · return claims inbox (M) · DBW and in-store pickup (L) · FBW view from the
order feed and stock reports (M) · promo calendar, reviews, finance (not planned).

## Order and parallelism

```text
M0 → S0 → WF5 → WP1 → WP2
              WF3 → WP3 → WP5
              WF1 → WF4 → WP6
              WF2 ──────→ WP4
              WF6 → WF7 → WP7 → WP8 → WP9 → WP10
              WF8 (after WF6)
```

Framework releases at the seams: after WF5 (the plugin can boot), after WF1–WF4 (catalogue and sync), after
WF6–WF8 (orders). Total: 2 phase-0 cards, 8 framework cards (2 L, 4 M, 2 S), 10 plugin cards (4 L, 6 M). The
Ozon module round was 17 framework and 5 plugin cards; most of that foundation is reused here.

## Related

- [Research index](README.md)
- [Gap map](gap-map.md)
- [Marketplace module design](../../wiki/marketplace-admin.md)
