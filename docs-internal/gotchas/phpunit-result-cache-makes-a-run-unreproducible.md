# gotcha: delete `.phpunit.result.cache` before every measurement, or two runs of the same tree disagree

**Namespace:** `[testing/*]`
**Discovered:** s84 (2026-08-21)

## What happens

The same worktree, the same commit, two runs minutes apart:

```text
first run (stale .phpunit.result.cache)
  ERRORS!    Tests: 2504, Assertions: 6095, Errors: 45, Failures: 6, Skipped: 66

after rm -f .phpunit.result.cache
  FAILURES!  Tests: 2504, Assertions: 6204,             Failures: 2, Skipped: 66
```

Forty-five errors appeared and vanished with no code change. The 45 were an artifact; the 2 were
real.

## Root cause

`phpunit.xml` sets:

```xml
executionOrder="depends,defects"
```

`defects` reorders the suite to run previously-failing tests FIRST, and "previously failing" is
read from `.phpunit.result.cache`. This project's unit tests share process state — Brain Monkey
function mocks, Patchwork patches, static caches on the framework's own classes — so the ORDER
changes the RESULT. A cache written by a filtered or partial run reorders the next full run into a
combination the suite has never been green in, and unrelated tests start reporting things like
`"get_locale" is not defined nor mocked in this test`.

## ✅ Correct

```bash
rm -f .phpunit.result.cache && composer test:unit
```

The integration-test recipe in `CURRENT-STATE.md` already does this
(`sh -c 'rm -f .phpunit.result.cache; vendor/bin/phpunit …'`) — the unit recipe should too, and
every measurement an agent reports should say whether the cache was cleared.

## Why it matters beyond a confusing number

"Pre-existing and unrelated" is a claim about `main`, and the only way to settle it is to run the
same thing on `main` with the cache cleared on both sides. In s84 a worker classified two failures
it had introduced as pre-existing, and the cache is what made that classification feel reproducible
to it. The comparison that actually settled it:

```text
main,        --testsuite=Unit --filter PickupHandlerTest  →  OK (206 tests, 745 assertions)
its branch,  --testsuite=Unit --filter PickupHandlerTest  →  FAILURES, 2 (206 tests, 741 assertions)
```

A `--filter` run selecting one file removes ordering from the argument entirely — reach for it
before you accept any "unrelated failure" claim.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other way a test count misleads
- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — the other source of failures that look like code defects
