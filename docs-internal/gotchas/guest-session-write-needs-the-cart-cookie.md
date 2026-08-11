# A guest's `WC()->session->set()` can silently not persist — a logged-in developer never sees it

**Namespace:** `[woocommerce/*]`
**Discovered:** 2026-08-11 (s65, while answering #178)

## The trap

`WC()->session->set( $key, $value )` looks unconditional. It is not. The write lands in memory
and raises a dirty flag; persistence happens on `shutdown`, and only under a condition that
differs between a guest and a logged-in customer (WooCommerce 11.0.0,
`class-wc-session-handler.php`):

```php
public function has_session() {
    return isset( $_COOKIE[ $this->_cookie ] ) || $this->_has_cookie || is_user_logged_in();
}

public function save_data( $old_session_key = '' ) {
    if ( $this->_dirty && $this->has_session() ) { /* INSERT … */ }
}
```

`is_user_logged_in()` short-circuits the condition, so **for a logged-in user every write always
persists**. For a guest it persists only once the WooCommerce session cookie exists — and
WooCommerce sets that cookie on `woocommerce_set_cart_cookies` (i.e. a non-empty cart) or on the
`order-pay` endpoint, not merely because you wrote to the session.

So a guest with an empty cart writes to the session, gets no error, and loses the value at
shutdown. The developer, logged into wp-admin, cannot reproduce it.

## Where it does and does not bite

| Situation | Guest write persists? |
|---|---|
| Anything on the checkout or cart page | Yes — the cart is non-empty, so the cookie exists |
| A city/locality picker on a product or catalog page, an empty cart | **No** |
| Geolocation defaults written on first visit | **No** — and it re-geolocates on every page view |
| Logged-in customer, anywhere | Yes, always |

This is the mechanism behind the operator's recollection that early СДЭК versions "had problems
with `WC()->session`" for the customer's chosen city. It is also why
`WC_Edostavka_Customer_Location_Data` and `Customer_Delivery_Point_Data` (Почта РФ) mirror
`WC_Customer`'s dual store: user meta when `get_id() > 0`, session otherwise.

## Other differences worth knowing, same file

- Expiry: guest `2 * DAY_IN_SECONDS`, logged-in `WEEK_IN_SECONDS` (`set_session_expiration():403`).
- Identity: guest `wc_rand_hash( 't_' )` in a cookie, logged-in the user id (`generate_customer_id():460`).
- `wp_logout` **destroys** the session outright (`init_hooks()`), so a session-only value does not
  survive a logout even for a logged-in customer.
- Guest → login does NOT lose data: WooCommerce clones the guest session into the user session
  (`clone_session_data():204-242`).

## Rule

Before choosing `WC()->session` for customer data, ask WHERE the value is first written. If any
write can happen with an empty cart, the session is the wrong store on its own — mirror
`WC_Customer`'s dual store, or force the cookie deliberately. If every write happens on the
cart/checkout, the session is correct and a dual store is over-engineering: it would make a
per-order choice outlive the order.

## Related
- [[custom-checkout-field-is-empty-on-reload-by-construction]]
- `docs-internal/specs/2026-08-11-sp5-pickup-selection-persistence-design.md` §2
