# Gotcha: [tooling/orca] — the watchdog fires «exited» on a terminal that was released on purpose; it is a false alert
> Tags: orca, watchdog, terminal, check-wait | Session: s227

## What happens

The terminal watchdog of the `orca-team` skill reported a worker terminal as exited right after the coordinator had released it. Reading
it as a crashed worker leads to a needless investigation or a restart.

## Root cause

The watchdog watches terminal state only; it cannot tell a released terminal from a dead one.

## Fix

Before reacting to «exited», check whether you released that terminal. For a real wait on a dispatch use `orca orchestration check
--wait`, which follows the task, not the terminal.

## Related

- [a-structured-codex-question-is-invisible-to-the-terminal-watchdog](a-structured-codex-question-is-invisible-to-the-terminal-watchdog.md)
- [a-backgrounded-orca-check-wait-starves-every-later-waiter](a-backgrounded-orca-check-wait-starves-every-later-waiter.md)
