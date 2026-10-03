# gotcha: an Orca Claude worker exits after `worker_done` — `terminal send` then fails `terminal_not_writable`

**Namespace:** `[tooling/orca]`
**Discovered:** s149 (2026-10-03), cards #332 and #1075 (Orca 1.4.218)

## What happened

Earlier in the same session a settled Sonnet worker, `worker-retain`ed after its `worker_done`, took follow-up rounds via
`orca terminal send` (#331, three rounds). Later, for #332 and #1075, the same move answered:

```text
{"ok":false,"error":{"code":"terminal_not_writable"}}
```

`terminal read` showed an empty screen, and `worker-start --terminal <handle>` refused with «Terminal … is not running a recognized
agent». The Claude process had exited after its `worker_done`; the retained dispatch and the terminal tab outlived it.

## ❌ Wrong

```bash
orca orchestration worker-retain --dispatch <ctx>
orca terminal send --terminal <handle> --text "Follow-up: read <brief>" --enter   # agent gone → not writable
```

## ✅ Correct

Treat a settled Claude worker as gone. For a follow-up round start a FRESH worker in the SAME worktree, with a self-contained brief that
points at the previous report and the critic's review:

```bash
orca orchestration worker-release --dispatch <old ctx>
orca terminal close --terminal <old handle>
orca orchestration worker-start --worktree path:<same worktree> --agent claude --model claude-sonnet-5-5 \
  --spec "You continue issue #N in this worktree … read <brief-fix.md>"
```

The work is safe — it is on the branch; only the agent's conversational context is lost. Check `git status` in the worktree first.

## Related

- [a-claude-worker-stalls-silently-on-api-connection-lost-mid-response](a-claude-worker-stalls-silently-on-api-connection-lost-mid-response.md) — the other way a Claude worker silently stops
- `../gotcha-index/tooling.md`
