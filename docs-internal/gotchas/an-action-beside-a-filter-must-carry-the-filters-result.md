# An action fired beside a filter must carry the filter's RESULT, not its input

**Namespace:** `[framework/wiring]`
**Discovered:** 2026-08-11 (s65, issue #176)

## The trap

A route computes a value, passes it through a domain filter, and then wants to fire an action so
another part of the framework can react. The obvious shape is wrong in two independent ways, and
both are silent:

```php
$filtered = apply_filters( 'woodev_shipping_pickup_point_selection', $computed, $point, $context );

do_action( 'woodev_shipping_pickup_point_selected', $point, $context );   // ← both mistakes

return rest_ensure_response( Selection_Result::sanitize( $filtered, $computed ) );
```

1. **The gate reads the pre-filter verdict.** The filter is contractually allowed to FLIP
   `allowed`. Gating persistence on `$computed` remembers a point the domain has just refused.
2. **The payload is the pre-filter object.** The same filter may return a CORRECTED point, and its
   own docblock promises the browser replaces what it holds with that one. A listener deriving a
   storage key from the pre-filter point files it under a locality the checkout no longer reports —
   the value is stored, the read misses, nothing throws.

The second one is the sneaky one: the code looks right, the tests pass, and the defect only shows
when a domain actually uses the correction the contract invites it to use.

## ✅ Correct

Fire after sanitization, gate on the FINAL verdict, and hand over the effective object:

```php
$filtered  = apply_filters( 'woodev_shipping_pickup_point_selection', $computed, $point, $context );
$sanitized = Selection_Result::sanitize( $filtered, $computed );

if ( true === $sanitized['allowed'] ) {
    $effective_point = $point;

    if ( null !== $sanitized['point'] && is_array( $filtered['point'] ?? null ) ) {
        $corrected = Pickup_Point::from_array( $filtered['point'] );
        if ( null !== $corrected ) { $effective_point = $corrected; }
    }

    do_action( 'woodev_shipping_pickup_point_selected', $effective_point, $context );
}

return rest_ensure_response( $sanitized );
```

Rebuilding through the same `from_array()` the sanitizer already used avoids a second, drifting
copy of the shape rules, and a non-null `$sanitized['point']` is exactly the signal that it
validated.

## Do not reuse the filter as the side-effect seam

Hooking `woodev_shipping_pickup_point_selection` to write the session was the tempting
zero-new-API option and is wrong: the contract is "the domain volunteers advice on top of a
verdict", it runs on refusals too, and it runs before the verdict is final. Adding a real action
keeps the controller free of WooCommerce globals as well — firing an action is not reading one.

## Testing it

Two mirrored tests, not one: a domain filter that refuses an allowed point must fire nothing, and
a domain filter that ALLOWS a framework-refused point must fire. One alone passes against a gate
reading the wrong verdict.

## Related
- [[built-on-both-sides-with-no-caller-in-the-middle]]
- [[an-empty-domain-key-is-not-a-key]]
