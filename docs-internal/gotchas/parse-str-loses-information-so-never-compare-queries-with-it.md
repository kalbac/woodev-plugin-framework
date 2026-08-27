# `parse_str()` loses information, so never compare two query strings through it

**Namespace:** `[php/*]`
**Found:** s98 (27.08.2026), on the merged #396 fix, by a critic pass. Two false positives, both reproduced.

## The trap

Matching a request against a declared query fragment looks like a job for `parse_str()`. It is
not: `parse_str()` is a *deserialiser for PHP*, not a faithful reader of a query string, and
every liberty it takes **widens** a subset match into a false positive.

```php
parse_str( 'wc-settings&tab=shipping&a[]=1', $out );
// $out === [ 'wc-settings' => '', 'tab' => 'shipping', 'a' => [ '1' ] ]
```

1. **It builds arrays.** `a[]=1` becomes `a => ['1']`. Filter non-scalars out — the obvious
   tidy-up — and the argument disappears from the *declaration*, so a request that does not
   carry it **at all** matches. Measured: the declaration above reduced to `page` + `tab`, and
   `page=wc-settings&tab=shipping` then matched it.
2. **It normalises keys.** `.` and a space become `_`. So `a.b=1` and `a%20b=1` both become
   `a_b=1` and match each other, despite being different arguments.
3. **A leading bare segment is not what you think.** `wc-settings&tab=…` — the shape providers
   actually register — reads as an empty-valued argument *named* `wc-settings`, not as
   `page=wc-settings`.

## ❌ Wrong

```php
$parsed = [];
parse_str( $query, $parsed );

foreach ( $parsed as $k => $v ) {
	if ( is_scalar( $v ) ) {           // <- this line is the false positive
		$args[ (string) $k ] = (string) $v;
	}
}
```

## ✅ Correct

Split by hand; keep keys verbatim; decode each half yourself.

```php
foreach ( explode( '&', $query ) as $index => $segment ) {
	if ( 0 === $index && false === strpos( $segment, '=' ) ) {
		$segment = 'page=' . $segment;      // the bare leading segment IS the page value
	}

	$parts            = explode( '=', $segment, 2 );
	$args[ rawurldecode( $parts[0] ) ] = isset( $parts[1] )
		? rawurldecode( str_replace( '+', ' ', $parts[1] ) )
		: '';
}
```

An array-style argument then stays the literal key `a[]` and must be present in the request to
match — which is what the contract said all along.

## Related

- [a-test-that-manufactures-its-own-precondition-never-tests-the-producer](a-test-that-manufactures-its-own-precondition-never-tests-the-producer.md) — the other finding from the same critic pass
