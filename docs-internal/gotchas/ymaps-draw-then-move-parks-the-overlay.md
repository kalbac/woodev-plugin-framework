# Draw-then-move parks a ymaps overlay off screen — and `setBounds()` starts its camera action LATE

**Namespace:** `[shipping/pickup]` · **Discovered:** s52 (2026-08-06), rig-verifying the SP-5
restore-on-reopen path. Two separate ymaps timing traps, hit back to back in one defect.

## The symptom

Reopening the pickup map with a point already chosen marked the sidebar row, opened the sidebar,
and recorded the focus (`getFocusedKey()` returned the right key) — and the map did not move and no
marker went active. 0 of 35 rendered markers read `data-state="active"`; the camera sat at the
city-wide default.

## Trap 1 — `setBounds()` ISSUES its camera command late, not just resolves late

`ymaps-camera-moves-are-async.md` records that `setBounds()` resolves after the animation. It is
asynchronous in a second, sharper way: it does not command the camera at call time either.
Internally it resolves the bounds against the projection and only THEN calls `map.setCenter()` —
measured on the rig at ~35-50 ms after the `setBounds()` call:

```
t+0   setPoints() → map.setBounds([[55.6,37.35],[55.9,37.85]], {duration:400})
t+2   restoreSelection() → focusGroup() → map.setCenter([55.7602,37.6055], 18, {duration:200})
t+16  ↑ that move COMPLETES — the camera is on the point, data-state="active" is written
t+41  ← setBounds' OWN internal setCenter([55.7503,37.6], 10) finally begins
t+462 the fit lands: camera back at the city view, the group re-clusters
```

So "I called `setBounds()` first and `setCenter()` second, therefore the `setCenter()` wins" is
false — the fit starts last and overwrites. And because a clustered feature has **no overlay of its
own**, the snap-back destroyed the marker that had just been marked active. That is why the defect
looked like a focus with no effect while `getFocusedKey()` was demonstrably correct.

Sequencing is therefore not only about which promise resolves first. `focusGroup()` now waits on
`_cameraFit`, the fit `setPoints()` publishes while it is in flight (null when none is, so an
ordinary click still moves the camera synchronously, inside its own task).

## Trap 2 — a camera move across the ObjectManager's FIRST layout parks the overlay

Fixing the ordering made the camera correct and the marker still invisible: its overlay sat at
ymaps' own off-screen sentinel, `left/top: -32760px`, and stayed there until some LATER zoom change
re-laid it out. Four instrumented rig runs isolate it:

| sequence | result |
|---|---|
| `add()` → move, cold ObjectManager (nothing drawn before) | overlay parked at the sentinel |
| `add()` → move, warm ObjectManager (a previous set already drawn) | fine |
| `add()` → 2.5 s pause → move | fine |
| move → `add()`, cold ObjectManager | fine, every time |

ymaps rebuilds its overlays in a burst that begins **after** `actionend` — i.e. after the previous
move's promise has already resolved — so there is no camera promise to await that marks the end of
it, and waiting for the burst's own `overlays.events` `add` bursts is not sufficient either (a move
started 3 ms after the last `add` still parked it). **Move the camera first, draw second.**

`setPoints( groups, { focus: <group key> } )` is that order: settle the camera on the group at
MAX_ZOOM, draw, apply the active state, emit `visibleChange`. `pickup-mount.js` resolves the restore
target BEFORE the draw so it can pass it.

## Related lesson: an "active" marker can be right in every assertion and invisible

The rig check asked for camera moved + `data-state="active"` + sidebar open + row `is-selected`. All
four passed while the map showed no pin at all. **Measure the marker's `getBoundingClientRect()`
against the map's**, not only its attributes — a ymaps overlay at `-32760` is present, styled,
`display: block`, and nowhere near the screen.

## Why jsdom cannot see any of this

The test stub's `setBounds()` applies the camera synchronously at call time and never delegates to
`setCenter()`, so trap 1 cannot happen there; and jsdom has no overlay layer at all, so trap 2
cannot either. What tests CAN pin is the ordering contract — no camera move while a fit is in
flight; the camera move before the draw — and that is what the regression tests assert.

## Related

- [[ymaps-camera-moves-are-async]] — the original async lesson this extends
- [[focusgroup-only-moved-for-clustered-points]] — same method, same "only a browser could see it"
- [[ymaps-objectmanager-properties-are-plain]] — the other ObjectManager-shape trap
