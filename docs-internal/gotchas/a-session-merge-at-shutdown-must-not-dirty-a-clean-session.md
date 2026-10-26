# Gotcha: [woocommerce/session] — A per-key session merge at `shutdown` must never dirty a session WooCommerce was not going to save

**Namespace:** `woocommerce/session` · **Discovered:** s199 (2026-10-26), #1381
> **Measured on:** WooCommerce 11.2.1 source (`class-wc-session-handler.php`, `abstract-wc-session.php`), the
> framework's `Session_Key_Merger` unit tests; the race itself on the rig (report
> `docs-internal/research/2026-10-26-classic-session-race-1381/report.md`).

## What happens

To protect the framework's own session keys from WooCommerce's whole-row save on < 11.4, a `shutdown`
callback at priority 19 re-reads the session row from `{$wpdb->prefix}woocommerce_sessions` and adopts a
concurrent write through `WC()->session->set()`. The obvious implementation — "adopt whenever the stored
value differs" — turns a request that was never going to save the session into one that does, and that
request then writes its WHOLE stale row: the one adopted key is right and every other key another request
changed meanwhile (`chosen_shipping_methods`, `customer`, a carrier's derived keys) is reverted. The guard
meant to stop one lost write produces many.

## Root cause

`WC_Session_Handler::save_data()` writes only when `$this->_dirty && $this->has_session()`. A request that
only READ the session leaves `_dirty` false and writes nothing, so the row's newer values already stand.
`WC_Session::set()` flips `_dirty` on any changed value — and `set( $key, null )` removes the key through
`__unset()`, which flips it too. There is no accessor for `_dirty`.

Three more facts about the row that a direct read has to respect:

- `_data` holds each value `maybe_serialize()`d, and the row is `maybe_serialize( $_data )` of THAT — so a
  value read from the table must be `maybe_unserialize()`d once more before it is compared with
  `$session->get()` or handed to `set()`.
- `get_session()` answers from `wp_cache_get()` first, and the cache holds what this request loaded — the
  stale copy. Only the table shows the concurrent write.
- a MISSING row is not "every key removed": it is a session saved for the first time (a fresh guest, a guest
  just migrated onto an account) and the request's own values are the only ones there are.

## Fix

```php
// ❌ adopt whenever the row differs — dirties a clean session, WooCommerce writes the whole stale row
foreach ( $keys as $key ) {
	if ( $stored[ $key ] !== $session->get( $key ) ) {   // also compares a serialized string with a value
		$session->set( $key, $stored[ $key ] );
	}
}

// ✅ merge under exactly the conditions save_data() writes under, and read the row as it is stored
if ( class_exists( 'Automattic\WooCommerce\Internal\Session\SessionDataWriter' ) ) { return; } // 11.4+ merges itself
if ( 'WC_Session_Handler' !== get_class( $session ) || ! $session->has_session() ) { return; }
if ( ! ( new \ReflectionProperty( get_class( $session ), '_dirty' ) )->getValue( $session ) ) { return; } // setAccessible() below PHP 8.1
$row = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$table} WHERE session_key = %s", $id ) );
if ( null === $row ) { return; }                                   // no row: nothing another request wrote
foreach ( $keys as $key ) {
	$session->set( $key, isset( $stored[ $key ] ) ? maybe_unserialize( $stored[ $key ] ) : null ); // set() ignores an equal value, null removes
}
```

The framework's implementation is `Woodev\Framework\Shipping\Session_Key_Merger`
(`woodev/shipping-method/class-session-key-merger.php`); its tests pin every gate
(`tests/unit/Shipping/SessionKeyMergerTest.php`).

## Related

- [overlapping-store-api-cart-requests-lose-each-other-s-session-writes](overlapping-store-api-cart-requests-lose-each-other-s-session-writes.md) — the race this merge closes on the classic side
- [guest-session-write-needs-the-cart-cookie](guest-session-write-needs-the-cart-cookie.md) — the `has_session()` half of the same `save_data()` condition
- [woocommerce-checkout-constant-is-not-a-placement-signal](woocommerce-checkout-constant-is-not-a-placement-signal.md) — the other #1381 trap, on the guard side
