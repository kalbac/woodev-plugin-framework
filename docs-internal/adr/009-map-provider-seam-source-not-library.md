# ADR-009: `Map_Provider` Seam Re-Pointed to Map Source, Not Rendering Library

**Status:** accepted

**Date:** 2026-07-31

## Context

The original S1 shipping spec (`platform-v2-s1-shipping-spec.md` §6a, decision **a**) drew
the `Map_Provider` seam along the axis "which library draws the map": a thin PHP descriptor
registered a JS **adapter** (Leaflet shipping as the framework default, a Yandex adapter
in the yandex plugin) behind a five-method contract
(`enqueue_assets()`, `get_js_adapter_handle()`, `get_localized_config()`, plus id/settings).
A provider-agnostic JS core (`pickup-map.js`) owned rendering, balloon layout, and
interaction; the adapter only translated a fixed config shape into library calls.

That axis assumed a second real rendering library would show up as a genuine second
implementation of the SAME five-method contract. It never did, and reading the target UX
against that contract shows it structurally cannot: clustering, a viewport-synced list
drawer, custom map controls, bounded geocoding search, and a payment-method-aware balloon
layout (see `plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`
for the shape this framework must eventually reproduce) are not config values a
provider-agnostic core can parameterize — they are the UI. A second library under this
contract would have been a second full build wearing the adapter's five methods, not a thin
adapter. "Provider-agnostic" was only ever agnostic while exactly one provider existed.

## Decision

Re-point the seam to the axis that has two REAL consumers: **where the map comes from** —
our own Yandex.Maps-rendered map, or a carrier's own widget/`<iframe>` embedded in the same
modal shell. A `Map_Provider` now owns EVERYTHING drawn inside its own container and pulls
point data through a `dataSource` the framework hands its `init()`. The PHP contract shrinks
to what a provider genuinely cannot avoid declaring:

```php
public function get_id(): string;
public function get_label(): string;
public function get_script_handle(): string;
public function get_settings_fields(): array;
public function get_js_config( array $context ): array;
```

`enqueue_assets()` and `get_js_adapter_handle()` are gone — enqueueing is
`Pickup_Handler::enqueue_assets()`'s job (it already registers
`woodev-pickup-map-provider-{provider}` → `js/frontend/map-provider-{provider}.js`), and
"JS adapter" was the deleted five-method model. `get_localized_config()` becomes
`get_js_config( array $context )` so a provider can shape its config against the current
request instead of emitting a fixed blob.

Two concrete providers now ship as framework classes (not "framework ships no default
provider" — that line from the original registry docblock no longer describes reality):
`Yandex_Map_Provider` (our own map; resolves its API key from the plugin's own setting, else
a `woodev_shipping_map_fallback_api_key` filter the framework itself hooks nothing on — see
that class's docblock for why a plugin that DOES hook it with a shared key takes on a
documented, accepted quota risk, not an oversight) and `Embedded_Map_Provider` (a carrier's
widget/iframe; declares no API-key field at all).

`Yandex_Map_Provider` IS registered by default, in
`Shipping_Plugin::get_map_provider_registry()` — its constructor is fully defaulted (an
empty key just falls back to the filter above), so the registry can build one with no
plugin-supplied data, giving the seam a real id → provider resolution path rather than a
permanently-empty registry with zero consumers. `Embedded_Map_Provider` is deliberately NOT
auto-registered: its constructor requires an embed URL and an expected origin, both
plugin-supplied, which the registry has no source for. A host plugin that wants a
merchant-configured Yandex key re-registers `yandex` with its own instance
(`Map_Provider_Registry::register()` overrides a previous registration under the same id).
`Map_Provider_Registry::get()` for an id with nothing registered still resolves to `null`.

`Pickup_Handler` now takes the `Map_Provider` instance directly (not a bare id string) — its
`get_js_config()`'s `provider` key reads `$map_provider->get_id()`, and `enqueue_assets()`
enqueues under `$map_provider->get_script_handle()` verbatim, so the `provider` config value
and the enqueued HANDLE can never silently disagree with the provider. The enqueued script's
underlying FILE PATH is still built from `$map_provider->get_id()` separately
(`map-provider-{id}.js`) — both concrete providers derive their handle from `get_id()` by the
same convention, but nothing enforces a THIRD provider following it.

## Consequences

- The original §6a decision **a** in `platform-v2-s1-shipping-spec.md` is superseded by this
  ADR; that spec section is left as written (historical record of the reasoning at the time)
  rather than rewritten, per this project's docs convention that superseded specs are marked,
  not edited into agreement with hindsight.
- `Yandex_Map_Provider`/`Embedded_Map_Provider` (SP-5 Task 9) are PHP descriptors only; the
  JS provider scripts that actually implement the rendering (clustering, drawer, balloon,
  bounded search) are separate, later SP-5 tasks (13/14) and are not built by this ADR.
- A future third "map source" (e.g. a second carrier's embedded widget with a different
  trust model) fits `Embedded_Map_Provider`'s shape directly; a genuinely new SOURCE axis
  member (neither "our own render" nor "embed a widget") would need a new concrete class,
  not a change to the interface — the interface itself is now sized to the axis, not to one
  provider's needs.

## Related

- `docs-internal/platform-v2-s1-shipping-spec.md` §6a — superseded original decision.
- `woodev/shipping-method/map/interface-map-provider.php`
- `woodev/shipping-method/map/class-yandex-map-provider.php`
- `woodev/shipping-method/map/class-embedded-map-provider.php`
- `woodev/shipping-method/map/class-map-provider-registry.php`
- `woodev/shipping-method/pickup/class-pickup-handler.php`
