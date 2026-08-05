# A custom HTML icon layout draws with its top-left corner AT the anchor — `iconShape` is centred, the artwork isn't

**Namespace:** `[shipping/pickup]` · **Discovered:** s51 (2026-08-05), operator report: "по иконке
практически невозможно попасть … кликнуть можно только по какому-то очень маленькому пространству в
самом верху иконки" ("almost impossible to hit the icon … you can only click some very small area at
the very top of the icon").

## What happened

[[ymaps-html-icon-layout-needs-iconshape]] (s50) fixed markers having *no* clickable area at all. This
is its sequel: once `iconShape` existed, the clickable rectangle and the drawn artwork turned out to be
two different rectangles, overlapping only in one corner.

```js
const ICON_BOX = { size: [ 45, 45 ], offset: [ -22, -23 ] };
```

`iconShapeFor()` (see the s50 fix) turns this into a hit rectangle **measured from the geo anchor**:

```js
// Rectangle( [-22, -23], [-22 + 45, -23 + 45] ) = Rectangle( [-22, -23], [23, 22] )
```

— a box centred on the point's coordinate, exactly as `offset` implies. But a custom
`templateLayoutFactory` HTML layout is **not** positioned by `offset` at all. ymaps places the layout's
root element with its **top-left corner pinned to the anchor**, so the artwork actually drawn on screen
spans `(0, 0) … (45, 45)` — a box that starts *at* the point and extends only right and down from it.

`iconImageOffset` does not fix this. It is an option of the **`default#image`** layout (same family of
mistake as the s50 gotcha: an option ymaps silently does not read for a custom layout). Both reference
implementations avoid the whole problem by using `default#image` instead of a custom layout, and both
supply the offset that option actually understands:

```js
// Yandex.Delivery widget-map.js
iconLayout: 'default#image', iconImageOffset: [ -25, -25 ]

// Russian Post bundle
iconLayout: 'default#image', iconImageOffset: [ -20, -20 ]
```

## The consequence

Drawn rectangle `(0,0)…(45,45)` and hit rectangle `(-22,-23)…(23,22)` overlap only in
`(0,0)…(23,22)` — the **top-left quadrant** of the icon artwork. Everywhere else on the visible pin —
which is most of it — a click lands on nothing. The same geometry mismatch also draws every pin
~22px right and ~23px below where the point actually is, since the artwork's top-left, not its centre,
sits on the true coordinate.

## The fix

CSS can't change what `iconShape` measures from, but it can move the *drawn* box to match it — offset
the layout element by the same numbers `iconShape` already uses, per marker state:

```css
/* ✅ RIGHT — mirrors ICON_BOX.offset so the artwork's centre lands on the anchor, same as iconShape */
.woodev-pickup-marker {
    margin-left: -22px;
    margin-top: -23px;
}

/* ICON_BOX_ACTIVE.offset */
.woodev-pickup-marker[ data-state="active" ] {
    margin-left: -25px;
    margin-top: -40px;
}
```

## Why no test saw it

Same reason as the parent gotcha: jsdom does not hit-test, and ymaps' layout placement (top-left vs
centred) is not something the mock reproduces — nothing about "which corner of a 45×45 box sits on the
coordinate" is observable off a real map.

**Confirmed on the live rig:** before the fix, a click at 85%/85% into the marker artwork (the region
that reads as "the icon" to a human, but was outside the hit rectangle) did nothing. After the fix the
same click opened the card, and exactly one marker took `data-state="active"`.

## Related

- [[ymaps-html-icon-layout-needs-iconshape]] — the prerequisite bug: no hit area at all before this one existed
- [[ymaps-margin-area-needs-explicit-width]] — same family: an ymaps option shape that silently isn't what a spec assumed, caught only by rig measurement
- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-9
