# A per-cycle memo is not in-flight de-duplication

**Namespace:** `[shipping/pickup]`
**Found:** s57 (08.08.2026), by adversarial review. The storm was latent for one commit.

## Root cause

`pickup-mount.js` kept one map, `detailedPoints`, doing **two jobs at once**:

1. *"this point's details already landed for the current listing"* — so re-opening a card, switching
   tabs in a co-located group, and re-entering from the map do not each spend a carrier request.
2. *(accidentally)* *"a request for this point is already in flight"* — because the marker is set
   **before** the fetch and only cleared on failure.

Job 1 requires wiping the map on every successful listing: a new listing may mean a new cart, so a
landed verdict is history. That wipe is correct.

But the wipe also destroys job 2 — and job 2 was the only thing preventing a second request for a
point already being asked about. That stayed harmless only while nothing re-requested automatically.

The moment a successful listing began re-asking for the still-open card (the fix for the *other*
half of #225 — an open card otherwise falls back to the sparse permissive verdict forever), the two
jobs collided:

```
listing L1 succeeds -> wipes detailedPoints -> re-asks -> F1(A) in flight, detailedPoints[A] = true
customer pans
listing L2 succeeds -> wipes detailedPoints (incl. A!) -> re-asks -> F2(A) in flight, alongside F1(A)
customer pans
listing L3 succeeds -> ... F3(A) ...
```

One detail request **per pan**, all for the same point, all in flight together, against the
merchant's carrier quota. Nothing errors; nothing looks wrong locally.

## ❌ Wrong

```js
if ( 'viewport' !== config.strategy || ! id || detailedPoints[ id ] ) {
    return;
}

detailedPoints[ id ] = true;   // doubles as the in-flight marker...
// ...but `detailedPoints = {}` on every successful listing wipes it mid-flight.
```

## ✅ Correct

Two maps, because they answer two different questions and have two different lifetimes:

```js
if (
    'viewport' !== config.strategy ||
    ! id ||
    destroyed ||
    detailedPoints[ id ] ||    // already LANDED this listing
    detailsInFlight[ id ]      // already BEING ASKED, right now
) {
    return;
}

detailedPoints[ id ]  = true;
detailsInFlight[ id ] = true;
```

`detailsInFlight[ id ]` is deleted on **both** settle branches and is never wiped by a listing, so
"already asking" stays true until the answer actually arrives.

## The corollary that bit on the way out

Removing the possibility of two concurrent same-point fetches made one of the *fix's own* guards
wrong. An earlier version had gated the **apply** path on lock ownership, by symmetry with the
release path. Once concurrent same-point fetches were impossible that became a bug: a customer who
leaves a point and comes **back** while its request is still travelling releases the lock on the way
out and starts no new request on the way back — so the answer arrives owning no lock, about the
point the card is showing, with nothing else on its way. Discarding it threw away the only verdict
anyone was going to fetch.

**Ownership decides who may RELEASE A LOCK. Identity decides whose answer may LAND. Two questions,
two guards — making them one looks tidy and silently drops data.**

## Related

- [[card-renders-from-a-snapshot-the-writers-never-touch]] — the defect whose fix introduced this
  one; same session, same file.
- [[built-on-both-sides-with-no-caller-in-the-middle]] — the same family: correct pieces, wrong
  seam.
