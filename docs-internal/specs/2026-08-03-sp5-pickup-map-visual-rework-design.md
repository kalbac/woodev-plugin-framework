# SP-5 pickup map — visual rework design

> Session 50 · 2026-08-03 · branch `feat/pickup-map` (PR #149, not merged)
>
> **Extends, does not replace, `2026-08-01-sp5-pickup-map-rework-design.md`.** That spec's D-1…D-15
> remain in force except where section 10 below records an explicit deviation. Where the two
> disagree, this document wins for presentation and the older one wins for everything else.

---

## 1. Problem

The map works functionally on the rig — points load, markers draw, the sidebar and card operate, the
order carries the chosen point. The operator rejected it visually for the third time: "everything
works, but crooked". His written review plus 14 screenshots (rig, Yandex.Delivery, Russian Post) are
the input to this design; they lived in `docs-internal/review/pickup-map-visual/` during the session
and are deleted once the work lands.

Two references, both Yandex Maps 2.1, both the operator's own production code or a widget he has
studied:

- **Yandex.Delivery** — `plugins-reference/woocommerce-yandex-delivery/`
  (`assets/js/frontend/wc-yandex-delivery-widget-map.js`, its CSS, `templates/html-modal-map.php`,
  `assets/css/frontend/backbone-modal.css`). This is the operator's own implementation and the
  primary target.
- **Russian Post** — `https://widget.pochta.ru/map/` (no source in this repo). Reference for the
  search field, the type filter and the sidebar list item.

The verdict to reproduce: "minimum task was to make it like the reference Yandex map".

## 2. Scope

**In scope.** Everything the customer sees inside the modal: the dialog shell, loading and empty
states, search, type filter, zoom control, markers and their click behaviour, the sidebar list, the
point card, the style-isolation contract, the ≤782px layout. Plus the rig fixture, which today cannot
exercise a third of these surfaces.

**Out of scope, deliberately.**

- The point-query contract (`?locality={name}` → carrier-defined parameters). Operator's decision
  2026-08-03: a separate sub-project after this one, with its own brainstorm → spec → plan. Filed as
  **#159**, board №6, status `Бэклог`.
- Every architectural contract already proven on the rig: the `dataSource` inversion, the
  `woodev/v1` REST route, the server-side `selectable` verdict, address replacement, persistence, the
  A2 checkout gate, the two loading strategies, the public event surface, `ObjectManager`, co-located
  point grouping.

**Approach.** The presentation layer is rewritten; the architecture is not touched. Porting the
reference implementation wholesale was considered and rejected: it would discard what is already
correct and rig-proven, and it would reinstate the ymaps balloon that D-2 dropped on purpose — the
balloon is exactly what breaks on identical coordinates (a CDEK pickup point and a postamat in one
building), the case the reference does not have to handle.

## 3. Root causes found in the current code

Five concrete defects, all of them execution drift from the 2026-08-01 spec rather than a wrong
decision in it. Each is named here because the fix is otherwise invisible in a task list.

1. **The dialog has no size of its own.** `.woodev-modal__content` sets no `height`; the only source
   of height in the whole tree is `.woodev-pickup-map { height: min(80vh, 800px) }`. Before the map
   mounts, the dialog is one header tall.
2. **Every panel is `position: fixed`.** `.woodev-modal__content` carries
   `transform: translate(-50%, -50%)`, which makes it the containing block for fixed descendants. So
   `.woodev-pickup-list`, `.woodev-pickup-card` and `.woodev-pickup-list__toggle` position against the
   *dialog*, header included — the sidebar covers the header, and while the dialog is one header
   tall, the toggle button lands on top of the close button. The reference avoids this by accident:
   its drawer lives inside a ymaps control pane, which is itself transformed and begins below the
   header.
3. **The `SearchControl`'s options never reached it.** ymaps controls take
   `{ data, options, state }`; `_buildSearchControl()` passes `provider`, `layout`, `resultsLayout`
   and `noPlacemark` at the root of the constructor argument. All four are ignored, so the control
   keeps its default chrome (the English "Address or place" box with the yellow Search button) *and*
   its default geocoder — which is why search finds addresses anywhere on Earth regardless of the
   displayed city.
4. **Markers have no hit area.** `_buildFeature()` sets `iconImageSize`/`iconImageOffset`, which only
   apply to `iconLayout: 'default#image'`. A custom HTML layout takes its clickable region from
   `iconShape`, which is never set — so clicks pass through the marker onto the map's POI layer and
   Yandex's own organisation card opens.
5. **The zoom control is the default slider at `left: 70`.** The reference uses `left: 12,
   bottom: 70`, and Russian Post uses two square buttons.

## 4. Decisions

### V-1. The modal owns its size and opens at full size before any content exists

`WoodevModal` accepts a size at construction and applies it to the dialog immediately, in
`buildDom()`, not when a consumer mounts something. An empty modal is the same box as a full one.

```js
new WoodevModal( {
    title: '…',
    width:     920,              // px or any CSS length; → min-width on the dialog
    bodyHeight: 'min(80vh,800px)' // → height on the body; the dialog is header + body
} );
```

Both are optional and both have generic defaults, because the shell serves consumers other than the
map. `max-height: calc(100vh - 40px)` stays on the dialog so no consumer can exceed the viewport.
The pickup handler passes the numbers; the shell only applies them. This is what D-13 already meant
by "general framework component" — sizing is chrome, not content.

### V-2. The dialog's structure and styling follow `wc-backbone-modal`

Not copied, matched: same three-part structure (`content` → `header` with `h1` + close button →
`body`), same 5px/10px header padding, same `#fcfcfc` header on a `#ddd` bottom border, same 18px/700
title, same 38×38 close button with the left border and the hover swap, same `min-width: 920px`, same
782px breakpoint, same fixed + `translate(-50%, -50%)` centring. The differences are ours and
deliberate: `rgba()` backdrop instead of `opacity` (gotcha
`modal-backdrop-opacity-dims-the-whole-dialog`), an `×` glyph replaced by the reference's inline SVG
close icon, `overflow: hidden` on the body so nothing a consumer mounts can escape the frame, and our
own `woodev_modal_*` events.

We do not adopt `wc-backbone-modal` itself, for the reasons already recorded in `woodev-modal.js`: the
blocks-checkout adapter needs the same dialog where Backbone is not available, and the storefront
should not gain a Backbone/underscore dependency.

### V-3. One stage element is the containing block. No panel is `position: fixed`

A new `.woodev-pickup-stage` fills the modal body (`position: absolute; inset: 0`) and is the
positioning context for everything the map feature draws. Every panel becomes `position: absolute`
against it.

The stage begins *below* the header, so the sidebar cannot cover the header and the toggle button
cannot reach the close button — not by tuning offsets, but structurally.

### V-4. Loading is three-staged, and the modal is closable throughout

| Stage | What is true | What is shown | Map usable |
|---|---|---|---|
| 1 | Modal open, map script/canvas not ready | Centred spinner over the body, additively | — |
| 2 | Map drawn, points in flight | Spinner over the stage; canvas gets `pointer-events: none` | no |
| 3 | Points in | Nothing | yes |

The overlay is always additive — never a replacement for the body — because the consumer is drawing
into that body at the same moment (settled in s48, kept). The close button, the backdrop and Escape
work in every stage: a customer who changes their mind must not have to wait for a third-party
script.

### V-5. Every customer-facing string is plugin-overridable

An empty result is domain language, not framework language: Russian Post has no pickup points, it has
post offices, so "В выбранном населённом пункте нет пунктов выдачи" is wrong text for it.

`Pickup_Handler::get_js_config()` already carries the full string map under `i18n` — 30-odd keys, all
framework defaults. Rather than growing a second, parallel `messages` array beside it, the existing
map becomes the override surface: the assembled array passes through

```php
apply_filters( 'woodev_pickup_map_i18n', $strings, $this->plugin_id );
```

before it is emitted, and a plugin overrides any key it disagrees with. One string system, not two.

One key is genuinely missing today and is added: **`emptyLocality`** — "there are no points in the
locality you asked for", which is distinct from the existing `emptyInView` ("none in *this view*",
a viewport-strategy statement) and from `noResults` (search found nothing).

The empty/error state renders as a centred card over the map, never as a replacement for the
interface — panels and controls keep working so the customer can search or change the filter.

### V-6. Search is entirely ours. `ymaps.control.SearchControl` is dropped

**Deviation from D-6.** D-6 kept the control's engine (`search()`, `getResultsCount()`,
`showResult()`) and replaced only its chrome. In practice we supply our own input, our own dropdown,
our own geocode provider and our own result-click branching — the engine contributes nothing but a
surface for options to get lost on, which is precisely defect 3 above. We call `ymaps.suggest()` and
`ymaps.geocode()` directly and own the control end to end.

The visual target is Russian Post's:

```
┌────────────────────────────────────────────────┬─────┐
│  Ваш адрес                            ✕    🔍  │  ⚏  │
└────────────────────────────────────────────────┴─────┘
┌──────────────────────────────────────────────────────┐
│  ПУНКТЫ ВЫДАЧИ                                       │
│  ПВЗ «Тверская»                                      │
│  Тверская, 5                                         │
│ ──────────────────────────────────────────────────── │
│  АДРЕСА                                              │
│  Москва, Цветной бульвар                             │
│  Московская область, посёлок Власиха, Цветной б-р    │
└──────────────────────────────────────────────────────┘
```

- `<form role="search">` with an `<input>`, a clear button that appears only on a non-empty value,
  and a magnifier icon. The filter button sits immediately to its right, sharing the row.
- Debounce 300 ms, minimum 3 characters.
- Two sections in one dropdown: matched points first (free, from the loaded pool), then address
  suggestions. An empty result renders the `no_results` message rather than an empty box.
- Picking a point → zoom to it and open its card.
- Picking an address → geocode once, drop a "your address" pin, fit the camera to the address plus
  the 3 nearest points, open the sidebar sorted by distance from that address (D-6, kept).
- If the nearest point is beyond the threshold → the explicit "nothing nearby" state with the
  distance and a "show it" button (D-6, kept).
- Visibility: `search => true|false` in the plugin's map config, default `true`. A carrier without a
  geocoding budget can switch it off entirely.

### V-7. Address search is hard-bounded to the loaded point set

`boundedBy` = the bounds of the currently loaded points, `strictBounds: true` — the reference's rule.
Under **bulk** that is exactly the buyer's locality, so "Цветной бульвар" in Tolyatti never appears
for a Moscow buyer. Under **viewport** the loaded set follows the viewport, so the same rule holds
without branching on strategy.

Before the first load there are no bounds; the address section then simply stays empty. Nothing else
happens, because stage 2 has the map blocked anyway.

### V-8. The type filter is Russian Post's menu, gated on the number of types

- An icon button next to the search field, carrying a count badge when the selection is not "all".
- Opens a panel titled «Тип» with one checkbox per type; type labels come from the plugin.
- Rendered **only when the plugin supplies two or more types**. One type → no control at all, as in
  the reference (`if ( point_types.length > 1 )`).
- The last checked type cannot be unchecked — re-check it and return, exactly as the reference does.
  An empty map is never a reachable state.
- Where filtering happens still follows D-10: bulk → `objectManager.setFilter()`; viewport → `types`
  in the query.

### V-9. Markers: framework defaults are inline SVG, and `iconShape` is mandatory

The plugin supplies up to four URLs (type × state) as D-5 already specifies. What is new is the
framework's own default, which today does not exist:

- default state — a filled `map-pin` (Lucide's shape, ISC-licensed, redrawn as one path);
- active state — a filled `map-pin-check`.

They are **inline SVG inside the HTML marker layout**, not files, so they inherit
`--woodev-pickup-accent` as their fill and get a white glyph. That keeps a merchant's accent colour
honoured on a plugin that ships no icons of its own. When the plugin does supply URLs, an `<img>` is
rendered instead and the framework only owns the box.

Geometry stays D-5's: default 45×45 (anchor −22, −23), active 50×70 (anchor −25, −40).

**`iconShape` is set on every feature** — `{ type: 'Rectangle', coordinates: [ offset, offset+size ] }`
matching the current state's box. Without it a custom HTML layout has no hit area and every click
falls through to the map's POI layer.

### V-10. Marker and list item behave identically, and no ymaps balloon ever opens

Clicking a marker and clicking its sidebar row do the same two things, in the same order: move the
camera to the point (`setBounds([c, c], { checkZoomRange: true, useMapMargin: true })`, awaited — see
gotcha `ymaps-camera-moves-are-async`), then open that point's card in the sidebar. Today the map
stays put on a marker click and the ymaps balloon opens instead; both halves of that are wrong.

`geoObjectOpenBalloonOnClick: false`, `clusterHasBalloon: false` and `suppressMapOpenBlock: true`
stay. With V-9's hit area in place, the POI card can no longer be reached through our markers.

The active marker swaps to the active icon and back on card close.

### V-11. The sidebar list is Russian Post's, without a header

The «Пункты выдачи в этой области (5)» header is removed outright — neither reference has one, and it
states something the customer can see. The reset control it carried is replaced by the search field's
own clear button (V-6).

One row = the plugin's type icon (only when the plugin supplies one) + **address in bold** +
name/description in muted grey, separated by a bottom border. Long values ellipsise on one line with
the full text in `title`.

The list keeps `padding-bottom: 28px` so the final row is not swallowed by the map's copyright — the
reference does this and Russian Post does not, which is a flaw the operator named explicitly.

Width `min( 320px, calc( 100% - 48px ) )`.

### V-12. The point card is Yandex.Delivery's, with room to breathe

Top to bottom: an icon chip (only when the plugin supplies an icon) and a close button; title;
subtitle (postal code); then sections separated by `border-top`, each a 14px/500 label over 14px/400
content — Адрес (with «Как добраться» in a `<details>`), Способы оплаты, Услуги, Телефон, Часы
работы, Ограничение веса. A sticky footer holds one full-width accent button.

Sections are `padding: 8px 16px 12px`; the footer is `padding: 16px 16px 36px`. The current card
renders as one unbroken block of small text, which is the whole of the operator's complaint.

The co-located group tab bar lives in the card header, as already designed. When `selectable` is
false the footer button is disabled and the reason is stated above it in a warning box.

### V-13. The zoom control is ours: two square buttons, bottom left

Our own DOM inside the stage, not `ymaps.control.ZoomControl`: two 36×36 buttons stacked, «+» over
«−», 4px radius, white on the `paper` shadow, at `left: 12px; bottom: 70px` — Russian Post's look at
Yandex.Delivery's position, which is what the operator asked for. No other ymaps control is added;
`controls: []` stays.

### V-14. Every element we render is styled explicitly. The font is never ours

A storefront theme that sets `button { display: none }` or `h1 { font-size: 34px; text-transform:
uppercase }` must not be able to break the map. So every element type we emit — `button`, `input`,
`form`, `h1`–`h6`, `p`, `ul`, `li`, `details`, `summary`, `svg`, `img` — carries an explicit
declaration for `display`, `margin`, `padding`, `border`, `background`, `color`, `font-size`,
`line-height`, `letter-spacing`, `text-transform` and `text-align` under our root selector.

The single exception is **`font-family`, which is always `inherit`** — including on `button`, `input`
and `select`, which do not inherit it by default. The map must read as part of the shop, not as a
second typeface pasted onto it.

Rules are scoped to `.woodev-modal` / `.woodev-pickup-stage` plus an element or class; no blanket
`!important`.

### V-15. The ≤782px layout is designed, not merely unbroken

- Dialog fills the screen (`width/height: 100%`, no radius) — already true, kept.
- Sidebar and card take the full stage width and sit above the map.
- The search row spans the width minus the filter button; the dropdown is full-width.
- Buttons are 44×44 minimum: toggle, filter, zoom, card close.
- The toggle sits above the copyright line, not on it.

Verified on the rig at 390px alongside the desktop pass.

### V-16. The rig fixture is extended first, not last

Today `woodev-test-shipping-method` returns five points of one type at distinct coordinates, none of
them unavailable. That fixture cannot show the type filter (needs ≥2 types), the co-located tab bar
(needs identical coordinates), a cluster with its badge, a scrolling list with its bottom padding, or
a disabled select button with its reason — five of the surfaces this rework changes.

The fixture gains: a second type (`POSTAMAT`) with its own icons, ~40 points across Moscow, at least
one pair on identical coordinates, one point with `selectable = false` and a stated reason, one with a
deliberately long address, and points with and without phone/services/weight limit so every card
section is exercised in both presence and absence.

This is task one of the plan. Building the visuals against the current fixture means building five
surfaces blind, which is how the previous two rounds were rejected.

## 5. Layout

```
.woodev-modal.woodev-modal-backdrop                    fixed, inset 0, rgba(0,0,0,.7)
└── .woodev-modal__content                             fixed, centred, min-width 920, max-height 100vh-40
    ├── .woodev-modal__header                          flex 0 0 auto — h1 + close
    └── .woodev-modal__body                            flex 1 1 auto, position relative, overflow hidden,
        │                                              height: min(80vh, 800px)   ← from V-1
        └── .woodev-pickup-stage                       absolute inset 0           ← containing block
            ├── .woodev-pickup-map                     absolute inset 0           (ymaps canvas)
            ├── .woodev-pickup-controls                absolute top 16 left 16    (search + filter)
            │   ├── .woodev-pickup-search
            │   └── .woodev-pickup-filter
            ├── .woodev-pickup-zoom                    absolute left 12 bottom 70
            ├── .woodev-pickup-list                    absolute top/right/bottom 0, width 320
            ├── .woodev-pickup-card                    absolute top/right/bottom 0, width 320, above list
            ├── .woodev-pickup-list__toggle            absolute right/bottom, square 44
            └── .woodev-pickup-overlay                 absolute inset 0           (loading / empty / error)
```

`map.margin.addArea()` keeps reserving the top strip (controls) and the right strip (open panel) so
ymaps' own auto-pan never puts a point under our chrome — this already works and is kept.

## 6. Contracts touched

| Unit | Change |
|---|---|
| `WoodevModal` | `width`/`bodyHeight` options applied at construction; SVG close icon; `overflow: hidden` on body; spinner in the loading overlay |
| `woodev-modal.css` | Restyled to `wc-backbone-modal` parity (V-2) |
| `pickup-panels.js` | Owns the stage; all panels absolute; list header removed; card resectioned; search + filter + zoom controls are its DOM |
| `map-provider-yandex.js` | `SearchControl` removed in favour of direct `suggest`/`geocode`; `iconShape` on every feature; inline-SVG default icons; camera move on marker click; zoom control removed from ymaps |
| `pickup.css` | Rewritten around the stage; explicit element styling; `font-family: inherit` throughout |
| `class-pickup-handler.php` | Emits `messages`, `search` flag, modal size |
| test fixture | V-16 |

No PHP data contract changes: option keys, REST namespace, meta keys, hook names and the point shape
are untouched.

## 7. Verification

Green tests are not evidence here — two sessions produced seven defects against a fully green suite,
and the reason repeated: the fixture was poorer than production. Every surface below is checked in
the browser on the rig (chrome-devtools MCP, port 8973, real Yandex key), at desktop width and at
390px, with a screenshot each:

1. modal opens at full size with a spinner, before the map exists; closes during loading
2. map drawn, points loading: blocked map, spinner over the stage
3. points in: markers with plugin icons, correct hit area, no POI card on click
4. marker click → camera moves → card opens; list row click → identical result
5. sidebar: no header, icon + bold address + muted name, last row not under the copyright
6. card: chip, sections with dividers, sticky footer, disabled button with a reason
7. toggle: square, accent, hides both list and card
8. search: our field, two sections, address outside the city absent, address pick → address + 3
   nearest, sidebar sorted by distance
9. filter: hidden on one type, visible on two, badge, last type cannot be unchecked
10. co-located pair → tab bar; cluster → badge
11. empty locality → plugin's own message
12. zoom control position and behaviour
13. all of the above at 390px
14. a theme with hostile `button`/`h1` rules does not break the map

## 8. Deviations from the 2026-08-01 spec

| Was | Now | Why |
|---|---|---|
| D-6: keep `SearchControl`'s engine, replace its view | Drop `SearchControl` entirely; call `suggest`/`geocode` directly | We supply the input, the dropdown, the provider and the click branching; the engine adds only a surface for options to get lost on — which is exactly what happened |
| D-5: framework owns geometry, plugin owns images | Unchanged, plus a framework default icon pair as inline SVG | A plugin shipping no icons currently gets nothing drawable |
| D-13: modal is generic chrome | Unchanged, plus size is part of that chrome | A dialog that cannot size itself is not self-sufficient |

Everything else in D-1…D-15 stands.

## 9. Related

- `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` — the design this extends
- `docs-internal/gotchas/modal-backdrop-opacity-dims-the-whole-dialog.md`
- `docs-internal/gotchas/ymaps-objectmanager-properties-are-plain.md`
- `docs-internal/gotchas/ymaps-camera-moves-are-async.md`
- `docs-internal/gotchas/ymaps-locale-region-drives-units.md`
- #159 — point-query contract, the sub-project that follows this one
- #158 — the fixture gap, closed by V-16
