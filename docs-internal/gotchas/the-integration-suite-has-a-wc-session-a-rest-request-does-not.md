# The integration suite has a `WC()->session`; a real REST request does not

**Namespace:** `[testing/integration]`
**Found:** s73 (14.08.2026), diagnosing issue #324. Reported by the operator, as a guest, on the rig.

## Root cause

WooCommerce starts the session and cart from exactly one place —
`WooCommerce::init()`, `includes/class-woocommerce.php:891` (WC 10.4.3):

```php
// Classes/actions loaded for the frontend and for ajax requests.
if ( $this->is_request( 'frontend' ) ) {
	wc_load_cart();
}
```

and `is_request( 'frontend' )` (`:604`) excludes **every** REST request:

```php
case 'frontend':
	return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' ) && ! $this->is_rest_api_request();
```

There is no Store API exception in that gate. The Store API has a session because it calls
`wc_load_cart()` itself (`src/StoreApi/Utilities/CartController.php:33`,
`DraftOrderTrait.php:17`/`:29`) — which is precisely the precedent for doing the same thing in
our own route.

`is_rest_api_request()` decides by looking for the REST prefix in `$_SERVER['REQUEST_URI']`.

**Under PHPUnit there is no REST request URI.** The integration suite dispatches through
`rest_get_server()->dispatch( $request )` inside an ordinary WP bootstrap, so
`is_rest_api_request()` is `false`, `is_request( 'frontend' )` is `true`, `wc_load_cart()` runs
at `init`, and `WC()->session` **exists** — for a guest as well.

So an integration test of a route that writes to `WC()->session` as a guest **passes whether or
not the route is broken in production.** `LocationRouteTest::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()`
does exactly that: it sets `wp_set_current_user( 0 )`, dispatches `/location/select`, and has
passed since the day it was written — while the same request from a real browser returned
`persisted: false`. The proof it really does have a session is in the same file: section 5's
`test_persisted_guest_location_record_is_cleared_by_the_teardown_helper()` asserts
`WC()->session->get( … )` is non-null right after that very dispatch, before checking that the
teardown helper clears it.

**The assertion that would have caught #324** reproduces the missing CONTEXT, not the missing
code: set `WC()->session = null` immediately before `rest_get_server()->dispatch()`, then assert
the response's `persisted` and that a session exists afterwards. That reddens before the fix and
passes after it. Asserting `persisted` on its own does neither — it is `true` in the suite either
way.

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

- [[guest-session-write-needs-the-cart-cookie]] — the other half: even with a session, a guest write only persists once the cart cookie exists
- [[built-on-both-sides-with-no-caller-in-the-middle]] — how the bridge came to be wired at two of the three call sites that need it
- [[phpunit-defects-cache-hides-cross-test-session-leaks]] — the same file's other environment-shaped trap
- [[../GOTCHAS.md]] — `[testing/integration]`
