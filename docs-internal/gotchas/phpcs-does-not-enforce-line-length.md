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

For test files, which no ruleset scans, measure with tabs expanded to 4 (a raw `length()`
under-counts a tab-indented file):

```bash
awk '{ gsub(/\t/,"    "); if (length($0)>120) print FILENAME":"FNR" ("length($0)")" }' <files>
```

## ⚠ How to probe the line-length rule WITHOUT fooling yourself

Restored in s135 — still true after #139. A long **comment** made of one unbreakable word reports
nothing, and that looks like proof the rule is broken. It is not: `LineLengthSniff.php:155-176`
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

Run it with `vendor/bin/phpcs --standard=phpcs-line-length.xml probe.php`. Put the probe in the
**scratchpad** and copy it in, never author it inside the repo.

## Related

- [mutation-sweep-branch-only-false-confidence](mutation-sweep-branch-only-false-confidence.md) — another green result that proves less than it appears to
- [phpunit-takes-one-path-and-silently-ignores-the-rest](phpunit-takes-one-path-and-silently-ignores-the-rest.md) — a passing test command that may not run the intended files
