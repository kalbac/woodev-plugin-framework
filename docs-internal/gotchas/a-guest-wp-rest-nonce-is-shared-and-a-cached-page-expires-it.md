# A guest's `wp_rest` nonce is shared by every guest, and a cached checkout page expires it

**Namespace:** `[shipping/location]` · **Discovered:** s195 (24.10.2026), card #1426, measured on the rig against WordPress 7.1 / WooCommerce 11.2.

## What happens

On a checkout served from a page cache, a guest's city suggestions answer 403 `rest_cookie_invalid_nonce`, the `/location/select`
write fails the same way, and the form silently keeps the previous city — while an administrator, who is never served the
cache, sees everything work. The first diagnosis («the nonce belongs to another WooCommerce session») is wrong.

## Root cause

- WooCommerce rebinds a logged-out visitor's nonce to their session only for actions that START with `woocommerce`
  (`WC_Session_Handler::maybe_update_nonce_user_logged_out()`); `wp_rest` is not one. Every guest's `wp_rest` nonce is therefore
  the same string — measured: two guests, one cache-free page each, identical nonce.
- What breaks it is TIME and OWNER: a `wp_rest` nonce is valid for 12–24 h (two ticks), so a page cached longer carries a dead one
  (measured: tick-1 → 200, tick-2 → 403); and a page cached from a logged-in visitor's request carries a nonce minted for that
  user id, which never validates for a guest.
- Core's `rest_cookie_check_errors()` answers 403 for ANY request that carries an invalid nonce — `permission_callback` and
  `__return_true` are never reached. A request with NO nonce at all is simply treated as logged out and goes through.
- `/location/select` and `/forget` check the nonce on purpose (they write); `/suggest` and `/list` are public reads.

## Fix

❌ A read signed with the page-load nonce for everybody — or, the first attempt at a fix, a read UNSIGNED for everybody.

⚠ Unsigned for a LOGGED-IN customer is worse than the 403: core demotes the request to user 0
(`rest_cookie_check_errors()` → `wp_set_current_user( 0 )`), the controller's `bridge_wc_session()` then starts the WooCommerce
session as a guest and `WC_Session_Handler::init_session_cookie()` DESTROYS the customer's numeric session (the cart goes), and
`build_scope()` loses the user-meta chain. Measured on the rig and with the real classes
(`tests/integration/Shipping/LocationReadAuthTest.php`).

✅ `readHeaders()` in `location-cascade.js`: a GUEST reads (`/suggest`, `/list`) with no `X-WP-Nonce`; a logged-in customer's read is
signed. Who is looking comes from `#woodev-location-nonce` — printed by `Checkout_Handler::print_location_nonce_node()` and replaced
on every `update_checkout` by `inject_location_nonce_fragment()`, an AJAX answer computed for THIS request's cookies that a page
cache never serves — and only once the node is `data-woodev-location-live="1"`; the first render (possibly cached for somebody
else) makes no claim, so the read is signed and a refusal takes the refresh-and-retry path. Writes (`/select`, `/forget`) always
sign with the node's nonce; a write or a signed read refused for its nonce retries ONCE after an `update_checkout`; a final
refusal of a write shows the existing «Could not save your choice» notice and clears the stale locality key.

⚠ Testing trap: WooCommerce skips `replaceWith()` for a fragment whose markup equals the previous response's. A probe that plants
a stale node AFTER the first `update_checkout` is never refreshed — simulate the cache by rewriting the served DOCUMENT instead.

## Related

- [a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout](a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout.md) — same family: the node WooCommerce re-renders is not yours to depend on
- [a-shared-select-queue-narrows-a-level-its-response-never-named](a-shared-select-queue-narrows-a-level-its-response-never-named.md) — the `/select` queue the retry sits inside
