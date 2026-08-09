# A control that changes the subject must announce it

**Namespace:** `[shipping/pickup]`
**Found:** s58 (09.08.2026), on the live Russian Post source. Issue #233.

## Root cause

A co-located group renders one tab per point. The tab handler was:

```js
tab.addEventListener( 'click', function () {
    self._activeIndex = index;
    renderCard( self );
} );
```

Locally complete and locally correct: it swaps the body, and a test proved the body swapped. But
a tab click moves the card onto a **different point**, and nothing outside the panels was told.

The mount's `cardOpened` funnel is what drives the viewport strategy's lazy detail fetch, the
#223 verdict lock, and the staleness guard. Never receiving the event, it kept `cardPointId` on
the FIRST point, so the second point's details were **never fetched at all**. It sat on the sparse
listing's permissive-by-omission verdict forever — its CTA stayed live while the first tab's
correctly went dead.

On live Pochta that is a customer able to choose cash on delivery at a parcel locker that does not
take it.

## The tell — a fossil docblock, again

`refreshPointDetails()` asserted:

> Re-opening a card, switching tabs inside a co-located group and re-entering from the map all
> funnel through `cardOpened`

The tab clause was never true. This is the second time in three sessions that a **confident
docblock describing an arrangement the code does not have** marked the exact spot of a wiring
hole — see [[built-on-both-sides-with-no-caller-in-the-middle]], where the tell was the same
shape.

The comment is not lying on purpose. It describes the design as intended, and the wiring for one
of its routes was simply never built. That is precisely what makes it a good place to look.

## Why the tests were all green

Every tab test asserted what the tab does to the DOM — the label rendered, the body swapped, the
active class moved. Not one asserted that anything outside the panels heard about it. **Unit
tests of a control test the control, never its announcement.**

## ❌ Wrong

```js
tab.addEventListener( 'click', function () {
    self._activeIndex = index;
    renderCard( self );   // ...and nobody else knows the card is now about a different point
} );
```

## ✅ Correct

```js
tab.addEventListener( 'click', function () {
    self._activeIndex = index;
    renderCard( self );

    self._emit( 'cardOpened', { group: group, pointId: point.id, origin: 'tab' } );
} );
```

A DEDICATED origin, not a borrowed one: consumers branch on it. Here `'tab'` joins `'restore'` as
an origin that moves no camera — every point in a co-located group shares one coordinate, so a
focus would move nothing and would re-enter the s52 draw-vs-move race for free.

## The rule

**When a UI control changes WHAT the surface is about — not merely how it looks — it must emit the
same event every other route to that state emits.** Enumerate the routes into a state and check
each one fires the funnel; a route that only mutates local state is a silent divergence, and the
symptom will appear far away, in whatever the funnel was feeding.

Grep test: for each event a component emits, list every code path that *should* emit it, then
check each one actually does. The one that doesn't is the bug.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — same family, same tell.
- [[card-renders-from-a-snapshot-the-writers-never-touch]] — the other "correct halves, wrong
  seam" defect in this subsystem.
- [[a-constant-field-cannot-be-a-verdict]] — the other half of the same operator report.
