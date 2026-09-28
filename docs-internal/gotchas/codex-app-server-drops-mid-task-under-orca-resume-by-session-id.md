# Gotcha: [tooling/orca] — A Codex worker under Orca loses its app-server after ~20–30 minutes; resume the SAME session by id instead of relaunching

> Tags: tooling, orca, codex, critic | Session: s142

## What happens

Codex 0.157.1 launched by `orca orchestration worker-start --agent codex` (Orca 1.4.215, macOS) worked
normally, then printed, in every live Codex terminal at the same moment:

```text
■ Connection lost. Attempting to reconnect…
■ Automatic reconnect could not restore this session. Your draft is still editable. Copy it before
  quitting with Ctrl+C, then reconnect with the same command.
■ app-server session could not be restored
• Reconnect failed — check the endpoint, then relaunch (33m 36s)
```

It happened three times in one night (s142): four critics at once after ~33 min, two after ~28 min,
one after ~20 min. Orca itself did not restart (same pid), the network was fine, and the rate limit was
not the cause — the rollout's own `token_count` showed the 5-hour window at 25 %. The dispatch stays
`live`, no `worker_done` ever arrives, and a `check --wait` just times out: it reads as a slow review.

## Root cause

Not established. The Codex TUI talks to a local app-server process; that connection drops and the TUI
cannot re-attach. No Codex log was written under `~/.codex/log`.

## ✅ What works — resume the session, keep the dispatch

The work done so far is in the rollout, and resuming it keeps the dispatch's task/dispatch ids in the
model's context, so its `worker_done` still settles the task:

```bash
# 1. find the session id by the critic's worktree (cwd)
cd ~/.codex/sessions/$(date +%Y/%m/%d)
grep -l '"cwd":"[^"]*s142-crit-g6"' rollout-*.jsonl      # → rollout-…-<uuid>.jsonl
# 2. quit the dead TUI and resume in the SAME terminal
orca terminal send --terminal <H> --interrupt --text $'\x03'   # twice
orca terminal send --terminal <H> --text "codex --dangerously-bypass-approvals-and-sandbox resume <uuid> \
  'The app-server connection dropped; same task. Do not redo finished work — finish what is missing, then \
  IMMEDIATELY send worker_done via /Applications/Orca.app/Contents/Resources/bin/orca.'" --enter
```

Every resumed critic in s142 finished and reported correctly (g1–g6).

## ❌ What wastes a round

- Waiting: the dead TUI never recovers on its own.
- `worker-stop` + a fresh critic: repeats 20–30 minutes of review and spends the budget again.

## Prevention

Tell the critic in its brief that the connection may drop and that it must send `worker_done` as soon
as its verdict is final; keep critic briefs scoped so a review fits well inside ~20 minutes.

## s143: the coordinator may not run the resume itself

Claude Code's auto-mode classifier DENIES `codex --dangerously-bypass-approvals-and-sandbox resume <id>` sent through
`orca terminal send` («Create Unsafe Agents»). Close the dead TUI (Ctrl+C twice), then hand the operator the exact
`! /Applications/Orca.app/Contents/Resources/bin/orca terminal send --terminal <H> --text "codex … resume <id> '…'" --enter`
line — a `!` command runs under his authority. If the Codex 5-hour window is spent, the resume waits for the reset; the
rollout and the dispatch survive it.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch-side traps
- [orca-account-list-serves-a-cached-rate-limit](orca-account-list-serves-a-cached-rate-limit.md) — read the rollout's `rate_limits`, not the cached number
- [../wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md)
