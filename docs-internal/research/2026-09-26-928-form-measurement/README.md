# #928 wave 2 — which cheaper negative-filter form to build, decided by measurement

> Session 140 (26.09.2026), macOS laptop. Research only — no framework code was changed and no real
> database was written to. Produced by a Sonnet 5 worker for card #928; the card forbids deciding by
> reasoning, so every number below comes from a replayable probe in this directory
> ([`lib.php`](lib.php), [`run.php`](run.php), [`summarize.php`](summarize.php); raw rows in
> [`results/`](results/), real SQL of every form in [`sql/`](sql/)).

## Recommendation

**Build form (X) — two queries — but in its INCLUSION shape, and take the marker scope out of the main
query.** Precompute, in one flat SQL against the meta table, the ids of the orders that pass the whole
filter (`marker present AND NOT EXISTS disqualifying status`), and hand that list to the main query as
`post__in`. Do **not** build the exclusion shape the card sketched (`exclude` / `post__not_in` =
"orders to hide") and do **not** build (H) with the scope left as a `meta_query`.

The deciding measurement is not the form of the negation at all — it is the **marker-scope
`meta_query`** that every form except two still carries:

| | what it keeps | at 1 000 orders, 4 carriers (M4:B0), 20 unrelated meta rows/order |
|---|---|---|
| current shape (`3M + B` joins) | scope + negation joins | **> 30 s** |
| (H) `NOT IN (SELECT …)` + scope `meta_query` | `N` scope joins | **> 30 s** |
| (X) exclusion list + scope `meta_query` | `N` scope joins | **> 30 s** |
| the **unfiltered** aggregate page (scope only) | `N` scope joins | **> 30 s** |
| (H-full) `EXISTS` scope + `NOT EXISTS` negation, **zero joins** | nothing | 2.8 ms |
| **(X-incl) id query → `post__in`, zero joins** | nothing | **2.2 ms** |

(The `current`, `H` and `X-excl` rows above are `*`-inferred cells: each contains the scope joins,
and the scope-only cell measured `> 30 s`. The three forms were also run directly wherever the scope
alone finished.)

`WP_Meta_Query` gives every OR-ed marker key its own **un-predicated** `JOIN` on the meta table
(`ON post_id = ID`, the `meta_key` test sits in `WHERE`), so an order with `d` meta rows costs `~d^N`
row combinations. `N` is the number of registered carriers, negation or no negation. That is why
changing the *negation* form alone is worthless: (H) and (X-exclusion) inherit the scope's wall, and
the wall is the same one the unfiltered page already hits — see
[Density sensitivity](#density-sensitivity--the-wall-is-the-scope-not-only-the-negation).

**(X-incl) vs (H-full) is a tie on speed, so the source reading decides** (100 000 orders, page 1 +
total, both datastores, all six carrier mixes — tables below): HPOS 175–210 ms (X-incl) against
176–295 ms (H-full); CPT 226–284 ms against 188–258 ms. Neither is consistently faster. What separates
them is the gate and the surface (see [Source answers](#source-answers)):

- (X-incl) reaches **both datastores through arguments WooCommerce supports** (`post__in`), keeps
  `paginate` / `total` / `max_num_pages` correct by construction, and **keeps
  `ShippingOrdersQueryRowSemanticsTest` meaningful** if the id computation sits behind a seam that takes a
  plain spec (see the last section).
- (H-full) needs two different SQL hooks with different table names and aliases, must strip the
  `meta_query` on both paths, and **turns the row-semantics gate red** (it walks the `meta_query` tree,
  which would no longer contain the negation) — recovering it means executing SQL in the test.

**What (X-incl) costs, honestly:** the id list is the size of the RESULT, not of the page — 24 k–85 k
ids at 100 k orders (≈ 6 bytes/id in the SQL: 503 KB for 85 361 ids; PHP-side build ≤ 23 ms). It grows
linearly with the store. Extrapolated, not measured beyond 100 k: ~3 M carrier orders reach MariaDB's
16 MB default `max_allowed_packet`. Two hazards belong in the card: an **empty** id list fails **open**
on both datastores (see below), and an id query that ever gets slow is paid on every page load, not
once per filter change — cache nothing here without deciding invalidation.

## What was measured

### Setup

- **Rig DB** (the only one the brief allowed): `wp-env-woodev-plugin-framework-5fd870b7-mysql-1`,
  **MariaDB 12.3.3** (⚠ the rig is MariaDB, not MySQL 8 — optimizer behaviour for `NOT IN` / `NOT EXISTS`
  and derived-table IN-lists differs between them; the join-explosion finding does not depend on it, the
  small-number ordering between H-full and X-incl might). `innodb_buffer_pool_size` = 128 MB, i.e. **far
  below** the 100 k data set (~0.9 GB); timings are medians of 5 runs after one warm-up, `SQL_NO_CACHE`.
- A throwaway database `probe928` (created and dropped by the probe; **dropped** at the end, nothing
  else touched). The four tables' DDL was copied with `SHOW CREATE TABLE` from the real rig DB — see
  [`ddl.sql`](ddl.sql) — so keys are exactly WooCommerce's: `wp_posts` / `wp_postmeta` (CPT) and
  `wp_wc_orders` / `wp_wc_orders_meta` (HPOS), each populated identically.
- `max_statement_time = 30` on every probe connection. (MariaDB's spelling: `max_execution_time`, the
  brief's name, does not exist there.) `>30s` = the statement hit it; two strikes stop a cell.
- **Data.** 1 k / 10 k / 100 k orders; per order ~20 unrelated meta rows (keys and value lengths of a real
  order), 5 % of orders carry no carrier, the rest are spread evenly over `M` mapped + `B` bare
  carriers with **exactly one marker each** (the operator's rule, 26.09.2026). A mapped carrier's order:
  status absent 10 % / `GO`→in_transit 20 % / `DONE`→delivered 55 % / unmapped junk 15 % — a mature store,
  so "unknown" matches ~24 % and "not in_transit" ~75 % of orders. `M ∈ {2,4,6}`, `B ∈ {0,2}`.
- **The SQL is the real SQL.** The probe runs inside the wp-env `cli` container, feeds the framework's
  real `Orders_Query::build_args()` to the real datastores and captures the SQL with a filter that throws
  before execution (HPOS: `woocommerce_orders_table_query_sql` + `…_count_sql`; CPT: `posts_request` on
  `WC_Order_Data_Store_CPT::query()`), then runs it over its own mysqli connection against `probe928`.
  Nothing was executed against the rig's `wordpress` database and no order was seeded through WooCommerce.
  The framework in the container is byte-identical to this worktree (md5 of both `Orders_Query` and
  `Orders_Registry` files matched).
- **Cost of a page** = HPOS: main query + the separate `COUNT` query; CPT: the one
  `SQL_CALC_FOUND_ROWS` statement. (X) forms add the id query. PHP-side list handling is reported apart
  (below).

### The forms

| form | SQL shape |
|---|---|
| `current` | `Orders_Query::build_args()` as it is today: `3M + B` joins for the negative filters |
| `scope only` | no status filter — the aggregate page's own baseline: `N` joins |
| **H** `NOT IN` | scope `meta_query` kept; `AND id NOT IN (SELECT … disqualifying status …)` injected through `woocommerce_orders_table_query_clauses` (HPOS) / `posts_clauses` (CPT). `NOT EXISTS` (correlated) was run too: the same within noise everywhere (`h_notexists` rows) |
| **H-full** | scope removed from `meta_query`; `EXISTS (marker) AND NOT EXISTS (disqualifying status)` through the same hooks — **no join left** |
| **X-excl** | one flat id query (`DISTINCT id … WHERE (key,value) disqualifies`) → `exclude` (`post__not_in`); scope `meta_query` kept |
| **X-incl** | one id query (`marker present AND NOT EXISTS disqualifying status`) → `post__in`; scope `meta_query` **dropped** (the list already implies it) |

Every form was compared with its neighbours by the `found`/`total` it returned: **198 cells checked, 0
mismatches** (`agree` in `results/*.jsonl`; in cells where `current` timed out the reference is the first
form that finished). That is a row-COUNT check on single-marker data, not a per-row proof — see the last
section for what still needs one.

### Results — 100 000 orders (page 1 + total; `M:B` carriers; `*` = inferred: the scope alone already timed out at a smaller point)

#### HPOS · `delivery_status=unknown`

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 17.5s | >30s | 5.1s | 281.4 ms | 5.1s (n=71385) | 174.7 ms (n=23733) |
| 4:0 | >30s | >30s* | >30s* | 289.6 ms | >30s* | 174.5 ms (n=23773) |
| 6:0 | >30s | >30s* | >30s* | 295.4 ms | >30s* | 175.4 ms (n=23827) |
| 2:2 | >30s | >30s* | >30s* | 226.5 ms | >30s* | 180.7 ms (n=59206) |
| 4:2 | >30s | >30s* | >30s* | 247.9 ms | >30s* | 183.5 ms (n=47349) |
| 6:2 | >30s | >30s* | >30s* | 258.9 ms | >30s* | 190.4 ms (n=41237) |

#### HPOS · `delivery_status_not=in_transit`

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 17.5s | >30s | 14.4s | 209.6 ms | 14.5s (n=19263) | 210 ms (n=75855) |
| 4:0 | >30s | >30s* | >30s* | 198.8 ms | >30s* | 189.6 ms (n=76094) |
| 6:0 | >30s | >30s* | >30s* | 199.2 ms | >30s* | 192.2 ms (n=75743) |
| 2:2 | >30s | >30s* | >30s* | 176 ms | >30s* | 186.8 ms (n=85361) |
| 4:2 | >30s | >30s* | >30s* | 187.7 ms | >30s* | 189.1 ms (n=82256) |
| 6:2 | >30s | >30s* | >30s* | 187.9 ms | >30s* | 189.5 ms (n=80491) |

#### CPT · `delivery_status=unknown`

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 26.8s | >30s | 7.6s | 217.9 ms | 8.1s (n=71385) | 279.8 ms (n=23733) |
| 4:0 | >30s | >30s* | >30s* | 199.7 ms | >30s* | 246.5 ms (n=23773) |
| 6:0 | >30s | >30s* | >30s* | 201.8 ms | >30s* | 252.3 ms (n=23827) |
| 2:2 | >30s | >30s* | >30s* | 187.9 ms | >30s* | 226.3 ms (n=59206) |
| 4:2 | >30s | >30s* | >30s* | 193.9 ms | >30s* | 239.5 ms (n=47349) |
| 6:2 | >30s | >30s* | >30s* | 199.1 ms | >30s* | 239.1 ms (n=41237) |

#### CPT · `delivery_status_not=in_transit`

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 26.8s | >30s | 22.5s | 258 ms | 21.7s (n=19263) | 280.9 ms (n=75855) |
| 4:0 | >30s | >30s* | >30s* | 223 ms | >30s* | 270.3 ms (n=76094) |
| 6:0 | >30s | >30s* | >30s* | 233.2 ms | >30s* | 283.7 ms (n=75743) |
| 2:2 | >30s | >30s* | >30s* | 194.6 ms | >30s* | 240.2 ms (n=85361) |
| 4:2 | >30s | >30s* | >30s* | 208.6 ms | >30s* | 250.6 ms (n=82256) |
| 6:2 | >30s | >30s* | >30s* | 215 ms | >30s* | 257.5 ms (n=80491) |

`n=` is the literal id-list length of the (X) forms. `H NOT IN` at 2:0 is the one point where a form that
keeps the scope joins survives 100 k orders (5–22 s); it is a full order of magnitude behind the
join-free forms and dies at `N ≥ 4`.

### Results — 10 000 and 1 000 orders (`unknown`; the other rows are in `results/`)

#### HPOS · 10 000 orders

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 1.6s | >30s | 464.5 ms | 24.4 ms | 465.8 ms (n=7147) | 14.7 ms (n=2348) |
| 4:0 | >30s | >30s* | >30s* | 25.1 ms | >30s* | 14.8 ms (n=2414) |
| 6:0 | >30s | >30s* | >30s* | 26.3 ms | >30s* | 15.2 ms (n=2376) |
| 2:2 | >30s | >30s* | >30s* | 19.6 ms | >30s* | 16.3 ms (n=5929) |
| 4:2 | >30s | >30s* | >30s* | 21.6 ms | >30s* | 16.4 ms (n=4761) |
| 6:2 | >30s | >30s* | >30s* | 23.1 ms | >30s* | 16.5 ms (n=4139) |

#### CPT · 10 000 orders

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 2.2s | >30s | 599.1 ms | 16.8 ms | 599.8 ms (n=7147) | 21.7 ms (n=2348) |
| 4:0 | >30s | >30s* | >30s* | 17.1 ms | >30s* | 21.9 ms (n=2414) |
| 6:0 | >30s | >30s* | >30s* | 17.4 ms | >30s* | 22.1 ms (n=2376) |
| 2:2 | >30s | >30s* | >30s* | 16.7 ms | >30s* | 20.5 ms (n=5929) |
| 4:2 | >30s | >30s* | >30s* | 16.7 ms | >30s* | 21.2 ms (n=4761) |
| 6:2 | >30s | >30s* | >30s* | 16.9 ms | >30s* | 21.5 ms (n=4139) |

#### HPOS · 1 000 orders

| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |
|---|---|---|---|---|---|---|
| 2:0 | 167.4 ms | 7.4s | 55.6 ms | 2.5 ms | 56.5 ms (n=691) | 2.1 ms (n=256) |
| 4:0 | >30s | >30s* | >30s* | 2.8 ms | >30s* | 2.2 ms (n=239) |
| 6:0 | >30s | >30s* | >30s* | 2.8 ms | >30s* | 2.2 ms (n=259) |
| 2:2 | >30s | >30s* | >30s* | 2.1 ms | >30s* | 3 ms (n=608) |
| 4:2 | >30s | >30s* | >30s* | 2.7 ms | >30s* | 2.9 ms (n=488) |
| 6:2 | >30s | >30s* | >30s* | 2.5 ms | >30s* | 2.7 ms (n=454) |

At 1 000 orders even the two-carrier `current` shape costs 7.4 s (HPOS) / 7.8 s (CPT): it is not a
"big store" problem, it is a density × carriers problem.

### Density sensitivity — the wall is the scope, not only the negation

The brief asked for ~20 unrelated meta rows per order. The rig itself holds ~6.4 rows per order in
`wp_wc_orders_meta`, so the same `scope only` cell was re-run at lower density (10 000 orders,
HPOS, `M4:B0` = `N = 4`; `UNRELATED` env var of `run.php`):

| unrelated meta rows / order | scope only, `N = 4` | scope only, `N = 8` |
|---|---|---|
| 5 (≈ the rig's own density) | **11.7 s** | > 30 s |
| 10 | > 30 s | > 30 s |
| 20 (this note's default) | > 30 s | > 30 s |

So at a density like the dev rig's, **the unfiltered aggregate page with four carriers already needs ~12 s
per 10 k orders** — the s128 hang was not (only) the negative filter. Whatever is built for #928 should
be judged by whether it removes the scope joins, because a negation form that keeps them fixes nothing
past `N = 3`. The join-free forms did not move (H-full 16–24 ms, X-incl 14–21 ms at 10 k at every
density; `results/r3-*.jsonl`).

### PHP-side cost of the literal list

`build_ms` in the rows = time to build the real query (`wc_get_orders` / `WP_Query`) with the list, up
to the aborting filter: **≤ 23 ms** for the largest list measured (85 361 ids, 503 KB of SQL), against
0.1–4.5 ms for the list-free forms. Linear; not a deciding factor below ~10⁶ ids.

### EXPLAIN highlights (100 000 orders, `M4:B2`, `unknown`)

- `scope only` — `wp_wc_orders` full-index scan + **six** `ref` joins on `wp_wc_orders_meta`, `Using
  temporary; Using filesort`, ~21 rows per join step. CPT identical with `wp_postmeta`.
- `H-full` / `X-incl` main query — `date_created` index scan stopping at `LIMIT 20`, the IN-list /
  `EXISTS` resolved as an `eq_ref` semi-join (`<subquery2>` / `<derived3>`), **no temporary table**.
  The `COUNT` query is an index-only scan of `type_status_date`. The id query is two `range` scans on
  `meta_key_value` (HPOS) / `meta_key` (CPT) — 181 k and 78 k candidate rows.
- On CPT the join-free forms scan `wp_posts` (`type: ALL`, filesort) — `WP_Query` orders by
  `post_date` and the `type_status_date` index is not chosen — which is why the CPT main query is
  ~100 ms at 100 k where the HPOS one, walking `date_created`, is ~10–35 ms.

## Source answers

Rig sources: `plugins/woocommerce.latest-stable` = WooCommerce **11.1.0**; WordPress core from the rig's
`WordPress/` (paths below are relative to those two roots).

### Does (X) reach both datastores through the supported surface, with the right pagination and count?

**Yes — for the inclusion form, with one hazard each way.**

- **CPT.** `WC_Data_Store_WP::get_wp_query_args()` maps `exclude` → `post__not_in`
  (`includes/data-stores/class-wc-data-store-wp.php:314`); every other key, `post__in` included, is
  passed through unmapped (`:320-322`). `WP_Query` renders them as `ID IN (…)` / `ID NOT IN (…)`
  (`wp-includes/class-wp-query.php:2248-2258`).
  ⚠ Those are an **`elseif` chain** — `post__in` wins and `post__not_in` is silently ignored if both are
  present, so the two lists cannot be combined on CPT.
  Pagination: `WC_Order_Data_Store_CPT::query()` returns `total => found_posts` and `max_num_pages` when
  `paginate` is set (`includes/data-stores/class-wc-order-data-store-cpt.php:1122-1127`; `no_found_rows`
  otherwise, `:1051-1053`); `found_posts` comes from `SQL_CALC_FOUND_ROWS` over the same `WHERE`, which
  contains the id list — measured: `found` identical across forms in every cell.
- **HPOS.** `OrdersTableQuery::maybe_remap_args()` maps `post__in` → `id` and `post__not_in` →
  `exclude` (`src/Internal/DataStores/Orders/OrdersTableQuery.php:275,278`); `exclude` becomes
  `id NOT IN (…)` via `where()` (`:1176-1178`, array shorthand `:1108-1112`) and `id` an `IN` the same
  way. The count query is built from the very same `$where` (`build_count_query`, `:960-967`) and run
  at `:1466-1470` (`max_num_pages = ceil(found / limit)`), so `total` and `max_num_pages` follow the list.
  Captured SQL: [`sql/hpos-unknown-x_incl-M2B0.sql`](sql/hpos-unknown-x_incl-M2B0.sql).
- **The aggregate count the page shows** is `Orders_Query::get_results(…)->total`
  (`woodev/shipping-method/admin/orders/class-orders-registry.php:971-987`; the docblock at
  `:1032-1042` states the contract). The menu badge uses `is_exported=false`, not a negative status
  filter, so it is untouched; the page's own total under `delivery_status_not` flows through the same
  `->total`, which is what the `found` comparison above exercised on both datastores.
- ⚠ **Empty list fails OPEN on both datastores.** HPOS treats `[]` as "argument not set"
  (`OrdersTableQuery.php:27` `SKIPPED_VALUES`, `:1443-1445` `arg_isset`); `WP_Query` tests
  `elseif ( $query_vars['post__in'] )` (`class-wp-query.php:2248`), falsy for `[]`. An empty
  "orders that pass" set would therefore return **every order**. It must be routed through the existing
  "matches nothing" mechanism, `Orders_Query::NO_MATCH_META_QUERY` (`class-orders-query.php:74`, use
  described at `:240`), never passed as `post__in => []`. (The probe skips such cells and says so.)

### Does (H) keep `ShippingOrdersQueryRowSemanticsTest` meaningful?

**No.** The gate obtains the `meta_query` through `built_tree()` (`tests/unit/ShippingOrdersQueryRowSemanticsTest.php:144`)
and decides row membership by walking it in `matches_query()` (`:226`), comparing with the independent
`oracle_matches()` (`:297`). Under (H) the negation lives in a SQL fragment injected by a hook, so the tree
holds only the scope: every negative case would go **red** (the oracle says the order is excluded, the
tree says it matches). The only ways back to green are to widen the tree with something the walker
cannot evaluate (blind) or to execute the SQL — a synthetic table, which the repo does not have in the
unit tier (#919 Q2).

### What would (X) need so the oracle still proves equivalence?

A seam, shaped like the existing `is_hpos_enabled()` one: let `Orders_Query` build a **plain spec** —
the registered marker keys and, per status key, the raw values that disqualify — and resolve it to ids
through one `protected` method (`resolve_passing_order_ids( spec )`) that the production class
implements with the id query. The unit test then overrides that method with an in-memory resolver over
the enumerated `universe()` rows and asserts: **the id set the query would pass to `post__in` equals the
oracle set** for every row, fixture and datastore, exactly as it does for the `meta_query` today. That
keeps the proof at unit speed and makes drift in the *spec* (a new provider branch) fail the gate; the
`empty ⇒ NO_MATCH` rule is testable there too.

What the unit tier still cannot prove is the **SQL text of the id query itself** (two hand-written
statements, one per datastore). That is what this directory's probe did at DB level — 198 count
comparisons, no mismatch — and it is not yet a re-runnable test; the honest follow-up is one small
seeded case in the integration suite covering both datastores. Per the operator's 26.09.2026 ruling the
oracle universe is narrowed to single-marker orders at the same time; the multi-marker rows of
#919 stay red until it is.

## Caveats

- MariaDB 12.3 with a 128 MB buffer pool on an OrbStack VM; not MySQL 8, not a tuned production host.
- Synthetic data: one marker per order, a fixed mature-store status mix, `wp_posts` rows with empty
  content. `d^N` ordering is robust to all of that; absolute milliseconds are not portable.
- Beyond 100 k orders is extrapolation. `>30s` cells marked `*` were not executed: the scope-only cell of
  the same or a smaller point had already timed out, and every such form contains that scope.
- Only the `unknown` and `not in_transit` filters were swept at every point; `not_delivered` is in
  `filter_spec()` but was not run (the two above already span "few excluded" and "most excluded").
- H forms measured with the guard `AND order_id IS NOT NULL` inside `NOT IN` on HPOS (its column is
  nullable — an unguarded `NOT IN` against a NULL yields no rows).

## How to run it

```bash
C=wp-env-woodev-plugin-framework-5fd870b7-cli-1
docker cp docs-internal/research/2026-09-26-928-form-measurement $C:/tmp/f928
docker exec -e SIZES=1000,10000 -e CONFIGS=2:0,4:2 -e OUT=/tmp/f928/r.jsonl $C wp eval-file /tmp/f928/run.php
docker cp $C:/tmp/f928/r.jsonl /tmp/r.jsonl && php docs-internal/research/2026-09-26-928-form-measurement/summarize.php /tmp/r.jsonl
# afterwards, always:
docker exec wp-env-woodev-plugin-framework-5fd870b7-mysql-1 mariadb -uroot -ppassword -e 'DROP DATABASE probe928'
```

Parameters (`SIZES`, `CONFIGS`, `FILTERS`, `STORES`, `FORMS`, `RUNS`, `UNRELATED`, `SEED`) are documented at the
top of [`run.php`](run.php). It writes only to `probe928` and needs the rig's `cli` and `mysql` containers.

## Related

- [../2026-09-26-919-subquery-seam/README.md](../2026-09-26-919-subquery-seam/README.md) — why the single global set subtraction is only valid under the one-marker rule (#919 Q4), and the "Q3" precompute this note measures
- [../2026-09-25-839-join-growth-evidence/README.md](../2026-09-25-839-join-growth-evidence/README.md) — the `4M + 2B → 3M + B` join law and the s128 wall this note re-anchors
- [../../gotchas/on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases.md](../../gotchas/on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases.md) — why the two datastores cost one tree differently
- [../../gotchas/two-concurrent-integration-runs-share-one-test-database.md](../../gotchas/two-concurrent-integration-runs-share-one-test-database.md) — why every measurement here used a throwaway database
- [../../../tests/unit/ShippingOrdersQueryRowSemanticsTest.php](../../../tests/unit/ShippingOrdersQueryRowSemanticsTest.php) — the gate discussed above
