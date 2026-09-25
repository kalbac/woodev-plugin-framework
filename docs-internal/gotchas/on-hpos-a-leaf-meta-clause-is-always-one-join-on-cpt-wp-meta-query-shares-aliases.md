# [woocommerce/meta-query-joins] On HPOS a leaf meta clause is always one join; on the legacy CPT store `WP_Meta_Query` shares aliases for POSITIVE `OR` siblings

> Namespace: `woocommerce/*` — added session 138 (2026-09-25), measured while sizing #839

## The trap

The same `meta_query` costs a different number of `JOIN`s on the two order datastores, and the
difference is not a constant factor — it depends on the COMPARISON operator. So a join budget
measured on HPOS does not transfer to CPT, and vice versa. Worse, the cheaper one is the one nobody
expects: **the legacy CPT path emits FEWER joins**, because `WP_Meta_Query` deduplicates and
WooCommerce's `OrdersTableMetaQuery` does not.

Measured on the aggregate delivery-status filter of the shipping orders page, leaf clauses vs joins:

| Filter shape | leaves | HPOS joins | CPT joins |
|---|---|---|---|
| `delivery_status=<canonical>`, 3 carriers | 6 | 6 | **4** |
| `delivery_status=<canonical>`, 6 carriers | 12 | 12 | **7** |
| `delivery_status=unknown`, any N | 4M+2B | 4M+2B | 4M+2B |
| `delivery_status_not=<canonical>` | 4M+2B | 4M+2B | 4M+2B |

## Root cause

`WP_Meta_Query` shares one table alias between sibling clauses of an `OR` **when the comparison is
positive** — `IN`, `=`, `LIKE` qualify. `EXISTS` does not, and no negative comparison does:
`NOT IN`, `NOT EXISTS`, `!=` each take their own alias, because a shared alias cannot express "this
row has no such meta" for two different keys at once. WooCommerce's HPOS meta-query builder shares
nothing at all: every leaf gets its own `INNER JOIN wp_wc_orders_meta AS metaN`, with the
`meta_key` predicate pushed into `WHERE` rather than into `ON`.

So: **negative filters dedupe nowhere, on either datastore.** They are exactly the ones that grow
per carrier, which is why #839's growth law is identical on both stores even though positive filters
differ.

## The part that actually bites

**The join COUNT is not what makes CPT pathological.** Same query, same count:

- 12 joins over `wp_postmeta` / `wp_posts` — never finished; MySQL sat in `Sending data` over four
  minutes (s128).
- 8 joins over `wp_wc_orders_meta` / `wp_wc_orders` — 4 ms.

The planner, not the arithmetic, is the difference. A conclusion that follows: declaring a page
HPOS-only removes the store where this shape becomes pathological but **reduces the join count by
zero**. It buys a planner, not a budget.

## Fix

❌ Wrong — measure one store and state the budget as a property of the query:

```php
// "the filter costs 12 joins" — true on HPOS, false on CPT for positive shapes
```

✅ Correct — measure BOTH, and say which store each number belongs to. Capture the real SQL from the
filter without executing anything:

```php
add_filter( 'woocommerce_orders_table_query_clauses', function ( $clauses ) { /* record, then */ throw new Captured(); } );  // HPOS
add_filter( 'posts_clauses', function ( $clauses ) { /* record */ return $clauses; } );                                      // legacy CPT
```

⛔ **Do not execute the aggregate with more than two carriers on CPT to find out.** Killing PHP does
not stop the query — it keeps running in MySQL holding metadata, and the next run hangs on
`DROP TABLE wp_users`; recovery needs `KILL <id>` inside the server (s128).

For a count with no database at all, build the tree with N fabricated carriers and hand it to the
real `WP_Meta_Query` with a stub `$wpdb` — WordPress core is on disk in the wp-env image.

## Related

- [a-negative-meta-clause-or-ed-across-providers-matches-every-order](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — the semantics of the very clauses counted here, and why each negative branch must bind its own marker
- [two-concurrent-integration-runs-share-one-test-database](two-concurrent-integration-runs-share-one-test-database.md) — why the pathological run poisons a neighbour's suite
- [killing-phpunit-leaves-its-mysql-query-running-and-holding-locks](killing-phpunit-leaves-its-mysql-query-running-and-holding-locks.md) — the `KILL <id>` recovery
