# A network drop (ECONNRESET) stops every Claude worker at an idle prompt — it does not resume by itself

**Namespace:** `[tooling/orca]`
**Found:** s231 (15–16.11.2026), three workers stopped overnight.

## Trap

`API Error: Connection dropped (ECONNRESET)` ends the worker's turn; the terminal shows an empty `❯` prompt and Orca
reports `stopReason=needs-input` (or nothing). The task is not done and no `worker_done` will come. A worker whose
session then sat idle long enough may show «Resume this conversation? … inactive for 18h» when typed into.
Some terminals exit to the shell entirely (`claude --resume <id>` printed).

## Fix

1. `orca terminal list --json` → read each worker's tail.
2. A «Resume this conversation?» dialog: send Enter (`--text $'\r'`), then the prompt.
3. Send: «The network dropped mid-task. Continue your task exactly where you stopped and finish it (gates, push,
   worker_done).» with `--enter --wait-submit 10`.
4. A terminal that exited to the shell: check the branch on origin first — the work may already be pushed; if not,
   start a NEW worker on the same worktree (`--worktree path:<worktree>`) with a brief «continue from HEAD».

## Related

- [watch-workers-retriggers-on-stale-api-error-buffer](watch-workers-retriggers-on-stale-api-error-buffer.md)
