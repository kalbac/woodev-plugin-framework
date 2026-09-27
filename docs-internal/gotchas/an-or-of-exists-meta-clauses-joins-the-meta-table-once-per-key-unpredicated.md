# Gotcha: [perf/meta-query] — An OR of `EXISTS` meta clauses joins the meta table once per key, un-predicated
> Tags: meta_query, orders-query, hpos, cpt, performance | Session: s140

## What happens

The «Заказы доставки» page scoped its rows with an OR of `marker EXISTS` clauses, one per registered
carrier. With 2 carriers it was fast everywhere we looked (the rig, #839's 106 ms), so the cost was
blamed on the NEGATIVE filters' `3M + B` joins for two cards running (#839, #919). Measured in s140:
the **unfiltered** page at 4 carriers takes **11.7 s per 10 000 orders** at the rig's real meta density,
and every form that keeps the scope times out past 30 s — the negation was never the wall.

## Root cause

`WP_Meta_Query` builds an `EXISTS` clause as `INNER JOIN meta ON (id = post_id)` with the `meta_key`
test in `WHERE` (`wp-includes/class-wp-meta-query.php:609-611`). OR-siblings share one alias only for
the value compares on its allow-list (`=`, `IN`, `LIKE` …, `:848`); `EXISTS` is not on it, so each key
gets its OWN join, and none of them carries the key in its `ON`. HPOS `OrdersTableMetaQuery` does the
same (`:436`, allow-list `:491-492`). An order with `d` meta rows therefore produces `~d^N` row
combinations before `WHERE` filters them: 4 joins 5 678 ms, 2 joins 110 ms, 1 keyed join 10.7 ms on the
same 10 k orders at 6.8 rows/order (51× ≈ 6.8²). At `N = 2` it hides; at `N ≥ 4` it stalls. A real CPT
store keeps addresses and totals in postmeta (30–60 rows/order), so it is worse than the rig.

## Fix

Never scope by an OR of `EXISTS` over several meta keys. Resolve the ids with one flat query whose
driver has the keys as a predicate (`meta_key IN (…)`) and correlated `EXISTS`/`NOT EXISTS` keyed on
id + key (+ value), then pass them as `post__in` — which HPOS maps to `id`. Map an EMPTY list to a
match-nothing sentinel: `[]` fails OPEN on both datastores.

❌ Wrong — one un-predicated join per carrier:

```php
$args['meta_query'] = [
	'relation' => 'OR',
	[ 'key' => '_cdek_marker', 'compare' => 'EXISTS' ],
	[ 'key' => '_yandex_marker', 'compare' => 'EXISTS' ],
	// … N of them → ~d^N rows
];
```

✅ Correct — `Orders_Id_Resolver` (#928): one flat id query, no join, fed to `post__in`:

```php
$ids = $resolver->resolve( $marker_keys, $tree ); // SELECT DISTINCT … WHERE mk.meta_key IN (…) AND EXISTS (…)
$args['post__in'] = $ids ?: [ 0 ];                // real code maps [] to NO_MATCH_META_QUERY
```

## Related

- [a-negative-meta-clause-or-ed-across-providers-matches-every-order](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — the correctness trap in the same OR
- [the-orders-filter-stands-on-two-unenforced-carrier-invariants](the-orders-filter-stands-on-two-unenforced-carrier-invariants.md) — what the id form still relies on
- [928-form-measurement](../research/2026-09-26-928-form-measurement/README.md) — the measurement and its independent verification
