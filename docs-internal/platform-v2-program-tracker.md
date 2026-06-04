# Platform v2 — Program Tracker (live)

> Sweep-across-the-whole-program status. Any session reads this first (per execution-protocol §0) to learn where we are. Update the "Next action" + statuses as work lands.

**Branch:** `refactor/platform-v2-clean-break` · **Baselines:** `platform-v2-pivot-baseline`, `platform-v2-pre-refactor`
**Last updated:** 2026-06-04

## Next action
⏸ **S0 / Phase 6 — final external audit pending (split-done sign-off).** All P6 verification checks GREEN: pure-WP neutrality suites pass · base 1296/77 (not god-object) · resolver minimal (ADR-003) · zero internal-API residue · `composer check` 190/505. **Operator: run `docs-internal/reviews/p6-split-done-audit-packet.md` through GPT-5.5 (holistic cross-cutting review).** On sign-off → tag `platform-v2-split-done`; **S0 COMPLETE** → S1 (shipping) begins, moving into the autodev loop.

> **Parallel workstream (operator-initiated 2026-06-04):** the autodev adversarial-loop bootstrap (`docs-internal/autodev-loop-{runbook,implementation-prompt}.md`) is a SEPARATE session on branch `autodev/loop-bootstrap` — additive (`.autodev/`, `tools/autodev/`), explicitly carved out from S0/P4 to avoid file collision. This session (S0) does NOT touch it; that session does NOT touch S0 files. Doc-drift fix for `cleanbreak-plan.md` Phase 3 is assigned to that bootstrap session (§3b).

## Stage map
| Stage | Scope | Status | Plan |
|---|---|---|---|
| **S0 Platform Split** | clean break + decompose base + minimal resolver (+ `api/` under base) | 🟡 in progress | `platform-v2-cleanbreak-plan.md` (+ base-decomposition sub-plan) |
| S1 Shipping | universal module; PVZ-map abstraction first | ⚪ planned (spec at S0 gate) | — |
| S2 Box-packer | minimal-virtual-box algorithm + neutral wrapper | ⚪ planned (spec at S1 gate) | — |
| S3 Licensing | `is_need_license` → modern UI → webhooks | ⚪ planned (spec at S2 gate) | — |
| S4 EDD | `Woodev_EDD_Plugin` (concept in v2.0) | ⚪ deferred | — |
| S5 React admin UI | built-in WP/WC React | ⚪ post-v2.0 | — |
| S6 Ecosystem orchestration | cross-project automation | ⚪ post-v2.0 stable | — |

## S0 phase board
| Phase | What | Status | External audit |
|---|---|---|---|
| P0 | Branch + frozen baseline | ✅ done (197/197 green, tags set) | — |
| P1 | CLAUDE.md/AGENTS.md clean-break reconciliation | ✅ done (ADR-005 added; ADR-002 bridge superseded) | no (docs) |
| P2 | Pilot gate: edostavka-shaped fixture through new path | ✅ **gate PASSED** (`7ebbd20`+`6ed8b72`); internal reviews ✅; ext audit (GPT-5.5) applied — caught real include-order coupling, hardened | done |
| P3 | Delete internal-API back-compat debt (cohesive) | ✅ **gate PASSED** (`711cbae`,`7cc3666`,`4223597` + audit fixes); green 182/412; internal verify ✅; audit-packet findings applied | done |
| P4 | Decompose `Woodev_Plugin` (sub-plan) | ✅ **gate PASSED** (`dc4f661`,`9acb359`,`dd47b99`,`ae84d9d`); base WC-name-free 1296/77; ext audit caught+fixed HPOS-timing bug; green 191/510 | done |
| P5 | Re-minimize resolver (ADR-003) | ✅ done — resolver already minimal post-P3 (641 lines, all members ADR-sanctioned); responsibility table + no-extraction decision in ADR-003 | no (internal) |
| P6 | "Split done" gate | 🟡 next | **yes** → tag `platform-v2-split-done` |

## Decisions on record
- D-1 split-first; D-2 clean break + preserve data; D-3 pragmatic base decomposition; D-4 keep thin rendezvous; D-5 pilot=edostavka.
- Validation deviation (operator): P2 gate uses an **in-repo fixture**, not a live edostavka rewrite → branch proves architecture, not live-data; data preservation enforced per-plugin at rewrite time.
- Review: external GPT-5.5 audit at key gates (P2/P3/P4/P6 + module gates); GPT-5.5 also = second opinion on contested design forks.

## Open follow-ups (out of current scope)
- `class-payment-gateway.php` (~2,378 lines) trait extraction — post-split debt.
- godaddy-fork study (Traits/Enums/Abilities, PLANS.md §4) — candidate GPT-5.5 research delegation before S1.
- **Test-scaffold duplication** (P2 code-review minor): `EdostavkaPilotFixtureTest` and `RealisticShippingFixtureTest` share a near-identical testable-resolver subclass + WP-stub helper. When a 3rd such fixture lands, extract a shared trait/base under `tests/unit/` instead of copying again.
- **i18n stale markers** (P3): `woodev/languages/*.po`/`*.pot` still reference the deleted `class-plugin-license-settings.php` line markers. Cosmetic (generated artifacts); regenerate via the i18n build at a convenient point.

## Related
- [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md) — the rulebook
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) · [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md)
- [CURRENT-STATE.md](CURRENT-STATE.md) — phase/bug detail
