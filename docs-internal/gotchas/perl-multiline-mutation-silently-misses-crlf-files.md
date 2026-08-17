# Gotcha: [testing/*] — A `perl -0pi` mutation with `\n` silently misses every CRLF file, and the green run reads as proof
> Tags: mutation-testing, windows, crlf, verification | Session: s78

## What happens

Standard practice here: after adding a test, mutate the implementation and confirm the test
FAILS — a test that passes both ways proves nothing.

The mutation is applied with a one-liner:

```bash
perl -0pi -e "s/\tif \( owner && owner !== record\.provider_id \) \{\n\t\t\treturn;\n\t\t\}/\tif ( false ) {\n\t\t\treturn;\n\t\t}/" file.js
```

The suite is re-run and comes back **fully green**. Read at face value, that says the new tests
are vacuous. Read the other way round — "my fix is not covered" — and you would go rewrite
working tests.

Both readings are wrong. `perl` exited 0, changed nothing, and said nothing. The working tree on
this box holds **CRLF**, so `\n` in the pattern never matched the `\r\n` in the file. The mutation
was never applied, so of course nothing failed.

This is the exact shape of gotcha `a-mutation-you-did-not-confirm-applied-proves-nothing`, with
a Windows-specific cause: the tool that reports success is not the tool that had to match.

## Root cause

Files in this repo are CRLF in the working tree (`.gitattributes` normalises to LF on commit —
hence the routine `warning: CRLF will be replaced by LF` on every `git diff`). A multi-line
`perl` pattern written with `\n` therefore matches nothing, and a non-matching `s///` is not an
error. `git diff --stat` afterwards still shows the file as modified — from the REAL edits made
earlier — so even the diff looks plausible.

## Fix

❌ Wrong — mutate and trust the exit code:

```bash
perl -0pi -e "s/old\nlines/new\nlines/" file.js
npm run test:js -- --roots "<rootDir>/tests/js"      # green => "the test is vacuous"
```

✅ Correct — use the `Edit` tool for the mutation (it matches the file as it really is), then
**grep the mutated line back before running anything**:

```bash
grep -n "if ( false &&" file.js     # no output => the mutation did NOT apply, stop
npm run test:js -- --roots "<rootDir>/tests/js"
```

Only a mutation you have SEEN in the file makes the following test run meaningful. If you must
use `perl`, match `\r?\n` — but prefer `Edit`.

## Related

- [a-mutation-you-did-not-confirm-applied-proves-nothing](a-mutation-you-did-not-confirm-applied-proves-nothing.md)
  — the general rule this is a Windows-flavoured instance of
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the other CRLF trap on
  this box, from the opposite direction (an edit that rewrites the WHOLE file)
- [git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree](git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree.md)
  — the same line-ending split biting the commit instead of the edit
