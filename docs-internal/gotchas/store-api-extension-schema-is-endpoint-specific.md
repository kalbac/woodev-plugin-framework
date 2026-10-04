# Gotcha: [woocommerce/store-api] — Store API extension schemas are endpoint-specific
> Tags: woocommerce, store-api, checkout, pickup | Session: SP-11 C-2a fix round, 2026-10-04
> **Verified against:** local WooCommerce 11.1.0 source and PR #1094 regression tests.

## What happens

An echo or `clear` command under `extensions['woodev-shipping']` disappears from checkout
requests even though cart responses expose that namespace. Unit tests that construct a
`WP_REST_Request` directly retain the payload and conceal the missing registration.

## Root cause

WooCommerce 11.1 `CheckoutSchema` builds its extension properties from the `checkout`
endpoint. `AbstractSchema::get_recursive_sanitize_callback()` rebuilds the request from those
registered properties; registering only `cart` does not register a checkout request property.
An open pickup object with no nested `properties` preserves the carrier/field keys through
that recursive sanitizer.

## Fix

```php
// ❌ A cart registration says nothing about the checkout endpoint.
woocommerce_store_api_register_endpoint_data( [ 'endpoint' => 'cart', /* ... */ ] );

// ✅ Register the same namespace for checkout with a writable pickup schema too.
woocommerce_store_api_register_endpoint_data( [
    'endpoint' => 'checkout',
    'namespace' => Store_Api_Pickup::EXTENSION_NAMESPACE,
    'schema_callback' => [ Store_Api_Pickup::class, 'checkout_schema' ],
    'schema_type' => ARRAY_A,
] );
// checkout_schema(): pickup => type object, readonly false, additionalProperties true.
```

When WooCommerce is unavailable in a unit suite, assert both registrations and the open,
writable checkout schema, and document that the real sanitizer is outside that test's scope.

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — Store API hooks and classic checkout are separate paths
- [store-api-checkout-hooks-also-run-for-pay-for-order](store-api-checkout-hooks-also-run-for-pay-for-order.md) — the other Store API checkout boundary
