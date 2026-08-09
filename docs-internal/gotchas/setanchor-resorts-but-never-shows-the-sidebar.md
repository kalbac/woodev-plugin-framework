# `setAnchor()` re-sorts the list body but never opens it — a stale card survived an address pick

**Discovered:** s50 (2026-08-04), rig verification (Task 20) — searching an address while a
point's card happened to be open left the card on screen; the sidebar list existed, correctly
sorted by distance from the searched address, but stayed invisible behind the stale card.

## What happened

Spec (D-6, carried into V-6) says an address pick makes "the sidebar open automatically, sorted by
distance from the searched address". The wiring was:

```js
// ❌ only re-sorts; never touches which panel is visible
provider.on( 'addressFocused', function( info ) {
    panels.setAnchor( info.latLng, info.label );
} );
```

`Panels.prototype.setAnchor()` sets the distance anchor and re-renders the **list body** content —
but the list body being correct is not the same as the list being the panel the customer can see.
If a card happened to be open (a perfectly ordinary sequence: click a marker, look at its card,
then search a different address), the stage stayed in `is-open is-card` and the card kept covering
the freshly-sorted list.

## The fix

A new, deterministic method — unlike `toggleList()`, which flips whatever is currently showing,
this always lands in the list state regardless of where it started:

```js
Panels.prototype.openList = function() {
    this._stage.classList.add( 'is-open' );
    this._stage.classList.remove( 'is-card' );
    this._activeGroup = null;
};
```

called right after `setAnchor()` in the same `addressFocused` handler:

```js
provider.on( 'addressFocused', function( info ) {
    panels.setAnchor( info.latLng, info.label );
    panels.openList();
} );
```

## Why no test caught it

Every existing `addressFocused` test asserted only on `setAnchorCalls` — the re-sort — never on
which panel was visible afterward, because no test constructed the "a card is already open" starting
state before emitting the event. The gap was invisible until a live click-then-search sequence in
the browser exposed it.

## The wider lesson

A method that updates a piece of content (`setAnchor()` re-sorting the list body) is not the same
guarantee as a method that ensures that content is *visible*. When a spec says a surface "opens
automatically", check that some code path actually toggles the *visibility* state — not just that
the content behind it happens to be correct once revealed.

## Related

- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — D-6, V-6
- [[focusgroup-only-moved-for-clustered-points]] — same session, same "the state a spec promises was
  never actually driven by any code path" shape of bug
