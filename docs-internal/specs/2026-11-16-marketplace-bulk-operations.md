# Marketplace bulk operations for large catalogues (#1786)

**Status:** IMPLEMENTED — #1786 closed 16.11.2026 (cards #1795–#1797, s232–s234); the body describes the state before the work.
Items A, B and C are operator decisions of 16.11.2026 (#1786, s231); item D was decided by the operator the
same day «по рекомендациям» (comment on #1786). Every remaining choice of this spec that is in neither is marked **(proposal)**.
r2 folds in the r1 critic of PR #1787 (two Must, six Should); r3 the r2 critic (two Must, three Should): tick ownership is a
MySQL named lock, the price pull settles the queued price send, the import freeze keeps what the import job needs, stop names its
run.
**Date:** 2026-11-16. **Target:** framework 2.1.x, consumed by Ozon Seller 2.0.0 before its release.

**Evidence labels:** **READ** — code read for this spec or by its critic; **UNVERIFIED** — not read or not run, the named card
proves it. `fw:` = this repo at `46ca13f7` (origin/main); `ozon:` = `kalbac/woocommerce-ozon-seller` `main` at `3d68c88`;
`v1:` = the same repo at tag `v1.1.7`, read with `git show v1.1.7:<path>`.

## 0. The merchant and the decisions

The merchant is a WooCommerce shop with 1 000–50 000 products that already sells on Ozon. They want the two catalogues joined and
synchronised with as few clicks as possible. v1 lost at least two customers because mass operations were missing or clumsy. This
module is also the reference for a future Wildberries plugin, so nothing below is Ozon-specific unless it says so.

| # | Operator decision (#1786) | Section |
|---|---|---|
| A | Every bulk action of «Товары сайта» applies to the ticked rows OR to every product under the current filter (category, state, the whole catalogue); it runs in the background in parts; the preview says «будет затронуто N товаров». | §2 |
| B | An account setting «Включать синхронизацию остатков и цен сразу после связывания», on by default. | §3 |
| C | «Связать по артикулу»: the whole shop or one category. | §4 |
| D | Further bulk operations — decided «по рекомендациям»: now / after 2.0 / no as listed in §5. | §5 |

## 1. What exists today (READ)

| Fact | Where |
|---|---|
| Twelve bulk actions, all `scope: selection`; `max_selection` 200 rows | `fw: woodev/marketplace/admin/pages/products/class-site-products-definition.php:212-250` |
| A selection expands to offers (simple products and variations); a parent expands to all its variations; more than 500 offers is refused whole | `class-site-products-selection.php:24` (`MAX_OFFERS`), `resolve()`; refusal in `class-site-products-provider.php` `prepare()` |
| Background actions: export, link by article, send stock, send price, archive; the rest (sync flags, warehouse, detach, reset) run inside the request | `class-site-products-bulk.php:66` (`BACKGROUND`) |
| «Связать по артикулу» is `scope: account` and always queues the whole shop | `class-site-products-definition.php` `link_by_article()`, `class-site-products-bulk.php` `run_linking()` → `Link_Scheduler::link_by_article( $account_id )` |
| The link job already accepts a category and reads its subtree; a category run keeps the near-match entries of other categories | `jobs/class-link-scheduler.php:80`, `flows/class-shop-sku-source.php` `products()` (`get_term_children`), `jobs/class-link-jobs.php` `near_entries_outside()` |
| The link row's `sync_stock` / `sync_price` default to 0 | `data/class-schema.php:221-222` |
| `Card_Linker` is the one writer of a link from a card: the link job, «Связать с…», the near-match confirmation and the import all call it, and it leaves both switches off; the adoption SQL does not take the switches | `flows/class-card-linker.php` (class doc), callers in `class-link-jobs.php`, `class-remote-cards-actions.php` (two calls), `class-card-import.php`; `data/class-wpdb-link-store.php` `adopt_card()` |
| Export creates its row with both switches on; the product tab creates rows with both on | `flows/class-export-service.php:281-285`, `admin/product/class-link-settings-writer.php:291-295` |
| Switching sync on in the product tab records a first send (stock and price intent); the bulk «Включить синхронизацию …» only flips the column and records nothing | `class-link-settings-writer.php` `save_offer_extras()`; `class-site-products-bulk.php` `run_flags()` → `Wpdb_Link_Store::set_sync_flag()` |
| `Stock_Intents::record_stock()` records nothing unless the account's stock mode is «сайт», a warehouse is known and the product manages a quantity | `sync/class-stock-intents.php:202-219` |
| `Price_Refresher::refresh()` does nothing unless the account's `price_direction` is `from_marketplace`, and skips links with `sync_price` off | `sync/class-price-refresher.php` `refresh()`, `eligible()` |
| An imported product's price is written through `Shop_Price_Writer::restore()`, which undoes the account price rule | `flows/class-product-writer.php` `price()`, `sync/class-shop-price-writer.php` |
| Remote cards: import takes at most 100 selected cards; the queue is Action Scheduler chunks of 10 articles; the import plan loads every planned article's WC product | `class-remote-cards-definition.php:43` (`MAX_IMPORT`), `jobs/class-import-scheduler.php:28` (`BATCH`), `class-remote-cards-import-plan.php` `woocommerce_lookup()` |
| Remote cards read from one transient snapshot of the whole cabinet, TTL 600 s; the «На сайте» filter is an in-memory pass over it plus one identity-map read | `data/class-cards-snapshot-store.php`, `class-remote-cards-query.php` |
| The products filter: account, state group, category (with descendants), «только с ошибками», search; the reader builds the WHERE clause in SQL over `wp_posts`, listing parents only (`post_type = product`) | `class-site-products-filter.php`, `class-site-products-reader.php` `where()`, `category_clause()` |
| The records engine sends `ids` with every action; `scope: account` must send none; an apply answer is rebuilt from a whitelist and carries at most 50 groups of at most 5 examples | `fw: woodev/admin/records/class-action-request.php` `targets()`, `class-action-response.php` (`MAX_GROUPS`, `MAX_EXAMPLES`, `apply()`), `src/admin-records/actions/api.ts` |
| The shell's selection is an id list capped at `max_selection` and dropped on every list read; the activity view draws the account line as text with an optional progress bar | `src/admin-records/selection.ts`, `src/admin-records/activity/views.tsx` |
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
  the table's own `total` — parent products, the unit of the table — so it matches the count above the table.
- In the «все по фильтру» mode every visible row shows ticked; unticking any row, changing a filter, the search, the page or the
  account drops back to no selection (the list read already resets the selection, `selection.ts`).
- The bulk select lists only the actions that support the mode (§2.6); the others disappear from the list while the mode is on.
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
| Apply answer | Same shape as today plus a `run` block `{ id, products_total }`; `Action_Response::apply()` keeps it (today's whitelist would drop it). |
| Account activity | `Activity_Normalizer::account()` gains optional `actions: [ { action, label, payload } ]` (at most two; `payload` a flat map of short strings the server put there). The activity view renders them as secondary buttons on the account line; each runs the named account action through the normal action flow — the account context, `ids: []`, the entry's `payload` unchanged through preview and apply, and for a mutating action `confirmed: true` after its own dialog: the existing authenticated write contract. The action declares the payload keys as fields of a new type `hidden` (validated like any field, never rendered). |
| Shell | `selection.ts` gets the mode (`matching: boolean`, the frozen query of the click); `table-model.tsx` renders the bar; `bulk-toolbar.tsx` filters actions and sends `matching`; `actions/api.ts` sends either body; `activity/types.ts` + `views.tsx` render the line's buttons; new message keys (`select_all_matching`, `selected_all_matching`, `selected_page`, `clear_selection`) with Russian defaults in `Standard_Messages`. |

### 2.3 The run and its lifecycle (marketplace)

A matching apply never processes products inside the request. It stores a **run** and queues its first tick.

**Storage — one row per account in a new table** `{prefix}woodev_{marketplace id}_bulk_runs` **(proposal: a table, not an option —
the start and the stop are conditional `UPDATE`s, which an option cannot do)**. Created by `Schema` with the other marketplace tables,
deleted with the account's other rows when the account is deleted, dropped by the uninstaller.

| Column | Meaning |
|---|---|
| `account_id` (PK) | one row per account: the active run, or the last finished one |
| `run_id` | random token of this run |
| `state` | `running`, `done`, `cancelled`, `stopped` |
| `cancel` | 0 / 1 — written only by «Остановить», never by a tick, so a tick's save cannot erase it |
| `tick` | CHAR(32), NULL — the token of the tick that owns the run now; every save is fenced by it (reconnect case below) |
| `action`, `payload`, `filter` | the action id, its validated fields, the `Site_Products_Filter` values — **not** a list of ids |
| `user_id` | who started it |
| `cursor` | JSON position: `{ parent, child }` (§2.4); for the remote-cards run the index into `items` (§2.7) |
| `items` | LONGTEXT, NULL — only the remote-cards run's frozen card list (§2.7); every start writes it fresh |
| `counts` | JSON: `products_total`, `products_done` (parent products) and `offers_accepted`, `offers_skipped`, `offers_failed` |
| `report` | JSON groups, bounded (§2.5) |
| `started_at`, `finished_at` | times |

**Who may work on a run: one MySQL named lock per account.** Every tick and the stop take `GET_LOCK( name, 0 )` before they read the
row and release it after their last write — the pattern of the framework's `Order_Lock` (`woodev/shipping-method/order/class-order-lock.php`),
with a name scoped by database, table prefix and account the same way. **(proposal: the named lock, chosen because it is the simplest
mechanism that is provably exclusive and that the tree already uses.)** Why it holds under every interleaving the r2 critic named:

- **Staggered duplicate tick.** Tick A holds the lock from before its first read of the row until after its last write. Tick B, started
  at any moment in between, gets 0 from `GET_LOCK` and exits before it reads the row or touches a product. A tick that starts after A
  released reads the cursor A saved and goes on from there. No two ticks ever plan the same part.
- **A paused or dead tick.** MySQL frees a named lock the moment the connection that holds it closes. A tick that is merely slow still
  holds the lock, so nobody else works; a tick whose process died has lost the lock and cannot resume. There is no takeover by
  heartbeat any more, so no «old tick resumes after takeover» case exists.
- **A lost connection while PHP lives on (r3 critic, MANDATORY for E2).** Losing the connection does NOT kill the tick: `wpdb::query()`
  catches MySQL error 2006, reconnects through `check_connection()` and re-runs the query on a NEW connection that does not hold the
  lock (`wp-includes/class-wpdb.php`, the `_do_query()` retry). So the named lock alone is not a fence. The tick therefore also:
  1. **writes a tick token** right after `GET_LOCK` is granted: `UPDATE … SET tick = <random> WHERE account_id = %d AND run_id = <read>
     AND state = 'running'` (new column `tick`, CHAR(32), NULL);
  2. **fences every save** of `cursor`/`counts`/`report` and the terminal write with `AND tick = <mine>`; 0 affected rows → the tick
     stops at once: no further product write, no next tick queued, no `RELEASE_LOCK` attempt that matters (it releases nothing it owns);
  3. **re-checks ownership before every product write** with one cheap `SELECT IS_USED_LOCK( name ) = CONNECTION_ID()` — after a
     reconnect `CONNECTION_ID()` is the new connection, so the answer is false (the lock is free or a new tick holds it) and the tick
     stops as in 2.
  What remains is the single write whose own query triggered the reconnect retry; every per-product write of `Site_Products_Bulk` is
  idempotent (it replans from the stored links and the row-level guards), so a duplicate of that one write is harmless. E2 states in
  its PR the measured cost of check 3 per part (query count, wall time).
- **Side effects are inside ownership.** The tick checks `run_id` and `state = running` *after* taking the lock, and every product
  write, every save of `cursor`/`counts`/`report` and the terminal write happen while it holds it AND its tick token is the row's.
- **Replacement.** A run reaches a terminal state only by a write made under the lock (the tick, or the stop below). A new start
  replaces only a terminal row: `UPDATE … WHERE account_id = %d AND run_id = <read> AND state <> 'running'` (no row → `INSERT`; the
  PK refuses a second). A terminal write is the holder's last write, so a replaced run has no worker left.

Two limits, written into §7: a host whose `wpdb` uses *persistent* connections keeps a dead request's lock until that connection is
reused (WordPress does not do that by default), and a Galera cluster does not replicate named locks between nodes.

**Start, stop, recovery:**

| | |
|---|---|
| Start while `running` | refused: «Уже выполняется «Отправить остатки» по фильтру — дождитесь окончания или остановите его». No automatic takeover: a run that cannot progress is stopped by the merchant. |
| Start over a finished run | the conditional `UPDATE` above: a finished run never blocks the next one; its result is overwritten by the new run's |
| «Остановить» | carries the `run_id` the line showed (§2.2 `payload`). With `GET_LOCK( name, 0 )` granted: re-read; if `run_id` matches and `state = running` → `state = cancelled`, release. Not granted (a tick is working): `UPDATE … SET cancel = 1 WHERE account_id = %d AND run_id = <shown> AND state = 'running'`; the tick reads `cancel` after every part and finishes `cancelled`. Either way a `run_id` that is not the current running run changes nothing and answers «Это действие уже закончилось — новое действие не остановлено.» |
| Recovery of a lost enqueue | a tick saves its part and only then queues the next, so a crash between the two leaves `running` with nothing queued. The account line's activity read (every 10 s while the page is open) and a sweep on the `Export_Scheduler::SWEEP_INTERVAL` (900 s) pattern queue a tick when the row is `running`, no `bulk_run( account, run_id )` action is pending, and `GET_LOCK( name, 0 )` is granted (released at once). A surplus tick is harmless: it waits for the lock by exiting, or reads the saved cursor. |

Runs on two accounts are independent. Selection-mode actions stay allowed during a run; their row-level guards (open export tasks,
the account claim, the activity locks) already keep the two from writing the same row at once.

**The tick** — Action Scheduler action `woodev_{plugin id}_bulk_run( account_id, run_id )`:

1. `GET_LOCK( name, 0 )`; not granted → exit. Read the row; exit (releasing) unless `run_id` matches and `state = running`; `cancel = 1`
   → finish `cancelled`. Write the fresh tick token (lost-connection fence above); 0 rows → exit.
2. Gates: account enabled and of this adapter; for an action that leads to a remote write (export, send stock, send price,
   archive) `License_Gate::refusal_for()` and the account pause — a refusal ends the run `stopped` with that sentence. The remote
   writes themselves still pass `Job_Gate` in their own runners; this check only stops queueing new work.
3. `wp_set_current_user( user_id )`; if that user no longer has the action's capability → `stopped`
   («у пользователя, запустившего действие, больше нет прав»). Per-product `edit_product` checks then work as in the request,
   always on the parent for a variation (the existing `editable` rule of `Site_Products_Selection`).
4. Read the next part (§2.4), plan and run it with the same `Site_Products_Bulk` code the selection mode uses (it already replans
   from the stored links and never trusts a preview), merge counts and report, save; re-read `cancel`.
5. `wp_cache_flush_runtime()` so the WooCommerce object cache does not grow across parts; repeat 4 until the 20-second budget
   (`Import_Jobs::BUDGET_SECONDS`) is spent; save, queue the next tick with no delay, `RELEASE_LOCK` (in a `finally`). A read that
   returns nothing finishes `done`.

Part sizes **(proposal; E2 measures and may change them)**: export 100 offers (one `export_batch`, `Export_Scheduler::BATCH_SIZE`,
because the validator builds the whole card); send stock / price 500; flags, warehouse, reset 500; detach 200 (the detacher takes
the account claim; a busy answer saves nothing and re-queues the tick after `Job_Gate::BUSY_DELAY` instead of reporting a failure).

### 2.4 The cursor: a position inside a parent

The cursor is `{ parent, child }`, both product ids, ascending; `child = 0` means «this parent's variations not started».

1. If `child > 0` or the current parent is variable and not finished: read its next variations,
   `Site_Products_Reader::variation_ids_after( $parent, $child, $limit )` (`post_parent = parent`, `ID > child`, published or
   private, `ORDER BY ID LIMIT n`), with `n` the room left in the part. Process them; `child` = the last id read. A read shorter than
   `n` means the parent is finished: `child = 0` and `parent` stays as the last finished parent.
2. Otherwise read the next parents: `Site_Products_Reader::ids_after( $filter, $parent, $limit )` — the list's own `where()` plus
   `p.ID > parent ORDER BY p.ID ASC LIMIT n`. A simple product is one offer and finishes at once; a variable product enters step 1.

So a part never holds more than its size, a variable product of any size — 101 or 501 variations — is processed over as many parts
as it needs, and the parent cursor advances only after its last variation. Each offer is reached once: a variation belongs to one
parent and the `(parent, child)` order is monotonic. A parent deleted before its variations are read yields an empty read and
finishes; a variation deleted between two reads is simply absent from the next one.

**What happens to rows that change mid-run.** The filter is read again for every new parent, so a product is taken as it is when
its turn comes:

- it left the filter before its turn → not processed, not counted;
- it entered the filter with an id above the cursor → processed; with an id below → not processed (the run moved past it);
- an action that moves a product out of the filter (export moves «Не связан» to «В очереди») cannot repeat it: the cursor is the id,
  not an offset;
- a variable parent already entered is finished even if it leaves the filter half way (its membership was decided when it entered).

### 2.5 Counts, progress and the report

- **Two units, never mixed.** `products_total` (the apply-time `COUNT`, the table's unit) and `products_done` count parent products;
  `offers_*` count offers (simple products and variations). The account line reads:
  «Выполняется «Включить синхронизацию остатков» по фильтру: товаров 3 400 из ~12 345 (обработано вариантов и простых товаров: 7 900)».
  `products_total` is an estimate (§2.4); `products_done` may end above or below it.
- **The report is bounded.** Groups by reason, each with a count and at most five examples (`Action_Response::MAX_EXAMPLES`). At most
  49 distinct reasons are kept; a 50th and later merge into one group «Другие причины» whose count is the sum of the merged ones — so
  the report always fits `Action_Response::MAX_GROUPS` (50) and its counts add up to `offers_skipped + offers_failed`. Summaries are
  cut to the response's short-text limit. The validator's per-field reasons (`Bulk_Validation_Report::groups()`) are what makes the
  overflow real on a heterogeneous catalogue.
- **Delivery of the report.** The account line's second button «Подробнее» runs a read-only account action `bulk_report`
  (`effect: read`): its preview answer carries the stored groups through the existing preview contract and the dialog
  (`actions/report.tsx`) shows them. No new response shape.
- **Wording of a finished run** depends on what the action does:
  - flags, warehouse, reset, detach finish inside the run → «Готово: товаров 12 000; изменено 17 500, пропущено 300, не удалось 45»;
  - export, send stock, send price, archive only prepare and queue remote work → «Подготовлено и поставлено в очередь: 17 500;
    пропущено 300. Отправка идёт в фоне — её ход виден в столбце «Состояние», ошибки — в фильтре «только с ошибками»». The run
    never reports a remote outcome: later failures of an `export_batch` land on the link and the task, which carry no run id;
  - the remote-cards import → «Поставлено в очередь на импорт: 12 000 карточек; пропущено 300. Результат каждой карточки — в её
    строке».
- The finished row stays until the next run replaces it; the account line shows its result for 24 hours after `finished_at`
  **(proposal)**, with «Подробнее».
- While a run is `running` the line's buttons are «Остановить» (`bulk_stop`, payload `{ run_id }` of the run on the line — §2.3) and «Подробнее»; every `all_matching` action is blocked on
  that account with the running sentence. «Остановить» says: «Обработка остановится после текущей части. То, что уже поставлено в
  очередь, будет отправлено».

### 2.6 Which actions support the mode, and the preview text

All twelve. The four that run inside the request in the ticked mode (flags, warehouse, reset, detach) run as a background run in the
matching mode. «Связать по артикулу» is an account action, not part of A (it is C).

The matching preview costs one `COUNT` (the same as the table total) plus, for every action, one sentence. It does not plan every
product: planning 50 000 offers is the work the run spreads over parts. Skip reasons arrive in the report.

| Action | Preview sentence in the matching mode (after «Будет затронуто N товаров по фильтру.») |
|---|---|
| Экспортировать / Обновить карточку | «Каждый товар будет проверен перед отправкой; прошедшие проверку уйдут в очередь частями по 100. Товары с ошибками останутся как есть — список причин будет в итоге.» |
| Отправить остатки / Отправить цены | «Текущие остатки (цены) с сайта будут поставлены в очередь на отправку на {маркетплейс} для всех связанных товаров по фильтру. Несвязанные и выключенные пропустим.» |
| Включить / Выключить синхронизацию остатков / цен | «Переключатель изменится у всех связанных товаров по фильтру. Включение сразу поставит в очередь отправку текущих остатков (цен) с сайта.» (§5, D1) |
| Выбрать склад | «Остатки всех связанных товаров по фильтру будут отправляться на выбранный склад.» |
| Архивировать | «Остаток на {маркетплейс} станет нулевым, затем карточки уйдут в архив. Товары на сайте не меняются.» — tone `danger`, confirm label «Архивировать N товаров» |
| Снять связь | «Сайт забудет карточки всех товаров по фильтру. Карточки на {маркетплейс} не меняются.» — tone `danger`, confirm label «Снять связь у N товаров» |
| Сбросить состояние | «Товары снова станут «Не связан». На {маркетплейс} ничего не меняется.» |
| Сбросить свою цену для {маркетплейс} (D2) | «У всех связанных товаров по фильтру своя цена будет удалена: цена снова считается по правилу аккаунта.» |
| Загрузить цены с {маркетплейс} (D3) | «Цены созданных карточек будут прочитаны с {маркетплейс} и записаны в товары сайта.» |

Every matching preview ends with: «Действие выполняется в фоне частями; товары, изменившиеся за это время, обрабатываются такими,
какими они будут в момент обработки. Ход работы — в строке аккаунта над таблицей.»

### 2.7 Remote cards: «Импортировать все по фильтру»

The remote-cards table (`Remote_Cards_Definition`, cap 100) gets the same mode for «Импортировать» only, with the filters it has
(«На сайте», search). «Похожие артикулы» (the near-match report) is excluded: it is a report the merchant decides row by row. It
shares the run table, the lock and the account line of §2.3 (one run per account, whichever table started it).

The rows come from the snapshot, not SQL, and the selection plan loads a WC product per article, so the request does neither at
scale. The design does not run `Remote_Cards_Import_Plan` at feed time at all: that plan is the *preview* of «Импортировать», and its
inputs (`details_ok`, `archived`, `state`, `group`) come from a snapshot that expires in 600 s. The import job is already the
authority — it reads every card from the marketplace itself (`Import_Jobs` step 2, «one request reads the cards of the batch») and
`Card_Import` decides in its own order: already linked → nothing; archived → skipped with a note; a variable parent refused; a
half-made group resumed or, for a sibling, skipped with a note; else link or create. So the run only needs the articles and their
**order**:

1. **Apply** checks the snapshot is present and younger than its TTL (else «Список карточек устарел — нажмите «Обновить список» и
   повторите»), counts the matching cards with the in-memory filter the page already uses, and stores the run with the filter. No
   plan, no article list in the request.
2. **Freeze** (the first tick, one pass): read the snapshot once (it is one transient, read whole anyway), apply the filter, and
   write `items` — a JSON list of `[ offer_id, group_key ]` (`Group_Assembler::key_of()`), the only facts the order needs. The order
   reproduces the plan's `with_products()` rule: **first the articles that are the SKU of a shop product still carrying the import
   marker** (`Product_Writer::marker_key()`; one batched SQL per 1 000 articles over `wc_product_meta_lookup` + `postmeta`, never a
   `wc_get_product()` per article), then the rest grouped by `group_key`. A snapshot missing or expired at the freeze stops the run
   with the apply sentence. After the freeze the snapshot may expire freely: nothing later reads it.
3. **Feed** (later ticks): take the next 100 entries of `items` from `cursor` and hand them to `Import_Scheduler::import()` — ten
   actions per part, in order. Import actions of one account run one at a time (each takes the account claim through
   `Account_Job`) and Action Scheduler starts them in the order they were queued, so a resuming product is finished before a sibling
   of its group arrives, whichever part the two fall in. **(UNVERIFIED: that Action Scheduler runs same-timestamp actions in id
   order; the feed queues each part one second after the previous one, which makes the order explicit either way.)**
   **Queue order alone does not survive a retry (r3 critic, MANDATORY for E3):** `Import_Jobs::run()` requeues an unfinished card on
   `Budget_Spent`, and `Account_Job` retries a busy or throttled job, so a later sibling can run while the resuming card waits. A
   sibling whose group still has an unfinished resuming product therefore **re-queues itself** (after `Job_Gate::BUSY_DELAY`, at most
   the import job's own attempt cap) instead of being skipped as group-building; E3 owns that dependency check in the feeder/import path.

`items` lives in the run's own row (§2.3), so there is no second row and no key conflict; a new start overwrites it; the account's
deletion and the uninstaller remove it with the row. 50 000 entries are about 2–3 MB of JSON, under the MySQL/MariaDB default
`max_allowed_packet` (16–64 MB); E3 states the measured size.

Preview: «Будет импортировано до N карточек: часть из них свяжется с уже существующими товарами. Картинки загружаются по одной —
импорт тысяч карточек займёт несколько часов.»

## 3. B — sync on right after linking

### 3.1 The setting

| | |
|---|---|
| Key | `sync_on_link`, in the `exchange` group of `Effective_Settings` (beside `orders_enabled`, `import_enabled`), so it has a «Общие» default and a per-account «свои» value like the rest of the group **(proposal: that group)** |
| Built-in default | `true` (operator decision) |
| Label | «Включать синхронизацию остатков и цен сразу после связывания» |
| Help | «Когда товар связывается с карточкой — по артикулу, вручную, при импорте или подтверждении похожего артикула, — у него сразу включается синхронизация. Если в настройках аккаунта остатки и цены отправляются с сайта, текущие остаток и цена сразу уйдут на {маркетплейс} и заменят те, что там сейчас. Выключите, если хотите сначала проверить связанные товары и включать синхронизацию сами.» |
| Reader | `Effective_Settings::sync_on_link( Account )`; an account whose `settings` JSON has no value gets the default — the same «no flag» rule the group already has |

What «sync on» achieves still depends on the account: the stock goes only when the account's stock mode is «Главный — сайт»
(`Stock_Mode::is_site()`, checked inside `record_stock()`), the price only when the account pushes prices (`Price_Intents::pushes()`).
The setting switches the per-link permission, never the account's direction.

### 3.2 Which link paths honour it

All of them through one place: `Card_Linker` receives the decision `function ( int $account_id ): bool` and the two first-send
recorders (callables over `Stock_Intents::record_stock()` and `Price_Intents::record_for_link()`), injected where it is built.

| Path | Today (READ) | With the setting on |
|---|---|---|
| Link by article job (exact pairs) | `Card_Linker::link()`, switches 0 | switches 1, first send |
| «Связать с…» on the remote-cards table | same | same |
| Near-match «Это тот же товар» | same | same |
| Import, card linked to an existing product (`LINKED_EXISTING`) | same | switches 1, first send |
| Import, product created from the card (`CREATED`) | same | switches 1, first **stock** send only **(proposal)** — the price was just written from the card through `Shop_Price_Writer::restore()`; sending it straight back is at best a no-op and at worst a rounding change |
| Export | creates the row with switches 1 | unchanged: the site made the card, the site is the source |
| Product tab | creates rows with switches 1 | unchanged |

Insert writes `sync_stock = sync_price = 1`. Adoption of an existing identity-less row writes both to 1 as well — `adopt_card()`
gains the two columns; with the setting off adoption leaves the row's switches as they are (today's behaviour). The first send runs
on the fresh row of a **created** card — the same calls the product tab makes on switch-on, so the flushers, their gates and the v1
write gate (`First_Remote_Write`) apply unchanged. A product without managed stock (every imported product: `Product_Writer` writes no
stock) records nothing, and so does an account whose stock mode is «Главный — маркетплейс» or «Не синхронизировать».

### 3.3 Existing shops and v1

v1 never switched sync on when linking: the link tool wrote the Ozon product id, the offer id and status `imported` only
(`v1: includes/wc-ozon-functions.php` `wc_ozon_seller_run_sync_product_callback()`); stock sync was a per-product meta,
`sync_stock_amounts`, off unless set (`includes/class-product.php:303`), switched on in bulk by the «Enable sync stocks» tool,
whose text warned that the first synchronisation may overwrite Ozon's stock; price sync was a per-product `auto_update_price` plus
the global `enable_sync_prices`, default `no`.

Migration: the v2 migration already carries both per-product flags as they were (`ozon: includes/migration/class-link-row-builder.php:68-69`)
and maps the global price switch (`class-v1-settings-mapper.php:69`). Nothing is rewritten. The new key is absent on a migrated
account, so it reads the default — on — and affects only links made after the upgrade **(proposal)**. No other shop runs v2 Ozon in
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
- The run summary already stores `category_id`; the line that shows the last run names the category.

## 5. D — further bulk operations (operator decision, 16.11.2026)

Researched by code: the v1 tools and list tables (§1), the twelve v2 actions, the per-product settings of a link
(`sync_stock`, `sync_price`, `warehouse_id`, `is_oversize`, `price_rule_override`, `mapping_id` — `data/class-schema.php`) and the
Ozon endpoints the v2 client already lists (`ozon: includes/ozon/api/class-ozon-client.php`, among them `/v1/product/archive`,
`/v1/product/unarchive`, `/v2/products/stocks`, `/v1/product/import/prices`, `/v5/product/info/prices`,
`/v2/product/info/stocks-by-warehouse/fbs`). The operator accepted the recommendations as they stood (comment on #1786).

Covered by A — a filter plus an existing action, no new code:

| Example | Is A + |
|---|---|
| Warehouse by category | category filter + «Выбрать склад» |
| Unlink by category (v1 had it) | category filter + «Снять связь» |
| «Пересчитать и отправить все цены / остатки сейчас» | no filter + «Отправить цены» / «Отправить остатки» |
| «Повторить все с ошибками»; export the unlinked of a category | «только с ошибками» (or state «Не связан» + category) + «Экспортировать»; the mapping is the category's own |

Decided list:

| # | Merchant problem | Scope | Cost | Decision |
|---|---|---|---|---|
| D1 | «Я включил синхронизацию у 3 000 товаров, а остатки на Ozon не поменялись» — bulk switch-on records no first send, the product tab does | `run_flags()`: after a switch-on, record the stock / price intent of each switched created card, as `Link_Settings_Writer` does | S | **now** (in E2) |
| D2 | «В v1 я задал цены для Ozon по категории, теперь хочу считать их по правилу аккаунта» — migrated per-product overrides cannot be cleared in bulk | action «Сбросить свою цену для {маркетплейс}»: `set_price_override( null )` + `Price_Intents::change_override()`; rows without an override are skipped «своей цены нет» | S | **now** (card D) |
| D3 | «На Ozon цены правильные, на сайте устарели» — v1 had «Load prices from OZON» | action «Загрузить цены с {маркетплейс}»: a **one-time pull** for created cards, see §5.1 | M | **now** (card D) |
| D4 | Same for stock — v1 had «Load stocks from OZON» | new adapter answer (read stock per article) + a runner writing WC stock; Ozon declares no `INBOUND_STOCK` | M | after 2.0 |
| D5 | «Верните карточки из архива» | action «Вернуть из архива» + a capability; the Ozon client already lists `/v1/product/unarchive`; v1 had none | M | after 2.0 |
| D6 | «У меня артикулы на сайте не совпадают с Ozon» — 5 000 pairs by hand | CSV export of links (offer id ↔ product) and import of a filled file into `Card_Linker` | L | after 2.0 |
| D7 | «Все товары категории — крупногабарит» | action «Крупногабарит: да / нет» over `is_oversize` (Ozon declares `OVERSIZE_WAREHOUSE`) | S | after 2.0 |
| D8 | v1 «Изменить цены» — a fixed or percent price for a category | per-product override in bulk | S | **no** — the account price rule is the group tool; per product stays in the tab |
| D9 | Explicit mapping for a group | action «Выбрать сопоставление» over `mapping_id` | S | **no** — mapping is already by category with inheritance (Ozon spec A14) |

The after-2.0 items get backlog cards (priority «После v2») when this spec merges.

### 5.1 D3 — the one-time price pull

The existing `refresh_prices` job cannot be reused as it is: `Price_Refresher::refresh()` does nothing unless the account's
`price_direction` is `from_marketplace`, and it skips links with `sync_price` off. A shop on the default `to_marketplace` would press
the button and nothing would change. D3 is therefore a separate mode:

- `Price_Refresher::pull_once( Account, offer_ids, Claim_Fence )` — the same reader and `Shop_Price_Writer`, without the direction
  check and without the `sync_price` check (the merchant asked explicitly); only `created` cards; a price the writer cannot
  restore is skipped with the writer's own sentence.
- Its job is the existing `refresh_prices` hook with a mode argument, ≤ 100 articles per action (`Price_Scheduler::REFRESH_BATCH`),
  run through `Account_Job` — the job gate and **the account claim**. The licence gate applies to queueing it.
- **It settles the queued send instead of leaving it.** The sender (`Price_Flush_Runner`) sends what `Price_Sync_Queue::due()`
  finds — `sync_state.pending_payload` that differs from `sent_payload` — not `links.last_pushed_price`, so recording the read price
  only in the links table would let a send queued earlier (WC 150 while the marketplace holds 100) go out after the pull and undo it.
  Per article, under the claim:
  1. read the pair's `pending_rev` (`r0`; no row → nothing is queued);
  2. write the shop price with `Shop_Price_Writer::write()`, which runs inside `Price_Refresh_Guard::run()`: the guard stops this
     marketplace's saved-product hook (`Price_Intents::on_product_updated()`), so the write itself records no new revision;
  3. new `Price_Sync_Queue::settle_pulled( account, link, r0, payload, token )`: one fenced `UPDATE` (the queue's own `write()` with
     the claim token) setting `pending_payload = sent_payload = <the read price as a send payload>`, `sent_rev = pending_rev`,
     `in_flight_* = NULL`, `attempts = 0`, `next_attempt_at = NULL`, **`WHERE … AND pending_rev = r0`**, then
     `links.last_pushed_price` as `accept()` does.
- Why this holds under the critic's cases:
  - **queued 150, marketplace 100:** `r0` is the revision of 150; the settle replaces it with 100 as both pending and sent, so `due()`
    no longer selects the pair; nothing is sent;
  - **a sender in flight:** the sender holds the account claim for its whole run (`flush_under_claim()`), so the pull's
    `Account_Job` waits (`Job_Gate::BUSY_DELAY`) and the two never overlap; a stale in-flight snapshot left by a dead sender is cleared,
    and that sender's `accept()` is fenced by its lost claim token and changes nothing;
  - **a real edit after the pull wrote the price:** the hook is not guarded any more, the edit stores a revision above `r0`, and it is
    sent as usual.
  - **an edit that lands between step 1 and step 2 (r3 critic, MANDATORY for D).** The merchant saves WC 200 (revision 2) after the
    pull read `r0 = 1`; the pull's step 2 then overwrites WC with 100 and its settle correctly fails. A queue CAS after the WC save
    cannot repair that, so **a failed settle (0 rows) triggers a restore**: the pull reads the pair's current pending revision `r1` and
    writes **that intent's shop price** back into the product under `Price_Refresh_Guard` — the merchant's edit is newer than the
    pull's read and wins. The shop-side price is taken from what `Price_Intents` recorded the intent from; if the pending payload is
    not invertible to a WC price (markup, rounding), D stores the shop price alongside the intent (a field in `pending_payload`'s
    meta, no new table). Then it re-reads `pending_rev`: unchanged → done (the pending 200 goes out as usual, WC holds 200); changed
    again → repeat, at most twice, then leave the article with the report line «Цена менялась во время загрузки — проверьте товар»
    and the newest pending intent untouched. No path leaves WC holding the pulled price while a newer merchant intent is pending.
- The one window left is the claim's own: a sender stalled past `Job_Gate::CLAIM_TTL` (120 s) inside its HTTP request can still land
  its send after the pull read the marketplace. Every claim user shares that window; it is named in §7.
- Available on every account direction; on `from_marketplace` it is the same read the push job does, run now.

## 6. Implementation cards

Tier: Sonnet 5.5 for every card (`claude-sonnet-5-5`); none needs Fable — each has a reference pattern in this tree (the import
and link jobs, the claim fence of the accounts table, `Link_Progress_Store`, the existing provider/definition pair). Critic: Codex.
Every brief carries the worker's own worktree path for Serena and says that `tests/**` is Serena-ignored. Settings live on the
marketplace settings page, never WC Integrations.

**File ownership rule.** `woodev/class-map.php` and the development classmap in `composer.json` change only in a card that adds a PHP
file, and only one such card runs per wave. B and C add no file.

| Card | Files | Size | Wave / depends on |
|---|---|---|---|
| **E1 — records engine: matching target and account-line actions** | `woodev/admin/records/` (`class-action-request.php`, `class-action-rest-handler.php`, `class-action-response.php`, `class-definition-parser.php`, `class-activity-normalizer.php`, `class-standard-messages.php`, new `interface-applies-to-matching.php`), `src/admin-records/` (`selection.ts`, `table-model.tsx`, `records-table.tsx`, `actions/bulk-toolbar.tsx`, `actions/api.ts`, `actions/types.ts`, `activity/types.ts`, `activity/views.tsx`, `types.ts`, `messages.ts`, `actions/fields.tsx` — the renderer of the `hidden` field), `woodev/admin/records/class-records-definition.php` and `class-action-input.php` (the server's `FIELD_TYPES` and input switch for `hidden`, r3 critic), `woodev/class-map.php`, `composer.json` classmap, tests `tests/unit/Admin/Records/`, `tests/js/admin-records-*` | M | 1 |
| **B — sync on link** | `woodev/marketplace/settings/class-effective-settings.php`, `class-defaults-section.php`, `class-accounts-sections.php` (the per-account field), `flows/class-card-linker.php` (decision + first-send recorders, no new class), `data/class-wpdb-link-store.php` (`adopt_card()` takes the switches), `flows/class-card-import.php` (CREATED: stock only), `class-flow-wiring.php`, `admin/pages/products/class-site-products-module.php` (only the `Card_Linker` construction), tests | M | 1 |
| **C — link by category** | `class-site-products-definition.php` (`link_by_article()` fields), `class-site-products-bulk.php` (`plan_linking()`, `run_linking()`, preview sentence), `class-site-products-provider.php` (`get_field_options()` for the category), `class-site-products-module.php` (`link_candidates`), `class-remote-cards-definition.php` (sentence), tests | S | 1b — **depends on B** (shares `class-site-products-module.php`); starts after B merges |
| **E2 — bulk run for «Товары сайта», with D1** | new `jobs/class-bulk-run-scheduler.php`, `jobs/class-bulk-run-runner.php`, `data/class-bulk-run-store.php`; `data/class-schema.php` (the table, `items` column included), `data/class-uninstaller.php`, the account deletion path that removes the account's rows (`Wpdb_Link_Store::delete_account()`'s caller), `class-site-products-reader.php` (`ids_after()`, `variation_ids_after()`), `class-site-products-bulk.php` (part entry point; D1 in `run_flags()`), `class-site-products-provider.php` (`Applies_To_Matching`, `bulk_stop`, `bulk_report`), `class-site-products-definition.php` (`all_matching`, sentences), `class-site-products-activity.php` (run line, recovery check), the named lock (a small `jobs/class-bulk-run-lock.php` on the `Order_Lock` pattern), `class-site-products-module.php` (wiring), `woodev/class-map.php`, `composer.json` classmap, tests | L | 2 — after E1, B, C |
| **E3 — remote cards: import all by filter** | `class-remote-cards-actions.php`, `class-remote-cards-provider.php`, `class-remote-cards-definition.php`, new `jobs/class-bulk-import-feeder.php` (freeze + feed strategy of the run runner), `data/class-bulk-run-store.php` (read/write of `items`; no schema change — E2 created the column), `woodev/class-map.php`, `composer.json` classmap, tests | M | 3 — after E2 |
| **D — «Сбросить свою цену», «Загрузить цены»** | `class-site-products-bulk.php`, `class-site-products-definition.php`, `class-site-products-provider.php` (licence phrase), `sync/class-price-refresher.php` (`pull_once()`), `price/class-price-sync-queue.php` (`settle_pulled()`, the restore read), `price/class-price-intents.php` (only if the shop price must be stored with the intent, §5.1), `jobs/class-price-refresh-runner.php` (the mode, through `Account_Job`), `jobs/class-price-scheduler.php` (mode argument), tests — no new PHP file | M | 3 — after E2; parallel with E3 (disjoint files) |
| **Ozon (ozon repo)** | composer bump to the framework release; the adapter's withheld list if an action must stay off for Ozon; merchant docs (bulk by filter, the new setting, link by category, the two price actions); release notes. No Ozon code is needed for A–C and D1–D3 (UNVERIFIED for the daily card limit, §7) | S | after a framework release with E2, E3, D |

### Acceptance (every card: phpunit, jest, phpcs, `lint:i18n`, `lint:docs` green)

- **E1:** a `matching` request with `ids` → 400; to a provider without the interface → 400; an undeclared filter → 400; an apply
  answer keeps `run { id, products_total }`; jest: the bar appears only on a full page with `total > rows`, unticking a row leaves the
  mode, a filter change clears it, the toolbar hides actions without `all_matching`, the request body carries `matching` and no
  `ids`; an account line with `actions` renders the buttons and one of them sends the account context, `ids: []`, the entry's
  `payload` unchanged in preview and apply, and `confirmed: true`; a `hidden` field is validated and never rendered.
- **B:** a unit test per path of §3.2 with the setting on and off (switches and first-send calls); CREATED records stock only;
  adoption writes the switches; first send records nothing on stock mode `marketplace` and `off` and for a product without managed
  stock; a migrated account with no key reads on; export and tab unchanged.
- **C:** preview count for a category with subcategories equals `Shop_Sku_Source::products( $id )` minus linked; a bad term → 422;
  the queued action has `[ account, category, 0 ]`.
- **E2:**
  - cursor: a product leaving the filter, one entering above and below the cursor, export moving the state; a variable product with
    101 and one with 501 variations finished over several parts with every variation reached once; a variation deleted between two
    reads; a parent deleted before its variations are read;
  - lifecycle, with **two real database connections** on the rig (an integration test; the unit suite mocks `wpdb` and cannot hold a
    named lock): tick B started *after* tick A took the lock exits without reading the row or writing a product; a tick whose
    connection is closed mid-part frees the lock and the next tick continues from the last saved cursor; a second start while running
    is refused; a start right after a finished run succeeds at once; «Остановить» with the lock free finishes the run `cancelled`, with
    the lock held sets `cancel` and the tick finishes after its part; cancel during a tick's save survives the save; **two-admin stop
    race:** a stop confirmed for R1 after R1 finished and R2 started answers «уже закончилось» and R2 keeps running; a save followed
    by a lost enqueue is recovered by the activity check and by the sweep; licence refusal mid-run; lost user capability; detach busy
    → re-queue; **reconnect fence (mandatory):** on two real connections, kill tick A's SERVER connection (`KILL <id>` from a third
    connection) while A's PHP continues; tick B takes the lock and writes its token; A's next product write is refused by the
    ownership check, A's next save affects 0 rows, A queues no tick, and the cursor/counts B saved are not overwritten;
  - report: 60 distinct reasons → 49 groups + «Другие причины», counts conserved; a 50 000-offer failing run keeps the stored report
    under 64 KB **(proposal for the bound)**;
  - **scale on the rig:** a generated fixture (a WP-CLI script under the scratchpad) of **10 000 parent products = 8 000 simple +
    1 998 variable × 5 variations + 1 variable × 101 + 1 variable × 501 → 18 592 offers**; link rows for the first 6 000 simple
    products and every variation (16 592 linked `created` offers with both switches off), 2 000 simple unlinked. Runs driven with
    `wp action-scheduler run`: «Включить синхронизацию остатков» by no filter must end with products 10 000, accepted 16 592,
    skipped 2 000 («не отправлялся на маркетплейс»), failed 0; «Выбрать склад» the same partition; «Экспортировать» with the state
    «Не связан» must reach exactly the 2 000 unlinked. The PR states per tick: wall time, peak memory (`memory_get_peak_usage`),
    query count, offers processed; every tick under the 20 s budget, peak memory flat across ticks.
- **E3:** a 50 000-card snapshot fixture: the apply request's time and peak memory, the freeze tick's time, peak memory and stored
  `items` bytes stated in the PR (no plan, no article list in the request); the snapshot expiring between the freeze and the feed
  changes nothing; a half-made group whose resuming article would sort after a sibling is fed with the resuming article first, and
  both end imported; **with a forced `Budget_Spent` in the resuming card's image step** the sibling re-queues itself instead of being
  skipped, and both still end imported (mandatory); a part never queues more than ten import actions; near rows are never imported; a new start clears `items`.
- **D:** «Сбросить свою цену» writes `null` and records the price intent, skips rows without an override; «Загрузить цены» on a
  `to_marketplace` account writes the restored price into the product and records no new revision; **a pair with WC 150 pending and
  the marketplace at 100** ends with pending = sent = 100 and is not selected by `due()`; a pull while a sender holds the claim waits
  and runs after it; an edit recorded between the pull's revision read and its settle is still sent; **the edit-BEFORE-the-WC-write interleaving
  (mandatory):** pull reads `r0`, the merchant saves WC 200, the pull writes 100 and its settle fails → the restore writes 200 back,
  WC = 200, pending = 200 and it is sent; an edit landing again during the restore is retried at most twice, then the report line; a real edit after the pull is
  sent; a stale in-flight snapshot is cleared and the dead sender's `accept()` changes nothing; on `from_marketplace` the same; a link
  with `sync_price` off is still pulled; a non-created card is skipped.

## 7. Not verified

- Whether Ozon's daily limit on created / updated cards (`/v4/product/info/limit`, read by the credential health check) is respected
  when an export by filter queues thousands of batches. The critic of r1 read `Ozon_Buckets`, `Ozon_Error_Classifier`,
  `Ozon_Export_Gateway` and `Export_Batch_Runner`: per-item throttling and rescheduling exist, daily-quota admission does not appear.
  E2 states in its PR what happens past the limit; until then «No Ozon code is needed» is not established for export.
- That `Price_Refresh_Guard` covers every price hook `Price_Intents` listens to on a product save — the r2 critic confirmed
  `on_product_updated()`; the other hooks of `Price_Intents` were not read in full. D's acceptance «records no new revision» proves it.
- The lost-connection fence (§2.3) — designed from the `wpdb` retry path, not probed on a live disconnect; E2's integration test proves it.
- The named lock's two environment limits (§2.3): persistent `wpdb` connections keep a dead request's lock until the connection is
  reused, and Galera clusters do not replicate `GET_LOCK`. Neither is WordPress's default; neither was probed on a real host.
- The account claim's own window in D3 (§5.1): a sender stalled past `Job_Gate::CLAIM_TTL` inside its HTTP request can still land a
  send after the pull read the marketplace. Shared by every claim user; not measured.
- That Action Scheduler starts same-timestamp actions of one group in id order (E3's feed spaces its parts by one second, so it does
  not depend on it).
- Action Scheduler throughput on a real host: the scale test runs the queue by hand; on a shop with little traffic WP-Cron fires
  less often and a 50 000-product run takes longer. Not measured.
- The size of the snapshot transient for a 50 000-card cabinet (E3 depends on reading it whole) — not measured; the refresh job
  already reports a refused write.
- The 390 px layout of the selection bar and the account-line buttons — drawn here, not probed.

## Related

- [Marketplace module and PHP-driven React admin](../wiki/marketplace-admin.md) — Rule 9, the delivery-untouched invariant B3
- [Marketplace admin UI spec](2026-11-15-marketplace-admin-ui.md) — the table card header, counts and views this bar sits in
- [Marketplace card importer spec](2026-11-14-marketplace-card-importer.md) — the remote-cards tab, «Импортировать», the import job
- Framework card #1786; Ozon Seller spec `docs/spec/ozon-seller-v2-spec.md` §8 (products screen) in the Ozon repo
