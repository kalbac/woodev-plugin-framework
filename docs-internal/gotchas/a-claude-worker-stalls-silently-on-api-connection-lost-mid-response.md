# Gotcha: [tooling/orca] — a Claude worker that hits «API Error: Connection lost mid-response» just stops; no worker_done ever comes

> Tags: tooling, orca, claude, worker | Session: s143

## What happens

A Claude Code worker under Orca (Sonnet 5 and Fable 5, macOS, 28.09.2026) printed
`⏺ API Error: Connection lost mid-response. The response above may be incomplete.` and ended its turn — idle at the
prompt, dispatch still `live`, no `worker_done`. `check --wait` only times out. It happened three times in one session,
twice to two parallel workers within the same minute.

## Root cause

A dropped connection to the model API ends the agent's turn; nothing in the TUI resumes it.

## Fix

❌ Wait on `check --wait` again, or relaunch the task (repeats finished work).
✅ After a quiet wait, read the worker's terminal tail; on that line, nudge it in place:

```bash
orca terminal send --terminal <H> --text "The API connection dropped mid-response. Continue the same task from where \
you stopped: check git status and your last step, finish what is missing, run the gates, then send worker_done via \
/Applications/Orca.app/Contents/Resources/bin/orca." --enter
```

Every nudged worker in s143 resumed and reported correctly.

## Related

- [codex-app-server-drops-mid-task-under-orca-resume-by-session-id](codex-app-server-drops-mid-task-under-orca-resume-by-session-id.md) — the Codex equivalent
- [../wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md)
