# A `terminal send` to a Codex worker that already sent `worker_done` starts NEW, unsupervised work

**Namespace:** `[tooling/orca]`
**Found:** s154 (05.10.2026), card #1107, Codex gpt-6-luna under Orca 1.4.220.

## What happened

The #1107 Codex worker raised an escalation («Serena is unavailable, may I read PHP with the shell?»).
The coordinator answered it with `orchestration reply` AND, because mail does not reach a busy worker,
also with a `terminal send` of the same permission. The worker meanwhile finished its turn and sent
`worker_done --outcome failed` (no files changed).

Codex then took the queued `terminal send` as a NEW user prompt and started implementing — outside any
dispatch. Consequences, all invisible from the orchestration side:

- the round-2 `worker-start --terminal <handle>` failed `failedStage: agent_readiness`,
  `lastError: timeout` — the terminal was busy, not dead;
- the work in progress belonged to no Task: it could never send a valid `worker_done`, and
  `worker-list` showed the dispatch `failed` while the agent was coding;
- the settled dispatch's release later answered `retained / no_owned_resource`.

## ✅ What to do

- **Do not `terminal send` to a worker that may be about to settle.** Answer an escalation with
  `orchestration reply` only, unless the worker is provably mid-turn and blocked on that answer.
- If it happens anyway: let the agent finish, and give it a report FILE to write plus a marker of your
  own (the coordinator's watchdog watched the report file's mtime, `-nt` a touch file), not a
  `worker_done` — its old IDs are settled. Read the work as user-owned and put it through the same
  critic gate.
- **Watchdog regex:** do not alert on `usage limit` — Codex's idle footer tip reads «…access available
  usage limit resets». Match `hit your usage limit` / `Approaching rate limits` instead. A marker string
  you ask the worker to print also appears in YOUR prompt echoed in its tail — watch a file, not text.

## Related

- [orchestration-mail-does-not-reach-a-busy-worker](orchestration-mail-does-not-reach-a-busy-worker.md)
- [an-orca-claude-worker-exits-after-worker-done-so-its-terminal-is-not-writable](an-orca-claude-worker-exits-after-worker-done-so-its-terminal-is-not-writable.md)
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md)
