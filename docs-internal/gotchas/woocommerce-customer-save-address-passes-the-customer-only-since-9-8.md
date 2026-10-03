# gotcha: `woocommerce_customer_save_address` passes the customer object only since WC 9.8 — and `WC()->customer` there is stale

**Namespace:** `[woocommerce/*]`
**Discovered:** s149 (2026-10-03), card #332 (Codex critic)

## What happened

The My Account save handler read the saved city from the hook's 4th argument and fell back to `WC()->customer`. The critic, reading WC's
source, found: `do_action( 'woocommerce_customer_save_address', $user_id, $address_type, $address, $customer )` gained `$address` and
`$customer` only in **9.8**; before that the hook passes `( $user_id, $load_address )`. We support WC ≥ 7.0. And `WC()->customer` is a
PRE-save copy: `WC_Form_Handler::save_address()` saves its own `new WC_Customer( $user_id )`, so the session's object still holds the old
city — comparing against it keeps or forgets the wrong record.

## ❌ Wrong

```php
public function on_save( $user_id, $type, $address = [], $customer = null ) {
    $customer = $customer ?: WC()->customer; // < 9.8: stale pre-save copy
}
```

## ✅ Correct

```php
public function on_save( int $user_id, string $type, array $address = [], ?object $customer = null ): void {
    if ( ! $customer instanceof WC_Customer ) {
        $customer = new WC_Customer( $user_id ); // already saved when the hook fires
    }
}
```

## Related

- [a-logged-in-customer-s-wc-session-outranks-the-profile](a-logged-in-customer-s-wc-session-outranks-the-profile.md) — the session/profile split behind the stale copy
- `../gotcha-index/woocommerce.md`
