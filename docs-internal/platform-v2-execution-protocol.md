# Platform v2 — Execution Protocol (the operating contract)

> The single rulebook every session and every sub-agent follows while executing the Platform v2 program (S0 split → S1 shipping → … → S6 orchestration). Lean and operational by design — it references existing conventions, never duplicates them.
>
> **Authority:** direction = `platform-v2-direction-audit-2026-06-03.md` (D-1..D-5). Detailed work = the per-stage plans. Status = `platform-v2-program-tracker.md`. Coding conventions = `CLAUDE.md` / `AGENTS.md`.

## 0. Resume protocol (start every session here)
1. Read `platform-v2-program-tracker.md` → where we are, current front, next action.
2. Read `CURRENT-STATE.md` (known bugs) + scan `GOTCHAS.md`.
3. Read this protocol + the **current stage's detailed plan**.
4. Continue from the tracker's "Next action". Do not re-plan finished work.

## 1. Branch & baseline
- All program work lands on `refactor/platform-v2-clean-break`. **Never commit program work to `main`.**
- Frozen references: `platform-v2-pivot-baseline` (v2-WIP pivot), `platform-v2-pre-refactor` (pristine pre-v2). Tag each stage gate (`platform-v2-split-done`, …).

## 2. Clean-break policy (D-2) — the prime directive
- **Internal code = free to break:** class/method names, registration shape, namespacing, file layout. Do **not** add `@deprecated`/`class_alias`/`_deprecated_function` shims for moved internal APIs. Delete existing ones.
- **Installed-site data = release-blocking, never break:** option keys, license state + instance IDs, updater identity, payment-gateway IDs, shipping-method IDs + instance setting keys, public hook names, cron hooks + recurrence + payload, custom tables, REST namespaces, AJAX actions, admin page slugs, log sources, background-job IDs, order/session meta keys. Enforced per-plugin via `docs-internal/migration/<plugin>-data-preservation-checklist.md` at rewrite time.

## 3. Commit discipline
- Conventional Commits. `composer check` **green before every commit** (phpcs + phpstan + phpunit). Never commit red.
- Batch a concern into **one cohesive change**, not micro-slices (audit §6.3). Internal-API removals: `refactor!:` + `BREAKING CHANGE:` footer.
- Code review BEFORE commit when touching `class-plugin.php`/`bootstrap.php`/`payment-gateway/`/public API/3+ files (AGENTS.md rule).

## 4. TDD
- Additive work: failing test → run-fail → minimal impl → run-pass → commit (bite-sized).
- Removal work: delete the shim **and its dedicated test together** → run full suite green → commit. Removing a behavior with no test is a red flag — add a guard test first if the behavior matters.
- Preserve-contract work: the test asserts the exact preserved string (hook/option/method id), so drift fails loudly.

## 5. Sub-agent strategy
- **Code task** → fresh sub-agent per task via `superpowers:subagent-driven-development` (two-stage review between tasks). One task = one self-contained green commit.
- **Read-only mapping / survey** → `Explore` agents, fan out in parallel for independent targets.
- **Independent research** (e.g. reference-plugin survey, godaddy-fork study) → parallel sub-agents.
- Docs-only, fully-specified edits → may be done directly (no sub-agent overhead).

## 6. Review — two layers
- **Internal (Claude):** subagent-driven two-stage review per task; at phase gates run profile reviewers (`code-reviewer`, `silent-failure-hunter`, `type-design-analyzer`) on the gate's diff.
- **External (GPT-5.5):** at **key gates** (S0: P2, P3, P4, P6; then each module's gate). I generate `docs-internal/reviews/<phase>-audit-packet.md` (diff range + plan section + invariants checklist + 3–5 pointed questions). Operator runs it through GPT-5.5 and returns findings. I process them via `superpowers:receiving-code-review` (verify skeptically; never implement blindly). **Second opinion:** I may request a GPT-5.5 packet for a contested design decision at any time (e.g. PVZ-map abstraction shape).

## 7. Gate model (between phases/stages)
A gate passes only when: (1) `composer check` green; (2) the plan's exit-gate checklist ticked; (3) internal review clean; (4) at key gates — external audit findings resolved; (5) tracker + CURRENT-STATE updated. Then tag (stage gates) and proceed.

## 8. Definition of Done
- **Task:** code + tests written, `composer check` green, reviewed, committed (Conventional Commit), tracker line updated.
- **Phase/Stage gate:** §7 satisfied.

## 9. Operator touchpoints (when I stop and ask)
- At each **key gate** (hand you the external audit packet).
- At a **genuine design fork** I can't resolve from PLANS.md/code/sensible defaults (rare — I default to deciding).
- Otherwise I run autonomously: routine task execution needs no check-in.

## Related
- [platform-v2-program-tracker.md](platform-v2-program-tracker.md) — live status
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) — S0 detail
- [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md) — S0 Phase 4 detail
- [platform-v2-direction-audit-2026-06-03.md](platform-v2-direction-audit-2026-06-03.md) — direction authority
