# gotcha: a coordinator terminal consumes ONE Orca Run — a newer `run-create` fences `check` on the older Run

**Namespace:** `[tooling/orca]`
**Discovered:** s195 (2026-10-24), Orca 1.4.237

## Symptom

The coordinator opened a second Run (`run-create`) while a worker of the first Run was still busy. Every later
`orca orchestration check --run <first run>` answered `consumer_fenced` — «This coordinator terminal is bound to
<newer run>, not <first run>» — and `worker-start --run <first run>` refused the same way. A worker's report sent to the
first Run sat undelivered. Separately, two background waiters on the SAME Run raced: both got an error payload instead of
the message.

## Root cause

`run-create` rebinds the calling coordinator terminal to the new Run; consumption (`check`, `worker-start`, acks) is fenced
to the bound Run only. Deliveries are FIFO per Run with one consumer.

## ❌ Wrong

```text
orca orchestration run-create ...        # Run B, while Run A still has a live worker
orca orchestration check --run run_A     # -> consumer_fenced
```

## ✅ Correct

- Keep one Run per wave; dispatch new tasks into the bound Run instead of opening another while workers are live.
- If a second Run already exists, tell the remaining workers to report to `run:<bound run>`, or rebind with
  `orca orchestration run-use --id <run>` before reading the older one.
- One background waiter per Run.

## Related

- [orca-account-list-serves-a-cached-rate-limit](orca-account-list-serves-a-cached-rate-limit.md)
