# An empty string from a domain seam is the domain FAILING to answer, not a key

**Namespace:** `[framework/contracts]`
**Discovered:** 2026-08-11 (s65, issue #176)

## The trap

A seam whose whole purpose is that the framework must not interpret its values makes it tempting
to accept every value it returns, `''` included. That is one step too far. `''` is not a value in
the domain's vocabulary — it is the seam telling you it could not name one:

- a point whose locality the plugin's own map does not cover (`self::REGION_CODE_BY_CITY[ … ] ?? ''`);
- `current_locality()` asked before WooCommerce can answer;
- the framework's own "chosen shipping method not known yet" sentinel, already documented as `''`
  on `woodev_shipping_pickup_point_selection`'s `$context['method_id']`.

Storing under `''` is not merely untidy. **Every unnameable locality collapses into one shared
bucket**, and a later read whose `current_locality()` also could not answer then recalls a point
belonging to some other locality entirely — the customer gets a pickup point from a city they
never chose. The write is silent, the read is silent, and the map looks fine.

## ❌ Wrong

```php
public function remember( string $locality, string $type, string $point_id ): void {
    $map[ $locality ][ $type ] = [ 'id' => $point_id, 'seq' => $this->next_sequence( $map ) ];
}
```

## ✅ Correct

Refuse on BOTH sides — the write and the read. Refusing only the write still lets a map written
by an earlier version, or by hand, be recalled:

```php
private static function is_usable_key( string $key ): bool {
    return '' !== $key;
}
```

## Why this is not the framework interpreting a domain value

It never compares the key to anything, never normalizes it, never derives it. It only declines to
treat "no answer" as an answer — the same reading `''` already has for a method id two seams over.
Consistency across the seams is what makes the rule defensible rather than ad hoc.

## Testing it

The load-bearing test is not "an empty locality is not written" — it is "a `''` bucket seeded
directly into storage is never recalled". Only that one fails when the guard is dropped from the
read side alone.

## Related
- [[derive-a-view-field-at-the-boundary-not-at-display-sites]] — the sibling rule about where a
  derived value is produced; this one is about what counts as a value at all
- [[custom-checkout-field-is-empty-on-reload-by-construction]]
