# A raw control byte anywhere in a text file makes git and grep treat the whole file as binary

**Namespace:** `[tooling/*]`
**Found:** s101 (28.08.2026), card #605, in `tests/unit/ScriptHandlerLogFieldTest.php`.

## The trap

`grep -rn "Script_Handler_Log_Probe" --include='*.php' .` prints

```
Binary file ./tests/unit/handlers/ScriptHandlerLogFieldTest.php matches
```

instead of the matching line. **The file is silently absent from every grep sweep in the
repository** — in a project whose entire workflow rests on grep sweeps. `git diff` on it is
useless for the same reason, showing `Bin 13921 -> 13936 bytes` rather than a diff.

PHP runs the file perfectly. Nothing fails. The file had been like that for sessions.

## Root cause

A **docblock** described a regex and the escape sequences went in as RAW BYTES rather than as the
two-character text:

```
20 2a 20 70 61 74 74 65 72 6e 20 60 2f 5b 00 2d 1f 7f 5d 2b 2f 60
                                            ^^    ^^ ^^
```

Six of them: `0x00`, `0x1f`, `0x7f`, `c2 80`, `c2 9f`, `c2 9b`. The author meant to write
`` `/[\x00-\x1f\x7f]+/` `` as prose; an editor or a paste turned the escapes into the bytes they
name. Git's binary heuristic keys on a NUL byte in the first block, so one of them was enough.

The irony worth keeping: the file's own data PROVIDER builds raw control bytes **deliberately**,
with `chr( 0x9b )`, and carries a comment explaining that storing them as escapes would silently
re-encode them and defeat the test. The deliberate bytes were fine. The accidental ones, in a
comment, cost the file its visibility.

## Fix

✅ Detect it — `file` is the cheapest check, and `git diff --stat` shows `Bin` for a file that
should be text:

```bash
file tests/unit/handlers/ScriptHandlerLogFieldTest.php
# …: data                     ← should be "PHP script, Unicode text, UTF-8 text"
```

✅ Fix it — replace the raw bytes with the escape TEXT the author meant. The diff is exactly
+15 bytes for those six (3+3+3+2+2+2), which is a useful check that nothing else moved.

✅ Sweep for others — a text file that `file` calls `data` is the whole signature:

```bash
git ls-files '*.php' '*.md' '*.js' '*.ts' | while read -r f; do
  case "$(file -b "$f")" in data*) echo "BINARY-LOOKING: $f";; esac
done
```

⚠ Do NOT "fix" it by adding the path to `.gitattributes` as text — that changes how git DIFFS the
file but leaves the bytes, so `grep` still skips it and the file stays invisible.

## Related

- [preg-match-u-returns-false-not-zero-on-invalid-utf8](preg-match-u-returns-false-not-zero-on-invalid-utf8.md) — the same file, the same bytes, the other direction: escapes silently re-encoded INTO valid UTF-8, defeating the test
- [grep-the-sink-not-one-spelling-of-it](grep-the-sink-not-one-spelling-of-it.md) — the other way a sweep quietly covers less than it reports
