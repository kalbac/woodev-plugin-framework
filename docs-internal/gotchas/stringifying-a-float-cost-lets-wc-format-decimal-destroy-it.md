# Gotcha: [woocommerce/shipping] — Stringifying a float cost hands `wc_format_decimal()` a value it destroys: `1.0E+20` becomes `1.02`

> Tags: woocommerce, shipping, rates, php, float | Session: s117

## What happens

A shipping cost arrives as a number — `array_sum()` returns one, and so does every price a carrier
computes. Somewhere on the way to `add_rate()` it gets normalised to a string, because the DTO or
the docblock says the cost is a string:

```php
$this->cost = (string) $cost;   // ❌ looks harmless, is not
```

For ordinary money it is harmless: `(string) 1234.56` is `'1234.56'`. Outside a narrow range PHP
switches to scientific notation, and then WooCommerce quietly returns the wrong price:

| supplied | `(string)` | what `wc_format_decimal()` makes of the STRING |
|---|---|---|
| `1.0e20` | `'1.0E+20'` | **`1.02`** |
| `0.00000001` | `'1.0E-8'` | **`1`** |

No exception, no notice, nothing in any log. A six-figure delivery is quoted at one rouble two
kopecks.

## Root cause

`wc_format_decimal()` branches on the type it was given, and the branches are not equivalent
(WooCommerce 11.0.1):

```php
if ( ! is_float( $number ) ) {
	// string path: strip everything that is not a digit, a dot or a minus
	$number = preg_replace( '/\.(?![^.]+$)|[^0-9.-]/', '', wc_clean( $number ) );
}
...
} elseif ( is_float( $number ) ) {
	// float path: sprintf() with the rounding precision — scientific notation handled correctly
	$number = str_replace( $decimals, '.', sprintf( '%.' . wc_get_rounding_precision() . 'f', $number ) );
}
```

The string path has no idea what `E` means. It deletes the `E` and the `+`, leaving `1.020`, and
`floatval()` reads `1.02`. Handed the same value as a **float**, the other branch formats it
correctly.

So the cast does not merely fail to help — it takes a value WooCommerce would have handled and
turns it into a different number. That is the distinction in
[a-cast-is-not-a-degradation](a-cast-is-not-a-degradation.md), arriving from the other direction:
there, a cast hid garbage; here, a cast destroyed good data.

Two floats deserve separate treatment because they pass every type check: `NAN` and `INF` are
`is_float()`, carry no amount, and `(string) NAN` additionally raises a PHP 8 warning
(*unexpected NAN value was coerced to string*).

## Fix

**Do not stringify a numeric cost. Pass the number through** and let `add_rate()` — which totals it
and calls `wc_format_decimal()` itself — do the formatting:

```php
if ( is_array( $cost ) || is_string( $cost ) || is_int( $cost ) ) {
	return $cost;
}

if ( is_float( $cost ) && is_finite( $cost ) ) {
	return $cost;          // ✅ the float path in wc_format_decimal() is the correct one
}

// NAN, INF, objects, bools, null: no amount at all — degrade and report, never cast.
_doing_it_wrong( __METHOD__, $message, '2.0.2' );
return '0';
```

`is_finite()` is the guard that separates a usable float from `NAN`/`INF`; a bare `is_float()` lets
both through.

Widening the declared type is part of the fix, not an afterthought:
`Shipping_Rate::get_cost()` returns `string|int|float|array`, and its docblock says why the number
is kept a number.

## How it was found

A Codex critic pass on PR #769 raised it as a suspected precision issue; the sharper mechanism —
that `wc_format_decimal()` handles the float and destroys the string — came out of verifying the
claim rather than accepting it. Both halves were measured: `php` for the `(string)` conversions,
the WooCommerce 11.0.1 source in the rig container for the two branches.

## Related

- [a-cast-is-not-a-degradation](a-cast-is-not-a-degradation.md) — the rule this is the mirror image of
- [wc-add-rate-ignores-the-rate-description-and-delivery-time](wc-add-rate-ignores-the-rate-description-and-delivery-time.md) — the other `add_rate()` trap from the same hour
- [php-stdlib-traps-that-survive-tests](php-stdlib-traps-that-survive-tests.md) — the same family of conversions that pass tests and fail in production
- Card #766 · PR #769
