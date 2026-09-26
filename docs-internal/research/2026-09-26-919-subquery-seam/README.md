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
([Q4](#q4-does-the-structural-redundancy-argument-survive)) finds real mismatches, and they are not
artefacts of a straw-man reading: with the exclusion set **bound to its own carrier's marker**, which
is what card #919 requirement 1 demands and what a competent implementer would build, the divergence
does not go away — it sharpens, to **2 / 8 / 36 mismatches** across the three fixtures, every one of
them a **multi-marker order** ([Q4b](#q4b-the-fair-model), replayable from `probe.php`).

**Multi-marker orders are physically producible by the shipped carrier plugins**, not a theoretical
enumeration axis: `edostavka` writes its marker on `checkout_create_order` **per shipping package**
(`class-wc-edostavka-checkout.php:930`), and `yandex` writes its marker from `set_state_status()` on
export, gated only on the order HAVING a Yandex shipping item, never on it being the only one
(`class-order.php:100`, reached from `export_order()` at `:302-315`). A cart that splits into two
packages — CDEK plus Yandex — therefore gets one marker at checkout and the other on export, with no
exotic input. Consistently, `Orders_Registry::resolve_provider_for_order()`
(`class-orders-registry.php:365-373`) picks whichever marker it finds first rather than asserting
uniqueness.

**The one thing that would change this**: proof that no order can ever carry more than one registered
carrier's marker. Given those per-package write paths that would have to be a business-rule decision,
not a code fact — see [What was not settled](#what-was-not-settled).

Card #919 should be **closed as "not viable as specified" and re-scoped** around a form that preserves
the existential per-provider structure. ⚠ **Re-scoped, not abandoned:** the current `3M + B` shape is
NOT known to be safe. The s128 wall was **12 joins at 2 mapped + 2 bare carriers** (`4·2 + 2·2`) — not
"`M = 4`" — and `3M + B` reaches that same 12 at `M = 4, B = 0`, with the README's own six-carrier row
at 18. "`3M + B` has not been shown to hit the wall" is true only because nothing past the s128 fixture
was ever executed. What IS measured is #839's field figure — 2 real carriers, HPOS, 71 orders, 106 ms
for the negative filter: slow relative to its siblings, not wedged.

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
  `OrdersTableQuery::get_field_mapping_info()` (`:130-134`) — so it cannot address order META at all,
  only order-table columns, which makes it unusable for a meta-based predicate regardless of the
  subquery question; and it has no CPT equivalent, which the card's own both-datastores constraint
  rules out on its own.
- HPOS meta itself is reached through `OrdersTableMetaQuery.php` (`:539-623`) against `wc_orders_meta`,
  not `wp_postmeta` — and that class prepares every value the same per-element way, so the conclusion
  holds on that path too.

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

| M (mapped carriers) | leaves | JOINs, unbound | JOINs, BOUND to own marker |
|---|---|---|---|
| 1 | 1 | 1 | 2 |
| 2 | 2 | 1 | 4 |
| 3 | 3 | 1 | 6 |
| 4 | 4 | 1 | 8 |
| 5 | 5 | 1 | 10 |
| 6 | 6 | 1 | 12 |

All INNER, none LEFT — nothing in this query is `NOT EXISTS`/`NOT IN`-shaped.

(WP_Meta_Query reuses one alias across top-level `OR`-related clauses, since an `OR` needs only one
matching row per order, unlike `AND`, which needs one joined row instance per condition that must
hold simultaneously — the same reason the *positive* `delivery_status=<canonical>` clauses in the
current code are already cheap, per #839's own table: `N + participants`, not `4M`.)

⚠ **That 1 join is the UNBOUND precompute** — the set "some mapped carrier reports a known status",
with no marker binding. It is cheap precisely because it leans on the same unwritten invariant #924 is
about (a carrier never writes another carrier's status meta). **Bound to its own marker**, as card
requirement 1 and #924 would both demand, the precompute costs **`2M` INNER joins** (measured:
2 / 4 / 6 / 8 / 10 / 12 for `M = 1…6`) — because each carrier then needs its marker row AND its status
row joined simultaneously, which an `OR` cannot share.

So the honest comparison against the **current, s139-shape** `3M + B` is `2M` INNER versus `3M + B`
LEFT, not "1 versus 12". Still a real saving, and INNER joins with a `meta_key` predicate are a far
better shape for the planner than un-selective `LEFT JOIN`s — but the card's headline "~1" understates
the cost of the only version of the idea that respects its own requirement 1.

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

| fixture | universe | mismatches | pure multi-marker | orphan-status | unexplained |
|---|---|---|---|---|---|
| 2 carriers (1 mapped, 1 bare) | 16 | 4 | 2 | 2 | **0** |
| 2 carriers (both mapped) | 64 | 16 | 8 | 8 | **0** |
| 3 carriers (2 mapped, 1 bare) | 128 | 64 | 24 | 40 | **0** |

⚠ **The last three columns are the point, and they exist because the coordinator re-ran the probe and
asked the obvious sceptical question: if only SOME mismatches are multi-marker, the rest would make the
rewrite wrong unconditionally, and the card would be dead rather than re-scopable.** The probe now
classifies every mismatch and `unexplained` is **0** in all three fixtures: each is either a
multi-marker order, or an "orphan status" — a status value on a provider's own key while that
provider's marker is ABSENT, which no carrier plugin can produce, and which is the same shape class
#924 is about.

The classification tests **orphan first, then multi-marker**, deliberately: a row that is both is still
impossible, so counting it as multi-marker would inflate the realistic-failure count. Doing it the
other way round reports 44 multi-marker rows in the 3-carrier fixture where only 24 are pure.

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

## Q4b — the fair model

The objection to answer before anything else: was the card killed by a straw man? `probe.php`'s first
model builds the exclusion set WITHOUT binding each status to its own carrier's marker, while card #919
requirement 1 explicitly demands that binding. So the probe measures the bound model too, and it does
not rescue the idea — it sharpens the finding:

| fixture | mismatches | multi-marker | single-marker |
|---|---|---|---|
| 2 carriers (1 mapped, 1 bare) | 2 | 2 | **0** |
| 2 carriers (both mapped) | 8 | 8 | **0** |
| 3 carriers (2 mapped, 1 bare) | 36 | 36 | **0** |

`new-only` is 0 here too. With the binding in place the orphan-status class disappears entirely and
**every** remaining mismatch is a genuine multi-marker order — the case the shipped carrier plugins can
produce. The divergence is therefore exactly and only the multi-marker case, under the reading the card
itself asks for.

This section and the bound cost column in [Q3](#q3--what-does-it-actually-cost) were added after review:
the reviewer built the bound variant independently, and folding it into the committed probe is what keeps
it a proof rather than a claim — the same rule that put `equivalence.php`'s successor into the test suite
in s139. Its numbers reproduce the reviewer's run exactly.

## What was not settled

- **Whether an order can, in real installed data, ever carry more than one REGISTERED carrier's
  marker at once.** This is what the recommendation would flip on, and the distinction matters:
  *producible* is settled, *observed* is not.

  **Producible — settled, by reading the two shipped plugins' write paths** (review found these; they
  are stronger than this note's first citations, which only showed the codebase anticipating the
  possibility):
  - `edostavka` writes its marker on `checkout_create_order` **per shipping package**
    (`class-wc-edostavka-checkout.php:930`);
  - `yandex` writes its marker from `set_state_status()` on export, gated only on the order HAVING a
    Yandex shipping item — never on it being the only one (`class-order.php:100`, reached from
    `export_order()` at `:302-315`).

  A cart splitting into two packages therefore produces a two-marker order with no exotic input. The
  framework itself writes no marker at all (nothing in `woodev/**` writes a `marker_meta_key`; the rig
  fixtures only seed one in tests), so this question can only ever be answered by the plugins and by a
  policy, not by framework code.

  **Observed — not settled.** Nobody has counted such orders in a real install. Settling it needs
  either a product answer ("can two of our carrier plugins both claim one order?" — given the
  per-package write paths, that has to be a business rule) or one count query against a real install's
  `wp_postmeta` / `wc_orders_meta` for orders carrying two or more registered marker keys.
  Deliberately NOT run here: no database was touched, and the only real databases reachable are shared
  ones this session was told to leave alone.
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
