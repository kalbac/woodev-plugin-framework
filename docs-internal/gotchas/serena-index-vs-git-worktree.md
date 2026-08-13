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

## s72 addendum — "the contents are identical anyway" expires at the branch's first commit

Observed 2026-08-14 across five subagent worktrees. The orchestrator's briefs said: *"use Serena
read-only against the main tree — file contents are identical to your base commit."* That is true
**only until the worker commits**, and it is false for every follow-up round on the same branch.

The second-round worker on PR #315 hit it: the main tree sat on `main`, its branch carried the PR's
commit, so Serena would have shown it the *pre-PR* version of the two files it had to edit. It
correctly deviated to `Read`/`Edit` on worktree paths and said so in its report — which is what the
rule above already licenses.

Two corrections to the brief template:

1. Say **"Serena reads the MAIN tree, which is on `main`"**, not "contents are identical". The first
   is always true; the second is a fact with a short shelf life.
2. **Fix-up rounds are the dangerous case**, not first-round work. The branch has commits by then,
   and the files under repair are exactly the ones that differ. Write the deviation into the brief
   for any round after the first, rather than leaving the worker to notice.

Serena stays mandatory for *navigation* — `find_referencing_symbols` across the repo is still right,
because call sites you are checking generally do live on `main`.

## Related
- [autodev loop] `tools/autodev/invoke-worker.ps1` — worker prompt
- [gotchas/russian-source-i18n-plural-n.md](russian-source-i18n-plural-n.md) — same session
