# Gotcha: [tooling/parallel-agents] — three parallel `gpt-6.1-sol` `high` audits exhaust a Codex Plus 5-hour window in ~10 minutes

> Tags: codex, rate-limit, sol, audits | Session: s184

## What happens

The #1324 code audit launched three `gpt-6.1-sol` `high` runs at once (framework React/TS, CDEK, Yandex). The Codex Plus 5-hour
window was exhausted in about ten minutes.

## Root cause

`sol` is the expensive model (see the measured astra/luna price gap in `CLAUDE.md`); the window is shared by every Codex terminal
on the account, so parallelism multiplies the burn, it does not buy time.

## Fix

❌ A fan-out of `sol` audits «to save wall clock».

✅ Stagger `sol` runs one at a time; give the first pass to `luna` and keep `sol` for the second round with a coverage table
(s184: the ~90-second luna first passes were shallow, the sol round 2 found the real defects). Check `orca account list`
(mind `updatedAt`) before a wave. Codex rate-limit reset credits exist but need the operator's word.

## Related

- [codex-rate-limit-prompt-rejects-worker-done-but-the-body-survives](codex-rate-limit-prompt-rejects-worker-done-but-the-body-survives.md) — what the exhausted window looks like mid-run
- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — the Mac's brake is this window
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
