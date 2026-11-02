# gotcha: local-ci `--pause-on-failure` waits for input, so an unattended worker idles forever

**Namespace:** `[tooling/local-ci]`
**Discovered:** s199 (2026-10-26), run-local-ci 0.18.1, MacBook (arm64, OrbStack)

> **Platform:** measured on macOS; the flag's behaviour is the same wherever local-ci runs.

## Symptom

A worker in an overnight wave ran `local-ci --quiet --pause-on-failure` as its own loop. One step failed, local-ci stopped
and waited for a keypress, and the worker sat on that prompt with no output and no `worker_done`. Nobody was watching the
terminal, so the worker idled for about 7 hours (06:07 → 13:06 in s199) and the coordinator only noticed by chance.

## Root cause

`--pause-on-failure` is an interactive affordance: on a failed step it holds the run open so a human can read the log and
decide. A worker has no human at its terminal, so the pause is a hang. The flag gives no timeout and no exit code while it
waits, so a watchdog that only reads terminal output cannot tell «paused» from «still running».

## Fix

- **Attended vs unattended.** The global agent rule lets an agent run `--quiet --pause-on-failure` for its own loop —
  that is fine while a person is at the terminal and can answer the pause. Never in an unattended worker or an overnight wave.
- Unattended workers and overnight waves run `local-ci` without `--pause-on-failure`: the run fails, the worker reads the log and
  reports the failure through `escalation` or `worker_done --outcome failed`.
- Use `--pause-on-failure` only in a visible terminal that a person is watching, as the coordinator does for its merge-evidence run.
- A watchdog for a wave also polls the worker's state (`worker-list`), not only its terminal tail, and flags a worker that has
  been quiet for too long.

## Related
- [tooling/local-ci] [npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt](npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt.md) — the same class of trap: an interactive prompt that parks a terminal nobody reads
- [sessions/s199](../sessions/s199.md) — How the work went (the idle worker and the watchdog that missed it)
