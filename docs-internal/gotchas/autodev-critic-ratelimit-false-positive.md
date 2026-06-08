# invoke-critic mis-reads benign repo text as a rate-limit (the loop's own docs poison its 429 detector)

**Namespace:** `[autodev/critic]`
**Discovered:** 2026-06-07 (autodev loop overnight resume — s1-p1-ajax-base) · **Fixed:** commit `b186c52`

## The trap

`tools/autodev/invoke-critic.ps1` runs the read-only codex critic and captures **all** of its
combined output (`*> $combinedFile` — stdout + stderr + everything the critic prints). The old
code then decided rate-limiting like this:

```powershell
$rateLimited = Test-RateLimited -ExitCode 1 -Stderr $combined   # <-- BUG
```

Two faults compound:
1. **Hard-coded `-ExitCode 1`** — `Test-RateLimited` first checks `ExitCode -ne 0`, so it was
   *always* in the "could be rate-limited" state regardless of codex's real exit code.
2. **Scans the ENTIRE combined output** for `429|rate.?limit|quota|overloaded|too many requests|usage limit`.
   But the critic is explicitly told to read the repo for facts, and it routinely reads
   `docs-internal/CURRENT-STATE.md`, `.autodev/digest.md`, and the gotcha files — which **describe
   the earlier critic-429 fix**. Those words land in the critic's own printed output and trip the
   detector.

Net effect: a critic that **successfully returned a valid `broken`/`clean` verdict** was
classified as rate-limited (exit 4). The conductor then refunded the attempt and returned the task
to `pending`, **discarding the real verdict** — so the task re-ran its (expensive, ~10 min opus)
worker forever and never committed or properly escalated. The loop's own documentation about a past
rate-limit fix had become a self-inflicted, recurring rate-limit false-positive.

## Correct pattern (the fix)

A successfully parsed verdict is authoritative and **must win over any rate-limit heuristic**:

1. Parse the structured verdict (`-o` output) FIRST.
2. If a verdict parsed → use it (`clean` → exit 0; otherwise → exit 3). Never a rate-limit.
3. Only when codex returned **no usable verdict** do you test for a rate-limit — and then with
   codex's **real** exit code (`$exit`, captured from `$LASTEXITCODE`). A clean `exit 0` is never a 429.

Failure mode stays safe: a real usage-limit produces no verdict + non-zero exit + the keyword → exit 4
(back off, refund). A no-verdict run without the keyword → exit 3 (uncertain → human). Validated live:
ajax-base's real `broken` verdict now lands, and a genuine codex usage-limit minutes later was still
caught correctly.

## Why it matters / who else is at risk

Any heuristic that scans an LLM's free-form output for control keywords is fragile when the LLM is
asked to read documents that contain those keywords. Prefer a structured success signal (a parsed
result, a real exit code) over keyword-sniffing the whole transcript. The same shape bit the loop
before via a different root cause (a rate-limited critic counted as task failures → false poison).

## Related
- [[autodev-attempt-refund-symmetry]] — the earlier critic-429 issue (refund on external pause); this
  is the *adjacent* bug where a NON-rate-limited critic was mislabeled as rate-limited.
- `tools/autodev/invoke-critic.ps1` — the fixed decision flow.
- `tools/autodev/_common.ps1` → `Test-RateLimited` — requires `ExitCode -ne 0` AND a keyword.
