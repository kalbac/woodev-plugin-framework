# Gotcha: [shipping/rate-cache] — WooCommerce writes into `instance_settings` on first read
> Tags: shipping/rate-cache, woocommerce/settings | Session: #1974, 2026-11-26

## What happens

A shipping method with the rate cache on never hits it until the merchant saves the instance again
(Yandex rig: 56 price requests, 8 after both causes were fixed — yandex PR #172).

## Root cause

`WC_Shipping_Method::get_instance_option()` (read in WooCommerce 11.3.0) assigns into
`$this->instance_settings` while reading: a field the saved row does not hold gets its default, and a
stored `''` read with a non-null fallback gets the fallback. A key built from that array before the
calculation (`get()`) differs from the one built after it (`put()`), so the rate is stored where
nobody looks. Any field added by an update is enough (`free_before_discount`, 2.1.9).

The `WC_Shipping_Method` doubles in unit tests do not do this, so the suite stayed green.

## Fix

❌ The in-memory array as an identity:

```php
$context['settings'] = $this->instance_settings;
```

✅ The saved row, which no read can change:

```php
$option              = $this->get_instance_option_key(); // '' without an instance
$context['settings'] = '' === $option ? [] : get_option( $option, [] );
```

A value changed only in memory is then not part of the identity: key it explicitly.

## Related

- [a-rate-cache-key-wider-than-the-carrier-request-misses-on-every-keystroke](a-rate-cache-key-wider-than-the-carrier-request-misses-on-every-keystroke.md) — the other cause of the same misses
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why a green unit suite said nothing
