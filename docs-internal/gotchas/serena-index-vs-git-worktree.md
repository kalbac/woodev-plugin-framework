# [tooling/serena-index-vs-worktree] Serena MCP index is bound to the main working tree — agents editing in a git worktree must NOT navigate via Serena

**Discovered:** 2026-06-11 (s7, Fable orchestrator running workers in an isolated worktree while a parallel session owned the main tree)

## Root cause

The Serena MCP project index points at `D:\Projects\woodev_framework` — the **main
working tree**, on whatever branch IT currently has checked out. A worker operating in a
separate `git worktree` (e.g. `woodev_framework-wt-orch` on a branch off `main`) that
calls `find_symbol`/`read_file` via Serena reads **another branch's code** (possibly with
a parallel session's uncommitted edits) — silently wrong line numbers, missing/extra
symbols, edits proposed against phantom code.

## ❌ Wrong

```text
Worker cwd = D:\projects\woodev_framework-wt-orch (branch feat/x off main)
-> mcp serena find_symbol "Woodev_Plugin/load_updater"   # reads feat/s3-licensing-ui tree!
```

## ✅ Correct

- Workers in a worktree use **Grep/Read on paths under the worktree root** (the
  AGENTS.md "always Serena for PHP" rule explicitly yields here — state the deviation in
  the worker prompt).
- Alternatively activate a separate Serena project on the worktree path — only worth it
  for long-lived worktrees (fresh indexing cost).
- The conductor's `invoke-worker.ps1` prompt says "Use Serena for all PHP reads" while
  spawning workers in per-task worktrees — same hazard; follow-up: make that prompt
  worktree-aware.

## Related
- [autodev loop] `tools/autodev/invoke-worker.ps1` — worker prompt
- [gotchas/russian-source-i18n-plural-n.md](russian-source-i18n-plural-n.md) — same session
