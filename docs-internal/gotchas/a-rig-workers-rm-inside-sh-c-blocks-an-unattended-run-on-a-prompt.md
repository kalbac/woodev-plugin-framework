# Gotcha: [tooling/orca] — a rig worker's `rm` inside `sh -c` raises an approval prompt that blocks an unattended run for ~2 minutes, then auto-denies

> Tags: orca, worker, rig, rm, approval-prompt, overnight | Session: s219 (06.11.2026)

## What happens

An unattended rig worker (overnight, nobody at its terminal) runs a command with `rm` inside an `sh -c` block. The permission layer
asks for approval. Nobody answers, the worker sits idle for about two minutes, and the prompt is then auto-denied — the command
does not run. The watchdog surfaces it as an escalation «Worker is waiting on a prompt».

## Root cause

`rm` inside `sh -c` is not covered by the allow rules, and an unattended worker has no human to approve it.

## Fix

- The coordinator approves from its own terminal: `orca terminal send --text 1` to that worker's terminal (the digit selects and
  confirms the prompt's «yes»).
- Design rig briefs so they need no `rm` in a shell block: the rig lock is `mkdir` to take and ONE `rmdir` to release (the s218
  handoff trap), and a rig is restored from a DB dump rather than by deleting files.
- Keep the watchdog running over an unattended wave — a silent prompt looks exactly like a quiet worker.

## Related

- [a-codex-structured-worker-can-quit-a-task-in-a-minute-without-trying](a-codex-structured-worker-can-quit-a-task-in-a-minute-without-trying.md) — another way a worker looks busy or silent while doing nothing
- [Session s219](../sessions/s219.md) — where it was hit
