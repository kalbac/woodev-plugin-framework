# Gotcha: [tooling/orca] — A worker handed a survey task will fan out to background forks, straight past the machine's agent cap
> Tags: orca, orchestration, concurrency, subagents, memory | Session: s104

## What happens

The 2–3 agent cap is enforced by the **coordinator** choosing how many workers to start. Nothing
enforces it below that line: a worker is an ordinary agent session with its own Agent tool.

In s104 a single Sonnet worker was briefed with #644 — "audit 93 board cards". A survey over 93
independent items reads exactly like the textbook case for parallel agents, so the worker delegated
it: two background forks, one of which span up **six further nested sub-agents** (a `claude`, two
`general-purpose`, three more `fork`s) all chasing the same 93 cards. One fork also hit a retry
storm and had to be killed.

Eight unsupervised agents, from one dispatch the coordinator believed was one agent.

They were stopped before damage, and the single card comment they had posted was checked and kept.
But the coordinator's own RAM headroom reading — the thing the cap exists to protect — was measuring
a fiction: free memory hit **0.1 GB of 15.3** at one point in that session, and at 0.4 GB in s84 a
starting Codex died on `VirtualAlloc`.

## Root cause

The cap lives in the coordinator's head and in the wiki. The brief did not carry it, because the
standing brief lines cover Serena, installs, gates and CRLF — not delegation. A worker following
ordinary good practice will parallelise a fan-out task, and it has no way to know what else is
running on the machine.

## Fix

**Add the line to any brief whose task is a survey, sweep, or audit over many independent items:**

> Do this work yourself, in this session. Do NOT spawn subagents or background forks — the
> coordinator is running other agents on this machine and the concurrency cap is a hardware limit,
> not a preference. If the task is too big for one lap, do a part of it and say so in your report.

The last clause matters: without an escape hatch, forbidding delegation invites a rushed, guessed
"complete" answer instead of an honest partial one. The s104 worker, once it stopped forking,
deep-checked ~10 of 93 cards and **said so in the report** — which is the outcome to want.

Symptom to watch for while a worker runs: a `heartbeat` whose subject says something like
`alive - waiting on 2 background subagents`. That is the signal, and it arrives in the coordinator's
ordinary `check --wait` delivery.

## Related

- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — where the cap comes from and what breaks past it
- [wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — the brief's standing lines
