# `toEqual( [] )` against a "was not called" recorder can pass while the call happened

**Namespace:** `[testing/js]` · **Session:** s52 (2026-08-06)

## Symptom

A test that reads as proof nothing happened:

```js
expect( provider.focusGroupCalls ).toEqual( [] );
```

stays green even after deliberately removing the guard that is supposed to stop
`focusGroup()` from ever being called for this case. The call happened —
`provider.focusGroup( undefined, { zoom: true } )` genuinely ran — and the test did not
notice.

## Root cause

Jest's `toEqual` **ignores `undefined` array items**, the same way it ignores `undefined`
object properties — this is documented, deliberate `toEqual` behaviour, not a bug:

```js
expect( [ undefined ] ).toEqual( [] );  // PASSES
expect( [ undefined ] ).toHaveLength( 0 );  // FAILS — correctly
```

This repo's JS test doubles (`StubProvider`, `StubPanels` in `tests/js/pickup-mount.test.js`
and siblings) mostly record calls onto plain arrays/properties rather than `jest.fn()` mocks
(see e.g. `StubProvider.prototype.focusGroup` pushing onto `this.focusGroupCalls`). Guard code
that calls `provider.focusGroup( key, options )` with a `key` that turned out to be
`undefined` — because the "point not found" branch that should have returned early was
removed or never written — pushes `undefined` into that array. `toEqual( [] )` cannot tell
`[]` from `[ undefined ]` apart, so the assertion silently accepts a call it was written to
rule out.

## Why it is dangerous rather than merely wrong

The test file, the CI run, and every normal read of the test all say "covered". Nothing about
a passing run signals the gap — `toEqual` does not warn, and the array in question really is
"empty" by every ordinary reading a person gives it while skimming assertions. The tell is
narrow and easy to miss entirely: the test only reveals itself under a **deliberate
mutation** of the guard it is supposed to protect (see
[[git-checkout-destroys-uncommitted-mutation-revert]] and
[[mutation-sweep-branch-only-false-confidence]] on why that check has to actually be run, not
assumed). A normal green run — the only kind most changes ever get — gives zero signal that
the assertion is hollow.

In the session this was found (SP-5 Task 12, `pickup-mount.js`'s `restoreSelection()`), the
mutation was: remove the `if ( ! key ) return;` guard that is supposed to stop a restore from
focusing a group that no longer exists. `toEqual( [] )` passed anyway.

## ❌ Wrong

```js
expect( provider.focusGroupCalls ).toEqual( [] );
expect( panels.setPointVerdictCalls ).toEqual( [] );   // same shape, same blind spot
```

## ✅ Correct

```js
expect( provider.focusGroupCalls ).toHaveLength( 0 );
```

`toHaveLength` checks the array's actual `.length`, so `[ undefined ]` (length 1) correctly
fails against an expectation of `0`. If the recorded values themselves matter too (not just
the count), pair it with `toEqual` for the populated case rather than relying on `toEqual`
alone for the empty one.

## Rule

Any "this was NOT called" assertion written as `expect( someCallsArray ).toEqual( [] )`
against one of this repo's plain-array call recorders has this blind spot. Prefer
`toHaveLength( 0 )` for "never called", and treat `toEqual( [] )` in that position as a smell
worth a second look during review. Genuinely empty arrays that were never touched at all
(nothing ever pushed, `undefined` or otherwise) are fine either way — the gap only bites when
a call happened with an `undefined` argument.

## Related

- [[npx-jest-bypasses-wp-scripts-jsdom]] — same session, same file, the other way a JS test
  run can look right and be wrong; that one is about the invocation, this one about the
  assertion.
- [[mutation-sweep-branch-only-false-confidence]] — the PHP-side lesson that a green suite
  does not prove coverage; this is the JS-side instance, found by exactly the deliberate
  mutation that lesson recommends.
- [[git-checkout-destroys-uncommitted-mutation-revert]] — the safe way to run that mutation
  check without losing the real work.
