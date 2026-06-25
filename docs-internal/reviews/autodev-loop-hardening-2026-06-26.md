# Autodev-loop hardening — 2026-06-26

> Triggered by a "Loop Engineering" video (Addy Osmani / Boris Cherny) the operator asked to
> compare our `autodev-loop` against. Method: (1) transcript pulled + framework distilled to
> `docs-internal/loop-engineering-framework.md`; (2) gap analysis of `tools/autodev/` by Claude
> AND an independent Codex (GPT-5.5) pass; (3) 10 fixes implemented on branch
> `fix/autodev-loop-hardening`; (4) Codex re-critic of the fixes (no self-certify) — 7 findings,
> all addressed; (5) a second focused Codex re-critic of the fix-of-fixes.

## Where the loop already met or exceeded the framework (do NOT regress)
- **Maker/Checker** with a HETEROGENEOUS critic (Claude worker, Codex GPT-5.5 critic) + physical
  fencing (worker rationale moved out of tree, read-only sandbox) — stronger than the framework's
  "separate agent".
- **Mutation-verified guards** (`mutation-check.ps1`): a guard counts only if it goes
  GREEN -> RED-on-flip -> GREEN-on-revert. Directly defuses the framework's "tests = false
  security" risk, which the framework names but does not solve.
- **Per-zone graduated autonomy** via GUARDS blessing; constitution = always-human; fail-closed gate.
- **Ralph technique**: disposable workers, blackboard-on-disk state, fresh context per task.

## The 10 fixes (all on `fix/autodev-loop-hardening`)

| # | Sev | Fix | Files | Proof |
|---|-----|-----|-------|-------|
| 1 | RED | Guard coverage is per CONTRACT VALUE, not per zone. A touched `exact_string` needs a guard whose recipe `canonical_value` == that value; a sibling value in the same zone is no longer auto-blessed. Zone-level match kept only as the fallback for path/grep touches with no enumerated value. | `gate.ps1`, `_common.ps1` | gate self-test Case 2 |
| 2 | RED | Cheap "rubber-stamp" critic tier removed. In `auto`, every non-empty diff runs the real Codex critic. spark (`gpt-5.3-codex-spark`) is the documented cost lever; never a Claude critic. | `invoke-critic.ps1` | parse + header |
| 3 | ORANGE | Dirty-file fence: worker edits outside `file_set` (or matching `forbidden_paths`) escalate. Baseline-subtracted (only NEW dirt) so pre-existing tree dirt does not false-stall. Ignore list is scratch-only — constitution files are NOT ignored. | `conductor.ps1`, `_common.ps1` | conductor Case 6 |
| 4 | ORANGE | Anti-drift `DRIFT:` verdict now ESCALATES (was log-only). | `conductor.ps1` | conductor Case 7 |
| 5 | ORANGE | Worker/critic 429 sets a flag; the outer loop sleeps `RateLimitBackoffSeconds` instead of busy-looping. | `conductor.ps1` | logic + parse |
| 6 | ORANGE | `-GuardBootstrap` removed — it was structurally unreachable (GUARDS.md is a constitution path → gate never auto-commits it). | `conductor.ps1` | no dangling refs |
| 7 | YELLOW | Branch preflight at startup AND a commit-time re-check (HEAD can move mid-run); refuse unless HEAD matches `^autodev/`. | `conductor.ps1` | conductor Case 5 |
| 8 | YELLOW | Bounded worker↔critic retry for NON-contract diffs (`CriticRetryMax` / task `max_rounds`); worker reads `critic-feedback.md`. Contract risk (declared OR actual-diff zone OR critic-named break) escalates on the FIRST objection. | `conductor.ps1`, `invoke-worker.ps1` | parse + dry-run |
| 9 | YELLOW | Structured task schema consumed: `success_commands` (gate runs each, exit 0 to COMMIT), `forbidden_paths` (fence, honored even under `-AssumeWorkerDone`), `max_rounds` (retry bound). | `_common.ps1`, `gate.ps1` | — |
| 10 | YELLOW | Runbook pseudocode + fallback table brought in line with the implementation (no worktree, real backoff, per-value guard, fence, preflight). | `autodev-loop-runbook.md` | — |

## Decisions, not bugs (left intentionally)
- **No automated task intake** (the framework's "triage skill"): GOAL.md deliberately says the loop
  executes tasks, it does not invent them. We are at framework L1 for intake — on purpose.
- **No real parallel worktrees**: workers serialize by file_set disjointness. Simplicity over
  throughput for a contract-heavy single repo.

## Codex re-critic of the fixes (no self-certify) — 7 findings, all addressed
1. (High) `.autodev/` fence ignore was too broad → would hide constitution edits. **Fixed** (scratch-only ignore).
2. (High) contract-retry gating used only frontmatter → **Fixed** (actual-diff zone + critic broken_contracts).
3. (High) branch guard startup-only → **Fixed** (commit-time re-check).
4. (Med) fence false-positive on pre-existing dirt → **Fixed** (pre-worker baseline).
5. (Med) `-AssumeWorkerDone` bypassed forbidden_paths → **Fixed** (forbidden honored there).
6. (Med) "constitution always escalates" comment imprecise (RETRY priority) → **Fixed** (comment: never auto-commits).
7. (Low) stale invoke-critic header → **Fixed**.

## Verification
- conductor / scheduler / gate self-tests: **PASS** (conductor 7 cases incl. the new fence/branch/drift; gate 5 cases incl. the per-value fix).
- All 10 `tools/autodev/*.ps1` parse clean (`[Parser]::ParseFile`).
- `composer check`: **green** (phpcs + phpstan clean, 842 unit tests pass) — PowerShell-only changes do not touch PHP.

## Related
- `docs-internal/loop-engineering-framework.md` — the yardstick (distilled from the video).
- `docs-internal/reviews/autodev-loop-review-2026-06-11.md` — the prior (s7) tooling review.
- `docs-internal/autodev-loop-runbook.md` — updated design.
