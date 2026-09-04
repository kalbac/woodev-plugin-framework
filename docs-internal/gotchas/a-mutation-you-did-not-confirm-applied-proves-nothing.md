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

## A sharper form, s115: confirm WHERE it landed, not only THAT it landed

The three s73 cases all failed to change the file at all. s115 found the version that *does* change
the file — into the wrong place — and it is harder to spot, because every check short of reading
the diff says success.

Mutating a fixture to prove a new test could fail:

```python
s.replace("Woodev_Loader::register(", "if ( false ) Woodev_Loader::register(", 1)   # first occurrence
```

The replacement reported success, the file changed, and the suite stayed **green** — which read as
"the new test is useless, it does not pin the call". It was the opposite. The file's own docblock
says *"registers itself … through `Woodev_Loader::register()`"*, so the FIRST occurrence of that
string is **prose**. The comment got mutated; the call never did.

Any file whose header documents what the code below does — which is every file in this repo — has
this shape. `replace(..., 1)`, `sed` without an address, and "first match" in an editor all aim at
the docblock before they aim at the code.

**The check that catches it:** after mutating, grep for the mutation marker and read the line
number, or print the mutated region. A count is not enough — `grep -c` was 1 in both the right and
the wrong case.

```bash
grep -n "if ( false ) Woodev_Loader::register" "$f"    # is that a code line or a comment line?
```

Anchor on something that cannot appear in prose — the statement's own indentation and following
line — rather than on the bare symbol name:

```python
old = "
Woodev_Loader::register(
	WOODEV_ENTRY_PATH_FIXTURE_FILE,"
assert old in s, "real call not found"        # fails loudly instead of hitting the comment
```

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
