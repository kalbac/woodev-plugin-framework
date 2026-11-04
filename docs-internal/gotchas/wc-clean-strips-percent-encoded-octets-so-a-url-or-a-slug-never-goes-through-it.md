# Gotcha: [woocommerce/sanitize] — `wc_clean()` / `sanitize_text_field()` strips percent-encoded octets, so a URL or a `sanitize_title()` slug must never go through it
> Tags: wc_clean, sanitize_text_field, sanitize_title, urls, cyrillic | Session: s215 (04.11.2026)

## What happens

A value that contains `%XX` sequences comes out of `wc_clean()` with the sequences removed. Two real losses in the
«Отслеживание доставки» 2.0.0 plugin (found by the Codex critic, reproduced against WordPress 6.6.2 formatting):

- a merchant's own-carrier link template `https://example.org/t?return=foo%2Fbar&n={tracking}` was saved as `…return=foobar…`
  (PR #2, `Items_Store::add()` ran the URL through `wc_clean()` before `esc_url_raw()`);
- `sanitize_title( 'Курьер' )` is the slug `%d0%ba%d1%83%d1%80%d1%8c%d0%b5%d1%80`; looked up through a `normalize()` that calls
  `wc_clean()`, it becomes the empty string and the carrier cannot be found (PR #2 `Provider_Catalog::find()`, PR #6 for v1
  API-written items, whose `tracking_provider` is exactly such a slug on a ru_RU shop).

## Root cause

`sanitize_text_field()` (which `wc_clean()` delegates to for scalars) removes percent-encoded octets on purpose — it is a
text-field sanitizer, not a URL or slug sanitizer. Anything that is ALREADY an encoded representation loses its content
silently, and a test double for `wc_clean()` that only trims and strips tags hides it.

## Fix

❌ **Wrong:**

```php
$url  = wc_clean( $args['url'] );          // 'foo%2Fbar' -> 'foobar'
$slug = wc_clean( sanitize_title( $name ) ); // '%d0%ba…' -> ''
```

✅ **Correct:**

```php
$url  = esc_url_raw( trim( (string) $args['url'] ) );   // a URL goes through the URL sanitizer only
$slug = sanitize_title( $name );                         // a slug is compared as-is, never re-cleaned
```

A lookup that must accept both a plain label and a stored encoded slug compares the encoded form in its own pass and keeps
it OUT of the lossy normalised passes. A regression test must use the real WordPress function (or a faithful copy), with a
pre-encoded component and an upper-case-hex Cyrillic slug.

## Related

- [php/stdlib traps that survive tests](php-stdlib-traps-that-survive-tests.md) — the same family: a sanitizer that passes every test double.
- [A cast is not a degradation](a-cast-is-not-a-degradation.md) — degrade to the pre-filter value, do not coerce.
