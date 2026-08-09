# ADR-009: `Map_Provider` Seam Re-Pointed to Map Source, Not Rendering Library

**Status:** accepted

**Date:** 2026-07-31

## Context

The original S1 shipping spec (`archive/platform-v2-s1-shipping-spec.md` §6a, decision **a**) drew
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

> **Addendum (2026-08-09, s60):** the shipped `interface-map-provider.php` has grown a
> **sixth** method beyond this five-method contract: `owns_chrome(): bool` — `true` when the
> provider owns the WHOLE container (a third-party widget/iframe with its own list/search UI,
> e.g. `Embedded_Map_Provider`); `false` when it draws only the map canvas and the framework
> renders the list panel, point card, search and filter around it (e.g. `Yandex_Map_Provider`).
> It narrows this ADR's "a provider owns EVERYTHING drawn inside its own container" claim —
> see the method's docblock and decision D-3 of the presentation rework.

`enqueue_assets()` and `get_js_adapter_handle()` are gone — enqueueing is
`Pickup_Handler::enqueue_assets()`'s job (it already registers
`woodev-pickup-map-provider-{provider}` → `js/frontend/map-provider-{provider}.js`), and
"JS adapter" was the deleted five-method model. `get_localized_config()` becomes
`get_js_config( array $context )` so a provider can shape its config against the current
request instead of emitting a fixed blob.

Two concrete providers now ship as framework classes: `Yandex_Map_Provider` (our own map;
resolves its API key from the merchant's own setting, else the PLUGIN's own required
fallback key, itself overridable by a site-level `woodev_shipping_map_fallback_api_key`
filter — see that class's docblock and the 2026-07-31 addendum below for why the fallback is
a plugin obligation, not a framework one, and the shared-quota risk that obligation carries)
and `Embedded_Map_Provider` (a carrier's widget/iframe; declares no API-key field at all).
"Framework ships no default provider" from the original registry docblock DOES still
describe reality — see below.

Neither concrete provider is registered by default. `Map_Provider_Registry::get()` for an id
with nothing registered resolves to `null`; the OWNING PLUGIN registers whichever provider(s)
it uses. `Embedded_Map_Provider` was never a candidate for a default registration — its
constructor requires an embed URL and an expected origin, both plugin-supplied, which the
registry has no source for. `Yandex_Map_Provider` briefly WAS registered by default (see the
2026-07-31 addendum below) on the theory that its constructor was fully optional; the operator
reversed that once the fallback key became a required, plugin-supplied constructor argument —
the framework can no longer construct one at all without plugin data, so it registers none.

## Addendum (2026-07-31): the fallback key is a plugin obligation, not a framework one

A code-review round on this seam raised the fallback-key design as underspecified:
`Yandex_Map_Provider`'s constructor originally took an OPTIONAL API key defaulting to `''`,
with `woodev_shipping_map_fallback_api_key` as the only fallback source and no default value
of its own. That made the constructor fully defaultable, so `Shipping_Plugin::get_map_provider_registry()`
was changed to register a `Yandex_Map_Provider()` by default — the review's own suggestion at
the time, reasoned as "the registry can build one with no plugin-supplied data."

The operator settled the question differently: **the framework ships no key and takes no
responsibility for one; the plugin is obliged to supply its own fallback, and a
site-level filter can still override it.** Concretely: the fallback key is now a REQUIRED
first constructor argument (not optional — an optional one would let a plugin author forget
it and ship a map that only fails on the storefront), exposed via an overridable
`get_fallback_map_key()` accessor for a plugin that resolves its key unusually, with the site
filter wrapped around that accessor's return value rather than around `''`. This makes
`new Yandex_Map_Provider()` — and therefore the just-added default registration — IMPOSSIBLE:
the framework cannot construct the class without plugin-supplied data. The default
registration in `get_map_provider_registry()` is reverted; the framework registers no
provider, exactly the position the ORIGINAL registry docblock held before the two-provider
paragraph in this ADR's Decision section briefly reversed it. This addendum exists so that reversal is a
recorded correction, not a silently vanished line of reasoning.

`Pickup_Handler` now takes the `Map_Provider` instance directly (not a bare id string) — its
`get_js_config()`'s `provider` key reads `$map_provider->get_id()`, and `enqueue_assets()`
enqueues under `$map_provider->get_script_handle()` verbatim, so the `provider` config value
and the enqueued HANDLE can never silently disagree with the provider. The enqueued script's
underlying FILE PATH is still built from `$map_provider->get_id()` separately
(`map-provider-{id}.js`) — both concrete providers derive their handle from `get_id()` by the
same convention, but nothing enforces a THIRD provider following it.

## Consequences

- The original §6a decision **a** in `archive/platform-v2-s1-shipping-spec.md` is superseded by this
  ADR; that spec section is left as written (historical record of the reasoning at the time)
  rather than rewritten, per this project's docs convention that superseded specs are marked,
  not edited into agreement with hindsight.
- `Yandex_Map_Provider`/`Embedded_Map_Provider` (SP-5 Task 9) are PHP descriptors only; the
  JS provider scripts that actually implement the rendering (clustering, drawer, balloon,
  bounded search) are separate, later SP-5 tasks (13/14) and are not built by this ADR.
  (Update, s60: Tasks 13/14 have since shipped — `map-provider-yandex.js` /
  `map-provider-embedded.js` exist under `woodev/shipping-method/assets/js/frontend/`.)
- A future third "map source" (e.g. a second carrier's embedded widget with a different
  trust model) fits `Embedded_Map_Provider`'s shape directly; a genuinely new SOURCE axis
  member (neither "our own render" nor "embed a widget") would need a new concrete class,
  not a change to the interface — the interface itself is now sized to the axis, not to one
  provider's needs.

## Related

- `docs-internal/archive/platform-v2-s1-shipping-spec.md` §6a — superseded original decision.
- [ADR-010](010-yandex-maps-js-api-2-1-not-3-0.md) — constrains the Yandex provider to Yandex Maps JS API 2.1.
- `woodev/shipping-method/map/interface-map-provider.php`
- `woodev/shipping-method/map/class-yandex-map-provider.php`
- `woodev/shipping-method/map/class-embedded-map-provider.php`
- `woodev/shipping-method/map/class-map-provider-registry.php`
- `woodev/shipping-method/pickup/class-pickup-handler.php`
