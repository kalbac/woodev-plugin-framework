# Gotcha: [shipping/rate-cache] — A key wider than the carrier request misses on every keystroke
> Tags: shipping/rate-cache, woocommerce/checkout | Session: #1974, 2026-11-26

## What happens

The rate cache is on, yet one classic checkout sends the same request over and over (Yandex 2.0.17:
80 calls in 6.5 minutes), the carrier answers 429 and a method disappears from the list.

## Root cause

WooCommerce recalculates after every pause in typing and, with `Fee_Payments` wired, on every change
of gateway. A field in the key that never reaches the request body — a half-typed postcode, a flat
number, the raw gateway id — is a miss each time.

The trap on the way back: a raw input also carries answers nobody keyed on purpose. The gateway id
silently covered the merchant's «only these payment methods» markup list, and the postcode covered
which settlement a carrier resolved from a location record. Replace a raw input by the DERIVED
answers (`cod`, `fee_applies`) only after listing every reader of it — each carrier's
`rate_package()` included.

## Fix

❌ Every method keyed by everything the package holds:

```php
$context            = Shipping_Rate_Cache::package_context( $package );
$context['payment'] = $this->payment_method_for_package( $package );
```

✅ Key what prices the quote (`Shipping_Method::get_rate_cache_context()`):

```php
if ( $this->is_pickup_shipping() ) {
	unset( $context['destination']['postcode'] /* and the street lines */ );
}
$context['cod'] = $this->is_cod_gateway( $this->payment_method_for_package( $package ) );
if ( $this->supports_fee_payments() ) {
	$context['fee_applies'] = $this->fee_applies_for_package( $package );
}
```

A carrier that needs the raw input puts it back: its own override, or
`FEATURE_GATEWAY_DEPENDENT_PRICE`.

## Related

- [percentage-box-cost-cache-needs-line-values](percentage-box-cost-cache-needs-line-values.md) — the opposite error: a key that is too narrow
- [cart-and-checkout-share-one-shipping-rate-cache-entry](cart-and-checkout-share-one-shipping-rate-cache-entry.md) — WooCommerce's own cache in front of ours
