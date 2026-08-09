# Spec — #238: wire cart-change verdict invalidation to a real cart-change signal

> Written s61 (2026-08-09). Supersedes the framing in issue #238 on two points — see
> "Where the card is wrong" below. Design decisions here are grounded in a code sweep
> (agent report, s61); every claim carries a `file:line` in the issue thread or below.

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

**Mechanism: a one-shot echo token per session.** `refreshCheckout()` sets it when it triggers
`update_checkout`; the module subscriber **consumes** it (reads and clears) and skips that session
for that event. One-shot, so a genuine later cart change is not swallowed.

Do **not** implement suppression by checking `refreshWaiter !== null` at debounce-fire time: our
module handler is bound at load, before any session's `one()` waiter, so it runs first — but the
debounced body runs *after* the waiter has already self-cleared, and the check would read `null`
and fail to suppress. The decision must be captured at event time, not at fire time.

Also note `refreshCheckout()` binds its waiter only when the modal stays open; when a selection
closes the modal, `closeSession()` has already removed the session, so there is nothing to suppress.

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
