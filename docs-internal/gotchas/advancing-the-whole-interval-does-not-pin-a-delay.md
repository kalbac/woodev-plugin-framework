# A test that advances the WHOLE interval does not pin the delay — it passes for 0 too

**Namespace:** `[testing/js]`
**Discovered:** s64 (11.08.2026), issue #260 follow-up
**Cost:** none shipped — caught by a mutation run before the commit. Would have shipped a
constant nobody was holding.

## What happened

A 500 ms delay was added before the pickup dialog's busy overlay appears (issue #260, operator
request). The test looked airtight:

```js
emitSelect( { id: 'P1' } );

expect( loadingOverlay() ).toBeNull();      // not up yet

jest.advanceTimersByTime( 500 );

expect( loadingOverlay() ).not.toBeNull();  // up now
```

Mutating `SELECTION_BUSY_DELAY_MS` from `500` to `0` left the suite **fully green**.

## Why

`setTimeout( fn, 0 )` still defers to a later macrotask, so the synchronous `toBeNull()`
immediately after `emitSelect()` passes for ANY delay, zero included. And
`advanceTimersByTime( 500 )` fires every timer due at or before 500 ms, so it fires a 0 ms one
just as happily. The pair proves the work is **deferred**. It says nothing about **how long**.

## The fix — assert on both sides of the boundary

```js
jest.advanceTimersByTime( 499 );

expect( loadingOverlay() ).toBeNull();       // still the widget's window

jest.advanceTimersByTime( 1 );

expect( loadingOverlay() ).not.toBeNull();   // and now it is ours
```

`n - 1` must not fire and `n` must. Anything less pins deferral, not duration.

## The general shape

This is the timing-test instance of a wider trap this repo keeps meeting: an assertion that
would hold under the mutation it is supposed to catch. Compare
`jest-toequal-empty-array-ignores-undefined` (a "never called" assertion that passes when the
call happened with `undefined`) and `mutation-sweep-branch-only-false-confidence` (a sweep that
mutates branch conditions only and reads as complete).

**Any test asserting a NUMBER — a delay, a cap, a threshold, a retry count — must be mutated
against a neighbouring value before it is trusted.** A test that only distinguishes "some"
from "none" is not testing the number.

## Related

- [jest-toequal-empty-array-ignores-undefined.md](jest-toequal-empty-array-ignores-undefined.md)
- [mutation-sweep-branch-only-false-confidence.md](mutation-sweep-branch-only-false-confidence.md)
- [npx-jest-bypasses-wp-scripts-jsdom.md](npx-jest-bypasses-wp-scripts-jsdom.md) — always run jest through `npm run test:js -- --roots …`
