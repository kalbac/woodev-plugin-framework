# Gotcha: [tooling/parallel-agents] — An OOM-killed `check --wait` is indistinguishable from an empty timeout, and re-launching on that reading puts two workers in one tree

> Tags: orca, orchestration, memory, tooling, coordinator | Session: s133

## What happens

`orca orchestration check --wait` is a long-lived process, and on this machine it is the **first
thing the OS kills** when memory runs short. In s133 it was killed three times in a row while both
workers kept running normally.

The receipt looks like nothing happened: the command simply ends. Read as "the wait returned with no
messages", the obvious next step is to assume the workers died and start replacements — which would
put two agents in one worktree, the exact failure
[two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md)
is about.

## Why memory runs short

Not from too many agents. Measured during the kills:

```
php        1.83 GB   ← a worker running phpstan, which our own rules tell it to give 4G
vmmemWSL   1.70 GB   ← Docker Desktop's VM behind the rig
claude     ~1.4 GB   ← coordinator + workers
free                 0.70 GB of 15.31 GB at the trough, 4.60 GB once phpstan finished
```

So the trough is a **transient peak of the worker's own PHP gates**, not a standing overload. It
passes on its own; nothing needs stopping.

## The distinguishing measurement

Absence never proves exit. Ask the fleet, not the dead waiter:

```bash
orca orchestration worker-list --run <run_id> --json
```

A live worker reads `workerState: ready`, `terminalState: active`, `dispatchStatus: dispatched`.
That is the only thing that separates "my waiter died" from "the workers died".

## ❌ Wrong

Treating the ended wait as a verdict — retrying the dispatch, or reporting the wave as failed.

## ✅ Correct

1. `worker-list` first, every time a wait ends without a message.
2. If the workers are alive, just re-enter the wait. Shorter windows make each kill cheaper, but do
   not avoid it.
3. Do not stop the rig or a worker to free memory unless the trough persists after the PHP gates
   finish — the peak is transient and stopping things costs more than it buys.

## Related

- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — the hardware cap this sits inside
- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — what a wrong reading leads to
- `docs-internal/sessions/s133.md`
