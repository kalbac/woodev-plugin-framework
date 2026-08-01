# ymaps camera moves are asynchronous — losing the `setBounds()` promise breaks two different things

**Namespace:** `[shipping/pickup]` · **Discovered:** s46 (2026-07-31), rig-verifying the SP-5 viewport strategy

## The trap

`map.setBounds()` in Yandex Maps JS API 2.1 does **not** move the camera synchronously. It
animates and returns a promise that resolves when the move has actually completed. Treating it
as fire-and-forget produced two unrelated-looking bugs on the same branch:

**1. The map is queried at its old viewport.**

```js
// WRONG — resolves while the map still shows the PREVIOUS viewport
self.map.setBounds( bounds, { checkZoomRange: true } );
```

`_loadViewport()` reads `map.getBounds()` straight after and gets the pre-move box — the
whole-world default. The server's per-side bbox cap then (correctly) refuses a planet-wide
request, and the customer sees "no points here" for a locality that has plenty. The symptom
points at the cap or at the point source; the cause is a dropped promise.

**2. A balloon opens on a placemark that is still clustered.**

A placemark folded into a cluster has **no balloon of its own**. `placemark.balloon.open()`
throws inside ymaps —

```
Uncaught TypeError: Cannot read properties of null (reading 'getGlobalPixelCenter')
```

— and takes the whole click handler with it, so the drawer item appears to do nothing at all.
Whether a given point is clustered depends on zoom *and* on how close its neighbours happen to
be, so **the same item works at one zoom level and throws at another**. A rig pass that clicks
one unclustered point proves nothing about the others.

## The fix — the reference implementation's move

`plugins-reference/woocommerce-yandex-delivery/.../wc-yandex-delivery-widget-map.js`
(`handlePlacemarkSelect()`) solves un-clustering in one deterministic step:

```js
map.setBounds( [ coords, coords ], { checkZoomRange: true, zoomMargin: 0, useMapMargin: true } )
    .then( function () { placemark.balloon.open(); } );
```

Collapsing the box to the point's own coordinates makes `checkZoomRange` resolve it to the
deepest zoom the map allows there — where nothing clusters — and awaiting the promise is what
guarantees the clusterer has re-drawn before the balloon opens. `useMapMargin` keeps the result
clear of whatever `map.margin.addArea()` reserved.

An earlier attempt here polled `clusterer.getObjectState()` after successive `+2` zoom steps on
a timer. It worked, and it was still wrong: non-deterministic, its timers outlived `destroy()`,
and rapid clicks overlapped their own loops.

## The degenerate case the fix above cannot solve: identical coordinates (s47)

Collapsing the bounds onto the point works because zooming in eventually separates neighbours. When
two points share the **same** coordinates it never does. Clustering in 2.1 is by **pixel grid**, so
identical coordinates fall in one cell at *every* zoom level, `checkZoomRange` resolves to max zoom
and the placemark is still clustered, `.then()` fires, `.balloon.open()` throws. The customer cannot
select either point — reported live in the CDEK plugin, where a PVZ and a postamat regularly share a
building.

Russian Post's widget (`https://widget.pochta.ru/map/main.*.js`) guards it before attempting the move:

```js
var st = om.getObjectState( id );

if ( st.isClustered ) {
    var same = st.cluster.features
        .map( f => f.geometry.coordinates.join( '' ) )
        .every( ( c, _, all ) => c === all[ 0 ] );

    // at max zoom, or all features on one coordinate → zooming cannot help
    if ( map.getZoom() === map.zoomRange.getCurrent()[ 1 ] || same ) { … }
}
```

They also **re-read `getObjectState()` after the move** and branch again: still clustered → open the
*cluster's* balloon with `clusters.state.set('activeObject', …)`; no longer clustered → open the
object's own. One check before the move is not enough.

The structural fix, taken in the s47 rework, is to stop depending on ymaps balloons at all: group
points by rounded position, draw one marker per position, and render the detail panel as our own DOM
with a tab bar. Then `setBounds()` only *frames* a point and nothing breaks when it stays clustered.
Do **not** solve this by nudging coordinates apart — the pin then lies about where the building is,
and no single offset is correct at more than one zoom level.

## Sequence the continuations, don't just guard `destroy()`

Two `setBounds()` promises are **not** guaranteed to resolve in the order they were issued —
animation duration depends on distance. Clicking item A then item B can let A's slower move
resolve last and open A's balloon over B, the customer's actual choice. A monotonic counter
captured at call time and re-checked at resolve time fixes it:

```js
var mySeq = ++this._openSeq;
… .then( function () { if ( self._destroyed || mySeq !== self._openSeq ) { return; } … } );
```

The reference's `isAnimating` flag is **not** an equivalent — it drives a secondary centring
animation inside a `balloonopen` listener, not ordering.

## Why the tests could not see any of this

The jest stub returned an already-resolved promise from `setBounds()` and updated its bounds
synchronously, so the dropped `return` made no observable difference. The stub had to gain a
*deferred* mode — a `setBounds()` that stays pending until the test releases it — before a test
could fail on the real contract.

Worse, none of it was reachable at all with a placeholder API key: ymaps refuses **geocoding**
with an invalid key while still happily serving tiles, so `_resolveInitialViewport()` never ran
and the empty result arrived for an unrelated reason. **Verifying the viewport strategy requires
a real key** — a fake one produces a plausible, wrong green.

## Related

- [[playwright-mcp-does-not-fire-wc-checkout-ajax]] — the other "the harness lied to you" case on this feature
- [[mutation-sweep-branch-only-false-confidence]] — same family: a green run that proves less than it appears to
