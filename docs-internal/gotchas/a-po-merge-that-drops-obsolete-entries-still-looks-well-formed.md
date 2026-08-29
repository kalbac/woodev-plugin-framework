# Gotcha: [i18n/catalogue-audit] — A `.po` merge that drops the `#~` obsolete entries still looks well-formed
> Tags: i18n, tooling, measurement | Session: s103

## What happens

You rebuild `woodev-plugin-framework-ru_RU.po` from a freshly generated `.pot` — a msgmerge done by
hand, because this machine has no gettext utilities and the container's wp-cli has `make-pot` and
`make-mo` but no merge. The script parses the source `.po`, matches every entry the new `.pot`
carries, and writes the result.

The output is valid. The counts are plausible. `wp i18n make-mo` builds it without complaint. And
**92 preserved translations are gone**:

```
obsolete in CURRENT .po: 92
obsolete in NEW .po:     0
```

Nothing warns you. There is no line in the diff that says "92 blocks removed" unless you look for
one — the diff is enormous anyway, because a regenerated `.pot` renumbers every `#:` source
reference in the file.

## Root cause

An obsolete entry is a whole entry commented out with `#~`:

```po
#~ msgctxt "enhanced select"
#~ msgid "No matches found"
#~ msgstr "Совпадений не найдено"
```

Every line begins with `#`, so a parser that classifies `#`-prefixed lines as COMMENTS never sees an
entry there at all. The blocks are invisible to the parse, therefore absent from the model,
therefore never re-emitted. The bug is not in the writing step; it is that the reading step is
correct about comments and wrong about these.

They matter because that is exactly what `#~` is for: gettext's own `msgmerge` demotes a string the
code no longer references instead of deleting it, so that a string which comes BACK finds its
translation waiting rather than being retranslated from scratch. In this repo they are 92 real
translations from the donor project's v1 setup wizard and select2 strings.

## Fix

❌ Wrong — the parser skips them, the writer never knows:

```python
if stripped.startswith('#'):
    current['comments'].append(line)   # `#~` blocks land here and die
    continue
```

✅ Correct — carry the source file's `#~` runs through verbatim, as raw text, without trying to
model them:

```python
carried, block = [], []
for line in io.open(po_path, encoding='utf-8').read().split(NL):
    if line.startswith('#~'):
        block.append(line)
        continue
    if block:
        carried.append(NL.join(block))
        block = []
if block:
    carried.append(NL.join(block))

out.extend(carried)
```

✅ And the check that actually catches it, which is one line and belongs in the merge script's own
report:

```bash
echo "obsolete in CURRENT .po: $(grep -c '^#~ msgid ' <old>)"
echo "obsolete in NEW .po:     $(grep -c '^#~ msgid ' <new>)"
```

**The general rule:** when a transformation's output is a file format that tolerates omissions, the
absence of an error proves nothing. Count what went IN and what came OUT, per category, and compare
— before installing, not after. This is the second catalogue operation in three sessions that looked
correct and was not; the first compared a `.po` against a `.mo` by bare msgid and undercounted by 16.

## Related

- [comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts](comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts.md) — the s100 catalogue measurement that was also wrong on its first pass, and for a structurally similar reason
- [a-concatenated-msgid-is-invisible-to-a-single-literal-scanner](a-concatenated-msgid-is-invisible-to-a-single-literal-scanner.md) — the same shape one layer up: a scanner whose skip-branch silently ate the finding
- [classify-an-i18n-string-by-its-render-path-not-its-file-path](classify-an-i18n-string-by-its-render-path-not-its-file-path.md) — the other #567 measurement lesson
