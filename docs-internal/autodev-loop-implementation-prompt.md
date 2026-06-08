# Implementation brief — Autonomous Adversarial Dev Loop (bootstrap)

> Paste this as the opening prompt of a FRESH implementing session.
> Design spec lives in `docs-internal/autodev-loop-runbook.md` — read it first.
> Recommended model for the implementing session: **Opus 4.8, high reasoning
> effort.** Rationale below (§9). Do not use a cheap model: this work creates
> irreversible operations (worktree removal, an auto-committing gate) where a bug
> is expensive.

## 1. What you are building

A continuous, multi-model development loop for this repo, exactly as specified in
`docs-internal/autodev-loop-runbook.md`. Roles:
- **Planner** — the operator (or a planning agent whose output the operator
  blesses). Cuts a phase into right-sized, **file-disjoint** tasks, each declaring
  its `file_set`. The loop does NOT invent tasks — it only executes them.
- **Conductor** — a dumb, LLM-free **PowerShell** script that holds the loop, the
  machine gate, the three fallback branches, the watchdog and the circuit breaker.
- **Worker** — `claude -p` (Opus→Sonnet→Haiku ladder), one task per fresh session.
- **Critic** — `codex exec` running **GPT-5.5 high**, **with read-only repo access
  (grep/Serena) but fenced from the worker's rationale** (§7).
- **Anti-drift** — periodic `claude -p` (Sonnet) comparing work to the phase
  **intent** in the tracker, NOT to commit titles (§7).

You are building the **infrastructure**, then validating it on ONE safe, real
workload (§6). You are NOT running it autonomously against Phase 4 (see §2), and the
loop's real deployment target is **S1 (shipping), not the tail of S0** (§2.7).

## 2. Hard constraints — read before any action

1. **Conductor is PowerShell, never Python.** (Operator preference, locked.)
2. **Do NOT touch Phase 4 work or its files.** A parallel session is actively
   doing P4 (`Woodev_Plugin` decomposition: `Translation_Handler` done; next
   `Plugin_Action_Links_Handler`, `API_Logger`, `Cron_Handler`, WC-seam removal).
   Running a second autonomous loop on P4 would collide on the same files. The
   loop's first workload is in an UNCONTENDED zone (§6).
3. **The conductor never reasons.** No LLM in the conductor. It only moves files,
   runs `composer check`, greps diffs against patterns, and routes. All
   intelligence is in the worker/critic subprocesses.
4. **Installed-site data contracts are release-blocking — never break them.** See
   `CLAUDE.md` → "Backward Compatibility — clean-break policy" and
   `docs-internal/migration/edostavka-data-preservation-checklist.md`.
5. **Work on a dedicated branch** `autodev/loop-bootstrap`, branched from
   `refactor/platform-v2-clean-break`. Never commit to `main`. All new files live
   under `.autodev/` and `tools/autodev/` — additive, no collision with P4.
6. **Idempotency and atomicity are mandatory** — queue claiming via atomic file
   move; a crash mid-task must lose at most one task and be safe to re-run.
7. **The loop is an executor, not a planner.** It consumes `queue/pending/*`; it
   does not produce tasks. Each task declares a `file_set`; the conductor must
   **serialize any two tasks whose `file_set`s intersect** (never run them in
   parallel worktrees — they would diverge and conflict, exactly as P4 tasks 2–4
   editing `class-plugin.php` would). For this bootstrap session you ARE the
   planner for the guard workload (§6); make those guard tasks file-disjoint.
8. **Do not aim the loop at the tail of S0.** P5+P6 ≈ 1.5 tasks — not worth the
   infra. The loop is for **S1 (shipping)**, a module with dozens of tasks. This
   session builds + proves the infra on the guard workload only; S0 is finished by
   hand by the operator's other session.

## 3. Pre-flight (do these first, report before proceeding)

### 3a. Worktree inventory + careful cleanup
The repo has accumulated agent worktrees under `.claude/worktrees/`, some **nested
inside each other** (e.g. `.claude/worktrees/agent-acf99487/.claude/worktrees/
agent-a3802523/...`) and **diverged** from main (e.g. `require_tls_1_2()` was
rewritten `_deprecated_function` → `wc_deprecated_function` in one worktree but
not merged). This is the runaway-isolation failure mode.
- Run `git worktree list` and walk `.claude/worktrees/`.
- For EACH worktree: is it locked? does it have uncommitted or unique unmerged
  commits? Produce a table: path / branch / HEAD / dirty? / unique-commits? / safe-to-remove?
- **Remove only those that are confirmed dead** (no lock, clean, no unique work):
  `git worktree remove <path>`. **Never** `--force` a worktree with uncommitted or
  unmerged work — escalate those to the operator in the report instead.
- Do NOT assume they belong to the paused session; verify, don't guess.

### 3b. Doc-drift reconciliation
`docs-internal/platform-v2-cleanbreak-plan.md` still describes Phase 3 as pending
("3.2-3.5 deletions next") and references shims/files that no longer exist
(`get_woocommerce_uploads_path` moved to `class-woocommerce-plugin.php`;
`class-plugin-license-settings.php` gone). `docs-internal/platform-v2-program-tracker.md`
correctly says **P3 done, P4 in progress**. Reconcile: mark the cleanbreak-plan
Phase 3 as DONE with a pointer to the tracker as the live source of truth. Do not
rewrite history — add a dated "superseded by tracker" note. This is a docs-only
change; commit separately.

### 3c. Critic availability check
Verify `codex exec` runs headless and can target GPT-5.5 high. If it cannot
(not installed / no headless mode), fall back to OpenCode + OpenRouter as the
critic transport and record the substitution in the runbook. Do not block on this —
pick whichever heterogeneous critic actually works, but it MUST be a non-Claude model.

## 4. Deliverables — file by file

```
tools/autodev/
  conductor.ps1              # the loop: claim -> worker -> critic -> gate -> commit/escalate
  scheduler.ps1              # claim only tasks whose file_set is disjoint from active tasks
  gate.ps1                   # machine gate: composer check + INVARIANTS grep + mutation-check
  invoke-worker.ps1          # `claude -p` model ladder + watchdog + rate-limit detect;
                             #   contract-zone tasks pin to Opus (pause, never downgrade)
  invoke-critic.ps1          # `codex exec` GPT-5.5 high, READ-ONLY repo access, fenced from
                             #   worker-report; TIERED: expensive critic only if diff touches a
                             #   contract zone OR diff > N lines, else cheap/none (mechanical signal)
  mutation-check.ps1         # uses the guard's mutation-recipe.json (file, locator,
                             #   canonical->mutated) to flip contract -> assert RED -> revert
  escalate.ps1               # writes .autodev/escalations/<id>.md + sends via Telegram skill
                             #   (replies are A/B structured only — Telegram is an injection surface)
  watchdog.ps1               # heartbeat staleness -> kill + respawn
.autodev/
  GOAL.md                    # 3-5 line anchor + pointer to program-tracker.md (live source of truth)
  INVARIANTS.md              # populated from the data-preservation checklist + clean-break policy (§5)
  GUARDS.md                  # seeded by §6 with the first mutation-verified guards
  queue/{pending,active,done,quarantine}/
  runtime/                   # ephemeral (gitignore this subdir only)
  escalations/
  digest.md
  conductor.log
```

## 5. Populate INVARIANTS.md from real contracts

Do NOT invent patterns. Source them from:
- `CLAUDE.md` → clean-break "Installed-site data contracts" list (the canonical
  never-break enumeration).
- `docs-internal/migration/edostavka-data-preservation-checklist.md` (real exact
  strings: option keys `woocommerce_edostavka_settings`, `wc_edostavka_webhook_ids`,
  …; method id `edostavka`; cron `wc_edostavka_orders_update`; order-meta prefix
  `_wc_edostavka_`; log source `edostavka_orders`; REST `wc/v3`; etc.).
Encode them as the `contract_zones` grep/path patterns and the `constitution` path
list shown in the runbook §1. The `constitution` list MUST include
`platform-v2-program-tracker.md`, `PLANS.md`, all `*-policy.md`, and INVARIANTS.md
itself (touching these = always human).

## 6. First validation workload (the ONLY autonomous work this session runs)

Prove the loop end-to-end on an uncontended, high-value task: **write the first
mutation-verified contract guards.** This bootstraps `GUARDS.md` — the registry the
whole autonomy boundary depends on — and touches only new test files (no P4 collision).

Pick 2-3 contracts from the edostavka checklist that are unit-testable without a WP
runtime (Brain Monkey), e.g.:
- the shipping method ID is exactly `edostavka`,
- the settings option key is exactly `woocommerce_edostavka_settings`.
For each:
1. Worker writes a guard test under `tests/unit/Contract/` asserting the exact
   string, AND emits `mutation-recipe.json` ({ file, locator, canonical_value,
   mutated_value }) telling the conductor exactly what to flip.
2. **mutation-check.ps1 proves it is a real guard** using that recipe: flip the
   contract, assert the guard goes RED, revert, assert GREEN. A guard that stays
   green on mutation — or ships no machine-checkable recipe — is rejected: fix it,
   or mark the contract human-only (never silently treat it as "guarded").
3. Critic (GPT-5.5) adversarially reviews: is the guard asserting the REAL contract
   string, or a tautology? Is any production code edited to match a changed string?
4. On clean verdict + green mutation-check → record the guard in `GUARDS.md` with
   `mutation_verified: yes` and `blessed_by: <pending-operator>`; commit.
5. Emit ONE escalation summarizing the new guards for the operator to bless.

This single pass exercises: worker, critic, machine gate, mutation-check, GUARDS
registry, escalation, and a commit checkpoint — the entire spine — on safe work.

## 7. Integration details to get right

- **Queue claiming + file-set lock:** atomic `Move-Item pending\<id> active\<id>`;
  if it throws, another iteration claimed it — skip. Additionally, skip any task
  whose `file_set` intersects a currently-active task (serialize overlaps).
- **Rate-limit detection:** inspect `claude -p` / `codex exec` exit code + stderr for
  429 / quota strings; on hit, step the model ladder, then `sleep_until_window_reset`
  and return the task to `pending` (lose nothing). **Contract-zone tasks are pinned
  to Opus** — they pause on 429, they do NOT downgrade to a weaker model.
- **Critic, tiered + repo-aware:** the critic gets READ-ONLY repo access (grep/Serena)
  so it can catch contract breaks invisible in the diff (removed contract-named
  methods, external string/hook refs, load-order regressions), but is FENCED from
  `.autodev/runtime/**/worker-report.md` and the worker commit message (no anchoring).
  Run the expensive GPT-5.5 critic only when a MECHANICAL signal fires — diff touches
  a contract zone OR diff > N lines; else cheap critic or machine-gate-only.
- **Watchdog:** worker touches `.autodev/runtime/<id>/heartbeat`; if mtime is stale
  > N minutes, kill the process and respawn fresh (attempts++).
- **Circuit breaker:** `attempts > 3` across fresh agents → `quarantine/` + escalate.
- **Escalation transport:** write the markdown file AND push a one-line summary via
  the `telegram` skill. Replies are **A/B structured choices only** — free-form text
  is logged for context but never executed as a worker instruction (injection surface).
- **Anti-drift (highest-value, give it real context):** every M commits, feed the
  Sonnet critic the **phase intent + goals from `platform-v2-program-tracker.md`**
  and the **diffs** of recent `done/` tasks (NOT commit titles), asking whether the
  work advanced the phase's intent or only its letter. One strong line in the digest.
- **Digest:** append to `docs-internal/CURRENT-STATE.md` every N commits, in the
  format from runbook §7, including the anti-drift result.

## 8. What NOT to do

- Do NOT run the loop against Phase 4 or any file the paused session is editing.
- Do NOT aim the autonomous loop at the S0 tail (P5/P6) — that is hand-finished;
  the loop targets S1.
- Do NOT run two tasks with overlapping `file_set`s in parallel worktrees.
- Do NOT give the critic the worker's rationale (report/commit message); repo-read only.
- Do NOT treat a contract as guarded without a passing mutation-recipe check.
- Do NOT downgrade a contract-zone task to a weaker model on a 429 — pause it.
- Do NOT `git worktree remove --force` anything with uncommitted/unmerged work.
- Do NOT invent contract patterns; source them from §5.
- Do NOT let the conductor make judgment calls — if it is tempted to "decide," that
  path must escalate instead.
- Do NOT execute free-form Telegram replies as worker instructions (A/B selection only).
- Do NOT auto-commit anything that touches a `constitution` path.

## 9. Model / effort guidance

- **Implementing session (you):** Opus 4.8, high effort. The conductor's atomicity/
  idempotency, the mutation-check, and the worktree cleanup are subtle and partly
  irreversible.
- **Runtime roles (encode in the scripts):** Worker = Opus→Sonnet→Haiku ladder;
  Critic = GPT-5.5 high (non-Claude, mandatory); Anti-drift = Sonnet (cheap,
  frequent). Cheapest model that passes the gate for routine workers; never cheap
  for the critic.

## 10. Acceptance criteria

- [ ] `.claude/worktrees/` inventoried; dead ones removed; risky ones escalated (not forced).
- [ ] cleanbreak-plan ↔ program-tracker drift reconciled (docs-only commit).
- [ ] Non-Claude critic transport verified working (`codex exec` GPT-5.5 or OpenRouter fallback),
      with read-only repo access AND fenced from the worker report; critic tiering is mechanical.
- [ ] `tools/autodev/*.ps1` + `.autodev/` scaffolding created; INVARIANTS.md populated from real contracts.
- [ ] `scheduler.ps1` serializes tasks with intersecting `file_set`s (verified with a 2-task overlap case).
- [ ] 2-3 mutation-verified guards written, each with a `mutation-recipe.json`, proven RED-on-mutation,
      recorded in GUARDS.md, committed.
- [ ] Anti-drift compares against tracker phase intent + diffs (not commit titles); one digest line produced.
- [ ] One end-to-end cycle (worker→critic→gate→commit) demonstrably ran on the guard workload.
- [ ] One escalation delivered to the operator (Telegram) to bless the new guards.
- [ ] `composer check` green; all work on `autodev/loop-bootstrap`, nothing on `main`, no P4 files touched.

## Related
- `docs-internal/autodev-loop-runbook.md` — the design spec this implements.
- `docs-internal/platform-v2-program-tracker.md` — live source of truth for phase state.
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — contract strings for INVARIANTS/guards.
