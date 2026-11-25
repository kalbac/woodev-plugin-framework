
# Gap map: Wildberries against `woodev/marketplace/`

Code read at `59a0ecf9` (framework 2.1.19, planned 2.1.20). Paths are relative to `woodev/marketplace/` unless
stated. READ means the code was read or grepped; nothing was executed. API facts come from
[the API map](api-map.md).

**Verdict column:** `fits` — the module provides it, the plugin only implements contracts; `extend` — a framework
change is needed; `plugin` — Wildberries-only domain code; `blocker` — a v1 flow cannot work without the change.

## 1. Capability map

| # | Capability | What the module provides (file:line) | Gap for Wildberries | Verdict |
|---|---|---|---|---|
| 1 | Menu, pages, settings placement, records engine | `class-marketplace-plugin.php:198` (`get_marketplace_pages()` overridable), `:321` (plugin views) | A «Поставки» page is added by the plugin | fits |
| 2 | Accounts, encrypted secrets, health, expiry | `account/class-credential-fields.php:22`; `account/class-health-result.php:126` (`expires_at()`); `account/class-key-expiry.php:20`; `class-marketplace-plugin.php:161` (write gate for a read-only key) | JWT gives expiry, categories and the read-only bit locally | fits |
| 3 | Account identifier | One **required, typed** identifier plus secrets: `account/class-credential-fields.php:46`, `settings/class-accounts-sections.php:508`, `:528`; `data/class-schema.php:234` (`external_id` unique), `:242` (`seller_id` bigint) | WB has only a token; the identity is the UUID `sid` inside it. The adapter cannot derive the identifier | extend (S) |
| 4 | Rate limits | `governor/class-bucket-spec.php:65` (`cost_4xx` declared), `governor/interface-request-governor.php:29` | Nothing reads `cost_4xx`: no caller outside the value class, no method to report a 4xx. WB charges 10 for it | extend (S) |
| 5 | Error handling | Adapter classifier (`capabilities/interface-marketplace-adapter.php:218`) | `X-Ratelimit-Retry`, 402/403/409 mapping | plugin |
| 6 | Catalogue schema and mappings | `catalog/interface-catalog-schema.php:25-46`; `catalog/class-field-definition.php:91`; adapter rule list `catalog/class-export-validator.php:30` | Subject list, characteristics, six directories are domain code. A field has no `maxCount` — enforce in an adapter rule. Title ≤ 60, images ≥ 700×900 are adapter rules; `Dimensions_Rule` is unit-neutral, cm/kg conversion is the card builder's. A profile already picks the exported barcode source (none, GTIN, SKU, custom field — `admin/pages/mappings/class-profile-choices.php:101`), one value per product | fits |
| 7 | Unit of linking | One link = one card = one unique article: `data/class-schema.php:300` (`UNIQUE (account_id, offer_id)`), `flows/interface-link-resolver.php:24` | WB's sellable unit is (`nmID`, `chrtID`); `vendorCode` names the colour card, shared by all its sizes. Workable with a **synthetic article per size** plus `remote_ids`; the UI would show it as «Артикул» | blocker (design + extend) |
| 8 | Matching shop products to cabinet cards | `flows/class-card-matcher.php:42`, `:76` — normalised WooCommerce SKU equals the article, exactly. One value per identifier name: `data/class-schema.php:325` (`PRIMARY KEY (link_id, id_name)`), `data/class-link-record.php:351` (scalars only) | A WB shop's variation SKU is rarely `vendorCode`+size. Needs match keys: the size's **whole list** of WB barcodes compared as raw strings (independent of the GTIN field), `vendorCode`+size, plain SKU; GTIN only for values that are GTINs. Several barcodes per link need a naming scheme or storage of their own | extend (M) |
| 9 | Import card → product | `flows/interface-card-importer.php:27`; `flows/class-group-assembler.php:64` (one card = one variation); `flows/class-product-writer.php:127` and `class-group-assembler.php:343` write the article as the shop SKU. The importer writes no barcode today (no barcode or GTIN reference under `flows/`) | Adapter can present each size as a "card" with group key = `imtID`/`nmID` and aspects colour + size (UNVERIFIED — not tried). The synthetic article must not become the SKU. **WB barcodes are not GTINs:** keep the full original list as marketplace data; write the WooCommerce GTIN only for a value that qualifies (exact rule for the design spec: digits only, a GTIN length, valid check digit), because the setter silently strips other characters (`12345Ejf5` → `123455`, [API map §4](api-map.md)) and refuses duplicates. WooCommerce 8.5 has no GTIN getter (`catalog/class-source-resolver.php:227`) | extend (M) |
| 10 | Export: batching | `jobs/class-export-scheduler.php:26` (100 link ids per batch); `flows/interface-export-gateway.php:39` builds one item **per link** | Sizes of one `nmID` must go in one request; a batch boundary may split them. `submit()` can regroup items, but not fetch the missing siblings | extend (M) |
| 11 | Export: result | `jobs/class-export-batch-runner.php:392` — an empty task id is a failure and is retried; `flows/interface-card-lifecycle.php:53` — `read_import()` receives only the task id | WB returns **no task id**; results are read by `vendorCode` from the card and error lists. Retrying an accepted create is the wrong outcome | blocker |
| 12 | Export: after creation | Poll phases read cards, seed stock and price, store the new ids and then fire **`woodev_{plugin id}_card_created`** `( $link_id, $account_id )` (`class-flow-wiring.php:122`, `:498`; `jobs/class-poll-task-runner.php:527-546`; pinned by `tests/unit/Marketplace/Jobs/PollTaskRunnerTest.php:63`). Deadlines `jobs/class-poll-task-runner.php:54-55` (card 3 h, task 24 h) | **Media needs no framework seam:** the plugin queues its own media job from the hook, once `nmID` is stored. The hook is not a durable runner — an exception is only logged (`:547-548`) and the card is already `created`, so it is not fired again for that run; a card that misses the 3 h deadline never fires it. It fires per link, i.e. per size, so the job is de-duplicated per `nmID` and needs its own retry, read-back and sweep. Separate gap: if the adapter waits for document checks (≤ 3 days) before calling a card created, both deadlines are too short | plugin (media) + extend (S, deadlines) |
| 13 | Export: updating a card | The builder sends "the card" | `cards/update` overwrites everything: read, merge, write; never drop cabinet edits, documents or sizes | plugin (high risk) |
| 14 | Export / link gating | `class-flow-wiring.php:475`, `:529`, `:590`; `admin/pages/products/class-site-products-module.php:304`; `admin/product/class-embedded-screens.php:281` | Export, linking, card list and catalogue refresh are all gated on `IMPORT_PRODUCTS`, documented as "Wildberries: decide later" (`capabilities/class-capability.php:47`). A WB adapter must declare it even without import; the account's import switch also follows it (`settings/class-shared-fields.php:69`) | extend (S, naming) |
| 15 | Stock push | `stock/interface-stock-pusher.php:33`, `:45` | `chrtID`, 1000 lines, amount only on read (`reserved` = 0). Formula (`wc_only` / `wc_plus_reserved`) must be measured | fits |
| 16 | Warehouses | One warehouse per link: `sync/class-stock-warehouse.php:62`; account default | Same limit as Ozon: one product feeds one seller warehouse. WB warehouses are bound to one scheme — the adapter lists only the supported ones. `OVERSIZE_WAREHOUSE` could carry `cargoType` | fits (scope question Q8) |
| 17 | Price calculation | `price/class-price-line.php:20` (price, old price, minimum per link) | Integer price + whole-percent discount; adapter conversion and rounding | plugin |
| 18 | Price send, asynchronous | `price/class-push-receipt.php:79`, `:92`; `price/interface-price-pusher.php:46` (`settle()`) | **Declared but not wired:** `jobs/class-price-flush-runner.php:295-297` logs a pending receipt and counts it as unanswered; `jobs/class-poll-task-runner.php:213` polls only import tasks; no caller of `settle()` exists. Every WB price send would be recorded as failed | blocker (M) |
| 19 | Price unit | Intents and lines are per link | One price per `nmID` unless `editableSizePrice`; sizes with different shop prices conflict, and siblings may be absent from a batch | extend (M) |
| 20 | Price read-back | `price_reader()` (`capabilities/interface-marketplace-adapter.php:245`) | Per `nmID`, mapped to sizes | fits |
| 21 | Order ingest, polling | `jobs/interface-posting-handler.php:32`, `:42`; poll every 10 min (`jobs/class-posting-jobs.php:26`) | New orders, statuses by ids; the first-hour buyer cancellation makes the interval matter | fits |
| 22 | Order grouping | `orders/class-order-dto.php:17`, `orders/class-order-group.php:29`, `orders/class-registry-order-writer.php:133` (`upsert_member`), `flows/class-wc-order-store.php:34` — designed for WB carts | `refresh()` gets only the group key; the adapter must find the member ids itself (UNVERIFIED path). Group key should be cart + warehouse | fits (verify) |
| 23 | Statuses | Adapter maps to `orders/class-status-map.php:22`; optional names and groups (`admin/shared/interface-posting-status-names.php`, `interface-posting-status-groups.php`) | Two axes must be composed into `status_raw` ≤ 32 characters (`data/class-schema.php:452`); `confirm/canceled_by_missed_call` is 31. Default status words are Ozon's (`admin/shared/class-posting-status-text.php:34`, `:69`) | fits |
| 24 | Cancel | `admin/order/interface-order-canceller.php:31`, `:53`, `:62`; `admin/order/class-cancel-ajax.php:151` refuses an empty reason list with 502 | WB has **no reasons**; one WooCommerce order holds several cancellable units | extend (M) |
| 25 | Seller-side status actions | Only cancel and label exist | DBS (confirm, deliver, receive with code, reject), pickup, DBW need actions; per-unit actions inside a group | extend (L), only if those schemes are in |
| 26 | Supplies (shipment) | `capabilities/class-capability.php:42` `CONFIRM_SHIPMENT` — declared, nothing consumes it | The whole FBS hand-over: supply, add orders, shipping point and date, boxes, deliver, QR. **Stickers exist only after an order is in a supply** — and a required customs declaration number must be entered before them | blocker for FBS (plugin page + generic order actions) |
| 27 | Labels | `flows/interface-label-provider.php:32-56`; one stored document per task; `flows/class-label-download.php:305` names anything but PDF/JSON `.bin` | Synchronous call fits (synthetic task id). One PNG/SVG/ZPL **per order**, ≤ 100: needs a printable sheet or multi-file packaging | extend (M) |
| 28 | Marking codes and other identifiers | none | `requiredMeta` — and for marked goods `optionalMeta` too — must be filled before a supply can be delivered: input on the order, validation errors. The sandbox does not enforce it, so the refusal path is tested with fixtures | plugin (+ order-screen seam) |
| 29 | Push intake | Nullable decoder and protocol; section hidden without the capability (`settings/class-notifications-section.php:139`; `deletion/class-deletion-module.php:133`) | Not used. New cabinet cards are not announced, so auto-import has no trigger | fits |
| 30 | Order screen, source filter, orders page, analytics | `admin/order/**`, `admin/pages/orders/**` | Works per WooCommerce order; label text and "posting" wording are Ozon's (see §2) | fits after §2 |
| 31 | FBW | none | Reports-based order and stock view | plugin (out of v1) |
| 32 | Returns, claims, finance, reviews, ads | no folders for them | New domain | plugin (out of v1) |
| 33 | Licence gate, diagnostics, deletion, bulk runs, tools | `jobs/class-license-gate.php`, `diagnostics/`, `deletion/` | none found | fits |

## 2. Ozon-specific assumptions baked into the module

| # | Assumption | Evidence | Effect on a WB plugin |
|---|---|---|---|
| A1 | A merchant sentence names Ozon | `admin/pages/mappings/class-mapping-list-definition.php:64` («…категориям Ozon…») | **Visible bug**: the WB «Сопоставление» page would say Ozon |
| A2 | An order is a «отправление» | 35 translatable strings in 10 files (orders page, analytics, cancel, details box `:161`, label and action gates, account deletion) | WB's words are «сборочное задание» / «заказ» and «поставка»; the descriptor offers only the marketplace name (`settings/interface-settings-descriptor.php:30`) |
| A3 | One sellable card per article | rows 7–10 above | Synthetic article or schema change |
| A4 | An import is a task with a number | row 11 | No-task-id mode |
| A5 | Prices are answered at once, per product | rows 18–19 | Async settlement, card-level price |
| A6 | Cancellation has reasons, with an «other» text | row 24 | Optional reasons |
| A7 | A label is one PDF made asynchronously | row 27; `admin/order/class-label-button.php:25` («downloads the PDF») | Formats and packaging |
| A8 | The account has a client id | row 3 | Derived identifier |
| A9 | The default status vocabulary is Ozon FBS | `admin/shared/class-posting-status-text.php:13-69` | Cosmetic: the adapter supplies names |
| A10 | "Has a catalogue" is spelled `IMPORT_PRODUCTS`; card events arrive by push | row 14, row 29 | Declare it; a dead auto-import option (UNVERIFIED in the UI) |
| A11 | Nothing ships the goods from the admin | row 26 | Supplies are new |

Contracts that every adapter must implement although the framework never calls them (no `->name(` caller in
`woodev/marketplace/`): `order_source()` (`capabilities/interface-marketplace-adapter.php:108`) and
`card_exporter()` (`:126`). A WB adapter would write dead stubs; removing them is allowed on the v2 line
([ADR-005](../../adr/005-platform-v2-clean-break-policy.md)) and needs the matching Ozon Seller change.

## 3. What already fits Wildberries by design

The domain layer was written with WB in mind: grouped orders (`upsert_member`), a pending / duplicate price receipt,
a 4xx cost in the bucket value, nullable task ids in the ledger, optional push, `remote_ids` bags (`nmID`, `imtID`,
`chrtID` are named in `links/class-product-link.php:21`). Three of these are values without a consumer — price
settlement, the 4xx cost, shipment confirmation. **A declared contract is not a working path**; each needs a card.

## 4. Data contracts

Ozon Seller 2.0.0 is released on these tables, so every schema change is additive with a `Schema::REVISION` step and
every contract change ships with the Ozon adapter update. Wildberries is a new product: no v1 plugin of ours exists
(UNVERIFIED against EDD), so its ids are free. A marketplace id of `wildberries` (11 characters) is within
`Schema::MAX_ID_LENGTH` 16 (`data/class-schema.php:42`).

## Related

- [Research index](README.md)
- [API map](api-map.md)
- [Card plan](card-plan.md)
- [Marketplace module design](../../wiki/marketplace-admin.md)
