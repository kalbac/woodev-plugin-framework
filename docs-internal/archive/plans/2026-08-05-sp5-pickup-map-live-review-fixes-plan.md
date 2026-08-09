> Archived s60 (2026-08-09): SP-5 map iteration shipped; PR #149 merged s51; the do-not-merge banner is obsolete.

# SP-5 Pickup Map — Live-Review Fixes (operator round 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.
> Steps use checkbox (`- [ ]`) syntax. **Every task ends with the full suite, not a targeted run.**

**Goal:** Close the nine defects the operator found in his own live review of PR #149 (05.08.2026),
by making our map behave the way the two references actually behave — not the way the previous
spec *described* them.

**Authority (in this order):**
1. This plan's "Reference truth" section — extracted from the reference code itself, verified 05.08.2026.
2. `plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`
   (620 lines, raw `ymaps.*` — the architectural twin of ours).
3. Russian Post's live bundle `https://widget.pochta.ru/map/main.a7d147fb5267ec1f0932.js`
   (minified; ymaps method names survive minification — grep it, do not guess).
4. `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` (V-1…V-16) and
   `2026-08-01-...-rework-design.md` (D-1…D-15) — **superseded wherever they disagree with 1–3.**

> **Spec §7.5 / "V-10" is WRONG and this plan overrules it.** It states "a marker click and a sidebar
> row click must behave identically". Neither reference does that. That single sentence is the root
> cause of defects 6 and 7 and half of 5. Do not "restore" it.

**Branch:** `feat/pickup-map` (PR #149). **Do not merge.**

---

## Reference truth (what the references ACTUALLY do)

Both references implement the same model. Ours diverges on every row below.

| Behaviour | Yandex.Delivery `widget-map.js` | Russian Post bundle | Ours today |
|---|---|---|---|
| Click a **marker** | `map.panTo(pos, {useMapMargin:true})` — **pan only, zoom untouched** | `i.panTo(a,{useMapMargin:!0})` — identical | `setBounds([p,p])` → **slams to max zoom** |
| Click a **sidebar row** | sets `isAnimating=true` then `setCenter(pos, maxZoom, {useMapMargin:true, duration:400})` | `x=!0` then `i.setCenter(a,s,{useMapMargin:!0,duration:200})` | same as marker click |
| Click a **cluster** | clusterer's own default expand | `setClusterOptions(id,{iconColor:'#FF5A01'})` + default expand | **nothing — no cluster click handler exists** |
| Sidebar reservation | `map.margin.addArea({right:0,top:0,width:320,height:'100%'})` on balloon open / drawer toggle | `{right:0,top:0,width:300,height:'100%'}` — identical | only on `listToggle`, never at init |
| Top chrome reservation | `map.margin.addArea({top:0,left:0,width:'100%',height:'64px'})` | identical, same 64px | **absent** |
| Address search pin | `noPlacemark: true` — no pin, ever | no pin | drops a bare `new ymaps.Placemark(latLng,{},{})` |
| Active icon | `getActiveIcon(type)` — one function, no per-type special-casing | `setObjectOptions(id,{iconImageHref:U(type)})` | same shape (**not** the defect — see D7) |
| Icon anchoring | `iconLayout:'default#image'` + `iconImageOffset:[-25,-25]` — ymaps applies the offset itself | `[-20,-20]`, same | custom HTML layout — **ymaps applies no offset at all** (see D5) |

---

## Root causes (verified by reading, 05.08.2026 — cite these, don't re-derive)

- **D1 search** — `pickup-panels.js:1736` reset handler hides the results, then `searchReset` →
  mount:1295 → `provider.clearAddress()` → `map-provider-yandex.js:1671` emits an EMPTY
  `searchResults` → mount:1394 → `renderSearchResults()` → `:1789 hidden=false` + `:1817` renders
  `noResults`. **The clear round-trip re-opens the box it just closed.**
  `noResults` text = "Пункты выдачи не найдены." (`class-pickup-handler.php:646`) — wrong register.
  Magnifier/clear glyphs are **CSS `content` emoji** — `'\1F50D'` 🔍 (`pickup.css:744`) and
  `'\2715'` ✕ (`:738`). That is the "web 2000" look. Submit has **no disabled logic whatsoever**.
  Nothing closes the results box on pick or on blur.
- **D2 filter** — the fixture has exactly **two** types (`PVZ`, `POSTAMAT` — `fixture-points.php`).
  `updateFilterBadge()` (`pickup-panels.js:1130`) shows the badge only while `partial`, with text =
  *selected* count. With two types, `partial` ⇒ selected is **always exactly 1**. The badge is
  arithmetically incapable of ever reading anything but "1". Checked state lives only inside a
  closed dropdown, on a bare `<input type=checkbox>` (`:1098`).
- **D3 POI** — `_buildMap()` (`map-provider-yandex.js:768`) passes
  `{suppressMapOpenBlock, minZoom, maxZoom}`. **`yandexMapDisablePoiInteractivity` is simply absent**,
  so Yandex's own POI layer keeps its click handlers and opens its organisation card.
- **D4 pin** — `_setAddressPin()` (`:1627`) is literally `new this.ymaps.Placemark(latLng, {}, {})`:
  no options ⇒ Yandex's stock teardrop. Both references pass `noPlacemark: true` and draw nothing.
- **D5 hit-area** — **the one nobody found.** `ICON_BOX.offset = [-22,-23]` is fed to `iconShape`
  (`:277`), which ymaps measures **from the geo anchor**, so the hit rectangle spans `(-22,-23)…(23,22)`
  — centred on the coordinate. But the marker is a **custom `templateLayoutFactory` HTML layout**
  (`:559`), and ymaps places such an element with its **top-left corner AT the anchor** — it does *not*
  apply `iconImageOffset` to custom layouts (that option only exists for `default#image`, which is
  exactly what both references use instead). `.woodev-pickup-marker` (`pickup.css:1206`) carries no
  compensating margin/transform. **Drawn box `(0,0)…(45,45)` vs hit box `(-22,-23)…(23,22)` overlap
  only in `(0,0)…(23,22)` — the top-left quadrant.** That is precisely the operator's "кликнуть можно
  только по очень маленькому пространству в самом верху иконки", and it also means every pin is
  drawn ~22px right / ~23px below its true location.
- **D6 zoom** — `focusGroup()` (`:1719`) always `setBounds([t,t], …)` on a degenerate box, which
  forces the deepest permitted zoom. One code path serves both origins because mount funnels marker
  clicks and row clicks through the same `cardOpened` event (`pickup-mount.js:1176`).
- **D7 postomat icon** — **not a code defect.** The fixture deliberately ships no `active` image for
  `POSTAMAT` (`woodev-test-shipping-method.php:492`), so `normalized_point_icons()` mirrors `default`
  into `active` (by design, D-5/CDEK shape) and only the 45×45→50×70 box changes. The real failure is
  that **size alone is not a legible selected state** — the framework must express focus visually
  even when the plugin ships one image.
- **D8 copyright** — `.woodev-pickup-list` is `position:absolute; top:0; right:0; bottom:0`
  (`pickup.css:398`) and `.woodev-pickup-list__toggle` sits at `right:16px; bottom:16px` (`:557`) —
  both over the bottom-right corner where ymaps renders its copyright. `setMargin()` exists
  (`map-provider-yandex.js:1480`) but mount calls it **only** from `listToggle` (`:1207`), never at
  init and never for the toggle button's own footprint.
- **D9 typography** — muted greys (`#646970` and friends) at 12–13px; operator reads them as
  faint/thin. Cosmetic, lowest priority, last task.

---

## Contract (frozen — every task implements against THIS, not against its own judgement)

Workers must not invent alternative names. These are the seams the parallel tasks share.

### Events

```
Panels → mount:
  cardOpened   { group, pointId|null, origin }   // origin ∈ 'marker'|'list'|'search'|'nearest'
  searchSubmit { query }                          // unchanged
  searchType   { query }                          // unchanged
  searchReset  {}                                 // unchanged
Provider → mount:
  pointClick          key                         // unchanged
  clusterClick        { coords }                  // NEW — mount ignores it; provider self-handles zoom
  searchCleared       {}                          // NEW — replaces the empty-searchResults hack
  searchResults       { points, addresses }       // now fires ONLY for a real completed search
  addressMatchedPoint { key }                     // NEW — the searched address IS one of our points
```

**Two requirements added after the first dispatch (05.08.2026) — both from the operator's review text,
both missed by the first pass over it. T3 and T4 must honour them; the critic must check them.**

- **Every camera move is animated.** Our `setBounds()` calls pass no `duration`, so they cut instantly;
  the operator wrote "плавно" twice. Both references animate everything: Yandex.Delivery uses
  `duration: 400` on fits and Russian Post `duration: 200` on focus hops. Fits get 400, focus/zoom
  hops get 200. An animated move resolves later, so `_focusSeq` sequencing matters MORE, not less.
- **An address that resolves onto one of our own points becomes that point.** Operator: "Если из
  списка выбран адрес точно совпадающая с точкой на карте … фокусируемся только на этой точке и
  делаем её активной, соответственно в сайдбаре должна открываться информация по этой точке."
  `focusAddress()` gains a same-place threshold (30 m, next to `NEARBY_THRESHOLD_M`): within it, emit
  `addressMatchedPoint( { key } )` and do nothing else — no `addressFocused`, no nearest-N fit, no
  camera move. **T3 wires it to `panels.openCard( group, null, 'search' )`**, which routes back through
  `focusGroup( key, { zoom: true } )` and yields the camera move and the active marker for free.

### Provider API changes

```js
provider.focusGroup( key, options )   // options.zoom === true → setCenter(coords, MAX_ZOOM, {useMapMargin:true, duration:200})
                                      // options.zoom !== true → panTo(coords, {useMapMargin:true, duration:200})
                                      //   ...the Russian-Post single-coordinate-cluster guard still applies to the zoom branch only
provider.setMargin( open, width )     // unchanged signature; now ALSO called once at init
provider.clearAddress()               // no longer emits searchResults; emits searchCleared
// DELETED: _setAddressPin, _removeAddressPin, this._addressPin  (D4 — no pin exists any more)
```

### Panels API changes

```js
panels.openCard( group, pointId, origin )   // 3rd arg REQUIRED at every internal call site
panels.hideSearchResults()                  // NEW — empties + hides, never renders an empty state
panels.setSearchBusy( busy )                // NEW — drives the submit button's in-flight disabled state
```

### DOM / CSS contract

| Element | Class / attribute | Owner |
|---|---|---|
| Marker root | `.woodev-pickup-marker`, `data-state="resting"\|"active"` | T1 writes, T4 styles |
| Submit button | `.woodev-pickup-search__submit`, `[disabled]`, `.is-ready` when actionable | T2 writes, T4 styles |
| Reset button | `.woodev-pickup-search__reset` | T2 writes, T4 styles |
| Filter toggle | `.woodev-pickup-filter__toggle`, `.is-filtered` when partial | T2 writes, T4 styles |
| Filter row | `.woodev-pickup-filter__row`, `[data-checked="true"\|"false"]` on the row | T2 writes, T4 styles |
| Icons | inline `<svg>` authored in JS, Lucide geometry, `stroke="currentColor"`, `stroke-width="2"`, 20×20 viewBox 24 | T2 |

**Marker alignment (D5) — the exact fix, agreed once here so T1 and T4 cannot disagree:**
keep `iconShape` centred on the anchor as it is today, and make the DOM match it in CSS —
`.woodev-pickup-marker { margin-left: -22px; margin-top: -23px; }` and
`.woodev-pickup-marker[data-state="active"] { margin-left: -25px; margin-top: -40px; }`,
i.e. exactly `ICON_BOX.offset` / `ICON_BOX_ACTIVE.offset`. **T4 must verify on the rig that the pin
tip lands on the coordinate and that a click anywhere on the artwork registers** — if ymaps turns out
to position custom layouts differently than this plan claims, report back rather than papering over it.

---

## Tasks

Run order: **T1 ‖ T2 ‖ T5** (disjoint files) → **T3** (needs T1+T2 contracts) → **T4** (needs T2 class
names) → **T6** (cosmetic) → Codex critic → operator rig review.

### T1 — `map-provider-yandex.js`: POI, pin, pan/zoom split, cluster, margin
Files: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js`, `tests/js/**` for it.
- [ ] `_buildMap()`: add `yandexMapDisablePoiInteractivity: true` to the options object (D3).
- [ ] Delete `_setAddressPin` / `_removeAddressPin` / `this._addressPin` entirely; `focusAddress()`
      keeps the `addressFocused` emit and the nearest-N fit, and drops nothing on the map (D4).
- [ ] `clearAddress()`: emit `searchCleared`, not an empty `searchResults` (D1).
- [ ] `focusGroup( key, options )` per the contract above (D6). Pan branch must NOT change zoom.
      The single-coordinate-cluster guard and `_focusSeq` sequencing stay, and now guard the zoom
      branch only — a pan can never un-cluster anything, so it needs no post-move re-check.
- [ ] Add a cluster click handler: `objectManager.clusters.events.add('click', …)` → zoom in one step
      toward the cluster anchor (`setCenter(coords, min(getZoom()+2, MAX_ZOOM), {duration:200})`), and
      emit `clusterClick`.
- [ ] jest: assert the POI flag reaches `ymaps.Map`; assert pan-vs-zoom picks the right call for each
      `options.zoom`; assert no `Placemark` is ever constructed by `focusAddress`; assert cluster click
      zooms. **A stub must mirror the real ymaps signature — the `setFilter` incident (s50) came from a
      stub that agreed with the bug.**

### T2 — `pickup-panels.js`: search lifecycle + icons + filter legibility + `origin`
Files: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`, its jest tests.
- [ ] Replace both CSS-`content` emoji with inline Lucide SVG authored in JS (`search`, `x`) per the
      DOM contract (D1c). Remove the `::before` glyph rules' reason to exist (T4 deletes them).
- [ ] Submit button state machine (D1d): `disabled` while `value.trim().length < SEARCH_MIN_CHARS`;
      `disabled` from submit until the next input change; `.is-ready` exactly when enabled. Add
      `setSearchBusy( busy )`. Initial state: disabled.
- [ ] `hideSearchResults()`; call it on point pick, on address pick, and on `focusout` of the search
      wrap when focus leaves it entirely (`relatedTarget` not inside) (D1e).
- [ ] `renderSearchResults()` renders the empty state **only** for a completed search; it is never
      reached by a clear any more.
- [ ] `openCard( group, pointId, origin )` — thread `origin` from every internal caller
      (list row → `'list'`, search point row → `'search'`, show-nearest → `'nearest'`), and include it
      in the `cardOpened` payload (D6).
- [ ] Filter legibility (D2): rows get `data-checked`; toggle gets `.is-filtered` while partial; the
      badge shows the selected count **only when 3+ types exist** (with two types the `.is-filtered`
      state carries the whole signal — a permanent "1" is noise, not information).
- [ ] jest for every one of the above, including "reset does not render the empty state".

### T5 — PHP + fixture (parallel-safe, touches neither JS file)
Files: `woodev/shipping-method/pickup/class-pickup-handler.php`,
`tests/_fixtures/woodev-test-shipping-method/{woodev-test-shipping-method.php,assets/images/}`.
- [ ] `noResults` → `'Поиск не дал результатов.'` (D1b). Check no other string asserts the old text.
- [ ] Add `assets/images/postamat-active.svg` and wire it as `POSTAMAT.active` so the rig exercises
      the two-image path as well as the framework's own one-image treatment (D7).
- [ ] phpcs + phpstan + the PHP unit suite.

### T3 — `pickup-mount.js`: rewire to the new contract
- [ ] `cardOpened` → `provider.focusGroup( key, { zoom: 'marker' !== payload.origin } )` (D6).
- [ ] `provider.on('searchCleared', … panels.hideSearchResults())`; drop the empty-`searchResults`
      assumption (D1a).
- [ ] `provider.on('addressMatchedPoint', … panels.openCard( groupsByKey[key], null, 'search' ))` —
      see the two late additions above the task list.
- [ ] Call `provider.setMargin()` once after `init()` resolves, from the panels' current state, so the
      reservation is correct before the first camera move rather than only after the first toggle
      (D8/5b). Add the top-chrome area the references both reserve (`top:0,left:0,width:'100%',height:'64px'`).
- [ ] `tests/js/pickup-mount.test.js:367` asserts the OLD `noResults` string
      (`'Пункты выдачи не найдены.'`). T5 changed the source string and could not touch this JS test.
      Update it to `'Поиск не дал результатов.'`.
- [ ] jest for the wiring; then the **full** suite.

### T4 — `pickup.css`: marker alignment, active state, control styling
- [ ] Marker offset per the frozen contract above (D5) — **rig-verify the hit area**.
- [ ] Make `[data-state="active"]` unmistakable with a single supplied image (D7): accent ring +
      elevation + scale, not size alone.
- [ ] Style the new search/filter states (`[disabled]`, `.is-ready`, `.is-filtered`, `[data-checked]`);
      delete the two emoji `::before` rules.
- [ ] Keep the sidebar and toggle clear of the copyright strip (D8) — verify the strip is fully
      visible in both open and closed states, desktop and ≤500px.

### T6 — typography contrast pass (cosmetic, last)
- [ ] Raise contrast/weight of the muted text scale (D9). No layout changes.

---

## Definition of done

- `composer check` green; `npx wp-scripts test-unit-js` green (503+ tests).
- Every one of the nine defects reproduced-then-fixed **on the rig at :8973**, by me, with the real
  Yandex key — including the two the operator called cosmetic.
- Codex (gpt-5.6) critic pass over the whole diff, findings presented verbatim to the operator.
- Marked **"needs operator verification"**, never "done".
