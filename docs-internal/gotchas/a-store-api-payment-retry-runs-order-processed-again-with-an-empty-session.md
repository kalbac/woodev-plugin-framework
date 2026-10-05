# Gotcha: [woocommerce/store-api] — A Store API payment retry runs `order_processed` again, with an empty session
> Tags: woocommerce, store-api, checkout, pickup, retry | Session: s152, SP-11 C-3 (#1090)
> **Verified against:** local WooCommerce 11.1.0 source (`Routes/V1/Checkout.php`, `Utilities/DraftOrderTrait.php`) and `StoreApiPickupFlowTest`.

## What happens

A block-checkout order whose payment failed is paid on the second attempt — and ends up with a
BLANK pickup-point id, although the first attempt stored it and the pre-payment validation passed.
Separately, a customer who changes the cart after a failed payment and then edits the address on
the checkout loses the pickup point of the FAILED order, which is still payable from My Account.

## Root cause

Three facts of the retry path, none of them visible from a single request:

1. **`woocommerce_store_api_checkout_order_processed` fires on EVERY `POST /checkout`**, retries
   included — WooCommerce reuses the pending/failed order (`get_draft_order()`), it does not skip
   the hook. Our first-attempt writer already cleared the session's selection, so the second run
   derives its «posted» values from an empty session.
2. **`Checkout_Handler::sanitize_posted_data()` resolves an ABSENT field to `''`** and
   `persist_values()` writes every key it is given. Absent is therefore not «leave alone» — it is
   «write a blank».
3. **The session keeps `store_api_draft_order` after the cart has moved on.** WooCommerce stops
   reusing a pending/failed order once the cart hash differs (`is_valid_draft_order()`), but the id
   stays in the session until the cart is emptied. Reading «the session's draft» by id alone
   returns an order that is no longer this cart's.

4. **The point id on the retry order is not a confirmation.** WooCommerce rewrites the reused
   order's shipping line and address from the cart, and the id in its meta survives both. Accepting
   a retry on that id alone (the first version of this fix did) lets a point confirmed for one
   street — or for instance 7 of a method — pay for another street, or for instance 8: the handler
   still «owns» the bare method id, and the session's memory, which held the binding, is empty.

A failed payment does not itself refresh the cart. The Checkout Blocks `onCheckoutFail` event now
uses WooCommerce's customer/rates refresh path, so the next cart answer includes the existing
`woodev-shipping` snapshot. When session memory is empty, that snapshot is restored from the
session's reusable retry order only if its saved point, full rate id and shipping destination still
match the placed confirmation and current cart. The restored snapshot re-binds identity only: the
carrier/payment compatibility verdict still runs in `validate_order` when the shopper places the
order. If the shopper changes the rate or address and then returns to the original values before
any checkout POST, the snapshot appears again; `validate_order` accepts that same restored state.
The same guarded snapshot also restores on a later payment-method refresh or reload.

## Fix

```php
// ❌ Only the session: on a retry the field is absent → sanitised to '' → written over the id.
$point_id = $this->remembered_point_id_for_order( $order );

// ✅ On a retry the order itself carries the point; contribute it for the method the handler owns.
if ( '' === $point_id && $this->owns_store_api_rate( self::order_shipping_method( $order ) ) ) {
    $point_id = $this->persisted_point_id( $order );
}
```

```php
// ❌ Any order the session still names.
return $draft instanceof \WC_Order ? $draft : null;

// ✅ Only the order WooCommerce itself would reuse (mirror DraftOrderTrait::is_valid_draft_order()).
if ( $draft->has_status( 'checkout-draft' ) ) { return $draft; }
return $cart && $draft->needs_payment() && $draft->has_cart_hash( $cart->get_cart_hash() ) ? $draft : null;
```

```php
// ❌ The bare id on the order: says nothing about the rate instance and the destination.
$point_id = Woodev_Order_Compatibility::get_order_meta( $order, $field_id );

// ✅ The confirmation the order was PLACED with. The writer keeps it when it empties the memory
//    (Pickup_Selection::remember_placed(), session key `<scope key>_placed`, one order); a retry
//    is accepted only while point id, FULL rate id, destination fingerprint and locality all
//    still match, and an order-backed PATCH/POST that finds them moved drops the order's point.
$snapshot = $handler->store_api_placed_confirmation( $order, $context['rate_id'], $context['address_key'] );
```

A retry test must run the WHOLE second `POST`: `update_order` → `validate_order` → BOTH
`order_processed` callbacks (priority 10, then 20). A test that stops at validation passes on the
broken code. Reset the handler's per-request memo between simulated requests, or the carrier-request
counts are meaningless.

The cart snapshot has a separate retry fallback: it must use the reusable order WooCommerce would
actually retry (`checkout-draft`, or pending/failed with the current cart hash), and require the
placed confirmation to match that order's point, full method instance, locality and current shipping
destination. An absent/stale draft, missing point, unavailable rate, unsupported extra pickup
package, changed address, changed rate or changed instance returns `null`; a live session choice
remains authoritative. This snapshot re-binds identity only; carrier/payment compatibility remains
the `validate_order` verdict at Place order. A rate/address change hides the snapshot while changed,
but returning to the original values before any checkout POST restores it, which is still the state
`validate_order` accepts. An explicit clear also removes the retry order's saved point. The client
re-reads cart state after the public `onCheckoutFail` event using `wc/store/cart`'s existing customer
refresh path. Tests cover the cart-read guards and the whole payment retry lifecycle.

## Related

- [store-api-checkout-hooks-also-run-for-pay-for-order](store-api-checkout-hooks-also-run-for-pay-for-order.md) — the other «this hook runs more often than you think» boundary
- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — where the Store API writers come from
- [a-pickup-handler-without-a-selection-scope-has-no-store-api-transport](a-pickup-handler-without-a-selection-scope-has-no-store-api-transport.md) — a scope-less handler is exempt from the retry cleanup
