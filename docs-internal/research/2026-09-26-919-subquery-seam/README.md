# #919 — can the delivery-status filter's `3N` joins collapse into a subquery, and what would prove it?

> Design-only task (s139/s140 worker, 26.09.2026). No production code was touched — this note answers
> #919's four questions from measurement, not reasoning, per the card's own requirement. Probe script:
> [`probe.php`](probe.php) (replayable; see **How to run it** below).

## Recommendation

**Do not build #919 as stated.** Its hypothesis — "compute the set of orders whose own carrier's
status is mapped and exclude it with one subtraction" — is not row-semantics-equivalent to the current
per-provider `OR`, independent of *how* it is wired (SQL-level subquery, or the `exclude`/`post__not_in`
arg): both realizations collapse an **existential** predicate ("at least one of this order's present
providers reports unknown/not-X") into a **single global set membership test**, and those two are only
the same when an order carries at most one registered carrier's marker. Enumeration
([Q4](#q4-does-the-structural-redundancy-argument-survive)) finds real mismatches — including ones
that are not exotic combinations the enumeration merely happens to cover, but the documented,
supported case of **split-shipment orders carrying more than one carrier's marker**
(`class-checkout-field-policy.php:590-593`; `Orders_Registry::resolve_provider_for_order()` already
silently picks "whichever registered provider's marker it finds first" rather than asserting
uniqueness, which is itself a sign multi-marker orders are anticipated, not excluded by design).
**The one thing that would change this**: proof that no order can ever carry more than one registered
carrier's marker in this system (a business-rule guarantee, not a code fact) — see
[What was not settled](#what-was-not-settled).

Card #919 should be **closed as "not viable as specified"** and re-scoped, if there is still appetite
for it, around a form that preserves the existential per-provider structure (see
[What would settle it](#what-was-not-settled)) — or simply left alone: the *current* s139 shape
(`3M + B` joins) is not the wall #839 found. The wall was `4M + 2B` at `M = 4` on the legacy CPT
planner; `3M + B` at realistic carrier counts (the fixture in #839's own README: 6 mapped carriers →
18 joins) has not been shown to hit it, and #839's own field measurement (2 real carriers, HPOS, 71
orders) put the negative filter at 106 ms — slow relative to its siblings, not wedged.

## Q1 — can the exclusion be expressed through the existing query-args surface at all?

**No subquery syntax exists anywhere in the surface either datastore's `meta_query` (or HPOS's
`field_query`) reaches**, confirmed by reading the actual clause builders, not recalled API shape:

- `WP_Meta_Query::get_sql_for_clause()` builds `IN`/`NOT IN` by exploding `value` into one `%s`
  placeholder per element and running it through `$wpdb->prepare()`
  (`wp-includes/class-wp-meta-query.php:705-708` for the `key`-shaped NOT-IN-on-keys case, `:733-746`
  for the ordinary value-based case). There is no clause shape that accepts a raw SQL fragment as
  `value` — whatever is passed is quoted as a literal.
- HPOS's `field_query` (`OrdersTableFieldQuery.php`) does exactly the same thing for its own `IN`/
  `NOT IN` (`:286-298`, same `$wpdb->prepare()` per-element pattern), and additionally: every atomic
  clause must resolve to a known **order-table column** through
  `OrdersTableQuery::get_field_mapping_info()` (`:130-134`) — it cannot reference `wp_postmeta` at
  all, so it is unusable for a meta-based predicate regardless of the subquery question, and it has no
  CPT equivalent, which the card's own constraint (both datastores) rules out on its own.

So `meta_query`/`field_query` are dead ends for a literal `NOT IN (SELECT …)`, exactly as the task
brief's trap anticipated.

**One arg on the existing surface DOES reach a set-exclusion, but only against a literal PHP array,
never a SQL subquery**: `exclude` (mapped from WooCommerce's generic `WC_Object_Query`).
- CPT: `WC_Data_Store_WP::get_wp_query_args()` maps `exclude` → `post__not_in`
  (`class-wc-data-store-wp.php:314`), a native `WP_Query` arg.
- HPOS: `OrdersTableQuery::process_orders_table_query_args()` handles `exclude` directly, and its
  `where()` helper turns an **array** value into `id NOT IN (...)` (shorthand at
  `OrdersTableQuery.php:1108-1112`, applied at `:1176-1178`).

Both accept an array of literal order ids — never a subquery — so using `exclude` means running a
**separate, earlier query** to materialize that id set in PHP, not embedding one subquery inside the
main query. This is a real, reachable mechanism (measured cheap in Q3), but it is a *different* shape
than the card asked for, and — as Q4 shows — it does not fix the underlying semantic problem, because
that problem is not about *how* the excluded set reaches the query; it is about *what set* "exclude"
can express at all (a single global set, not a per-provider existential).

A genuine single-query `NOT IN (SELECT …)` is reachable **only** through a raw SQL hook —
`woocommerce_orders_table_query_clauses` on HPOS, `posts_clauses`/`posts_where` (built on `WP_Query`)
on CPT — exactly the trap named in the task brief.

## Q2 — if it needs a SQL hook, what instrument proves equivalence?

Moot for the literal hypothesis, since Q4 shows the semantics are wrong regardless of mechanism — but
answered for completeness, because a future, *corrected* form of this idea might still need a SQL
hook:

- **The existing gate (`tests/unit/ShippingOrdersQueryRowSemanticsTest.php`) cannot see a raw SQL
  fragment at all.** It walks the `meta_query` array (`built_tree()` at
  `ShippingOrdersQueryRowSemanticsTest.php:144-154`, `matches_query()` at `:226-250`) — a filter
  applied through `posts_where`/`woocommerce_orders_table_query_clauses` returns a *string*, not a
  tree, so this file's `foreach` over `$tree` would iterate nothing meaningful. The gate would go
  green while checking nothing, exactly the silent-gap risk the task brief named.
- To prove a raw-SQL rewrite equivalent, the repo has no existing instrument that stays inside the
  "no database" discipline #839/#919 both require. Two real options, neither free:
  1. **Execute the generated SQL against a synthetic table** (SQLite or an in-memory MySQL fixture
     standing in for `wp_postmeta`/`wc_orders_meta`) and compare row sets against the same oracle
     `ShippingOrdersQueryRowSemanticsTest` already has. Nothing like this exists in the repo today —
     it would be new harness code, and PHPUnit's own DB fixtures are reserved for the (currently
     forbidden-this-session) integration suite.
  2. **Prove the two forms match once, symbolically, then pin the raw SQL by golden-string
     comparison** (build both the current tree's generated SQL and the raw hook's SQL for one fixed
     registry shape, diff them, then snapshot-test the string). This stops catching drift the moment
     `delivery_status_meta_clauses()` grows a new branch, or a provider's carrier count changes — a
     golden string does not generalize the way row enumeration does, and it is exactly the shape of
     evidence #839's own README says was rejected once already ("a script kept as research is
     auditable, but only a test is re-run by CI" — the analogous complaint here is "a golden string is
     re-run, but it does not generalize").
  3. Nothing found in this session reaches the same "unit-test-fast, no-database, evaluates the actual
     row-selection semantics" bar `ShippingOrdersQueryRowSemanticsTest` clears for the `meta_query`
     form. This is the honest gap: a raw-SQL rewrite trades a provable gate for an unprovable one.

## Q3 — what does it actually cost?

Measured with `probe.php`, same no-database technique as `measure.php` (leaf-clause counting +
real `WP_Meta_Query` against a stub `$wpdb`):

**The "mapped ids" precomputation query** (an `OR` of one `IN(known)` leaf per mapped carrier, no
`NOT`, no marker binding — see [Q4](#q4-does-the-structural-redundancy-argument-survive) for why no
binding is needed for this specific query) costs **exactly 1 `JOIN`, for any `M` from 1 to 6** — an
`INNER JOIN`, not `LEFT`, because nothing in it is `NOT EXISTS`/`NOT IN`-shaped:

| M (mapped carriers) | leaves | JOINs |
|---|---|---|
| 1 | 1 | 1 |
| 2 | 2 | 1 |
| 3 | 3 | 1 |
| 4 | 4 | 1 |
| 5 | 5 | 1 |
| 6 | 6 | 1 |

(WP_Meta_Query reuses one alias across top-level `OR`-related clauses, since an `OR` needs only one
matching row per order, unlike `AND`, which needs one joined row instance per condition that must
hold simultaneously — the same reason the *positive* `delivery_status=<canonical>` clauses in the
current code are already cheap, per #839's own table: `N + participants`, not `4M`.)

Compared against the **current, s139-shape** cost of the same filter (`3M + B`, `B = 0` in this
fixture): `M=4` is 12 joins today vs. the 1-join precompute step. So **if** the semantics could be
made to work (they cannot, as specified — see Q4), the join-count saving the card promised is real
and even understates itself slightly (1, not "~1").

**Whether it is genuinely cheap on the legacy CPT planner, or just moves the wall**: the precompute
query is **not a dependent/correlated subquery** — it is one flat, non-correlated `SELECT` against
`wp_postmeta`, bound by an `OR` of `meta_key = X AND meta_value IN (…)` predicates on a single joined
alias. This is the same shape as any ordinary WordPress meta query and gives the MySQL planner nothing
unusual to choke on, unlike the `4M`/`3M` shape #839 found (many `LEFT JOIN`s with no `meta_key`
predicate in the `ON` clause, which is what made those un-selective and multiplicative). This
reasoning is NOT independently measured against the CPT planner on a realistic table — doing so safely
would need `EXPLAIN` against a **sized, disposable copy** of `wp_postmeta` (never the shared test
database; see the safety rule in [What was not settled](#what-was-not-settled)) — but it is a
structurally different query shape from the one that wedged MySQL in s128, so the risk profile is not
the same as "trading 12 joins for an equally bad subquery."

## Q4 — does the structural redundancy argument survive?

**No — and this is the finding that decides the card**, not a caveat on top of a working design.

`probe.php` builds the CURRENT `meta_query` tree for `delivery_status=unknown` (via
`Orders_Query::build_args()`, i.e. the real, post-#839-step-2 builder) and, independently, evaluates
the PROPOSED rewrite's semantics — `FULL_SCOPE AND NOT(any provider's own status is a known one)` —
against the exact same `universe()` enumeration `equivalence.php`/the s139 gate already use (marker
present/absent × status absent/mapped/unmapped, per provider, **including combinations where more
than one provider's marker is present on the same order** — the axis the card's own hypothesis
implicitly assumes cannot happen).

Result, all three of the fixtures #839/s139 already established as the reference set:

| fixture | universe | mismatches | multi-marker | orphan-status | unexplained |
|---|---|---|---|---|---|
| 2 carriers (1 mapped, 1 bare) | 16 | 4 | 2 | 2 | **0** |
| 2 carriers (both mapped) | 64 | 16 | 8 | 8 | **0** |
| 3 carriers (2 mapped, 1 bare) | 128 | 64 | 44 | 20 | **0** |

⚠ **The last three columns are the point, and they were added when the coordinator re-ran the probe
and asked the obvious sceptical question: if only SOME mismatches are multi-marker, the rest would make
the rewrite wrong unconditionally, and the card would be dead rather than re-scopable.** The probe now
classifies every mismatch, and `unexplained` is **0** in all three fixtures: each one is either
multi-marker, or an "orphan status" — a status value on a provider's own key while that provider's
marker is ABSENT, which is physically impossible for a carrier plugin to produce and is the same shape
class #924 is about. So the divergence really is CONDITIONAL on multi-marker orders, and nothing else.

Every mismatch is `old-matched, new-did-not` (`new-only` is **0** everywhere) — the rewrite makes the
filter **strictly too narrow**, silently dropping orders that genuinely are "unknown." A concrete
failing row (2-mapped-carriers fixture):

```json
{"_m1_marker":"1","_m2_marker":"1","_m2_status":"M2_GO"}
```

This order carries BOTH carriers' markers. Carrier 1 has no status meta at all → by the current,
per-provider `OR` semantics it genuinely IS "unknown" (via carrier 1's contribution) — carrier 2 being
`in_transit` does not change that; the filter's job is "does *any* of this order's providers say
unknown," an existential over the providers actually present on the order. The proposed rewrite instead
computes one GLOBAL boolean per order — "is *some* provider on this order mapped" — and subtracts that
whole order from the result the moment ANY of its providers is mapped, which is the wrong quantifier: it
should subtract the order only when EVERY present provider is mapped. That is not what set-subtraction
against a single global "mapped ids" set can express; expressing "exists an unmapped provider among
this order's own present providers" needs the SAME kind of per-provider correlation the current `OR`
tree already provides, structurally.

**Why this is not a theoretical-only enumeration artifact**: `class-checkout-field-policy.php:590-593`
documents that WooCommerce split shipments give ONE order more than one chosen shipping
method/package, and `Orders_Registry::resolve_provider_for_order()` (`class-orders-registry.php:365-
369`) already resolves "the" carrier for a row by returning the FIRST provider whose marker is
present, without asserting there is only one — a tell that more-than-one-marker orders are anticipated
elsewhere in this codebase, not ruled out by construction. #919's own required check — "the structural
redundancy argument must survive," echoing #837 defect 2 (a negation OR'd across providers with no
binding matched the whole table) — is exactly the trap this rewrite falls into, in a different guise:
here it is not a missing marker BINDING, it is the wrong BOOLEAN COMBINATOR (a single global set
membership test standing in for a per-provider existential) that erases the OR structure #837 required.

## What was not settled

- **Whether an order can, in real installed data, ever carry more than one REGISTERED carrier's
  marker at once.** This is what the recommendation would flip on. The evidence found
  (`class-checkout-field-policy.php`'s split-shipment note, `resolve_provider_for_order()`'s
  first-wins behavior) is suggestive, not dispositive — it shows the codebase *anticipates* the
  possibility, not that it has been observed in production data. Settling it needs either an
  operator/product answer ("can two of our shipped carrier plugins both claim the same order?") or a
  query against a real install's `wp_postmeta`/`wc_orders_meta` counting orders with more than one
  registered marker key present — deliberately NOT run in this session (no database was touched, per
  the task's safety rule, and the only real installs available are the shared test databases this
  session was told to leave alone).
- **Whether the legacy-CPT planner actually handles the 1-join "mapped ids" precompute query well at
  realistic table sizes.** Reasoned as safe (flat, non-correlated, `meta_key`-predicated `OR`), not
  measured against a real `wp_postmeta` table. Settling it needs `EXPLAIN` against a sized, disposable
  copy of the table — never the shared test database (`gotcha
  two-concurrent-integration-runs-share-one-test-database`; s128's `KILL <id>` recovery is the reason
  this session enumerated instead of executing).
- **Whether a corrected, existential-preserving version of this idea exists that still beats `3M + B`
  joins.** Not attempted here — Q4 closed the door on the literal hypothesis before a corrected design
  was worth spending budget on. If #919 is revisited, the shape to explore is one that keeps a
  PER-PROVIDER correlated predicate (e.g., a `UNION` of per-provider "unmapped-or-absent" subqueries,
  or a correlated `NOT EXISTS` per provider against a narrower synthetic table) rather than one global
  set subtraction — but any such design inherits Q2's proof gap the moment it needs a SQL hook to
  express, and should be measured before being written, the same way this task was.

## How to run it

From the repository root, with the wp-env WordPress copy on disk (overridable via `WOODEV_839_REPO` /
`WOODEV_839_WP_CORE`, same as the #839 harness):

```bash
php docs-internal/research/2026-09-26-919-subquery-seam/probe.php
```

It reuses [`../2026-09-25-839-join-growth-evidence/wp-harness.php`](../2026-09-25-839-join-growth-evidence/wp-harness.php)
directly rather than duplicating it, and mirrors that directory's `registry_of()`/`universe()` exactly,
so a reader can diff the two probes to confirm they describe the same carriers. No database is
touched — the same discipline #839's own evidence directory uses.

## Related

- [../2026-09-25-839-join-growth-evidence/README.md](../2026-09-25-839-join-growth-evidence/README.md) — the join-growth measurement and the `4M+2B -> 3M+B` collapse this card was step 3 of
- [../../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md](../../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — #837 defect 2, the structural-binding failure Q4 finds a new variant of
- [../../gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md](../../gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md) — the leaf-semantics trap both this probe's and the s139 gate's oracle implement
- [../../gotchas/two-concurrent-integration-runs-share-one-test-database.md](../../gotchas/two-concurrent-integration-runs-share-one-test-database.md) — why the CPT planner question in Q3 stays unmeasured
- [../../../tests/unit/ShippingOrdersQueryRowSemanticsTest.php](../../../tests/unit/ShippingOrdersQueryRowSemanticsTest.php) — the gate Q2 finds cannot see a raw-SQL rewrite
