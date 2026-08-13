# A WordPress HTTP response header can be an ARRAY, and `Set-Cookie` usually is

**Namespace:** `[api/http-headers]` · **Discovered:** s72 (2026-08-14), adversarial review of PR #315 (#300)

## The shape

`wp-includes/class-wp-http-requests-response.php` collapses single-valued headers and keeps
multi-valued ones as arrays:

```php
foreach ( $this->response->headers->getAll() as $key => $value ) {
    if ( count( $value ) === 1 ) { $converted[ $key ] = $value[0]; }  // string
    else                         { $converted[ $key ] = $value; }     // ARRAY
}
```

`Woodev_API_Base::handle_response()` stores that result as-is, so `$this->response_headers` is
`array<string, string|array<string>>` — not `array<string, string>`, which is what its docblock and
every consumer assumed.

**A session-establishing response is the normal case for the array shape**: servers send one
`Set-Cookie` line per cookie, so the header a security fix cares most about is precisely the one
most likely to be an array.

## What it broke

The response-header sanitizer masked values with `str_repeat( '*', strlen( (string) $value ) )`.
Given an array that is two defects at once:

1. **`Array to string conversion` warning on every affected response.** This base class is shared by
   the payment-gateway tree; under `WP_DEBUG_DISPLAY` the warning is emitted into the output stream
   and corrupts AJAX/REST JSON during checkout. Under `WP_DEBUG_LOG` it floods `debug.log`.
2. **The broadcast payload changes type** — `headers['set-cookie']` goes from `array` to `string`.
   The action `woodev_{api_id}_api_request_performed` is an installed-site data contract, and a
   subscriber doing `foreach ( $payload['headers']['set-cookie'] as $c )` now iterates a string.

The secret itself did not leak (the value was replaced), which is exactly why this would have
shipped: the security assertion passed while the mechanism was wrong.

## The rule

Mask element-wise and preserve the container:

```php
$headers[ $name ] = is_array( $value )
    ? array_map( static fn( $item ): string => str_repeat( '*', strlen( (string) $item ) ), $value )
    : str_repeat( '*', strlen( (string) $value ) );
```

More generally: **when you normalize a container, check the VALUES too.** The PR that introduced this
had "measured rather than assumed" in its description and it was true — the measurement established
that `handle_response()` converts the `Requests_Utility_CaseInsensitiveDictionary` into a plain
array, and stopped there. The container was right; the values were never looked at.

## Related

- [[array-cast-of-get-states-false-is-not-empty]] — the other "the type is not what the docblock says"
  finding in this codebase
- `woodev/api/class-api-base.php` → `handle_response()`, `mask_secret_headers()`,
  `get_sanitized_response_headers()`
- #318 — the same raw response is still written into a `wp_options` transient by the cacheable base
