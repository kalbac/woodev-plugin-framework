# Gotcha: [tooling/orca] — a Codex worker in structured mode asks a question the terminal watchdog never sees

> Tags: orca, codex, structured, watchdog, question, ask | Session: s221 (07.11.2026)

## What happens

`worker-start --agent codex` on Orca 1.4.x now often returns `"mode": "structured"` with a `structworker_…` handle instead of a
`term_…` terminal. In s221 a Codex image worker sent `orchestration ask` (an aspect-ratio question) and waited about an hour: the
coordinator's `watch-workers.py` only tails `term_…` handles, so it never exited, and the question was found only by reading the
worker's transcript with `terminal read --terminal structworker_…`.

## Root cause

The watchdog watches terminal buffers and the Run inbox only through the handles it was given; a structured worker has no terminal
it can tail, and its `question` sits in the Run inbox until someone runs `check`.

## Fix

❌ Start `watch-workers.py` for the Claude terminals and assume a structured Codex worker is covered.

✅ When a structured worker is part of the wave, poll the Run yourself while waiting — `orchestration check --run <run> --json`
(parse every message) — or read `worker-list`: `attention: input` means it is waiting on you. A structured worker that is quiet
past ~30 min gets a `terminal read --terminal structworker_…` look.

## Related

- [a-mailbox-delivery-can-carry-several-messages-and-a-cut-hides-the-second](a-mailbox-delivery-can-carry-several-messages-and-a-cut-hides-the-second.md) — the other way a worker's question gets lost
- [Session s221](../sessions/s221.md) — where it was hit
