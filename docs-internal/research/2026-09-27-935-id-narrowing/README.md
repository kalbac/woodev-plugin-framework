# #935 — narrowing the resolver's id query and cutting the N+2 calls, decided by measurement

> Session 141 (27.09.2026), macOS laptop. Research only — **no framework source was changed** and no real
> database was written to. Produced by a Sonnet 5 worker for card #935 on top of PR #937 (the resolver has the
> order-status leaf; `OrdersIdResolverDatastoresTest` exists). Every number is from a replayable probe in this
> directory ([`lib.php`](lib.php), [`run.php`](run.php), [`summarize.php`](summarize.php); raw rows in
> [`results/`](results/)). The harness is a fork of the #928 one
> ([`../2026-09-26-928-form-measurement/`](../2026-09-26-928-form-measurement/README.md)).

## Decision

**Threshold used.** A candidate is built only if, on one page load (rows + N carrier counts + scope count) at the
brief's **10 k-order** scale, it is **≥ 2× faster and ≥ 50 ms faster** in the views it targets on **both**
datastores, **and** no measured view — the unfiltered default included — gets more than 10 % slower. 50 ms is
where a change stops being invisible on a REST call that also hydrates 20 orders in PHP; the no-regression
clause is there because the unfiltered view is the one every visit pays. 100 k orders is the *does-it-scale*
check, not the bar. The threshold was fixed with the 10 k table in hand — it is a judgement call, and the
100 k numbers are here so it can be moved: candidate A' (narrow period only, behind a probe) **would** clear a
100 k bar for the period view (−440 ms HPOS, −260 ms CPT) and still fail the no-regression clause without the probe.

**Neither candidate is built.** The default, unfiltered view — the one every visit pays for — is improved by
neither, and the one view candidate A does speed up (a narrow period) needs a row-count probe to avoid regressing
wider periods, which is more machinery than a card that was filed as "not a blocker" earns.

| candidate | verdict | why (numbers below) |
|---|---|---|
| **A** push order status + `date_created` into the id query | **not built** | Unconditional push makes the default view **slower** (+26 % HPOS / +16 % CPT at 100 k) and any period wider than a few % of the store slower too. Status alone buys nothing. Only a *narrow period* wins (458 → 22 ms HPOS, 324 → ~67 ms CPT at 100 k) — and only with a probe that falls back to today's form when the window is wide. |
| **B** carrier counts as ONE `GROUP BY` | **not built** | Unfiltered, HPOS at 100 k: one statement costs 287–315 ms against ≈ 190 ms for the N calls it replaces (**slower**), and at 10 k it is a tie-to-slower (19.6–22.9 vs ≈ 18 ms). CPT at 100 k: 226–232 ms against ≈ 463 ms (**−230 ms of a 899 ms page load, ≈ 1.35× on the page**; 2.0× on the count part alone, but ≈ 25 ms at 10 k). It would need WooCommerce's exact status / date semantics re-implemented inside a hand-written count, and cannot serve a search. (In a 1-week period on HPOS it wins, ≈ 127 → 3.6 ms — the same narrow-window niche as A'; on CPT it is *slower*, 181–191 vs ≈ 104 ms.) |

Candidate A' at the 10 k scale: 41.7 → 4.5 ms (HPOS, 9×, −37 ms) and 31 → 11 ms (CPT, 3×, −20 ms) per page
load, for a narrow-period request only — under the 50 ms bar on both.

## What was measured

### Setup

- Rig DB `wp-env-…-mysql-1`, **MariaDB 12.3.3**, `innodb_buffer_pool_size` 128 MB (far below the 100 k data set).
  A throwaway database `probe935` (DDL copied from the real rig tables with `SHOW CREATE TABLE`, read-only) — **dropped
  at the end**; the rig's `wordpress` database was never written. Eight tables: the four of #928 plus
  `wc_order_addresses`, `wc_order_operational_data`, `woocommerce_order_items(meta)` so WooCommerce's HPOS search SQL runs.
- **10 000 orders × 4 mapped carriers (`M4:B0`)** in both datastores, ~20 unrelated meta rows per order, one marker
  per order, 5 % of orders carry none; mature-store status mix; created-dates spread evenly over 2023–2025. A second
  pass at **100 000 orders**. Timings are medians of 5 (10 k) / 3 (100 k) runs after one warm-up, `SQL_NO_CACHE`.
- **The SQL is the real SQL.** The probe subclasses the real `Orders_Query`, points its `resolve_order_ids()` seam
  at the probe DB (the id SQL is compiled by the real `Orders_Id_Resolver::compile()`), feeds the real `build_args()` to
  the real datastores and captures the SQL with a filter that aborts before execution
  (HPOS `woocommerce_orders_table_query_sql`/`_count_sql`; CPT `posts_request`), then runs it over its own mysqli
  connection. Cost of a request = id query + main query (+ the separate `COUNT` on HPOS; CPT's `SQL_CALC_FOUND_ROWS`
  is one statement).
- **The N+2 sequences are replicated from the callers**: `Orders_Controller::build_carrier_counts()` /
  `build_scope_counts()` (`rest-api/class-orders-controller.php` ~527–616: rows + N carriers with `per_page=1` + the
  «Новые» scope count; «Все» reuses the page's total) and `Orders_Registry::query_new_order_counts()`
  (`class-orders-registry.php` ~1039–1053: aggregate + N). With N = 4 that is **6 resolver calls per page load, 5 for the badge**.
- **Equivalence in every cell.** For each scenario the `found` total and an md5 of the first page's ids from the
  narrowed forms are compared with today's, and so are the six `found` values of a whole page load: **0 disagreements
  across the five result files** (`agree` in `results/*.jsonl`). Candidate B's per-carrier counts equal the N calls' `found` in every cell.

### The forms

| form | id-query change |
|---|---|
| `today` | `Orders_Id_Resolver::compile()` unchanged: driver `mk.meta_key IN (markers)` + one correlated `EXISTS` per leaf |
| `a_exists` | `AND EXISTS (SELECT 1 FROM orders o WHERE o.id = mk.order_id AND <status list> AND <date window>)` |
| `a_join` | `JOIN orders o ON …` from the driver |
| `a_in` | `AND mk.order_id IN (SELECT o.id FROM orders o WHERE <type> AND <status list> AND <date window>)` — a semi-join the optimizer costs itself |
| `a_drive` | the order table **drives**: `orders o STRAIGHT_JOIN meta mk`, type pinned |
| `*_ns` | the same without the status list (date window only) |
| **B** `b_exists` / `b_join` | one `SELECT mk.meta_key, COUNT(DISTINCT order_id) … GROUP BY mk.meta_key WITH ROLLUP`, every carrier's compiled tree OR-ed, status/date exact |

The date window is the request's `after`/`before` **widened by one day each side** — a superset on purpose
(the site timezone is < 1 day off the GMT column), so the main query, which still applies WooCommerce's exact
`date_created`, returns the same page. Status is the main query's own native list (default: every status but
cancelled/failed).

### Today: what one page load costs

| | id query (6 calls) | main + count | **page load** | one request | badge |
|---|---|---|---|---|---|
| HPOS 10 k | 18 ms | 32 ms | **50 ms** | 16 ms | 34 ms |
| CPT 10 k | 19 ms | 64 ms | **83 ms** | 19 ms | 65 ms |
| HPOS 100 k | 213 ms (37 %) | 370 ms | **583 ms** | 199 ms | 386 ms |
| CPT 100 k | 211 ms (23 %) | 718 ms | **929 ms** | 228 ms | 711 ms |

The id query is linear (~7 ms per 10 k orders per call) but it is **not the dominant term**: the N+2 main/count
queries, each carrying the ~95 k-id list, are 63 % (HPOS) to 77 % (CPT) of the load. The list is 46 KB of SQL at
10 k, 0.5 MB at 100 k; PHP-side build ≤ 5 ms at 10 k.

### Candidate A — one page load, ms (the metric that decides)

At 10 k orders:

| scenario | HPOS today | HPOS `a_exists` | HPOS `a_drive` | CPT today | CPT `a_exists` | CPT `a_drive` |
|---|---|---|---|---|---|---|
| no filter | 50 | **63** | 166 | 83 | **90** | 299 |
| status (15 % of orders) | 44 | 35 | 29 | 45 | 37 | 53 |
| status (5 %) | 44 | 12 | 12 | 35 | 33 | 24 |
| period 1 week | 42 | 4.5 | 4.8 | 31 | 32 | **11** |
| period 30 days | 42 | 8.2 | 8.7 | 33 | 34 | 16 |
| status + 1 week | 41 | 1.6 | 1.5 | 31 | 29 | 3.2 |
| search (narrow) | 85 | 94 | 198 | 45 | 59 | 423 |

At 100 k orders:

| scenario | HPOS today | HPOS `a_exists` | HPOS `a_drive` | CPT today | CPT `a_exists` | CPT `a_drive` |
|---|---|---|---|---|---|---|
| no filter | 583 | **735** | 2 560 | 929 | **1 080** | 9 060 |
| status (15 %) | 477 | 502 | 515 | 525 | 428 | 1 600 |
| period 1 week | 458 | 22 | 21 | 324 | 332 | **67** |
| status + 1 week | 443 | 7 | 5 | 331 | 326 | 9 |
| search (narrow) | 934 | 1 060 | 2 960 | 462 | 617 | 8 650 |

Read-outs:

- **No filter is not narrowed by anything useful** — the only thing to push is the default status list, and pushing it
  costs more than it saves (+26 % / +16 % at 100 k). Forcing the order table to drive (`a_drive`) is a full scan: ×4 / ×10.
- **Status alone does not pay**: on HPOS the id query gets *slower* (205 → 439 ms at 100 k) by about what the main
  and count queries win back (−210 ms); CPT −18 % at best.
- **Search is untouched** by design (it stays in the main query) and is what the pushed forms pay extra on.
- **A period wins big — for a narrow window.** On CPT the plain `EXISTS` form does **not** use the date (the optimizer
  keeps the meta driver and probes `posts` by primary key): 324 → 332 ms; only a form that lets the order table's
  `type_status_date` index work (`a_in` / `a_drive` **with the status list present**) gets 324 → 67 ms. Without the
  status list even `a_in` stays at 118 ms for one week (`a_in_ns`).

### Where the narrowing stops paying — window width (100 k, one request, ms; id-query ids in brackets)

| window (orders in it) | HPOS today | HPOS `a_in` | HPOS `a_drive` | CPT today | CPT `a_in` | CPT `a_drive` |
|---|---|---|---|---|---|---|
| 1 week (736) | 159 | **6.7** | 7.2 | 112 | **11** | 11 |
| 30 days (2 641) | 155 | **16** | 16 | 110 | **43** | 39 |
| 6 months (15 097) | 158 | 134 | **94** | 140 | 145 | 250 |
| 1 year (30 200) | 171 | 152 | 197 | 155 | **205** | 469 |
| everything (90 146) | 188 | **255** | 764 | 209 | **268** | 1 591 |

Break-even sits between ~2.6 k and ~15 k orders in the window — roughly **3–15 % of the store** — and past it the
pushed form is up to +36 % (HPOS) / +32 % (CPT) slower than not narrowing. A safe A' therefore needs a cheap probe
(`SELECT COUNT(*) FROM (SELECT 1 … LIMIT k) t` on the same index range) to choose a form per call; the rule
"narrow only for windows shorter than X days" cannot work, because orders per day is the store's, not ours.

### Candidate B — carrier counts in one statement

Raw rows: `results/r1-10k.jsonl` (10 k) and `results/r4-100k-b.jsonl` (100 k). **The 100 k rows were re-measured in the
fix round (27.09.2026) — the first pass' B timings were never committed to `results/`; the numbers below are read
straight from `r4-100k-b.jsonl` (`php summarize.php results/r4-100k-b.jsonl` prints them, derived column included)
and replace the earlier prose figures (294 / 268 / 3.8 / 234 ms).** `b_exists` / `b_join` are the two SQL shapes of
the same statement.

| | one `GROUP BY` (`b_exists` / `b_join`) | the N calls it replaces (derived) | verdict |
|---|---|---|---|
| HPOS 10 k, no filter | 19.6 / 22.9 ms | ≈ 17.6 ms | tie to slightly slower |
| CPT 10 k, no filter | 19.8 / 19.6 ms | ≈ 44.9 ms | −25 ms (2.3×) — under the 50 ms bar |
| HPOS 100 k, no filter | **314.6 / 287.3 ms** | ≈ 189.5 ms | **slower** (+52–66 %) |
| CPT 100 k, no filter | 231.6 / 225.7 ms | ≈ 463.4 ms | −232 / −238 ms of the 899 ms page load (−26 %; the count part alone 2.0×) |
| HPOS 100 k, 1-week period | 3.6 / 3.6 ms | ≈ 127.0 ms | wins — a narrow window only, the same story as A' |
| CPT 100 k, 1-week period | 191.2 / 181.2 ms | ≈ 103.8 ms | **slower** (the date is not used on `posts`) |

*"The N calls" is derived, not measured directly:* the page-load total minus the page's own request minus the
«Новые» scope request (≈ the page's cost; the N carrier requests carry ~¼ of the ids each), taken from the `today`
rows of the same result file. The 100 k baseline of the B comparison is therefore that file's own `today` run
(HPOS page load 553 ms, CPT 899 ms) — a fresh seed, so it differs by a few % from the 583 / 929 ms of
`r2-100k.jsonl` used in the tables above. The conclusion does not depend on the derivation: on CPT the whole gap
(≈ 235 ms) takes the page load from 899 to ≈ 665 ms, ≈ 1.35× — under 2× — and HPOS gets worse. B's counts equal the
N calls' in every cell (`agree` in the file), on the rig's UTC timezone; making that hold for every site timezone,
every status and every carrier tree means re-implementing WooCommerce's date/status semantics inside the count, and
the count still cannot include a search. That is the cost side; the gain side is above.

## Independent cross-check

A hand-written SQL run straight through the `mariadb` client — not through the harness — on the 100 k HPOS
data set: today's id query returns **94 890** ids (86 ms, one cold run); the same query narrowed to the week
2024-07-01…07 (window ±1 day, default status list) returns **736** ids in 6.1 ms; today's 94 890 restricted to
the same predicate is **736** — an exact subset relation (the list only shrinks). The main query then keeps
**569** of them, the same 569 as the exact-day-bound count and as `found` of every form in the harness. (Same
author as the harness; not a critic's re-run.)

## Caveats

- MariaDB 12.3 with a 128 MB buffer pool, not MySQL 8; the semi-join / driver choices behind `a_in` vs `a_exists`
  are optimizer-specific and the break-even would move.
- Synthetic data: created-dates uniform over three years, one marker per order, empty order-item tables (so HPOS
  search's item-name subquery is cheaper than on a real store), `wp_posts` holds only orders (on a real CPT store
  `post_type = 'shop_order'` matters more, which favours `a_drive`-style forms and hurts today's).
- Beyond 100 k is extrapolation; B's "derived" column is derived (see above).
- Only the UTC site timezone was exercised for exactness; the ±1-day superset window is what makes A' safe in other zones.

## What would move the decision

- A card for a store class where a narrow period is the *normal* view (an archive workflow): then build A' — date
  window only, status list riding along for the CPT index, a bounded-`COUNT` probe choosing the form, and the
  equivalence gate extended with period as a request axis.
- A real store measurement showing the id query (not main/count) dominates — here it is 23–37 % of the load.

## How to run it

```bash
C=$(scripts/machine/rig-container.sh cli)
docker cp docs-internal/research/2026-09-27-935-id-narrowing $C:/tmp/f935
docker exec -e SIZE=10000 -e OUT=/tmp/f935/r.jsonl $C wp eval-file /tmp/f935/run.php
docker cp $C:/tmp/f935/r.jsonl /tmp/r935.jsonl && php docs-internal/research/2026-09-27-935-id-narrowing/summarize.php /tmp/r935.jsonl
# afterwards, always:
docker exec "$(scripts/machine/rig-container.sh mysql)" mariadb -uroot -ppassword -e 'DROP DATABASE probe935'
```

Parameters (`SIZE`, `CONFIG`, `STORES`, `MODES`, `SCENARIOS`, `RUNS`, `UNRELATED`, `SEED`, `PAGELOAD`, `CANDIDATES`)
are documented at the top of [`run.php`](run.php). It writes only to `probe935`. Result files: `r1-10k` (all
scenarios, `today`/`a_exists`/`a_join`, B), `r1b-10k-drive` (`a_drive`), `r2-100k` (main 100 k pass),
`r3-100k-windows` (window-width sweep, request rows only), `r4-100k-b` (candidate B at 100 k, unfiltered and 1-week, both
datastores — `SIZE=100000 RUNS=3 MODES=today SCENARIOS=none,period_1w CANDIDATES=B`; also carries that run's `today` rows).

## Related

- [../2026-09-26-928-form-measurement/README.md](../2026-09-26-928-form-measurement/README.md) — the harness this forks, and why the id list exists at all
- [../../gotchas/an-or-of-exists-meta-clauses-joins-the-meta-table-once-per-key-unpredicated.md](../../gotchas/an-or-of-exists-meta-clauses-joins-the-meta-table-once-per-key-unpredicated.md) — the wall the resolver removed
- [../../gotchas/narrowing-the-id-query-by-a-date-window-only-pays-below-a-few-percent-of-the-store.md](../../gotchas/narrowing-the-id-query-by-a-date-window-only-pays-below-a-few-percent-of-the-store.md) — the lesson from this measurement
- [../../../woodev/shipping-method/admin/orders/class-orders-id-resolver.php](../../../woodev/shipping-method/admin/orders/class-orders-id-resolver.php) — the class under discussion
