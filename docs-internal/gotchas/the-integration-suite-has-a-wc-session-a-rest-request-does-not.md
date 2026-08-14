# The integration suite has a `WC()->session`; a real REST request does not

**Namespace:** `[testing/integration]`
**Found:** s73 (14.08.2026), diagnosing issue #324. Reported by the operator, as a guest, on the rig.

## Root cause

WooCommerce does not start a session or a cart on a plain REST request. `class-woocommerce.php:315`:

```php
if ( $this->is_request( 'admin' ) || ( $this->is_rest_api_request() && ! $this->is_store_api_request() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
```

and `is_rest_api_request()` decides by looking at `$_SERVER['REQUEST_URI']` for the REST prefix.

**Under PHPUnit there is no REST request URI.** The integration suite dispatches through
`rest_get_server()->dispatch( $request )` inside an ordinary WP bootstrap, so
`is_rest_api_request()` is `false`, WooCommerce initialises normally at `init`, and
`WC()->session` **exists** — for a guest as well.

So an integration test of a route that writes to `WC()->session` as a guest **passes whether or
not the route is broken in production.** `LocationRouteTest::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()`
does exactly that: it sets `wp_set_current_user( 0 )`, dispatches `/location/select`, and has
passed since the day it was written — while the same request from a real browser returned
`persisted: false`. The proof it really does have a session is in the same file: section 5 exists
specifically to clean up the guest record that write leaves behind for the next test class.

## What it cost

Issue #324. `Customer_Location_Store::set()` for a guest can only write to `WC()->session`; with
no session the write returned `false`, `/location/select` honestly answered `persisted: false`,
and `Location_Controller::build_scope()` then silently dropped the `within` parameter and searched
the whole country. The customer picked «Жуковский» and was offered addresses in Kazan and
Krasnoyarsk. Nothing in the suite could see it:

- **Unit tests** never have `WC()` at all.
- **Integration tests** always have a session, guest or not.
- **Rig passes** had all been made as a logged-in admin, and for a logged-in user `set()` writes
  user meta and returns `true` without consulting the session at all.

## ❌ Wrong

Treating "the integration suite runs the real WordPress + the real WooCommerce" as "the
integration suite reproduces production". It reproduces the *code*, not the *request context* —
and for session, cart, cookies and `is_rest_api_request()`, the context IS the behaviour.

## ✅ Correct

For anything that depends on a REST request's own context, pin it where the condition is
reproducible — a unit-level seam. `Location_Controller::bridge_wc_session()` is `protected`
exactly so a probe can assert it was called, and issue #324's regression tests assert both that
`/select` calls it and that it calls it **before** the write (bridging after the write leaves the
write with nothing to land in).

When the same layer must also be checked end to end, check it **as the role that has the bug**:
guest and logged-in are two different code paths through `Customer_Location_Store::set()`, and
only one of them can fail this way.

## Related

- [[guest-session-write-needs-the-cart-cookie.md]] — the other half: even with a session, a guest write only persists once the cart cookie exists
- [[built-on-both-sides-with-no-caller-in-the-middle.md]] — how the bridge came to be wired to two of the three routes that need it
- [[phpunit-defects-cache-hides-cross-test-session-leaks.md]] — the same file's other environment-shaped trap
- [[../GOTCHAS.md]] — `[testing/integration]`
