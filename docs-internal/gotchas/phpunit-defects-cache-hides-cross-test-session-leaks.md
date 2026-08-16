# A stale `.phpunit.result.cache` hides cross-test state leaks CI cannot avoid

**Namespace:** `[testing/integration]` · **Discovered:** s72 (2026-08-13), PR #312 CI-only failure

## The trap

`phpunit.xml` sets `executionOrder="depends,defects"`. Without a `.phpunit.result.cache`
(gitignored, `.gitignore:26`), PHPUnit runs test **classes** in plain directory/declaration
order — alphabetical within `tests/integration/Shipping/`. `LocationRouteTest` therefore always
runs before `PickupRouteTest` on a fresh checkout: **every CI run, every time.**

Locally, the FIRST integration run after a checkout reproduces this. But PHPUnit writes a fresh
`.phpunit.result.cache` after every run — including a failing one — recording which tests just
failed. The next local run then honours `defects`: it moves the previously-failing
`PickupRouteTest` methods to the FRONT of the queue, ahead of `LocationRouteTest`, so the
polluting write hasn't happened yet when they run. The suite goes green. A developer who reruns
locally to double-check a red CI leg gets a *different, more favourable* execution order than CI
ever used, and the cache never entangles with git status — it looks like nothing changed.

Measured on the SAME commit: `104 tests / 405 assertions OK` locally against
`104 tests / 249 assertions, 2 failures` on CI. The assertion count is the tell — a suite that
reports *fewer* assertions than a green run did is not the same suite, it stopped early.

This is not the WP/WC/PHP version matrix. All three CI legs (WP 6.4/WC 8.5.1/PHP 8.1 through WP
latest/WC latest/PHP 8.2) were reproduced to PASS locally once the exact same versions were
provisioned — the versions were never the variable. Deleting `.phpunit.result.cache` before the
run was what flipped it red, on ANY version combination.

## Root cause underneath the masking

The order-sensitivity itself was a real bug, not a PHPUnit quirk to route around:
`LocationRouteTest::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()`
writes a guest `Location_Record` into `WC()->session` and never cleans it up.
`WC()->session` is a process-wide singleton PHPUnit's DB-transaction-per-test rollback does not
touch, so the record survives into whichever integration test runs next in the SAME PHP process.
`PickupRouteTest`'s bulk fixture then correctly (per Task 15's record-vs-legacy locality
matching) returned zero points for that record — it carried no settlement name at all, only
`key`/`provider_id`/`level`/`country` — failing two assertions with no code changed in the file
that actually broke.

## ❌ Wrong

> Reran the suite locally, it passed, called CI a flake and requeued the job.

> Assumed the WP/WC/PHP matrix caused it because CI runs three legs and local runs one — never
> measured which variable actually flips the result.

## ✅ Correct

1. **Don't trust a local integration pass without deleting `.phpunit.result.cache` first** — it
   is not representative of CI's fresh-checkout order. `rm -f .phpunit.result.cache` before any
   "does this reproduce" experiment.
2. When a REST/session-touching integration test explicitly persists something into `WC()->session`
   or `WC()->cart` (not just DB rows the transaction rollback already reverts), its `tearDown()`
   must undo that write explicitly — the same discipline this file already applied to the DaData
   token option and the provider-registry gate, just missing for the session write itself.
3. To isolate which test pollutes which: `--filter 'ClassA|ClassB'` narrows a 104-test failure to
   the two classes actually involved in a couple of seconds, without touching the whole suite.

## Related

- [[phpunit-multiple-file-args]] — another way a passing local run can misrepresent CI
- [[guest-session-write-needs-the-cart-cookie]] — the same `WC()->session` guest-write mechanism,
  a different failure mode (the write silently not happening at all)
- [[rig-serves-the-working-tree-branch-switch-reverts-fixes]] — a different "local environment
  quietly disagrees with what's being reviewed" trap on this same branch
