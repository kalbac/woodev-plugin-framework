# Refund the circuit-breaker attempt on EVERY external pause, not just the worker's

**Namespace:** `[autodev/circuit-breaker]`
**Discovered:** 2026-06-06 (autodev loop, escalation `poison-s1-p1-warehouse-store`)

## The trap

The conductor (`tools/autodev/conductor.ps1`) increments a persisted `attempts` counter at the
top of every iteration and quarantines the task as a **poison** escalation once
`attempts > MaxAttempts` (=3). Several exit paths after that increment are *external pauses*
(a 429 / rate-limit from a model transport), NOT genuine failed attempts — and each one must
**refund** the attempt, or repeated pauses silently march a perfectly good task into a false
poison.

The worker rate-limit path was fixed for exactly this (commit `557126a`):

```powershell
if ($w.Status -eq 'RATE_LIMITED') {
    Restore-Attempt -TaskId $task.id -Attempts $attempts   # refund: external pause, not a failure
    Move-Task -TaskId $task.id -ToDir $Config.QueuePending
    return $task
}
```

…but the **critic** rate-limit path (codex/GPT-5.5 exit 4) was missed — it returned the task to
pending **without** refunding:

```powershell
# WRONG (pre-2026-06-06): no refund -> each codex 429 advances the breaker
if ($criticExit -eq 4) {
    Move-Task -TaskId $task.id -ToDir $Config.QueuePending
    return $task
}
```

So warehouse-store — worker status DONE, `composer` green, clean additive diff — was quarantined
"failed across 3 fresh agents" (empty evidence) purely because codex took three back-to-back
429s. Its `verdict.json` is the fingerprint: `verdict: uncertain`, `confidence: 0`,
`broken_contracts: []`, and a `notes` blob full of `rate-limit / ERROR / Exception`.

## Correct pattern

- Every post-increment "external pause" exit (worker 429, critic 429, and any future LLM
  transport) refunds via the shared `Restore-Attempt` helper. Keep the worker and critic paths
  **symmetric** — if you add a new model-backed step, it inherits the same rule.
- A genuine failed attempt (bad diff, gate RETRY, real timeout) does **not** refund — the
  breaker must still fire for actually-stuck tasks.
- The invariant is locked by `conductor.ps1 -SelfTest` (no subprocesses): external pauses never
  reach the breaker threshold; genuine failures still cross it.

## Reading the symptom

A "poison" with **empty evidence** + a `verdict.json` showing `confidence: 0` / `uncertain` /
`notes` full of rate-limit text = an infra-failed critic, NOT bad code. Treat it as a re-gate
(human one-glance), not a drop. Distinguish "critic errored (infra)" from "critic ran and is
uncertain (judgment)" before believing a poison label.

## Note on critic over-aggression (greenfield-additive)

The "critic too aggressive on additive diffs" worry was largely this bug in disguise: in the
warehouse-store case the critic never ran (rate-limited). When it *does* run it is well
calibrated — the prompt already says "a purely additive change that introduces NO contract value
… is normally clean; do not invent doubt", and the resolved S1 critic verdicts were each
confirmed correct (pickup-selection, order-handler) or clean (checkout-handler). Do **not** retune
critic calibration without a real false-positive example — that would be a fix without a root cause.

## Related
- Fix: `tools/autodev/conductor.ps1` (`Restore-Attempt`, critic exit-4 path, `-SelfTest`)
- Prior half of the same bug: worker 429 refund, commit `557126a`
- `.autodev/escalations/_outbox.md` — `poison-s1-p1-warehouse-store` resolution
- `docs-internal/autodev-loop-runbook.md` — conductor per-iteration spine
