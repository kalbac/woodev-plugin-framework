# Gotcha: [tooling/orca] — `orchestration send --to dispatch:<id>` answers `dispatch_inactive` after the worker's `worker_done` — which may still be unread

> Tags: orca, orchestration, dispatch | Session: s204 (30.10.2026, Orca 1.4.x)

## What happens

A coordinator nudges a quiet worker with `send --to dispatch:<id>` and gets `dispatch_inactive`. It reads that as «the
worker died» and relaunches the task — but the worker had already finished: its `worker_done` was sitting unread in the
coordinator's inbox, so the relaunch would duplicate completed work.

## Root cause

`worker_done` settles the dispatch; a settled dispatch no longer accepts mail. The error describes the dispatch, not the
agent, and says nothing about the delivered result.

## Fix

❌ Wrong: on `dispatch_inactive` → `worker-start` again.

✅ Correct: first `check` the coordinator inbox (and `worker-list`) for a `worker_done` of that dispatch id; only a dispatch
with no `worker_done` and a dead terminal is a relaunch.

## Related

- [orchestration-mail-does-not-reach-a-busy-worker](orchestration-mail-does-not-reach-a-busy-worker.md) — `send --to dispatch:` is pull-only
- [an-oom-killed-check-wait-reads-as-an-empty-timeout](an-oom-killed-check-wait-reads-as-an-empty-timeout.md) — silence is not death
