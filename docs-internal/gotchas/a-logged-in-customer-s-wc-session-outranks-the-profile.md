# gotcha: a logged-in customer's WooCommerce session outranks the profile — a probe that edits only user meta reads stale data

**Namespace:** `[rig/*]`
**Discovered:** s149 (2026-10-03), card #1075

## What happened

To reproduce «the customer has a city saved in the profile but no location record», the coordinator set user meta `shipping_city =
Бутово` for `admin` and deleted `woodev_customer_location`. The checkout still showed «Москва» and the fix under test looked broken.

It was not. A logged-in customer's WooCommerce session is stored SERVER-SIDE (`wp_woocommerce_sessions`, `session_key` = the user id) and
lives across browser contexts and visits. `WC_Customer_Data_Store_Session` loads the session's `customer` array first — here it still held
`shipping_city = Москва` — and our location record lives in that session too (`woodev_customer_location` key), not only in user meta.

## ❌ Wrong

```bash
wp user meta update 1 shipping_city "Бутово"   # the session still says «Москва»
wp user meta delete 1 woodev_customer_location # the session still holds the record
```

## ✅ Correct

Reset the session row together with the meta, then probe in a fresh browser context:

```php
global $wpdb;
$wpdb->delete( 'wp_woocommerce_sessions', [ 'session_key' => '1' ] );
delete_user_meta( 1, 'woodev_customer_location' );
update_user_meta( 1, 'shipping_city', 'Бутово' );
```

Back the session row up first if the rig's state matters (`get_var( "SELECT session_value …" )`) and put it back afterwards.

## Related

- [the-integration-suite-has-a-wc-session-a-rest-request-does-not](the-integration-suite-has-a-wc-session-a-rest-request-does-not.md) — another place where WC's session decides what a test sees
- `../gotcha-index/rig.md`
