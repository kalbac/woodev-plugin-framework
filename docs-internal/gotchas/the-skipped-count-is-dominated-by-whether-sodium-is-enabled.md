# Gotcha: [testing/measurement] — The SKIPPED count is dominated by ext-sodium, not by what the tree contains
> Tags: testing, measurement, baselines, sodium | Session: s102
> **Measured on:** the Windows desktop (PHP 8.5.1 with sodium disabled) — re-measured on the macOS
> laptop in s138, where the answer is different: see "macOS: the flag is redundant AND noisy" below.

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

## macOS (s138, 26.09.2026): the flag is redundant AND its warning lies

The laptop's Homebrew PHP **8.5.7 has sodium compiled in**, so the whole premise above does not hold
here:

```
$ php -m | grep sodium
sodium
$ php vendor/bin/phpunit --testsuite=Unit            # no flag
Tests: 4082, Assertions: 10225, Skipped: 1
$ php -d extension=sodium vendor/bin/phpunit --testsuite=Unit
Tests: 4082, Assertions: 10225, Skipped: 1           # identical
```

**And the flag now prints a warning that says the opposite of the truth:**

```
Warning: PHP Startup: Unable to load dynamic library 'sodium'
  (tried: /opt/homebrew/lib/php/pecl/20250925/sodium (no such file), ... .so (no such file))
```

Homebrew's PHP was upgraded, the pecl directory for the new API version (`20250925`) holds no
`sodium.so`, and `-d extension=sodium` therefore asks for a dynamic module that is not there — while
the built-in one is already loaded. `extension_loaded('sodium')` still returns true, and SKIPPED is
still 1. An agent that reads that warning and concludes "sodium is off, so the skipped count is
meaningless" has it exactly backwards.

**So, per machine:**

| Machine | `php -m` has sodium | `-d extension=sodium` | SKIPPED in the primary |
|---|---|---|---|
| Windows desktop (8.5.1) | no | **required** | 1 with the flag, 67 without |
| macOS laptop (8.5.7) | **yes** | redundant, prints a false warning | **1 either way** |

The rule that survives both is the one about what the NUMBER means, not about the flag: **1 in the
primary checkout, 6 wherever `plugins-reference/` is absent, anything else is a real signal.** Check
`php -m | grep sodium` before deciding whether the flag is needed at all, and never treat its warning
as evidence about the extension's state.

⚠ Keeping `-d extension=sodium` in a brief is still correct — it is harmless on macOS and necessary
on the desktop — but the brief should say the warning is expected there, or the worker reports it as a
gate failure.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the rule this corrects; its 5-test signal is real, the 66 it was compared against was not
- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — the other way the local runtime differs from CI, and #609's gate for it
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — the other reason two runs of one tree disagree; always `--do-not-cache-result` when measuring
