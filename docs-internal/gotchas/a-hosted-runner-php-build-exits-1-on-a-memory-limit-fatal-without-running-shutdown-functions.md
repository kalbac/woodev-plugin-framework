# Gotcha: [testing/php-versions] — A hosted-runner PHP build exits 1 on a memory-limit fatal and never runs shutdown functions

> Tags: ci, php-versions, error-reporting, process-tests | Session: s191 (21.10.2026) | **Measured on:** GitHub-hosted `ubuntu` runners, php7.4 / 8.0 / 8.1 packages rebuilt 19–20.10.2026; 8.2 / 8.3 unaffected

## What happens

The two process tests in `ErrorReporterProcessTest` (`engine_fatal`, `oom_heap`) that exhaust `memory_limit` in a child PHP and then
assert exit code **255** plus the log line written by a shutdown function went red on CI for PHP 7.4 / 8.0 / 8.1, with no change in our code.
The child exits **1** and the shutdown function never runs, so nothing is logged.

## Root cause

A repackaged PHP build on the hosted runner (rebuilt 19–20.10.2026) exits 1 on ANY «Allowed memory size exhausted» fatal and skips
`register_shutdown_function()` callbacks. A probe workflow reproduced it in a three-line script with no framework loaded; an uncaught
exception on the same build still exits 255 and still runs shutdown, so only the memory-limit path is broken. Nothing in our reporter can
report an OOM on such a build.

## Fix

❌ Assert 255 + the log line unconditionally, or loosen the assertion to «exit non-zero» (it then proves nothing on a sound engine).

✅ Ask the ENGINE whether it can do the thing, and skip explicitly when it cannot: `tests/unit/ErrorReporting/fixtures/oom-probe.php` runs a
tiny memory-exhausting script in a child process; the two tests call it first and `markTestSkipped()` with the measured reason when
shutdown did not run. On a sound engine (local PHP 8.5, hosted 8.2 / 8.3) both run in full with the 255 assertion untouched (PRs #1401, #1399, #1402).
The same pattern fits any process test that depends on engine behaviour a package maintainer can change.

## Related

- [below-php-8-1-reflection-needs-setaccessible-and-anonymous-classes-are-class-at-anonymous](below-php-8-1-reflection-needs-setaccessible-and-anonymous-classes-are-class-at-anonymous.md) — the other «green here, red on an older CI PHP» trap
- [integration-jobs-die-on-a-github-api-504-not-on-your-code](integration-jobs-die-on-a-github-api-504-not-on-your-code.md) — another red job that is the runner, not the code
