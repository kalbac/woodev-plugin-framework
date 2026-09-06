# gotcha: `orca account list` serves a CACHED rate limit — a reading taken right after a run reports the state BEFORE it

**Namespace:** `[tooling/orca]`
**Discovered:** s122 (2026-09-06)

## Symptom

Two Codex critic rounds were run back to back to price `gpt-6-astra` against `gpt-5.6-luna`.
`orca account list --json` was read immediately after each one:

```text
after luna  -> session  0 % ->  3 %  | weekly 73 %
after astra -> session  3 % ->  4 %  | weekly 73 %
```

Read that way, astra looks CHEAPER than luna — it moved the session counter by one point against
luna's three, on a task where it also finished in a third of the wall time. That conclusion is
completely wrong, and it is the conclusion a session would carry into its handoff.

## Root cause

`result.rateLimits` is a **cached snapshot**, and the result carries its own age in
`rateLimits.<provider>.updatedAt` (epoch ms). Orca refreshes it on its own schedule, not when you
ask. Right after the astra round the cached figure was minutes old and still described the state
before that round; a few minutes later the same command returned **40 % / 79 %** — a jump of
**+36 pp session and +6 pp weekly** for that one review.

## ✅ Correct

**Check `updatedAt` against the wall clock before believing any figure**, and if it predates the
run you are pricing, wait and read again:

```bash
orca account list --json | python -c "
import json,sys,datetime
d=json.load(sys.stdin)['result']['rateLimits']['codex']
print(d['session']['usedPercent'], d['weekly']['usedPercent'],
      datetime.datetime.fromtimestamp(d['updatedAt']/1000).strftime('%H:%M:%S'))"
```

**The authoritative number is Codex's own**, written into every `token_count` event as
`payload.rate_limits.primary/secondary.used_percent`, alongside the exact token totals. Under
Orca, Codex runs with its OWN HOME, so the rollout is **not** in `~/.codex/`:

```text
%APPDATA%/orca/codex-runtime-home/home/sessions/<YYYY>/<MM>/<DD>/rollout-*.jsonl
```

⚠ **`multi_agent = true` means ONE run writes TWO rollout files** (a main thread plus a
sub-agent). Sum both, or you under-count by roughly a third — the luna round split 6.07 M / 1.21 M
tokens across its pair.

## ❌ Wrong

- Reading `orca account list` once, straight after a run, and treating the delta as that run's cost.
- Pricing a model from the session (5 h) window alone. It is a rolling window that decays; the
  WEEKLY figure is the one that actually gates the subscription.

## Related

- [[the-skipped-count-is-dominated-by-whether-sodium-is-enabled]] — the same shape: a measurement
  whose default reading answers a different question than the one asked.
- `CLAUDE.md` → Orca — carries the measured luna/astra prices this gotcha's method produced.
