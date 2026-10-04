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

A failed payment does NOT refresh the cart in the browser, so the block keeps echoing the original
confirmation; the next cart answer (a payment-method switch returns `__experimentalCart`) carries no
point, because the session forgot it.

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

A retry test must run the WHOLE second `POST`: `update_order` → `validate_order` → BOTH
`order_processed` callbacks (priority 10, then 20). A test that stops at validation passes on the
broken code. Reset the handler's per-request memo between simulated requests, or the carrier-request
counts are meaningless.

## Related

- [store-api-checkout-hooks-also-run-for-pay-for-order](store-api-checkout-hooks-also-run-for-pay-for-order.md) — the other «this hook runs more often than you think» boundary
- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — where the Store API writers come from
- [a-pickup-handler-without-a-selection-scope-has-no-store-api-transport](a-pickup-handler-without-a-selection-scope-has-no-store-api-transport.md) — a scope-less handler is exempt from the retry cleanup
