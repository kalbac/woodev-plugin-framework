---
id: s7-t4-antidrift-wiring-and-cleanup
title: Wire anti-drift into the conductor, fix watchdog stdout liveness, Invoke-Native everywhere, worker-prompt cleanup, dead-key removal
phase: Autodev tooling — loop review remediation (reviews/autodev-loop-review-2026-06-11.md items 2,3,7,8,13,15)
type: tooling
model: sonnet
touches_contract_zone: false
writes_guard: false
file_set:
  - tools/autodev/_common.ps1
  - tools/autodev/conductor.ps1
  - tools/autodev/anti-drift.ps1
  - tools/autodev/watchdog.ps1
  - tools/autodev/mutation-check.ps1
  - tools/autodev/invoke-worker.ps1
depends_on:
  - s7-t3-loop-control-flow-hardening
contract_zones_touched: []
needs_guard: no
acceptance:
  - "anti-drift WIRED: conductor counts successful COMMITs in the session; every AntiDriftEveryCommits (config, default 5) commits it invokes tools/autodev/anti-drift.ps1 (log on failure, never crash the loop); counter resets after each run"
  - "anti-drift.ps1 actually APPENDS its one-line verdict (timestamped) to $Config.Digest (.autodev/digest.md) as its SYNOPSIS promises; -DigestOnly emits/appends the digest line without the sonnet call; git calls routed through Invoke-Native"
  - "DigestEveryCommits config key REMOVED (redundant — digest now rides the anti-drift cadence); CurrentState config key removed IF truly unread after these changes (grep-verify; if anti-drift uses it, keep)"
  - "watchdog.ps1: event sink uses $Event.SourceEventArgs.Data (not $EventArgs.Data); watchdog -SelfTest still PASSES and still proves stdout-driven liveness (the self-test must fail if the sink stops receiving data — strengthen it if it would not)"
  - "mutation-check.ps1: phpunit invocations routed through Invoke-Native (preserve exit-code semantics: RED expected on mutation)"
  - "invoke-worker Build-WorkerPrompt cleaned: (a) 'git add -N -- <file_set>' named as the EXPLICIT sole exception to the no-git-add rule (one sentence, no contradiction); (b) all 'worktree' wording in prompt + SYNOPSIS replaced with 'repository working tree (serialized by file_set disjointness)'; (c) Serena line becomes conditional: 'Use Serena tools for PHP if they are available in your session; otherwise use Grep/Read'"
  - "conductor.ps1 + invoke-worker.ps1 stale comments about per-task worktrees corrected"
  - "all self-tests green: conductor -SelfTest, scheduler -SelfTest, watchdog -SelfTest, invoke-worker -DryRun"
---

# Task

Implement items 2 (wording half), 3, 7, 8, 13, 15 of
`docs-internal/reviews/autodev-loop-review-2026-06-11.md` (read first). PowerShell only.
s7-t3 has already landed on this branch — build on its state of the files.

Implementation notes:
- Conductor wiring point: immediately after a successful COMMIT route (where the task
  moves to done/), increment `$commitsSinceDrift`; when `>= $Config.AntiDriftEveryCommits`
  run `& (Join-Path $here 'anti-drift.ps1')` inside try/catch (WARN on failure), reset counter.
- anti-drift digest append format: `[yyyy-MM-dd HH:mm:ss] [anti-drift] <ON-TRACK|DRIFT|UNCERTAIN: line>` via Add-Content -Encoding utf8.
- Real per-task git worktrees are explicitly OUT OF SCOPE (recorded follow-up) — this task only makes the WORDS honest.

## What NOT to change
- invoke-critic.ps1 (untouched this task).
- The s7-t3 changes (escalated/ routing, diff hash, caps) — extend, don't rework.
- gate.ps1, scheduler.ps1, escalate.ps1.

## Verification (capture real output)
1. `pwsh -File tools/autodev/watchdog.ps1 -SelfTest` — PASS (stdout liveness case included).
2. `pwsh -File tools/autodev/conductor.ps1 -SelfTest`, `scheduler.ps1 -SelfTest` — PASS.
3. anti-drift -DigestOnly run appends a line to a temp-pointed digest (show the line); clean up.
4. Grep: zero remaining 'worktree' in invoke-worker prompt/SYNOPSIS; zero `$EventArgs.Data`; zero `DigestEveryCommits`.
