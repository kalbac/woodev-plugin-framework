# RUNBOOK — Autonomous Adversarial Dev Loop (woodev-framework)

> Status: DESIGN (not yet implemented). Authored 2026-06-04.
> Purpose: a continuous, multi-model development loop where a worker model makes
> changes, a *different* critic model adversarially verifies them, a machine gate
> closes most commits autonomously, and only irreducible human-judgment decisions
> reach the owner.
>
> Design decisions locked:
> - **Conductor language: PowerShell** (Windows-native, no Python).
> - **Worker:** Claude (`claude -p`), Opus→Sonnet→Haiku fallback ladder.
> - **Critic:** GPT-5.5 high via `codex exec`.
> - **Autonomy boundary:** machine commits *reversible* work AND *irreversible*
>   work that is guarded by a mutation-verified test. Everything else escalates.
> - **Escalations** routed to the owner via the existing Telegram bot.

## 0. Roles and the core principle

| Role | Tool | Context | Lifetime |
|---|---|---|---|
| **Planner** | human, or a planning agent whose decomposition the human blesses | — | upstream of the loop; produces tasks, not in it |
| **Conductor** | dumb PowerShell script, no LLM | none | immortal — holds loop / gate / fallbacks |
| **Worker** | `claude -p` (Opus→Sonnet→Haiku) | fresh per task | disposable |
| **Critic** | `codex exec` GPT-5.5 high — repo-read, fenced from worker rationale | fresh per diff | disposable |
| **Anti-drift** | `claude -p` (Sonnet), periodic — checks work against phase *intent* | fresh | disposable |

Principle: **agents are cattle, not pets — disposable; state lives on disk.**
All "permission to commit" lives in the conductor + tests; all "intelligence"
lives in the worker/critic. The conductor never reasons; it only routes files and
runs the gate, so it has no context to rot and no rate limit to hit.

**The loop is an executor, not a planner.** It consumes well-formed tasks from
`queue/pending/`; it does not invent them. A **Planner** (the operator, or a
planning agent whose decomposition the operator blesses) cuts a phase into tasks
that are right-sized and **file-disjoint**, each declaring its `file_set`. The
conductor serializes any two queued tasks whose `file_set`s intersect — two tasks
editing the same file must never run in parallel worktrees, or the worktrees
diverge and integration conflicts (this is exactly how P4's tasks 2–4, all editing
`class-plugin.php`, would collide). Decomposition quality is itself a judgment act;
keeping it upstream of the loop prevents `TOO_BIG` bounces and file contention.

## 1. Blackboard — file layout

```
.autodev/
  GOAL.md                      # immutable anchor: 3-5 lines "what & why", pointer to PLANS.md
  INVARIANTS.md                # never-break list as machine-checkable grep patterns
  GUARDS.md                    # registry of blessed, mutation-verified guards
  queue/
    pending/<task-id>.md       # queued tasks
    active/<task-id>.md        # claimed / in-flight
    done/<task-id>.md          # completed + commit hash
    quarantine/<task-id>.md    # poison tasks (circuit breaker)
  runtime/<task-id>/
    diff.patch                 # worker output
    worker-report.md           # what it did + why it is safe
    verdict.json               # critic output
    heartbeat                  # mtime = liveness signal
    attempts                   # retry counter
  escalations/<id>.md          # inbox for the human
  digest.md                    # feed into docs-internal/CURRENT-STATE.md
  conductor.log
```

`GOAL.md`, `INVARIANTS.md`, `GUARDS.md`, `queue/`, `escalations/` are **git-tracked**
(durable). `runtime/` is ephemeral scratch.

### INVARIANTS.md (machine-checkable patterns, not prose)

```yaml
# Touching any pattern = "irreversible contract zone" -> a guard is required
contract_zones:
  - id: license
    paths: ["woodev/licensing/**", "woodev/class-license*.php"]
    why: "license key option, activation state, instance IDs (release-blocking)"
  - id: option_keys
    grep: ["get_option\\(", "update_option\\(", "_settings'"]
    why: "option keys must stay byte-for-byte"
  - id: hooks
    grep: ["do_action\\(", "apply_filters\\("]
    why: "public hook names are a contract"
  - id: cron
    paths: ["woodev/class-lifecycle.php"]
    grep: ["wp_schedule_event", "_cron"]
  - id: rest
    grep: ["register_rest_route"]
  - id: gateway_ids
    grep: ["\\$this->id\\s*=", "get_method_title"]
constitution:                  # touching = ALWAYS human
    paths: ["PLANS.md", "**/*-policy.md", "**/*data-preservation*", "INVARIANTS.md", "GOAL.md"]
```

### GUARDS.md (the owner's trust list)

```
| contract_id | guard_test                                | mutation_verified | blessed_by | date       |
|-------------|-------------------------------------------|-------------------|------------|------------|
| option_keys | tests/unit/Contract/OptionKeysTest.php    | yes (red on flip) | maksim     | 2026-06-04 |
```

Empty at the start → almost everything escalates → agents fill it by writing guards
→ the owner blesses each guard ONCE → that contract becomes autonomous forever.

## 2. WORKER prompt (`claude -p`, one task, fresh context)

```text
You are a disposable worker. You do ONE task, then you die. Your memory is the
blackboard, not your context. Use Serena for all PHP reads — never the Read tool.

TASK: {task-id} — see .autodev/queue/active/{task-id}.md
ANCHOR: read .autodev/GOAL.md before anything. Do not exceed the task scope.
INVARIANTS: read .autodev/INVARIANTS.md. You MUST NOT break any contract zone.

Rules:
- Work in the git worktree you were started in. Touch only files the task names.
- Make the smallest change that completes the task.
- If you find the task needs >1 logical change, STOP and write
  worker-report.md with status=TOO_BIG and a proposed decomposition. Do not code.
- If completing the task REQUIRES touching a contract zone (INVARIANTS), and no
  blessed guard exists in GUARDS.md for it, STOP: status=NEEDS_GUARD. Do not code.

Output (write to .autodev/runtime/{task-id}/):
- the change itself, committed to the worktree (do NOT push, do NOT touch main)
- diff.patch  = `git diff` of your change
- worker-report.md with: status (DONE|TOO_BIG|NEEDS_GUARD|BLOCKED),
  files_touched[], one-line rationale, and "contract_zones_touched: [...]"
- if the task WRITES A GUARD: also emit mutation-recipe.json — the exact
  { file, locator (line or regex), canonical_value, mutated_value } the conductor
  must flip to prove the guard goes RED. A contract with no machine-checkable recipe
  (cron-payload shape, DB schema) is NOT auto-blessable — mark the guard human-only;
  do not pretend the gate can verify it.
- Touch heartbeat file every significant step.

Do NOT claim success. Do NOT run the gate. The conductor judges you.
```

## 3. CRITIC prompt (`codex exec` GPT-5.5 high, adversarial)

```text
You are an adversarial verifier. Default assumption: this diff BREAKS a data
contract. Your job is to prove it does, with a specific line. Only if you cannot,
return clean. You are NOT the author; you have no stake in the work passing.

INPUTS:
- diff:        .autodev/runtime/{task-id}/diff.patch
- invariants:  .autodev/INVARIANTS.md
- guards:      .autodev/GUARDS.md
- the repository, READ-ONLY (grep / Serena). USE IT. A contract break is often
  invisible in the patch: a removed method whose NAME is a contract, an external
  string/hook reference, a load-order regression. Reason about the whole repo, not
  only the changed lines (the P3 backwards_compatible regression was found exactly
  this way — by reasoning about the full load loop, not the deleted lines).
You are FENCED from the worker's reasoning: do NOT read
.autodev/runtime/**/worker-report.md or the worker's commit message. Repo access is
for facts, never for the author's justification — anchoring on the worker's
rationale is the one thing you must avoid.

Check, in order:
1. Does the diff touch any contract zone in INVARIANTS? List each touch.
2. For each touch: is there a guard in GUARDS that asserts THIS contract?
   - If a renamed/removed option key, hook, REST route, gateway/instance id,
     cron hook, meta key, log source, AJAX action, admin slug, DB schema -> BROKEN.
3. Is any test in the diff modified to match a changed contract (fabricated proof)?
   That is BROKEN, not a fix.
4. Logic/regression risk independent of contracts.

Output verdict.json:
{ "verdict": "clean" | "broken" | "uncertain",
  "broken_contracts": [{ "zone": "...", "file": "...", "line": N, "evidence": "..." }],
  "notes": "...", "confidence": 0.0-1.0 }
When in doubt -> "uncertain" (this routes to human, never to silent pass).
```

## 4. CONDUCTOR logic + three fallback branches

```text
preflight: refuse to run unless HEAD matches AllowedBranchPattern (^autodev/) -- never main

loop forever:
  task = claim_next(queue/pending -> queue/active)   # atomic move; SKIP any task whose
                                                     # file_set intersects an active task
  if none: sleep 30s; run_periodic_jobs(); continue

  # NO per-task git worktree exists. Workers run in the shared repository working tree,
  # SERIALIZED by file_set disjointness (the scheduler is the lock). A dirty-file fence
  # (below) catches any worker edit outside its file_set.
  attempts = inc(runtime/task/attempts)

  # ---- CIRCUIT BREAKER ----
  if attempts > 3:
      move task -> quarantine/ ; escalate("poison task", task) ; continue

  # ---- WORKER (model fallback ladder) ----
  # contract-zone work is NEVER silently downgraded by a timing 429 — it pauses.
  ladder = task.touches_contract_zone ? [opus] : [opus, sonnet, haiku-or-openrouter]
  for model in ladder:
      r = run_with_watchdog( claude -p --model {model} <WORKER_PROMPT>, wt,
                             timeout=20m, heartbeat=runtime/task/heartbeat )
      if r.rate_limited:        continue   # FALLBACK 1a: tier down / next provider
      if r.timed_out_no_hb:     break_and_respawn()   # WATCHDOG
      break
  if r.rate_limited (whole ladder exhausted, incl. contract-zone single-model):
      sleep_until_window_reset()           # FALLBACK 1b: backoff, lose nothing
      release task -> pending ; continue

  report = read(worker-report.md)
  if report.status == TOO_BIG:    enqueue(report.decomposition); archive(task); continue
  if report.status == NEEDS_GUARD: escalate("needs guard", task); continue
  if report.status == BLOCKED:    escalate("blocked", task); continue

  # ---- DIRTY-FILE FENCE (the shared tree has no worktree isolation) ----
  worker_changed = git_status_changed_files() - pre_worker_baseline   # only NEW dirt this run
  stray = worker_changed - file_set - under(.autodev scratch)         # constitution files NOT ignored
  forbidden = worker_changed matching task.forbidden_paths            # honored even for -AssumeWorkerDone
  if stray or forbidden: escalate("dirty-file", task); continue

  # ---- CRITIC (heterogeneous non-Claude checker; runs on EVERY non-empty diff) ----
  # There is NO "cheap rubber-stamp" tier: a zone-free small diff can still carry a logic or
  # architecture regression that composer-check alone would miss. The only legitimate cost
  # lever is a cheaper Codex tier (Config.CriticModel = gpt-5.3-codex-spark) -- never a skip
  # and never a Claude model (Claude reviewing Claude is not independent).
  verdict = run( codex_critic(diff) )                  # gpt-5.5-high (read-only, fenced)
  if verdict != clean:
      if task.touches_contract_zone:  escalate("critic reject", task, verdict); continue   # never auto-retry a contract risk
      elif round < maxRounds(task):   feed verdict back to a FRESH worker; round++; retry   # CriticRetryMax / task.max_rounds
      else:                           escalate("critic reject (retries spent)", task); continue

  # ---- MACHINE GATE (the real lock) ----
  if not run("composer check").green:       retry(); continue
  for cmd in task.success_commands:                    # operator-authored acceptance gate
      if exit(cmd) != 0:                    retry(); continue
  zones = grep_diff_against(INVARIANTS)
  if zones.touch_constitution:              escalate("constitution change", task); continue
  for z in zones.contract:
      touched_values = enumerated exact_strings of z present in the diff
      if touched_values:
          # PER-VALUE coverage: every touched value needs ITS OWN mutation-verified+blessed
          # guard (recipe.canonical_value == value). One zone guard does NOT bless the whole
          # zone -- e.g. an 'option_keys' guard on settings does not cover a different key.
          for v in touched_values:
              guard = GUARDS.lookup_by_value(v)
              if not guard or not blessed(guard) or not mutation_check(guard):
                  escalate("no guard for contract value", task, v); continue_outer
      else:
          # zone touched via path_glob/grep with no enumerated value: legacy zone-level guard.
          guard = GUARDS.lookup_by_zone(z)
          if not guard or not blessed(guard) or not mutation_check(guard):
              escalate("no guard for contract", task, z); continue_outer
      # mutation_check uses the guard's mutation-recipe (file, locator, canonical->mutated)
      # to flip the contract, assert the guard goes RED, then revert. NO recipe -> not
      # auto-checkable -> the contract stays human-gated (never silently "guarded").

  # ---- COMMIT (checkpoint) ----
  git commit -m "<type>(autodev): {task.title} ..."   # in the shared tree, on the loop branch
  move task -> done/ (record hash) ; append_digest(task)
```

### Fallback branches, summarized

| Scenario | Detection | Conductor reaction |
|---|---|---|
| **1. Rate limit / 429** | CLI exit code / error string | step down the model ladder (ordinary tasks) → return task to pending with the **attempt refunded** (a 429 is not a failed attempt; never a false poison) → the conductor then **sleeps `RateLimitBackoffSeconds`** before the next claim so it does not busy-loop on the same limit. **Contract-zone tasks never downgrade** — a license-zone edit must not land on Haiku just because Opus is busy; it pauses instead |
| **2. Context bloat** | not detected — **prevented**: 1 task = 1 fresh session; worker self-reports `TOO_BIG` | too-big task → decompose into queue, no code |
| **3. Session restart** | agent died / killed | non-event: new agent reads blackboard + git + `CURRENT-STATE.md`; checkpoint = git commit after gate; idempotency via `done/` |

## 5. Watchdog & Circuit Breaker

- **Watchdog:** `mtime(heartbeat)` unchanged > N min → agent hung → kill → respawn
  fresh (same task, attempts++).
- **Breaker:** `attempts > 3` across *different fresh* agents → task is poison →
  `quarantine/` + escalate. Protects against runaway token spend.

## 6. ESCALATION format (`.autodev/escalations/<id>.md` → Telegram)

```markdown
# ESCALATION {id} — {reason}
**Task:** {task-id} — {title}
**Type:** needs-guard | disagreement | constitution | uncertain | poison
**What happened:** 2-3 lines, plain language.
**Decision you need to make:** one sentence.
**Option A:** ... (what the plan / PLANS.md implies)
**Option B:** ...
**Cost of being wrong:** {e.g. "breaks license activation on live sites"}
**Evidence:** diff excerpt + critic verdict + relevant INVARIANT line.
**Reply:** `A` / `B` — structured choice only. Free-form text is recorded for the
operator's context but is **never** fed to a worker as an instruction: Telegram is
an injection surface (the skill itself warns of prompt-injection), so escalation
replies *select among options*, they do not author work. Until you reply, this task
is parked (others continue).
```

The escalation **re-hydrates** the owner with context — decide in 2 minutes
without knowing the history.

## 7. DIGEST format (feed into `docs-internal/CURRENT-STATE.md`)

```markdown
## Autodev digest — {N commits since last}
- Done: {task titles + hashes}
- Phase progress vs PLANS.md: {on-track | drift flagged}
- Guards blessed this run: {list} | pending your blessing: {count}
- Open escalations: {count} (see .autodev/escalations/)
- Anti-drift check: {result of periodic PLANS.md conformance}
```

Anti-drift critic runs every M commits — and this is the highest-value safeguard in
the whole design, so invest the most context budget here. It does **NOT** compare
commit titles; that is far too shallow to catch real drift (a task can technically
close "minimize the resolver" while missing the point entirely). It is given the
**phase intent and goals from `platform-v2-program-tracker.md`** plus the actual
**diffs** of the recent `done/` tasks, and asked: *"does this work advance the
phase's stated intent, or has it wandered — satisfied the letter of the tasks while
missing their purpose?"* This is the one defence against the exact failure the whole
effort exists to prevent: green gate, wrong direction.

## 8. Decision map

```
worker change -> critic
  ├ uncertain ───────────────► HUMAN
  ├ broken (2 rounds) ───────► HUMAN (disagreement)
  └ clean -> machine gate:
        composer test red ──► retry / HUMAN
        constitution ───────► HUMAN
        contract + guard ✓ ─► COMMIT
        contract, no guard ─► HUMAN (needs guard)
        no contract ───────► COMMIT
```

## 9. Where to deploy this (ROI)

Do **not** build this loop for the tail of S0 — P5 (resolver minimization) + P6
(gate) is ≈1.5 tasks, and the infrastructure cost dwarfs the payoff. **Finish S0 by
hand** (it is nearly done and cheap). The loop's economics only work on a module
with dozens of tasks: **S1 (shipping) is the real target.**

Sequence: finish S0 manually → build and prove the loop on the **contract-guard
workload** → deploy the autonomous loop on S1. The guard workload is not throwaway
validation: those mutation-verified guards are permanent production safety — they
protect the installed-site data contracts through all of S1 *and* the later
per-plugin migrations. So the guards get written regardless of whether full
autonomy is ever switched on.

## Related

- `docs-internal/platform-v2-cleanbreak-plan.md` — Phase 3 deletions are the first
  real workload for this loop.
- `docs-internal/platform-v2-execution-protocol.md` — operating rules.
- `CLAUDE.md` → "Backward Compatibility — clean-break policy" — source of the
  contract-zone / internal-code split that INVARIANTS.md encodes.
