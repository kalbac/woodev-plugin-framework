# A NEGATIVE meta clause OR-ed across providers matches EVERY order

> Namespace: `shipping/*` — added session 127 (2026-09-08). Found on the rig with two carriers
> registered; invisible with one, which is why every test missed it.

## The trap

The orders page scopes rows by OR-ing one clause per registered carrier — that is correct for the
row scope, and correct for any POSITIVE condition. Apply the same shape to a NEGATIVE one and the
query silently matches the whole table:

```text
carrier=all    has_tracking=false  ->  71 of 71 rows   (should be 24)
realistic      has_tracking=false  ->  11 of 34        (correct)
test_shipping  has_tracking=false  ->  13 of 37        (correct)
```

## Root cause

«Carrier B's tracking key does not exist» is **trivially true of every carrier A order** — a carrier
never writes another carrier's meta. So:

```text
OR( realistic_tracking NOT EXISTS, test_tracking NOT EXISTS )
```

is satisfied by literally every row: a realistic order satisfies the second branch, a test order the
first. AND-ing that with the marker scope changes nothing, because the negative group is already
`true`.

A POSITIVE condition has no such hole: `EXISTS` on a carrier's own key already implies that
carrier's order.

## ❌ Wrong

```php
$clauses[] = [
    'key'     => $tracking_key,
    'compare' => $has_tracking ? 'EXISTS' : 'NOT EXISTS',
];
```

## ✅ Correct — bind the negative to that provider's own marker

```php
if ( $has_tracking ) {
    $clauses[] = [ 'key' => $tracking_key, 'compare' => 'EXISTS' ];
    continue;
}

$clauses[] = [
    'relation' => 'AND',
    [ 'key' => $provider->get_marker_meta_key(), 'compare' => 'EXISTS' ],
    [ 'key' => $tracking_key,                    'compare' => 'NOT EXISTS' ],
];
```

## Why the tests did not catch it

**Every earlier tracking test registered ONE provider.** With one provider the OR has a single
branch, that branch is the carrier's own, and the defect cannot appear. The guard that works
registers TWO and asserts each OR branch names a marker — see
`ShippingOrdersQueryTest::test_has_tracking_false_on_the_aggregate_does_not_match_every_order()`.

**The transferable half:** an aggregate over N sources needs at least two sources in the fixture
before any of its logic is really under test. One is not a small N, it is a different shape.

## Its siblings on the same page

- `delivery_status=unknown` returns every row for the same reason — recorded on **#837**, unfixed.
- `carrier=advanced` widens to all instead of matching nothing — **#835**.
- Any future "is not" rule from **#836** walks straight into this; ⚠ and it cannot be written as a
  `NOT IN` either, for a different reason — see the Related gotcha.

## Related

- [a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all](a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md) — the other half of the negation problem
- [wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore](wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md)
