# Gotcha: [testing/*] — A `relation` key does not tell the AND wrapper from a single `meta_query` part
> Tags: meta_query, orders-query, test-helper, shipping | Session: s139

## What happens

A test helper written to address `meta_query` parts by ROLE instead of by index — the whole point of
#918, which existed to let #839 step 2 land without re-indexing eight tests — broke on the very
change it was written to enable. Three tests died with `Undefined array key 0` and two of the #837
defect-2 guards reported `'OR' !== 'AND'`, all five looking like defects in the production change and
none of them being one.

The helper decided "is this the wrapper around several parts, or one part on its own?" by asking
`array_key_exists( 'relation', $meta_query )`.

## Root cause

`Orders_Query::combine_meta_queries()` returns `$parts[0]` UNWRAPPED when there is only one part, and
`array_merge( [ 'relation' => 'AND' ], $parts )` otherwise. Both outcomes can carry a top-level
`relation`, because a single part is very often itself a relation group:

- **Two mapped carriers, scope dropped.** The sole remaining part is the status part,
  `[ 'relation' => 'OR', 0 => AND-group, 1 => AND-group ]`. Branching on the presence of `relation`
  unsets the `OR` and hands back the two AND-groups as if they were top-level parts — so the helper
  returns one provider's clause where the test asked for the whole status part, and the guard reads
  `'AND'` where it asserts `'OR'`.
- **One carrier with no status concept.** `meta_query_for_keys()` is a thin wrapper over
  `meta_query_for_clauses()`, so for ONE key it returns a BARE clause. The `unknown` filter's part for
  such a carrier is that carrier's own marker `EXISTS` leaf — byte-equal to the scope part. With the
  scope dropped there is exactly one part, the helper strips it as "the scope", and indexing into the
  empty remainder throws `Undefined array key 0`.

And the discriminator that looks like the obvious repair is also wrong: **"the wrapper is present iff
one of the numeric children equals the scope part"**. For one carrier post-collapse the filter part is
`AND( marker_leaf, OR( … ) )`, and `marker_leaf` IS byte-equal to
`meta_query_for_keys( [ that_key ] )` — so that rule reports a wrapper where there is none and returns
the inner `OR` group.

## Fix

Recognise the wrapper by the ONE thing that is unambiguous: its relation is `AND` **and** its known
first child is the scope part. `build_args()` pushes the marker-key scope onto `$meta_query_parts`
before any filter part, so the scope is always first when it is there at all.

❌ Wrong — the presence of a `relation` key, or the scope matched against every child:

```php
private function meta_query_top_level_parts( array $meta_query ): array {
	if ( array_key_exists( 'relation', $meta_query ) ) {   // shreds a single OR-group part
		unset( $meta_query['relation'] );

		return array_values( $meta_query );
	}

	return [ $meta_query ];
}

// and then, on the parts: remove the first child equal to the scope — which
// eats the SOLE part when a filter part is itself shaped like the scope.
```

✅ Correct — relation `AND` plus the scope in its known first position:

```php
private function meta_query_top_level_parts( array $meta_query, array $scope ): array {
	if ( 'AND' === ( $meta_query['relation'] ?? null ) && ( $meta_query[0] ?? null ) === $scope ) {
		unset( $meta_query['relation'] );

		return array_values( $meta_query );
	}

	return [ $meta_query ];
}

private function meta_query_filter_part( array $meta_query, array $scope_marker_keys ): array {
	$scope = Orders_Query::meta_query_for_keys( $scope_marker_keys );
	$parts = $this->meta_query_top_level_parts( $meta_query, $scope );

	return 1 === count( $parts ) ? $parts[0] : $parts[1];
}
```

Live at `tests/unit/ShippingOrdersQueryTest.php:101-163`.

## The acceptance that catches it

A helper meant to survive a shape change must be proven against BOTH shapes, and neither run is
optional. Keep the tests and swap the production file:

```bash
rm -f .phpunit.result.cache && php vendor/bin/phpunit --testsuite=Unit --filter ShippingOrdersQueryTest
git checkout main -- woodev/shipping-method/admin/orders/class-orders-query.php
rm -f .phpunit.result.cache && php vendor/bin/phpunit --testsuite=Unit --filter ShippingOrdersQueryTest
git checkout HEAD -- woodev/shipping-method/admin/orders/class-orders-query.php
```

The first version of the helper passed the pre-change shape and nothing else, and its own docblock
claimed it handled the byte-identical case — which it did only while the scope part was still present.
A claim in a docblock is not a measurement.

## Related

- [on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases](on-hpos-a-leaf-meta-clause-is-always-one-join-on-cpt-wp-meta-query-shares-aliases.md) — the cost law the collapse changes, and why the two datastores price the same tree differently
- [a-negative-meta-clause-or-ed-across-providers-matches-every-order](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — #837 defect 2, whose guards are two of the five tests this broke
- [a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all](a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md) — the leaf semantics the row-semantics gate had to implement to prove equivalence
