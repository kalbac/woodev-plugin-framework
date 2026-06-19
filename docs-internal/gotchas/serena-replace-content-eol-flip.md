# Serena `replace_content` rewrites the whole file as CRLF on Windows

**Topic:** `[tooling/*]` · **Discovered:** s25 (2026-06-20)

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
  the file's existing EOL). It requires a built-in `Read` first — that's fine.
- If you must use Serena `replace_content`, **convert the file back to LF**
  afterwards: `sed -i 's/\r$//' <file>` and re-run the suite.
- Serena **symbol** tools (`replace_symbol_body`, `insert_after_symbol`) and
  `create_text_file` were not re-tested for this; assume the same risk and verify
  EOL (`file <path>`) after any Serena write on Windows.

## ❌ Wrong

- Trusting `git diff` to reveal the problem — autocrlf/`.gitattributes` normalization
  hides the EOL flip; the working-tree file is still CRLF and breaks local tests.

## Related

- [[build-artifacts-eol-lf-windows-parity]] — the `.gitattributes eol=lf` pin for build assets
- [[serena-index-vs-git-worktree]] — other Serena-on-Windows caveat
