# gotcha: an ObjectManager layout gets PLAIN properties, a Placemark layout gets a data manager

**Namespace:** `[shipping/pickup]`
**Discovered:** s49 (2026-08-02), from the operator's console screenshot — invisible to 393 green jest tests

## Symptom

Every pickup marker rendered as an empty box: `<div class="woodev-pickup-marker"></div>` with no
`data-state`, no `<img>`, no modifier class — while the 45×45 CSS box applied, so the elements were
there and merely blank. Marker clicks did nothing. Then dragging the map produced
`Uncaught Error: map.action.Continuous: ticking while inactive. browser:Chrome behavior:drag`
repeating forever (600+ times), and the map froze.

## Root cause

`ymaps.templateLayoutFactory.createClass( template, { build } )` gives the layout its feature data
through `this.getData()`. The `properties` on that object has **two different shapes**:

| Source | `getData().properties` |
|---|---|
| `Placemark` | a data manager — read with `.get( key )` |
| `ObjectManager` | the **plain JSON object** the feature was added with — read with `properties[ key ]` |

Our layout called `.get()` unconditionally:

```js
// ❌
var groupSize = data.properties.get( 'groupSize' );
```

Under `ObjectManager` that throws `properties.get is not a function`. The throw happens **inside
ymaps' own cross-origin script**, so the browser reports it as a bare `Script error.` at `:0` with
no stack and no file — and `list_console_messages` filtered to errors showed nothing useful. The
half-built layout then left ymaps' drag machinery in an inconsistent state, which is what the
`ticking while inactive` storm actually was.

## Fix

Read through an accessor that tolerates both shapes:

```js
// ✅
function readProperty( properties ) {
    if ( properties && 'function' === typeof properties.get ) {
        return function( key ) { return properties.get( key ); };
    }

    return function( key ) { return properties ? properties[ key ] : undefined; };
}
```

## Why the tests could not see it

The test helper backing `getData().properties` returned `{ get, set, getAll }` — the **Placemark**
shape. Every `_renderMarker()` test therefore exercised a shape production never produces. The suite
was green at 393 tests while not a single marker rendered in a browser.

Same root lesson, same day, second instance: `buildProviderConfig()` forwarded neither
`defaultLocation` nor `pointIcons` to the provider, and the mount's test fixture happened to omit
both keys too — so nothing failed. The map opened at its `[0,0]` placeholder in the Atlantic, and
because `ObjectManager` creates overlays **only for visible objects**, there were no markers at all
and the sidebar (driven by the same bounds test) was empty.

**Rule:** a test fixture shaped more poorly than production hides production's bugs. When a fixture
stands in for a third-party object, model the shape that library ACTUALLY hands you, and keep config
fixtures key-for-key with what PHP really emits.

## Related

- [[modal-backdrop-opacity-dims-the-whole-dialog]] — the other s48/s49 defect only a browser could see
- [[ymaps-camera-moves-are-async]] — the other ymaps async/state trap on this feature
- [[mutation-sweep-branch-only-false-confidence]] — green coverage that proves less than it looks
- Issue #158 — the rig fixture still cannot exercise the type filter, tab bar or clusters
