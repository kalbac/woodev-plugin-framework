# Gotcha: [tooling/phpcs] — A rule silenced by `exclude-pattern` cannot be revived by a CLI flag, and `tab-width` changes the answer by a third
> Tags: tooling, phpcs, measurement | Session: s110

## What happens

`phpcs.xml` silences a rule with `<exclude-pattern>*</exclude-pattern>`, and a comment next to it
tells the reader how to run the check on demand:

```
vendor/bin/phpcs --warning-severity=1 --error-severity=0 --sniffs=Generic.Files.LineLength ./woodev
```

The command runs, exits 0 and prints an empty report. It looks like there are no violations. There
are 1393. The documented escape hatch never worked, and a reader trusting it concludes the opposite
of the truth.

Second half of the trap: once the check IS run correctly, the number depends on a setting nobody
thinks about. The same sniff over the same tree reports **1002** or **1393** depending on
`tab-width`.

## Root cause

Two independent facts about PHPCS:

1. **`exclude-pattern` is not a severity.** `--warning-severity` / `--error-severity` change which
   severities are *reported*; an `exclude-pattern` removes the file from the rule's scope entirely,
   before severity is consulted. No CLI flag overrides it. Nor can `--sniffs` re-add it — that
   filters the active sniff list, it does not undo an exclusion.
2. **Sniff properties are not settable from the command line.** `lineLimit` and `absoluteLineLimit`
   are `<property>` elements. `--runtime-set` sets ruleset *config vars*, not sniff properties, so
   `--runtime-set lineLimit 120` silently does nothing and you measure against `Generic`'s defaults
   (80/100) instead of the project's 120.

And the number itself: `Generic.Files.LineLength` measures length **after tab expansion**.
WordPress-Core sets `tab-width` to 4, so in a tab-indented codebase every leading tab counts as 4.
A standalone ruleset that does not inherit WordPress-Core silently counts tabs as 1 character and
under-reports by roughly a third.

## Fix

❌ Wrong — a comment promising a measurement that returns nothing:

```xml
<rule ref="Generic.Files.LineLength">
    <!-- Measure on demand: phpcs --warning-severity=1 --sniffs=Generic.Files.LineLength -->
    <exclude-pattern>*</exclude-pattern>
</rule>
```

✅ Correct — a deliberately-silenced rule gets its own ruleset file, with tab expansion:

```xml
<!-- phpcs-line-length.xml -->
<ruleset name="Woodev Line Length">
    <file>./woodev</file>
    <arg name="extensions" value="php"/>
    <arg name="warning-severity" value="1"/>
    <arg name="tab-width" value="4"/>   <!-- load-bearing: without it, 1002 instead of 1393 -->
    <rule ref="Generic.Files.LineLength">
        <properties>
            <property name="lineLimit" value="120"/>
            <property name="absoluteLineLimit" value="0"/>
        </properties>
    </rule>
</ruleset>
```

```bash
vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev
```

**Verify the escape hatch on red before documenting it.** An empty PHPCS report and a clean codebase
are indistinguishable from the exit code. If a documented command is supposed to find something,
run it once against a tree where you know it must fire.

⚠ Unrelated but adjacent, and it bites while writing exactly these comments: **an XML comment may
not contain `--`**, so a CLI flag cannot be written literally inside one. `phpcs.xml` stops being
well-formed XML, and the failure surfaces as a confusing parse error rather than a phpcs message.
Check with `python -c "import xml.dom.minidom;xml.dom.minidom.parse('phpcs.xml')"` after editing.

## Related

- [git-diff-name-only-interleaves-eol-warnings](git-diff-name-only-interleaves-eol-warnings.md) — the other s110 critic finding; that one was a false alarm, this one was real
- [a-source-asserting-test-breaks-on-mechanical-reformatting](a-source-asserting-test-breaks-on-mechanical-reformatting.md) — the other way the same `phpcbf` run drew blood
