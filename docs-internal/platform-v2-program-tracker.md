# Platform v2 — Program Tracker (live)

> Sweep-across-the-whole-program status. Any session reads this first (per execution-protocol §0) to learn where we are. Update the "Next action" + statuses as work lands.

**Branch:** `refactor/platform-v2-clean-break` · **Baselines:** `platform-v2-pivot-baseline`, `platform-v2-pre-refactor`
**Last updated:** 2026-06-04

## Next action
▶️ **S0 / Phase 4 — decompose `Woodev_Plugin` (in progress).** Handlers extracted: Task 1 `Translation_Handler` ✅ (`dc4f661`), Task 4 `Cron_Handler` ✅ (`9acb359`), green 189/428. **Decision (mine, 2026-06-04): Tasks 2 (`Plugin_Action_Links_Handler`) and 3 (`API_Logger`) NOT extracted** — both are polymorphic template-methods overridden by `Woodev_Payment_Gateway_Plugin` (action-links calls `parent::`; api-logging is no-op'd by gateways which log per-gateway, and `get_api_log_message()` is an external caller). Extracting them needs overridable-handler gold-plating + risks double-logging on live payment plugins — contradicts D-3 "extract the clearest, pragmatic, no gold-plating." Two small polymorphic methods on the base ≠ god-object. Next: Task 5 remove the last WC seam `add_woocommerce_hooks()` stub (P3 already removed the other 2 seams), Task 6 construction tidy. Then P4 **key gate** → external GPT-5.5 audit.

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
| P4 | Decompose `Woodev_Plugin` (sub-plan) | 🟡 in progress — Task 1 `Translation_Handler` ✅ (`dc4f661`); Tasks 2–6 next | **yes (at end)** |
| P5 | Re-minimize resolver (ADR-003) | ⚪ | no (internal) |
| P6 | "Split done" gate | ⚪ | **yes** → tag `platform-v2-split-done` |

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
