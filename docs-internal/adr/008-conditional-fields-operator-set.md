# ADR-008: Conditional-Fields `show_if` — Operator Set & Evaluation Contract

**Status:** accepted

**Date:** 2026-07-05

## Context

Plugins need to show/hide a settings field based on other fields' values (e.g. show «API-ключ» only when `mode = live`). The feature (`show_if`) was shipped for both admin surfaces (settings page + setup wizard). The hard constraint is that field visibility must be resolved **identically** in three places — client render, client save-gate, and the authoritative server gate — because the server persists nothing when validation fails (SP-3 atomic REST). A drift between the PHP evaluator and its JS mirror is a silent client/server disagreement bug.

We deliberately shipped a **minimal** grammar and needed to lock exactly which operators exist and how edge cases evaluate, so that a future operator addition follows a written contract instead of being re-decided (and re-mirrored) from scratch.

Spec: `docs-internal/specs/2026-07-05-conditional-fields-design.md`.

## Decision

**Grammar (WP_Query-style, flat, no nesting):** a `show_if` value is a condition group — an optional `relation` (`AND` default | `OR`) plus a flat list of conditions `{ setting, operator, value }`. A bare single condition (`['setting'=>…,'value'=>…]`) is sugar for a one-condition group. `show_if` accepts the array directly OR a callback `fn(string $field_id): array` returning it (resolved on demand, server-side, at schema-build time; the callback returns data, never a predicate — so it mirrors).

**Operator set (v1) — exactly four:** `=`, `!=`, `in`, `not_in`.
- `in` / `not_in` take an array `value`; `=` / `!=` take a scalar.
- An **unknown operator evaluates to not-matching (fail-closed)**.

**Evaluation contract (the PHP `Woodev_Setting::evaluate_conditions()` ↔ JS `evaluateConditions()` mirror):**
- **Total pure function.** Empty group / empty member list → visible (`true`).
- **String comparison.** Both the controlling value and the target are coerced to string before comparing; enum option **keys** (incl. zero-based ints) round-trip correctly.
- **Boolean coercion follows PHP's `(string)` cast:** `true → '1'`, `false → ''` (NOT `'true'`/`'false'`). The JS mirror has a `toComparable()` helper so a **toggle** controlling field agrees across client and server.
- **Empty / non-scalar controlling value = the empty string** — no special-casing. Therefore `=` does NOT match an empty controller, and `!=` / `not_in` DO match it. Authors who want "show when `mode = live`" must write `= 'live'` (not `!= 'test'`, which also shows while `mode` is unset). An **unregistered** controlling id (typo, or a controller on another handler) that is absent from the submission also resolves to the empty string (guarded — it must never throw and abort the save).
- **Controlling fields are scalar** in v1 (`select` / `radio` / `toggle` / `text`). A multi-value (multiselect) controller is out of scope.

**Server skip:** `Woodev_Abstract_Settings::filter_visible_values()` strips hidden fields from the submitted map once, at the top of both REST save paths (settings + wizard), against the **effective** controlling value (posted, else stored). A hidden field is therefore neither validated nor persisted.

**Deferred (NOT in v1):** comparison operators `>` `<` `>=` `<=`; unary `empty` / `set`; `contains` (array/multiselect controller); **nested** condition groups; section / sub-tab / step-level visibility; a DRY `apply_show_if( ids, conditions )` registration helper.

## Consequences

- **Adding a future operator** is mechanical and contract-bound: add the case to PHP `evaluate_conditions()` **and** the JS `evaluateConditions()` mirror (keep string comparison + `toComparable` + fail-closed default), add a unit case to `ConditionalFieldsTest`, and document it here. Do not diverge the two runtimes.
- **Adding nesting or a new operand shape** (unary, numeric threshold) is additive — the flat grammar is a strict subset — but each is a genuinely new evaluation shape and a new mirror surface, so it waits for a real plugin (the shipping-module pilot) to prove the need. Tracked in `FUTURE-BACKLOG.md` ("conditional-fields v2").
- **The fail-closed unknown-operator + guarded unregistered-controller rules** mean a misconfigured `show_if` degrades safely (field hidden / treated as empty) instead of crashing a save.
- The string-comparison + boolean-coercion contract is the single most fragile invariant; both source files carry a "KEEP IN SYNC" cross-reference and this ADR is the tie-breaker.

## Related

- [[../specs/2026-07-05-conditional-fields-design.md]] — full design spec (§5 = the mirror contract)
- [[005-platform-v2-clean-break-policy.md]] — installed-site data contracts (option keys) preserved; `show_if` adds no new stored data
- [[../DOCS-INDEX.md]] — docs navigation
