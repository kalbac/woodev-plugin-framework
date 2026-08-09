# An HTML icon layout has no hit area without `iconShape` — clicks fall through to Yandex's POI layer

**Discovered:** s50 (2026-08-03), diagnosing "clicking a pickup marker opens a Yandex organisation
card".

## What happened

Pickup markers are drawn with a custom HTML layout (spec D-5 — the framework needs to overlay a group
count badge and express state as a class, which `iconLayout: 'default#image'` cannot do). The feature
was built like this:

```js
// ❌ WRONG — sizes an image layout does not have, and no hit area at all
options: {
    iconLayout: this._iconLayoutClass,
    iconImageSize: [ 45, 45 ],
    iconImageOffset: [ -22, -23 ],
}
```

Markers rendered correctly. Clicking one did nothing of ours — instead Yandex's own POI card opened
("Government of the Russian Federation" on a marker at Krasnopresnenskaya Embankment), because the
click was never intercepted and reached the map's own point-of-interest layer underneath.

## Why

`iconImageSize` and `iconImageOffset` are options of the **`default#image`** layout. A custom layout
built with `templateLayoutFactory` does not consume them. Its clickable region comes from `iconShape`
and from nothing else — and when `iconShape` is absent, the overlay has no shape, so hit-testing never
matches it.

```js
// ✅ RIGHT
function iconShapeFor( offset, size ) {
    return {
        type: 'Rectangle',
        coordinates: [
            [ offset[ 0 ], offset[ 1 ] ],
            [ offset[ 0 ] + size[ 0 ], offset[ 1 ] + size[ 1 ] ],
        ],
    };
}

options: {
    iconLayout: this._iconLayoutClass,
    iconImageSize: box.size,          // still passed: getShape()/auto-pan read them
    iconImageOffset: box.offset,
    iconShape: iconShapeFor( box.offset, box.size ),
}
```

The shape must follow the **current** state's box: our default marker is 45×45 at `[-22, -23]` and
the active one 50×70 at `[-25, -40]`, so a selected marker whose shape still described the small box
would be clickable only across part of its own artwork.

## Why no test saw it

jsdom does not hit-test, and ymaps is mocked in unit tests, so nothing about a missing hit area is
observable off a real map. `suppressMapOpenBlock: true` and `geoObjectOpenBalloonOnClick: false` were
both set and both correct — they suppress *our* balloon, and they say nothing about a click that
never reaches our overlay in the first place. The only signal was a screenshot showing a stranger's
organisation card.

## Related

- [[ymaps-html-icon-layout-anchors-at-its-top-left]] — the sequel (s51): `iconShape` existing was not
  enough, it turned out to be centred on the anchor while the drawn artwork isn't, so clicks still
  missed most of the icon
- [[ymaps-control-options-must-be-nested]] — same session, same family: a shape the library does not read
- [[ymaps-objectmanager-properties-are-plain]] — the layout's *data* side of the same feature
- [[ymaps-camera-moves-are-async]]
- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-9
