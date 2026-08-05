# `map.margin.addArea()` needs an EXPLICIT `width` — `right` is an offset, not a size

**Namespace:** `[shipping/pickup]` · **Discovered:** live-review round 2 (2026-08-05), rig-verifying
the SP-5 pickup map with the real Yandex key.

## The trap

`setMargin()` reserved the sidebar's screen space like this:

```js
// ❌ WRONG — no `width` key at all
this._marginArea = this.map.margin.addArea( { right: width, top: 0, height: '100%' } );
```

This shipped, passed code review, and had **no unit test at all** exercising it — `_marginArea`
had a docblock and a real production incident already on record (the missing `removeArea()`
method, same file), but nothing asserted the shape of what got *added*.

## Why it's wrong

`right` is an **offset** — where the reserved area's edge sits, measured in from the map's right
edge. `width` is the **size** of that area. Pouring the panel's pixel width into `right` declares
an area that starts `width` pixels in from the edge and is `undefined` pixels wide. ymaps reserves
**zero pixels**. Every `useMapMargin: true` camera move in this file (`focusGroup()`,
`focusAddress()`, the initial-viewport/bulk fits) still resolved its promise and still "worked" —
it just worked against a reservation that didn't exist.

## Where it came from

The design spec this method was built against
(`docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` §6) specified the wrong shape
verbatim. The implementation copied the spec faithfully; **the spec itself was wrong**. Both
reference implementations get it right and agree with each other exactly:

```js
// Yandex.Delivery widget-map.js
this.map.margin.addArea( { right: 0, top: 0, width: 320, height: '100%' } )

// Russian Post bundle
{ right: 0, top: 0, width: 300, height: "100%" }
```

`right: 0` anchors the area to the right edge; `width` is what actually gives it size. The fix:

```js
// ✅ RIGHT
this._marginArea = this.map.margin.addArea( { right: 0, top: 0, width: width, height: '100%' } );
```

## Measured on the rig — the confirming numbers

Sidebar open, one marker clicked, map element x 180…1100 (920px wide), sidebar panel x 780…1100
(320px wide):

- The focused point's anchor landed at **x = 640** — the centre of the FULL map, not the
  margin-adjusted centre.
- The margin-adjusted centre (what it should have been) is **x = 480**.
- Vertically the anchor landed at **y = 507**; the margin-adjusted vertical centre is **507
  exactly** — a 32px shift from the full-map centre of 475, precisely half the file's own 64px
  top-chrome strip (see {@see WoodevYandexMapProvider#_buildMap}, added the same session). That
  strip's `addArea()` call HAS an explicit `width` (`'100%'`) and visibly worked — proving
  `useMapMargin: true` itself is fine, and isolating the bug to this one malformed call.
- ymaps' own copyright strip sat at x 807…1097, entirely underneath a panel starting at x 780 —
  invisible. Yandex's ToS requires the copyright stay visible; this is operator defect 8 with the
  sidebar open, and the root cause is the SAME zero-width reservation: ymaps only moves its own
  copyright out of an area it believes is actually reserved.

This single one-line bug was the entirety of operator defect 5b ("иконка находится где-то с краю
видимой части карты" — the focused icon lands somewhere off at the edge of the visible map) and
defect 8 (copyright hidden) whenever the sidebar was open.

## Why the tests could not see it

`setMargin()` had no jest test before this fix. Once one was written, a LOOSE assertion (checking
only that `addArea()` was called, or matching a subset of keys) would have passed on the buggy
shape too — `{ right: width, top: 0, height: '100%' }` is a perfectly valid-looking object; nothing
about it throws or looks malformed in isolation. The regression test pins the **exact** object,
`width` included as its own field with its own value, specifically so a mutant that puts the
number back into `right` fails immediately:

```js
expect( sidebarCalls[ 0 ] ).toEqual( { right: 0, top: 0, width: 320, height: '100%' } );
```

jsdom also does not lay out or hit-test pixels at all, so even a correct-looking loose assertion
proves nothing about where the reserved area actually sits on a real map — this is the same class
of bug as the two related gotchas below: code that is syntactically fine, type-checks, and is
invisible off a real rig.

## Related

- [[ymaps-camera-moves-are-async]] — the promise this reservation feeds (`useMapMargin: true`)
  resolves correctly even when the margin itself is a no-op; the two bugs look identical from a
  passing test but are unrelated mechanisms.
- [[ymaps-html-icon-layout-needs-iconshape]] — same family: an option shape ymaps silently
  under-reads (or, here, under-declares) with no error, no warning, and no test that pinned the
  exact shape.
- [[ymaps-copyright-pane-is-trapped-in-a-stacking-context]] — the follow-up (s51): fixing this
  file's `width` bug did NOT, as assumed here, pull the copyright strip out from under the sidebar.
  Margins move the camera only; the strip's visibility needed a separate fix.
- `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` §6 — the spec paragraph that
  specified the wrong shape; correct it there too if that document is ever revised.
