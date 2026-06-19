# A REST endpoint can't back a browser-facing screen that relies on cookie login

> Namespace: `[api/*]` · Discovered: s24 (2026-06-19), rig-debugged with the operator.

## Problem

The account-connector's `/oauth/authorize` approval screen was first registered as a
**REST route** (`register_rest_route`, `permission_callback => __return_true`) and
gated on `is_user_logged_in()`. When the browser navigates to a REST URL directly
(a full-page redirect, **no `X-WP-Nonce` header**), WordPress's REST cookie
authentication does **not** treat the request as logged in — REST requires the
`wp_rest` nonce for cookie auth, so `is_user_logged_in()` returns **false** even
though the user has a valid `wordpress_logged_in_*` cookie. Result: the login gate
redirected to `wp-login.php`, and after a successful login it returned to the same
REST URL where `is_user_logged_in()` was *still* false → **endless login loop**.

Symptom on the rig: "logged in on the issuer in another tab, but the authorize page
keeps asking me to log in, and after login it silently redirects to itself."

## Fix — model it on WooCommerce `WC_Auth`

A browser-facing auth/approval screen must run in the **normal WP request context**,
not REST. `WC_Auth` (`woocommerce/includes/class-wc-auth.php`) registers a
rewrite/query-var endpoint and handles it on **`parse_request`** — there
`is_user_logged_in()` / `current_user_can()` honor the cookie for a plain navigation.

We did the same: the authorize screen is now a front-end
`?woodev_account_authorize=1` request, dispatched from the connector plugin on
`add_action( 'parse_request', …, 0 )`, reading its params from the superglobals
(`$_GET`/`$_POST`/`$_SERVER['REQUEST_METHOD']`) instead of a `WP_REST_Request`. The
signed, server-to-server routes (`request_token` / `access_token` / `me` /
`invalidate`) stay REST — they carry the HMAC signature and never need a cookie.

## Rule

- REST routes are for **programmatic, signed/nonce'd** requests. Never gate a
  **browser-navigated** screen on `is_user_logged_in()` inside a REST callback.
- Browser-facing login/approval screens → a `parse_request` (or `template_redirect`)
  query-var endpoint in normal context (the WC_Auth pattern).

## Related

- [[rest-cookie-nonce-auth-semantics]] — the underlying REST cookie-nonce rule.
- [[wp-nonce-url-esc-html-breaks-js-urls]] — the other s24 rig bug (sibling).
