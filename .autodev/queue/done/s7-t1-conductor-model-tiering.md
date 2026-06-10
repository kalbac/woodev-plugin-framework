---
id: s7-t1-conductor-model-tiering
title: Wire per-task worker model tiering (haiku/sonnet/opus) into the autodev conductor loop
phase: Autodev tooling — Fable 5 re-tiering (orchestrator prompt "After / housekeeping")
type: tooling
touches_contract_zone: false
writes_guard: false
file_set:
  - tools/autodev/_common.ps1
  - tools/autodev/invoke-worker.ps1
  - tools/autodev/conductor.ps1
  - docs-internal/fable5-autodev-orchestrator-prompt.md
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - "Task frontmatter supports optional `model: haiku|sonnet|opus`; ConvertFrom-AutodevTask defaults it to $null (StrictMode-safe)"
  - "invoke-worker.ps1 accepts -Model; ladder = WorkerLadder sub-ladder starting at the declared model (opus->sonnet->haiku, sonnet->haiku, haiku only); no declared model -> full ladder (current behavior preserved)"
  - "Contract-zone pin UNCHANGED and wins over any declared model: -TouchesContractZone always pins to WorkerLadder[0] (opus) + logs a WARN if a weaker model was declared"
  - "Invalid declared model -> WARN + fall back to full ladder (never crash the loop)"
  - "conductor.ps1 passes -Model from the parsed task to invoke-worker"
  - "DryRun output prints the declared model and resulting ladder"
  - "Verified via invoke-worker -DryRun with temp task files (model: sonnet -> ladder 'sonnet -> haiku'; model: haiku + contract zone -> pinned 'opus'); scheduler.ps1 self-test still passes"
---

# Task

The operator re-tiered the autodev loop (2026-06-10, s5): the orchestrator picks a worker
model per task complexity (haiku = trivial/mechanical, sonnet = moderate, opus =
complex/contract-adjacent). The operator-directed pattern already does this via the Agent
tool; this task wires the SAME tiering into the automated conductor loop
(`tools/autodev/`), per `docs-internal/fable5-autodev-orchestrator-prompt.md` →
"After / housekeeping". The critic side (GPT-5.5 high) is already wired — do NOT touch
invoke-critic.ps1.

## 1. `tools/autodev/_common.ps1` — frontmatter field

In `ConvertFrom-AutodevTask`, add `model = $null` to the `$obj` defaults (the generic
`key: value` parser already fills it when present; the explicit default keeps
`Set-StrictMode -Version Latest` property access safe for tasks without the key).
Update the function docblock example to show the optional `model:` key.

## 2. `tools/autodev/invoke-worker.ps1` — sub-ladder from declared model

- Add `[string]$Model` to `param()`.
- Ladder construction (replace the current one-liner, keep the `[string[]]` cast +
  single-element comma trick — see the existing comment about scalar unwrapping):
  1. `-TouchesContractZone` → ladder is `, $Config.WorkerLadder[0]` exactly as today.
     If `$Model` is also set and differs from `WorkerLadder[0]`, log a WARN that the
     contract-zone pin overrides the declared model. (A license-zone edit must never
     run on haiku, even if the task spec mistakenly declares it.)
  2. Else if `$Model` is set and present in `$Config.WorkerLadder` → ladder = the
     sub-array of `WorkerLadder` from that model's index to the end (declared tier
     first, then rate-limit step-downs to cheaper tiers only).
  3. Else if `$Model` is set but NOT in `WorkerLadder` → `Write-AutodevLog -Level WARN`
     ("unknown model '<x>', falling back to full ladder") and use the full ladder.
  4. Else → full ladder (current behavior, byte-for-byte).
- Extend the existing "Task ... ladder:" log line and the DryRun output to include the
  declared model (or "(none)").
- Update the `.SYNOPSIS`/`.DESCRIPTION` comment block: MODEL LADDER now starts at the
  task-declared tier when present.

## 3. `tools/autodev/conductor.ps1` — pass-through

At the invoke-worker call site (~line 152), pass the parsed task's model:
`-Model ([string]$task.model)` (cast so $null becomes ''). Do not change anything else.

## 4. `docs-internal/fable5-autodev-orchestrator-prompt.md` — housekeeping

In "## After / housekeeping", mark the conductor wiring item DONE with date 2026-06-10
and one line describing the mechanism (frontmatter `model:` → invoke-worker sub-ladder;
contract-zone pin unchanged). Keep the edit minimal.

## What NOT to change
- `invoke-critic.ps1` (critic tiering is mechanical and already correct; model already gpt-5.5).
- `WorkerLadder` order/content in `_common.ps1` config.
- The contract-zone pause-not-downgrade semantics on 429.
- `scheduler.ps1`, `gate.ps1`, `watchdog.ps1`, `anti-drift.ps1`.
- No PHP files; `composer check` not required (note why in the report).

## Verification (run these, capture output in the report)
1. Create a temp task file with `model: sonnet`, `touches_contract_zone: false` in
   `.autodev/queue/active/`, run `tools/autodev/invoke-worker.ps1 -TaskId <id> -DryRun`
   → printed ladder must be `sonnet -> haiku`.
2. Same with `model: haiku` + `-TouchesContractZone` → pinned `opus`, WARN logged.
3. No `model:` key → full ladder `opus -> sonnet -> haiku` (unchanged).
4. `model: gpt-9` (unknown) → WARN + full ladder.
5. `pwsh -File tools/autodev/scheduler.ps1 -SelfTest` (if that switch exists; otherwise
   skip and say so) — frontmatter parser change must not break it.
6. Clean up temp task files + any runtime dirs they created.

## Reference
- `docs-internal/fable5-autodev-orchestrator-prompt.md` — tiering policy table
- `.autodev/queue/done/s5-p1-need-license-flag-and-seam.md` — frontmatter format example
