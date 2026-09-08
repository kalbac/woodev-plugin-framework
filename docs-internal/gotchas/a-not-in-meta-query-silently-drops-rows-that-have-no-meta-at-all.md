# `NOT IN` in a `meta_query` silently drops every row that has no such meta at all

> Namespace: `php/*` — added session 126 (2026-09-08).

## The trap

A `meta_query` clause with `'compare' => 'NOT IN'` reads like "every row whose value is not one of
these" — including rows that have no value at all. It is not. Rows with no meta row for that key are
**excluded**, and nothing warns you.

Only `NOT EXISTS` makes `WP_Meta_Query` use a LEFT JOIN. WordPress states it in its own source
(`wp-includes/class-wp-meta-query.php`, `get_sql()`):

> If any JOINs are LEFT JOINs (as in the case of NOT EXISTS), then all JOINs should be LEFT.
> Otherwise posts with no metadata will be excluded from results.

So the LEFT JOIN is triggered by the presence of a `NOT EXISTS` clause, not by negativity in
general. `NOT IN`, `!=` and `NOT BETWEEN` on their own join INNER.

## Why it is dangerous rather than merely wrong

The excluded rows are usually the MAJORITY case, and the query still succeeds. In SP-10 the
`unknown` delivery-status filter was built as one `NOT IN` of the carrier's known raw statuses. The
reasoning written into the docblock was that negative compares LEFT JOIN. The result: an order the
carrier had never reported on — an order with no status meta whatsoever, which is the commonest
"unknown" there is — never appeared in the filter that exists to find it. No error, no warning,
a plausible-looking shorter list.

## ❌ Wrong

```php
// "everything that is not one of the known statuses" — but only among orders
// that already HAVE a status. Orders with none are dropped.
$meta_query[] = [
    'key'     => $status_key,
    'value'   => $known_raw_values,
    'compare' => 'NOT IN',
];
```

## ✅ Correct

```php
// Both halves, OR'd: no meta at all, and meta present but unrecognised.
$meta_query[] = [
    'relation' => 'OR',
    [
        'key'     => $status_key,
        'compare' => 'NOT EXISTS',
    ],
    [
        'key'     => $status_key,
        'value'   => $known_raw_values,
        'compare' => 'NOT IN',
    ],
];
```

## How it was caught, and how it nearly was not

By an **integration test on real rows** that created three orders — one mapped, one with an
unmapped raw value, one with no status meta — and asserted all three outcomes. The unit test at the
same time asserted the WRONG shape and passed, because it only inspected the args array that was
built; a unit test over query ARGS cannot see what MySQL will do with them.

⚠ The test that caught it was written by the worker who introduced the bug, in the same commit, and
was red the first time anyone ran it — the author could not run integration from a worktree
(`WOODEV_FRAMEWORK_DIR` points at the main checkout). **Integration is the coordinator's gate and is
not optional**; a worker's green unit run says nothing about this class of defect.

## Related

- [wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore](wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md) — the other half of the same family: the CPT datastore drops `meta_query` outright
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — same shape of failure one layer up
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — why a worker's own green run is not the tree's green run
