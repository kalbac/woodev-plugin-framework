# gotcha: a `--types`-filtered `check --wait` still wakes on heartbeats, and the next `worker_done` goes unseen

**Namespace:** `[tooling/orca]`
**Discovered:** s163 (2026-10-09), Orca 1.4.222

## What happened

The coordinator started one background waiter per wave:

```bash
orca orchestration check --wait --types "worker_done,escalation,question" --timeout-ms 3000000 --json
```

It returned within minutes on a delivery that held only a **heartbeat** — the run inbox is FIFO, and the oldest unacknowledged
delivery is returned whatever `--types` says. The coordinator acked it and did not restart the waiter, trusting the
«You have N orchestration messages» prompts Orca injects into the coordinator's terminal. Those prompts arrived for the first
few messages and then **stopped**. Twice in one night all workers had sent `worker_done` 10–20 minutes earlier while the
coordinator reported «all three are working»; the operator caught it both times («не вижу активных процессов»).

## ❌ Wrong

- One `check --wait --types worker_done,…` and then relying on the injected message prompts.
- Treating `worker-list` `projection.liveness = live` as «still working» — a settled worker reads `live` too.

## ✅ Right

A background loop that acks heartbeat-only deliveries itself and exits on the first delivery holding anything else; restart it
after every processed delivery:

```bash
for i in $(seq 1 200); do
  out=$(orca orchestration check --run "$RUN" --wait --timeout-ms 600000 --json 2>/dev/null)   # stdout only: stderr = keepalives
  res=$(echo "$out" | python3 -c '
import json,sys
r=json.load(sys.stdin)["result"]; ms=r.get("messages") or []
if not ms: print("EMPTY")
elif all(m["type"]=="heartbeat" for m in ms): print("HB "+r["deliveryId"])
else: print("MSG "+r["deliveryId"])')
  case "$res" in
    HB*)  orca orchestration check --run "$RUN" --ack "${res#HB }" --json >/dev/null ;;
    MSG*) echo "$res"; exit 0 ;;
  esac
done
```

The exiting background task notifies the coordinator; it then reads the delivery with a plain `check`, processes it, acks it,
and starts the loop again. After this was in place (s163, second half) no `worker_done` was missed.

## Related

- [a-backgrounded-orca-check-wait-starves-every-later-waiter](a-backgrounded-orca-check-wait-starves-every-later-waiter.md) — run only ONE waiter per run
- [one-check-wait-per-run-and-a-second-one-fails-invisibly](one-check-wait-per-run-and-a-second-one-fails-invisibly.md)
- [a-claude-worker-stalls-silently-on-api-connection-lost-mid-response](a-claude-worker-stalls-silently-on-api-connection-lost-mid-response.md) — the terminal watchdog that complements this
- `../gotcha-index/tooling.md`
