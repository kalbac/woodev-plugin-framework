# `composer phpcs` sees NO warning-level sniff at all — line length is only the most visible one

**Namespace:** `[tooling/phpcs]` · **Discovered:** s45 (2026-07-31) · **Scope measured:** s109 (31.08.2026)

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

## The scope is the whole warning level, not one metric (measured s109)

`warning-severity 0` is a GLOBAL argument (`phpcs.xml:76`), not a property of the line-length rule.
Every warning-level sniff in the ruleset is silenced by it. Measured across `woodev/` on
31.08.2026 with `--warning-severity=1 --error-severity=0`:

**1786 violations from 19 sniffs**, none of which `composer phpcs` reports today.

| sniff | count |
|---|---|
| `Generic.Files.LineLength.TooLong` | 1393 (137 files) |
| `Generic.Formatting.MultipleStatementAlignment.*` | 54 |
| `WordPress.WP.Capabilities.Undetermined` | 17 |
| `WordPress.WP.AlternativeFunctions.parse_url_parse_url` | 5 |
| `Generic.CodeAnalysis.ForLoopWithTestFunctionCall` | 3 |
| `WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode` | 3 |
| `WordPress.Security.SafeRedirect.wp_redirect_wp_redirect` | 1 |
| `WordPress.WP.CronInterval.ChangeDetected` | 1 |
| reserved-keyword parameter names, `rand`, `error_log` | 6 |

One of them is a **security** sniff. So "phpcs clean" is not merely weak evidence about line
length — it is silent about an entire severity tier, including checks nobody would knowingly
disable. `phpcbf` can fix 356 of these automatically; line length is NOT among them.

## ⚠ How to probe this WITHOUT fooling yourself

A long **comment** made of one unbreakable word reports nothing even at `--warning-severity=1`, and
that looks like proof the rule is broken in some other way. It is not: `LineLengthSniff.php:155-176`
deliberately skips a comment-only line whose first non-breaking word already exceeds the limit —
otherwise a long URL in a comment could never be written at all.

❌ A probe that proves nothing:

```php
<?php
// xxxxxxxx…140 x's, no spaces…xxxxxxxx
```

✅ A probe that works — real code, with spaces to break on:

```php
<?php
class Woodev_Probe_Long_Line {
	public function run(): string {
		$value = 'aaaa' . 'bbbb' . /* … out past 120 columns … */ . 'oooo';
		return $value;
	}
}
```

```
vendor/bin/phpcs probe.php                       → . 1 / 1 (100%)   silent
vendor/bin/phpcs --warning-severity=1 probe.php  → 4 | WARNING | Line exceeds 120 characters;
                                                      contains 150 characters
```

Put the probe in the **scratchpad** and copy it in, never author it inside the repo.


## Related

- [[mutation-sweep-branch-only-false-confidence]] — the other "green means nothing" trap from the same branch
- [[phpunit-multiple-file-args]] — a passing PHPUnit run that never executed the file you cared about
