# gotcha: WooCommerce caches rates per package hash, so a gate that hides a rate must change the package

**Namespace:** `[woocommerce/shipping]`
**Discovered:** s177 (2026-10-14), operator's rig look at the licence gate (#1270, PR #1271)

## What happens

The licence gate made a carrier return no rate. The operator activated the key: the admin notices vanished, but the
checkout kept showing NO carrier methods until he cleared the cart cache.

`WC_Shipping::calculate_shipping_for_package()` stores the calculated rates in the customer's session under
`'wc_ship_' . md5( wp_json_encode( $package ) . WC_Cache_Helper::get_transient_version( 'shipping' ) )` and returns the
stored rates while that hash is unchanged. A licence flip changes neither the package nor the shipping transient version,
so the session keeps serving the result computed under the OLD state — «no rate» after activation, and (the other way)
live rates after the licence stops being in force.

Our own rate cache (`Shipping_Rate_Cache`) was not the problem: the gate runs before it.

## ❌ Wrong

```php
// The gate decides inside calculate_shipping() only.
if ( ! License_Gate::is_in_force( $plugin ) ) {
	return; // WooCommerce caches THIS empty result in the session until the cart/address changes.
}
```

## ✅ Correct

Put every input the rate depends on into the package, so WooCommerce's own hash changes with it:

```php
add_filter( 'woocommerce_cart_shipping_packages', static function ( array $packages ): array {
	foreach ( $packages as &$package ) {
		$package['woodev_license_state'] = License_Gate::license_state(); // e.g. "edostavka:1,yandex_delivery:0"
	}
	return $packages;
} );
```

The value must be deterministic (sorted, no timestamps) or it busts the cache on every request. Block checkout goes
through the same `get_shipping_packages()`, so one filter covers both.

For a pickup-dependent rate, the framework includes the chosen point as `woodev_pickup` when the carrier reads it from the framework's selection storage (`Pickup_Handler::get_selected_point_for_method()`, #1490).

## Related

- [a-cached-asset-under-an-unchanged-ver-reads-as-a-broken-feature](a-cached-asset-under-an-unchanged-ver-reads-as-a-broken-feature.md) — the same «unchanged key, stale result» shape on the asset side
- Session: [../sessions/s177.md](../sessions/s177.md)
