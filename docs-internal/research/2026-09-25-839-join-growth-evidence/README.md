# #839 — the JOIN growth law of the aggregate delivery-status filter, and the equivalence proof

> Session 138 (25.09.2026), macOS laptop. Produced by an Opus 5 worker, reviewed by Codex
> (`gpt-5.6-terra`). Kept because the review's one BLOCKER was that this evidence existed only as
> prose in a scratchpad: a proof nobody can rerun is not a proof.

## Why these files are in the repository

Card #839 asked where the wall is. It was answered without executing the pathological query at all —
which matters, because in s128 running it wedged the shared test database and needed `KILL <id>`
inside MySQL to recover. Two measurements, no database touched:

- **`measure.php`** — counts the leaf clauses `Orders_Query::build_args()` emits for N fabricated
  carriers, and turns each tree into REAL SQL through the REAL `WP_Meta_Query` with a stub `$wpdb`,
  so the legacy-CPT join count is observed rather than inferred. Output: `table-before.txt`.
- **`equivalence.php`** — decides whether dropping the duplicate marker SCOPE part changes WHICH rows
  the filter selects, by exhaustive enumeration: it evaluates the `meta_query` tree directly against
  every possible order over the carriers (marker present/absent × status absent/mapped/unmapped),
  16 / 64 / 128 orders, with WordPress's own leaf semantics — including the trap that `NOT IN` does
  not match a row that has no such meta row at all
  ([gotcha](../../gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md)).
- **`hpos-capture.php`** — captures the real HPOS SQL through
  `woocommerce_orders_table_query_clauses` and aborts before execution, anchoring the leaf counts to
  observable joins.
- **`wp-harness.php`** — the shared bootstrap. Its two paths are overridable:
  `WOODEV_839_REPO` and `WOODEV_839_WP_CORE`.

## How to run them

From the repository root, with the wp-env WordPress copy on disk:

```bash
php docs-internal/research/2026-09-25-839-join-growth-evidence/measure.php
php docs-internal/research/2026-09-25-839-join-growth-evidence/equivalence.php
```

`equivalence.php` builds BOTH the current and the proposed tree itself, so it runs on plain `main`
and does not need the parked branch checked out. Re-verified from this location on `main` at
`50988ea`: every case `IDENTICAL`, zero rows differing in either direction.

## What was measured

`M` = carriers with a usable status map, `B` = carriers with none, `N = M + B`. Joins:

| Filter | Cost |
|---|---|
| no filter | `N` |
| `delivery_status=unknown` | `4M + 2B` |
| `delivery_status_not=<canonical>` | `4M + 2B` |
| `delivery_status=<canonical>` | `N + participants` |
| `delivery_status_not=unknown` | `N + M` |

The **`4 carriers (2 mapped + 2 bare) / unknown`** cell is **12** — exactly the twelve
`LEFT JOIN wp_postmeta` s128 saw when MySQL sat in `Sending data` for over four minutes. The table is
therefore anchored to the field observation, not merely plausible.

The duplicate marker join is the SCOPE term, `N`. For every NEGATIVE filter it is a pure duplicate,
because each of those branches already opens with that same carrier's `marker EXISTS`.

## Status of the fix

The collapse (`4M + 2B` → `3M + B`, a saving of exactly N joins) is written and proven but **parked**
on branch `kalbac/s138-card-839-scope-collapse`. It is not merged because it re-indexes eight existing
tests — purely positionally, every asserted clause byte-identical — and two of those are the #837
defect-2 guards. Re-indexing a guard in the same change that alters the shape it guards is the move
the rule exists to prevent.

**Before that change lands, `equivalence.php` must become a committed unit test** (the critic's
BLOCKER): a script kept as research is auditable, but only a test is re-run by CI. Tracked on #918
(role-based addressing of `meta_query` parts, which removes the eight failures without touching an
assertion) and #919 (the subquery seam, which is where ~95 % of the cost actually is).

## Related

- [../../gotchas/on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases.md](../../gotchas/on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases.md) — why the two datastores cost the same tree differently
- [../../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md](../../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — the semantics these clauses must preserve (#837 defect 2)
- [../../gotchas/two-concurrent-integration-runs-share-one-test-database.md](../../gotchas/two-concurrent-integration-runs-share-one-test-database.md) — why the pathological query is never executed to find out
