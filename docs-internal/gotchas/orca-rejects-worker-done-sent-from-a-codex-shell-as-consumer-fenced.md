# Orca can reject a Codex worker's `worker_done` (`consumer_fenced`) — settle the dispatch with `worker-stop`

**Namespace:** `[tooling/orca]`
**Discovered:** s157 (06.10.2026), Orca 1.4.220, codex 0.160

## What happens

A Codex worker finished its task (report written, gates green) but its `orchestration send --type worker_done` was
refused — first "this shell was not recognized as the dispatched terminal", then on a resend with explicit
`--from <its handle>` `consumer_fenced` ("run the command from the worker's own terminal"). The dispatch stayed
`in_progress` with `unverifiable / stale_status` liveness and `nextAction: none`, so a waiting coordinator never wakes.
Before failing, the worker wandered into `orca computer …` commands trying to fix it.

## ✅ Correct

1. Watch the terminal, not only the inbox: a worker whose tail says it is done but whose dispatch is still open is this case.
2. Read the report file to confirm the work; the transcript is the positive proof the final turn sent no accepted `worker_done`.
3. `orca orchestration worker-stop --dispatch <id> --json` → `state: stopped`, terminal closed. Work on disk is kept.

Do not ask the worker to resend more than once; do not let it run `orca computer` commands.

## Related

- `docs-internal/wiki/orchestrating-agents-with-orca.md`
- gotcha `an-orca-claude-worker-exits-after-worker-done-so-its-terminal-is-not-writable`
