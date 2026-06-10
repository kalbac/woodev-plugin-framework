---
id: s7-t3-loop-control-flow-hardening
title: Autodev loop control-flow hardening — escalated/ queue dir, empty-file_set gate escalate, ReuseVerdict diff hash, cost caps
phase: Autodev tooling — loop review remediation (reviews/autodev-loop-review-2026-06-11.md items 1,4,5,6,10)
type: tooling
model: sonnet
touches_contract_zone: false
writes_guard: false
file_set:
  - tools/autodev/_common.ps1
  - tools/autodev/conductor.ps1
  - tools/autodev/scheduler.ps1
  - tools/autodev/gate.ps1
  - tools/autodev/invoke-critic.ps1
  - tools/autodev/invoke-worker.ps1
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - "NEW queue/escalated/: config key QueueEscalated; Initialize-AutodevDirectories creates it; conductor's NEEDS_GUARD/BLOCKED route (~L183), critic-escalate route (~L235) and gate-escalate route (~L287) Move-Task to QueueEscalated instead of QueueActive (no more stranded-in-active no-op)"
  - "scheduler treats escalated/ tasks as BLOCKING for file_set intersection exactly like active/ ones (serialization preserved), but never claims from it; scheduler -SelfTest extended with one case proving an escalated task blocks an intersecting pending task"
  - "gate.ps1: a task whose file_set is empty/missing yields verdict ESCALATE (reason 'empty file_set — nothing can be safely judged'), NEVER COMMIT"
  - "invoke-critic writes diff_sha256 (SHA256 of the diff text it judged) into verdict.json; conductor -ReuseVerdict reuses a clean verdict ONLY when stored diff_sha256 matches the SHA256 of the CURRENT regenerated diff; mismatch -> log + fall through to a fresh critic run"
  - "cost caps: new config keys WorkerMaxTurns (default 100) appended to the claude args as --max-turns, and MaxSessionHours (default 8) -- conductor's main loop exits gracefully (log + break) when exceeded"
  - "conductor ~L163 stale comment/log fixed to match the actual move-to-pending behavior"
  - "all existing self-tests still pass: conductor -SelfTest, scheduler -SelfTest, watchdog -SelfTest, invoke-worker -DryRun (with a temp task)"
---

# Task

Implement items 1, 4, 5, 6, 10 of `docs-internal/reviews/autodev-loop-review-2026-06-11.md`
(read it first — it carries the verified file:line evidence). PowerShell only, no PHP.
Keep the existing code style (comment tone, Write-AutodevLog levels, Set-StrictMode-safe,
`[string[]]` casts where arrays may unwrap).

Key implementation notes:
- `Move-Task` in conductor takes `-ToDir`; the three escalation call sites currently pass
  `$Config.QueueActive` — switch to `$Config.QueueEscalated`. Do NOT change the
  quarantine or pending routes.
- scheduler: the active-set builder (`$sets` around scheduler.ps1:40-50 and the blocked-by
  reporter ~L144-152) must enumerate BOTH QueueActive and QueueEscalated.
- diff hash: `[System.Security.Cryptography.SHA256]` over UTF8 bytes of the diff string;
  helper in _common.ps1 (e.g. `Get-AutodevTextSha256`) so conductor and invoke-critic share it.
- `--max-turns` goes into the `$args` array in invoke-worker next to `--permission-mode`.
- MaxSessionHours: capture `$sessionStart = Get-Date` before the loop; check at the top of
  each iteration.

## What NOT to change
- Worker prompt text and worktree wording (separate task s7-t4).
- invoke-critic tiering thresholds, codex transport, fencing.
- Queue file frontmatter format.
- anti-drift.ps1 / mutation-check.ps1 / watchdog.ps1 (s7-t4).

## Verification (capture real output)
1. `pwsh -File tools/autodev/scheduler.ps1 -SelfTest` — PASS incl. the new escalated-blocks case.
2. `pwsh -File tools/autodev/conductor.ps1 -SelfTest` — PASS.
3. invoke-worker -DryRun with a temp task: command line shows `--max-turns 100`.
4. Grep proves no remaining `Move-Task ... QueueActive` at the three escalation sites.
