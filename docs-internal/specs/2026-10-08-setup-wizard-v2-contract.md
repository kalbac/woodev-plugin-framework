# Setup Wizard v2 Contract

**Date:** 2026-10-08 (s162)  
**Issues:** #109 (engine), #1177 (CDEK v1→v2 migration wizard, the first real consumer), #110 (item 7 stays on its trigger)  
**Basis:** the read-only audit [2026-10-01-109-setup-wizard-audit.md](../research/2026-10-01-109-setup-wizard-audit.md) and the
operator's decisions of 08.10.2026, taken item by item on the audit's proposals.

## Why now

On 02.10.2026 the operator deferred the audit's proposals 1, 2 and 4 "until the first real carrier plugin". On 08.10.2026 he
decided the v1→v2 migration wizard ships **before** the v2 release (#1177), and its engine is this wizard — so the trigger fired.

## Decisions (operator, 08.10.2026 — all on the agent's recommendation)

### D1 — «Completed» means "the merchant reached the end", not "the plugin is ready"

- The persisted state is monotonic: `completed` can never be overwritten by `skipped`. `skipped` may later become `completed`.
- «Настрою позже» = `skipped`; the wizard stays reachable from an admin notice so the merchant can come back.
- Carrier readiness ("no API key", "no warehouse address") is reported by the plugin's own notices and gates. The wizard does not
  gate on readiness, and completing it proves nothing about readiness.
- Reason: the migration wizard rests on the rule that the store keeps working before anyone opens it (#1177); a wizard that gates
  readiness would contradict it.

### D2 — A first-class step contract, provided by the framework

Framework primitives, not per-plugin custom code:

- `skippable` per step (default `true`; the PHP bootstrap must actually emit it — today it never does, audit §2).
- A server-side validation callback that runs **before** anything is persisted and returns structured, per-field errors. Throwing
  from `on_save` after persistence is not validation.
- **Actions**: a typed, server-side operation bound to a step, run through REST, returning a structured result
  (`success` / `error` + message + optional data). Examples the migration wizard needs: «Проверить ключ» (without persisting),
  «Начать с чистого листа», «Очистить старые данные». Destructive actions declare themselves as such, so the UI can confirm them.
- Callbacks are wrapped in `Throwable`, not `Exception`; unexpected details are redacted from the response and logged (audit #6).

### D3 — Branching: recompute the step graph on the server after every saved step

- Not live reactivity to unsaved form values. After each successful save the server re-evaluates visibility predicates and returns
  the current step graph; the client re-renders from it.
- A step hidden by a choice is never saved: the server refuses a save or action for a step that is not visible in the current graph.
- Example: choosing «Начать с чистого листа» on the first step removes the mapping steps from the graph.

### D4 — Custom step type: a plugin-supplied React component in a framework slot

- A step may declare a component (a registered script handle + an exported component name). The framework renders it inside the
  standard step frame and gives it the same plumbing as built-in steps: values, save, actions, navigation, errors.
- Built-in step types stay: settings step, content step. No framework catalogue of special step types for now.
- Reason: every carrier's migration is different (mapping table, zone distribution, order report); guessing a catalogue up front
  would be wrong. If the second plugin (#1179) repeats a step shape, it is lifted into the framework then.

### Not doing

- **Per-step server progress / drafts** (audit proposal 3) — rejected 02.10.2026 and still not needed: every step persists on
  «Продолжить», and on reopening the wizard shows the saved values.

## Contained fixes that ride along (no decision needed)

- Audit #5: `aria-current="step"`, focus moves to the step heading after navigation, a text status per step, and an honest error
  state when complete/skip persistence fails (no success screen after a failed `complete`).
- Audit #6: `Throwable` around plugin callbacks (covered by D2).
- Audit #7: a minimal author example after the API is settled.

## Out of scope here

- The CDEK migration steps themselves — #1177, in the plugin, built on this contract.
- #110 item 7 (masking secret values in the bootstrap) — stays on its trigger; the first real secret field in a wizard IS that
  trigger, so the implementer must check whether the migration wizard shows the CDEK key.

## Related

- [Setup Wizard audit](../research/2026-10-01-109-setup-wizard-audit.md) — the facts and proposals these decisions answer.
- [CDEK v2 plugin design](2026-10-06-cdek-v2-plugin-design.md) — the plugin the migration wizard serves.
