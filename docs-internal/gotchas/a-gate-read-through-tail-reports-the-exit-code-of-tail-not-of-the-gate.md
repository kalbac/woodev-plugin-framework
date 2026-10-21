# Gotcha: [tooling/shell] — A gate piped through `| tail` reports `tail`'s exit code, so a failing gate reads green

> Tags: shell, ci-gates, phpcs, coordinator | Session: s191 (21.10.2026)

## What happens

The coordinator checked `vendor/bin/phpcs -q | tail` after a merge into `woodev-russian-post` `v2` and saw a short, quiet output. phpcs had
reported 21 errors the merge introduced; `v2` went red until fixed. The pipeline's exit code was `tail`'s (0), and the few lines left by `tail`
did not show the failure.

## Root cause

A pipeline's status is its last command's unless `set -o pipefail` is on, and the Bash tool's shell does not have it. `tail` also drops everything above the
last lines, which is where phpcs prints the per-file error list.

## Fix

❌ `vendor/bin/phpcs -q | tail -5` as the merge gate.

✅ Run the gate on its own and read its exit code: `vendor/bin/phpcs -q; echo "phpcs exit $?"` (same for `phpunit`, `composer phpstan`). If the output is
long, redirect to a file first (`… > out.txt; echo $?`) and read the file. Gates are judged by the exit code, never by how short the output looks.

## Related

- [grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline](grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline.md) — the opposite pipe trap: a green result reported as red
- [a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli](a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli.md) — another phpcs output that reads as «no violations»
