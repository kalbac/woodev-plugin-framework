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

A multi-line `perl` pattern written with `\n` matches nothing in a file whose terminators are
`\r\n`, and a non-matching `s///` is not an error. `git diff --stat` afterwards still shows the
file as modified — from the REAL edits made earlier — so even the diff looks plausible.

### ⚠️ Corrected s82 — the repo is NOT a CRLF tree

The original text of this section claimed "files in this repo are CRLF in the working tree" and
that `.gitattributes` only normalises on commit. **Both halves are wrong**, and the claim then
propagated into session handoffs and subagent briefs as a standing "the tree is CRLF" instruction.

Measured on this box:

```bash
$ git ls-files --eol woodev/class-lifecycle.php
i/lf    w/lf    attr/text eol=lf        woodev/class-lifecycle.php
```

`.gitattributes` sets `*.php text eol=lf` (and the same for `*.js`), which overrides
`core.autocrlf=true` in BOTH directions — index and working tree. Tracked PHP/JS files are LF on
disk.

So a file only becomes CRLF here after a **tool flips it**, and there is a known one:
[[serena-replace-content-eol-flip]] rewrites an entire file as CRLF on every
`replace_content` / `replace_symbol_body`. That is also where the `warning: CRLF will be replaced
by LF` lines come from — they are not routine, they are a **symptom that Serena touched the file**,
and they name exactly which files have been flipped.

The failure this gotcha describes is real; only its cause was misattributed. The chain is:
Serena edits a file → the file becomes CRLF → a later `perl` mutation with `\n` silently misses it.
The remedy below is unchanged, and is correct regardless of which file happens to be flipped —
never trust a mutation you did not confirm applied.

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
