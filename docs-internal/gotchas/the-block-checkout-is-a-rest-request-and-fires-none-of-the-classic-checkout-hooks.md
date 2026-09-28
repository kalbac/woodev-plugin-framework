# Gotcha: [shipping/checkout] — The block checkout is a REST request: the framework's shipping guard blocks its rates, and a block-checkout order fires none of the classic checkout hooks
> Tags: shipping, checkout, store-api, rest-request, blocks, hooks | Session: s142
> **Measured on:** the rig, WooCommerce 11.1.0, 2026-09-28.

## What happens

On the block cart/checkout (the WC Store API, `POST /wc/store/v1/…`), a package that should
produce several carrier rates produces **only `free_shipping`**. The framework's own methods —
`woodev_test_shipping`, `woodev_realistic_pickup_shipping`, and any carrier plugin's — never
appear.

Measured on the rig (WC 11.1.0), the same RU / Москва package:

```text
plain WC()->shipping()                    -> 3 rates: free_shipping:1, woodev_test_shipping:3, woodev_realistic_pickup_shipping:5
with REST_REQUEST defined                 -> 1 rate (free_shipping only)
real POST /wc/store/v1/cart/update-customer -> free_shipping only
```

The second half is silent data loss. Once those rates *do* appear, a carrier order placed through
the block checkout carries **no framework meta and no carrier marker**, because the Store API
fires none of the classic checkout hooks the framework writes that data on. The classic
"pickup point required" guard does not run there either. (Cards #963, #964, #966.)

## Root cause

Two independent gaps, both rooted in "the block checkout is a REST request."

**1. The shipping guard treats every REST request as "not a cart request."**
`Shipping_Method::calculate_shipping()` (`woodev/shipping-method/class-shipping-method.php:374`)
is the sole caller of `Shipping_Method::should_send_cart_api_request()` (`:840`), at `:386`, and
that guard returns `false` for ANY request with `REST_REQUEST` defined:

```php
private function should_send_cart_api_request(): bool {
	return ! (
			( is_admin() && did_action( 'woocommerce_cart_loaded_from_session' ) ) ||
			( defined( 'REST_REQUEST' ) || defined( 'REST_API_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) )
		);
}
```

The block cart/checkout computes shipping through the Store API, which *is* a REST request, so
`REST_REQUEST` is defined and the guard returns `false`: `calculate_shipping()` returns before it
adds any rate. Only WooCommerce's own `free_shipping` survives, because core adds it outside the
framework's guard.

Worse, the result is **cached in the WC session by package hash** and reused by the classic
checkout too, so an empty Store-API computation can leave the classic path with the same empty
result.

**2. The Store API fires none of the classic checkout hooks.** The framework writes its order
data on `woocommerce_checkout_process`, `woocommerce_checkout_posted_data`,
`woocommerce_checkout_create_order` and `woocommerce_checkout_order_processed` (e.g.
`Checkout_Handler` at `class-checkout-handler.php:258`, `Pickup_Checkout_Handler` at
`class-pickup-handler.php:1863`, `Location_Provider_Registry` at
`class-location-provider-registry.php:726`). The Store API fires none of them; its analogue is
`woocommerce_store_api_checkout_order_processed( $order )`, which carries **no `$posted_data`**.
Until that hook is wired, a block-checkout order has no framework meta and no carrier marker, and
the "pickup point required" guard — which reads posted data — does not run.

## How it hid

The rig runs the **classic** checkout, where the request is not a REST request, so
`should_send_cart_api_request()` returns `true` and the rates compute. The bug lives only in the
Store-API path, which the classic path never exercises. A naive probe at `/checkout/` *would* hit
it — that URL is the block checkout (see the related gotcha) — but the established test path was
the classic one, so nothing saw the missing rates or the missing order meta.

## Fix

Two halves: the first is in flight on #949 (`fix/949-store-api-rates`); the second is #963 / #964
/ #966.

**1. Let the Store API through the guard** — detect it explicitly instead of blocking all REST
requests:

```php
// ❌ old guard — blocks the Store API with every other REST request
return ! (
		( is_admin() && did_action( 'woocommerce_cart_loaded_from_session' ) ) ||
		( defined( 'REST_REQUEST' ) || defined( 'REST_API_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) )
	);

// ✅ in flight on #949 — the Store API is the one REST request that MUST compute
if ( WC()->is_store_api_request() ) {
	return true;
}
return ! (
		( is_admin() && did_action( 'woocommerce_cart_loaded_from_session' ) ) ||
		( defined( 'REST_REQUEST' ) || defined( 'REST_API_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) )
	);
```

**2. Wire the Store API's order hook too, not just the classic one:**

```php
// ❌ classic only — a block-checkout order gets no framework meta / marker
add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );

// ✅ both — the Store API analogue passes the order as the 1st arg; there is no $posted_data
add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'handle_store_api_order_processed' ], 10, 2 );

function handle_store_api_order_processed( $order ) {
	// same persistence as the classic handler — the order is already built
}
```

## Related

- [#949 — store-api-rates](https://github.com/kalbac/woodev-plugin-framework/issues/949) — `fix/949-store-api-rates`: let the Store API through the shipping guard (half 1)
- [#964 — order-persistence-core](https://github.com/kalbac/woodev-plugin-framework/issues/964) — `feat/964-order-persistence-core`: wire the Store API order hook so a block-checkout order carries framework meta / marker (half 2); #963 is the same effort
- [#966 — store-api-pickup-required](https://github.com/kalbac/woodev-plugin-framework/issues/966) — `fix/966-store-api-pickup-required`: the "pickup point required" guard must run on the Store API too
- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — the rig's `/checkout/` IS the block checkout, so a naive probe there triggers the bug
- [block-checkout-reads-country-locale-not-checkout-fields](block-checkout-reads-country-locale-not-checkout-fields.md) — the other "the block checkout is not the classic checkout" gap: it ignores `woocommerce_checkout_fields`
- [the-integration-suite-has-a-wc-session-a-rest-request-does-not](the-integration-suite-has-a-wc-session-a-rest-request-does-not.md) — the same REST-context split on the test side: a REST request and the PHPUnit suite disagree about which context is live
- [the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other](the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — the general shape: a checkout rule lives in two halves, and fixing one silently leaves the other
- [research/2026-09-28-710-i0-measurement](../research/2026-09-28-710-i0-measurement/README.md) — the I0 per-request measurement, the evidence behind this gotcha
