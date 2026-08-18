# Gotcha: [testing/integration] — a fresh guest's shipping country resolves through geolocation, not the store default
> Tags: testing, integration, woocommerce, customer, shipping-country | Session: s78

## What happens

A test that constructs a fresh guest `WC()->customer` and expects `get_shipping_country()` to
answer the store's `woocommerce_default_country` setting (or an empty string) is wrong. It answers
`US` instead — even on a store whose default country is `RU` and whose `woocommerce_default_customer_address`
is left at its own default.

Reached in this codebase via `Location_Service::customer_shipping_country()`
(`woodev/shipping-method/location/class-location-service.php`), which is the country authority
`is_customer_record_stale()`'s rule (b) checks a stored record against (#346). Two integration
tests had to call `WC()->customer->set_shipping_country( 'RU' )` explicitly before asserting
anything about staleness, or the ambient customer answered `US` and every `RU` fixture record
read as stale for the wrong reason.

## Root cause

`WC_Customer::get_shipping_country()` for a customer with no explicitly-set shipping country falls
back to `wc_get_customer_default_location()`. That function's own default,
`woocommerce_default_customer_address`, is `'geolocation'` (WooCommerce's own out-of-the-box
setting — not something this project configures) — which resolves the country through
`WC_Geolocation::geolocate_ip()`. In a test/CI environment the request IP is not a routable public
address, so the MaxMind/API lookup fails, and `WC_Geolocation` falls back to a **hardcoded `'US'`**
— entirely bypassing `woocommerce_default_country`, the setting a developer would reasonably
expect to be consulted.

So "the store's own configured default country" and "what a fresh guest's `WC()->customer` actually
reports" are two different chains that happen to share a name (`default`) but not a value.

## Fix

❌ Wrong — assuming an unconfigured guest customer reflects the store setting:

```php
// Store default is RU, so a fresh guest customer should read RU too... right?
$customer = new \WC_Customer( 0, true );
$this->assertSame( 'RU', $customer->get_shipping_country() ); // fails: reads 'US'
```

✅ Correct — seed the field explicitly whenever a test's assertion depends on a specific shipping
country, rather than relying on WooCommerce's own default chain:

```php
WC()->customer->set_shipping_country( 'RU' );
```

✅ Also correct — when writing a country-authority accessor that must never disagree with a
caller's own already-resolved country (e.g. a REST request's own `country` param), give it an
explicit override rather than only reading the ambient customer object — see
`Location_Service::is_customer_record_stale()`'s optional `$for_country` parameter (#350/#352
follow-up): a call site with a stronger authority than the ambient customer can supply it directly
instead of fighting this fallback chain.

## Related

- [the-integration-suite-has-a-wc-session-a-rest-request-does-not](the-integration-suite-has-a-wc-session-a-rest-request-does-not.md) — another place the integration harness's ambient WooCommerce state does not match a real request
- [guest-session-write-needs-the-cart-cookie](guest-session-write-needs-the-cart-cookie.md) — a sibling guest/session surprise in the same location layer
- [within-applied-reports-the-scope-builder-not-the-provider](within-applied-reports-the-scope-builder-not-the-provider.md) — another `Location_Service`/`Location_Controller` seam where an ambient signal reads as an authority it is not
