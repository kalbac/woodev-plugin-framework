# Gotcha: [tooling/parallel-agents] — `--model terra` kills a Codex run, and the buffer shows it as a warning first
> Tags: tooling, orca, codex | Session: s103

## What happens

You start a Codex critic through Orca with the model the operator names in conversation:

```bash
orca orchestration worker-start --task <id> --worktree current --agent codex --model terra --json
```

`worker-start` returns `stage: input_accepted` and echoes `launch.effective = {"agent":"codex","model":"terra"}`.
The TUI comes up in the right directory, the status line reads `terra high`, and the buffer says:

```
⚠ Model metadata for `terra` not found. Defaulting to fallback metadata; this can
  degrade performance and cause issues.
```

That reads like a warning about context accounting, and the run looks alive. It is not. Further
down the SAME buffer, after the MCP startup noise and three failed hooks, sits the real outcome:

```
■ {"type":"error","status":400,"error":{"type":"invalid_request_error",
   "message":"The 'terra' model is not supported when using Codex with a ChatGPT account."}}
```

The turn is dead, no work was done, and the dispatch stays `dispatched` forever. In s103 this
cost a full critic round: the warning was read, judged "metadata only", and the run was left to
finish something it had already stopped doing.

## Root cause

`terra`, `luna` and `sol` are the SHORT names the operator uses in conversation. They are not
model ids. The real id is in his own `~/.codex/config.toml`:

```toml
model = "gpt-5.6-terra"
model_reasoning_effort = "high"
```

Passing `--model terra` therefore does two bad things at once: it fails to resolve, AND it
overrides a config default that was already correct.

## Fix

❌ Wrong — the conversational short name:

```bash
orca orchestration worker-start --task <id> --agent codex --model terra
```

✅ Correct — omit `--model` entirely and inherit `config.toml`:

```bash
orca orchestration worker-start --task <id> --worktree current --agent codex --json
# status line then reads: gpt-5.6-terra … D:\Projects\woodev_framework
```

Pass `--model gpt-5.6-terra` only when overriding a different configured default.

**Read the WHOLE buffer before judging a Codex launch healthy.** The ordering is adversarial:
the benign-looking metadata warning prints early, the fatal 400 prints last, and between them sit
MCP startup lines long enough to push the error out of a short tail read. The single signal that
the turn is actually running is the status line changing to `Working (Ns • esc to interrupt)` —
check for that, not for the absence of an error.

**Recovering the stuck dispatch:** `worker-stop` answers `stop_unknown` with
`"The worker terminal is user_owned; no terminal was closed."`, and the Task then refuses
`task-update --status ready` while the dead Dispatch is still active. Do not fight it — create a
NEW Task with the same spec and start a fresh worker. The stale Dispatch is inert.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch recipe this sits on top of; the brief still arrives unsubmitted and still needs the empty `--enter`
- [dispatch-inject-reports-failure-after-succeeding](dispatch-inject-reports-failure-after-succeeding.md) — the other direction: a receipt that lies about failure rather than about success
- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — `input_accepted` was returned here too, for a run that never made a single API call
- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why Codex is launched in an Orca terminal at all
