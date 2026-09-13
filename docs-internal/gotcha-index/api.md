# Gotcha index — [api/*] API layer

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [api/error-boundary] **A vendor's REFUSAL is not an exception: `handle_response()` throws only on `is_wp_error()` (transport). Each plugin draws the semantic line itself in `do_post_parse_response_validation()` — an empty stub in the framework, overridden by all six reference plugins.** → [a-carrier-refusal-is-not-an-api-exception](../gotchas/a-carrier-refusal-is-not-an-api-exception.md) (s124)
- [api/http-headers] **A WordPress response header can be an ARRAY, and `Set-Cookie` usually is.** → [wp-http-duplicate-headers-arrive-as-arrays](../gotchas/wp-http-duplicate-headers-arrive-as-arrays.md) (s72)
- [api/rest-not-for-browser-auth] **A REST endpoint can't back a browser-facing screen that relies on cookie login.** → [rest-endpoint-not-for-browser-cookie-auth](../gotchas/rest-endpoint-not-for-browser-cookie-auth.md) (s24)
- [api/catalog-fetch-timeout] **«Плагины» catalog fetch uses the default 5s timeout — cold cache fails on a slow issuer.** → [extensions-catalog-fetch-5s-timeout](../gotchas/extensions-catalog-fetch-5s-timeout.md) (s25; fixed s26)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
