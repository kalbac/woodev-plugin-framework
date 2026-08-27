# Gotcha: [i18n/catalogue-audit] — Comparing a `.po` against a compiled `.mo` by bare msgid undercounts
> Tags: i18n, testing, measurement | Session: s100

## What happens

You audit the translation catalogue by parsing `.po` msgids and checking each one against the
compiled `.mo`, and conclude that a batch of translated strings never reaches runtime. In s100 that
produced a confident, wrong claim: *"16 translated strings are missing from the compiled `.mo`"*,
written onto a live card as a fact and used to justify a fix.

The real number was **zero**. Recompiling the `.mo` produced a catalogue identical in content to the
committed one.

## Root cause

Three separate ways a naive comparison lies, all of which fired at once:

1. **`msgctxt` changes the key.** A `.po` entry with a context is stored in the `.mo` under the
   composite key `msgctxt + "\x04" + msgid`, not under `msgid`. Looking up the bare msgid misses it.
   14 of the 16 were this.
2. **Escaped quotes break a naive regex.** `msgid "Job data key \"%s\" not set"` extracted with a
   `"(.*)"` pattern yields a string containing literal backslashes that matches nothing. 2 of the 16.
3. **`msgctxt` can span multiple lines.** A single-line regex truncates it to `""`, so the composite
   key is built wrong. 1 of the 16 — its real context was
   `coordinating conjunction for a list of order statuses: on-hold, processing, or completed`.

An `.mo` being older by mtime than its `.po` is **not** evidence of desync either: a `.po` gets
edited for comments, references and line numbers without any translated string changing.

## Fix

❌ Wrong — bare-msgid lookup, single-line regex:

```python
pairs = re.findall(r'^msgid\s+"(.*)"\s*\nmsgstr\s+"(.*)"', po, re.M)
missing = [a for a, _ in pairs if a not in catalog]     # counts 16 phantoms
```

✅ Correct — build the composite key, and account for the escaping:

```python
blocks = re.split(r'\n\n+', po)
for b in blocks:
    m = re.search(r'^msgid\s+"(.*)"', b, re.M)
    s = re.search(r'^msgstr\s+"(.*)"', b, re.M)
    c = re.search(r'^msgctxt\s+"(.*)"', b, re.M)     # may be multi-line — verify, don't assume
    if not (m and s and m.group(1) and s.group(1)):
        continue
    key = (c.group(1) + '\x04' + m.group(1)) if c else m.group(1)
    if key not in catalog:
        ...
```

✅ Better — do not hand-roll the comparison at all. Recompile and diff the two catalogues:

```bash
# in the rig container; local gettext tools (msgfmt/msgcat) are NOT installed on this machine
wp i18n make-mo woodev/languages /tmp/mo-out
```

then load both `.mo` files with Python's `gettext.GNUTranslations` and compare `_catalog` to
`_catalog`. Both sides are then keyed identically and no parsing of your own is involved. That is
the measurement that falsified the claim.

**The general rule this belongs to:** the `.pot` staleness finding in the same session survived
because it was checked a second, independent way (its own `POT-Creation-Date` header, plus grepping
for three specific strings that should have been in it). A finding checked only one way is a
hypothesis. Verify before writing it into a card or a docblock — a plausible inference recorded as
fact closes the question and hides the real state.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the same shape: a measurement that proved the instrument, not the thing
- [a-stale-composer-classmap-only-breaks-isolated-test-runs](a-stale-composer-classmap-only-breaks-isolated-test-runs.md) — the other s100 case where the first diagnosis was wrong and a control run settled it
- [wp-safe-remote-request-local-rig](wp-safe-remote-request-local-rig.md) — the rig container these `wp i18n` commands run in
