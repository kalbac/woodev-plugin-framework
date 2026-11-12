# Gotcha: [api/rest] `@wordpress/api-fetch` always appends `?_locale=user`, so a strict query validator rejects every call

**Discovered:** s229 (12.11.2026), #1747 / PR #1748.

## What happens

The marketplace mappings screen showed «Форма отправлена в неверном виде» (HTTP 400) on every load. Direct REST
tests passed, because they never send the key.

## Root cause

`@wordpress/api-fetch` adds `_locale=user` to every request. The mappings routes ran a `Query_Validator` that rejects
unknown query parameters, and `_locale` was unknown to it.

## Fix

Any route that rejects unknown query params must allow WordPress core's reserved keys. The framework has them in
`Query_Validator::CORE_QUERY_KEYS`.

```php
// ❌ only the route's own keys
$allowed = array( 'page', 'per_page', 'search' );

// ✅ plus core's reserved keys
$allowed = array_merge( array( 'page', 'per_page', 'search' ), Query_Validator::CORE_QUERY_KEYS );
```

A route test for a strict validator should send `_locale=user` once, as the browser does.

## Related

- [a-rest-arg-default-outside-its-own-minimum-rejects-every-request](a-rest-arg-default-outside-its-own-minimum-rejects-every-request.md) — the other REST-validation trap
