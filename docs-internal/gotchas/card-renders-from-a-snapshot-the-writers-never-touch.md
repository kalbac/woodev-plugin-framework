# The card renders from a snapshot the writers never touch

**Namespace:** `[shipping/pickup]`
**Found:** s57 (08.08.2026), by measurement on the rig. Issues #223 + #225 — two separately-reported
defects, one root cause.

## Root cause

`Panels` holds two references to the same conceptual data:

- `_groups` — the currently VISIBLE groups, **replaced wholesale** by `setVisible( groups )`.
- `_activeGroup` / `_activeIndex` — the group and point the open CARD shows, **captured once** at
  `openCard()` time.

`updatePoint()` and `setPointVerdict()` locate a point by walking `_groups`. `renderCard()` renders
from `_activeGroup`. While no listing arrives these are the *same objects* and everything works.

Under the `viewport` strategy a listing arrives constantly. Every refetch, `fetchAndSetPoints()`
rebuilds groups via `geo.groupByPosition( points )` — **new objects** — the provider emits
`visibleChange`, and `setVisible()` replaces `_groups`. `_activeGroup` is left pointing at an
orphan.

From then on both writers **find the point** (`found === true`, so `renderCard()` even runs),
mutate the NEW object, and the card re-renders from the OLD one. **The verdict is applied and
silently discarded.**

## Why it hid for so long

Every symptom pointed somewhere else:

- The write "worked" — `found` was true, no error, no warning.
- `renderCard()` ran — so the card visibly re-rendered, just with unchanged data.
- It only fires when a listing lands **inside** the window between a card opening and its answer.
  On a fast connection that window barely exists; the slow rig is what made it reproducible.
- Re-opening the card fixed it, which reads like "a transient glitch" rather than a structural bug.
  (It fixed it because `openCard()` re-captures `_activeGroup` from the current set.)

## The measurement that settled it

Reasoning had produced three competing hypotheses (see issue #225's body). Instrumenting the live
session decided it in one run:

```
start:                { cta.disabled: false, activeGroupStillInGroups: true  }
afterListingRefetch:  { cta.disabled: false, activeGroupStillInGroups: false, cardStillOpen: true }
afterRefusal_CTA:     { disabled: false }                       <- CTA STILL CLICKABLE
verdictInGroups:      { allowed: false, reason: "refused" }     <- the write landed here
verdictInActiveGroup: { allowed: true,  reason: null }          <- the card renders from here
```

The control run — same refusal, no intervening listing — gave `cta.disabled: true`. So what broke
was **precisely the object divergence**, not the verdict path.

## ❌ Wrong

```js
Panels.prototype.setVisible = function ( groups ) {
    this._groups = groups || [];   // _activeGroup silently orphaned

    renderList( this );
};
```

## ✅ Correct

Heal the identity when the set is replaced — but only when **both** identities resolve:

```js
Panels.prototype.setVisible = function ( groups ) {
    this._groups = groups || [];

    if ( this._activeGroup ) {
        // ... find a fresh group with the same `key`, then the active POINT inside it by id ...
        if ( -1 !== freshIndex ) {
            this._activeGroup = freshActive;
            this._activeIndex = freshIndex;
            renderCard( this );
        }
        // else: keep the stale object deliberately — see below.
    }

    renderList( this );
};
```

**Matching the group key alone is not enough, and the near-miss is worse than the bug.** A group
`key` is a COORDINATE — co-located points share one — so a fresh group under the same key can hold
a *different set of points*: one filtered out by a type change, one the carrier dropped, one whose
tab index no longer exists. The first version of this fix fell back to `freshIndex = 0` in that
case, which **silently swapped the card onto another PVZ while the customer was reading it** — same
card, same screen position, different address, different verdict. Caught by an adversarial review
pass, not by any test.

When the point cannot be resolved (or panned out of the viewport entirely), **keep the stale
object**: the card must go on showing what the customer opened. Then make the writers reach it —
`updatePoint()`/`setPointVerdict()` also apply to `_activeGroup` when it is not reachable by walking
`_groups`.

## The generalizable rule

**When one field is a live collection and another is a snapshot INTO that collection, every
wholesale replacement of the collection is a silent invalidation of the snapshot.** Either re-point
the snapshot on replacement, or make every writer aware the snapshot exists. Doing neither produces
the worst failure mode available: the write reports success, the render reports success, and the
data is wrong.

The smell to grep for: a method that mutates by walking collection A, and a render path that reads
from field B, where B was assigned from A at some earlier time.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — the s56 sibling: also a defect *between*
  two individually-correct halves, also invisible to unit tests of either half.
- [[a-per-cycle-memo-is-not-in-flight-deduplication]] — found while fixing this one.
- [[rig-serves-the-working-tree-branch-switch-reverts-fixes]] — how the rig was driven for the
  measurement above.
- [[ymaps-draw-then-move-parks-the-overlay]] — the other class of bug where the card/marker state
  and the visible reality disagree.
