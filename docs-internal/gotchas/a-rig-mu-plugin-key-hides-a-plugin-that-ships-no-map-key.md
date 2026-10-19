# A rig mu-plugin that injects a Maps key hides a plugin that ships no fallback key

**Namespace:** `[rig/config]`
**Discovered:** 2026-10-20 (s188)

## The trap

The framework's `Yandex_Map_Provider` resolves its key as: the merchant's shared key (`woodev_pickup_map_yandex_api_key`)
→ the PLUGIN's fallback key (a required constructor argument, ADR-009 addendum) → the `woodev_shipping_map_fallback_api_key`
filter. Each carrier plugin supplies its fallback from a constant defined in its main file. CDEK defines
`WC_CDEK_YANDEX_MAPS_FALLBACK_KEY`; Yandex 2.0.0–2.0.2 READ `WOODEV_YANDEX_DELIVERY_MAP_FALLBACK_KEY` but never DEFINED
it, so its fallback was `''` and `mapConfig.hasApiKey === false`.

The rig never showed it: `wp-content/mu-plugins/zz-rig-yandex-key.php` injects a key through the filter, so every
acceptance run, e2e and screenshot had a working map. On a merchant site without its own key the pickup modal said
«Не удалось загрузить пункты выдачи» while the points request returned 200 with thousands of points — the framework
never even requests the Maps script when `hasApiKey` is false (`map-provider-yandex.js:1032`), emits
`woodev_pickup_error` `{code:'map_script'}`, and the draw error that follows is swallowed (#1370).

## Correct

- A plugin's fallback key is a literal `define()` in the main file, guarded by `defined() ||` so wp-config still wins,
  and a unit test reads the main file's source and asserts the literal exists (Yandex 2.0.3, yandex#127).
- Before trusting a rig check of anything a rig mu-plugin also supplies (keys, credentials, filters), list
  `wp-content/mu-plugins/` and ask what the plugin does WITHOUT it.
- To diagnose the modal on a live site: before opening it, run
  `document.body.addEventListener('woodev_pickup_error', e => console.log(e.detail))` — `code: 'map_script'` means the
  map, not the points.

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md)
- ADR `adr/009-map-provider-seam-source-not-library.md` (Addendum 2026-07-31)
