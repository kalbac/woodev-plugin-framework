# [testing/integration] WP REST cookie-nonce auth semantics — what `rest_cookie_check_errors()` actually does

**Discovered:** 2026-06-11 (s8, S3.2 — two consecutive integration-test rewrites until the test matched core's real behavior)

## The trap

Integration tests that simulate REST cookie auth fail in non-obvious ways because core's
`rest_cookie_check_errors()` behaves differently than intuition suggests:

1. **It short-circuits to "ok" unless the global `$wp_rest_auth_cookie === true`.** That
   global is set by `rest_cookie_collect_status()` during a real cookie-auth handshake. A
   test that just calls `wp_set_current_user()` never sets it — the nonce is then *never
   checked* and auth "passes" for the wrong reason. Set
   `$GLOBALS['wp_rest_auth_cookie'] = true;` explicitly.
2. **The nonce is read from superglobals**, not from the `WP_REST_Request` object:
   `$_REQUEST['_wpnonce']` or `$_SERVER['HTTP_X_WP_NONCE']`. Setting a header on the
   request object alone does nothing for this filter.
3. **Missing nonce ≠ error.** Core *demotes to anonymous* (`wp_set_current_user( 0 )`)
   and returns `true` — the request then fails later at the permission callback, with
   status from `rest_authorization_required_code()`: **401 for anonymous, 403 for a
   logged-in user**. Asserting a hard-coded 403 for the no-nonce case is wrong (this
   exact assertion failed on CI). Assert the error *code* (e.g. `woodev_license_forbidden`)
   plus `status ∈ [401, 403]`.
4. **Invalid nonce** is the only case that errors directly: `rest_cookie_invalid_nonce`.

## Pattern (see `tests/integration/LicenseRestAuthTest.php`)

```php
$GLOBALS['wp_rest_auth_cookie'] = true;
$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );
// ...dispatch via rest_get_server()->dispatch(); clean both up in tearDown().
```

## Related

- [brain-monkey-function-pollution.md](brain-monkey-function-pollution.md) — the other "passes locally / fails on CI" trap fixed in the same commit (`a6844dc`)
