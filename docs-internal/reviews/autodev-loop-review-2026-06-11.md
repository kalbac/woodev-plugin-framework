# Autodev-loop tooling review — 2026-06-11 (s7, Fable orchestrator)

> Full assessment of `tools/autodev/` (11 scripts, ~2,100 lines) requested by the operator.
> Method: Explore-agent deep-read of every script + orchestrator spot-verification of the
> three load-bearing claims against source. Items marked ✅ fixed are addressed by the
> `fix/autodev-loop-hardening` branch (s7-t3 / s7-t4); items marked ⏳ are recorded
> follow-ups, deliberately out of scope.

## Keep (working well, do not touch)
- Pipeline shape: scheduler (atomic claim + file_set disjointness) → worker → external critic → mechanical gate (INVARIANTS zones) → commit/escalate. "Mechanics decide, LLM advises" separation.
- Battle-tested fixes: 429 attempt-refund symmetry, verdict-parse-before-ratelimit, contract-zone opus pin (now + declared-model override, PR #26), mutation-verified guards.

## Fixed on this branch
| # | Severity | Finding (verified) | Fix |
|---|----------|--------------------|-----|
| 1 | 🔴 | `NEEDS_GUARD`/`BLOCKED`/critic-escalate/gate-escalate did `Move-Task → queue/active/` where the file already is → no-op → task stranded forever in `active/`, permanently blocking every intersecting file_set (`conductor.ps1:183/235/287`) | New `queue/escalated/` dir; escalation paths move there; scheduler treats `escalated/` as blocking (serialization preserved); operator unparks by moving back to `pending/` |
| 2 | 🔴 | "Per-task worktree" is fiction: no `git worktree add` exists anywhere; conductor never passes `-Worktree`; workers run in the MAIN tree (`invoke-worker.ps1:39`) | Honesty fix: all "worktree" wording removed from SYNOPSIS/prompts → "repository working tree, serialized by file_set disjointness". Real worktrees = ⏳ follow-up (would require per-worktree composer install) |
| 3 | 🔴 | anti-drift never invoked (comments only, `conductor.ps1:19,359`); `AntiDriftEveryCommits`/`DigestEveryCommits` read by nobody; digest-append unimplemented in `anti-drift.ps1` despite its SYNOPSIS | Commit counter in conductor → run anti-drift every `AntiDriftEveryCommits` COMMITs; anti-drift now appends its line to `digest.md`; redundant `DigestEveryCommits` key removed |
| 4 | 🟠 | Empty/missing `file_set` → gate sees zero changed files → COMMIT with no zone/constitution checks (`gate.ps1:85-98`) | Empty file_set → ESCALATE, never COMMIT |
| 5 | 🟠 | `-ReuseVerdict` reuses any prior `clean` verdict with no proof it judged the same code (`conductor.ps1:208`) | `verdict.json` gains `diff_sha256`; reuse only when it matches the current diff hash |
| 6 | 🟠 | Timeout log says "leaving in active for respawn", code moves to `pending` (`conductor.ps1:163-164`) | Comment/log corrected |
| 7 | 🟠 | `$EventArgs.Data` inside event-sink scriptblock can be $null on Windows PowerShell 5.1 → stdout liveness silently dead (`watchdog.ps1`) | `$Event.SourceEventArgs.Data` (works on 5.1 and 7+) |
| 8 | 🟠 | `anti-drift.ps1`/`mutation-check.ps1` call git/phpunit bare, bypassing `Invoke-Native` stderr protection | Routed through `Invoke-Native` |
| 10 | 🟡 | No cost bounds: only the 20-min worker wall-clock | `WorkerMaxTurns` (claude `--max-turns`) + `MaxSessionHours` conductor wall-clock exit |
| 13 | 🟡 | Worker prompt contradictions: "do NOT run git add" next to "run `git add -N`"; "this git worktree" (false); Serena instruction unconditional | Prompt cleaned: `git add -N` named as the explicit diff-only exception; worktree wording removed; Serena "if available, else Grep/Read" |
| 15 | ⚪ | Dead config keys (`CurrentState`, `DigestEveryCommits`), stale worktree comments | Removed |

## Recorded follow-ups (⏳ not in this branch)
- **Telegram reply ingestion** — escalations are send-only; unparking is manual. A poller acting on structured A/B replies is a feature, not a fix.
- **Real per-task git worktrees** — needs per-worktree `composer install` + gate path changes; revisit if parallel workers are ever wanted.
- **Resume journal** — kill between worker-DONE and commit leaves uncommitted changes invisible to the next run. Mitigated by escalated/-blocking; full fix needs an in-progress journal.
- **`confidence` / `broken_contracts[].line`** — parsed, stored, never routed on. Either use (low-confidence `uncertain` → cheap re-critic) or drop from the schema at the next schema rev.
- **Codify "BLOCK → counter-evidence → re-verdict"** — saved this session twice (one false-positive refuted, one real catch confirmed); currently an orchestrator-manual pattern, conductor escalates immediately.
- **invoke-worker workers in agent-tool worktrees**: gotcha [[serena-index-vs-git-worktree]] applies to the *operator-directed* pattern, not the conductor (whose workers run in the main tree).

## Related
- [fable5-architecture-review-2026-06-10.md](fable5-architecture-review-2026-06-10.md)
- `.autodev/queue/done/s7-t3-*.md`, `s7-t4-*.md` — the fixing tasks
- `docs-internal/fable5-autodev-orchestrator-prompt.md` — tiering policy (PR #26)
