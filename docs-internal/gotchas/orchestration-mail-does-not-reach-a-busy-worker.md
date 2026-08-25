# `orchestration send --to dispatch:` does not reach a worker that is mid-task

**Namespace:** `[tooling/parallel-agents]`
**Found:** s92 (25.08.2026).

## The trap

The Orca guide presents `orca orchestration send --to dispatch:<id>` as *"coordinator guidance to
one supervised worker"*, and it is — but delivery is **pull, not push**: the message sits in the
Run mailbox until the worker itself calls `orchestration check`. A Claude worker in the middle of a
task does not poll.

Measured in s92: a correction sent that way was never seen. The worker finished the task using the
wrong key, and the coordinator only found out by reading the file afterwards. A second correction
sent with `orca terminal send --terminal <handle> --text ... --enter` landed immediately and was
applied mid-turn.

## ✅ How to be right

| Need | Use |
|---|---|
| Correct a worker that is **working right now** | `orca terminal send --terminal <handle> --text "..." --enter` |
| Guidance the worker will pick up at its next checkpoint | `orca orchestration send --to dispatch:<id>` |
| A tracked new task | `worker-start` / `dispatch --inject` |

If the correction matters, **verify it landed** — read the file the worker was about to write, not
the send receipt. `"ok": true` on the send means the message was stored, never that it was read.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md)
- [dispatch-inject-reports-failure-after-succeeding](dispatch-inject-reports-failure-after-succeeding.md)
