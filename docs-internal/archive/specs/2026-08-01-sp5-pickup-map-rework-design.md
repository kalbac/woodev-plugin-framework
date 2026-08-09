> Archived s60 (2026-08-09): SP-5 map iteration shipped; PR #149 merged s51; superseded by later map work.

# SP-5 rework — pickup map presentation layer: design

> Status: **APPROVED** (operator, s47 · 2026-08-01). Amends, does not replace,
> `2026-07-30-sp5-pickup-map-design.md`: everything that document says about the server side, the
> REST contract, the loading strategies, the `dataSource` inversion and the server-authoritative
> `selectable` verdict still stands. This document replaces its §4.3, §4.9 and §10.9 (presentation
> layer, initial viewport, `Map_Provider` contract).
> `@since 2.0.2`, VERSION unchanged.

## 1. Problem

The operator opened the SP-5 map on the rig (s46, PR #149) and rejected it within five seconds:
*"направление правильное, но визуально и функционально нет"*. The mandate for this rework is not a
defect list — it is: **reproduce the reference pickup map, under our universal structure, and do it
better.**

Three reference implementations were read in full for this design. All three run **Yandex Maps JS
API 2.1**:

| Reference | File | What it contributes |
|---|---|---|
| Yandex.Delivery | `plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js` (620 lines) + `templates/html-modal-map.php` + `assets/css/frontend/wc-yandex-delivery-pickup-point-modal-map.css` | The operator's stated target: layout, drawer, docked point panel, icon sets |
| CDEK | `plugins-reference/woocommerce-edostavka/assets/js/frontend/woodev-yandex-map-plugin.js` | `ObjectManager`, co-located-point tabs, icon state by size, 2GIS tile layer, search over the loaded pool |
| Russian Post | `https://widget.pochta.ru/map/main.a7d147fb5267ec1f0932.js` (73 KB, minified) | Custom `SearchControl` view, filter menu with badge, co-located-point guard, initial-viewport cascade, point services |

## 2. Non-goals — what this rework does NOT touch

Verified live on the rig in s46, architecture holds:

- the `dataSource` inversion passed into `init()` — the reason one contract serves both strategies;
- `selectable` computed **server-side**, client only renders (`2026-07-30` spec §4.5);
- both loading strategies, the 10°-per-side bbox cap, lazy details with verdict recomputation;
- REST `woodev/v1`, point persistence into order meta, A2 gate release, address replacement (§8);
- the server backstop on `woocommerce_checkout_process`;
- `pickup-datasource.js` (debounce, de-duplication).

The modal shell's **behaviour** (focus trap, Esc, focus return) is also proven and is preserved
verbatim — but the file itself moves and generalises, see D-13.

## 3. Decisions

### D-1. Stay on JS API 2.1. Version 3.0 is rejected outright

Recorded as an ADR, **not** a backlog card — this is a closed decision, not deferred work.

Verified against Yandex documentation (2026-08-01):

- v3 core has **no clustering** ("The API v3 has no clusterization tool"). The official
  `@yandex/ymaps3-clusterer` package exists and loads without a bundler via
  `ymaps3.import('@yandex/ymaps3-clusterer')`, but Yandex states "Packages do not guarantee backward
  compatibility".
- v3 has **no built-in pop-up window** and **no `SearchControl`**.
- v3 `ymaps3.search()` / `suggest()` require a **separate API key** (`setApikeys({search: …})`) — a
  second shared-key obligation for every consuming plugin.
- v3 markers accept arbitrary HTML; `setLocation()` returns **`void`** (not a promise); `map.bounds`
  is a synchronous getter; coordinates are **LngLat**, inverted relative to 2.1's LatLng.
- The Yandex **Map Style Editor** (universal / grey / monochrome, HEX accent, JSON export) is
  compatible with **JS API 3.0 and MapKit SDK only**. There is no scheme-customization API in 2.1;
  the only lever on 2.1 is a custom tile layer via `ymaps.Layer`.
- 2.1 has **no announced end-of-support date**. Its version history's last entry is **2.1.79
  (03.06.2021)** — the API is frozen and in maintenance, not developed.
- Nothing the references rely on is deprecated or removed in 2.1: `Clusterer`, `ObjectManager`,
  `control.SearchControl`, `control.ListBox`, `templateLayoutFactory`, `map.margin` (since 2.1.35),
  `geoQuery` are all current.

The style editor is a real loss and the operator accepted it: shipping the map he rejected outweighs
a cosmetic capability, and all three working references are on 2.1, so we keep the ability to check
our behaviour against code that demonstrably works.

### D-2. The point panel is our own DOM. The ymaps balloon is not used at all

The Yandex reference achieves "information in the sidebar" by overriding ymaps internals:

```css
#yandex-delivery-map-container ymaps[class*=-balloon-pane] {
    bottom: 0 !important; left: unset !important; right: 0 !important; top: 0 !important;
    transform: none !important; z-index: 5500 !important;
}
```

We do not. Both panels (list and point card) are plain elements inside the modal container. Marker
clicks are handled through `ObjectManager`'s own click event and the card is rendered directly.

Consequences: no selectors against undocumented ymaps class names; the card does not depend on
whether a point is currently clustered, which retires the s46 gotcha
`ymaps-camera-moves-are-async`'s second half ("a placemark folded into a cluster has no balloon");
`setBounds()` is needed only to *show* a point, never to un-cluster it before opening something.

### D-3. The provider narrows to the map. Panels belong to the framework

`Map_Provider` currently says "the provider owns everything drawn inside the container". That was
written for the balloon model. It changes.

Rationale — three reasons, in order of weight:

1. **The card renders framework-owned data**: `selectable.reason`, i18n labels, weight limits,
   payment methods, and the escaping rules of `Pickup_Point::to_browser_array()`. Roughly 250 lines
   presenting a framework value object currently live inside an adapter to one specific map library.
   That is inverted layering regardless of how many providers exist.
2. `map-provider-yandex.js` is already 1477 lines and this rework adds a tab bar, sidebar states and
   icon layouts to it.
3. Breaking the contract is free **now** — the v2 clean-break policy forbids shims for internal APIs
   and `Map_Provider` has no external consumer yet. It will not be free after two plugins ship.

Honest counterweight, recorded so it is not rediscovered as an objection: today this serves exactly
one provider, because `Embedded_Map_Provider` owns its whole container by nature. The co-located
point case does **not** by itself require the split — a provider could group internally — it merely
adds more collection-level logic that reason 1 wants out of the adapter.

### D-4. Co-located points: group by position, tab bar in the card. Coordinates are never moved

Clustering in 2.1 is by **pixel grid**, so two points with identical coordinates fall in the same
grid cell at every zoom level and can never be separated by zooming. The reference technique
(collapse bounds onto the point + `checkZoomRange`) therefore never works for them, and
`placemark.balloon.open()` on a permanently clustered point throws.

Russian Post solves this correctly and we copy their guard verbatim (see §7.5): before attempting to
zoom, they check whether **all** features in the cluster share identical coordinates and skip the
attempt if so.

CDEK's own implementation contains the tab bar already
(`clusterBalloonContentLayout`, `.my-balloon-tabs__main-tabs`) but its grouping counter is defective:

```js
// woodev-yandex-map-plugin.js:327-331 — `elem` is the container id STRING, it has no .hash
if ( mapper.has( hash ) ) { mapper.set( hash, mapper.get( elem.hash ) + 1 ) }   // → NaN
else                      { mapper.set( hash, 1 ) }
```

`mapper.get( undefined ) + 1` is `NaN`, so every duplicate after the first gets `orderNumber: NaN`
and the template's `{% if (geoObject.properties.orderNumber == 1) %}` never matches for it. In the
same block, the `.map()` branch for an already-seen `item.code` returns nothing, pushing `undefined`
into the array handed to `objectManager.add()`. Both are real; neither is reproduced here.

Grouping key: coordinates rounded to **4 decimals** (≈ 11 m). Exact float equality is too brittle
(a carrier returns `55.7558` and `55.75580001`), and at zoom 18 at latitude 55° one pixel is ≈ 0.36 m
while a 45 px icon covers ≈ 16 m, so anything closer than that overlaps visually anyway.

Coordinate jitter was considered and rejected: it makes the pin lie about where the building is, the
offset cannot be correct at more than one zoom level simultaneously, and three or more co-located
points would need a circular layout algorithm.

### D-5. Icons: the plugin supplies URLs, the framework owns geometry

The plugin supplies up to four URLs (type × state). The framework owns the two boxes and two anchors,
taken from CDEK's live values:

```css
.woodev-pickup-pin                      { width: 45px; height: 45px; }  /* anchor [-22, -23] */
.woodev-pickup-pin[data-state="active"] { width: 50px; height: 70px; }  /* anchor [-25, -40] */
```

This reproduces CDEK's behaviour (active state expressed by size, single image) *and* the Yandex
reference's behaviour (two images) without the plugin ever handling pixels. A plugin that omits
`active` gets `default` rendered in the larger box.

The marker uses an **HTML icon layout** via `templateLayoutFactory`, not `iconLayout: 'default#image'`,
so the framework can overlay the group-count badge and express state as a class. The framework still
passes ymaps the matching `iconImageSize` / `iconImageOffset`, because ymaps cannot read them from CSS
and needs them for hit-testing and `getShape()`.

### D-6. Search answers "which points are near me", not "where is this address"

The operator's diagnosis, confirmed by Russian Post's own placeholder — literally `placeholder="Ваш
адрес"`, not "find a pickup point". Customers type **their own** address.

Both reference models fail differently:

- CDEK searches only the loaded point pool (free, no quota) — a customer's home address matches
  nothing, so the search almost never finds anything;
- Yandex geocodes the address and zooms to it — if no point happens to be nearby, the customer sees
  an empty map and concludes there are none. Russian Post has the same flaw (`showResult()` just
  moves the camera).

Our model, which none of the three does:

1. typing filters the loaded pool instantly (free) **and** queries `ymaps.suggest()`, rendered as two
   sections;
2. picking a point result opens its card;
3. picking an address result geocodes it once, drops a "your address" pin, and **fits the camera to
   the address plus the N nearest points** — never to the address alone;
4. the sidebar opens automatically, sorted by distance **from the searched address**, showing
   distances;
5. if the nearest point is beyond the threshold, an explicit empty state with the nearest point's
   distance and a "show it" button — never a silently empty map.

`N` is a framework constant of **3**, overridable via `woodev_pickup_search_nearest_count`. It is
deliberately **not** a plugin parameter: what varies is network density, and that varies between
cities of one carrier far more than between carriers, so a single per-plugin number cannot track it —
whereas fitting to N nearest adapts automatically, because it works in geometry rather than in
kilometres (a dense centre yields a ~500 m frame, a sparse suburb an ~8 km one).

The control is `ymaps.control.SearchControl` with **our own view** via `templateLayoutFactory` — the
Russian Post model. Its engine is kept (`search()`, `getResultsCount()`, `showResult()`); only its
chrome is replaced. `provider.geocode` is fully ours, which is what lets one result set carry both
sections; our own results-click handler branches per section rather than always calling
`showResult()`.

### D-7. Initial viewport: buyer's city, then the plugin's hardcoded default. No geolocation

Under **bulk** no geocoding is needed at all: points arrive for the locality and
`setBounds( objectManager.getBounds() )` frames exactly the buyer's city. The cascade only matters
under **viewport** (a bbox is needed before the first request), when the locality is unknown, or when
the city has no points.

```
viewport strategy:  ymaps.geocode( locality )  →  plugin default
bulk strategy:      setBounds( objectManager.getBounds() )  after the first load
```

Browser/IP geolocation was considered (Russian Post falls back to `ymaps.geolocation.get()`) and
rejected by the operator: a customer behind a VPN would be sent to Amsterdam, and the
"locality unknown" case is rare enough that a predictable default is preferable.

The plugin's default is **hardcoded coordinates plus zoom**, not a city name, so the fallback path
never calls the geocoder:

```php
$default_location = [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ];   // Moscow, in the PLUGIN
```

It is a **required** constructor argument, matching the precedent already set by
`Yandex_Map_Provider::__construct( string $fallback_key, … )` — a shared, load-bearing value is an
obligation, never an optional parameter an author can forget. The framework itself knows no regional
default: Moscow is CDEK's and Yandex.Delivery's default, not the framework's.

`minZoom: 8`, `maxZoom: 18`. Eight is not taste: under the viewport strategy a zoom of 4 produces a
bbox wider than our 10°-per-side server cap, the server correctly refuses it, and the customer is told
there are no points in a place that has them. If a bbox still exceeds the cap, the map shows an
explicit "zoom in" message rather than an error. Eighteen matches all three references; note that
ymaps' own default `maxZoom` is 23, so this must stay explicit.

### D-8. Map appearance: a tile-layer seam, empty by default

CDEK layers 2GIS tiles over the ymaps engine (`map.layers.add(...)` + `map.copyrights.add('© 2Gis')`).
The operator's reason was personal aesthetic preference; with style presets unavailable on 2.1 (D-1),
a custom tile layer is the only lever that exists.

`mapConfig` therefore accepts optional `layers` and `copyrights`, empty by default (= Yandex tiles).
Choosing what to layer is the plugin's decision, including whether the terms of use of both parties
permit it.

### D-9. `Pickup_Point` gains `services`. Schedule stays a string

Russian Post exposes per-point services distinct from payment methods ("Примерка", "Проверка
вложений", "Проверка исправности", "Частичный выкуп", "Возможен возврат"). These genuinely affect
choice, cost nothing to render (a plugin that supplies none simply has no section), and the field is
pure presentation.

Structured schedules were considered and rejected for this rework: carriers' formats are mutually
incompatible so normalisation would land on **every** plugin, and an "open now" badge needs a
per-point timezone to avoid lying. `work_time` stays a single string. Backlog card.

### D-10. Type filter: checkbox menu with a count badge; filtering location depends on strategy

The UI is Russian Post's (a menu of checkboxes plus a badge), not a `ListBox`. Russian Post sends the
selected types to the server (`pvzType`); that is right for viewport — do not fetch what will not be
shown — but wasteful for bulk, where the whole locality is already loaded and toggling a checkbox
would trigger a refetch.

One UI, and the strategy chooses where filtering happens:

- **bulk** → `objectManager.setFilter()` on the client;
- **viewport** → `types` added to the query, so `Point_Query`, the REST route and `Point_Source` all
  gain the parameter.

### D-11. `ObjectManager` replaces `Clusterer`

Both CDEK and Russian Post already use `new ymaps.ObjectManager({ clusterize: true, … })`. It stores
data as JSON and creates overlays lazily for visible objects only, and it provides `setFilter()` and
`getObjectState()` natively — the latter being exactly what s46 hand-rolled for the clustered-point
case.

### D-12. Locale is passed explicitly and resolved from the site locale

Verified list of locales accepted by 2.1 (`lang`, format `language_REGION`, ISO 639-1 + ISO 3166-1):
**`ru_RU`, `en_US`, `en_RU`, `ru_UA`, `uk_UA`, `tr_TR`**. Hyphenated forms (`ru-RU`) are accepted only
for backward compatibility and are not recommended.

> Caveat to confirm on the rig: the Russian localisation page lists all six; an English-language page
> in search results listed only four (`ru_RU`, `en_US`, `tr_TR`, `uk_UA`). Cheap to verify live.

Resolution rule:

```
site locale ∈ { ru_RU, en_US, en_RU, ru_UA, uk_UA, tr_TR }  →  use as-is
otherwise                                                    →  en_US
```

`en_US` is WordPress's own default locale, so the fallback is only reached for genuinely foreign
locales. No `en_*` → `en_RU` remapping.

**Known consequence, accepted:** the region part drives units — for regions `RU`, `UA`, `TR` ymaps
shows distances in kilometres, for `US` in miles. A store running `en_US` therefore gets a map
labelled in miles. Our own distances (sidebar, search results) are computed by us and are formatted
from the same region, so the two never disagree on screen.

`lang` is emitted as an explicit `mapConfig` field in addition to being baked into `scriptUrl`,
because the panels read it for distance formatting.

### D-13. The modal becomes a general framework component, not a pickup one

`pickup-modal.js` is a generic dialog that happens to have been written for the map: shell, backdrop,
focus trap, Esc, focus return. Nothing in it is about pickup points. It moves out of the shipping
module and becomes reusable framework furniture:

| Before | After |
|---|---|
| `woodev/shipping-method/assets/js/frontend/pickup-modal.js` | `woodev/assets/js/frontend/woodev-modal.js` |
| `window.WoodevPickupModal` | `window.WoodevModal` |
| handle `woodev-pickup-modal`, registered by `Pickup_Handler` | handle `woodev-modal`, registered once framework-side; `Pickup_Handler` only declares the dependency |
| styles inside `pickup.css` | `woodev/assets/css/frontend/woodev-modal.css` |

Everything pickup-specific leaves it. The component takes a title, a content element (or a render
callback), and a `modalId`; the pickup layer supplies its own. BEM: `.woodev-modal`,
`.woodev-modal__content`, `.woodev-modal__header`, `.woodev-modal__body`, `.woodev-modal__close`,
`.woodev-modal-backdrop`.

**Responsive behaviour follows WooCommerce's own backbone modal**, which is what merchants and
integrators already recognise:

```css
.woodev-modal__content {
    position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
    max-width: 100%; min-width: 920px;          /* wide, because a map lives here */
}
@media screen and (max-width: 782px) {
    .woodev-modal__content { width: 100%; height: 100%; min-width: 100%; }   /* full screen */
}
```

782px is WooCommerce's own breakpoint (see the vendored copy of its modal CSS at
`plugins-reference/woocommerce-yandex-delivery/assets/css/frontend/backbone-modal.css`), and it is
the same number the map's own layout uses, so there is one breakpoint in the feature, not two.

### D-14. A public event surface, in two layers

Today the modal fires **nothing**. A plugin consuming the framework cannot hook the lifecycle of the
map at all — the only public symbol is the constructor. That is a gap, not a simplification: the
reference integrations are built entirely on WooCommerce's five modal events
(`wc_backbone_modal_loaded`, `_before_remove`, `_removed`, `_response`, `_validation`).
`wc-yandex-delivery-modal-standard-map.js` does all of its map initialisation inside
`wc_backbone_modal_loaded` and destroys the map in `wc_backbone_modal_before_remove`.

Because the modal is now generic (D-13), the events split into two layers. Generic events carry a
`modalId` so listeners can filter — exactly WooCommerce's `target` argument, which the reference uses
as `if ( 'wc-modal-yandex-delivery-map' === target )`.

**Modal layer** (`woodev-modal.js`):

| Event | Payload | When |
|---|---|---|
| `woodev_modal_opened` | `{ modalId, context }` | DOM in place, focus trapped |
| `woodev_modal_before_close` | `{ modalId, reason }` | **cancelable** — `preventDefault()` aborts the close |
| `woodev_modal_closed` | `{ modalId, reason }` | `reason`: `select` / `escape` / `backdrop` / `button` |

**Pickup layer** (`pickup-mount.js` / `pickup-panels.js`), `modalId: 'woodev-pickup-map'`:

| Event | Payload | When |
|---|---|---|
| `woodev_pickup_map_ready` | `{ fieldId, provider }` | the provider's `init()` resolved |
| `woodev_pickup_points_loaded` | `{ fieldId, count, strategy }` | after each point load |
| `woodev_pickup_point_selected` | `{ fieldId, point }` | the customer pressed the CTA |
| `woodev_pickup_error` | `{ fieldId, code, message }` | an error that breaks the whole map |

Two rules inside this:

**Dispatch is a native `CustomEvent` with `bubbles: true` on `document.body`**, so both
`addEventListener` and jQuery `.on()` receive it. The reverse does not hold — `jQuery.trigger()` on a
custom type creates no native event and `addEventListener` never sees it. That asymmetry is already
documented in `pickup-mount.js`'s own docblock for `updated_checkout`, so this is not a style choice.

**Only `before_close` is cancelable.** `point_selected` deliberately is not: the availability verdict
is server-authoritative, and a client-side veto would create a second source of truth.

`woodev_pickup_error` is explicitly a **reporting hook for #130** (the framework's own error
reporter), not only a UI signal. #130's JS scope is `window.onerror` plus a script-URL filter, which
cannot see these failures by construction: we catch them and turn them into a message on screen, so
nothing ever propagates to `window.onerror`. A handled map failure — script blocked, key rejected,
upstream 5xx — is exactly the class of event that reporter exists for, and it must subscribe to this
event separately. Recorded on #130 as well, since that is where the implementer will look.

Side effect worth recording: this plus a public `refresh()` on the open session answers **#148**
(the verdict going stale when the payment method changes while the map is open) without extending the
provider contract — that was one of the three options on that card.

### D-15. One accent colour, plugin default plus merchant override

Today every plugin ships its own map, hardcodes its own element colours and exposes its own
colorpicker setting. That mechanism has to survive the move into the framework.

**One colour, not a palette.** The accent drives the card's CTA, the active list item, the drawer
toggle, the cluster icon and the checkout trigger button. All three references use a single brand hue
for exactly these (`#FCE000` Yandex, `#0a8c37` CDEK, `#1937ff` Russian Post), so a second knob would
be surface nobody asked for. The **contrast colour is derived**, not configured: relative luminance
decides black or white text, because a merchant who picks yellow and is then asked to pick a text
colour will pick wrong.

Resolution mirrors the API key, which already has this exact shape:

```
merchant setting (non-empty)  →  plugin default  →  framework default
```

with a `woodev_pickup_accent_color` filter as the site-level override. The merchant-facing field is a
framework-owned `Woodev_Control::TYPE_COLOR` control contributed through
`Pickup_Handler::get_settings_fields()`, next to the provider's own `map_api_key`.

**The accent lives at the top level of the pickup config, not inside `mapConfig`.** The checkout
trigger button — which is rendered into §8's anchor, outside the modal entirely — needs it too, and
"brand accent" is not a ymaps concept. The provider merely reads it for `clusterIconColor`.

**Delivery is CSS custom properties set through the CSSOM**, never a generated `<style>` block and
never a string-built `style=` attribute:

```js
root.style.setProperty( '--woodev-pickup-accent', accent );
root.style.setProperty( '--woodev-pickup-accent-contrast', contrastFor( accent ) );
```

**This value is validated twice, and that is deliberate.** It arrives from a merchant-editable
setting and ends up inside CSS, so it is untrusted input on a path where a malformed value is not
merely ugly. Server side: `sanitize_hex_color()`, falling back to the plugin default when it returns
null. Client side: refuse anything not matching `/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i`
before it reaches `setProperty()`. Neither check alone is enough — the server one can be bypassed by
a filter returning garbage, and the client one is not authoritative.

Related, and part of the same operator remark: the checkout trigger button has **two states** and
currently only one label. It gains a second i18n key, so a customer who has already chosen a point
sees «Выбрать другой пункт выдачи» rather than the same «Выбрать пункт выдачи» as before choosing.

## 4. Architecture

```
FRAMEWORK                              PROVIDER (yandex)
─────────────────────────────          ──────────────────────────
woodev-modal.js    generic shell       init( canvasEl, config )
pickup-panels.js   list                setPoints( groups )
                   card + tab bar      focusGroup( key )
                   position grouping   setTypeFilter( codes )
                   search view         destroy()
                   service chips
pickup-datasource.js                   ── events ──▶
pickup-mount.js                          pointClick( groupKey )
                                         boundsChange( bbox )
                                         visibleChange( keys )

Embedded provider: owns_chrome = true → the framework renders no panels and hands the
whole container to the carrier's widget.
```

Position grouping lives in the framework. The provider receives **groups**, not points, and knows
only each group's size (for the badge).

| File | Change |
|---|---|
| `woodev/assets/js/frontend/woodev-modal.js` | **moved** from `shipping-method/…/pickup-modal.js`, generalised, gains events (D-13, D-14) |
| `woodev/assets/css/frontend/woodev-modal.css` | **new** — modal chrome + WC-pattern responsive, extracted from `pickup.css` |
| `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js` | rewritten, ~1477 → ~400 lines |
| `woodev/shipping-method/assets/js/frontend/pickup-panels.js` | **new**, ~500–600 lines |
| `woodev/shipping-method/assets/css/frontend/pickup.css` | rewritten |
| `woodev/shipping-method/assets/js/frontend/pickup-mount.js` | adapted to the new config |
| `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js` | declares `owns_chrome` |
| `woodev/shipping-method/map/interface-map-provider.php` | contract rewritten |
| `woodev/shipping-method/map/class-yandex-map-provider.php` | `lang`, `layers`, `copyrights` |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | icons, default location, new i18n keys |
| `woodev/shipping-method/pickup/class-pickup-point.php` | `services` |
| `woodev/shipping-method/pickup/class-point-query.php` | `types` |
| `woodev/shipping-method/rest-api/class-pickup-controller.php` | `types` |
| `woodev/class-map.php` | regenerated if any PHP class moves (`bin/generate-class-map.php`) |

Moving the modal out of `shipping-method/` changes who registers it. The handle `woodev-modal` is
registered once framework-side so any subsystem can depend on it; `Pickup_Handler::enqueue_assets()`
stops registering `woodev-pickup-modal` at `js/frontend/pickup-modal.js` and simply lists
`woodev-modal` as a dependency.

## 5. PHP contracts

```php
interface Map_Provider {
    public function get_id(): string;
    public function get_label(): string;
    public function get_script_handle(): string;
    public function get_settings_fields(): array;
    public function get_js_config( array $context ): array;
    public function owns_chrome(): bool;          // NEW — embedded returns true
}
```

Plugin-side construction — `default_location` required, `point_icons` optional:

```php
new Pickup_Config(
    default_location: [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ],
    point_icons: [
        'pvz'      => [ 'default' => '…/pvz.svg',      'active' => '…/pvz-active.svg' ],
        'postamat' => [ 'default' => '…/postamat.svg' ],   // active falls back to default
    ],
    accent_color: '#FCE000',      // plugin default; the merchant may override it in settings
);
```

Top-level browser config (outside `mapConfig`, because the checkout trigger button needs it too):

```php
'accentColor' => '#fce000',       // resolved: merchant → plugin → framework, then sanitize_hex_color()
```

`mapConfig` shape:

```php
[
    'scriptUrl'  => '…',                 // lang already inside the URL
    'ns'         => 'WoodevPickupMap',
    'hasApiKey'  => true,
    'lang'       => 'ru_RU',             // NEW — panels read it for distance formatting
    'layers'     => [],                  // NEW — optional custom tile layers
    'copyrights' => [],                  // NEW
]
```

Model changes: `Pickup_Point` gains `services: string[]`, escaped through `esc_html()` alongside the
other display fields in `to_browser_array()`. `Point_Query` gains `types: string[]`.

## 6. Layout

```
STATE 1 — map, sidebar closed (default)
┌─ Пункты выдачи заказов ───────────────────────── ✕ ─┐
│ ┌───────────────────────────────────────────────┐   │
│ │ 🔍 Ваш адрес                                  │   │
│ │ [ Тип пунктов ▾ ②]                            │   │
│ │                                               │   │
│ │           ◉         ⬤12                       │   │
│ │      ◉                        ◉               │   │
│ │  [+]                                    ┌───┐ │   │
│ │  [−]                                    │ ◀ │ │   │
│ └─────────────────────────────────────────└───┘─┘   │
└─────────────────────────────────────────────────────┘

STATE 2 — sidebar open, full height, map shifted by map.margin
┌────────────────────────────┬──────────────────────┐
│ 🔍 Ваш адрес               │ Пункты выдачи        │
│ [ Тип пунктов ▾ ]          │ в этой области       │
│                            ├──────────────────────┤
│        ◉        ⬤12        │ Ленина, 5            │
│                            │ ПВЗ «Магнит»         │
│                       ┌───┐│ Москва               │
│  [+]                  │ ▶ │├──────────────────────┤
│  [−]                  └───┘│ Тверская, 12         │
└────────────────────────────┴──────────────────────┘
   sorted by distance from the anchor, capped at 300,
   recomputed on boundschange, active item highlighted

STATE 3 — point selected: the card covers the list
┌────────────────────────────┬──────────────────────┐
│                            │ Постамат │ ПВЗ    ✕  │ ← tabs only when group > 1
│                            │ ▔▔▔▔▔▔▔▔             │
│        ◉        ┌───┐②     ├──────────────────────┤
│                 │ 📦│      │ Постамат №4          │
│                 └───┘      │ Индекс: 101000       │
│                            ├──────────────────────┤
│                            │ Адрес:               │
│                            │ Москва, Ленина 5     │
│                            │ ▸ Как добраться      │
│                            ├──────────────────────┤
│                            │ Методы оплаты:       │
│                            │ Картой, наличными    │
│                            ├──────────────────────┤
│                            │ Услуги:              │
│                            │ (Примерка) (Выкуп)   │
│                            ├──────────────────────┤
│                            │ Телефон · Часы · Вес │
│                            ├──────────────────────┤
│                            │ ⚠ Оплата при получен.│ ← sticky footer
│                            │   недоступна         │
│                            │ [ Забрать здесь ]    │
└────────────────────────────┴──────────────────────┘

STATE 4 — address searched
┌────────────────────────────┬──────────────────────┐
│         ◉ 1.2 км           │ Ближайшие к          │
│                            │ «Москва, Тверская 1» │
│    ★ ваш адрес             │ [ × сбросить ]       │
│      ◉ 0.4 км              ├──────────────────────┤
│                            │ 0.4 км · ПВЗ «Магнит»│
│              ◉ 2.1 км      │ 1.2 км · Постамат №4 │
└────────────────────────────┴──────────────────────┘
```

Sidebar geometry: `position: fixed; top: 0; bottom: 0; right: 0; width: min(320px, 100% - 48px)`,
plus `map.margin.addArea({ right: <width>, top: 0, height: '100%' })` while open. Toggle button fixed
bottom-right. **No mobile bottom sheet** — the reference uses the same side panel, merely narrower,
and the current implementation's bottom-sheet variant is dropped.

The card covers the list (higher `z-index`), matching the reference where the two panels occupy the
same place. CTA reads «Продолжить оформление заказа» when this point is already the selected one, and
«Забрать здесь» otherwise.

## 7. Behaviour details

### 7.1 Sidebar contents

Only points currently inside the viewport, via `ymaps.geoQuery(...).searchInside(map)`. Sorted by
distance from the **anchor**: the searched address when a search is active, otherwise the map centre —
one rule, not two modes. Capped at 300 entries. Recomputed on `boundschange`. Item shows
`short_address` in bold, then `name` and `locality`. Empty state when nothing is in frame.

### 7.2 Distances

Haversine, computed by us, formatted from the region part of the resolved locale (§D-12). A pure
function, unit-testable without a map.

### 7.3 Grouping

```
key( point ) = round( lat, 4 ) + ',' + round( lng, 4 )
```

A pure function over the point array. The card's tab bar appears only when a group holds more than one
point; the tab label is the point's `type.label`, falling back to `name` when two points in a group
share a type. The marker carries the first point's icon plus a count badge.

### 7.4 Filtering

`objectManager.setFilter()` under bulk; `types` in the query under viewport. The badge shows the
number of active type filters.

### 7.5 Opening a point — the Russian Post guard

```js
var st = om.getObjectState( id );

if ( st.isClustered ) {
    var same = st.cluster.features
        .map( f => f.geometry.coordinates.join( '' ) )
        .every( ( c, _, all ) => c === all[ 0 ] );

    if ( map.getZoom() === map.zoomRange.getCurrent()[ 1 ] || same ) {
        openCard( groupKey );                       // zooming cannot help — render directly
    } else {
        map.setBounds( [ c, c ], { checkZoomRange: true, zoomMargin: 0, useMapMargin: true } )
           .then( () => openCard( groupKey ) );      // re-read getObjectState() after the move
    }
} else {
    openCard( groupKey );
}
```

Because the card is our own DOM, `setBounds()` here only frames the point; nothing depends on it
having un-clustered. `setBounds()` returns a promise and must be awaited (s46 gotcha
`ymaps-camera-moves-are-async`, now confirmed by the API reference as well), and concurrent camera
moves still need sequencing — two moves are not guaranteed to resolve in click order.

### 7.6 Grey tiles on a single-point city (issue #150)

`checkZoomRange: true` is documented as exactly the remedy: it "queries the server for the range of
zoom levels permitted for the map centre" and "prevents grey unloaded tiles". Our three `setBounds()`
calls already pass it and the map is built with `maxZoom: 18`. This is therefore expected to be a
**live-verification** task, not a redesign — but s46 twice proved that green code is not working
behaviour, so both strategies must be exercised on the rig with a real key, including the click-driven
degenerate case in §7.5.

## 8. Testing

- **jest**: grouping (identical, near-identical, three-way, rounding boundary), haversine and
  formatting per region, nearest-N selection, locale resolution, search result merging (points +
  geocoder), sidebar sorting and the 300 cap, icon-set fallback (`active` missing → `default`).
- **jest (modal)**: every event fires once with the documented payload; `before_close` is cancelable
  and `preventDefault()` genuinely aborts the close; `modalId` is carried on all three modal events;
  events reach both `addEventListener` and jQuery `.on()`; focus trap, Esc and focus return still
  behave exactly as before the move.
- **PHPUnit**: `services` escaping in `to_browser_array()`, `types` parsing in `Point_Query`, required
  `default_location`, `lang` resolution, `layers`/`copyrights` passthrough, `owns_chrome()`, and the
  `woodev-modal` handle being registered framework-side with `Pickup_Handler` depending on it.
- **Rig (chrome-devtools MCP, real Yandex Maps key, ports 8973/8974)**: both strategies; sidebar
  closed → open → full height; card with and without a tab bar; co-located points; search by own
  address including the "nothing nearby" empty state; type filter under both strategies; **#150 on
  both the initial-fit and click-driven paths**; the modal at ≤ 782px rendering full-screen.

Mutation testing must cover **values and content**, not only branches — see gotcha
`mutation-sweep-branch-only-false-confidence`. Line length must be measured by hand: `phpcs` does not
enforce it and does not scan `tests/`.

## 9. Spun off to the backlog

- Server-side pagination for the viewport strategy (Russian Post uses `pageSize: 200` + `page`); a
  dense bbox currently returns everything in one response.
- Structured per-point schedule (D-9).
- Mixed i18n source languages: the framework catalogue (`woodev/languages/`) has **English** msgids
  with a `ru_RU` translation, while new SP-5 code authors Russian source strings, which no catalogue
  can translate.

## 10. Related

- `2026-07-30-sp5-pickup-map-design.md` — the original SP-5 design this amends
- `2026-07-06-checkout-field-layer-design.md` — §8, which owns the anchor this mounts into
- `docs-internal/gotchas/ymaps-camera-moves-are-async.md`
- `docs-internal/gotchas/mutation-sweep-branch-only-false-confidence.md`
- `docs-internal/gotchas/phpcs-does-not-enforce-line-length.md`
- Issue #150 — single-point city zoom breaks tiles
- Issue #148 — the verdict going stale while the map is open; D-14 answers it
- Issue #130 — the framework error reporter; must subscribe to `woodev_pickup_error` (D-14)
