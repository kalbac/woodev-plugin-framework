# Gotcha: [shipping/checkout] — a `detach()` that only unbinds still writes, through whatever was already in flight
> Tags: js, teardown, race, checkout, location, single-flight | Session: s120

## What happens

A renderer's `detach()` reads as a full teardown. `attachRelatedListRegion()`'s was one line:

```js
detach: function() {
	unbind();
}
```

It removes the listener, so nothing NEW can start. It cancels nothing that already started — and
this renderer's whole job is to start something slow: it only has the `<option>` TEXT, so it must
match it against a `GET /location/list`, **measured at 10.5 s for a cold region** (#541). Measured
in s120 with a deterministic jest probe (`attach → change → detach() → resolve`), the detached
renderer still called BOTH `options.onSelect()` and `release()`.

That alone is not the defect. The defect is where the late write LANDS.

## Root cause

`options.onSelect()` is the cascade's `enqueueSelect()`, whose first line is
`entry.pendingRecord = record`. That queue is **last-writer-wins by design** — its own docblock
states the guarantee:

> once the customer stops selecting, the record persisted server-side equals their MOST RECENT
> selection

The guarantee holds because every caller is a LIVE one, so "called later" means "chosen later". A
detached renderer breaks exactly that equivalence: a ten-second-old region arrives as if freshly
picked and overwrites the newer choice. **A last-writer-wins queue cannot defend itself — it has no
notion of a stale caller, and adding one to the queue is impossible, because the caller is the only
party that knows when its own intent was formed.**

## Fix

❌ Wrong — drop the late write because the renderer was detached:

```js
var dead = false;
// detach: function() { unbind(); dead = true; }
if ( ! dead ) {
	options.onSelect( { record: candidate.record } );
}
```

This is the obvious shape and it trades a rare wrong write for a **routine lost one**. Landing after
`detach()` is the NORMAL path here: `reconcileAfterCheckoutUpdate()` re-attaches the renderer
without ever re-asking for the value already on screen, so the late call is usually how the pick
reaches the cascade at all.

✅ Correct — drop it only when something NEWER has arrived, which is a different question:

```js
var superseded = release && 'function' === typeof release.isStale && release.isStale();

if ( ! superseded ) {
	options.onSelect( { record: candidate.record } );
}
```

with the owner of the truth answering it — a counter of PICKS, bumped everywhere an intent enters
and nowhere else:

```js
function nextPickSeq( entry ) {
	entry.pickSeq = ( entry.pickSeq || 0 ) + 1;

	return entry.pickSeq;
}
```

## The key that looks right and is not

The busy-marker token was already there, `release()` already used it, and reusing it is the first
thing anyone will try:

```js
return null !== token && ( ! entry.selectBusy || entry.selectBusy.token !== token ); // ❌
```

It is wrong because **`settleSelect()` calls `clearSelectBusy()` unconditionally**: any EARLIER
request settling wipes the marker, so "my token is no longer standing" also reads true for a pick
nothing superseded — and dropping that one loses a live selection. Pinned by a test that fails
against precisely this implementation:

```
× isStale() stays FALSE when an EARLIER request settling clears the marker
Tests: 1 failed, 294 passed
```

**The generalisation worth carrying:** a marker is presence, not order. If the question is "did
something newer happen", answer it with something monotonic, never with "is my flag still there" —
flags get cleared by parties that are not answering your question.

## Related

- [a-per-cycle-memo-is-not-in-flight-deduplication](a-per-cycle-memo-is-not-in-flight-deduplication.md) — the neighbouring in-flight trap in this same layer
- [the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other](the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — the other place a checkout rule lives on both sides of a seam
