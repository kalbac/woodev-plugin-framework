# `composer phpcs` enforces warning sniffs; LineLength is deliberately excluded and needs its own measurement

**Namespace:** `[tooling/phpcs]` · **Discovered:** s45 (2026-07-31) · **Updated:** #139, s110

## History

Before #139, `phpcs.xml` set `warning-severity` to `0`, so all warning-level sniffs were silent.
That was fixed in s110: `phpcs.xml` now sets `warning-severity` to `1`, and new violations from the
remaining warning-level sniffs fail `composer phpcs`. The old count of 1,786 violations across 19
sniffs is historical evidence for that decision, not a claim about today's gate.

## The live exception

`Generic.Files.LineLength` is deliberately excluded from the main ruleset. The repository already
contains more than a thousand long lines, so failing normal CI on the soft 120-column target is not
currently useful. This exclusion cannot be reversed with a command-line severity flag.

Measure the live exception with the dedicated ruleset instead:

```bash
vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev
```

`phpcs-line-length.xml` expands tabs to four columns, scans `woodev/`, and uses a 120-column soft
limit with no hard limit. Test files remain outside that measurement and need a separate check when
their line length matters.

## ❌ Wrong

> `composer phpcs`: clean, therefore every line is within 120 columns.

## ✅ Correct

Report `composer phpcs` as evidence for its active rules, and report the dedicated line-length
measurement separately. Do not revive the obsolete claim that all warning-tier sniffs are silent.

## Related

- [mutation-sweep-branch-only-false-confidence](mutation-sweep-branch-only-false-confidence.md) — another green result that proves less than it appears to
- [phpunit-takes-one-path-and-silently-ignores-the-rest](phpunit-takes-one-path-and-silently-ignores-the-rest.md) — a passing test command that may not run the intended files
