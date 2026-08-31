# Serena `replace_content`/`replace_symbol_body` rewrites the whole file as CRLF on Windows

**Topic:** `[tooling/*]` · **Discovered:** s25 (2026-06-20) · **Confirmed for `replace_symbol_body`:** #244 (2026-08-10)

## Symptom

After a one-line edit to `woodev/class-plugin.php` via Serena's `replace_content`,
the unit suite went from green to **2 failures in `BoxPackerDispatcherWiringTest`** —
a test that was NOT touched by the edit. The test asserts a literal substring
`"if ( Woodev_Helper::is_woocommerce_active() ) {\n\t\t\t\t…"` (LF) against the file
source. `git diff` showed only the intended `+1` line (git normalizes EOL on diff,
so the corruption was invisible there).

## Root cause

On this Windows box, Serena MCP's **`replace_content`** (file-based regex/literal
replace) rewrites the **entire file with CRLF** line terminators, even though the
repo stores LF. `file woodev/class-plugin.php` → `… with CRLF line terminators`.
Any source-assertion test matching `\n` (not `\r\n`) then fails, and committing the
file would also trip the **Assets-build-parity** / `.gitattributes eol=lf` guards.

## ✅ Correct

- For **existing source files**, use the built-in `Edit` tool (surgical, preserves
  the file's existing EOL). It requires a built-in `Read` first — that's fine. On this
  project, PHP source specifically is Serena-mandatory to *read*
  (`find_symbol`/`get_symbols_overview`) — that mandate is about navigation, not about
  which tool performs the write; use the built-in `Edit` for the actual edit once
  Serena has located the symbol.
- If you must use Serena `replace_content` **or `replace_symbol_body`**, **convert the
  file back to LF** afterwards: `sed -i 's/\r$//' <file>` and re-run the suite.
  `replace_symbol_body` was confirmed to have the identical CRLF-rewrite behavior
  during #244 (2026-08-10) — the "assume the same risk" note below was correct, and is
  no longer just an assumption.
- `insert_after_symbol`/`insert_before_symbol` and `create_text_file` were not
  re-tested for this; assume the same risk and verify EOL (`file <path>`) after any
  Serena write on Windows.

## What a MIXED-EOL file actually looks like when it breaks (s109)

The flip does not have to be Serena's — any writer that emits `
` into a CRLF file, or `
`
into an LF one, produces the same mixed state. In s109 a nine-line docblock was inserted into
`class-checkout-field-settings.php` by a Python script, and phpcs then reported:

```
 76 | ERROR | [x] Spaces must be used for mid-line alignment; tabs are not allowed
    |       |     (Universal.WhiteSpace.DisallowInlineTabs.NonIndentTabsUsed)
```

**On a line that contains no mid-line tab at all** — line 76 was `		/**`. The message points at
whitespace, so the natural next move is to go stare at indentation, which is a dead end. The real
cause is the EOL mix confusing the tokenizer's column arithmetic.

**Diagnose by counting, not by looking:**

```bash
python -c "b=open('<file>','rb').read(); print('CRLF', b.count(b'
'), 'LF', b.count(b'
'))"
```

Equal numbers mean a pure-CRLF file; `CRLF == 0` means pure LF. Anything between is mixed, and that
is your bug. Normalise to what the file already was — here `.gitattributes` pins `*.php text eol=lf`,
so LF — then re-run phpcs before changing a single character of whitespace.


## ❌ Wrong

- Trusting `git diff` to reveal the problem — autocrlf/`.gitattributes` normalization
  hides the EOL flip; the working-tree file is still CRLF and breaks local tests.

## Related

- [[build-artifacts-eol-lf-windows-parity]] — the `.gitattributes eol=lf` pin for build assets
- [[serena-index-vs-git-worktree]] — other Serena-on-Windows caveat
