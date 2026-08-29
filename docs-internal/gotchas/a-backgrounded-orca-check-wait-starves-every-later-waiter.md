# A backgrounded `orca orchestration check --wait` starves every later waiter, and the stream is not one JSON object

**Namespace:** `[tooling/parallel-agents]`
**Found:** s105 (30.08.2026), coordinating two workers.

## Trap 1 — one waiter at a time

`orca orchestration check --wait` holds the Run's FIFO waiter. Send it to the background (because
the Bash tool moved a long call there, or on purpose) and **every later `check --wait` in the
foreground returns immediately with `count: None` / no messages** — not because the worker is idle,
but because the backgrounded call owns the queue.

The symptom reads exactly like "the worker has produced nothing", which invites the wrong
conclusion: that the worker is stuck. Two full waits were spent on this in s105 while the worker was
in fact running normally.

```bash
# what it looks like
$ orca orchestration check --run run_x --wait --types worker_done --timeout-ms 540000 --json
count: None deliveryId: None        # returned in ~2 seconds, not 9 minutes
```

**Fix:** keep exactly one waiter. If a wait got backgrounded, stop it before starting another
(`TaskStop` on the task id), then wait in the foreground.

## Trap 2 — `--json` emits a stream, not a document

With `--wait`, the CLI prints a keepalive object roughly every 15 s and then the real result:

```json
{"_keepalive":true,"_heartbeat":true,"elapsedMs":15014,"deadlineMs":560000}
{"_keepalive":true,"_heartbeat":true,"elapsedMs":30019,"deadlineMs":560000}
{ …the actual result… }
```

`json.load()` on that dies with `JSONDecodeError: Extra data: line 2 column 1`. Decode with
`raw_decode` in a loop and keep the objects without `_keepalive`:

```python
dec = json.JSONDecoder(); i = 0; objs = []
while i < len(raw):
    while i < len(raw) and raw[i] != '{': i += 1
    if i >= len(raw): break
    try:
        o, end = dec.raw_decode(raw[i:]); objs.append(o); i += end
    except Exception: i += 1
real = [o for o in objs if not o.get('_keepalive')]
```

## Trap 3 — a follow-up `send` only lands on the worker's next `check`

`orca orchestration send --to dispatch:<id>` is inbox mail, not prompt injection. A worker that
never calls `orchestration check` **never sees it**. In s105 a mid-flight correction (a CI failure
the worker had to fix) was sent this way and was still unread when the worker reported `worker_done`;
the fix had to be made by the coordinator afterwards.

If a correction must land, verify it was consumed — or plan to do that work yourself.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the other "the receipt does not prove what you think" trap
- [a-worker-can-fan-out-to-background-forks-past-the-concurrency-cap](a-worker-can-fan-out-to-background-forks-past-the-concurrency-cap.md) — same area: what the coordinator can and cannot see
