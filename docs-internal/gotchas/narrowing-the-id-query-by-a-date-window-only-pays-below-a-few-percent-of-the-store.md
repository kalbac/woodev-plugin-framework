# Gotcha: [perf/id-query] — Narrowing the id query by a date window only pays below a few percent of the store
> Tags: orders-query, id-resolver, hpos, cpt, performance | Session: s141

## What happens

The obvious follow-up to the orders id query (#928) is to push the request's period (and order status) into it, so
the `post__in` list shrinks and the N+2 resolver calls of a page load stop shipping every carrier order. Measured on
100 000 orders × 4 carriers (#935): a **1-week** window takes a page load 458 → 22 ms (HPOS) and 324 → ~67 ms
(CPT) — but the same push makes the **unfiltered** page +26 % / +16 % slower, a 1-year window +32–36 %, and the
order-table-drives form (`STRAIGHT_JOIN`) ×4 (HPOS) / ×10 (CPT) on a window that covers the store.

## Root cause

The resolver's id query is one flat index range on `meta_key IN (markers)` — ~7 ms per 10 k orders. A narrowing
predicate on the order table is only cheaper than that range while the window holds fewer than roughly 3–15 % of
the orders; beyond it the semi-join / per-order probe costs more than the range it replaces, and the optimizer does
not switch back (MariaDB 12.3). On the legacy CPT datastore the plain `EXISTS` form does not use the date at all
(the optimizer keeps the meta driver and probes `posts` by primary key); the `type_status_date` index only helps when
the status list rides along in the same predicate. Status alone never pays: the id query gets slower by what the
main and count queries win back.

## Fix

Nothing was built (the default view is helped by none of it). If a narrow-period workflow ever justifies it, choose
the form per call with a bounded probe (`SELECT COUNT(*) FROM (SELECT 1 … LIMIT k) t` over the same type/status/date
range) — a static "narrow only when the window is shorter than X days" rule cannot work, because orders per day is
the store's number. Keep the date window a ±1-day SUPERSET of WooCommerce's exact `date_created` so the main query
still decides the page.

❌ Wrong — push the period unconditionally:

```php
$sql .= " AND mk.order_id IN (SELECT o.id FROM {$orders} AS o WHERE o.date_created_gmt BETWEEN %s AND %s)";
```

✅ Correct — measure the window first, narrow only when it is small:

```php
$in_window = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$orders} AS o WHERE … LIMIT 3001) t" );
$narrow    = $in_window <= 3000; // the cut-off is the store's, not a constant to copy — re-measure
```

## Related

- [935-id-narrowing](../research/2026-09-27-935-id-narrowing/README.md) — the measurement and the tables behind these numbers
- [an-or-of-exists-meta-clauses-joins-the-meta-table-once-per-key-unpredicated](an-or-of-exists-meta-clauses-joins-the-meta-table-once-per-key-unpredicated.md) — why the id query exists
