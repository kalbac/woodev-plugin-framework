# A mutation you did not confirm APPLIED proves nothing

**Namespace:** `[testing/*]`
**Found:** s73 (14–15.08.2026). Bit three times in one session, on three different fixes.

## The trap

Mutation testing answers "does a test actually pin this line?" — you break the line, expect red,
restore. The whole method rests on the mutation having *happened*. When it silently does not, the
suite stays green and you read that as **"the test survives the mutation"**, which is the exact
opposite of what occurred: nothing was mutated at all.

Three times in one session, on this repo, with three different tools:

```bash
# 1. perl -0pi with a \t/\n pattern — no match, file untouched, suite green
perl -0pi -e "s/if\( \$node\.attr\( 'id' \).../if( false ) {/" "$f"

# 2. PowerShell -replace with escaped backticks — regex never matched
($orig -replace "(?s)(\`t\`treturn )true", '$1false') | Set-Content $f

# 3. PowerShell .Replace() with "`r`n" — the file is LF, so the needle never occurred
$orig.Replace("`t`treturn true`r`n`t}", ...)
```

Every one reported success at the shell level. Two produced a green suite (read as "unpinned line
— write a test!"), and one *did* change the file but into a **syntax error**, reddening 11 tests
for a reason unrelated to the mutation — equally misleading in the other direction.

## ❌ Wrong

```bash
sed -i 's/return true/return false/' "$f"
npm test        # green -> "the line is not pinned"
```

## ✅ Correct

Assert the mutation landed, in the same breath as running the tests:

```bash
python - <<'PY'
import io,sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
src = io.open(path, encoding='utf-8').read()
if old not in src:
    print("!! MUTATION NOT APPLICABLE — needle absent"); sys.exit(2)
io.open(path,'w',encoding='utf-8',newline='').write(src.replace(old,new,1))
print("applied ok")
PY
```

or simply `grep -c` for the mutated text before running anything. On this repo the most reliable
route turned out to be the **editor tool** (`Edit`), which fails loudly when its `old_string` does
not match — no escaping layer between intent and file.

Watch for the environment traps that caused all three:

- **Line endings.** Source here is LF; a `\r\n` needle never matches.
- **Tabs.** This codebase indents with tabs; a pattern written with spaces silently misses.
- **Escaping layers.** PowerShell backticks, `perl -0pi` inside a bash heredoc inside a tool call —
  each layer can eat a character, and none of them errors when the result simply fails to match.

## Why it matters more than it sounds

A false "mutation survived" sends you to write a test for a line that is already pinned — wasted
work, and it *inflates* confidence, because you end the exercise believing you verified something.
A false "mutation killed" (the syntax-error case) is worse: it certifies a test that may pin
nothing.

The whole point of mutation-checking on this repo is that a green suite is not evidence
(`built-on-both-sides-with-no-caller-in-the-middle`). An unverified mutation puts you back to
exactly that state while feeling like the opposite.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — why mutation-checking is used here at all
- [[mutation-sweep-branch-only-false-confidence]] — the other way a mutation sweep overstates
- [[advancing-the-whole-interval-does-not-pin-a-delay]] — a test that passes against a neighbouring value
- [[../GOTCHAS.md]] — `[testing/*]`
