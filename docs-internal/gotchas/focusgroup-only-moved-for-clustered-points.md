# `focusGroup()` only recentred the camera for clustered points — a plain marker click did nothing

**Discovered:** s50 (2026-08-04), rig verification (Task 20) of the pickup map visual rework —
caught only by clicking a real marker in a real browser, not by any test.

## What happened

Clicking a pickup marker opened the point card correctly (Task 10's `cardOpened` wiring worked),
but the map camera never moved. On the rig, clicking a marker in a wide city-level view left the
view exactly as it was — no zoom, no recentring — while clicking the same point's sidebar row had
the identical (non-)effect. Spec V-10 requires both to "move the camera onto the point, then open
the card", matching what the operator asked for explicitly: "карта зумится к этой точке, а на риге
карта остаётся в том же положении".

## Why

`focusGroup( key )` pre-dates this rework — it was built in an earlier session to solve a
different, narrower problem: a point folded into a ymaps cluster has no balloon of its own, so
`focusGroup` moved the camera to un-cluster it first. Its guard was:

```js
// ❌ only moves the camera when the point is CURRENTLY CLUSTERED
if ( state && state.isClustered && ! isSingleCoordinateCluster( state.cluster )
    && this.map.getZoom() < MAX_ZOOM ) {
    var target = clusterAnchorCoordinates( state.cluster );
    // ...setBounds...
}
```

When this session wired marker-click-and-sidebar-click parity (Task 10), it reused `focusGroup()`
as the camera-move primitive without checking that this guard only fires for the un-clustering
case. A plain, already-visible marker — the overwhelmingly common case — is never clustered, so the
`if` never ran, and the whole method reduced to `_applyFocus( key )`: swap the icon to active, do
nothing to the camera.

## The fix

`focusGroup()` now has two targets instead of one: the cluster's anchor when the point is
genuinely folded into one (unchanged), and the group's OWN `lat`/`lng` (from `_groupsByKey`, the
provider's own point cache) otherwise:

```js
if ( this.map.getZoom() < MAX_ZOOM ) {
    var target = null;

    if ( wasClustered ) {
        if ( ! isSingleCoordinateCluster( state.cluster ) ) {
            target = clusterAnchorCoordinates( state.cluster );
        }
    } else {
        var group = this._groupsByKey[ key ];

        if ( group ) {
            target = [ group.lat, group.lng ];
        }
    }

    if ( target ) {
        attemptedMove = true;
        mover = this.map.setBounds( [ target, target ], { /* … */ } );
    }
}
```

The post-move "did the move actually un-cluster it" re-check now only runs when the move was an
un-clustering attempt (`attemptedMove && wasClustered`) — recentring on an already-solo point has
nothing analogous to re-check.

## Why every test passed anyway

Every existing `focusGroup()` test set `ymapsStub.lastObjectManager.state` directly (to control
clustered/not-clustered) but never called `provider.setPoints( [...] )` first — so `_groupsByKey`
was empty in all of them. One test even asserted the bug as correct behaviour: *"a group that is
not currently clustered focuses without ever calling setBounds"*. That assertion was true only
because the group's own coordinates were never available to move to — not because "no camera move"
is the right behaviour. A green suite encoded the narrow, wrong contract as a passing test.

## The wider lesson

A helper built to solve one specific problem (escape a cluster) can silently become "the only
camera-move primitive in the file" once later work reuses it for a broader purpose, without
anyone re-checking whether its original guard still matches the new job. When repurposing an
existing method for a new call site, read what its condition actually gates — not just what it is
named — and check whether a test asserting today's behaviour is asserting the FEATURE or an
accidental byproduct of incomplete test data.

## Related

- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-10
- [[ymaps-camera-moves-are-async]] — the original problem `focusGroup()`/`_focusSeq` solve
- [[ymaps-html-icon-layout-needs-iconshape]] — same session, same "click behaviour only verified by
  clicking a real marker" lesson
