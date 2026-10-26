# Gotcha: [woocommerce/checkout] — `woocommerce_checkout_process` validates the form while `WC()->customer` still holds the PREVIOUS address; the posted address is synced only afterwards

**Namespace:** `woocommerce/checkout` · **Discovered:** s199 (2026-10-26), #1381 round-2 critic (Codex gpt-6.1-sol)
> **Measured on:** WooCommerce 11.2.1 source — `class-wc-checkout.php:1384` (`woocommerce_before_checkout_process`),
> `:1392` (`woocommerce_checkout_process`), `:1399` (`update_session( $posted_data )` → `set_customer_address_fields()`
> → `WC()->customer->save()`), `:1409` (`validate_checkout()`). Reproduced in
> `LocationServiceTest::test_custom_settlement_guard_accepts_the_picked_city_at_placement_while_the_customer_still_holds_the_old_one`.

## What happens

The #1381 placement guard made a stored city record "stale" when the customer's native shipping city does not
name it, and classic order placement was detected correctly (`did_action( 'woocommerce_before_checkout_process' )`).
Yet with the record AND the posted form both saying Подольск, `Checkout_Handler::guard_custom_settlement()` —
which runs on `woocommerce_checkout_process` and reads the record through that gate — got `null` back and refused
the order with «The selected locality is not in the list». The customer object still said Казань: a slow
`update_order_review` had saved it last, and the per-key session merge deliberately leaves WooCommerce's own
`customer` key alone. Copying the right address afterwards does not remove the notice.

## Root cause

`WC_Checkout::process_checkout()` runs its validation hooks BEFORE it copies the posted address anywhere:

```text
woocommerce_before_checkout_process   ← "an order is being placed" (first action)
woocommerce_checkout_process          ← every plugin validator runs HERE
get_posted_data()
update_session( $posted_data )        ← only NOW billing_*/shipping_* reach WC()->customer and the session
validate_checkout()                   ← core's own field validation, rates, woocommerce_after_checkout_validation
```

So on both validation hooks `WC()->customer->get_shipping_city()` is whatever the last `update_order_review`
(or the last Store API sync, or the saved account address) left there — NOT the form being submitted. A rule
that compares "the form" against anything must read the form from `$_POST` (cleaned as `get_posted_data()`
cleans it) and choose the column WooCommerce will ship to (`shipping_*` only when `ship_to_different_address`
is posted and `wc_ship_to_billing_address_only()` is false — `Address_Target::resolve()` is that rule).

## Fix

```php
// ❌ on woocommerce_checkout_process this is the PREVIOUS address, not the submitted one
$city = WC()->customer->get_shipping_city();

// ✅ the submitted delivery city, same column update_session() is about to copy
$section = Address_Target::resolve( ! empty( $_POST['ship_to_different_address'] ) );
$city    = wc_clean( (string) wp_unslash( $_POST[ "{$section}_city" ] ?? '' ) );
```

`Location_Service::native_address_value()` does exactly this during classic placement and keeps reading
`WC()->customer` in a Store API request, where the request body IS the synced customer
(`woodev/shipping-method/location/class-location-service.php`). Unit tests that stand in for placement must set
`$_POST` as well as fire the action; a double of `WC()->customer` alone proves nothing about this phase.

## Related

- [woocommerce-checkout-constant-is-not-a-placement-signal](woocommerce-checkout-constant-is-not-a-placement-signal.md) — how placement is detected; this one is about WHAT is authoritative once it is
- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — on the Store API side the customer IS synced before any framework code reads it
- [a-session-merge-at-shutdown-must-not-dirty-a-clean-session](a-session-merge-at-shutdown-must-not-dirty-a-clean-session.md) — why the merge leaves WooCommerce's `customer` key stale in the first place
