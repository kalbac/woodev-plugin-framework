
# Gap map: Avito against `woodev/marketplace/`

Code read at `157c4bd9` (framework 2.1.20; `woodev/marketplace/` is unchanged since the `59a0ecf9` of the
[WB gap map](../2026-11-26-wb-seller/gap-map.md)). Paths are relative to `woodev/marketplace/`. The code was read or
searched through Serena and grep; nothing was executed. API facts come from [the API map](api-map.md).

**Verdict column:** `fits` — the module provides it, the plugin only implements contracts; `extend` — a framework
change is needed; `plugin` — Avito-only code; `blocker` — a v1 flow cannot work without the change; `shared` — the
[WB card plan](../2026-11-26-wb-seller/card-plan.md) already needs the same change.

## 1. Where Avito differs from Ozon and Wildberries

| | Ozon (built) | Wildberries (researched) | Avito |
|---|---|---|---|
| What is sold | Product card in a shared catalogue | Colour card with sizes | **A listing (ad)** with a city/address, one photo set, one price; each variation is its own ad (`MultiItem` only groups them) |
| How content is sent | API call, task number | API call, no task number | **A full file the marketplace downloads** on a schedule (≤ hourly by API trigger); no create/edit call at all |
| «Delete» | Archive call | Trash call | **Absence from the file** |
| Answer to an export | Poll the task | Read error lists | Read the **upload report**; per-ad detail only for the current / last successful upload |
| Stock | Per warehouse | Per seller warehouse | **Per listing, no warehouse** |
| Price | Batch | Batch, applied later | One call per listing, **and** the feed carries a price |
| Orders | Push + poll | Poll | Poll only |
| Hand-over | Ship + label | Supply + sticker | Confirm / courier slot / tracking / code, PDF label for pickup-point orders |
| Cancel | With reasons | Without reasons | Without reasons |
| Paid per action | No | No | **Yes** — publication and promotion spend the seller's money |

## 2. Capability map

| # | Capability | What the module provides (file:line) | Gap for Avito | Verdict |
|---|---|---|---|---|
| 1 | Menu, pages, settings, records engine, licence gate, diagnostics, bulk runs | `class-marketplace-plugin.php:198` (pages overridable), `jobs/class-license-gate.php` | Nothing found; marketplace id `avito` is within `data/class-schema.php:42` | fits |
| 2 | Accounts and secrets | `account/class-credential-fields.php:46` — typed identifier + secrets; `data/class-schema.php:234` (`external_id` unique), `:242` (`seller_id` bigint) | `client_id` + `client_secret` fit as they are; `user_id` from `/core/v1/accounts/self` fits `seller_id`. No expiry date to report | fits |
| 3 | Token, errors, rate limits | adapter's `error_classifier()`; `governor/class-bucket-spec.php:80` (limit per period) | 24 h token fetch and cache is client code; per-method buckets fit | plugin |
| 4 | Categories and characteristics | `catalog/interface-catalog-schema.php:25`, `:35`, `:46`; `catalog/class-field-definition.php:91` | 3086 leaf categories, values behind separate XML/JSON links. **A field has no conditional visibility or requiredness**; Avito fields depend on each other (`visible` / `required` / `hidden`). Without it the mapping screen shows fields that must stay empty and cannot say what is missing | extend (M) |
| 5 | Listing-level values | `Mapping`, `Profile`, per-link overrides (`admin/product/class-link-form-input.php:21`) | Address, contact, `AdType`, `Condition`, `Delivery`, dimensions, documents: account defaults + mapping + override. Whether account-wide defaults for adapter fields exist was not checked | fits (verify) / extend (S) |
| 6 | **Publication = a feed** | `flows/interface-export-gateway.php:39` (`build_item` per link), `:52` (`submit` a batch); `jobs/class-export-scheduler.php:26` (100 links a batch) | Avito takes no batches: it downloads **all** listings from one URL. Needed: a stored item per link, a snapshot builder (stream the file), a public tokenised URL on 80/443, rebuild on change, fetch log, and guards — never publish a partial or empty snapshot (that archives listings). `build_item()` can be reused to produce one ad; `submit()` has no meaning. No feed or XML code was found in the module; the public-route precedent is `intake/class-notifications-controller.php:118` | **blocker** (L) |
| 7 | Export result | `jobs/class-export-batch-runner.php:392` — an empty task id is a failure and a retry; `flows/interface-card-lifecycle.php:53` (`read_import( task id )`); `jobs/class-poll-task-runner.php:54-55` (card 3 h, task 24 h) | There is no task; upload ids appear in the report list after Avito fetches the file, on its schedule, up to 24 h late. Results are read by file `Id` from the last upload, and a later upload replaces them | **blocker** (shared with WB WF2, longer deadlines) |
| 8 | Link states and late ids | `flows/interface-card-lifecycle.php:30`, `:40`; `data/class-link-record.php:34-44` | `avito_id` is a late id learned from the report — the contract fits. Avito statuses (`blocked`, `rejected`, `old`) and report messages map onto the existing states and reason text | fits |
| 9 | Unit of linking | `data/class-schema.php:299` one link per product per account, `:300` unique article | Matches «one ad per product». The same product in several cities is not possible (Avito also forbids duplicate ads) | fits (owner question Q8) |
| 10 | Reading existing listings, linking | `flows/interface-link-resolver.php:24` (a card needs a unique `offer_id`), `flows/class-card-matcher.php:42` (match = normalised SKU equals article) | The listing list has no seller id; `Id` comes only from `autoload/v2/items/ad_ids`, and **hand-published listings have none**. Needed: link by Avito number (typed or picked), the number written to the feed as `AvitoId`, keep the `Id` of a previous generator, never take over a listing whose required fields the shop cannot fill | **blocker** for sellers with listings (M; shared part with WB WF3) |
| 11 | Import listing → product | `flows/interface-card-importer.php` | The API gives title, price, category and URL only — no description, photos or fields | not possible in v1 |
| 12 | Export / link gating | `class-flow-wiring.php:475`, `:529`, `:590`; `admin/pages/products/class-site-products-module.php:304`; `admin/product/class-embedded-screens.php:281` | Export and linking are gated on `IMPORT_PRODUCTS` (`capabilities/class-capability.php:47`) — an Avito adapter would have to claim an import it cannot do | extend (S, shared with WB) |
| 13 | **Stock send** | `stock/interface-stock-pusher.php:33`, `:45` (take a warehouse id); `sync/class-stock-warehouse.php:43`; `sync/class-stock-intents.php:212` (no warehouse → nothing is sent); `diagnostics/class-why-not-syncing.php:54` | Avito has no warehouses. As built, nothing is sent and every product says «не выбран склад». A one-fake-warehouse adapter workaround is conceivable but was not tried and leaves a meaningless setting on screen | **blocker** (S/M: a «no warehouses» capability) |
| 14 | Stock rules | `sync/class-sync-limits.php:51` (batch sizes), `sync/interface-stock-rules.php` (`archive()` may answer true) | 200 per write, 10 per read; stock only after `avito_id` is known (a not-yet-published refusal should map to the wait verdict). The formula (`wc_only` / `wc_plus_reserved`) must be measured, and the API returns no reserved figure — the second formula has no input | fits (measure) |
| 15 | Price send | `price/interface-price-pusher.php:32`; `sync/class-sync-limits.php:51` (price batch may be 1) | One call per ad, 150 / min. The feed must carry the same price, or the next load may write an old one (conflict C7) | fits; feed coupling in row 6 |
| 16 | Price read-back | adapter `price_reader()` (`capabilities/interface-marketplace-adapter.php:245`) | From the listing list | fits |
| 17 | Order ingest | `jobs/interface-posting-handler.php:32`, `:42`; `jobs/class-posting-jobs.php:45` (poll every 10 min) | Poll by status and date, 20 a page. One Avito order may hold several items — `items[].id` should equal the feed `Id` (to be measured); `items[].avitoId` is the fallback | fits |
| 18 | Order data | `orders/class-order-dto.php:121`; `orders/class-registry-order-writer.php:133` | Buyer data only for DBS/RDBS: an order of a partner delivery is created without a buyer. Totals include commission | fits |
| 19 | Cancel | `admin/order/interface-order-canceller.php:53`, `:76`; `admin/order/class-cancel-ajax.php:152` (empty reason list → 502) | Avito `reject` has no reasons | extend (M, shared with WB WF6) |
| 20 | Seller order actions | cancel and label only; `admin/shared/interface-posting-allowed-actions.php:44` gates them | Confirm (deadline — a miss cancels the order), tracking number, courier interval (a form with remote options), pickup preparation, code check, «Честный знак» before confirm, return post office. `availableActions` maps well onto a generic «allowed actions» list | extend (L, shared with WB WF6) |
| 21 | Labels | `flows/interface-label-provider.php:32`, `:44`, `:56`; `flows/class-label-answer.php:84`, `:98`; `flows/class-label-service.php:52` (15 min poll) | Task then PDF — the contract fits; the provider downloads with the token and answers «pending» until the file exists (behaviour before readiness UNVERIFIED). `max_keys()` 50; A6 or thermal is a setting | fits |
| 22 | Notifications | nullable decoder (`capabilities/interface-marketplace-adapter.php:135`) | Not used in v1 | fits |
| 23 | Messenger | none | New domain; needs the «Максимальная» subscription | plugin / out of v1 |
| 24 | Spending money | none | The feed can order paid placements and promotion (`ListingFee`, `AdStatus`, `Promo`), and the API load trigger ignores publication limits. Nothing in the module guards money | plugin (safety rule in the spec) |
| 25 | Autoload profile | none | Read-merge-write of `feeds_data` (up to three links): never replace a merchant's other feed or schedule | plugin |
| 26 | Merchant wording | `admin/pages/mappings/class-mapping-list-definition.php:64` says «категориям Ozon»; «отправление», «карточка», «склад» across `admin/**`; `settings/interface-settings-descriptor.php:30` supplies only the name | Avito words: «объявление», «заказ», «остаток» | extend (S, shared with WB WF8) |
| 27 | Dead contracts | `capabilities/interface-marketplace-adapter.php:108` (`order_source`), `:126` (`card_exporter`) | Same stubs as for WB (WB gap map §2) | extend (S, shared) |

## 3. Summary

**Reused as it is:** accounts, jobs and the governor, mappings and profiles in outline, link records with late ids,
price sending and reading, order intake and WooCommerce order writing, labels, diagnostics, deletion, licensing,
the React pages.

**Four things a v1 cannot do without a framework change:** publishing as a feed (row 6), reading results without a
task (row 7), sending stock without a warehouse (row 13), linking listings by their Avito number (row 10).

**Shared with Wildberries:** rows 7, 10, 12, 19, 20, 26, 27. Designing them once, for both, is the main saving if WB
goes first.

**Avito-only code (plugin):** client and token, category tree and field parsing, the ad XML serialiser and
validation rules, photo conversion (WebP → JPEG/PNG), autoload profile management, report reading, order mapping and
actions, money safety.

**Data contracts:** Ozon Seller 2.0.0 is released on the module's tables, so every schema change is additive with a
`Schema::REVISION` step and ships with the Ozon adapter update. A new Avito plugin has no installed data of ours;
the free third-party generator's ids are not our contract (not examined).

## Related

- [Research index](README.md)
- [API map](api-map.md)
- [Card plan](card-plan.md)
- [Marketplace module design](../../wiki/marketplace-admin.md)
