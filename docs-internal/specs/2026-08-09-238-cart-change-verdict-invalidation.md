# Spec — #238: wire cart-change verdict invalidation to a real cart-change signal

> Written s61 (2026-08-09). Supersedes the framing in issue #238 on two points — see
> "Where the card is wrong" below. Design decisions here are grounded in a code sweep
> (agent report, s61); every claim carries a `file:line` in the issue thread or below.
>
> **§3 WAS WRONG AND IS SUPERSEDED (s62, 2026-08-10).** The implementation matched this spec
> exactly and a spec-compliance review returned "COMPLIANT, zero findings" — then a Codex pass
> on the same code returned two HIGH defects, both inherent to the MECHANISM §3 chose, not to
> how it was carried out. §3 is rewritten below; the rest of the document still stands. The
> lesson is recorded because it generalises: conformance to a spec is not correctness, and a
> compliance review cannot find a defect the spec itself specified.

## The defect

`forgetPointDetails()` — the whole cart-change verdict invalidation built for #232 — is
called from exactly one place, `refresh()` (`pickup-mount.js:3114`). `refresh()` is reachable
only through `getSession()`, which has **zero production callers**. So the invalidation has
never run in production.

## Where the card is wrong

1. **`getSession()` is not dead code to be deleted.** It is a deliberate extension point
   (Task 20), documented at `pickup-mount.js:222-228` as the hook external code — e.g. a
   payment-method-change listener — uses to reach `refresh()` without this file knowing
   anything about payment methods. Project rule: a hook without a consumer yet is not a
   YAGNI argument for removal. It stays.

2. **"Sessions only exist while the modal is open" is false**, and this is the fact that
   decides the design. `handleModalClosed()` (`pickup-mount.js:2511-2517`) calls only
   `invalidateSelection()`. A modal dismissed via Escape / backdrop / close button leaves the
   entry in `sessions`, leaves `destroyed === false`, and leaves `panels`/`provider` alive with
   their DOM detached. Entries are removed **only** by `closeSession()`, called from just two
   sites: the next trigger click (`pickup-mount.js:3203`) and a selection that successfully
   closed the modal (`pickup-mount.js:2379-2381`).

   Consequence: a naive `updated_checkout` → "refresh every session" subscriber would fire live
   carrier requests for pickers the customer had already dismissed — invisible, and against the
   merchant's quota. This is the storm the card feared, arriving by a route the card did not name.

## What actually needs to happen

A dismissed session needs **nothing**: the next trigger click calls `closeSession()` then
`openSession()` (`pickup-mount.js:3201-3205`), rebuilding everything from scratch. So the
exposure is exactly the card's stated window — the cart changing while the picker is **open**.

Therefore: on `updated_checkout`, refresh only sessions whose modal is **currently open**.

## Design

### 1. `WoodevModal.prototype.isOpen()`

`woodev-modal.js` tracks `_isOpen` privately (`:261`, `:347`, `:373`, `:402`) with no public
accessor. Add one — a trivial `return this._isOpen;` getter. Reaching into `session.modal._isOpen`
from `pickup-mount.js` instead would couple this file to a private; the modal shell is framework
mechanism and an open-state query is a legitimate part of its contract.

### 2. A second `updated_checkout` subscriber, module scope

Registered next to the existing one (`pickup-mount.js:3243`, which does `mountAll` only, deferred
60ms). Walks `sessions` and calls `refresh()` on each session whose modal reports open.

**Debounced.** `updated_checkout` fires in bursts on a live checkout. `pickup-datasource.js`'s own
300ms trailing debounce (`:80`, `:468-479`) collapses the resulting *network* calls, but not the
pool resets: `refresh()` has no reentrancy guard, so each call independently runs
`forgetPointDetails()` + `resetPointPool()` and bumps `poolGeneration` (research C.11). Debounce on
our side, at module scope.

### 3. Echo suppression — the trap the card did not see

This file's own selection flow causes the very event we are subscribing to.
`refreshCheckout()` (`pickup-mount.js:2443`) does
`window.jQuery( document.body ).trigger( 'update_checkout' )`; WooCommerce answers asynchronously
with `updated_checkout` on the same node. Without suppression, confirming a point in a picker that
stays open (`selection.close === false`) would immediately wipe the pool and refetch — destroying
the result the customer just produced, on every single selection.

No existing suppressor is reachable from module scope: the per-session "a refresh we caused is in
flight" state is the `refreshWaiter`/`refreshTimer`/`refreshBusyPanels` closure variables
(`pickup-mount.js:1238-1251`), and the object `getSession()` returns is only
`{ modal, refresh, destroy }` (`pickup-mount.js:3130-3170`).

**Mechanism (s62, corrected): read the session's existing in-flight state. No token.**
`refreshCheckout()` already arms `refreshWaiter`/`refreshTimer` at the moment it triggers
`update_checkout`, and `dropRefreshWaiter()` settles them on *every* path — WooCommerce
answering, `REFRESH_TIMEOUT_MS` expiring, a newer refresh superseding this one, `destroy()`.
`isSelfRefreshInFlight()` is a read-only predicate over that state, with no lifetime of its own.
The module subscriber calls it per session, at event time.

Read it **at event time, inside `handleCartChanged()`**, never in the debounced body — the
original §3 was right about that and the reasoning is unchanged: our module handler is bound at
load, before any session's `one()` waiter, so jQuery runs it first while the waiter is still
outstanding; by the time the debounce fires, that waiter has settled and the state reads the same
for an echo as for a genuine change. The binding order is invisible at the call site and is
commented in the source for exactly that reason.

**Why the one-shot token this replaced was wrong** (both found by Codex, s61):

- **H1** — a bare boolean is not tied to the request that set it, so it consumed whichever
  `updated_checkout` arrived first, whatever its origin.
- **H2** — nothing cleared it when WooCommerce never answered at all, so it stayed armed for as
  long as the picker stayed open and silently ate that session's next genuine cart change. The
  file's own docblock already stated the governing fact — a `one()` self-cleans only if the event
  fires, which is why *that* waiter is paired with `REFRESH_TIMEOUT_MS`. The token was given no
  such pairing.

**H1 is downgraded, not eliminated, and that is accepted.** An `updated_checkout` carries no
origin; no design can separate our echo from a foreign change at the DOM. What the fix guarantees
is the *window*: suppression lasts exactly as long as our refresh is outstanding, and the first
event settles it either way. A foreign change landing inside the window costs one event of delay
— a cart change always produces an `updated_checkout` — and is never dropped.

**Verification note (s62):** H2 is independently observable and its regression test fails on the
pre-fix commit (`5f0d4c8`). H1 is **not** independently observable — measured, not assumed: the
scenario the s61 handoff proposed for it produces exactly one refresh under both the old and the
new mechanism. Its test is therefore a characterisation test pinning "delay, not loss"; it passes
on both. H1's fix is structural — the flag can no longer outlive the request that armed it — and
is what the H2 test proves.

Also note `refreshCheckout()` binds its waiter only when the modal stays open; when a selection
closes the modal, `closeSession()` has already removed the session, so there is nothing to suppress.

**One real gap, verified and accepted (s62).** `refreshCheckout()` is handed `panels`, which is
`null` under `ownsChrome` — so an embedded provider triggers `update_checkout` with the modal open
and **no waiter bound**, and its echo is not suppressed. Harmless only because `refresh()` is
itself a no-op under `ownsChrome` (`pickup-mount.js`, `refresh()`'s first guard): the unsuppressed
event reaches a function that does nothing. Recorded in the source so a future `ownsChrome`
behaviour in `refresh()` does not silently turn this into a live defect. This path has never been
rig-run at all — tracked separately.

## Out of scope — file as a separate card, do not fix here

Under `viewport`, `refresh()` runs `forgetPointDetails()` + `resetPointPool()` **unconditionally**
but only refetches when `lastBbox` is non-null (`pickup-mount.js:3117-3125`). With `lastBbox === null`
the pool and detail memo are wiped with nothing scheduled to repopulate them until the next
`boundsChange`. Pre-existing, independent of this change.

## Tests

- an open picker + `updated_checkout` → that session's details are forgotten and a refetch runs
- a **dismissed** picker (modal closed, session still registered) + `updated_checkout` → **no**
  network call, no pool reset. This is the regression the whole design exists to prevent
- a burst of `updated_checkout` events collapses to one refresh
- a selection that keeps the modal open → the resulting echo does **not** refresh that session
- a genuine cart change *after* that echo **does** refresh it (proves the token is one-shot)
- `isOpen()` on the modal: false before open, true while open, false after close

The suite has no real jQuery (`tests/js/pickup-mount.test.js:26-29`), so `onCheckoutUpdated` falls
back to a native event and tests fire `document.body.dispatchEvent( new Event( 'updated_checkout' ) )`
(`:1055-1066`). `openPicker()` installs a jQuery double recording `.one()`/`.off()`/`.trigger()`
(`:869-874`) — that double is what an echo test drives.

## Related

- #232 (introduced `forgetPointDetails()`), #234 (surfaced this), #219 (same shape)
- Gotcha `built-on-both-sides-with-no-caller-in-the-middle` — this is its s59 addendum, now fixed
