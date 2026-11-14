# Marketplace card importer (marketplace card → WooCommerce product) — design

**Date:** 2026-11-14. **Status:** draft r2 — written for `kalbac/woocommerce-ozon-seller#75`; r2 folds in the eight findings of the
r1 critic (Claude Opus, PR #1763), see the [revision log](#revision-log). The three operator questions of §6 are still open.
**Target:** framework **2.1.6** (cards I1–I6 below); first consumer Ozon Seller 2.0.0 (card O6).

**Evidence labels:** **READ** (code or document read for this spec), **UNVERIFIED** (reason given; the named card proves it).
`fw:` = this repo at `f72878e3`; `ozon:` = `kalbac/woocommerce-ozon-seller` `main` at `99bee18`; `v1:` = the same repo at tag `v1.1.7`
(the importer file is unchanged since the initial commit `3e3fe52`).

## 0. The decision this implements

Operator, 14.11.2026 (ozon-seller#75, binding): the importer is built **before** Ozon Seller 2.0. A marketplace card becomes a
WooCommerce product

- (a) on the push notification `CREATE_OR_UPDATE_ITEM`, when the account's «Импортировать новые карточки автоматически»
  (`import_enabled`) is on, and
- (b) by the bulk action «Импортировать» on the marketplace cards tab (F12b).

Everything that is not specific to one marketplace — event handler, bulk action, field mapping through profiles, job and queue, link
creation, idempotency — lives in the framework marketplace module (`woodev/marketplace/**`). The plugin's adapter keeps only what is
Ozon's: which requests read a card and how their answer becomes the framework's card value.

Standing rules applied: PHP-driven React admin; KISS, no invented safeguards; technical knobs are constants, not settings; the licence
gate is the default for every remote action. No call to woodev.ru is added.

## 1. Scope

### 1.1 What v1 did (READ `v1:includes/class-product-importer.php`, `wc-ozon-functions.php:439`, `webhooks/class-webhook-item-changed.php`, `admin/settings/class-global-settings.php`, `list-tables/class-list-table-ozon-products.php`)

| Field | v1 behaviour |
|---|---|
| Type | `simple` (default) or `external` («Buy on OZON» link); **no variable products** — an Ozon group became N separate simple products |
| Name, SKU | card name; SKU = `offer_id`; slug = the Ozon product id |
| Status, visibility, stock status | from three settings, defaults `publish`, `visible`, `instock`; **stock quantity was never imported** |
| Price | `old_price` → regular and `price` → sale; with no old price, `price` → regular; no currency or markup handling |
| Description | separate request per card, on by default (`allow_insert_product_description`) |
| Weight, dimensions | from the attributes request, converted to the shop's units |
| Attributes | raw Ozon attributes kept in product meta (for re-export); WooCommerce attribute taxonomies only when `allow_insert_product_attributes` (default **off**) |
| Images | primary → featured, the rest → gallery, through `wc_rest_upload_image_from_url()`; reused by the `_wc_attachment_source` meta; one failed image aborted the whole card |
| Categories | reverse category mapping first; else created by name «category → type» when `create_new_category` (default on), behind `current_user_can( 'manage_product_terms' )` — false in a job with no user, so it worked only when the queue ran in an admin's request (code read; runtime UNVERIFIED) |
| Meta | per-store `product_id`, `sku`, `offer_id`, `status = imported`; category and type ids; `is_oversize` |

- **Existing SKU.** `wc_get_product_id_by_sku( offer_id )` found a product → the card was **refused** («already exists») unless
  `allow_replace_existing_product` (default off) was on, in which case the product was **overwritten** (name, prices, images).
- **Triggers.** Row action and bulk action «Import» on the Ozon products table (one Action Scheduler action per click), and the
  item webhook when `allow_create_new_product` (default off) was on.
- **Webhook on a card that is already on the site.** No content was re-imported. v1 only refreshed the Ozon SKU, renamed the article
  (`enable_sync_sku`) and, with `enable_sync_prices`, wrote the Ozon price back into the product.

### 1.2 What exists in v2 today (READ)

- `fw:` `Capability::IMPORT_PRODUCTS`; the account switch `import_enabled` (column, settings field, `Effective_Settings::import_enabled()`);
  the cards tab with «Обновить список», «Связать по артикулу», «Связать с…» and the near-match report (#1753, #1756); `Card_Linker`,
  `Link_Jobs`, `Cards_Snapshot_Store`. **No import action, job or handler.**
- `ozon:` subscribes to `TYPE_CREATE_OR_UPDATE_ITEM` when `import_enabled` or prices come from Ozon (`Ozon_Push_Types::for_features()`),
  but registers no handler for it (`Plugin_Obligations::register_event_handlers()`), so the event ends in `Noop_Event_Handler`.
- `fw:` `Price_Scheduler::queue_refresh()` (the `from_marketplace` price read) has **no caller**: nothing reacts to a changed card.

### 1.3 What 2.0 does

1. Imports a card as a **simple product**: name, SKU = article, description, price, weight and dimensions, images, category.
2. Never duplicates: a linked card is skipped; a card whose article is the SKU of an existing product is **linked** to it, unless
   that product is a variable parent (§2.6). An archived card is not imported.
3. Two triggers, one job: the item notification (auto-import on) and «Импортировать» on the cards tab (row and bulk).
4. Two merchant settings on «Общие» — both v1 settings, migrated by the Ozon plugin: «Сразу публиковать импортированные товары»
   (v1 `product_status`) and «Создавать категории для импортированных товаров» (v1 `create_new_category`).
5. A linked card's notification refreshes its price when the account reads prices from the marketplace (§4).

### 1.4 Not in this design

| Item | Why | Where it goes |
|---|---|---|
| Ozon groups (`model_id`) → one variable product | not v1 parity; the largest and riskiest part (in-place simple → variable conversion). The Ozon spec's D9 adopted it for 2.0 **provisionally** | card I7, pending open question 1 |
| WooCommerce attributes from card attributes | v1 default off; creates global taxonomies in the merchant's shop (Ozon spec risk 11) | later, by demand (open question 3) |
| Overwriting an existing product («replace existing») | v1 default off; destroys merchant edits | not planned (open question 3) |
| `external` product type | Ozon spec §5.6 imports simple products; the storefront «Купить на Ozon» button was removed (its R3) | not planned (open question 3) |
| Visibility and stock-status settings | WooCommerce's own defaults equal v1's defaults | dropped |
| Stock quantity | v1 never imported it; the link starts with both sync switches off (`Card_Linker`) | unchanged |
| Barcode; the card's attributes stored as link `attribute_overrides` | v1 did not import a barcode; overrides are an export concern of every linked card, not only imported ones | separate card if export needs it |
| Per-account import settings | v1's were global | later, by demand |
| Re-importing content when a linked card changes | v1 did not | not planned |

## 2. Framework / adapter split

### 2.1 The adapter contract

An **optional interface the adapter implements**, the pattern of `Order_Canceller` and `Deletion\Account_Data_Cleaner` (READ
`fw:deletion/class-deletion-module.php:131`). `Marketplace_Adapter` does not change, so a plugin bundling an older adapter keeps
booting next to framework 2.1.6. The importer is wired only when `$adapter instanceof Card_Importer` **and**
`supports( Capability::IMPORT_PRODUCTS )`.

```php
namespace Woodev\Framework\Marketplace\Flows;

interface Card_Importer {
    /** Event types that announce a created or changed card (the decoder's names). */
    public function import_event_types(): array;

    /** The article an event is about; '' when it names no card or reports a failed card. */
    public function imported_article( Inbound_Event $event ): string;

    /** Request path named in the error ring next to a failed import run. */
    public function import_path(): string;

    /**
     * Reads whole cards. `$renew` extends the account claim between requests or throws.
     *
     * @return array<string,Card_Content> By article; a card the marketplace did not answer for is absent.
     */
    public function read_cards( Account $account, string $context, array $offer_ids, callable $renew ): array;
}
```

`Catalog\Card_Content` (new, a pure value in the scanned domain layer) is what one card is, in neutral units:

| Member | Meaning |
|---|---|
| `card(): array` | the `Link_Resolver` card (`offer_id`, `remote_ids`, `state`, `is_created`, `archived`, `failed`, `status_text`, `oversize`) — what `Card_Linker::link()` takes. The linker itself ignores `archived` (`Link_Jobs::link_pair()` turns it into the state before the call), so `Card_Import` reads the flag and never hands an archived card to the linker (§2.6 row 2) |
| `name()`, `description()` | strings; the description is raw, the framework sanitises it |
| `prices(): ?Price_Pair` | price and old price as `Money` in the cabinet's currency; null when the card has none |
| `weight_g(): ?int`, `length_mm()`, `width_mm()`, `height_mm()` | null when unknown |
| `primary_image(): string`, `images(): string[]` | absolute URLs |
| `remote_category(): array` | the descriptor `Mapping::remote_category()` stores; `[]` when unknown |
| `category_path(): string[]` | category names from the root down, for creating shop categories; `[]` when unknown |
| `group(): array` | reserved for I7: `key` (string, '' = stands alone), `size` (int), `aspects` (name => value). I1–I6 ignore it |

Ozon (card O6): `Ozon_Adapter implements Card_Importer`, delegating to a new `Ozon_Card_Reader` that reads the card's summary,
attributes and description and builds `Card_Content`. `import_event_types()` = `Ozon_Push_Types::item_types()`;
`imported_article()` returns `offer_id` unless the body's `is_error` is true (v1's rule).

### 2.2 New and changed framework files

| File (`woodev/marketplace/…`) | Class | Role | Card |
|---|---|---|---|
| `catalog/class-card-content.php` | `Catalog\Card_Content` | the card value above | I1 |
| `flows/interface-card-importer.php` | `Flows\Card_Importer` | adapter contract | I1 |
| `jobs/class-import-scheduler.php` | `Jobs\Import_Scheduler` | Action Scheduler calls of the job; `pending_offer_ids()` | I1 |
| `data/class-link-report-store.php` (changed) | `Data\Link_Report_Store` | import notes per article (§2.9) | I1 |
| `jobs/class-account-job.php` | `Jobs\Account_Job` | the gate → claim → work → classify skeleton, extracted from `Link_Jobs` | I2 |
| `flows/class-product-writer.php` | `Flows\Product_Writer` | `Card_Content` → `WC_Product`, images included | I3 |
| `flows/class-import-category.php` | `Flows\Import_Category` | shop categories of a card | I3 |
| `sync/class-shop-price-writer.php` | `Sync\Shop_Price_Writer` | the inverse price rule and the guarded price write, extracted from `Price_Refresher` | I3 |
| `settings/class-effective-settings.php`, `class-defaults-section.php` (changed) | — | the two settings | I4 |
| `flows/class-card-import.php` | `Flows\Card_Import` | imports **one** card (§2.6) | I5 |
| `jobs/class-import-jobs.php` | `Jobs\Import_Jobs` | the `import_cards` job | I5 |
| `jobs/class-card-event-handler.php` | `Jobs\Card_Event_Handler` | the item notification | I5 |
| `class-flow-wiring.php` (changed) | `Flow_Wiring::import()` | builds and registers the above | I5 |
| `admin/pages/products/class-remote-cards-{definition,actions,rows,provider}.php`, `class-site-products-module.php` (changed) | — | «Импортировать», notes, activity; the module builds the three classes and hands them the scheduler, the notes store and `Logging` | I6 |

Client code: **none expected.** The cards tab is the generic records view (`src/marketplace-admin/views/records/`); a new action, a
locked row and a note in a `stack` cell are all PHP-declared. I6 names the one thing to check (§2.4).

### 2.3 Event path

`Flow_Wiring::intake()` registers `Card_Event_Handler` for every type of `import_event_types()` **before** it fires
`woodev_{plugin id}_event_handlers`, so a plugin can still replace it. The handler only queues work (a handler runs under the
event's claim and must stay short), and the drain is already licence-gated:

| The event's article | Account | Result (the text stored on the event row) |
|---|---|---|
| '' (no card, or a failed card) | — | `no card to act on` |
| linked (`Wpdb_Link_Store::find_by_offer_id()`) | prices `from_marketplace` | `Price_Scheduler::queue_refresh()` → `price refresh queued` |
| linked | any other price direction | `card is linked; nothing to do` |
| not linked | `Effective_Settings::import_enabled()` false | `card is not linked; auto-import is off` |
| not linked | auto-import on | `Import_Scheduler::import()` → `import queued` |

A scheduling failure throws, so the drain's retry ladder applies. The body is trusted for the article only; the job re-reads the card.

### 2.4 Bulk action on the cards tab

`Remote_Cards_Definition::IMPORT` (`import`), declared **both** as a row action and as a bulk action: `effect: mutate`,
`interaction: dialog`, `scope: selection`, capability `manage_woocommerce` (it starts a remote read and creates products, like
«Обновить список»). `pagination.max_selection` rises from 1 to 100 (one page).

- **Preview** (no remote call, no write): reads the cards from this account's snapshot, the links and
  `wc_get_product_id_by_sku()`, and groups the selection — «будет создано товаров: N», «уже на сайте: K», «товар с таким
  артикулом уже есть, свяжем: M», «товар с таким артикулом — вариативный, выберите вариант через „Связать с…“»,
  «карточка в архиве — не импортируем», «нет полных данных — обновите список» (`details_ok` false), «нет в загруженном
  списке». The plan uses the same decision as the job (§2.6), so the two cannot disagree.
- **Apply** replans, requires the licence (`License_Gate::refusal_for( 'импортировать карточки с маркетплейса' )`), queues the
  eligible articles in batches of `Import_Scheduler::BATCH` and answers 202 «Импорт поставлен в очередь…».
- **Activity.** A row whose article is in a pending or running `import_cards` action is `locked` with «Импортируется» and blocks
  `import` and `link_to`; the account line says «Импортируем карточки…». Both read Action Scheduler's own tables — local state.
- **After the job** the card moves to «Уже на сайте» by itself: that column is computed from the links.

Raising `max_selection` exposes the three one-row actions: `link_to`, `near_confirm` and `near_dismiss` all read `$ids[0]`
(READ `Remote_Cards_Actions::preview()` / `apply()`), so with several rows selected they would silently act on the first. I6 makes
each of them **refuse more than one id** on the server (preview and apply). I6 also checks that the records shell shows checkboxes
once `max_selection` is above 1 (UNVERIFIED — not run for this spec).

### 2.5 The job

`woodev_{plugin id}_import_cards( account_id, offer_ids[≤10], attempt )`, group = the plugin's.

- `Import_Scheduler::import( account_id, offer_ids )` chunks by `BATCH = 10` and skips a chunk that is already waiting with the same
  arguments (`as_has_scheduled_action`, as `Link_Scheduler::enqueue()` does). The queue is Action Scheduler; no list of our own.
- A notification queues one action per card — the cost model of posting events. A burst is serialised by the account claim.
- `Import_Jobs` runs through `Account_Job` (I2): `Job_Gate::check()` (account on, licence in force, pause) → `Job_Gate::claim()` →
  work → release; a classified error pauses, waits or retries on `Backoff` up to 3 attempts; `Claim_Lost` retries after
  `Job_Gate::BUSY_DELAY`. **No write gate**: nothing is written to the marketplace (as in `Link_Jobs`).
- Work: `read_cards()` once for the batch, then `Card_Import::import()` per card. A card's own failure (WooCommerce refused the
  save) is caught, noted (§2.9) and the batch goes on.
- **Claim.** The run extends the account claim (`Account_Job`'s `hold()`, i.e. `Job_Gate::still_holds()`) **before every card and
  before every image**, and throws `Claim_Lost` when another run took it. The claim lives 120 s (`Job_Gate::CLAIM_TTL`) and one
  image download is bounded at 30 s (§2.8), so the step between two renewals cannot outlive it.
- Budget `BUDGET_SECONDS = 20`, checked before every card and before every image. When it is spent, the run stops and requeues the
  cards it has not finished, the current one included, with the attempt unchanged.
- **A run always advances.** The budget check may stop a run only after that run has finished one card or downloaded one image. A
  slow `read_cards()` that eats the budget by itself therefore still ends with progress, and a requeue with the attempt unchanged
  cannot repeat forever.

### 2.6 One card — idempotency

`Card_Import::import( Account, Card_Content, Claim_Fence )` decides in this order:

| # | State found | Action | Outcome |
|---|---|---|---|
| 1 | the article has a link in this account | nothing | `already_linked` |
| 2 | the card is archived (`archived` true, or its state is `Link_Record::STATE_ARCHIVED`) | nothing: no product, no link | `skipped`, note «Карточка в архиве — не импортируем» |
| 3 | a product with SKU = article exists and carries **no** import marker | load it (`wc_get_product()`). A **variable parent** is refused here, before the linker. Anything else (a simple product, or a variation) → `Card_Linker::link( account, id, $product->get_parent_id(), card, fence )`; its content is not touched | `linked_existing`; for a variable parent the note «К вариативному товару нельзя привязать карточку. Выберите один из его вариантов через „Связать с…“»; a linker refusal (`PRODUCT_LINKED`, `CARD_LINKED`, `BUSY`) as a note with `Card_Linker::message()` |
| 4 | a product with SKU = article carries the import marker (an earlier run of ours was cut short) | continue 5 on that product | — |
| 5 | no product | create the product, then link | `created` |

Row 3 must refuse the parent itself: `Card_Linker::link()` answers `NOT_A_CARD` only when the product **already has a row of kind
parent**. A variable product nobody opened on the marketplace tab has no row, and the linker would insert an offer link onto it
(READ `Card_Linker::link()`: `find()` null → `insert()`). Every existing caller filters parents out first (`Shop_Sku_Source`,
`Remote_Cards_Products::pick()`), and so does the importer. `wc_get_product_id_by_sku()` can also return a variation, which is why
the parent id comes from the loaded product, not from a constant 0.

Row 5 in order:

1. **First save:** a `draft` product with the name, `set_sku( article )`, the marker meta `_woodev_{marketplace id}_importing` and
   the category (§3). The SKU and the marker go in together because rows 3 and 4 find a half-made product **by its SKU**; the
   category goes in so that it is stored before the price is worked out.
2. Fields (description, weight, dimensions), then the price — after step 1, because the price rule is resolved from the product's
   **stored** categories (§3).
3. Images, one at a time, each reused by its source URL (§2.8).
4. **Final save:** the final status, the marker removed.
5. `Card_Linker::link()` with the run's fence.

Why this order holds at every cut point (a PHP time limit during image downloads is the real case — Ozon spec risk 7):

- cut before the first save → nothing exists → the next run starts row 5 again;
- cut between the first and the final save → the SKU and the marker are both stored → the next run resumes on the same product
  (row 4); images already fetched are found by their source URL;
- cut between the final save and the link → the next run finds the SKU without a marker and links it (row 3);
- the link is refused as `CLAIM_LOST` → `Claim_Lost` → the job retries → row 3.

Two runs cannot create the same product at once. Every run holds the account claim and extends it before each card and each image
(§2.5). `set_sku()` throws when another product already has the SKU, so even a run that lost its claim unnoticed gets a refused
save (a note), not a second product. The unique `(account_id, offer_id)` index is the last guard for the link. Matching is **exact**
SKU only; look-alike articles stay the business of «Связать по артикулу» and its report.

### 2.7 The link record

Written by `Card_Linker::link()` only — the same row «Связать с…» writes: `kind = offer`, the article frozen, `remote_ids`,
`card_state` and `is_created` from the card, `is_oversize` when the card says so, **both sync switches off**. Importing a card
therefore never makes the shop push stock or price to it; the merchant switches synchronisation on as for any linked product.

### 2.8 Images

`wc_rest_upload_image_from_url()` + `wc_rest_set_uploaded_image_as_attachment( $upload, $product_id )` — WooCommerce's own
helpers (Context7 `/woocommerce/woocommerce`; the stubs in `vendor/php-stubs/woocommerce-stubs`), the pair v1 and WooCommerce's CSV
importer use. An image is looked up first by the `_wc_attachment_source` meta (WooCommerce's convention, so images v1 imported are
reused) and the meta is written after a download. All of a card's images are taken, as in v1. A failed image is **not** fatal: the
product is kept and the note says how many images could not be loaded.

The helpers work in a background job (READ WooCommerce `includes/wc-core-functions.php:35` requires `wc-rest-functions.php` on every
request; the upload helper includes `wp-admin/includes/file.php` itself and passes its own `mimes` to `wp_handle_sideload()`, so
there is no user or capability check). I3's real-WordPress test still proves it inside an Action Scheduler callback.

**Each download is bounded.** The helper calls `download_url( $image_url )` with WordPress's default timeout of **300 s** (READ
`wp-admin/includes/file.php`), longer than the account claim lives. `Product_Writer` therefore wraps that one call in an
`http_request_args` filter that sets `timeout` to `IMAGE_TIMEOUT = 30` — added right before the call, removed right after, a
constant and not a setting. WooCommerce's helper and its checks stay in use. Before each image the writer calls the run's `$renew`
(§2.5).

### 2.9 Failure reporting

| What failed | Where it is reported |
|---|---|
| the run (remote refusal, lost claim, unexpected error) | `Api_Error_Ring::record()` with `import_path()`, and `log_error` — as `Link_Jobs::report()`; when the run gives up, every card of the batch gets the note «Не удалось импортировать: {reason}» |
| one card (save refused, no answer for the article, linker refusal, a variable parent with that SKU, an archived card) | an **import note** for that article |
| a warning on a created product (images missing, price not set) | an import note; the product exists |
| an event that led to nothing | the event row's result text (diagnostics page) — no merchant note |

Import notes: `Link_Report_Store::set_import_note()`, `import_notes()`, `clear_import_note()` — one option per account
(`import_notes_{account id}`, at most `MAX_ENTRIES`, oldest dropped), cleared by `forget_account()`. `Remote_Cards_Rows` shows a
card's note in its «Статус карточки» cell; a later successful import clears it.

A note entry has **two fields**: `text`, the plain sentence the merchant reads, and `detail`, the technical tail (exception class,
HTTP status), stored separately and possibly empty. The job never calls `Logging::hint()`: that method answers by the switch **at the
moment it is called**, so a tail glued on at write time would be missing after the merchant turns «Логирование» on to
investigate, and would stay visible after it is turned off. `Remote_Cards_Rows` calls `hint( $detail )` **when it renders the row**,
so the tail follows the switch as it is now.

### 2.10 Gates

- **Licence.** The bulk action refuses at request time; the job reschedules itself through `Job_Gate::check()`; the drain does not run
  handlers without a licence.
- **Account.** A switched-off or deleted account ends the job silently (notes say «Аккаунт маркетплейса отключён или удалён»).
  Account deletion already removes pending actions by their first argument (`Account_Queue_Cleaner`) and the report options.
- **Capability.** No `Card_Importer` on the adapter → no action, no handler, no settings.
- **Auto-import** stays off by default (`builtin_defaults()['import_enabled'] = false`).

## 3. Field mapping

The **profile** of a card is found backwards through the mappings: the card's `remote_category()` → the first term-bound mapping
(lowest id, v1's order) with that category → its `profile_id`. New read: `Wpdb_Mapping_Store::for_category( array $descriptor )`
returning the mapping and its term ids. Import then writes each field **where that profile reads it on export**, so an imported
product round-trips; with no mapping the built-in profile defaults apply (`Profile_Choices` defaults), which is v1's behaviour.

| Card | Target | Through |
|---|---|---|
| name | `set_name()` | — |
| article | `set_sku()` always (the idempotency key; the link freezes the article, so the profile's `offer_id_source` no longer matters) | — |
| description | profile `description_source`: `description` (default), `short_description` or a meta key; `wp_kses_post()` | profile |
| primary image | profile `primary_image_source`: featured image (default, downloaded — §2.8), or a meta key, which on export holds a link to the photo, so the card's URL is written there and nothing is downloaded | profile |
| other images | profile `images_source`: gallery (default, downloaded), a meta key (the URLs are written, nothing is downloaded), or `none` → not imported | profile |
| prices | regular and sale price by the **inverse of the price rule in force** (`Shop_Price_Writer`, the code `Price_Refresher` uses today). No markup → old price → regular, price → sale: v1's result. A currency the rule cannot resolve → no price, a note. **Worked out only after the category is stored** (§2.6, first save): the rule comes from `Price_Context::price_terms()`, which resolves the mapping through `Category_Ancestry::for_product()` → `wc_get_product_term_ids()`, i.e. the categories in the database, not those set on an unsaved object. Before the save, a profile-level markup would be missed and the first export would send the marketplace price times that markup | account / profile price rule |
| weight, dimensions | `wc_get_weight( g )`, `wc_get_dimension( mm )` into the shop's units | — |
| category | the mapping's terms; else, when «Создавать категории…» is on, `category_path()` created by name (an existing term with the same name and parent is reused); else none | mapping |
| status | `publish` when «Сразу публиковать…» is on, else `draft` | setting |
| type, visibility, stock | `simple`; WooCommerce defaults (visible, in stock, stock not managed) | — |

Settings (card I4), both on «Общие», group «Заказы и карточки», stored as keys of the one defaults option
`woodev_{adapter id}_account_defaults`, shown only when the adapter is a `Card_Importer`:

| Key | Label | Default | v1 source (O6 migration) |
|---|---|---|---|
| `import_publish` | «Сразу публиковать импортированные товары» | open question 2 | `product_status = publish` → on, anything else → off |
| `import_create_categories` | «Создавать категории для импортированных товаров» | on (v1) | `create_new_category` |

Readers: `Effective_Settings::import_publish()` and `::import_create_categories()`. A draft is the safe meaning of «off»: the card's
price is the marketplace's, and auto-import can fire with nobody watching.

## 4. Updates versus creates

| `CREATE_OR_UPDATE_ITEM` for… | v1 | v2 |
|---|---|---|
| a card not on the site, auto-import on | imported | imported (§2.3 → §2.6) |
| a card not on the site, auto-import off | ignored | ignored; the event row says why |
| a linked card, prices read from the marketplace | price written back (`enable_sync_prices`) | `refresh_prices` queued — the existing job, with its own checks (price sync on for the link, card created) |
| a linked card, otherwise | Ozon SKU refreshed, article renamed (`enable_sync_sku`) | nothing: the article is frozen in v2 (Ozon spec §12, migration row 2), identifiers come from the linking and export flows |
| a card reported as failed (`is_error`) | ignored | ignored |

Content of a linked product is **never** rewritten by a notification, in v1 or in v2.

## 5. Implementation cards

Every card: its own unit tests under `tests/unit/Marketplace/<Folder>/`, `woodev/class-map.php` regenerated, strings in the
framework text domain with the Russian catalogue, PHP 7.4, a critic of another lineage, and the delivery-untouched invariant of the
[marketplace wiki](../wiki/marketplace-admin.md). Tiers follow the project rule.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| I1 — Contract, card value, scheduler, notes | S · Haiku 5.5 | — | `catalog/class-card-content.php`, `flows/interface-card-importer.php`, `jobs/class-import-scheduler.php`, `data/class-link-report-store.php` | §2.1, §2.5 scheduling, §2.9 notes. Accept: the layer scanner passes with `Card_Content`; the fake adapter of the contract tests implements `Card_Importer`; a duplicate chunk is not queued twice; a note keeps `text` and `detail` as two fields and the store never calls `Logging`; notes are bounded and forgotten with the account |
| I2 — `Account_Job` skeleton | S · Sonnet 5.5 | — | `jobs/class-account-job.php`, `jobs/class-link-jobs.php` | Move `guarded()`, `after_failure()`, `hold()`, `report()` out of `Link_Jobs`; `Link_Jobs` uses it. Two things of `Link_Jobs` become parameters: the extra exception it gives up on (`Link_Resolve_Exception`) and the request path `report()` names (`list_path()` there, `import_path()` for the importer). Accept: the existing `Link_Jobs` tests pass untouched |
| I3 — Product writer | M · Sonnet 5.5 | I1 | `flows/class-product-writer.php`, `flows/class-import-category.php`, `sync/class-shop-price-writer.php`, `sync/class-price-refresher.php`, `data/class-wpdb-mapping-store.php`, `tests/integration/Marketplace/` | §2.6 row 5 steps 1–4, §2.8, §3. Accept: **the first save stores the SKU, the marker and the category together** (a test cuts the run right after it and finds the product by SKU with the marker); the price is computed after that save and a profile-level markup is applied (test: mapping profile +10 %, account rule none → regular = card price ÷ 1.1); resumes on a marked product without a second download; a failed image keeps the product; the writer calls `$renew` before each image and stops when it throws; a download is cut at `IMAGE_TIMEOUT` and the filter is gone afterwards; `for_category()` matches a stored mapping whatever the key order of the adapter's descriptor; `Price_Refresher` tests pass untouched; a real-WordPress test creates a product with an image inside an Action Scheduler callback |
| I4 — Import settings | S · Haiku 5.5 | — | `settings/class-effective-settings.php`, `settings/class-defaults-section.php` | §3 settings. Accept: hidden for an adapter that is not a `Card_Importer`; saved values survive a reload; defaults as decided |
| I5 — One-card import, job, event handler, wiring | M · Sonnet 5.5 | I1–I4 | `flows/class-card-import.php`, `jobs/class-import-jobs.php`, `jobs/class-card-event-handler.php`, `class-flow-wiring.php`, the wiki page | §2.3, §2.5, §2.6, §2.9, §2.10, §4. Accept: the five rows of §2.6 and its four cut points; **a variable parent with the card's SKU and no link row gets no link** and the note of row 3; a variation with that SKU is linked with its real parent id; an archived card creates no product and no link; the five rows of §2.3; the claim is renewed before every card and a lost claim stops the run; budget requeue keeps the attempt, and a run whose `read_cards()` used the whole budget still finishes one card or one image; no handler and no hook without `Card_Importer`; no remote call at request time |
| I6 — «Импортировать» on the cards tab | M · Sonnet 5.5 | I1 (parallel with I5) | `admin/pages/products/class-remote-cards-{definition,actions,rows,provider}.php`, `admin/pages/products/class-site-products-module.php` (construction of the three classes only) | §2.4, the note cell. Accept: preview writes and calls nothing and has the variable-parent and archived groups; apply replans; licence refusal; locked rows; **`link_to`, `near_confirm` and `near_dismiss` each refuse more than one id** in preview and apply; the note's `detail` is shown only while «Логирование» is on, switching it changes the same stored note's rendering both ways; a table GET never calls the marketplace |
| I7 — Groups → variable products | L · Fable 5 | I5; open question 1 | `flows/class-group-assembler.php`, `flows/class-card-import.php` | Ozon spec §5.6 group assembly on `Card_Content::group()`, its five-step in-place conversion and its acceptance tests (red-then-black equals red+black). Not part of 2.1.6 unless question 1 says so |
| Release 2.1.6 | coordinator | I1–I6 | — | — |
| O6 — Ozon adapter (repo `woocommerce-ozon-seller`) | M · Sonnet 5.5 | released 2.1.6 | `includes/ozon/catalog/class-ozon-card-reader.php`, `includes/ozon/adapter/class-ozon-adapter.php`, `includes/migration/**` (the two settings, warnings for the dropped v1 options), fixtures, `docs/spec` §5.6 and `docs/wiki` | `Card_Importer` on the adapter; `Card_Content` from recorded fixtures of the three requests; migration carries `product_status` and `create_new_category`. Accept on the rig: bulk import of several cards, auto-import by a real notification, a re-click duplicates nothing, a card with an existing SKU is linked, a card whose SKU is a variable parent's is refused with the note, an archived card is skipped, an image failure leaves a product |

Waves (at most three workers, disjoint files): **I1 ∥ I2 ∥ I4** → **I3** → **I5 ∥ I6** → release 2.1.6 → **O6**. I3 may start as
soon as I1 is merged, beside I2 and I4.

## 6. Open questions for the operator

1. **Ozon groups → variable products: in 2.0 or right after?** The Ozon spec adopted «in 2.0» provisionally (its D9, agenda item 10,
   seven tickets). v1 imported every card as a separate simple product. **Recommendation: right after 2.0** (card I7, framework
   2.1.7). 2.0 then ships v1 parity, and nothing is redone: `Card_Content::group()` is reserved now, and the Ozon spec's in-place
   conversion was designed for a sibling card that arrives later. Keeping it in 2.0 adds one L card on the hardest code of the importer
   before the release.
2. **Default of «Сразу публиковать импортированные товары» for a new shop.** v1's default was «publish». **Recommendation: off
   (draft).** An imported product carries the marketplace's price and untested content, and auto-import runs unattended. Upgraded
   shops keep whatever v1 had, either way.
3. **Three non-default v1 options are not carried into 2.0**: the `external` product type, WooCommerce attributes created from card
   attributes, and «replace existing product». **Recommendation: drop them, with a line in the migration report for a shop that had
   one switched on**, and bring one back only on a ticket. How many shops use them is not known.

## 7. What this spec did not verify

- The Ozon answers behind `Card_Content` (image, price, weight and dimension field names and units) were read from v1 code only,
  not from the v2 client, fixtures or the live API — O6 pins them with fixtures.
- `wc_rest_upload_image_from_url()` inside an Action Scheduler request was read in WooCommerce's source, not run (I3 runs it). The
  records shell with a selectable cards table was not run (I6).
- How a mapping's stored `category` JSON compares with the adapter's `remote_category()` descriptor (key order, extra keys) was
  not measured — I3's acceptance covers `for_category()`.
- Whether Ozon sends `CREATE_OR_UPDATE_ITEM` when only a price changes is not known; the price row of §4 depends on it.
- v1's category creation in a background job (the capability check) was read, not run.
- Nothing was run on the rig. r2 itself has not been re-read by a critic.

## Revision log

| Rev | Date | Change |
|---|---|---|
| r1 | 14.11.2026 | First draft (PR #1763). |
| r2 | 14.11.2026 | The r1 critic's eight findings (Claude Opus). (1) §2.6: the first save carries the SKU, the marker and the category — r1 let the SKU arrive with the final save, so a run cut during images left a draft the next run could not find. (2) §2.6 row 3: a variable parent is refused before `Card_Linker` (the linker refuses a parent only when it already has a parent row), and a variation passes its real parent id. (3) §2.6, §3: the price is worked out after the category is stored. (4) §2.1, §2.4, §2.6 row 2: an archived card is skipped with a note, never linked. (5) §2.5, §2.8: the claim is extended before every card and every image; one download is bounded at 30 s. (6) §2.9: a note stores its technical tail separately and `Logging::hint()` is called at render. (7) §2.2, §2.4, I6: `class-site-products-module.php` joins the file set; `link_to`, `near_confirm`, `near_dismiss` refuse more than one id. (8) §2.5: a run always finishes one card or one image before the budget may stop it. Card acceptances I1, I2, I3, I5, I6 and O6 updated; §2.8 records that the image helpers load in a background job (read in WooCommerce's source); §7 gains the critic's unchecked items. §6 is unchanged. |

## Related

- [../wiki/marketplace-admin.md](../wiki/marketplace-admin.md) — the marketplace module, the records engine and the cards tab this extends.
- [../adr/013-marketplace-layer-and-react-admin-in-the-framework.md](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md) — why non-domain marketplace logic lives in the framework.
- [../AGENT-RULES.md](../AGENT-RULES.md) — Rule 9 (PHP-driven React) and the preservation rules.
- [../DOCS-SCHEMA.md](../DOCS-SCHEMA.md) — the conventions this file follows.
