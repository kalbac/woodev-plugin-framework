# Gotcha: [testing/measurement] — The SKIPPED count is dominated by ext-sodium, not by what the tree contains
> Tags: testing, measurement, baselines, sodium | Session: s102

## What happens

`CURRENT-STATE.md` and every handoff since s84 carry the rule *"compare SKIPPED, not assertions —
the primary is 66"*, on the reasoning that a checkout skipping MORE than 66 has silently run fewer
contract guards (gotcha `a-worktree-silently-skips-five-contract-tests`).

Measured in s102, same tree, same command, one flag apart:

```
$ vendor/bin/phpunit --testsuite=Unit --do-not-cache-result
Tests: 3120, Assertions: 7451, Skipped: 67

$ php -d extension=sodium vendor/bin/phpunit --testsuite=Unit --do-not-cache-result
Tests: 3120, Assertions: 7725, Skipped: 1
```

**66 of the 67 skips are "ext-sodium is not enabled in this php.ini".** The number the project
compares across checkouts is, locally, almost entirely a property of the operator's PHP
configuration — and it is 274 assertions, not 66 tests, that actually go unrun.

## Root cause

Five test files gate themselves on the extension:

```
tests/unit/LicenseAuthorityClaimsTest.php
tests/unit/LicenseCommandDeactivateTest.php
tests/unit/LicenseCommandDispatcherTest.php
tests/unit/LicenseCommandTransportAcksTest.php
tests/unit/LicenseEnvelopeVerifierTest.php
```

Each calls `require_sodium()` → `markTestSkipped( 'ext-sodium not available in this PHP runtime.' )`.
That is correct behaviour — CI installs `extensions: sodium` precisely so the Ed25519 binding
semantics are always exercised somewhere. `php_sodium.dll` ships in the local PHP 8.5.1 build; it is
simply not enabled in `php.ini`.

CI on the same branch reported `Skipped: 6`. That reconciles exactly:

| Where | Skipped | = |
|---|---|---|
| local, sodium off | 67 | 1 genuine + 66 sodium |
| local, sodium on | 1 | 1 genuine |
| CI (sodium installed) | 6 | 1 genuine + 5 `plugins-reference` contract tests |

The 5 are the ones `a-worktree-silently-skips-five-contract-tests` is about: `plugins-reference/` is
gitignored, so it is absent from a CI checkout and from every worktree, present only in the primary.

So the local "66" and the CI "6" were never the same measurement, and the one signal the rule exists
to catch — 5 contract tests going missing — is a rounding error inside a number driven by an
unrelated flag.

## Fix

❌ Wrong — compares a number that moves with `php.ini`:

```bash
vendor/bin/phpunit --testsuite=Unit          # "Skipped: 66, matches the baseline, fine"
```

✅ Correct — enable sodium for the run, so SKIPPED means what the rule assumes it means:

```bash
php -d extension=sodium vendor/bin/phpunit --testsuite=Unit --do-not-cache-result
# primary checkout: Skipped: 1
# a worktree / any checkout without plugins-reference: Skipped: 6
```

`-d extension=sodium` is used rather than editing `php.ini`, so the measurement does not depend on
a machine change nobody else has.

With sodium on, the numbers are legible again: **1 in the primary, 6 anywhere `plugins-reference` is
absent**, and any other value is a real signal.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the rule this corrects; its 5-test signal is real, the 66 it was compared against was not
- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — the other way the local runtime differs from CI, and #609's gate for it
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — the other reason two runs of one tree disagree; always `--do-not-cache-result` when measuring
