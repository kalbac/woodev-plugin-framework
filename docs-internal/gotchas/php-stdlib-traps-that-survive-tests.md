# Four PHP/WP stdlib behaviours that pass tests and fail in production

**Namespace:** `[php/stdlib]` · **Discovered:** s45 (2026-07-31), SP-5 review rounds · **4th added s108**

Each of these was caught by a reviewer, not by a green suite. They share a shape: the wrong
behaviour is *plausible*, so a test written from the same assumption as the code agrees with it.

## 1. `$int > null` silently becomes `$int > 0`

A pickup point's `max_weight` is `int|null`, where **null means "the carrier did not say"** and must
be permissive. The guard:

```php
if ( null !== $max_weight && $cart_weight > $max_weight )
```

Delete `null !== $max_weight` and PHP coerces `null` to `0`, so the condition becomes
`$cart_weight > 0` — **a point with an unknown limit rejects every non-empty cart**, which is the
exact inverse of the documented rule. Nothing throws; the customer just cannot use any point.

Related trap in the same class: a carrier that expresses "no limit" as `0` produces
`Вес заказа 0.00 кг превышает ограничение — 0.00 кг`. Treat a non-positive limit as absent.

**Rule:** when `null` and `0` mean opposite things, test both explicitly, and never let a
comparison against a nullable reach the operator without an explicit null guard.

## 2. `array_filter()` preserves keys, so JSON turns an array into an object

```php
return array_filter( array_map( [ Pickup_Point::class, 'from_array' ], $payloads ) );
```

Drop one malformed point out of three and the result is `[ 0 => …, 2 => … ]`. `wp_json_encode()`
serialises that as a JSON **object** `{"0":…,"2":…}`, not an array — and the map JS iterating it
gets nothing. It works right up until the first bad record from a real carrier.

**Rule:** `array_values()` anything that crosses a JSON boundary after a filter. `$points[] = …` in
a loop is immune by construction and is the safer idiom.

## 3. `add_query_arg()` does not URL-encode

WordPress's own documentation says values "are expected to be encoded appropriately with
`urlencode()` or `rawurlencode()`" — `build_query()` calls `_http_build_query( …, $urlencode = false )`.
An API key containing `&`, `#` or a space silently truncates the query string.

The test missed it because the **stub** used `http_build_query()`, which *does* encode. A stub that
is more correct than the real function hides the bug it exists to catch.

**Rule:** `rawurlencode()` values before `add_query_arg()`, and make stubs faithful to the real
function's flaws, not to its intent.

## 4. `is_numeric()` is not integer validation, and `(int)` then swallows the difference

Validating a `Set-Cookie` `Max-Age` (`woodev/api/class-api-base.php`, #686):

```php
if ( 'max-age' === $name && is_numeric( trim( $value ) ) ) {
    $max_age = (int) trim( $value );   // ❌
}
```

Measured on this machine:

| value | `is_numeric` | `(int)` |
|---|---|---|
| `0.5` | true | **0** |
| `+1` | true | 1 |
| `1e3` | true | 1000 |

So `Max-Age=0.5` — which RFC 6265 §5.2.2 says must be IGNORED, leaving `Expires` to decide — becomes
`0`, and `0` is the deletion sentinel. **A cookie the server told us to keep until 2031 is deleted on
arrival.** Nothing throws, no test written from the same assumption disagrees, and the failure only
appears against a server that sends a fractional Max-Age.

The shape is general: `is_numeric()` answers "would PHP read this as a number", which is a far wider
set than "is this the integer my format allows". Floats, exponent notation, leading `+`, leading and
trailing whitespace and hex-ish forms all pass it, and the `(int)` cast that follows turns every one
of them into something plausible rather than rejecting it.

```php
if ( 1 === preg_match( '/^-?[0-9]+$/', trim( $value ) ) ) {   // ✅
    $max_age = (int) trim( $value );
}
```

**Rule:** when an external format specifies its own numeric grammar, validate against THAT grammar
with a regex, not against PHP's. `is_numeric()` is the right tool only when you genuinely mean "any
PHP number". And never let `is_numeric()` guard a cast whose result is a sentinel — the truncation
and the sentinel collide silently.

## Related

- [[mutation-sweep-branch-only-false-confidence]] — all three of these die to a value mutant and survive a branch mutant
- [[format-validator-null-strlen-deprecation]] — another null-reaching-a-scalar-API bug in this codebase
- [[a-cast-is-not-a-degradation]] — the same collision from the other side: a cast that satisfies the type and destroys the meaning
