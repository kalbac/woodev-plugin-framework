# `orca orchestration check --json` is pretty-printed, so a line parser reads it as empty

**Discovered:** s132 (12.09.2026), waiting on the #855 worker.

## The trap

`check --wait --json` emits **two different shapes on the same stream**:

- keepalive heartbeats, one compact object **per line**: `{"_keepalive":true,"elapsedMs":…}`
- the actual delivery, **pretty-printed across many lines**

A parser written for the first — read line, `json.loads`, skip keepalives — cannot parse the
second, and its natural failure mode is to report **nothing**. Three consecutive waits were read as
«no delivery» when each had in fact delivered; the worker's heartbeats sat unacknowledged and kept
replaying.

The damage is not a lost message — `check` replays an unacknowledged delivery, so nothing is
dropped. The damage is that the coordinator concludes the worker is silent, and starts
investigating a worker that is fine. Per the guide's own safety floor, absence is a checkpoint, not
a fact — and here the absence was manufactured by the reader.

## ❌ Wrong

```python
for line in open(path):
    obj = json.loads(line)        # the real delivery spans ~40 lines
    if not obj.get('_keepalive'):
        deliveries.append(obj)
```

## ✅ Correct

Drop the keepalive LINES, then decode what remains as a stream of objects:

```python
text = '\n'.join(l for l in raw.splitlines() if '"_keepalive"' not in l)
dec, i, objs = json.JSONDecoder(), 0, []
while i < len(text):
    start = text.find('{', i)
    if start < 0:
        break
    try:
        obj, i = dec.raw_decode(text, start)
        objs.append(obj)
    except ValueError:
        i = start + 1
```

## Two neighbouring traps from the same wait

- **Give the Bash timeout real headroom over `--timeout-ms`.** A 540 s orca timeout under a 560 s
  shell timeout truncates the final object often enough to look like the same bug.
- **Do not background a `check --wait`** — it holds the FIFO waiter and starves every later one
  (its own gotcha).

## Related

- [a-backgrounded-orca-check-wait-starves-every-later-waiter](a-backgrounded-orca-check-wait-starves-every-later-waiter.md)
- [one-check-wait-per-run-and-a-second-one-fails-invisibly](one-check-wait-per-run-and-a-second-one-fails-invisibly.md)
- [a-reused-orca-terminal-reports-the-previous-turns-transcript](a-reused-orca-terminal-reports-the-previous-turns-transcript.md) — the other way a live worker reads as stalled
