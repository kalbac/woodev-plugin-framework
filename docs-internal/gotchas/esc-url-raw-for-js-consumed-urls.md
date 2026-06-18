# Use `esc_url_raw` (not `esc_url`) for URLs handed to JS / REST

**Topic:** `[admin-ui/*]` · Discovered s20 (2026-06-18), license-page «Продлить» button.

## Problem

`esc_url()` is for **display in HTML** — it HTML-entity-encodes `&` to `&#038;` (and `'`/`"`
etc.). That is correct when the URL is parsed as HTML (the browser decodes `&#038;` back to `&`).

But when a URL is passed to **JavaScript as data** — e.g. inlined into a JSON bootstrap payload
and assigned to a React `href` via the DOM **property** — there is no HTML parsing step, so the
literal `&#038;` is **not** decoded and ends up in the actual navigated URL:

```
https://woodev.ru/checkout/?a=1&#038;b=2&#038;edd_license_key=...   ← broken query string
```

This bit the `renewal_url` field in `get_state()`: `get_renewal_link()` returned `esc_url($url)`,
React rendered `href={ state.renewal_url }` (a property set, not innerHTML), so the «Продлить»
link carried `&#038;` between every query arg.

## ❌ Wrong

```php
// URL destined for a React href / JSON payload, escaped for HTML display:
return esc_url( add_query_arg( $args, $base ) ); // & → &#038; → breaks the JS-set href
```

## ✅ Correct

```php
// Data context (JS/REST/redirect/storage) → esc_url_raw keeps a plain '&':
return esc_url_raw( add_query_arg( $args, $base ) );
```

Rule of thumb: **`esc_url` for HTML output, `esc_url_raw` for data** (DB, redirects, REST/JSON,
anything JavaScript consumes). Links embedded *inside* a sanitized HTML message string (rendered
via `RawHTML`/`wp_kses_post`) still use `esc_url` — there the `&#038;` is correct and decoded.

## Related

- `woodev/licensing/class-license-messages.php` — `get_link_helper( …, $raw )`, `get_renewal_url()`
- `woodev/admin/class-admin-pages.php` — `window.woodevLicenses` JSON bootstrap (consumes `renewal_url`)
- [[license-page-css-bundle-only]] — other license-page rendering gotchas
