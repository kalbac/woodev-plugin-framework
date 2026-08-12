# gotcha: `jest.resetModules()` gives a fresh module, not a fresh `document.body` — zombie listeners keep answering

**Namespace:** `[testing/js]`
**Discovered:** s70 (2026-08-13, location cascade country-clearing)

## The trap

A jsdom suite that boots a self-mounting module per test usually resets like this:

```js
beforeEach( () => {
	jest.resetModules();
	document.body.innerHTML = '';   // ❌ removes children, NOT listeners
	// … re-require the module under test
} );
```

`jest.resetModules()` does exactly what it says: the next `require()` returns a NEW module
instance. It says nothing about the DOM. A module that binds a **delegated** listener —

```js
document.body.addEventListener( 'change', handleFieldChanged );
```

— bound it to `document.body`, and `innerHTML = ''` replaces that element's *children* while the
element itself survives every test in the file. So each test leaves one more live instance
listening, each holding its own stale state, and every one of them answers the next test's events.

Measured: one country-change event in a single file run was handled by **nine** cascade
instances. The eight zombies looked up fields by id (`document.getElementById( 'billing_city' )`),
which resolves to the CURRENT test's freshly built nodes — so a zombie whose remembered country
was `US` treated the live test's `RU` as a real transition and wiped fields the current instance
had correctly left alone.

## What it looks like

The signature is unmistakable once you know it, and baffling until then: **the test passes alone
and fails in the file.**

```
npx … -t "a programmatic country change carrying the SAME value clears nothing"   → PASS
npm run test:js -- --roots "<rootDir>/tests/js"                                    → FAIL
```

Worse, the failure accuses the wrong code. Here it read as "the remembered-value gate is broken" —
a gate this project has already paid for once (gotcha
`a-programmatic-parent-change-must-not-run-a-destructive-cascade`) — while a probe showed the gate
comparing `"RU"` against `"RU"` and correctly returning. The gate was never involved.

## The fix

Replace the element, not its contents:

```js
beforeEach( () => {
	jest.resetModules();
	document.body.replaceWith( document.createElement( 'body' ) );   // ✅ listeners go with it
	// … re-require the module under test
} );
```

Every listener bound to the old body dies with it. The same applies to anything else a module
attaches to a surviving global node — `document`, `window`, `document.documentElement`. For those,
the module needs an explicit teardown the test can call; there is no cheap "replace the window".

## Why production is fine

A page boots the module ONCE. Multiple instances are a pure artifact of a test file that re-boots
per test — which is exactly why the symptom appears only in aggregate, and why "it passes alone"
is not evidence of anything.

## Related

- [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] — the gate this trap framed
  for a crime it did not commit.
- [[hook-snapshot-restore-defeats-an-identity-based-reset]] — the PHP-side twin, discovered the
  same session: a reset that looks complete while the surrounding harness quietly restores what it
  erased.
- [[jest-scans-agent-worktrees-inside-the-repo]] — the other "green alone, different in the full
  run" jest trap.
