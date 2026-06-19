# `wp_nonce_url()` HTML-encodes `&` → breaks a URL consumed by JS/JSON

> Namespace: `[admin-ui/*]` · Discovered: s24 (2026-06-19), rig-debugged. Specialization
> of [[esc-url-raw-for-js-consumed-urls]].

## Problem

`get_connect_url()` built the connect URL with `wp_nonce_url( $url, $action )` and put
the result into the `window.woodevExtensions` JSON bootstrap as `account.connectUrl`,
which React set as an `<a href>`. Clicking it did **nothing** — the page silently
re-rendered.

Root cause: **`wp_nonce_url()` runs its result through `esc_html()`**, so it returns
`…?page=woodev-extensions&amp;woodev-account-connect=1&amp;_wpnonce=…` (the `&` is
HTML-entity-encoded). That string is correct for HTML *output*, but it was consumed as
**data** (JSON → React `href`), which is **not** HTML-parsed. The browser navigated to
the literal `&amp;` URL, so the query keys parsed as `amp;woodev-account-connect` and
`amp;_wpnonce` → `isset( $_GET['woodev-account-connect'] )` was **false** → the handler
no-op'd and just re-rendered the page.

## Fix

Build the nonced URL WITHOUT `wp_nonce_url`'s `esc_html` pass, and `esc_url_raw` it
(data context — does not entity-encode `&`):

```php
$url = add_query_arg(
    array(
        'page'                   => 'woodev-extensions',
        'woodev-account-connect' => '1',
        '_wpnonce'               => wp_create_nonce( 'woodev_account_connect' ),
    ),
    admin_url( 'admin.php' )
);
return esc_url_raw( $url ); // clean '&', JSON/JS-safe.
```

## Rule

`wp_nonce_url()` is for URLs printed into **HTML**. For a nonced URL that goes into
**JSON / a REST payload / a React prop / a redirect / storage**, add the nonce as a
plain `add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url )` and `esc_url_raw`
it — never `wp_nonce_url`.

(Inverse note: inside an HTML attribute — e.g. EDD's `<input value="<?php echo esc_url(
$redirect ); ?>">` — `esc_url`/`&#038;` is CORRECT, because the browser HTML-decodes the
attribute back to `&` when it reads the form field.)

## Related

- [[esc-url-raw-for-js-consumed-urls]] — the general rule (this is the `wp_nonce_url` case).
- [[rest-endpoint-not-for-browser-cookie-auth]] — the other s24 rig bug (sibling).
