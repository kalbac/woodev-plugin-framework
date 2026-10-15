# Gotcha: [tooling/parallel-agents] — the worker watchdog re-alerts on a stale «API Error» line still in the terminal buffer
> Tags: orca, watchdog, claude | Session: s181

## What happens

A Haiku worker hit «API Error: Connection lost mid-response». The coordinator nudged it and restarted
`watch-workers.py`; the watchdog exited at once with the same `ALERT … API Error` — the old line was still in the terminal tail while
the agent sat idle at the prompt. Restarting it again fires again; the alert carries no information about the NEW state.

## Root cause

The watchdog greps the terminal tail for error text whenever the agent is idle. After an error the text stays in scroll-back until
newer output pushes it out, so «idle + error text in tail» stays true even after a successful nudge.

## ✅ Correct

After a nudge, verify progress by the agent's real output (`orca terminal read` — a new turn, a written report file), not by the
watchdog. While the stale line is on screen, watch that worker's deliveries through the inbox only (run the watchdog over the OTHER
terminals; it still consumes the shared inbox, so the stuck worker's `worker_done` is not missed).

## Related

- The rule to run a watchdog for every wave: the `orca-team` skill §11 (global, not in this repo)
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md)
