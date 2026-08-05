# `composer phpcs` does not enforce the 120-char limit, and never sees `tests/`

**Namespace:** `[tooling/phpcs]` · **Discovered:** s45 (2026-07-31)

## The trap

`AGENTS.md` and `CLAUDE.md` both state a 120-character line limit. `composer phpcs` reports
`clean`. Both statements are true at the same time, and the limit is being violated.

`phpcs.xml` sets:

```xml
warning-severity 0
absoluteLineLimit 0
```

`Generic.Files.LineLength` emits a **warning**, not an error, and warnings are suppressed at
severity 0 — so an over-length line is detected, downgraded, and silently dropped. `absoluteLineLimit
0` disables the hard-error tier entirely. On top of that the ruleset scans only `./woodev` and
excludes `*/tests/*`, so no test file is ever linted at all.

Across SP-5 this produced over-length lines in nearly every task — one at 172 chars, one at 144 —
in files phpcs had just declared clean. Every one was caught by measuring manually.

## Why it matters beyond tidiness

"`composer phpcs` clean" is quoted in this project as evidence in task reports and reviews. For line
length it is **not evidence of anything**. Treating it as such means the convention exists only on
paper, and a reviewer who trusts the report will not check.

## ❌ Wrong

> `composer phpcs`: clean, no violations. Line lengths fine.

## ✅ Correct

Measure separately, with tabs expanded to 4 (the file uses tabs; a raw `length()` under-counts):

```bash
awk '{ gsub(/\t/,"    "); if (length($0)>120) print FILENAME":"FNR" ("length($0)")" }' <files>
```

Do this for **test files too** — they are outside phpcs entirely, so nothing else will.

The real fix is tracked as issue #139: either raise `warning-severity` and set `absoluteLineLimit`
so CI enforces it, or write down that the limit is a manual check and put it in the review
checklist. Until one of those lands, a clean phpcs run says nothing about line length.

## Related

- [[mutation-sweep-branch-only-false-confidence]] — the other "green means nothing" trap from the same branch
- [[phpunit-multiple-file-args]] — a passing PHPUnit run that never executed the file you cared about
