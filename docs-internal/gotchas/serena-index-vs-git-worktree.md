# Serena navigation once read the main checkout from a worker worktree; activate the worker path instead

**Namespace:** `[tooling/serena]` · **Discovered:** s7 (2026-06-11) · **Status:** historical failure

## What failed

Early Serena sessions used one index bound to the main checkout. A worker in another worktree could
therefore receive symbols and paths from the main branch rather than the branch it was editing. The
failure was real: line numbers, symbols, and any proposed edits could describe a different revision.

## Current rule

This is no longer a reason to avoid Serena. A worker activates Serena on **its own worktree path**
and verifies that a `find_symbol` result points inside that path before navigating or editing. This
keeps the semantic navigation contract while ensuring the indexed revision is the worker's revision.

If activation cannot succeed, report the missing Serena capability under the standing workflow; do
not claim that Grep/Read is the preferred route for PHP work.

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the current activation and verification rule
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — a separate Windows-specific write hazard
