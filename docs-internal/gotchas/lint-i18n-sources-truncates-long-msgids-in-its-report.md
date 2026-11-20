# gotcha: `lint:i18n-sources` truncates a long msgid in its report, so a catalogue entry copied from the report is wrong

**Namespace:** `[i18n/catalogue]` · **Discovered:** s238 (19.11.2026, catalogues of PR #1866)

## What happens

`npm run lint:i18n-sources` lists each missing msgid as `✗ missing from .pot and .po: "…"`, but cuts a long string at about
120 characters without a marker. A script (or a person) that copies the quoted text into the `.pot`/`.po` adds an entry whose
msgid matches nothing in the code: the lint then reports the full string as still missing, and the truncated entry stays
behind as dead weight.

## ❌ Wrong

Copy the quoted text from the lint report straight into the catalogues.

## ✅ Correct

Take the msgid from the source line the report names (`referenced from: <file>:<line>`), or treat any reported msgid of
≥ 110 characters as truncated and read the full literal from that line. Re-run the lint: it must end with
`i18n sources: OK`. Then `msgfmt -c` and `lint:i18n`, `lint:mo`.

## Related

- [parallel-prs-that-both-touch-the-po-catalogue-resolve-by-hand-union](parallel-prs-that-both-touch-the-po-catalogue-resolve-by-hand-union.md)
- [../gotcha-index/i18n.md](../gotcha-index/i18n.md)
