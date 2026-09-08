# One `check --wait` per Run — a second one fails, and the failure reads as "no worker finished"

> Namespace: `tooling/*` — added session 127 (2026-09-08). Cost about forty minutes of a coordinator's
> night, twice, before the raw JSON was read instead of the parsed field.

## The trap

An Orca Run holds **exactly one active actionable waiter**. Launch a second
`orca orchestration check --wait` against the same Run — trivially easy when the first is running in
the background and you have lost track of it — and the new one does not queue, block, or warn. It
returns immediately with:

```json
{ "ok": false, "error": { "code": "waiter_exists",
  "message": "Run run_xxxxxxxx already has an active actionable waiter." } }
```

**The damage is done by what happens next.** A parser that reaches straight for
`result.messages` / `result.count` — the normal shape — finds neither, because there is no `result`
at all. It prints something like `COUNT None`, or an empty list. Read as "the wait completed and no
worker reported anything", which is exactly what a healthy timeout looks like. Two consecutive false
readings in s127 were interpreted as "both workers are still going" while one of them had in fact
finished twenty minutes earlier.

## Root cause

`ok: false` with an `error` object is a *different top-level shape* from `ok: true` with a `result`
object. Every wait-parsing snippet written against the success shape degrades silently on the
failure shape, because `dict.get()` returns `None` rather than raising.

## ❌ Wrong

```bash
orca orchestration check --wait --types worker_done --timeout-ms 900000 --json \
  | python -c "import json,sys; d=json.load(sys.stdin); print(d['result'].get('count'))"
```

Also wrong: launching a fresh `check --wait` "to be safe" while an earlier backgrounded one may
still be alive. Check first, or reuse the one you have.

## ✅ Correct

```bash
orca orchestration check --wait --types worker_done --timeout-ms 900000 --json > wait.json
python - <<'PY'
import io, json
d = json.load(io.open('wait.json', encoding='utf-8'))
if not d.get('ok'):
    raise SystemExit('ERR ' + d.get('error', {}).get('code', '?'))   # waiter_exists lands here
r = d['result']
print('COUNT', r.get('count'))
PY
```

**Assert `ok` before touching `result`, on every orchestration call** — not just this one. And keep
one waiter per Run: when a wait returns, acknowledge its Delivery and start the next one from the
same place, rather than starting a spare.

## The neighbouring trap, same night

A `check --wait` process **can be killed by the OS under memory pressure** (this machine's free RAM
sat at ~0.55 GB of 15.3 with a single worker plus Docker). The harness reports that honestly as
`killed`, but the effect on the coordinator is identical to a silent timeout — no worker report
arrives and nothing looks wrong. After any `killed` notification, re-establish the waiter rather
than assuming the workers are simply slow.

## Related

- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — the RAM ceiling that kills waiters as well as agents
- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the same family: an orchestration receipt that reads as progress
- `docs-internal/wiki/orchestrating-agents-with-orca.md` — the adopted worker loop
