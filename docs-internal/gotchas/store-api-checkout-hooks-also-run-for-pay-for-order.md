# Gotcha: [woocommerce/store-api] — Store API checkout hooks also run for pay-for-order
> Tags: woocommerce, store-api, checkout, pickup | Session: SP-11 C-2a fix round, 2026-10-04
> **Verified against:** local WooCommerce 11.1.0 source and PR #1094 regression tests.

## What happens

The Store API pickup payment gate rejects an existing order when the shopper's current cart
is empty or uses another rate. Merely recording an order in
`woocommerce_store_api_checkout_update_order_from_request` does not prove this is `/checkout`.

## Root cause

WooCommerce 11.1 `CheckoutOrder` (`POST /checkout/{id}`) uses `CheckoutTrait` and fires the
same update-order hook and payment-validation hook as checkout. Existing/admin-created orders
must not read their pickup rate or destination from the current cart/session. A failed or
pending order is a checkout retry only when it is the session's `store_api_draft_order`.

## Fix

```php
// ❌ Every invocation of this shared hook becomes a checkout request.
self::$echoes[ $order->get_id() ] = $payload;

// ✅ Reject the pay-for-order route and orders outside the session draft before reading the cart.
// Read the route id from the URL ONLY — get_param() prefers the body, so a client posting `id`
// to POST/PATCH /checkout would otherwise skip the whole gate (critic round 2, s151).
$url_params = $request->get_url_params();
if ( (int) $order->get_id() !== static::draft_order_id() || isset( $url_params['id'] ) ) {
    unset( self::$echoes[ $order->get_id() ] );
    return;
}
```

Keep the `Checkout_Handler` missing-slot backstop limited to `checkout-draft`; the pickup
adapter separately validates the session draft's failed/pending retries. A regression test
must invoke `update_order()` and then `validate_order()` with an empty cart, as the real route does.

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — Store API hooks and classic checkout are separate paths
- [store-api-extension-schema-is-endpoint-specific](store-api-extension-schema-is-endpoint-specific.md) — the other Store API checkout boundary
