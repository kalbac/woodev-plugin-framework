# Gotcha: [tooling/orca] — one mailbox delivery can carry several messages; a `cut`/`tail` view hides the second, and a worker's question waits forever

> Tags: orca, watchdog, check, inbox, question, delivery | Session: s220 (06.11.2026)

## What happens

The coordinator's watchdog (`watch-workers.py`) or a `check --wait` exits on a delivery and prints it as ONE JSON object
holding a `messages` array. In s220 a critic's `worker_done` and a Codex worker's `question` ("may I edit PHP without
Serena?") arrived in the same window. The coordinator read the delivery through `tail -3 … | cut -c1-2500`, saw only the
`worker_done`, and never answered the question. The worker sat idle for ~1.5 h with zero diff; `worker-list` showed
`attention: ["input", …]` the whole time.

## Root cause

`check` acknowledges every message it returns. Whatever the reading command truncates is gone from the inbox — nothing
wakes the coordinator for it again.

## Fix

❌ `tail -3 out | cut -c1-2500` or `head -c 3000` on a delivery.

✅ Parse every message, every time:

```bash
python3 -c 'import sys,json
s=open(sys.argv[1]).read(); d=json.JSONDecoder().raw_decode(s)[0]
for m in d["result"]["messages"]: print(m["id"], m["type"], m.get("subject",""), "|", m["body"][:900])' OUT
```

(for a watchdog `DELIVERY {…}` line, `json.loads(line[9:])["messages"]`). A worker quiet past ~40 min: `worker-list` —
`attention: input` means it is waiting on YOU; `orchestration inbox --terminal run:<run> --limit 50` shows what `check`
already took.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the other way a Codex worker sits idle
- [Session s220](../sessions/s220.md) — where it was hit
