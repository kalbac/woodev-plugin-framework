# ADR-010: Pickup map stays on Yandex Maps JS API 2.1; version 3.0 is rejected

**Status:** accepted

**Date:** 2026-08-01

## Context

The SP-5 pickup map is built on Yandex Maps JS API **2.1**, inherited from the reference plugin. While
reworking the presentation layer (s47, see `specs/2026-08-01-sp5-pickup-map-rework-design.md`) the
operator asked whether we should move to **JS API 3.0**, which has existed for some time.

Facts established against Yandex's own documentation on 2026-08-01, not from memory:

**Against 3.0:**

- The core has **no clustering**. The migration guide states plainly: *"The API v3 has no
  clusterization tool."* An official package `@yandex/ymaps3-clusterer` (`YMapClusterer`,
  `clusterByGrid`) exists and can be loaded without a bundler via
  `ymaps3.import('@yandex/ymaps3-clusterer')`, but Yandex warns that *"Packages do not guarantee
  backward compatibility"* — unlike modules.
- There is **no built-in pop-up window** and **no `SearchControl`**.
- `ymaps3.search()` / `ymaps3.suggest()` require a **separate API key**
  (`getDefaultConfig().setApikeys({ search: … })`). That is a second shared-credential obligation for
  every consuming plugin, on top of the map key we already require.
- Coordinates are **LngLat**, inverted relative to 2.1's LatLng — a silent-corruption trap across
  every coordinate site in the codebase.
- `YMap.setLocation()` returns **`void`**, not a promise, so all camera-sequencing logic would have to
  be re-derived against `YMapListener` events.
- All three working reference implementations we check our behaviour against (Yandex.Delivery, CDEK,
  Russian Post) are on 2.1. Moving loses that reference.

**For 3.0:**

- Markers accept arbitrary HTML natively.
- The Yandex **Map Style Editor** (universal / grey / monochrome schemes, HEX accent colour, slider
  control of terrain, roads, buildings and label detail, JSON export) is compatible with **JS API 3.0
  and MapKit SDK only**. JS API 2.1 exposes no scheme-customisation API at all; the only way to change
  a 2.1 map's appearance is to layer custom tiles via `ymaps.Layer` — which is exactly why the CDEK
  plugin layers 2GIS tiles over the ymaps engine.

**About 2.1's longevity:**

- No end-of-support date has been announced. The FAQ says only that Yandex *may* eventually deprecate
  an outdated **minor** version, in which case projects are switched automatically to the newest.
- The version history's last entry is **2.1.79 (03.06.2021)**: the API is frozen, in maintenance, not
  developed.
- Nothing the references rely on is deprecated or removed: `Clusterer`, `ObjectManager`,
  `control.SearchControl`, `control.ListBox`, `templateLayoutFactory`, `map.margin` (since 2.1.35) and
  `geoQuery` are all current.

The balance shifted during the design discussion. Two of the three original objections to 3.0 —
"no balloons" and "no `SearchControl`" — were neutralised by decisions taken independently: the point
card became our own DOM, and the search field became our own view. The remaining hard costs are the
clusterer package's backward-compatibility disclaimer and the second API key for search.

## Decision

**The pickup map stays on Yandex Maps JS API 2.1. Version 3.0 is rejected — not deferred.**

Consequently, map appearance presets (monochrome, accent colour) are **not available** and will not be
implemented. The only appearance lever is an optional custom tile layer, exposed through `mapConfig`'s
`layers` and `copyrights` fields, empty by default.

This is a closed decision, deliberately recorded here rather than as a backlog card, so that it is not
re-litigated each time the style editor is noticed.

## Consequences

**Easier:**

- The rework ships against an API we already run, with three working references to check behaviour
  against.
- Clustering, custom balloon layouts, bounded geocoding search, `geoQuery.searchInside()`,
  `map.margin` and `ObjectManager.setFilter()` all remain first-class and documented.
- One API key per plugin, not two.
- No LngLat/LatLng audit, no re-derivation of camera sequencing.

**Harder:**

- No map style presets, ever, on this stack. A merchant wanting a monochrome or accent-coloured map
  must supply their own tile layer.
- We are building on an API whose last release was in 2021. If Yandex ever announces an end of support
  for 2.1, this ADR must be superseded and the map rewritten — the migration cost recorded above is
  the estimate to start from.
- The 2GIS tile layering that CDEK relies on remains the only appearance mechanism, and whether it is
  permitted under both parties' terms of use is the consuming plugin's decision, not the framework's.

## Related

- [[../specs/2026-08-01-sp5-pickup-map-rework-design.md]] — the rework this decision belongs to
- [[009-map-provider-seam-source-not-library.md]] — why the provider seam is "where does the map come
  from", not "which library draws it"
- [[../gotchas/ymaps-camera-moves-are-async.md]]
