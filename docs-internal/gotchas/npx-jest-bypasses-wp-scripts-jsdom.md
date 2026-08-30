# `npx jest` is not how this project runs JS tests — it silently loses jsdom

**Namespace:** `[testing/js]` · **Session:** s52 (2026-08-06)

## Symptom

`npx jest` reports a catastrophe that does not exist:

```
Tests:       194 failed, 278 passed, 472 total
```

with failures like `ReferenceError: Element is not defined` and assertions returning `[]`
where the DOM should have produced a value. Four of the eight suites fail outright.

The same tree, run correctly, is **631 passed, 631 total, 8 suites**.

## Root cause

This project's own `jest-unit.config.js` (added s107, #188, to scope `roots` — see
[[jest-scans-agent-worktrees-inside-the-repo]]) is a `wp-scripts`-specific filename, not
one plain `jest` auto-discovers (`jest.config.js`/`.json` or a `jest` key in
`package.json` — neither exists here). JS tests run through `@wordpress/scripts`:

```json
"test:js": "wp-scripts test-unit-js"
```

`wp-scripts test-unit-js` supplies the whole jest configuration, including
`testEnvironment: jsdom`, the WordPress babel preset and the module mapping. Invoking
`npx jest` bypasses `wp-scripts` entirely, so jest falls back to its own defaults — the
**node** environment, where `Element`, `document` and the rest of the DOM do not exist.

Every suite that touches the DOM therefore fails, and — worse than the failures — the
**total count drops** (472 vs 631), because suites that fail to load never contribute their
tests. So the run looks like "many tests broke" when in fact several files never executed at
all.

## Why it is dangerous rather than merely wrong

The failure is loud but points in exactly the wrong direction. It appears immediately after
whatever change you just made, names your own test files, and shows real-looking assertion
diffs. The natural reading is "I broke the JS layer" — and the natural response is to start
"fixing" tests that were never broken, or to conclude a refactor must be reverted.

In s52 this was caught only because the PHP work had touched no JS at all, which made a
sudden 194-test failure impossible to believe. Had the same command appeared in a task that
*did* change JS, the false signal would have been entirely plausible.

The plan for that session had `npx jest` written into all seven of its JS tasks before this
was found.

## ❌ Wrong

```bash
npx jest
npx jest tests/js/pickup-panels.test.js
npx jest tests/js/pickup-panels.test.js -t "openList"
```

## ✅ Correct

```bash
npm run test:js                                                   # the full suite
npm run test:js -- tests/js/pickup-geo.test.js                    # one file
npm run test:js -- tests/js/pickup-panels.test.js -t "openList"   # one file, filtered
```

Arguments after `--` are forwarded to jest unchanged, so every jest flag still works — it is
only the *entry point* that must be `wp-scripts`.

## Rule

Quote a JS test result as evidence only if it came from `npm run test:js`. If a JS run
reports a total other than the current baseline (which lives in the `CURRENT-STATE.md`
header — compare against that, not a number frozen in this file; 631 was only the s52
truth), suspect the invocation before suspecting the code: a changed TOTAL means suites
failed to load, which is an environment problem, not a regression.

## Related

- [[phpunit-multiple-file-args]] — the same family on the PHP side: an invocation that
  silently runs less than you think while looking like it ran everything.
- [[mutation-sweep-branch-only-false-confidence]] — on what a green (or red) run does not prove.
- [[wp-scripts-jsx-runtime-wp66]] — another behaviour owned by `@wordpress/scripts` rather
  than by this repo's own config.
- [[git-checkout-destroys-uncommitted-mutation-revert]] — the OTHER way a JS run reads as "I
  broke everything" while the code is fine; same session. Here the total does NOT drop, which
  is what tells the two apart.
- [[jest-toequal-empty-array-ignores-undefined]] — the opposite failure shape, same session: a
  bad invocation makes tests look broken when they are not; this one makes a broken guard look
  covered when it is not.
