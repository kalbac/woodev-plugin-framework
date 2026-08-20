# gotcha: `input_accepted` is not proof a worker started — the prompt can sit queued forever

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s83 (2026-08-20)

## What happened

Three of five agents dispatched through Orca orchestration in s83 accepted their task and then did
nothing at all. `orca orchestration worker-start` returned a clean receipt every time:

```json
{ "state": "ready", "stage": "input_accepted" }
```

and `worker-show` kept reporting `status: dispatched`, `state: ready`. Every liveness signal said
the worker was fine. It had not executed a single tool call.

Reading the terminal buffer showed why. The prompt was **in the input box, unsubmitted**:

```text
› [Pasted Content 9239 chars]   tab to queue message
⚠ MCP startup incomplete (failed: github, laravel-boost, telegram)
```

The agent TUI was still starting its MCP servers when the text arrived, so the paste landed but
the Enter never registered as a submit. The fix is one keystroke:

```bash
orca terminal send --terminal <handle> --text "" --enter --json
```

## Why the usual guard does not catch it

`orca terminal wait --for tui-idle` is the documented way to avoid racing startup, and it is
**not sufficient** — it already had a recorded habit of counting a dialog as idle (s82). A TUI
still enumerating MCP servers reads as idle too. `input_accepted` in the receipt only means Orca
handed the text to the terminal, not that the agent consumed it.

## The rule

**After starting any worker, verify it actually began** by reading its buffer for real activity —
a tool call, a thinking indicator, a `Working (…)` line — not by trusting the receipt or the
dispatch status. Do it once, early. A worker that never starts looks exactly like a worker that is
thinking hard, and `check --wait` will burn its entire timeout on it.

In s83 this cost two full nine-minute wait windows before anyone looked at a buffer.

## Related observation, not proven

The same session saw `UserPromptSubmit hook timed out after 10s — output discarded` in a worker
buffer, and Orca installs eleven hooks into `~/.claude/settings.json` all with `timeout: 10`. That
message concerns the hook's own output, not the prompt, and it was observed on a later nudge
rather than on the original delivery — so it is **not** established as the cause of the stall.
Recorded here only so the next person does not re-derive the correlation and mistake it for a
finding. Codex's Orca hooks were separately observed failing outright (`PostToolUse hook (failed)
— exited with code 1`), which is what `orca agent hooks prepare-codex` exists to repair.

## Related

- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — the other half of "the receipt looked fine"
- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — verification is the orchestrator's job, not the worker's
- `../wiki/orchestrating-agents-with-orca.md` — where the start-verification step belongs in the loop
