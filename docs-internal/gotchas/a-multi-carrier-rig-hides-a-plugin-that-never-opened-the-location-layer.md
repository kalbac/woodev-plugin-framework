# A multi-carrier rig hides a plugin that never opened the location layer

**Namespace:** `[shipping/location]`
**Found:** s194 (23.10.2026), by the operator on a demo site with Russian Post as the only Woodev plugin.

## What happens

The Location layer is **fleet-wide**: `Location_Provider_Registry::declare_needed()` opens it for every plugin as soon as
ANY plugin's `Shipping_Plugin::needs_location_provider()` returns `true`. Russian Post 2.0.3 implemented
`get_location_adapter()` but left `needs_location_provider()` at the base default `false` and declared no
`->source_location()` fields. On the rig (CDEK + Yandex + Russian Post) the layer was open — CDEK and Yandex opened it —
so every acceptance check passed. On a shop with Russian Post alone there was no «Локация» section in Woodev → Настройки →
Доставка, no region/settlement field modes, no cascade and no DaData suggestions.

Opening the layer is also not enough: the cascade mounts only for fields declared with
`Field::create( 'shipping_state' | 'shipping_city' | 'shipping_address_1' )->source_location( LEVEL_* )`.

## ❌ Wrong

```php
public function get_location_adapter(): ?Location_Adapter {
	return $this->location_adapter ??= new My_Location_Adapter( /* … */ );
}
// needs_location_provider() not overridden; checkout handler declares only the pickup field.
```

## ✅ Correct

```php
public function needs_location_provider(): bool {
	return true;
}
// …and in get_checkout_handler(): the three source_location fields, exactly as CDEK/Yandex declare them.
```

Accept a new carrier plugin **with it as the only Woodev plugin active**, not only beside its siblings. Since #1420
(PR #1421) the framework raises `_doing_it_wrong()` for both halves.

## Related

- [user-meta-is-network-wide-on-multisite-store-per-site-data-with-user-option](user-meta-is-network-wide-on-multisite-store-per-site-data-with-user-option.md) — another fleet-wide location fact
- [../sessions/s194.md](../sessions/s194.md) — the session that found and fixed it (kalbac/woodev-russian-post#56)
