# GOAL — autodev adversarial dev loop (immutable anchor)

> This is the loop's North Star. A disposable worker reads it before anything.
> It is short on purpose. The **live source of truth** for *what to build now* is the
> program tracker, not this file.

**What:** Execute a continuously-running, multi-model development loop on this repo.
A Claude worker makes one file-disjoint change per fresh session; a non-Claude critic
(GPT-5.5) adversarially verifies it; a machine gate (`composer check` + INVARIANTS grep
+ mutation-check) closes reversible/guarded commits autonomously; everything else
escalates to the operator.

**Why:** Move fast on the Platform v2 rewrite (S1 done; S2 box-packer and the later per-plugin
migrations) without ever breaking an installed-site data contract — the loop's
adversarial gate is the safety net that lets autonomy be trusted.

**Never:** break an installed-site data contract (see `.autodev/INVARIANTS.md`).
The loop only *executes* tasks from `queue/pending/`; it does not invent them.

## Live source of truth (read these, in order)
1. `docs-internal/platform-v2-program-tracker.md` — where the program actually is.
2. `.autodev/INVARIANTS.md` — the never-break contract zones (machine-checkable).
3. `.autodev/GUARDS.md` — which contracts are already guarded (autonomous) vs. human-only.
4. `docs-internal/autodev-loop-runbook.md` — the full design of this loop.

## Boundaries of this loop
- Branch: `autodev/loop-s2` (never `main`). All loop artefacts live under
  `.autodev/` and `tools/autodev/` — additive, no collision with other workstreams.
- Deployment target: **S2 (box-packer)** — 3 tasks. S1 (shipping, 33 done + 1 deferred) merged to main 2026-06-08.
- The conductor never reasons. All intelligence is in the worker/critic subprocesses.
