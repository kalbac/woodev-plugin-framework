# SP-5 — Viewport point accumulation (#234)

> Session 59, 2026-08-09. Supersedes the framing in issue #234 itself, which was wrong on two
> counts — see §2. Measurement-backed; every number in §4 was taken on the rig against LIVE
> Russian Post, not estimated.

## 1. The defect

Under `strategy: 'viewport'` the map draws exactly the last listing. Zoom in from a frame showing
10 points, get 5, zoom back out to the identical frame — still 5. The customer remembers the
previous frame and cannot tell where the points went.

Mechanically: `fetchAndSetPoints()` builds its groups from *this* listing's `points` and hands them
to `provider.setPoints()`, which is a full `removeAll()` + `add()` rebuild. Every answer replaces
the set wholesale.

The reference — Russian Post's own widget (`plugins-reference/pochta-widget/main.a7d147fb….js`) —
keeps a `Set` of already-seen ids and draws the UNION of everything shown during the session.

## 2. Two premises in issue #234 that are false

Both were checked against the code before designing anything.

**"Accumulation changes framework semantics."** It does not. `setPoints()`'s own docblock
(`map-provider-yandex.js:1685`) already assigns this responsibility to the caller:

> a full rebuild (`removeAll()` then `add()`), never an incremental diff: **the caller is the one
> that decides what the current full set is (including any cross-fetch de-duplication)**, so this
> file always draws exactly what it was handed.

Accumulation is therefore a change in `pickup-mount.js` alone. `Map_Provider`'s contract,
`setPoints()`, and `map-provider-embedded.js` are untouched.

**"The map and the sidebar will deliberately diverge."** They will not, in any state. The sidebar is
fed by `visibleChange`, which is `_emitVisibleChange()` → `_groupsInsideBounds(_groupsByKey)` — the
pool intersected with the current margin-aware frame. A pooled point outside the frame is not drawn
on screen either; the moment it enters the frame it enters the list. Map and sidebar stay the same
set throughout.

```
today:      pool = last listing        sidebar = pool ∩ frame   → zoom −1 collapses the set
accumulated: pool = union of listings  sidebar = pool ∩ frame   → zoom −1 keeps what was seen
```

## 3. Design

### 3.1 The pool

A session-scoped `pointPool` in `pickup-mount.js`, keyed by `point.id`, **viewport strategy only**.
`bulk` fetches once and its listing is authoritative — it does not use the pool at all.

On every successful listing:

1. Merge the listing's points into the pool. **The listing wins on conflict *in the pool*** — it is
   the fresher carrier record.

   **But it does not win on screen, and that is deliberate.** Step 3 below re-applies every stored
   detail field over the freshly merged record, so where a listing field and a detail field share a
   name, the *older* detail value is what the customer sees. This is not an oversight introduced
   here — it is #232's fix, which stopped an open card from losing its own content on every pan —
   and the adversarial review was right to call the flat claim "listing wins" false. Recorded
   precisely because the two halves read as contradictory and a future reader will otherwise
   "correct" one of them:

   | layer | winner | why |
   |---|---|---|
   | the pool | the listing | it is the fresher carrier record |
   | what is drawn | the stored detail | a detail fetch asked about ONE point and got the full record; a listing is sparse by construction |

   The exposure is bounded by §3.2: `detailsById` is cleared on exactly the events that can
   invalidate it, and always together with the pool.
2. Build groups from the POOL, not the listing: `geo.groupByPosition( poolValues() )`.
3. Re-apply `detailsById` onto those groups — unchanged, and it must keep running *after* grouping
   so a re-merged sparse record cannot overwrite what a detail fetch already learned.
4. `panels.setTypes( extractTypes( poolValues() ) )` — from the pool, not the listing. Taking it
   from the listing would make the type chips flicker as the customer pans.

### 3.2 Reset

`resetPointPool()` is called from exactly three places:

| Trigger | Why |
|---|---|
| `refresh()` — checkout update | The cart may have changed; every stored `selectable` was computed against the old one. |
| `typeFilterChange` under viewport | The SERVER filters by type here (that branch refetches rather than filtering client-side). A union of listings taken under different type filters is incoherent. |
| `start()` — first open and every retry | A fresh session/provider starts from nothing. |

Never reset on: pan, zoom, address search, opening or closing a card.

**Which of these actually fire in production — checked, not assumed (09.08.2026).** A worker
contradicted the plan here and was right, and following it up found something bigger:

| trigger | fires in production? |
|---|---|
| `start()` | **yes, and it is the dominant one** — a fresh session is built on every trigger click (`sessions[ fieldId ] = openSession( … )`), so closing and reopening the picker already starts from an empty pool |
| `typeFilterChange` | yes |
| `refresh()` | **NO — it has no production caller at all.** `getSession()` is exported and never called outside `tests/js/`. `onCheckoutUpdated()` exists in this file but is wired only to `mountAll`, never to `refresh` |

The third row is a pre-existing hole, not one this design opens: `forgetPointDetails()` — added by
#232 precisely so a cart change invalidates stored verdicts — is therefore dead in production too.
Filed as **#238**; deliberately NOT fixed here, because wiring `updated_checkout` to `refresh()`
naively means a live carrier call (6–13 s) on an event the checkout fires often, and that needs its
own design.

What it means for #234: the pool's real-world reset is "every time the picker opens", which covers
the common case completely. The `refresh()` reset is still implemented and still correct — it is
the right place for it, and it starts working the moment #238 does.

**Invariant — the pool and the details memo reset TOGETHER.** `refresh()` already calls
`forgetPointDetails()`; `resetPointPool()` goes beside it and the two must never be separated.
`geo.groupByPosition()` does not deep-copy, so re-applied detail fields land on the pooled point
objects themselves; clearing `detailsById` while keeping the pool would leave those fields stranded
with nothing left to re-derive them from. A test pins that one call clears both. (This is the s57
lesson about two guards that look independent and are not.)

### 3.3 A reset must survive an in-flight listing

Found by the adversarial review, and it is the one finding that needs code the design did not have.

A listing request is already travelling when a reset fires (a checkout update, a type change). It
settles afterwards and its continuation merges its now-stale points into the freshly emptied pool.
The guard the continuation has today is `destroyed` alone, which is false here.

This race exists today and is harmless today: each listing REPLACES the set, so the next listing
overwrites the stale one wholesale. Accumulation is what makes it permanent — nothing removes a
point once pooled.

`fetchPoints()` being debounced and superseded does NOT close it. Supersede means an earlier CALLER
receives the LATER result; it says nothing about a request that had already gone out before the
reset.

**Fix:** a monotonic `poolGeneration`, bumped by `resetPointPool()`. `fetchAndSetPoints()` captures
it at call time and the continuation drops the listing entirely if the generation moved while the
request was in flight — no merge, no draw. This is the same shape as `pendingSelectionToken`, which
exists because the equivalent ABA hole was found in the selection lock in s57; the two must not
diverge in style.

### 3.4 The empty-frame message

Today `points.length === 0` drives `showFetchMessage( 'emptyInView' )`. With a pool that becomes
wrong: a listing can come back empty for a bbox in which the pool still holds points, because the
source truncates at `MAX_PAGES × PAGE_SIZE` (§4 measured this happening at `MIN_ZOOM`). The message
must be driven by what the customer can actually SEE — the pool's in-frame subset — not by the
listing length. `bulk`'s `emptyLocality` path is unchanged.

### 3.5 Stale points

A pooled point can outlive its removal — or its becoming unavailable — at the carrier, for the
length of a session. Nothing in the three resets is triggered by a carrier-side change. This is
contained, and the containment is worth stating precisely, because the adversarial review's sharpest
correct point was that fail-closed selection protects *checkout integrity*, not *picker
correctness* — being shown an option and refused it later is its own defect, just a smaller one.

Three layers, in the order the customer meets them:

1. **Opening the card re-asks.** `cardOpened` drives `refreshPointDetails()`, which fetches that one
   point and recomputes its verdict server-side; a refusal locks the CTA before the customer can
   press it. So a stale point is caught at the moment of interest, not at order time.
2. **Selection is server-authoritative.** `POST …/select` recomputes fail-closed, so even a bypassed
   client cannot order against a stale point.
3. **The pool resets on cart change**, which is the invalidation we can actually observe.

Residual exposure: a point the carrier removed between two frames stays *visible on the map* until
a reset. It cannot be selected, and it announces itself as unavailable as soon as it is opened.
That is strictly smaller than the defect the customer reports today, and it is the same exposure any
map with a client-side cache carries.

### 3.6 Bound on the pool

**Default: unlimited.** Justified by §4, not by argument.

**Seam, no consumer yet:** a `maxAccumulatedPoints` config value (0 = unlimited) plumbed through the
pickup config with a PHP filter, so a domain whose carrier is denser than Russian Post can bound it
without a redesign. How dense a carrier is, is domain knowledge — the framework supplies the
mechanism and the knob. When set above zero, the pool trims oldest-inserted first and never evicts
a point that is in the current frame, is the open card's point, or is the customer's current
selection.

## 4. Measurements (rig, live Russian Post, 09.08.2026)

`MIN_ZOOM = 8`, `MAX_ZOOM = 18`, `BBOX_CAP_DEGREES = 10`. At `MIN_ZOOM` on a 1280px rig the frame
spans ~2.2° lat × 5.6° lng, so the 10° server cap is never reached by zooming out — the operator's
prior was right, and this is why.

**Cost against pool size** — `setPoints()` is the real production path (build features, `removeAll`,
`add`, focus reconciliation, `_emitVisibleChange`), measured with synthetic pools spread over the
Moscow region:

| pool | `setPoints()` | `_groupsInsideBounds()` | `groupByPosition()` | pool merge | detail re-apply | JSON |
|---|---|---|---|---|---|---|
| 500 | 17 ms | 0.015 ms | 0.28 ms | 0.03 ms | 0.08 ms | 0.2 MB |
| 2 000 | 13 ms | 0.020 ms | 0.66 ms | 0.10 ms | 0.16 ms | 0.9 MB |
| 5 000 | 44 ms | 0.065 ms | 2.3 ms | 0.32 ms | 0.24 ms | 2.2 MB |
| 10 000 | 88 ms | — | — | — | — | 4.4 MB |
| 20 000 | 334 ms | 0.25 ms | 10.7 ms | 2.8 ms | 1.39 ms | 8.8 MB |

Measured record size: **462 bytes per point**.

`setPoints()` runs once per listing, against a live listing that takes **6–13 s** to come back on
this rig. 334 ms of draw at 20 000 points sits inside that entirely.

**The one superlinear operation is not on this path.** `setTypeFilter()` costs 9 ms at 500 and
1017 ms at 20 000 — but it is called from exactly one place, `pickup-mount.js:2364`, inside the
`'bulk' === config.strategy` branch. The viewport strategy refetches on a type change instead
(and, per §3.2, resets the pool). Verified by grep, not assumed.

**Realistic accumulation** — scripted tour, unique points after each listing:

| leg | listing | unique total |
|---|---|---|
| Moscow centre z11 | 885 | 909 |
| Khimki z11 | 328 | 1 028 |
| Balashikha z11 | 379 | 1 113 |
| Podolsk z11 | 116 | 1 229 |
| Odintsovo z11 | 83 | 1 290 |
| Moscow z8 (`MIN_ZOOM`) | **2 000** | 2 362 |
| Tver z8 | 1 511 | 2 904 |
| Tula z8 | 1 535 | 4 064 |
| Ryazan z8 | 0 | 4 064 |
| Vladimir z8 | 1 865 | 4 664 |

Growth decelerates sharply within a region (885 → +119 → +85 → +116 → +61): consecutive frames
overlap heavily and dedup absorbs most of each listing. Reaching 20 000 requires panning at minimum
zoom across most of European Russia — and even there the cost is 334 ms per listing.

**Incidental finding, worth its own card:** the Moscow `MIN_ZOOM` listing returned exactly 2 000
points — the source's own `MAX_PAGES (10) × PAGE_SIZE (200)` ceiling. The customer is silently
served a truncated set at wide zooms today. Accumulation mitigates it (points seen at closer zooms
survive the truncated wide listing) but does not fix it.

## 5. Tests

jest, `tests/js/pickup-mount.test.js`:

1. Two listings with disjoint points → `setPoints()` receives the union.
2. A point present in both listings appears once, carrying the SECOND listing's field values.
3. Panning away and back draws the point again without a listing containing it.
4. `refresh()` clears the pool **and** the details memo — one call, both assertions (§3.2 invariant).
5. A type-filter change under viewport clears the pool; under bulk it does not touch it.
6. `start()`/retry clears the pool.
7. `setTypes()` is computed from the pool, not the listing.
8. An empty listing with pooled points in frame does NOT show `emptyInView`; an empty listing with
   an empty in-frame subset does.
9. `restoreSelection()` finds a chosen point that is in the pool but absent from the last listing.
10. Bulk strategy is unaffected — its listing still replaces wholesale.
11. Cap path: with `maxAccumulatedPoints` set, oldest-inserted are evicted first and the in-frame /
    open-card / selected points are never evicted.
12. A listing that was already in flight when the pool was reset is DROPPED on arrival — its points
    are not merged and nothing is redrawn from it (§3.3).
13. A listing in flight across NO reset still lands normally — the generation guard must not be a
    blanket "drop anything that overlaps a reset-free window".

## 6. Explicitly out of scope

**The `prevTopRightPoint`/`prevBottomLeftPoint` delta protocol.** The reference widget is only
observed SENDING those fields; that the server answers with a delta is an inference, not a measured
fact. Sending them without accumulating would lose points, and now that we accumulate they would be
a bandwidth optimisation at best. We keep requesting the full frame, which is correct either way.
If it is ever wanted, it belongs in the domain source, not here.

## 7. Adversarial review (Codex, 09.08.2026)

Run against this design before any code. Three of its HIGH findings were bundle artefacts — it was
given the CURRENT code plus the design and read them as one proposed final state, so it correctly
observed that the resets "are absent from the shown handler". They are absent because they are not
written yet. Recorded so the same objection is not re-raised at review time.

What it found that was real, and what changed as a result:

| finding | verdict | change |
|---|---|---|
| "listing wins" is defeated by the detail re-application | **correct** — the flat claim was false | §3.1 now states the two-layer precedence explicitly |
| a reset has no request-generation barrier | **correct, and the only new code it forced** | new §3.3 |
| a fresh listing cannot remove a point that became invalid | **correct in kind** — fail-closed protects checkout, not the picker | §3.5 rewritten to name the residual exposure instead of implying there is none |
| restore could focus an off-frame pooled group | **not reachable** — `selectionRestoreAttempted` is claimed on the first listing that is non-empty or carries a restore group, and the pool equals that listing at that moment; it can only be a union from listing 2 onward | no code change; pinned by a test so it stays unreachable |
| unlimited accumulation makes redraws progressively slower | **known and measured** — 334 ms at 20 000, once per listing, against a 6–13 s fetch | no change; §3.6 already carries the seam |

## Related

- Issue #234 · gotcha `built-on-both-sides-with-no-caller-in-the-middle` (why the docblock was
  re-read rather than trusted) · gotcha `card-renders-from-a-snapshot-the-writers-never-touch`
  (why §3.2's invariant is pinned by a test) · gotcha
  `a-per-cycle-memo-is-not-in-flight-deduplication` (the s57 ABA hole §3.3's generation guard is
  modelled on)
- `docs-internal/archive/specs/2026-08-01-sp5-pickup-map-rework-design.md` — the `dataSource` inversion this
  builds on
