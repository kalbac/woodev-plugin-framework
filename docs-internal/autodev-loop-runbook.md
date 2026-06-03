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
| **Conductor** | dumb PowerShell script, no LLM | none | immortal — holds loop / gate / fallbacks |
| **Worker** | `claude -p` (Opus→Sonnet→Haiku) | fresh per task | disposable |
| **Critic** | `codex exec` GPT-5.5 high | fresh per diff | disposable |
| **Anti-drift** | `claude -p` (Sonnet), periodic | fresh | disposable |

Principle: **agents are cattle, not pets — disposable; state lives on disk.**
All "permission to commit" lives in the conductor + tests; all "intelligence"
lives in the worker/critic. The conductor never reasons; it only routes files and
runs the gate, so it has no context to rot and no rate limit to hit.

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
You are NOT given the worker's rationale. Do not ask for it. Judge the diff alone.

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
loop forever:
  task = claim_next(queue/pending -> queue/active)   # atomic move
  if none: sleep 30s; run_periodic_jobs(); continue

  worktree = ensure_worktree(task.id)                # ISOLATION: one wt per task
  attempts = inc(runtime/task/attempts)

  # ---- CIRCUIT BREAKER ----
  if attempts > 3:
      move task -> quarantine/ ; escalate("poison task", task) ; continue

  # ---- WORKER (with model fallback ladder) ----
  for model in [opus, sonnet, haiku-or-openrouter]:
      r = run_with_watchdog( claude -p --model {model} <WORKER_PROMPT>, wt,
                             timeout=20m, heartbeat=runtime/task/heartbeat )
      if r.rate_limited:        continue   # FALLBACK 1a: tier down / next provider
      if r.timed_out_no_hb:     break_and_respawn()   # WATCHDOG
      break
  if all models rate_limited:
      sleep_until_window_reset()           # FALLBACK 1b: backoff, lose nothing
      release task -> pending ; continue

  report = read(worker-report.md)
  if report.status == TOO_BIG:    enqueue(report.decomposition); archive(task); continue
  if report.status == NEEDS_GUARD: escalate("needs guard", task); continue
  if report.status == BLOCKED:    escalate("blocked", task); continue

  # ---- CRITIC ----
  verdict = run( codex exec gpt-5.5-high <CRITIC_PROMPT> )   # fallback: openrouter critic if codex down
  if verdict == uncertain:        escalate("critic uncertain", task, verdict); continue
  if verdict == broken:
      if round < 2: feed verdict back to a fresh worker; round++; retry
      else:         escalate("worker/critic disagreement", task, verdict); continue

  # ---- MACHINE GATE (the real lock) ----
  if not run("composer test").green:        retry_or_escalate(); continue
  zones = grep_diff_against(INVARIANTS)
  if zones.touch_constitution:              escalate("constitution change", task); continue
  for z in zones.contract:
      guard = GUARDS.lookup(z)
      if not guard:                         escalate("no guard for contract", task, z); continue_outer
      if not mutation_check(guard):         escalate("guard not protecting", task, z); continue_outer
      # mutation_check = break contract -> assert guard test goes RED -> revert

  # ---- COMMIT (checkpoint) ----
  git commit -m "<type>(autodev): {task.title} ..."
  merge worktree -> integration branch ; move task -> done/ (record hash)
  append_digest(task)
```

### Fallback branches, summarized

| Scenario | Detection | Conductor reaction |
|---|---|---|
| **1. Rate limit / 429** | CLI exit code / error string | step down the model ladder → switch provider (OpenRouter overflow) → if all out: `sleep_until_window_reset` + return task to pending (nothing lost) |
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
**Reply:** `A` / `B` / free text. Until you reply, this task is parked (others continue).
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

Anti-drift critic runs every M commits: "compare `done/` against `PLANS.md` — are
we still doing what it says?" → one line in the digest.

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

## Related

- `docs-internal/platform-v2-cleanbreak-plan.md` — Phase 3 deletions are the first
  real workload for this loop.
- `docs-internal/platform-v2-execution-protocol.md` — operating rules.
- `CLAUDE.md` → "Backward Compatibility — clean-break policy" — source of the
  contract-zone / internal-code split that INVARIANTS.md encodes.
