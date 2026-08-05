# `ObjectManager.setFilter()`'s callback takes ONE argument, not `(objectId, object)` — selecting any specific type hid every marker

**Discovered:** s50 (2026-08-04), rig verification (Task 20) — clicking the type filter to show
only one point type made every marker on the map disappear, including the ones that should have
matched.

## What happened

```js
// ❌ WRONG — the second parameter is always undefined
this.objectManager.setFilter( function( objectId, object ) {
    if ( ! list ) {
        return true;
    }

    var properties = object && object.properties;   // object is undefined → properties is undefined
    var typeCode = properties ? properties.typeCode : undefined;   // always undefined

    return -1 !== list.indexOf( typeCode );          // -1 !== list.indexOf(undefined) → always false
} );
```

With no filter applied (`codes` empty/null) the early `return true` masked the bug completely —
every marker showed, because the broken branch never ran. The instant a customer picked a specific
type, `list` became non-empty, the early return stopped firing, and `typeCode` resolved to
`undefined` for every single feature — so **every** marker vanished, not just the ones of the
wrong type.

## Why

Confirmed against a real, live `ymaps.ObjectManager` on the rig rather than assumed:

```js
const om = new ymaps.ObjectManager( { clusterize: false } );
om.add( { type: 'Feature', id: 'x', geometry: {...}, properties: { typeCode: 'PVZ' }, options: {} } );
om.setFilter( function( object ) {
    console.log( object );   // the FEATURE itself — { type, id, geometry, properties, options, ... }
} );
```

`setFilter()`'s callback receives exactly **one** argument: the feature object. There is no
`objectId` parameter at all. The fix drops it:

```js
// ✅ RIGHT
this.objectManager.setFilter( function( object ) {
    if ( ! list ) {
        return true;
    }

    var properties = object && object.properties;
    var typeCode = properties ? properties.typeCode : undefined;

    return -1 !== list.indexOf( typeCode );
} );
```

## Why no test caught it

The unit test that specifically exercises the stored filter function called it with the SAME
wrong shape the production code expected:

```js
// ❌ the test's own stub call matched the bug, not the real API
expect( filterFn( 'a', { properties: { typeCode: 'pvz' } } ) ).toBe( true );
```

A hand-written test double is only as good as the assumption behind it — here both the production
code and the test that exercised it were written against the same incorrect mental model of the
ymaps signature, so nothing ever disagreed. The bug was caught only by opening the type filter in
a real browser against a real map and watching every marker disappear.

## The wider lesson

This is the third defect this session in the same family as
[[ymaps-objectmanager-properties-are-plain]] and [[ymaps-control-options-must-be-nested]]: ymaps
callback shapes are easy to get subtly wrong, and a plausible-looking test double reproduces the
wrong shape just as easily as the right one. When a ymaps callback's arguments matter, check the
library's actual behaviour — a live instance in a browser console, not memory or a doc skim — before
trusting a test written against an assumption.

## Related

- [[ymaps-objectmanager-properties-are-plain]]
- [[ymaps-control-options-must-be-nested]]
- [[ymaps-html-icon-layout-needs-iconshape]]
- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-8, D-10
