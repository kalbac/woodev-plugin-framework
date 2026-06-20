# «Плагины» catalog fetch uses the default 5s timeout — cold cache fails on a slow issuer

**Topic:** `[api/*]` · **Discovered:** s25 (2026-06-20)

## Symptom

On the rig, with the catalog transient cleared (`woodev_extensions_catalog_v2`),
the «Плагины» page rendered **«Не удалось загрузить каталог, попробуйте позже.»**
(`stale: true`). The account `/purchases` call worked fine on the same page.

## Root cause

`Woodev_REST_API_Extensions::remote_json()` calls `wp_safe_remote_get( $url )` with
**no `timeout` arg → WordPress default 5 seconds**. The issuer's
`edd-api/v2/products/?number=-1` (woodev-core enrichment over ~26 products, ~252 KB)
takes **~8.6 s** cold → cURL error 28 (timeout) → empty products → `stale: true`,
uncached, so it retries-and-fails every load until the endpoint is warm.

Why `/purchases` worked: `Woodev_Account_Connection::request()` sets `'timeout' => 15`.

Normally masked in production by the **week-long** catalog transient — only bites on
a cold cache against a slow store (rig, or a woodev.ru blip).

## ✅ Workaround (rig)

Warm the cache with a longer timeout, then load the page (cache hit):

```php
add_filter( 'http_request_timeout', static fn() => 40 );
( new Woodev_REST_API_Extensions() )->get_items(); // populates the transient
```

## Fix (done — s26, 2026-06-20)

`Woodev_REST_API_Extensions::remote_json()` now passes
`array( 'timeout' => self::FETCH_TIMEOUT )` (`FETCH_TIMEOUT = 20`) to
`wp_safe_remote_get()` — both the products and categories fetches. Covered by
`ExtensionsRestControllerTest::test_catalog_fetch_uses_extended_timeout`.

## Related

- [[wp-safe-remote-request-local-rig]] — other rig transport traps (SSRF host/port allowances)
