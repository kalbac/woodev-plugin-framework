# Marketplace bulk operations for large catalogues (#1786)

**Status:** draft — items A, B and C are operator decisions of 16.11.2026 (#1786, s231); item D is a proposal for the operator,
and every choice in this spec that is not in the card is marked **(proposal)**.
**Date:** 2026-11-16. **Target:** framework 2.1.x, consumed by Ozon Seller 2.0.0 before its release.

**Evidence labels:** **READ** — code read for this spec; **UNVERIFIED** — not read or not run, the named card proves it.
`fw:` = this repo at `46ca13f7` (origin/main); `ozon:` = `kalbac/woocommerce-ozon-seller` `main` at `3d68c88`;
`v1:` = the same repo at tag `v1.1.7`, read with `git show v1.1.7:<path>`.

## 0. The merchant and the decisions

The merchant is a WooCommerce shop with 1 000–50 000 products that already sells on Ozon. They want the two catalogues joined and
synchronised with as few clicks as possible. v1 lost at least two customers because mass operations were missing or clumsy.

| # | Operator decision (#1786) | Section |
|---|---|---|
| A | Every bulk action of «Товары сайта» applies to the ticked rows OR to every product under the current filter (category, state, the whole catalogue); it runs in the background in parts; the preview says «будет затронуто N товаров». | §2 |
| B | An account setting «Включать синхронизацию остатков и цен сразу после связывания», on by default. | §3 |
| C | «Связать по артикулу»: the whole shop or one category. | §4 |
| D | Work out which further bulk operations are needed and propose a list. | §5 |

## 1. What exists today (READ)

| Fact | Where |
|---|---|
| Twelve bulk actions, all `scope: selection`; `max_selection` 200 rows | `fw: woodev/marketplace/admin/pages/products/class-site-products-definition.php:212-250` |
| A selection expands to offers (simple products and variations); more than 500 offers is refused whole | `class-site-products-selection.php:24` (`MAX_OFFERS`), refusal in `class-site-products-provider.php` `prepare()` |
| Background actions: export, link by article, send stock, send price, archive; the rest (sync flags, warehouse, detach, reset) run inside the request | `class-site-products-bulk.php:66` (`BACKGROUND`) |
| «Связать по артикулу» is `scope: account` and always queues the whole shop | `class-site-products-definition.php` `link_by_article()`, `class-site-products-bulk.php` `run_linking()` → `Link_Scheduler::link_by_article( $account_id )` |
| The link job already accepts a category and reads its subtree; a category run keeps the near-match entries of other categories | `jobs/class-link-scheduler.php:80`, `flows/class-shop-sku-source.php` `products()` (`get_term_children`), `jobs/class-link-jobs.php` `near_entries_outside()` |
| The link row's `sync_stock` / `sync_price` default to 0 | `data/class-schema.php:221-222` |
| `Card_Linker` is the one writer of a link from a card: the link job, «Связать с…», the near-match confirmation and the import all call it, and it leaves both switches off | `flows/class-card-linker.php` (class doc), callers in `class-link-jobs.php`, `class-remote-cards-actions.php` (two calls), `class-card-import.php` |
| Export creates its row with both switches on; the product tab creates rows with both on | `flows/class-export-service.php:281-285`, `admin/product/class-link-settings-writer.php:291-295` |
| Switching sync on in the product tab records a first send (stock and price intent); the bulk «Включить синхронизацию …» only flips the column and records nothing | `class-link-settings-writer.php` `save_offer_extras()`; `class-site-products-bulk.php` `run_flags()` → `Wpdb_Link_Store::set_sync_flag()` |
| Remote cards: import takes at most 100 selected cards; the queue is Action Scheduler chunks of 10 articles | `class-remote-cards-definition.php:43` (`MAX_IMPORT`), `jobs/class-import-scheduler.php:28` (`BATCH`) |
| Remote cards read from one transient snapshot of the whole cabinet, TTL 600 s | `data/class-cards-snapshot-store.php` |
| The products filter: account, state group, category (with descendants), «только с ошибками», search; the reader builds the WHERE clause in SQL over `wp_posts` | `class-site-products-filter.php`, `class-site-products-reader.php` `where()`, `category_clause()` |
| The records engine sends `ids` with every action; `scope: account` must send none | `fw: woodev/admin/records/class-action-request.php` `targets()`, `src/admin-records/actions/api.ts` |
| The shell's selection is an id list capped at `max_selection` and dropped on every list read | `src/admin-records/selection.ts` |
| Account-level knobs with «Общие» defaults: groups `prices` and `exchange`, read through `Effective_Settings` | `settings/class-effective-settings.php` |

v1 (READ): «Инструменты» had four tools, each for all products or one category: link / unlink by SKU
(`includes/class-tools-controller.php` `sync_products`, a background job with an «in progress» option as the lock), enable stock
sync, disable stock sync, and «Изменить цены» (a fixed or percent price value per product, optionally sent at once). The
«Товары» list table offered export, update SKU, send stock, **load stock from Ozon**, send price, **load price from Ozon**,
unlink (`includes/list-tables/class-list-table-products.php` `get_bulk_actions()`); the Ozon list offered import and link by SKU;
the variation panel repeated the per-product ones.

## 2. A — «apply to all matching the filter»

### 2.1 UX: the Gmail pattern

The shell keeps today's selection and adds one mode on top, the pattern of Gmail's «Выбрать все цепочки»:

```text
 [x] (header checkbox ticked: every row of the page is selected)
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ Выбраны все 20 товаров на этой странице.  Выбрать все 12 345 товаров по фильтру │
 └──────────────────────────────────────────────────────────────────────────────┘
            ↓ click
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ Выбраны все 12 345 товаров по фильтру «Категория: Обувь · Не связан».  Снять выбор │
 └──────────────────────────────────────────────────────────────────────────────┘
```

- The bar shows only when the header checkbox selected the whole page AND the table total is larger than the page. The number is
  the table's own `total`, so it matches the count above the table.
- In the «все по фильтру» mode every visible row shows ticked; unticking any row, changing a filter, the search, the page or the
  account drops back to no selection (the list read already resets the selection, `selection.ts`).
- The bulk select lists only the actions that support the mode (§2.4); the others disappear from the list while the mode is on.
- The bar names the active filters in words, so the merchant sees what «все» means before choosing an action. «Все» with no
  filter reads «Выбраны все 12 345 товаров магазина».
- The `max_selection` cap and the 500-offer refusal stay for the ticked mode; the matching mode has no count cap.

### 2.2 The engine contract (generic records, not marketplace)

The records engine gains a second target kind. Nothing marketplace-specific goes into `woodev/admin/records/**` or
`src/admin-records/**`.

| Part | Change |
|---|---|
| Action descriptor | `all_matching: bool` (default false) on a `scope: selection` action. `Definition_Parser` accepts it; an `account` action may not set it. |
| Request body | Preview and apply accept `matching: { filters, search }` instead of `ids`. Exactly one of the two: `ids` non-empty or `matching` present → 400 otherwise. `matching` is validated by the same `Query_Validator` rules as the list read (declared filters, their option values, search length); page, per page and sort are not part of it. |
| Provider | A new optional interface `Applies_To_Matching` (like `Provides_Field_Options`): `preview_matching( context, action, query, payload )` and `apply_matching( context, action, query, payload )`. A `matching` request to a provider without it, or for an action without `all_matching`, → 400. Authorisation of a `matching` call is the action's capability only; there are no ids to authorise. |
| Apply answer | Same shape as today; `queued: true` and a `run` block `{ id, total }` (§2.3). |
| Account activity | `Activity_Normalizer::account()` gains an optional `stop: { action, label }`. The shell renders it as a secondary button on the account line, calling that account action with an empty body. |
| Shell | `selection.ts` gets the mode (`matching: boolean`, the frozen query of the click); `table-model.tsx` renders the bar; `bulk-toolbar.tsx` filters actions and sends `matching`; `actions/api.ts` sends either body; new message keys (`select_all_matching`, `selected_all_matching`, `selected_page`, `clear_selection`) with Russian defaults in `Standard_Messages`. |

### 2.3 The server: a bulk run (marketplace)

A matching apply never processes products inside the request. It stores a **run** and queues its first tick.

**The run** — one non-autoloaded option per account, `woodev_{plugin id}_bulk_run_{account id}` **(proposal: an option, not a new
table — one row per account, bounded size)**:

| Field | Meaning |
|---|---|
| `id` | random token; the tick checks it, so a run started after a cancelled one never meets the old tick |
| `action`, `payload` | the action id and its validated fields (warehouse) |
| `filter` | the `Site_Products_Filter` values (state, category, only errors, search) — **not** a list of ids |
| `user_id` | who started it |
| `cursor` | the last product id processed (`p.ID`, ascending) |
| `total` | the count at apply time, for the progress line only |
| `processed`, `accepted`, `skipped`, `failed` | running counts in offers |
| `groups` | reasons with counts and at most five examples each (`Site_Products_Bulk::EXAMPLES`), merged chunk by chunk |
| `state` | `running` → `done` / `cancelled` / `stopped` (licence, pause, lost user right) |
| `started_at`, `heartbeat_at`, `finished_at` | times |

**The tick** — Action Scheduler action `woodev_{plugin id}_bulk_run( account_id, run_id )`:

1. Load the run; stop if the id differs or `state` is not `running`; stop with `cancelled` if the cancel flag is set.
2. Gates: account enabled and of this adapter; for an action that leads to a remote write (export, send stock, send price,
   archive) `License_Gate::refusal_for()` and the account pause — a refusal ends the run `stopped` with that sentence. The remote
   writes themselves still pass `Job_Gate` in their own runners; this check only stops queueing new work.
3. `wp_set_current_user( user_id )`; if that user no longer has the action's capability → `stopped`
   («у пользователя, запустившего действие, больше нет прав»). Per-product `edit_product` checks then work as in the request.
4. **Re-evaluate the filter now**: `Site_Products_Reader::ids_after( $filter, $cursor, $limit )` — the same `where()` as the list,
   plus `p.ID > cursor ORDER BY p.ID ASC LIMIT n`. Sort is ignored: the id order is what makes the cursor stable.
5. Expand to offers with `Site_Products_Selection` rules, stopping the chunk before `MAX_OFFERS` (500) offers or the per-action
   size below, and plan + run the chunk with the same `Site_Products_Bulk` code the selection mode uses (it already replans from
   the stored links and never trusts a preview).
6. Merge counts and groups, advance `cursor`, write `heartbeat_at`, `wp_cache_flush_runtime()` so the WooCommerce object cache does
   not grow across chunks, and repeat until the 20-second budget (`Import_Jobs::BUDGET_SECONDS`) is spent; then queue the next tick
   with no delay. An empty read ends the run `done`.

Chunk sizes **(proposal; the scale card measures and may change them)**: export 100 offers (one `export_batch`,
`Export_Scheduler::BATCH_SIZE`, because the validator builds the whole card); send stock / price 500; flags, warehouse, reset 500;
detach 200 (the detacher takes the account claim; a busy answer re-queues the same chunk after `Job_Gate::BUSY_DELAY` instead of
reporting a failure).

**What happens to rows that change mid-run.** The filter is read again at every chunk, so a product is taken as it is when its chunk
comes:

- it left the filter before its chunk → not processed, not counted;
- it entered the filter with an id above the cursor → processed; with an id below → not processed (the run moved past it);
- an action that moves a product out of the filter (export moves «Не связан» to «В очереди») cannot repeat it: the cursor is the id,
  not an offset;
- `total` is therefore an estimate; the result says how many were really processed.

**Locks.** One run per account: the apply creates the option with `add_option()` (atomic — a second apply finds it and is refused:
«Уже выполняется «Отправить остатки» по фильтру — дождитесь окончания или остановите его»). A `running` run whose `heartbeat_at` is
older than 15 minutes is taken over by the next apply **(proposal)**. Runs on two accounts are independent. Selection-mode actions
stay allowed during a run; their row-level guards (open export tasks, the account claim, the activity locks) already keep the two
from writing the same row at once.

**Progress, cancel, result.** The account line (`Site_Products_Activity::account()`) shows the run:
«Выполняется «Включить синхронизацию остатков» по фильтру: обработано 3 400 из ~12 345» with `stop` → «Остановить». While a run
is active every `all_matching` action is blocked on that account with this sentence. «Остановить» sets the cancel flag; the chunk
in progress finishes; work already queued (export batches, stock and price intents) is not withdrawn, and the stop sentence says
so. After the run the line shows its result for 24 hours **(proposal)**: «Готово: обработано 12 000, пропущено 300, не удалось 45 —
Подробнее», and «Подробнее» opens the existing report dialog (`actions/report.tsx`) with the merged groups.

**Licence and account gates at apply.** The matching apply runs the same `prepare()` checks as today (account enabled, licence for
remote actions) before it stores the run.

### 2.4 Which actions support the mode, and the preview text

All twelve. The four that run inside the request in the ticked mode (flags, warehouse, reset, detach) run as a background run in the
matching mode. «Связать по артикулу» is an account action, not part of A (it is C).

The matching preview costs one `COUNT` (the same as the table total) plus, for every action, one sentence. It does not plan every
product: planning 50 000 offers is the work the run spreads over chunks. Skip reasons arrive in the result.

| Action | Preview sentence in the matching mode (after «Будет затронуто N товаров по фильтру.») |
|---|---|
| Экспортировать / Обновить карточку | «Каждый товар будет проверен перед отправкой; прошедшие проверку уйдут в очередь частями по 100. Товары с ошибками останутся как есть — список причин будет в итоге.» |
| Отправить остатки / Отправить цены | «Текущие остатки (цены) с сайта будут отправлены на {маркетплейс} для всех связанных товаров по фильтру. Несвязанные и выключенные пропустим.» |
| Включить / Выключить синхронизацию остатков / цен | «Переключатель изменится у всех связанных товаров по фильтру. Включение сразу отправит текущие остатки (цены) с сайта.» (see §5, item 1) |
| Выбрать склад | «Остатки всех связанных товаров по фильтру будут отправляться на выбранный склад.» |
| Архивировать | «Остаток на {маркетплейс} станет нулевым, затем карточки уйдут в архив. Товары на сайте не меняются.» — tone `danger`, confirm label «Архивировать N товаров» |
| Снять связь | «Сайт забудет карточки всех товаров по фильтру. Карточки на {маркетплейс} не меняются.» — tone `danger`, confirm label «Снять связь у N товаров» |
| Сбросить состояние | «Товары снова станут «Не связан». На {маркетплейс} ничего не меняется.» |

Every matching preview ends with: «Действие выполняется в фоне частями; товары, изменившиеся за это время, обрабатываются такими,
какими они будут в момент обработки. Ход работы — в строке аккаунта над таблицей.»

### 2.5 Remote cards: «Импортировать все по фильтру»

The remote-cards table (`Remote_Cards_Definition`, cap 100) gets the same mode for «Импортировать» only, with the filters it has
(«На сайте», search). «Похожие артикулы» (the near-match report) is excluded: it is a report the merchant decides row by row.

The source of the rows is the snapshot, not SQL, so the run differs in one point: **at apply time** the provider evaluates the
filter over the current snapshot, runs `Remote_Cards_Import_Plan` over all matching cards (the plan is already an in-memory pass over
the snapshot) and freezes the eligible articles (create + link) into the run option. A snapshot older than its TTL refuses the apply
with «Список карточек устарел — нажмите «Обновить список» и повторите». The tick does not import: it feeds `Import_Scheduler::import()`
100 articles at a time (ten Action Scheduler actions), so a 50 000-card apply never inserts 5 000 actions in one request. The import
job re-checks every card (`Card_Import` order: already linked → nothing). Preview: «Будет импортировано N карточек, связано с уже
существующими товарами M. Картинки загружаются по одной — импорт тысяч карточек займёт несколько часов.» The run, its lock and the
account line are shared with §2.3 (one run per account, whichever table started it).

## 3. B — sync on right after linking

### 3.1 The setting

| | |
|---|---|
| Key | `sync_on_link`, in the `exchange` group of `Effective_Settings` (beside `orders_enabled`, `import_enabled`), so it has a «Общие» default and a per-account «свои» value like the rest of the group **(proposal: that group)** |
| Built-in default | `true` (operator decision) |
| Label | «Включать синхронизацию остатков и цен сразу после связывания» |
| Help | «Когда товар связывается с карточкой — по артикулу, вручную, при импорте или подтверждении похожего артикула, — у него сразу включается синхронизация, и текущие остаток и цена с сайта уходят на {маркетплейс}, заменяя те, что там сейчас. Выключите, если хотите сначала проверить связанные товары и включать синхронизацию сами.» |
| Reader | `Effective_Settings::sync_on_link( Account )`; an account whose `settings` JSON has no value gets the default — the same «no flag» rule the group already has |

What «sync on» achieves still depends on the account: the stock goes only when the account's stock mode is «Главный — сайт»
(`Stock_Mode::is_site()`), the price only when the account pushes prices (`Price_Intents::pushes()`). The setting switches the
per-link permission, never the account's direction.

### 3.2 Which link paths honour it

All of them through one place: `Card_Linker` receives a decision `function ( int $account_id ): bool` and a «sync starter».

| Path | Today (READ) | With the setting on |
|---|---|---|
| Link by article job (exact pairs) | `Card_Linker::link()`, switches 0 | switches 1, first send |
| «Связать с…» on the remote-cards table | same | same |
| Near-match «Это тот же товар» | same | same |
| Import, card linked to an existing product (`LINKED_EXISTING`) | same | switches 1, first send |
| Import, product created from the card (`CREATED`) | same | switches 1, first **stock** send only **(proposal)** — the price came from the card; sending it back through the account's markup would change the price on the marketplace the moment it was imported |
| Export | creates the row with switches 1 | unchanged: the site made the card, the site is the source |
| Product tab | creates rows with switches 1 | unchanged |

Insert writes `sync_stock = sync_price = 1`. Adoption of an existing identity-less row writes both to 1 as well; with the setting off
adoption leaves the row's switches as they are (today's behaviour). The first send is `Stock_Intents::record_stock()` and
`Price_Intents::record_for_link()` on the fresh row of a **created** card — the same calls the product tab makes on switch-on, so
the flushers, their gates and the v1 write gate (`First_Remote_Write`) apply unchanged. A product without managed stock (every
imported product: `Product_Writer` writes no stock — READ) records nothing.

### 3.3 Existing shops and v1

v1 never switched sync on when linking: the link tool wrote the Ozon product id, the offer id and status `imported` only
(`v1: includes/wc-ozon-functions.php` `wc_ozon_seller_run_sync_product_callback()`); stock sync was a per-product meta,
`sync_stock_amounts`, off unless set (`includes/class-product.php:303`), switched on in bulk by the «Enable sync stocks» tool,
whose text warned that the first synchronisation may overwrite Ozon's stock; price sync was a per-product `auto_update_price` plus
the global `enable_sync_prices`, default `no`.

Migration: the v2 migration already carries both per-product flags as they were (`ozon: includes/migration/class-link-row-builder.php:68-69`)
and maps the global price switch (`class-v1-settings-mapper.php:69`). Nothing is rewritten. The new key is absent on a migrated
account, so it reads the default — on — and affects only links made after the upgrade. **(proposal)** No other shop runs v2 Ozon in
production yet (Ozon 2.0 is unreleased), so no data migration exists for v2 rows.

## 4. C — link by article: whole shop or one category

The «Связать по артикулу» dialog (both tables share `Site_Products_Definition::link_by_article()`) gets two fields:

```text
 Связать по артикулу
 ( ) Весь магазин
 (•) Одна категория (вместе с подкатегориями)   [ Обувь ▾ ]   ← searchable, server-listed
 ─────────────────────────────────────────────────────────────
 Будут проверены 1 240 товаров с артикулом из категории «Обувь» и её
 подкатегорий. Карточки аккаунта читаются целиком, а связываются только
 товары этой категории.
                                        [ Отмена ]  [ Связать ]
```

- The category field is a `select` with `options_source` and `searchable`, served by the provider's `get_field_options()`
  (search by name, cursor paging) — not the 200-term list of the table filter, which a shop with thousands of categories outgrows
  (`Site_Products_Provider::MAX_CATEGORIES`).
- The payload carries `where` (`shop` / `category`) and `category` (term id, required with `category`, checked to be a `product_cat`
  term → 422 otherwise).
- Preview: `link_candidates` takes the category — `function ( int $account_id, ?int $category_id ): int` over
  `Shop_Sku_Source::products( $category_id )` — and the sentence names the category. The whole-shop sentence stays as today.
- Apply: `Link_Scheduler::link_by_article( $account_id, $category_id )` — the job already supports it.
- The remote read is the same either way: the job reads the whole cabinet and restricts only the shop side. The sentence says so,
  so the merchant does not expect a category run to be faster on the marketplace side.
- The run summary already stores `category_id`; the tools/activity line that shows the last run names the category.

## 5. D — further bulk operations (proposal for the operator)

Researched by code: the v1 tools and list tables (§1), the twelve v2 actions, the per-product settings of a link
(`sync_stock`, `sync_price`, `warehouse_id`, `is_oversize`, `price_rule_override`, `mapping_id` — `data/class-schema.php`) and the
Ozon endpoints the v2 client already lists (`ozon: includes/ozon/api/class-ozon-client.php`, among them `/v1/product/archive`,
`/v1/product/unarchive`, `/v2/products/stocks`, `/v1/product/import/prices`, `/v5/product/info/prices`,
`/v2/product/info/stocks-by-warehouse/fbs`).

Four of the examples on the card need no new action once A exists — they are a filter plus an existing action:

| Example | Is A + |
|---|---|
| Warehouse by category | category filter + «Выбрать склад» |
| Unlink by category (v1 had it) | category filter + «Снять связь» |
| «Пересчитать и отправить все цены / остатки сейчас» | no filter + «Отправить цены» / «Отправить остатки» |
| «Повторить все с ошибками»; export the unlinked of a category | «только с ошибками» (or state «Не связан» + category) + «Экспортировать»; the mapping is the category's own |

The real additions:

| # | Merchant problem | Scope | Cost | Recommendation |
|---|---|---|---|---|
| 1 | «Я включил синхронизацию у 3 000 товаров, а остатки на Ozon не поменялись» — bulk switch-on records no first send, the product tab does | `run_flags()`: after a switch-on, record the stock / price intent of each switched created card, as `Link_Settings_Writer` does | S | **now** — it is a defect, and A makes it a 50 000-product defect |
| 2 | «В v1 я задал цены для Ozon по категории, теперь хочу считать их по правилу аккаунта» — migrated per-product overrides cannot be cleared in bulk | new action «Сбросить цену для {маркетплейс}»: `set_price_override( null )` + `Price_Intents::change_override()` | S | **now** — v1 shops arrive with overrides from «Изменить цены» |
| 3 | «На Ozon цены правильные, на сайте устарели» — v1 had «Load prices from OZON» | new action «Загрузить цены с {маркетплейс}»: `Price_Scheduler::queue_refresh()` for created cards; the `refresh_prices` job and `Price_Refresher` exist | S | **now** — the counterweight to B's default-on first send for the shop that already sells on Ozon |
| 4 | Same for stock — v1 had «Load stocks from OZON» | new adapter answer (read stock per article) + a runner writing WC stock; Ozon declares no `INBOUND_STOCK` | M | after 2.0 |
| 5 | «Верните карточки из архива» | action «Вернуть из архива» + a capability; the Ozon client already lists `/v1/product/unarchive`; v1 had none | M | after 2.0 |
| 6 | «У меня артикулы на сайте не совпадают с Ozon» — 5 000 pairs by hand | CSV export of links (offer id ↔ product) and import of a filled file into `Card_Linker` | L | after 2.0; the near-match report and «Связать с…» cover small differences |
| 7 | «Все товары категории — крупногабарит» | action «Крупногабарит: да / нет» over `is_oversize` (Ozon declares `OVERSIZE_WAREHOUSE`) | S | after 2.0 |
| 8 | v1 «Изменить цены» — a fixed or percent price for a category | per-product override in bulk | S | **no** — the account price rule (markup, rounding, old price) is the group tool; per product stays in the tab |
| 9 | Explicit mapping for a group | action «Выбрать сопоставление» over `mapping_id` | S | **no** — mapping is already by category with inheritance (Ozon spec A14) |

## 6. Implementation cards

Tier: Sonnet 5.5 for every card (`claude-sonnet-5-5`); none needs Fable — each has a reference pattern in this tree (the import
and link jobs, `Link_Progress_Store`, the existing provider/definition pair). Critic: Codex. Every brief carries the worker's own
worktree path for Serena and says that `tests/**` is Serena-ignored. Settings live on the marketplace settings page, never WC
Integrations.

| Card | Files (disjoint by wave) | Size | Wave |
|---|---|---|---|
| **E1 — records engine: matching target** | `woodev/admin/records/` (`class-action-request.php`, `class-action-rest-handler.php`, `class-definition-parser.php`, `class-activity-normalizer.php`, `class-standard-messages.php`, new `interface-applies-to-matching.php`), `src/admin-records/` (`selection.ts`, `table-model.tsx`, `records-table.tsx`, `actions/bulk-toolbar.tsx`, `actions/api.ts`, `actions/types.ts`, `types.ts`, `messages.ts`), `woodev/class-map.php`, tests `tests/unit/Admin/Records/`, `tests/js/admin-records-*` | M | 1 |
| **B — sync on link** | `woodev/marketplace/settings/class-effective-settings.php`, `class-defaults-section.php`, `class-accounts-sections.php` (the per-account field), `flows/class-card-linker.php`, new `flows/class-link-sync-starter.php`, `class-flow-wiring.php`, `admin/pages/products/class-site-products-module.php` (only the `Card_Linker` construction), `flows/class-card-import.php` (CREATED: stock only), class map, tests | M | 1 |
| **C — link by category** | `class-site-products-definition.php` (`link_by_article()` fields), `class-site-products-bulk.php` (`plan_linking()`, `run_linking()`, preview sentence), `class-site-products-provider.php` (`get_field_options()` for the category), `class-site-products-module.php` (`link_candidates`), `class-remote-cards-definition.php` (sentence), tests | S | 1 — merge after B (both touch `class-site-products-module.php`, different hunks) |
| **E2 — bulk run for «Товары сайта»** | new `jobs/class-bulk-run-scheduler.php`, `jobs/class-bulk-run-runner.php`, `data/class-bulk-run-store.php`; `class-site-products-reader.php` (`ids_after()`), `class-site-products-bulk.php` (chunk entry point; D1 first send in `run_flags()`), `class-site-products-provider.php` (`Applies_To_Matching`), `class-site-products-definition.php` (`all_matching`, sentences, stop action), `class-site-products-activity.php` (run line), `class-site-products-module.php` (wiring), uninstaller (the option), class map, tests | L | 2 (after E1, B, C) |
| **E3 — remote cards: import all by filter** | `class-remote-cards-actions.php`, `class-remote-cards-provider.php`, `class-remote-cards-definition.php`, `class-remote-cards-import-plan.php` (all-matching entry), the bulk-run runner's import feeder (a strategy class `jobs/class-bulk-import-feeder.php`), tests | M | 3 (after E2) |
| **D2+D3 — clear price override, load prices** | `class-site-products-bulk.php`, `class-site-products-definition.php`, `class-site-products-provider.php` (licence phrase), tests | S | 3 (parallel with E3: disjoint files) |
| **Ozon (ozon repo)** | composer bump to the framework release; the adapter's withheld list if an action must stay off for Ozon; merchant docs (bulk by filter, the new setting, link by category); release notes. No Ozon code is needed for A–C | S | after a framework release with E2 |

### Acceptance (every card: phpunit, jest, phpcs, `lint:i18n`, `lint:docs` green)

- **E1:** a `matching` request with `ids` → 400; to a provider without the interface → 400; an undeclared filter → 400; jest: the
  bar appears only on a full page with `total > rows`, unticking a row leaves the mode, a filter change clears it, the toolbar
  hides actions without `all_matching`, the request body carries `matching` and no `ids`.
- **B:** a unit test per path of §3.2 with the setting on and off (switches and first-send calls), CREATED records stock only, a
  migrated account with no key reads on, export and tab unchanged.
- **C:** preview count for a category with subcategories equals `Shop_Sku_Source::products( $id )` minus linked; a bad term → 422;
  the queued action has `[ account, category, 0 ]`.
- **E2:** unit tests of the cursor rules of §2.3 (a product leaving the filter, one entering above and below the cursor, export
  moving the state), the `add_option` lock and its stale takeover, cancel, licence refusal mid-run, lost user capability, detach busy
  → re-queue. **Scale test on the rig: a generated fixture of 10 000 products (a WP-CLI script under the scratchpad, 20 % variable
  with 5 variations), runs of «Включить синхронизацию остатков», «Выбрать склад» and «Экспортировать» by filter driven with
  `wp action-scheduler run`; the PR states per tick: wall time, peak memory (`memory_get_peak_usage`), query count, offers
  processed; acceptance — every tick under the 20 s budget, peak memory flat across ticks (no growth beyond the first chunk), the
  final counts equal the fixture.**
- **E3:** 2 000 snapshot cards fixture: the apply stores the frozen list, the tick never queues more than ten actions, a stale
  snapshot refuses, near rows are never imported.
- **D2+D3:** clear writes `null` and records the price intent; load queues `refresh_prices` batches of ≤ 100 created articles.

## 7. Not verified

- Whether Ozon's daily limit on created / updated cards (`/v4/product/info/limit`, read by the credential health check) is handled
  by the export runner when an export by filter queues thousands of batches — not read; E2 reads `Export_Batch_Runner` and states
  in its PR what happens past the limit, and the export preview gains a sentence if work is deferred to the next day.
- `Stock_Intents::record_stock()` returning false for a product without managed stock — inferred from the bulk planner's reason
  text and its fallback, not read in the intent class.
- Whether `Price_Refresher` writes the marketplace price into the product as is or reverses the account markup — not read; D3 must
  say which before its preview text is final.
- Action Scheduler throughput on a real host: the scale test runs the queue by hand; on a shop with little traffic WP-Cron fires
  less often and a 50 000-product run takes longer. Not measured.
- The size of the snapshot transient for a 50 000-card cabinet (E3 depends on reading it whole) — not measured; the refresh job
  already reports a refused write.
- The 390 px layout of the selection bar — drawn here, not probed.

## Related

- [Marketplace module and PHP-driven React admin](../wiki/marketplace-admin.md) — Rule 9, the delivery-untouched invariant B3
- [Marketplace admin UI spec](2026-11-15-marketplace-admin-ui.md) — the table card header, counts and views this bar sits in
- [Marketplace card importer spec](2026-11-14-marketplace-card-importer.md) — the remote-cards tab, «Импортировать», the import job
- Framework card #1786; Ozon Seller spec `docs/spec/ozon-seller-v2-spec.md` §8 (products screen) in the Ozon repo
