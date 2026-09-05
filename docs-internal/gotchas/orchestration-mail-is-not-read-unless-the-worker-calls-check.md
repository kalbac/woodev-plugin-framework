# Gotcha: [tooling/orca] — `orchestration send` is mail, not injection: a head-down worker never reads it

> Tags: orca, orchestration, agents, coordination | Session: s118

## What happens

You dispatch a worker, then realise mid-run that part of the brief was wrong. The obvious fix is
the documented follow-up channel:

```bash
orca orchestration send --to dispatch:<id> --subject "CORRECTION" --body "<...>" --json
```

It returns `ok: true`. The message is real, addressed correctly, and delivered to the right
mailbox. **The worker never sees it**, and finishes the task on the brief you were trying to
correct.

Measured in s118: two Sonnet workers ran ~28 and ~20 minutes. Three correction messages were sent
across them. **Neither worker called `orchestration check` even once**, so all three sat unread
until the dispatch settled. One worker had been told not to hand-write a `.po` -> `.mo` compiler,
because the repo already has a verified one (`wp i18n make-mo`, gotcha
`the-mo-is-reproducible-from-the-po`) — and hand-wrote one anyway, costing a full extra round.

## Root cause

`orchestration send` writes to an inbox. The Orca guide says so plainly — *"The follow-up is
structured inbox mail, not prompt injection. The worker's next `orchestration check` receives it"* —
and the operative words are **the worker's next `orchestration check`**. Nothing wakes the agent,
nothing appears in its context, and no tool result mentions the message. An agent working a long
task has no reason to poll a mailbox it was not told to poll, and the injected lifecycle preamble
does not make it a habit.

The `ok: true` receipt is what makes this expensive: it reports that the mail was *stored*, which
reads exactly like the mail was *delivered to a reader*. Same shape as the two lifecycle receipts
this project has already been burned by — `input_accepted` is not proof a worker started, and
`worker-release` answering `ok: true` while leaving the terminal running.

## ❌ Wrong

```bash
# Send the correction and carry on, assuming it landed.
orca orchestration send --to dispatch:<id> --subject "CORRECTION: ..." --body "..." --json
```

## ✅ Correct

Two halves; do both.

**1. Put the obligation in the brief itself**, as an explicit numbered step, not an aside:

> Run `orca orchestration check --json` at the start, and again at least once mid-task. That
> mailbox is how I correct a brief that turns out to be wrong.

**2. After sending anything that changes the work, confirm it was read.** Read the worker's buffer
and look for the check call or the changed behaviour:

```bash
orca terminal read --terminal <handle> --json
```

If the work is already heading the wrong way, do not wait for a mailbox poll that may never come —
`orca terminal send --terminal <handle> --text "<the correction>" --enter --json` puts it straight
into the agent's context.

⚠ `terminal send` into a worker's terminal makes the later `worker-release` return `retained`
(`user_takeover`). That is expected, not a failure — clean the terminal up with the worktree at
session end.

## The cheaper lesson underneath

A correction that arrives after the worker has finished is not a correction, it is a second round.
In s118 both corrections would have been unnecessary had the briefs been checked against
`GOTCHAS.md` **before** dispatch: the `.mo` tooling was already documented, and so was the reason.
The mail channel is a repair mechanism, and repair is more expensive than getting the brief right.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md)
  — the same receipt-versus-reality gap at launch time.
- [worker-release-answers-retained-and-leaves-the-terminal-running](worker-release-answers-retained-and-leaves-the-terminal-running.md)
  — and again at teardown. Three of these now; read the `state`, never the `ok`.
- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the fact the unread
  correction was carrying.
- [wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — the worker
  loop this belongs to.
